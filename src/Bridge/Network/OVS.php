<?php

namespace App\Bridge\Network;

use \Exception;
use App\Bridge\Bridge;
use App\Bridge\Tools\ArrayTools;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Wrapper for the `ovs-vsctl` command.
 */
class OVS extends Bridge
{
    public static function getCommand() : string {
        return 'ovs-vsctl';
    }

    /**
     * Adds a new OVS bridge to the system.
     *
     * @param string $bridge The bridge name.
     * @param bool $mayExist If set to `true`, appends the --may-exist option.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function bridgeAdd(string $bridge, bool $mayExist = false) : Process
    {
        if (empty($bridge)) {
            throw new Exception("Bridge name cannot be empty.");
        }

        $command = ArrayTools::arrayFilterEmpty([ $mayExist ? '--may-exist' : null, 'add-br', $bridge ]);

        return static::exec($command);
    }

    /**
     * Delete an OVS bridge from the system.
     *
     * @param string $bridge The bridge name.
     * @param bool $ifExists If set to `true`, appends the --if-exists option.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function bridgeDelete(string $bridge, bool $ifExists = false) : Process
    {
        if (empty($bridge)) {
            throw new Exception("Bridge name cannot be empty.");
        }

        $command = ArrayTools::arrayFilterEmpty([ $ifExists ? '--if-exists' : null, 'del-br', $bridge ]);

        return static::exec($command);
    }

    /**
     * Adds a new port to an OVS bridge.
     *
     * @param string $bridge The bridge name.
     * @param string $port The port name.
     * @param bool $mayExist If set to `true`, appends the --may-exist option.
     * @param string[] ...$options Options to append to the command.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function portAdd(string $bridge, string $port, bool $mayExist = false, string ...$options) : Process
    {
        if (empty($bridge) || empty($port)) {
            throw new Exception("Bridge and port name cannot be empty.");
        }

        $command = ArrayTools::arrayFilterEmpty([ $mayExist ? '--may-exist' : null, 'add-port', $bridge, $port ]);

        if (!empty($options))
            array_push($command, ...$options);
        
        $command = ArrayTools::arrayFilterEmpty($command);

        return static::exec($command);
    }

    /**
     * Delete a new port to an OVS bridge.
     *
     * @param string $bridge The bridge name.
     * @param string $port The port name.
     * @param bool $ifExists If set to `true`, appends the --if-exists option.
     * @param bool $withInterface If set to `true`, deletes the system network interface as well.
     * @param string[] ...$options Options to append to the command.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function portDelete(string $bridge, string $port, bool $ifExists = false, bool $withInterface = true, string ...$options) : Process
    {
        if (empty($bridge) || empty($port)) {
            throw new Exception("Bridge and port name cannot be empty.");
        }

        $command = ArrayTools::arrayFilterEmpty([ $ifExists ? '--if-exists' : null, $withInterface ? '--with-iface' : null, 'del-port', $bridge, $port ]);

        if (!empty($options))
            array_push($command, ...$options);

        return static::exec($command);
    }

    /**
     * List posts attached to an OVS bridge.
     *
     * @param string $bridge The bridge name.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function portList(string $bridge) : Process
    {
        if (empty($bridge)) {
            throw new Exception("Bridge and port name cannot be empty.");
        }

        $command = [ 'list-ports', $bridge ];

        return static::exec($command);
    }

    /**
     * Set parameters for an OVS interface.
     *
     * @param string $name The interface name.
     * @param array|string[] ...$options Options to append to the command.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function setInterface(string $name, array $options) : Process
    {
        if (empty($options)) {
            throw new Exception("Options array cannot be empty.");
        }

        $command = ArrayTools::arrayFilterEmpty([ 'set', 'interface', $name ]);
        foreach ($options as $key => $value) {
            array_push($command, $key . "=" . $value);
        }

        return static::exec($command);
    }

    public static function ovsPortExists(string $bridge, string $port) : bool
    {
        try {
            $process = static::portList($bridge);
        } catch (ProcessFailedException $exception) {
            return false;
        }

        $output = $process->getOutput();
        if (empty($output)) {
            return false;
        }

        if (strpos($output, $port) !== false) {
            return true;
        }
        
        return false;
    }

    public static function LinkTwoOVS(string $bridge, string $bridgeInt)
    {
        // Create patch between lab's OVS and Worker's OVS
        OVS::portAdd($bridge, "Patch-ovs-".$bridge, true);
        OVS::setInterface("Patch-ovs-".$bridge, [
            'type' => 'patch',
            'options:peer' => "Patch-ovs-".$bridgeInt
        ]);

        OVS::portAdd($bridgeInt, "Patch-ovs-".$bridgeInt, true);
        OVS::setInterface("Patch-ovs-".$bridgeInt, [
            'type' => 'patch',
            'options:peer' => "Patch-ovs-".$bridge
        ]);
    }

    public static function UnlinkTwoOVS(string $bridge, string $bridgeInt)
    {
        if (OVS::ovsPortExists($bridgeInt, "Patch-ovs-".$bridgeInt)) {
            OVS::portDelete($bridgeInt, "Patch-ovs-".$bridgeInt, true);
        }
        
        if (OVS::ovsPortExists($bridge, "Patch-ovs-".$bridge)) {
            OVS::portDelete($bridge, "Patch-ovs-".$bridge, true);
        }
    }
}