<?php

namespace App\Bridge\Network;

use App\Bridge\Bridge;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Wrapper for the UNIX `ip` command.
 */
class IPTools extends Bridge
{
    const LINK_SET_UP       = 'up';
    const LINK_SET_DOWN     = 'down';
    const TUNTAP_MODE_TAP   = 'tap';

    public static function getCommand(): string
    {
        return 'ip';
    }

    /**
     * Add addresses for one interface.
     *
     * @param string $name The device name.
     * @param string $address The address to add. Should be in CIDR notation.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function addrAdd(string $name, string $address) : Process {
        if (empty($name)) {
            throw new Exception("Device name cannot be empty.");
        }

        $command = [ 'addr', 'add', $address, 'dev', $name ];

        return static::exec($command);
    }

    /**
     * Show informations about addresses for one or all interfaces.
     *
     * @param string $name The device name. If set to `null`, gather informations about all devices.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function addrShow(string $name = null) : Process {
        $command = [ 'addr', 'show' ];

        if (!empty($name)) {
            array_push($command, 'dev', $name);
        }

        return static::exec($command);
    }

    /**
     * Delete an interface.
     *
     * @param string $name The device name.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function linkDelete(string $name) : Process {
        if (empty($name)) {
            throw new Exception("Device name cannot be empty.");
        }

        $command = [ 'link', 'delete', $name ];
        
        return static::exec($command);
    }

    /**
     * Show informations for one or all interfaces.
     *
     * @param string $name The device name. If set to `null`, gather informations about all devices.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function linkShow(string $name = null) : Process {
        $command = [ 'link', 'show' ];

        if (!empty($name)) {
            array_push($command, 'dev', $name);
        }

        return static::exec($command);
    }

    /**
     * Set state for one interface.
     *
     * @param string $name The device name.
     * @param string $operand The operand to execute. Bitmask set by a const from this class.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function linkSet(string $name, string $operand = self::LINK_SET_UP) : Process {
        if (empty($name)) {
            throw new Exception("Device name cannot be empty.");
        }

        $command = [ 'link', 'set', $name, $operand ];
        
        return static::exec($command);
    }

    /**
     * Add a routing table rule.
     *
     * @see https://www.systutorials.com/docs/linux/man/8-ip-route/
     * @param string $route The route to add.
     * @param int $tableId The id of the table to add the route.
     * @throws Exception If the route string is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function routeAdd(string $route, int $tableId = 254) : Process {
        if (empty($route)) {
            throw new Exception("Route cannot be empty.");
        }

        $route = explode(' ', $route);

        $command = [ 'route', 'add' ];
        array_push($command, ...$route);
        array_push($command, 'table', (string) $tableId);
        
        return static::exec($command);
    }

    /**
     * Show existing routes.
     *
     * @see https://www.systutorials.com/docs/linux/man/8-ip-route/
     * @param int $tableId The id of the table to show.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function routeShow(int $tableId = 254) : Process {
        $command = [ 'route', 'show', 'table', (string) $tableId ];
        
        return static::exec($command);
    }

    /**
     * Delete a routing table rule.
     *
     * @see https://www.systutorials.com/docs/linux/man/8-ip-route/
     * @param string $route The route to delete.
     * @param int $tableId The id of the table to delete the route.
     * @throws Exception If the route string is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function routeDelete(string $route, int $tableId = 254) : Process {
        if (empty($route)) {
            throw new Exception("Route cannot be empty.");
        }

        $route = explode(' ', $route);

        $command = [ 'route', 'del' ];
        array_push($command, ...$route);
        array_push($command, 'table', (string) $tableId);
        
        return static::exec($command);
    }

    /**
     * Add a routing policy rule.
     *
     * @see http://man7.org/linux/man-pages/man8/ip-rule.8.html
     * @param string $selector The selector to apply.
     * @param string $action The action to add.
     * @throws Exception If the selector or device is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function ruleAdd(string $selector, string $action) : Process {
        if (empty($selector) || empty($action)) {
            throw new Exception("Selector or action cannot be empty.");
        }

        $selector = explode(' ', $selector);
        $action = explode(' ', $action);

        $command = [ 'rule', 'add' ];
        array_push($command, ...$selector);
        array_push($command, ...$action);

        return static::exec($command);
    }

    /**
     * Delete a routing policy rule.
     *
     * @see http://man7.org/linux/man-pages/man8/ip-rule.8.html
     * @param string $selector The selector to apply.
     * @param string $action The action to delete.
     * @throws Exception If the selector or device is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function ruleDelete(string $selector, string $action) : Process {
        if (empty($selector) || empty($action)) {
            throw new Exception("Selector or action cannot be empty.");
        }

        $selector = explode(' ', $selector);
        $action = explode(' ', $action);

        $command = [ 'rule', 'del' ];
        array_push($command, ...$selector);
        array_push($command, ...$action);

        return static::exec($command);
    }

    /**
     * Add a TUN:TAP interface.
     *
     * @param string $name The device name.
     * @param string $mode The mode for the interface. Bitmask set by a const from this class.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function tuntapAdd(string $name, string $mode = self::TUNTAP_MODE_TAP) : Process {
        if (empty($name)) {
            throw new Exception("Device name cannot be empty.");
        }

        $command = [ 'tuntap', 'add', $name, 'mode', $mode ];

        return static::exec($command);
    }

    public static function routeExists(string $route, int $tableId = 254) : bool
    {
        $output = static::routeShow($tableId);
        $route = preg_quote($route, '/');
        $exists = preg_match('/(' . $route . ')/', $output->getOutput());

        if($exists) {
            return true;
        } else {
            return false;
        }
    }

    public static function networkInterfaceExists(string $name) : bool {
        try {
            $output = static::linkShow($name);
        } catch (ProcessFailedException $exception) {
            return false;
        }

        return true;
    }
}