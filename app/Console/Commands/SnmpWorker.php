<?php

namespace App\Console\Commands;

use App\Console\LnmsCommand;
use LibreNMS\Util\DynamicConfig;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use LibreNMS\Config;
use App\Models\Device;
use LibreNMS\Util\Debug;
use Symfony\Component\Process\Process;
use Log;
use PHPSocketIO\SocketIO;
use Workerman\Redis\Client as RedisClient;
use LibreNMS\Snmptrap\Listener as TrapListener;
use FreeDSx\Snmp\TrapSink;
use Workerman\Lib\Timer;

class SnmpWorker extends LnmsCommand
{
    protected $name = 'snmp:worker';

    // TODO 配置界面
    const LIBRENMS_BRIDGE_SOCKETIO_PORT = 3161;
    const LIBRENMS_BRIDGE_MEASUREMENT = ['processors', 'mempool', 'ports', 'sensor', 'uptime'];
    const SNMP_SET_TYPE = ['i', 'u', 't', 'a', 'o', 's', 'x', 'd', 'b'];

    protected $worker;
    protected $socketIO;
    protected $trapReceiver;
    protected $snmpEncoder;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->addArgument('status', null, InputArgument::OPTIONAL, '获取Snmp Worker状态');
        $this->addArgument('start', null, InputArgument::OPTIONAL, '启动Snmp Worker');
        $this->addArgument('stop', null, InputArgument::OPTIONAL, '停止Snmp Worker');
        $this->addOption('deamon', 'd', InputOption::VALUE_NONE, '以deamon方式启动Snmp Worker');

        include_once base_path('includes/snmp.inc.php');

        $this->_queue = new \SplPriorityQueue();
        $this->initSocketIO();
        $this->initWorker();
    }

    protected function initWorker()
    {
        if (strpos(strtolower(PHP_OS), 'win') === 0) {
            exit("start.php not support windows, please use start_for_win.bat\n");
        }

        // 检查扩展
        if (!extension_loaded('pcntl')) {
            exit("Please install pcntl extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
        }

        if (!extension_loaded('posix')) {
            exit("Please install posix extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
        }

        // 标记是全局启动
        define('GLOBAL_START', 1);

        // TODO 配置参数及界面？
        $this->worker = new Worker('http://0.0.0.0:8080');
        // 进程数量设置参考
        //  https://www.workerman.net/doc/workerman/faq/processes-count.html
        $this->worker->count = 4 * 3;
        $this->worker->name = 'Snmp Worker Api';

        foreach (['onWorkerStart', 'onConnect', 'onMessage', 'onClose', 'onError', 'onBufferFull', 'onBufferDrain', 'onWorkerStop', 'onWorkerReload'] as $event) {
            if (method_exists($this, $event)) {
                $this->worker->$event = [$this, $event];
            }
        }

        Worker::$logFile = '/data/logs/workerman.log';

        // 避免重启以后该文件仍然存在，导致无法启动新进程的问题，所以修改到/tmp目录下
        Worker::$pidFile = '/tmp/snmpworker.pid';
    }

    const redis_subscribe_channel = ['snmp:*', 'snmptrap:*'];
    const fastRefresh_internval = 30; // 快速刷新时间间隔秒数
    const max_fastRefresh_period = 10 * 60; // 快速刷新持续秒数
    protected function initSocketIO()
    {
        $io = new SocketIO($this::LIBRENMS_BRIDGE_SOCKETIO_PORT);
        $this->socketIO = $io;
        $this->socketIO->on('workerStart', function () use ($io) {
            // TODO consider support redis username/password auth & db select
            $redis = new RedisClient('redis://' . env('REDIS_HOST', '127.0.0.1') . ':' . env('REDIS_PORT', 6379));
            // TODO topic可配置
            // Workerman redis组件参考
            //  不能采用phpredis，否则会导致整个进程busy !!!!!
            //  必须采用Workerman\Redis\Client
            //  https://www.workerman.net/doc/workerman/components/workerman-redis.html
            $redis->psubscribe(self::redis_subscribe_channel, function ($pattern, $channel, $message) use ($io) {
                $pattern_index = array_search($pattern, self::redis_subscribe_channel);
                if ($pattern_index > 0) {
                    $io->emit('snmptrap', json_decode($message, true));
                    unset($pattern);
                    unset($channel);
                    unset($message);
                    unset($pattern_index);
                    return;
                }
                // https://www.php.net/manual/zh/json.constants.php
                $snmp_data = json_decode($message, false, JSON_PARTIAL_OUTPUT_ON_ERROR);
                if (!is_object($snmp_data)) {
                    return;
                }
                $device = $snmp_data->device;
                $measurement = $snmp_data->measurement;
                $tags = $snmp_data->tags;
                $fields = $snmp_data->fields;
                if (in_array($measurement, $this::LIBRENMS_BRIDGE_MEASUREMENT)) {
                    // 通过socketio通知到对应的观察者
                    // $by_id = $device->device_id;
                    $by_ip = $device->overwrite_ip;
                    if (empty($by_ip)) {
                        $by_ip = $device->ip;
                    }
                    if (empty($by_ip)) {
                        $by_ip = $device->hostname;
                    }
                    // $io->to($by_id)->emit($measurement, [$tags, $fields]);
                    if (!empty($by_ip)) {
                        $io->to($by_ip)->emit($measurement, [$by_ip, $tags, $fields]);
                    }
                } else {
                    // echo 'snmp:'.$measurement.PHP_EOL;
                }

                unset($message);
                unset($snmp_data);
                unset($device);
                unset($measurement);
                unset($tags);
                unset($fields);
            });

            $io->timer = Timer::add($this::fastRefresh_internval, array($this, 'fastRefresh'), array(), true);
        });
        $this->socketIO->on('connection', function ($socket) use ($io) {
            $headers = $socket->request->headers;
            $real_ip = $headers['x-real-ip'];
            $user_agent = $headers['user-agent'];
            $socket->on('watch', function ($msg) use ($io, $socket, $real_ip) {
                $watchRequest = explode('@', $msg);
                $socket->join($watchRequest[0]);
                $this->logSocketIO('watched:' . $real_ip . '-->' . $msg);
                // 提高设备数据刷新率
                $this->_queue->insert([
                    'ip' => $msg,
                    'count' => 0,
                    'm' => count($watchRequest) > 1 ? $watchRequest[1] : 'core'
                ], 0);
            });
            $socket->on('unWatch', function ($msg) use ($io, $socket, $real_ip) {
                $socket->leave($msg);
                $this->logSocketIO('unWatched:' . $real_ip . '-->' . $msg);
                // TODO 取消对设备刷新率的提高
            });
        });
    }

    // 快速刷新
    private $_queue = null;
    public function fastRefresh()
    {
        if (!$this->_queue->current()) {
            return;
        }
        $nextRound = [];
        while ($current = $this->_queue->current()) {
            // 如果反复watch，这里保证至多重复刷新一次
            if (array_key_exists($current['ip'], $nextRound)) {
                $nextRound[$current['ip']]['count'] = min($nextRound[$current['ip']]['count'], $current['count']);
                $this->_queue->next();
                continue;
            }

            if (($current['count'] += 1) >= $this::max_fastRefresh_period / $this::fastRefresh_internval) {
                $this->logSocketIO('done refresh:' . $current['ip']);
                unset($current);
                $this->_queue->next();
                continue;
            }

            try {
                // Discovery module: https://docs.librenms.org/Support/Discovery%20Support/#os-based-discovery-config
                // Poller module: https://docs.librenms.org/Support/Poller%20Support/
                // Sensors: https://docs.librenms.org/Developing/os/Health-Information/
                $proc = Process::fromShellCommandline("lnms device:poll {$current['ip']} -m {$current['m']}");
                $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));
                $proc->start();
                $this->logSocketIO("fastRefresh-->ip:{$current['ip']},cmd:{$proc->getCommandLine()}");
            } catch (\Throwable $th) {
                $this->logSocketIO("fastRefresh-->exception:{$th->getMessage()}");
            }

            $nextRound[$current['ip']] = $current;
            $this->_queue->next();
        }
        foreach ($nextRound as $ip => $next) {
            $this->_queue->insert($next, 0);
        }
        unset($current);
        unset($nextRound);
    }

    public function onWorkerStart()
    {
    }

    // 处理HTTP请求
    public function onMessage(TcpConnection $connection, Request $request)
    {
        $snmp = $request->post('snmp', []);
        $cmd = $request->post('cmd', []);
        $parallel = $request->post('parallel', false);
        $multi_set = $request->post('multi_set', false);

        // 并行指令
        $parallel_cmd = [];
        for ($i = 0; $i < count($snmp); $i++) {
            $device_actions = $snmp[$i];
            if (!array_key_exists('ip', $device_actions) || empty($device_actions['ip'])) {
                continue;
            }
            $device = Device::findByIp($device_actions['ip']);
            if (!$device) {
                continue;
            }

            if (key_exists('set_community', $device_actions)) {
                $device->community = $device_actions['set_community'];
            }
            if (!key_exists('set', $device_actions)) {
                continue;
            }

            $device_snmp_cmd = '';
            $device_cmd = null;
            for ($j = 0; $j < count($device_actions['set']); $j++) {
                $action = $device_actions['set'][$j];
                // 校验不通过不予执行
                if (!$this->validateSnmpSet($action)) {
                    continue;
                }

                // 批量设置oid的模式
                if ($multi_set) {
                    if (!isset($device_cmd)) {
                        $device_cmd = gen_snmp_cmd([Config::get('snmpset', 'snmpset')], $device->toArray(), $action['oid'], '-OQXUte', 'SNMPv2-TC:SNMPv2-MIB:IF-MIB:IP-MIB:TCP-MIB:UDP-MIB:NET-SNMP-VACM-MIB');
                        $device_cmd[] = $action['type'];
                        $device_cmd[] = $action['value'];
                        continue;
                    }
                    $device_cmd[] = $action['oid'];
                    $device_cmd[] = $action['type'];
                    $device_cmd[] = $action['value'];
                    continue;
                }

                $action_cmd = gen_snmp_cmd([Config::get('snmpset', 'snmpset')], $device->toArray(), $action['oid'], '-OQXUte', 'SNMPv2-TC:SNMPv2-MIB:IF-MIB:IP-MIB:TCP-MIB:UDP-MIB:NET-SNMP-VACM-MIB');
                $action_cmd[] = $action['type'];
                $action_cmd[] = $action['value'];

                $proc = new Process($action_cmd);
                $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));

                $this->logCommand($proc->getCommandLine());

                if ($parallel) {
                    if (strlen($device_snmp_cmd) > 0) {
                        $device_snmp_cmd .= ' && ';
                    }
                    // 采用Process格式化参数的能力，确保接正确处理参数
                    $device_snmp_cmd .= $proc->getCommandLine();

                    unset($action_cmd);
                    unset($proc);
                    continue;
                }

                $proc->run();
                $exitCode = $proc->getExitCode();
                $output = $proc->getOutput();
                $stderr = $proc->getErrorOutput();

                $snmp[$i]['set'][$j]['result'] = [
                    'exitCode' => $exitCode,
                    'stderr' => $stderr
                ];

                unset($proc);
                unset($exitCode);
                unset($output);
                unset($stderr);
            }

            if ($parallel) {
                if (isset($device_cmd)) {
                    // 用来格式化参数，确保没有问题
                    $proc = new Process($device_cmd);
                    $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));

                    $parallel_cmd[] = $proc->getCommandLine();
                    unset($proc);
                    continue;
                }

                if (empty($device_snmp_cmd)) {
                    continue;
                }
                // Debug
                // $parallel_cmd[] = "'ping' '-n' '-c' '100' '" . $device_actions['ip'] . "' && " . $device_snmp_cmd;
                $parallel_cmd[] = $device_snmp_cmd;
                unset($device_cmd);
                unset($device_snmp_cmd);
                // 批量模式稍后执行
                continue;
            }

            if (isset($device_cmd)) {
                $proc = new Process($device_cmd);
                $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));

                $this->logCommand($proc->getCommandLine());
                $proc->run();
                $exitCode = $proc->getExitCode();
                $output = $proc->getOutput();
                $stderr = $proc->getErrorOutput();

                unset($proc);
                unset($exitCode);
                unset($output);
                unset($stderr);
            }
        }

        if (count($parallel_cmd) > 0) {
            $this->parallelExcute($parallel_cmd);
            unset($parallel_cmd);
        }

        $cmd_result = [];
        $parallel_cmd = [];
        foreach ($cmd as $command) {

            $proc = new Process(explode(' ', $command));
            $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));

            $this->logCommand($proc->getCommandLine());
            if ($parallel) {
                $parallel_cmd[] = $proc->getCommandLine();
                unset($proc);
                continue;
            }

            // 这样启动会产生defunct
            $proc->run();
            $exitCode = $proc->getExitCode();
            $output = $proc->getOutput();
            $stderr = $proc->getErrorOutput();

            $cmd_result[] = [
                'cmd' => $command,
                'result' => [
                    'exitCode' => $exitCode,
                    'stderr' => $stderr
                ]
            ];

            unset($proc);
            unset($exitCode);
            unset($output);
            unset($stderr);
        }

        if (count($parallel_cmd) > 0) {
            $this->parallelExcute($parallel_cmd);
            unset($parallel_cmd);
        }

        $connection->send(json_encode([
            'snmp_result' => $snmp,
            'cmd_result' => $cmd_result
        ], JSON_UNESCAPED_UNICODE));

        // TODO review clean
        unset($snmp);
        unset($cmd);
        unset($cmd_result);
        unset($parallel);
        unset($multi_set);
    }

    public function onWorkerStop()
    {
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(DynamicConfig $definition)
    {
        Worker::runAll();
    }

    // 验证set action数据结构
    private function validateSnmpSet($action)
    {
        $key_check = array_key_exists('oid', $action) && array_key_exists('type', $action) && array_key_exists('value', $action);
        if (!$key_check) {
            return false;
        }
        if (!in_array($action['type'], $this::SNMP_SET_TYPE)) {
            return false;
        }
        // $value_check = empty($action['oid']) || empty($action['type']) || empty($action['value']);
        // if ($value_check) {
        //     return false;
        // }
        return true;
    }

    // 并行执行指令
    private function parallelExcute($parallel_cmd)
    {
        // parallel excute, Only for *unix
        $proc = Process::fromShellCommandline('echo -e "' . implode('\n', $parallel_cmd) . '" | ' . Config::get('parallel', 'parallel'));
        $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));

        $this->logCommand($proc->getCommandLine());

        // run parallel excute for snmpset first
        $proc->run();
        $exitCode = $proc->getExitCode();
        $output = $proc->getOutput();
        $stderr = $proc->getErrorOutput();

        unset($proc);
        unset($exitCode);
        unset($output);
        unset($stderr);
    }

    private function logCommand(string $command): void
    {
        if (Debug::isEnabled()) {
            Log::debug('SNMP Worker[%c' . $command . '%n]', ['color' => true]);
        }
    }

    private function logSocketIO(string $message): void
    {
        if (Debug::isEnabled()) {
            Log::debug('SNMP Worker[%c' . $message . '%n]', ['color' => true]);
        }
    }
}
