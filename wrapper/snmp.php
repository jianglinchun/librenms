<?php

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader
| for our application. We just need to utilize it! We'll require it
| into the script here so that we do not have to worry about the
| loading of any our classes "manually". Feels great to relax.
|
*/

require __DIR__ . '/../vendor/autoload.php';

// Check that we don't run this as the wrong user and break the install
\App\Checks::runningUser();

$app = require_once __DIR__ . '/../bootstrap/app.php';

use WpOrg\Requests\Requests;

function invoke_snmp_via_rpc($action = 'snmpget', $argc, $argv)
{
    try {
        $cmd = [$action];
        for ($i = 1; $i < $argc; $i++) {
            $cmd[] = $argv[$i];
        }
        // TODO RPC地址可配置
        // TODO RPC的传输方式等等可配置？
        $proxy_response = Requests::post(
            'http://127.0.0.1:8000/_snmp_proxy',
            array(),
            $cmd,
            [
                'timeout' => 20
            ]
        );

        if(!$proxy_response->success) {
            echo $proxy_response->status_code;
            exit(1);
        }
        $proxy_result = json_decode($proxy_response->body);

        $exitCode = $proxy_result[0];
        $output = $proxy_result[1];
        $stderr = $proxy_result[2];

        echo $exitCode == 0 ? $output : $stderr;
        exit($exitCode);
    } catch (\Throwable $th) {
        echo $th->getMessage();
        exit(1);
    }

    exit(0);
}
