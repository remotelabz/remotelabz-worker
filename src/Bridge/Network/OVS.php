<?php

namespace App\Bridge\Network;

use \Exception;
use App\Bridge\Bridge;
use App\Bridge\Tools\ArrayTools;
use App\Service\Instance\LogDispatcher;
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

    /**
     * Shutdown all ports of an OVS bridge.
     *
     * @param string $bridge The bridge name.
     * @param LoggerInterface|null $logger Optional logger instance.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return void
     */
    public static function shutdownAllPorts(string $bridge, ?LogDispatcher $logger = null) : void
    {
        if ($logger) {
            $logger->debug("[OVS:shutdownAllPorts]::shutdownAllPorts called for bridge: " . $bridge);
        }

        if (empty($bridge)) {
            throw new Exception("Bridge name cannot be empty.");
        }

        $process = static::portList($bridge);
        $output = trim($process->getOutput());
        
        if (empty($output)) {
            return;
        }

        $ports = explode("\n", $output);
        foreach ($ports as $port) {
            $port = trim($port);
            if (!empty($port)) {
                static::portDown($bridge, $port, $logger);
            }
        }
    }

    public static function portDown(string $bridge, string $port, ?LogDispatcher $logger = null) : Process
    {
        if ($logger) {
            $logger->debug("[OVS:portDown]::Shutting down port: " . $port . " on bridge: " . $bridge);
        }

        if (empty($bridge) || empty($port)) {
            throw new Exception("Bridge and port name cannot be empty.");
        }

        // Récupérer le numéro du port OpenFlow
        $getPortNum = new Process(['ovs-ofctl', 'dump-ports-desc', $bridge]);
        $getPortNum->run();
        
        if (!$getPortNum->isSuccessful()) {
            if ($logger) {
                $logger->error("[OVS:portDown]::Failed to get port number for: " . $port);
            }
            throw new ProcessFailedException($getPortNum);
        }
        
        $output = $getPortNum->getOutput();
        
        if ($logger) {
            $logger->debug("[OVS:portDown]::ovs-ofctl output", ["output" => $output, "port" => $port]);
        }
        
        // Essayer plusieurs patterns possibles
        // Pattern 1: "port_name(number)"
        if (preg_match('/' . preg_quote($port, '/') . '\s*\((\d+)\)/', $output, $matches)) {
            $portNum = $matches[1];
        } 
        // Pattern 2: "number(port_name)"
        elseif (preg_match('/(\d+)\(' . preg_quote($port, '/') . '\)/', $output, $matches)) {
            $portNum = $matches[1];
        }
        // Pattern 3: Chercher juste le port et extraire le numéro avant
        elseif (preg_match('/(\d+).*' . preg_quote($port, '/') . '/', $output, $matches)) {
            $portNum = $matches[1];
        }
        else {
            if ($logger) {
                $logger->error("[OVS:portDown]::Port number not found for port: " . $port . ". Output was: " . $output);
            }
            // Ne pas échouer complètement - le port peut avoir déjà été retiré
            if ($logger) {
                $logger->warning("[OVS:portDown]::Skipping port down for missing port: " . $port);
            }
            
            // Retourner un process vide au lieu de lever une exception
            $dummyProcess = new Process(['true']);
            $dummyProcess->run();
            return $dummyProcess;
        }
        
        // Ajouter une règle pour drop tout le trafic
        $command = ['ovs-ofctl', 'add-flow', $bridge, "priority=65535,in_port={$portNum},actions=drop"];
        
        if ($logger) {
            $logger->debug("[OVS:portDown]::Command executed: " . implode(' ', $command), ["portNum" => $portNum]);
        }
        
        $process = new Process($command);
        $process->run();
        
        if (!$process->isSuccessful()) {
            if ($logger) {
                $logger->error("[OVS:portDown]::Command failed: " . $process->getErrorOutput());
            }
            throw new ProcessFailedException($process);
        }
        
        return $process;
    }


    /**
     * Bring up all ports of an OVS bridge.
     *
     * @param string $bridge The bridge name.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return void
     */
    public static function bringUpAllPorts(string $bridge, ?LogDispatcher $logger = null) : void
    {
        if ($logger) {
            $logger->debug("[OVS:bringUpAllPorts]::Up all ports called for bridge: " . $bridge);
        }
        
        if (empty($bridge)) {
            throw new Exception("Bridge name cannot be empty.");
        }

        $process = static::portList($bridge);
        $output = trim($process->getOutput());
        
        if (empty($output)) {
            return;
        }

        $ports = explode("\n", $output);
        foreach ($ports as $port) {
            $port = trim($port);
            if (!empty($port)) {
                static::portUp($bridge, $port);
            }
        }
    }

    /**
     * Bring up a specific port on an OVS bridge by removing drop rules.
     *
     * @param string $bridge The bridge name.
     * @param string $port The port name.
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the bridge or port name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function portUp(string $bridge, string $port, ?LogDispatcher $logger = null) : Process
    {
        if ($logger) {
            $logger->debug("[OVS:portUp]::Up port: " . $port . " on bridge: " . $bridge);
        }

        if (empty($bridge) || empty($port)) {
            throw new Exception("Bridge and port name cannot be empty.");
        }

        // Récupérer le numéro du port OpenFlow
        $getPortNum = new Process(['ovs-ofctl', 'dump-ports-desc', $bridge]);
        $getPortNum->run();
        
        if (!$getPortNum->isSuccessful()) {
            if ($logger) {
                $logger->error("[OVS:portUp]::Failed to get port number for: " . $port);
            }
            throw new ProcessFailedException($getPortNum);
        }
        
        $output = $getPortNum->getOutput();
        
        if ($logger) {
            $logger->debug("[OVS:portUp]::ovs-ofctl output", ["output" => $output, "port" => $port]);
        }
        
        // Essayer plusieurs patterns possibles
        // Pattern 1: "port_name(number)"
        if (preg_match('/' . preg_quote($port, '/') . '\s*\((\d+)\)/', $output, $matches)) {
            $portNum = $matches[1];
        } 
        // Pattern 2: "number(port_name)"
        elseif (preg_match('/(\d+)\(' . preg_quote($port, '/') . '\)/', $output, $matches)) {
            $portNum = $matches[1];
        }
        // Pattern 3: Chercher juste le port et extraire le numéro avant
        elseif (preg_match('/(\d+).*' . preg_quote($port, '/') . '/', $output, $matches)) {
            $portNum = $matches[1];
        }
        else {
            if ($logger) {
                $logger->error("[OVS:portUp]::Port number not found for port: " . $port . ". Output was: " . $output);
            }
            // Ne pas échouer complètement - le port peut avoir déjà été retiré
            if ($logger) {
                $logger->warning("[OVS:portUp]::Skipping port up for missing port: " . $port);
            }
            
            // Retourner un process vide au lieu de lever une exception
            $dummyProcess = new Process(['true']);
            $dummyProcess->run();
            return $dummyProcess;
        }
        
        // Supprimer les règles de drop pour ce port
        $command = ['ovs-ofctl', 'del-flows', $bridge, "in_port={$portNum}"];
        
        if ($logger) {
            $logger->debug("[OVS:portUp]::Command executed: " . implode(' ', $command), ["portNum" => $portNum]);
        }
        
        $process = new Process($command);
        $process->run();
        
        if (!$process->isSuccessful()) {
            if ($logger) {
                $logger->error("[OVS:portUp]::Command failed: " . $process->getErrorOutput());
            }
            throw new ProcessFailedException($process);
        }
        
        return $process;
    }

    /**
     * Get the status of all ports on an OVS bridge.
     *
     * @param string $bridge The bridge name.
     * @throws Exception If the bridge name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function getPortsStatus(string $bridge) : Process
    {
        if (empty($bridge)) {
            throw new Exception("Bridge name cannot be empty.");
        }

        $command = ['dump-ports-desc', $bridge];
        
        // Note: cette commande utilise ovs-ofctl au lieu de ovs-vsctl
        $process = new Process(array_merge(['ovs-ofctl'], $command));
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        
        return $process;
    }

}