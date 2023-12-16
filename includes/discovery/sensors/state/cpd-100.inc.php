<?php
// ---- 电路配置 ----
// SNMPv2-SMI::enterprises.9595.1.5 1.3.6.1.4.1.9595.1.5 DIPInfo.0 拨码配置信息（16位拨码配置）(低16位)
// SNMPv2-SMI::enterprises.9595.1.6 1.3.6.1.4.1.9595.1.6 ProMode.0 保护模式 有损-1、无损-0（default）
// SNMPv2-SMI::enterprises.9595.1.7 1.3.6.1.4.1.9595.1.7 SwapMode.0 切换模式（可读、可写） 自动-0（default）、手动1-1、手动2-2

// ---- 拨码配置 ----
$cur_oid = '.1.3.6.1.4.1.9595.1.5';
$index = '0';
$DIPInfo = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.5', '-Ovqe');
$state_name = 'DIPInfo';
$descr = 'DIPInfo';
$states = [];
create_state_index($state_name, $states);
//Discover Sensors
discover_sensor($valid['sensor'], 'state', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $DIPInfo, 'snmp', $index);

//Create Sensor To State Index
create_sensor_to_state_index($device, $state_name, $index);

// ---- 保护模式 ----
$cur_oid = '.1.3.6.1.4.1.9595.1.6';
$index = '0';
$ProMode = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.6', '-Ovqe');;
$state_name = 'ProMode';
$descr = 'Protection Mode';
$states = [
    [ 'value' => 1, 'generic'=>1, 'graph'=>0, 'descr'=>'Lossy'],
    [ 'value' => 0, 'generic'=>0, 'graph'=>0, 'descr'=>'Lossless'],
];
create_state_index($state_name, $states);
//Discover Sensors
discover_sensor($valid['sensor'], 'state', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $ProMode, 'snmp', $index);

//Create Sensor To State Index
create_sensor_to_state_index($device, $state_name, $index);

// ---- 切换模式 ----
$cur_oid = '.1.3.6.1.4.1.9595.1.7';
$index = '0';
// 这里的参数非常重要，特别是 e ,完整参数见 snmpget --help，不然这里无法争取获取数据
$SwapMode = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.7', '-OQUvs');
$state_name = 'SwapMode';
$descr = 'Swap Mode';
$states = [
    [ 'value' => 0, 'generic'=>0, 'graph'=>0, 'descr'=>'Auto'],
    [ 'value' => 1, 'generic'=>1, 'graph'=>0, 'descr'=>'Manual 1'],
    [ 'value' => 2, 'generic'=>2, 'graph'=>0, 'descr'=>'Manual 2'],
];
create_state_index($state_name, $states);
//Discover Sensors
discover_sensor($valid['sensor'], 'state', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $SwapMode, 'snmp', $index);

//Create Sensor To State Index
create_sensor_to_state_index($device, $state_name, $index);

// ---- 装置管理 ----
// 1.3.6.1.4.1.9595.1.8 Reset.0 装置恢复出厂设置 装置恢复出厂设置-1
// 1.3.6.1.4.1.9595.1.9 Reboot.0 装置重启
// $temp = array_values(\SnmpQuery::get([
//     'SNMPv2-SMI::enterprises.9595.1.8',
//     'SNMPv2-SMI::enterprises.9595.1.9',
// ])->values());
// if (is_array($temp)) {
//     $Reset = $temp[0];
//     $Reboot = $temp[1];
// }

// ---- 环回测试 ----
// 1.3.6.1.4.1.9595.1.10 RecircleTest.0 "开始近端环回\远端环回撤销环回"
$RecircleTest = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.10', '-Ovqe');
$cur_oid = '.1.3.6.1.4.1.9595.1.10';
$index = '0';
$state_name = 'RecircleTest';
$descr = 'Recircle Test';
$states = [
    [ 'value' => 1, 'generic'=>0, 'graph'=>0, 'descr'=>'Start User Nearby Side Recircle Test'],
    [ 'value' => 2, 'generic'=>1, 'graph'=>0, 'descr'=>'Start Transport Channel 1 Nearby Side Recircle Test'],
    [ 'value' => 4, 'generic'=>2, 'graph'=>0, 'descr'=>'Start Transport Channel 2 Nearby Side Recircle Test'],
    [ 'value' => 8, 'generic'=>2, 'graph'=>0, 'descr'=>'Start Transport Channel 1 Remote Side Recircle Test'],
    [ 'value' => 16, 'generic'=>2, 'graph'=>0, 'descr'=>'Start Transport Channel 2 Remote Side Recircle Test'],
    [ 'value' => 32, 'generic'=>2, 'graph'=>0, 'descr'=>'Start User Channel 1 Remote Side Recircle Test'],
    [ 'value' => 64, 'generic'=>2, 'graph'=>0, 'descr'=>'Start User Channel 2 Remote Side Recircle Test'],
];
create_state_index($state_name, $states);
//Discover Sensors
discover_sensor($valid['sensor'], 'state', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $RecircleTest, 'snmp', $index);

//Create Sensor To State Index
create_sensor_to_state_index($device, $state_name, $index);

// ---- 性能管理 ----
// 1.3.6.1.4.1.9595.1.13 statisticsStand 统计标准（误码统计） "0: G826 1: M2100"
// 1.3.6.1.4.1.9595.1.14 statisticsTime 统计时间 "0：不统计 1：15min 2:：30min 3：1hour 4：2hour 5：4hour 6：8hour 7：16hour 8：24hour"

// ---- 统计标准（误码统计） ----
$statisticsStand = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.13', '-Ovqe');;
$cur_oid = '.1.3.6.1.4.1.9595.1.13';
$index = '0';
$state_name = 'statisticsStand';
$descr = 'statisticsStand';
$states = [
    [ 'value' => 0, 'generic'=>0, 'graph'=>0, 'descr'=>'G826'],
    [ 'value' => 1, 'generic'=>1, 'graph'=>0, 'descr'=>'M2100']
];
create_state_index($state_name, $states);
//Discover Sensors
discover_sensor($valid['sensor'], 'state', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $statisticsStand, 'snmp', $index);

//Create Sensor To State Index
create_sensor_to_state_index($device, $state_name, $index);

// ---- 统计时间 ----
$statisticsTime = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.14', '-Ovqe');;
$cur_oid = '.1.3.6.1.4.1.9595.1.14';
$index = '0';
$state_name = 'statisticsTime';
$descr = 'statisticsTime';
$states = [
    [ 'value' => 0, 'generic'=>0, 'graph'=>0, 'descr'=>'Off'],
    [ 'value' => 1, 'generic'=>1, 'graph'=>0, 'descr'=>'15 Minutes'],
    [ 'value' => 2, 'generic'=>1, 'graph'=>0, 'descr'=>'30 Minutes'],
    [ 'value' => 3, 'generic'=>1, 'graph'=>0, 'descr'=>'1 Hour'],
    [ 'value' => 4, 'generic'=>1, 'graph'=>0, 'descr'=>'2 Hour'],
    [ 'value' => 5, 'generic'=>1, 'graph'=>0, 'descr'=>'4 Hour'],
    [ 'value' => 6, 'generic'=>1, 'graph'=>0, 'descr'=>'8 Hour'],
    [ 'value' => 7, 'generic'=>1, 'graph'=>0, 'descr'=>'16 Hour'],
    [ 'value' => 8, 'generic'=>1, 'graph'=>0, 'descr'=>'24 Hour'],
];
create_state_index($state_name, $states);
//Discover Sensors
discover_sensor($valid['sensor'], 'state', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $statisticsTime, 'snmp', $index);

//Create Sensor To State Index
create_sensor_to_state_index($device, $state_name, $index);

// ---- 告警管理 ----
// 1.3.6.1.4.1.9595.1.16 Alarm.0 从0~19位对应20种告警，设备失电告警两种场景，主从情况下，从掉电，主出。非主从情况下，对端端发出。
// SnmpTrap和这里应该无关，不是管理平台？

// ---- 工具 ----
// 1.3.6.1.4.1.9595.1.17 TimeSync.0 Ipaddress 时间校正、同步-手动 手动配置时钟源IP地址
// 1.3.6.1.4.1.9999.1.18 TimeSync.1 时间校正、同步-自动 时间：00:00:00（24） (只能SET?)
// $tools = array_values(\SnmpQuery::get([
//     'SNMPv2-SMI::enterprises.9595.1.17',
//     'SNMPv2-SMI::enterprises.9595.1.18',
// ])->values());
// if (is_array($tools)) {
//     var_dump($tools);
// }

// ---- 灯状态 ----
// 1.3.6.1.4.1.9595.1.19 DeviceLed.0 设备面板共计26个灯的状态查询
$DeviceLed = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.19', '-Ovqe');
$cur_oid = '.1.3.6.1.4.1.9595.1.19';
$index = '0';
$state_name = 'DeviceLed';
$descr = 'DeviceLed';
$states = [];
create_state_index($state_name, $states);
//Discover Sensors
discover_sensor($valid['sensor'], 'state', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $DeviceLed, 'snmp', $index);

//Create Sensor To State Index
create_sensor_to_state_index($device, $state_name, $index);

// ---- 对端设备信息 ----
// 1.3.6.1.4.1.9595.1.20 remoteDevice 对端设备信息
// $temp = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.20', '-Ovqe');
// var_dump($temp);


// current是电流的含义，所以放在这个分类下面也不合适
// ---- 电路配置 ----
// 1.3.6.1.4.1.9595.1.5 DIPInfo.0 拨码配置信息（16位拨码配置）
// $DIPInfo = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.5', '-Ovqe');
// $cur_oid = '.1.3.6.1.4.1.9595.1.5';
// $index = '0';
// $state_name = 'DIPInfo';
// $descr = 'DIPInfo';
// //Discover Sensors
// discover_sensor($valid['sensor'], 'current', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $DIPInfo);

// // ---- 延时设置 ----
// // 1.3.6.1.4.1.9595.1.11 CmdDelay.0 命令延时时间设置 通道切换中的保持时间（1~120ms）
// $CmdDelay = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.11', '-Ovqe');
// $cur_oid = '.1.3.6.1.4.1.9595.1.11';
// $index = '0';
// $state_name = 'CmdDelay';
// $descr = 'CmdDelay (1~120ms)';
// //Discover Sensors
// discover_sensor($valid['sensor'], 'current', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $CmdDelay);

// // ---- 性能管理 ----
// // 1.3.6.1.4.1.9595.1.15 statisticsLog 统计日志 ES ,ESR, SES, SESR
// $statisticsLog = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.15', '-Ovqe');
// $cur_oid = '.1.3.6.1.4.1.9595.1.15';
// $index = '0';
// $state_name = 'statisticsLog';
// $descr = 'statisticsLog';
// //Discover Sensors
// discover_sensor($valid['sensor'], 'current', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $statisticsLog);

// // ---- 工具 ----
// // 1.3.6.1.4.1.9595.1.17 TimeSync.0 Ipaddress 时间校正、同步-手动 手动配置时钟源IP地址

// // ---- 对端设备信息 ----
// // 1.3.6.1.4.1.9595.1.20 remoteDevice 对端设备信息
// $remoteDevice = snmp_get($device, 'SNMPv2-SMI::enterprises.9595.1.20', '-Ovqe');
// $cur_oid = '.1.3.6.1.4.1.9595.1.20';
// $index = '0';
// $state_name = 'remoteDevice';
// $descr = 'remoteDevice';
// //Discover Sensors
// discover_sensor($valid['sensor'], 'current', $device, $cur_oid, $index, $state_name, $descr, 1, 1, null, null, null, null, $remoteDevice);