<?php

/**
 * Listener.php
 *
 * Creates the correct handler for the trap and then sends it the trap.
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
 *
 * @copyright  www.ithubs.net
 * @author     jiang <jianglinchun@gmail.com>
 */

namespace LibreNMS\Snmptrap;

use App\Models\Eventlog;
use LibreNMS\Alert\AlertRules;
use LibreNMS\Config;
use LibreNMS\Snmptrap\Handlers\Fallback;
use Log;
use FreeDSx\Snmp\TrapSink;
use FreeDSx\Snmp\Trap\TrapContext;
use FreeDSx\Snmp\Trap\TrapListenerInterface;
use FreeDSx\Snmp\Protocol\TrapProtocolHandler;
use FreeDSx\Snmp\Message\Request\MessageRequest;
use FreeDSx\Snmp\Protocol\SnmpEncoder;
use FreeDSx\Snmp\Message\EngineId;
use FreeDSx\Snmp\Module\SecurityModel\Usm\UsmUser;
use FreeDSx\Snmp\Message\Request\MessageRequestV1;
use FreeDSx\Snmp\Message\Request\MessageRequestV2;
use FreeDSx\Snmp\Message\Request\MessageRequestV3;
use App\Models\Device;

class Listener implements TrapListenerInterface
{
    /**
     * @var array
     */
    protected $users = [];

    public function __construct()
    {
        $user1 = UsmUser::withPrivacy('user1', 'auth-password123', 'sha512', 'priv-password123', 'aes128');

        $this->users[EngineId::fromText('foobar123')->toBinary()]['user1'] = $user1;
    }

    /**
     * {@inheritdoc}
     */
    public function accept(string $ip): bool
    {
        # Implement any logic here for IP addresses you want to accept / decline...
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getUsmUser(EngineId $engineId, string $ipAddress, string $user): ?UsmUser
    {
        # Assuming we have an array populated with the engine and users associated with it...
        if (isset($this->users[$engineId->toBinary()]) && isset($this->users[$engineId->toBinary()][$user])) {
            return $this->users[$engineId->toBinary()][$user];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function receive(TrapContext $context): void
    {
        # The full SNMP message
        $message = $context->getMessage();
        # The IP address the trap came from
        $ipAddress = $context->getIpAddress();
        # The trap request object only
        $trap = $context->getTrap();
        # The SNMP version that was used
        $version = $context->getVersion();

        $community = $message->getCommunity();
        $version = $message->getVersion();

        $pdu = $message->getRequest();
        $id = $pdu->getId();
        $errorStatus = $pdu->getErrorStatus();
        $errorIndex = $pdu->getErrorIndex();

        if ($message instanceof MessageRequestV1) {
            $v1_ipAddress = $pdu->getIpAddress()->getValue();
            echo 'trap:v1:'. $ipAddress. ':'. $v1_ipAddress . PHP_EOL;
        }
        if ($message instanceof MessageRequestV2) {
            // var_dump($message);
            echo 'trap:v2:'. $ipAddress . PHP_EOL;
        }
        if ($message instanceof MessageRequestV3) {
            echo 'trap:v3:'. $ipAddress . PHP_EOL;
        }

        $oidList = $pdu->getOids();

        $device = Device::findByIp($ipAddress);
        if (!$device) {
            echo 'SNMP Trap: No Host Record for --> ' . $ipAddress . PHP_EOL;
            return;
        }

        foreach ($oidList as $oidItem) {
            $oid = $oidItem->getOid();
            $status = $oidItem->getStatus();
            $value = $oidItem->getValue()->getValue();

            if (strcasecmp($oid, '1.3.6.1.4.1.9595.1.16') == 0) {
                $handler = app(\LibreNMS\Interfaces\SnmptrapHandler::class, ['SNMPv2-SMI::enterprises.9595.1.16']);
                if (method_exists($handler, '_handle')) {
                    $handler->_handle($device, $value);
                }
            }
        }
    }
}
