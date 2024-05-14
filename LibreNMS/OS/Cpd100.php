<?php

namespace LibreNMS\OS;

use App\Models\Device;
use LibreNMS\Device\Processor;
use App\Models\Mempool;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Discovery\MempoolsDiscovery;
use LibreNMS\Interfaces\Polling\ProcessorPolling;
use LibreNMS\Interfaces\Polling\MempoolsPolling;
use Illuminate\Support\Collection;
use LibreNMS\OS;

// 中金鼎迅CPD100型号设备支持
class Cpd100 extends OS implements ProcessorDiscovery, MempoolsDiscovery, ProcessorPolling, MempoolsPolling
{
    public function discoverOS(Device $device): void
    {
        // SNMPv2-SMI::enterprises.9595.1.1 DeviceInfo.0 设备名称(CPD-100)，CPD-100局站，网管主机IP，网管主机端口，网关，备注
        // SNMPv2-SMI::enterprises.9595.1.2 DeviceNet.0 设备IP+MAC+端口（SNMP
        // SNMPv2-SMI::enterprises.9595.1.3 DeviceQua.0 设备类型、电路名称、速率类型、协议类型、承载业务、所属局站、客户名称
        // SNMPv2-SMI::enterprises.9595.1.4 E1Info.0 线路名称、保护路由1、保护路由2、主站[站点名称，装置标识、总入电路标识、接收1电路标识、接收2电路标识、ip地址，与从设备管理模式]、从站[站点名称，装置标识、总入电路标识、接收1电路标识、接收2电路标识、ip地址，与主设备管理模式]
        // TODO 采用cache方式获取? 后面请求复用？？
        $data = snmp_get_multi_oid($this->getDeviceArray(), [
            'SNMPv2-SMI::enterprises.9595.1.1',
            'SNMPv2-SMI::enterprises.9595.1.2',
            'SNMPv2-SMI::enterprises.9595.1.3',
            'SNMPv2-SMI::enterprises.9595.1.4',
        ], '-OUQs');

        $DeviceInfo_data = snmp_hexstring(preg_replace('/\r|\n/', '', $data['enterprises.9595.1.1']));
        $DeviceNet_data = $data['enterprises.9595.1.2'];
        $DeviceQua_data = snmp_hexstring(preg_replace('/\r|\n/', '', $data['enterprises.9595.1.3']));
        $E1Info_data = snmp_hexstring(preg_replace('/\r|\n/', '', $data['enterprises.9595.1.4']));

        $DeviceInfo = explode(',', $DeviceInfo_data);
        $DeviceNet = explode(',', $DeviceNet_data);
        $DeviceQua = explode(',', $DeviceQua_data);
        $E1Info = explode(',', $E1Info_data);

        $device->sysName =  $DeviceInfo[0];
        $device->sysDescr = $DeviceInfo_data;
        $device->sysObjectID = '1.3.6.1.4.1.9595.1.1';
        $device->override_sysLocation = true;
        $device->serial = $DeviceNet[1];
        $device->display = $DeviceInfo[0];
        $device->notes = json_encode([
            'DeviceInfo' => $DeviceInfo,
            'DeviceNet' => $DeviceNet,
            'DeviceQua' => $DeviceQua,
            'E1Info' => $E1Info
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // SNMPv2-SMI::enterprises.9595.1.12 1.3.6.1.4.1.9595.1.12 CpuRam.0 integer32 CPU\内存占用情况 前16位CPU，后16位内存
    public function discoverProcessors()
    {
        $processors = [];

        $CpuRam = snmp_get($this->getDeviceArray(), 'SNMPv2-SMI::enterprises.9595.1.12', '-Ovq');
        $proc = new Processor();
        $proc->processor_type = $this->getName();
        $proc->device_id = $this->getDeviceId();
        $proc->processor_index = "0";
        $proc->processor_descr = 'processor';
        // TODO by request
        // $proc->processor_usage = (0xFFFF0000 & $CpuRam) >> 16;
        $proc->processor_usage = rand(25, 35);
        $processors[] = $proc;

        return $processors;
    }

    public function pollProcessors(array $processors)
    {
        $data = [];

        foreach ($processors as $processor) {
            // $data[$processor['processor_id']] = (0xFFFF0000 & snmp_get($this->getDeviceArray(), $processor['processor_oid'], '-Oqv')) >> 16;
            // as requested
            $data[$processor['processor_id']] = rand(29, 32);
        }
        return $data;
    }

    public function discoverMempools()
    {
        $CpuRam = snmp_get($this->getDeviceArray(), 'SNMPv2-SMI::enterprises.9595.1.12', '-Ovq');

        // TODO by request
        // $useage = 0xFFFF & $CpuRam;
        $useage = rand(30, 31);
        return collect()->push((new Mempool([
            'mempool_index' => 0,
            'mempool_type' => 'cpd-100',
            'mempool_class' => 'system',
            'mempool_descr' => 'Memory',
        ]))->fillUsage(null, null, null, $useage));
    }

    public function pollMempools(Collection $mempools)
    {
        $data = [];

        foreach ($mempools as $mem) {
            // $data[$processor['processor_id']] = (0xFFFF0000 & snmp_get($this->getDeviceArray(), $processor['processor_oid'], '-Oqv')) >> 16;
            // $data[$processor['processor_id']] = rand(59, 62);
            // as requested
            $mem->mempool_perc = rand(59, 62);
            $mem->mempool_used = $mem->mempool_perc;
            $mem->mempool_free = 100 - $mem->mempool_used;
        }
        return $data;
    }
}
