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

class SnmpWorker extends LnmsCommand
{
    protected $name = 'snmp:worker';

    protected $worker;

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

        // TODO 参考配置？？？
        $this->worker = new Worker('http://0.0.0.0:8080');

        foreach (['onWorkerStart', 'onConnect', 'onMessage', 'onClose', 'onError', 'onBufferFull', 'onBufferDrain', 'onWorkerStop', 'onWorkerReload'] as $event) {
            if (method_exists($this, $event)) {
                $this->worker->$event = [$this, $event];
            }
        }

        Worker::$logFile = '/data/logs/workerman.log';

        // 避免重启以后该文件仍然存在，导致无法启动新进程的问题，所以修改到/tmp目录下
        Worker::$pidFile = '/tmp/snmpworker.pid';
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
