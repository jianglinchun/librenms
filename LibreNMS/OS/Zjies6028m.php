<?php

namespace LibreNMS\OS;

use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Polling\ProcessorPolling;
use LibreNMS\OS;

// 中金鼎迅ZJIES 6028M型号设备支持
class Zjies6028m extends OS implements ProcessorDiscovery, ProcessorPolling
{
    // SNMPv2-SMI::enterprises.9595.1.12 1.3.6.1.4.1.9595.1.12 CpuRam.0 integer32 CPU\内存占用情况 前16位CPU，后16位内存
    public function discoverProcessors()
    {
        $processors = [];

        $CpuRam = snmp_get($this->getDeviceArray(), '.1.3.6.1.4.1.3807.1.8012.1.4.1.1.2.202576128', '-Ovq');
        $proc = new Processor();
        $proc->processor_type = $this->getName();
        $proc->device_id = $this->getDeviceId();
        $proc->processor_index = "0";
        $proc->processor_descr = 'processor';
        $proc->processor_oid = '.1.3.6.1.4.1.3807.1.8012.1.4.1.1.2.202576128';
        $proc->processor_usage = intval($CpuRam) / 100;
        $processors[] = $proc;

        return $processors;
    }

    public function pollProcessors(array $processors)
    {
        $data = [];

        foreach ($processors as $processor) {
            $CpuRam = snmp_get($this->getDeviceArray(), $processor['processor_oid'], '-Oqv');
            $data[$processor['processor_id']] = intval($CpuRam) / 100;
        }
        return $data;
    }
}
