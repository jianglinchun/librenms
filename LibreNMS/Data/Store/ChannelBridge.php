<?php

namespace LibreNMS\Data\Store;

use LibreNMS\Config;
use Log;
use \Channel\Client as ChannelClient;

class ChannelBridge extends BaseDatastore
{
    public function __construct()
    {
        parent::__construct();

        ChannelClient::connect('php73', 2161);
    }

    public function getName()
    {
        return 'ChannelBridge';
    }

    public static function isEnabled()
    {
        return Config::get('ChannelBridge.enable', true);
    }

    public function put($device, $measurement, $tags, $fields)
    {
        ChannelClient::publish('snmp_get_data', [
            'device' => [
                'device_id' => $device['device_id'],
                'hostname' => $device['hostname'],
                'sysName' => $device['sysName'],
                'ip' => $device['ip'],
                'overwrite_ip' => $device['overwrite_ip']
            ],
            'measurement' => $measurement,
            'tags' => $tags,
            'fields' => $fields,
        ]);
    }
}