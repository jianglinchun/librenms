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
use LibreNMS\Data\Source\NetSnmpQuery;
use Log;
use PHPSocketIO\SocketIO;
use Workerman\Redis\Client as RedisClient;

class SnmpWorker extends LnmsCommand
{
    protected $name = 'snmp:worker';

    // TODO 配置界面
    const LIBRENMS_BRIDGE_SOCKETIO_PORT = 3161;
    const LIBRENMS_BRIDGE_MEASUREMENT = ['processors', 'mempool', 'ports', 'sensor'];

    protected $worker;
    protected $socketIO;

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

    protected function initSocketIO()
    {
        $io = new SocketIO($this::LIBRENMS_BRIDGE_SOCKETIO_PORT);
        $this->socketIO = $io;
        $this->socketIO->on('workerStart', function () use ($io) {
            // TODO redis地址可配置
            $redis = new RedisClient('redis://redis:6379');
            // TODO topic可配置
            // Workerman redis组件参考
            //  不能采用phpredis，否则会导致整个进程busy !!!!!
            //  必须采用Workerman\Redis\Client
            //  https://www.workerman.net/doc/workerman/components/workerman-redis.html
            $redis->psubscribe(['snmp:*'], function ($pattern, $channel, $message) use ($io) {
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
                    // echo json_encode($snmp_data) . PHP_EOL;
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
        });
        $this->socketIO->on('connection', function ($socket) use ($io) {
            $headers = $socket->request->headers;
            $real_ip = $headers['x-real-ip'];
            $user_agent = $headers['user-agent'];
            $socket->on('watch', function ($msg) use ($io, $socket, $real_ip) {
                $socket->join($msg);
                echo 'watched:' . $real_ip . '-->' . $msg . PHP_EOL;
            });
            $socket->on('unWatch', function ($msg) use ($io, $socket, $real_ip) {
                $socket->leave($msg);
                echo 'unWatched:' . $real_ip . '-->' . $msg . PHP_EOL;
            });
        });
    }

    public function onWorkerStart()
    {

    }

    // 处理HTTP请求
    public function onMessage(TcpConnection $connection, Request $request)
    {
        $snmp = $request->post('snmp', []);
        $cmd = $request->post('cmd', []);

        for ($i = 0; $i < count($snmp); $i++) {
            $device_actions = $snmp[$i];
            $ip = $device_actions['ip'];
            $device = Device::findByIp($ip);
            if (key_exists('set', $device_actions)) {
                for ($j = 0; $j < count($device_actions['set']); $j++) {
                    $action = $device_actions['set'][$j];
                    $action_cmd = NetSnmpQuery::make()->device($device)->buildCli('snmpset', [$action['oid']]);
                    $action_cmd[] = $action['type'];
                    $action_cmd[] = $action['value'];

                    $proc = new Process($action_cmd);
                    $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));

                    $this->logCommand($proc->getCommandLine());

                    $proc->run();
                    $exitCode = $proc->getExitCode();
                    $output = $proc->getOutput();
                    $stderr = $proc->getErrorOutput();

                    $snmp[$i]['set'][$j]['result'] = [
                        'exitCode' => $exitCode,
                        'stderr' => $stderr
                    ];
                }
            }
            // 批量处理模式，CPD100设备支持有问题
            // if (key_exists('set', $device_actions)) {
            //     $action_cmd = NetSnmpQuery::make()->device($device)->buildCli('snmpset', []);
            //     foreach ($device_actions['set'] as $action) {
            //         $action_cmd[] = $action['oid'];
            //         $action_cmd[] = $action['type'];
            //         $action_cmd[] = $action['value'];
            //     }
            //     $proc = new Process($action_cmd);
            //     $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));

            //     $this->logCommand($proc->getCommandLine());

            //     $proc->run();
            //     $exitCode = $proc->getExitCode();
            //     $output = $proc->getOutput();
            //     $stderr = $proc->getErrorOutput();

            //     $action['result'] = [
            //         'exitCode' => $exitCode,
            //         'stderr' => $stderr
            //     ];
            // }
        }

        $cmd_result = [];
        foreach ($cmd as $command) {

            $proc = new Process(explode(' ', $command));
            $proc->setTimeout(Config::get('snmp.exec_timeout', 1200));

            $this->logCommand($proc->getCommandLine());

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
        }

        $connection->send(json_encode([
            'snmp_result' => $snmp,
            'cmd_result' => $cmd_result
        ], JSON_UNESCAPED_UNICODE));

        // TODO review clean
        unset($snmp);
        unset($cmd);
        unset($cmd_result);
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

    private function logCommand(string $command): void
    {
        if (Debug::isEnabled()) {
            Log::debug('SNMP[%c' . $command . '%n]', ['color' => true]);
        }
    }
}
