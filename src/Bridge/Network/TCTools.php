<?php

namespace App\Bridge\Network;

use \Exception;
use App\Bridge\Bridge;
use App\Bridge\Tools\ArrayTools;
use App\Service\Instance\LogDispatcher;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Wrapper for the `tc` (Traffic Control) command.
 * Allows bandwidth limiting, latency control, packet loss simulation, etc.
 */
class TCTools extends Bridge
{
    const QDISC_HTB = 'htb';
    const QDISC_TBF = 'tbf';
    const QDISC_NETEM = 'netem';
    const QDISC_PRIO = 'prio';
    
    public static function getCommand(): string
    {
        return 'tc';
    }

    /**
     * Add a queueing discipline (qdisc) to an interface.
     *
     * @param string $interface The network interface name.
     * @param string $qdisc The qdisc type (htb, tbf, netem, etc.).
     * @param string $handle The handle identifier (e.g., "1:0").
     * @param array $options Additional options for the qdisc.
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function qdiscAdd(string $interface, string $qdisc, string $handle = '1:0', array $options = [], ?LogDispatcher $logger = null): Process
    {
        if (empty($interface)) {
            throw new Exception("Interface name cannot be empty.");
        }

        $command = ['qdisc', 'add', 'dev', $interface, 'root', 'handle', $handle, $qdisc];
        
        foreach ($options as $key => $value) {
            if (is_numeric($key)) {
                $command[] = $value;
            } else {
                $command[] = $key;
                $command[] = $value;
            }
        }

        if ($logger) {
            $logger->debug("[TCTools:qdiscAdd]::Adding qdisc {$qdisc} to interface {$interface}");
        }

        return static::exec($command);
    }

    /**
     * Delete a queueing discipline from an interface.
     *
     * @param string $interface The network interface name.
     * @param string $parent The parent (default: 'root').
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function qdiscDelete(string $interface, string $parent = 'root', ?LogDispatcher $logger = null): Process
    {
        if (empty($interface)) {
            throw new Exception("Interface name cannot be empty.");
        }

        $command = ['qdisc', 'del', 'dev', $interface, $parent];

        if ($logger) {
            $logger->debug("[TCTools:qdiscDelete]::Deleting qdisc from interface {$interface}");
        }

        return static::exec($command);
    }

    /**
     * Show queueing disciplines for an interface.
     *
     * @param string|null $interface The network interface name. If null, shows all interfaces.
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function qdiscShow(?string $interface = null, ?LogDispatcher $logger = null): Process
    {
        $command = ['qdisc', 'show'];

        if (!empty($interface)) {
            $command[] = 'dev';
            $command[] = $interface;
        }

        if ($logger) {
            $logger->debug("[TCTools:qdiscShow]::Showing qdisc" . ($interface ? " for interface {$interface}" : " for all interfaces"));
        }

        return static::exec($command);
    }

    /**
     * Add a traffic class to an interface.
     *
     * @param string $interface The network interface name.
     * @param string $parent The parent handle (e.g., "1:0").
     * @param string $classid The class identifier (e.g., "1:1").
     * @param string $qdisc The qdisc type (htb, etc.).
     * @param array $options Additional options for the class.
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function classAdd(string $interface, string $parent, string $classid, string $qdisc, array $options = [], ?LogDispatcher $logger = null): Process
    {
        if (empty($interface)) {
            throw new Exception("Interface name cannot be empty.");
        }

        $command = ['class', 'add', 'dev', $interface, 'parent', $parent, 'classid', $classid, $qdisc];
        
        foreach ($options as $key => $value) {
            if (is_numeric($key)) {
                $command[] = $value;
            } else {
                $command[] = $key;
                $command[] = $value;
            }
        }

        if ($logger) {
            $logger->debug("[TCTools:classAdd]::Adding class {$classid} to interface {$interface}");
        }

        return static::exec($command);
    }

    /**
     * Delete a traffic class from an interface.
     *
     * @param string $interface The network interface name.
     * @param string $parent The parent handle.
     * @param string $classid The class identifier.
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function classDelete(string $interface, string $parent, string $classid, ?LogDispatcher $logger = null): Process
    {
        if (empty($interface)) {
            throw new Exception("Interface name cannot be empty.");
        }

        $command = ['class', 'del', 'dev', $interface, 'parent', $parent, 'classid', $classid];

        if ($logger) {
            $logger->debug("[TCTools:classDelete]::Deleting class {$classid} from interface {$interface}");
        }

        return static::exec($command);
    }

    /**
     * Show traffic classes for an interface.
     *
     * @param string $interface The network interface name.
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function classShow(string $interface, ?LogDispatcher $logger = null): Process
    {
        if (empty($interface)) {
            throw new Exception("Interface name cannot be empty.");
        }

        $command = ['class', 'show', 'dev', $interface];

        if ($logger) {
            $logger->debug("[TCTools:classShow]::Showing classes for interface {$interface}");
        }

        return static::exec($command);
    }

    /**
     * Limit bandwidth on an interface using HTB (Hierarchical Token Bucket).
     *
     * @param string $interface The network interface name.
     * @param string $rate The maximum bandwidth (e.g., "1mbit", "100kbit").
     * @param string|null $ceil The ceiling bandwidth (optional).
     * @param string|null $burst The burst size (optional).
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name or rate is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function limitBandwidth(string $interface, string $rate, ?string $ceil = null, ?string $burst = null, ?LogDispatcher $logger = null): Process
    {
        if (empty($interface) || empty($rate)) {
            throw new Exception("Interface name and rate cannot be empty.");
        }

        // Delete existing qdisc if any
        try {
            static::qdiscDelete($interface, 'root', $logger);
        } catch (ProcessFailedException $e) {
            // Interface might not have a qdisc, continue
        }

        // Add HTB qdisc
        static::qdiscAdd($interface, self::QDISC_HTB, '1:0', ['default' => '1'], $logger);

        // Add class with bandwidth limit
        $options = [
            'rate' => $rate,
        ];

        if ($ceil !== null) {
            $options['ceil'] = $ceil;
        }

        if ($burst !== null) {
            $options['burst'] = $burst;
        }

        if ($logger) {
            $logger->debug("[TCTools:limitBandwidth]::Limiting bandwidth to {$rate} on interface {$interface}");
        }

        return static::classAdd($interface, '1:0', '1:1', self::QDISC_HTB, $options, $logger);
    }

    /**
     * Add network emulation (latency, packet loss, etc.) to an interface using netem.
     *
     * @param string $interface The network interface name.
     * @param int|null $delay Delay in milliseconds (optional).
     * @param int|null $delayVariation Delay variation/jitter in milliseconds (optional).
     * @param float|null $loss Packet loss percentage (0-100) (optional).
     * @param float|null $corrupt Packet corruption percentage (0-100) (optional).
     * @param float|null $duplicate Packet duplication percentage (0-100) (optional).
     * @param float|null $reorder Packet reordering percentage (0-100) (optional).
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function addNetworkEmulation(
        string $interface, 
        ?int $delay = null, 
        ?int $delayVariation = null, 
        ?float $loss = null, 
        ?float $corrupt = null, 
        ?float $duplicate = null,
        ?float $reorder = null,
        ?LogDispatcher $logger = null
    ): Process {
        if (empty($interface)) {
            throw new Exception("Interface name cannot be empty.");
        }

        // Delete existing qdisc if any
        try {
            static::qdiscDelete($interface, 'root', $logger);
        } catch (ProcessFailedException $e) {
            // Interface might not have a qdisc, continue
        }

        $options = [];

        if ($delay !== null) {
            $options[] = 'delay';
            $options[] = "{$delay}ms";
            
            if ($delayVariation !== null) {
                $options[] = "{$delayVariation}ms";
            }
        }

        if ($loss !== null) {
            $options[] = 'loss';
            $options[] = "{$loss}%";
        }

        if ($corrupt !== null) {
            $options[] = 'corrupt';
            $options[] = "{$corrupt}%";
        }

        if ($duplicate !== null) {
            $options[] = 'duplicate';
            $options[] = "{$duplicate}%";
        }

        if ($reorder !== null) {
            $options[] = 'reorder';
            $options[] = "{$reorder}%";
        }

        if ($logger) {
            $logger->debug("[TCTools:addNetworkEmulation]::Adding network emulation to interface {$interface}");
        }

        return static::qdiscAdd($interface, self::QDISC_NETEM, '1:0', $options, $logger);
    }

    /**
     * Add latency to an interface.
     *
     * @param string $interface The network interface name.
     * @param int $delay Delay in milliseconds.
     * @param int|null $jitter Delay variation/jitter in milliseconds (optional).
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name or delay is invalid.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function addLatency(string $interface, int $delay, ?int $jitter = null, ?LogDispatcher $logger = null): Process
    {
        if ($logger) {
            $logger->debug("[TCTools:addLatency]::Adding {$delay}ms latency to interface {$interface}");
        }

        return static::addNetworkEmulation($interface, $delay, $jitter, null, null, null, null, $logger);
    }

    /**
     * Add packet loss to an interface.
     *
     * @param string $interface The network interface name.
     * @param float $loss Packet loss percentage (0-100).
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name or loss percentage is invalid.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function addPacketLoss(string $interface, float $loss, ?LogDispatcher $logger = null): Process
    {
        if ($loss < 0 || $loss > 100) {
            throw new Exception("Packet loss percentage must be between 0 and 100.");
        }

        if ($logger) {
            $logger->debug("[TCTools:addPacketLoss]::Adding {$loss}% packet loss to interface {$interface}");
        }

        return static::addNetworkEmulation($interface, null, null, $loss, null, null, null, $logger);
    }

    /**
     * Reset all traffic control rules on an interface.
     *
     * @param string $interface The network interface name.
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function reset(string $interface, ?LogDispatcher $logger = null): Process
    {
        if ($logger) {
            $logger->debug("[TCTools:reset]::Resetting traffic control on interface {$interface}");
        }

        return static::qdiscDelete($interface, 'root', $logger);
    }

    /**
     * Check if an interface has traffic control rules configured.
     *
     * @param string $interface The network interface name.
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name is empty.
     * @return bool True if traffic control is configured, false otherwise.
     */
    public static function hasTrafficControl(string $interface, ?LogDispatcher $logger = null): bool
    {
        if (empty($interface)) {
            throw new Exception("Interface name cannot be empty.");
        }

        try {
            $process = static::qdiscShow($interface, $logger);
            $output = trim($process->getOutput());
            
            // Check if there's a non-default qdisc (not just "noqueue" or "pfifo_fast")
            return !empty($output) && 
                   !preg_match('/^qdisc (noqueue|pfifo_fast)/', $output);
        } catch (ProcessFailedException $e) {
            return false;
        }
    }

    /**
     * Create a complete traffic shaping configuration with bandwidth limit and network conditions.
     *
     * @param string $interface The network interface name.
     * @param string $rate The maximum bandwidth (e.g., "1mbit", "100kbit").
     * @param int|null $delay Delay in milliseconds (optional).
     * @param float|null $loss Packet loss percentage (0-100) (optional).
     * @param LogDispatcher|null $logger Optional logger instance.
     * @throws Exception If the interface name or rate is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return void
     */
    public static function configureTrafficShaping(
        string $interface, 
        string $rate, 
        ?int $delay = null, 
        ?float $loss = null, 
        ?LogDispatcher $logger = null
    ): void {
        if (empty($interface) || empty($rate)) {
            throw new Exception("Interface name and rate cannot be empty.");
        }

        // Reset existing configuration
        try {
            static::reset($interface, $logger);
        } catch (ProcessFailedException $e) {
            // No existing configuration, continue
        }

        // Add HTB root qdisc for bandwidth control
        static::qdiscAdd($interface, self::QDISC_HTB, '1:0', ['default' => '12'], $logger);

        // Add class with bandwidth limit
        static::classAdd($interface, '1:0', '1:1', self::QDISC_HTB, ['rate' => $rate], $logger);

        // If network emulation is needed, add netem as child qdisc
        if ($delay !== null || $loss !== null) {
            $netemOptions = [];
            
            if ($delay !== null) {
                $netemOptions[] = 'delay';
                $netemOptions[] = "{$delay}ms";
            }
            
            if ($loss !== null) {
                $netemOptions[] = 'loss';
                $netemOptions[] = "{$loss}%";
            }

            static::qdiscAdd($interface, self::QDISC_NETEM, '1:1', $netemOptions, $logger);
        }

        if ($logger) {
            $logger->debug("[TCTools:configureTrafficShaping]::Configured traffic shaping on {$interface}: rate={$rate}, delay={$delay}ms, loss={$loss}%");
        }
    }
}