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
        if (!static::isValidChain($chain)) {
            throw new UnexpectedValueException($chain . ' is not a valid chain name.');
        }

        $command = [ '-A', $chain ];

        if (!empty($name)) {
            array_unshift($command, '-t', $table);
        }

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
        if (!static::isValidChain($chain)) {
            throw new UnexpectedValueException($chain . ' is not a valid chain name.');
        }

        $command = [ '-D', $chain ];

        if (!empty($name)) {
            array_unshift($command, '-t', $table);
        }

        $rule = $rule->export();

        array_push($command, ...$rule);

        return static::exec($command);
    }
}