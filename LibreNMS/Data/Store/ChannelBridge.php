<?php

namespace LibreNMS\Data\Store;

use LibreNMS\Config;
use Log;
use \Channel\Client as ChannelClient;

class ChannelBridge extends BaseDatastore
{
    protected $redis;

    public function __construct()
    {
        parent::__construct();

        $this->redis = new \Redis();
        try {
            $this->redis->connect(env('REDIS_HOST', 'redis'), env('REDIS_PORT', 6379));
        } catch (\Throwable $th) {
            Log::error('ChannelBridge Error: ' . $th->getMessage());
        }
    }

    public function getName()
    {
        return 'ChannelBridge';
    }

    public static function isEnabled()
    {
        return Config::get('ChannelBridge.enable', true);
    }

    // TODO 进行配置
    const filtered_measurement = ['poller-perf', 'ospf-statistics', 'netstats-ip_forward', 'availability'];
    public function put($device, $measurement, $tags, $fields)
    {
        if (in_array($measurement, $this::filtered_measurement)) {
            return;
        }
        try {
            $topic = 'snmp:'.$device['hostname'].':'.$measurement;
            $this->redis->publish($topic, json_encode([
                'device' => [
                    'device_id' => $device['device_id'],
                    'hostname' => $device['hostname'],
                    'sysName' => $device['sysName'],
                    'ip' => $device['ip'],
                    'overwrite_ip' => $device['overwrite_ip']
                ],
                'measurement' => $measurement,
                'tags' => array_merge($tags, ['rrd_def'=>null]),
                'fields' => $fields,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $th) {
            Log::error('ChannelBridge Error: ' . $th->getMessage());
        }
    }
}
