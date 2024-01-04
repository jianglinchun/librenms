<?php

/**
 * Cpd100Trap.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 */

namespace LibreNMS\Snmptrap\Handlers;

use App\Models\Device;
use LibreNMS\Enum\Severity;
use LibreNMS\Interfaces\SnmptrapHandler;
use LibreNMS\Snmptrap\Trap;
use App\Models\Eventlog;

class Cpd100Trap implements SnmptrapHandler
{
    const alarmPos = [
        '设备失电告警',
        '接入1Los告警',
        '接入1LOF告警',
        '接入1AIS告警',
        '接入1RAI告警',
        '接入1误码门限告警',
        '接入1设备上电',
        '接入1时标丢失告警',
        '接入2Los告警',
        '接入2LOF告警',
        '接入2AIS告警',
        '接入2RAI告警',
        '接入2误码门限告警',
        '接入2设备上电',
        '接入2时标丢失告警',
        '主/备电路切换',
        '备/主电路切换',
        '对端发送失效告警',
        '对端接收失效告警',
    ];
    /**
     * Handle snmptrap.
     * 从0~19位对应20种告警，设备失电告警两种场景，主从情况下，从掉电，主出。非主从情况下，对端端发出。
     *
     * @param  Device  $device
     * @param  Trap  $trap
     * @return void
     */
    public function handle(Device $device, Trap $trap)
    {
        $alarmData = $trap->getOidData($trap->getTrapOid());
        $alarm = [];
        for ($i = 0; $i < 19; $i++) {
            $alarm[] = ($alarmData >> $i) & 0x1;
        }
        $alarm_result = array_filter(array_combine($this::alarmPos, $alarm), function ($arr) {
            return $arr == 1;
        });
        foreach ($alarm_result as $alarm_message => $alarm_flag) {
            $trap->log('SNMP Trap: Cpd-100 Alarm [' . $alarm_message . ']', Severity::Warning);
        }
    }

    // 单独为了记录
    public function _handle($device, $alarmData)
    {
        $alarm = [];
        for ($i = 0; $i < 19; $i++) {
            $alarm[] = ($alarmData >> $i) & 0x1;
        }
        $alarm_result = array_filter(array_combine($this::alarmPos, $alarm), function ($arr) {
            return $arr == 1;
        });
        foreach ($alarm_result as $alarm_message => $alarm_flag) {
            Eventlog::log('SNMP Trap: Cpd-100 Alarm [' . $alarm_message . ']', $device, 'trap', Severity::Warning, null);
        }
    }
}
