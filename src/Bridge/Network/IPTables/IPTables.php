<?php

namespace App\Bridge\Network\IPTables;

use App\Bridge\Bridge;
use UnexpectedValueException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Wrapper for the UNIX `iptables` command.
 */
class IPTables extends Bridge
{
    const CHAIN_INPUT = 'INPUT';
    const CHAIN_OUTPUT = 'OUTPUT';
    const CHAIN_FORWARD = 'FORWARD';
    const CHAIN_PREROUTING = 'PREROUTING';
    const CHAIN_POSTROUTING = 'POSTROUTING';

    public static function getCommand(): string
    {
        return 'iptables';
    }

    public static function isValidChain(string $chain)
    {
        return in_array($chain, [
            static::CHAIN_INPUT,
            static::CHAIN_OUTPUT,
            static::CHAIN_FORWARD,
            static::CHAIN_PREROUTING,
            static::CHAIN_POSTROUTING
        ]);
    }

    /**
     * Append one or more rules to the end of the selected chain.
     *
     * @param string $chain The chain to append the rule.
     * @param Rule $rule The rule to add.
     * @param string $table The table to apply the rule.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function append(string $chain, Rule $rule, string $table = null) : Process {
        /*if (!static::isValidChain($chain)) {
            throw new UnexpectedValueException($chain . ' is not a valid chain name.');
        }*/

        $command = [];

        if (!empty($table)) {
            array_push($command, '-t', $table);
        }

        array_push($command,'-A', $chain);

        $rule = $rule->export();
        array_push($command, ...$rule);

        return static::exec($command);
    }

    /**
     * Delete one or more rules to the end of the selected chain.
     *
     * @param string $chain The chain to append the rule.
     * @param Rule $rule The rule to delete.
     * @param string $table The table to apply the rule.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function delete(string $chain, Rule $rule, string $table = null) : Process {
        /* if (!static::isValidChain($chain)) {
            throw new UnexpectedValueException($chain . ' is not a valid chain name.');
        }
        */
        // In case of custom chain, we cannot check the valid change name !
        
        $command = [];

        if (!empty($table)) {
            array_push($command, '-t', $table);
        }

        array_push($command, '-D', $chain);

        $rule = $rule->export();
        array_push($command, ...$rule);

        return static::exec($command);
    }

    /**
     * Check if a rule exists in the selected chain.
     *
     * @param string $chain The chain to append the rule.
     * @param Rule $rule The rule to delete.
     * @param string $table The table to apply the rule.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return bool
     */
    public static function exists(string $chain, Rule $rule, string $table = null) : bool {
        /* if (!static::isValidChain($chain)) {
            throw new UnexpectedValueException($chain . ' is not a valid chain name.');
        } */
        // Creation of custom chain for each lab so we cannot know if the chain is valid or not

        $command = [];

        if (!empty($table)) {
            array_push($command, '-t', $table);
        }

        array_push($command, '-C', $chain);

        $rule = $rule->export();
        array_push($command, ...$rule);

        $output = static::exec($command, false);

        if ($output->getExitCode() == 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Create one or more rules to the end of the selected chain.
     *
     * @param string $chain The chain to append the rule.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function create_chain(string $chain) {
        if (!static::isChainExists($chain)) {
            $command = [];
            array_push($command, '-N', $chain);  
            return static::exec($command);
        }
        return null;
    }

    /**
     * Delete a chain.
     *
     * @param string $chain The chain to append the rule.
     * @return Process The executed process.
     */
    public static function delete_chain(string $chain) {
        if (static::isChainExists($chain)) {    
            static::flush_chain($chain);
            $command = [];
            array_push($command, '-X', $chain);  
            return static::exec($command);
        } else return false;
    }

    /**
     * Delete a chain.
     *
     * @param string $chain The chain to append the rule.
     * @return Process The executed process.
     */
    public static function flush_chain(string $chain) {
        if (static::isChainExists($chain)) {
        $command = [];
        array_push($command, '-F', $chain);  
        return static::exec($command);
        }
        else return false; 
}


    /**
     * Delete one or more rules to the end of the selected chain.
     *
     * @param string $chain The chain to append the rule.
     * @throws Exception If the device name is empty.
     * @throws ProcessFailedException If the process didn't terminate successfully.
     * @return Process The executed process.
     */
    public static function isChainExists(string $chain) : bool {
        $command = [];

        array_push($command, '-L', $chain);

        $output = static::exec($command, false);

        if ($output->getExitCode() == 0) {
            return true;
        } else {
            return false;
        }
    }

}