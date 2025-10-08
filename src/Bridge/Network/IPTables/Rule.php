<?php

namespace App\Bridge\Network\IPTables;

use UnexpectedValueException;

/**
 * Represents an `iptables` rule.
 */
class Rule
{
    const PROTOCOL_TCP = 'tcp';
    const PROTOCOL_UDP = 'udp';
    const PROTOCOL_ICMP = 'icmp';
    const PROTOCOL_ALL = 'all';

    const STATE_NEW = 'NEW';
    const STATE_ESTABLISHED = 'ESTABLISHED';
    const STATE_RELATED = 'RELATED';
    const STATE_INVALID = 'INVALID';

    private $protocol;
    private $source;
    private $destination;
    private $jump;
    private $goto;
    private $inInterface;
    private $outInterface;
    private $fragment;
    private $sourcePort;
    private $destinationPort;
    private $states;

    public static function create()
    {
        return new static;
    }

    /**
     * Generate a string for the current rule object.
     *
     * @throws UnexpectedValueException if a field is set with an incorrect value.
     * @return string The generated command.
     */
    public function __toString()
    {
        $rule = "";

        if ($this->hasValidProtocol()) {
            $rule .= '--protocol ' . $this->protocol;
        } else {
            throw new UnexpectedValueException();
        }

        if (!empty($this->source)) {
            $rule .= '--source ' . $this->source;
        }

        return $rule;
    }

    /**
     * Example
     * Bloquer UDP sur un port spécifique
     * $rule = Rule::create()
     *  ->setProtocol(Rule::PROTOCOL_UDP)
     *  ->setDestinationPort('53')
     *  ->setSource('192.168.1.0/24')
     *  ->setJump('DROP');
     */

    /**
     * $rule = Rule::create()
     *     ->setProtocol(Rule::PROTOCOL_ALL)
     *     ->setStates(['ESTABLISHED', 'RELATED'])
     *     ->setJump('ACCEPT');
     */

    /**
     * $rule = Rule::create()
     *     ->setProtocol(Rule::PROTOCOL_TCP)
     *     ->setDestinationPort('22')
     *     ->setJump('ACCEPT');
     */

    /**
     * Generate an array for the current rule object.
     *
     * @throws UnexpectedValueException if a field is set with an incorrect value.
     * @return array|string[] The generated command.
     */
    public function export()
    {
        $rule = [];

        if (!empty($this->protocol)) {
            if ($this->hasValidProtocol()) {
                $rule[] = '--protocol';
                $rule[] = $this->protocol;
            } else {
              throw new UnexpectedValueException();  
            }
        }

        if (!empty($this->source)) {
            $rule[] = '--source';
            $rule[] = $this->source;
        }

        if (!empty($this->destination)) {
            $rule[] = '--destination';
            $rule[] = $this->destination;
        }

        // Gestion des ports source (TCP/UDP)
        if (!empty($this->sourcePort)) {
            if ($this->protocol === self::PROTOCOL_TCP || $this->protocol === self::PROTOCOL_UDP) {
                $rule[] = '--source-port';
                $rule[] = $this->sourcePort;
            }
        }

        // Gestion des ports destination (TCP/UDP)
        if (!empty($this->destinationPort)) {
            if ($this->protocol === self::PROTOCOL_TCP || $this->protocol === self::PROTOCOL_UDP) {
                $rule[] = '--destination-port';
                $rule[] = $this->destinationPort;
            }
        }

        // Gestion des états de connexion
        if (!empty($this->states)) {
            $rule[] = '-m';
            $rule[] = 'state';
            $rule[] = '--state';
            $rule[] = is_array($this->states) ? implode(',', $this->states) : $this->states;
        }

        if (!empty($this->jump)) {
            $rule[] = '--jump';
            $rule[] = $this->jump;
        }

        if (!empty($this->goto)) {
            $rule[] = '--goto';
            $rule[] = $this->goto;
        }

        if (!empty($this->inInterface)) {
            $rule[] = '--in-interface';
            $rule[] = $this->inInterface;
        }

        if (!empty($this->outInterface)) {
            $rule[] = '--out-interface';
            $rule[] = $this->outInterface;
        }

        if (!empty($this->fragment)) {
            $rule[] = '--fragment';
            $rule[] = $this->fragment;
        }

        return $rule;
    }

    /**
     * Get the value of protocol
     */ 
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * Set the value of protocol
     *
     * @return  self
     */ 
    public function setProtocol($protocol)
    {
        $this->protocol = $protocol;

        return $this;
    }

    public function hasValidProtocol()
    {
        return in_array($this->protocol, [
            static::PROTOCOL_ALL,
            static::PROTOCOL_ICMP,
            static::PROTOCOL_TCP,
            static::PROTOCOL_UDP
        ]);
    }

    /**
     * Get the value of source
     */ 
    public function getSource()
    {
        return $this->source;
    }

    /**
     * Set the value of source
     *
     * @return  self
     */ 
    public function setSource($source)
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Get the value of destination
     */ 
    public function getDestination()
    {
        return $this->destination;
    }

    /**
     * Set the value of destination
     *
     * @return  self
     */ 
    public function setDestination($destination)
    {
        $this->destination = $destination;

        return $this;
    }

    /**
     * Get the value of jump
     */ 
    public function getJump()
    {
        return $this->jump;
    }

    /**
     * Set the value of jump
     *
     * @return  self
     */ 
    public function setJump($jump)
    {
        $this->jump = $jump;

        return $this;
    }

    /**
     * Get the value of goto
     */ 
    public function getGoto()
    {
        return $this->goto;
    }

    /**
     * Set the value of goto
     *
     * @return  self
     */ 
    public function setGoto($goto)
    {
        $this->goto = $goto;

        return $this;
    }

    /**
     * Get the value of inInterface
     */ 
    public function getInInterface()
    {
        return $this->inInterface;
    }

    /**
     * Set the value of inInterface
     *
     * @return  self
     */ 
    public function setInInterface($inInterface)
    {
        $this->inInterface = $inInterface;

        return $this;
    }

    /**
     * Get the value of outInterface
     */ 
    public function getOutInterface()
    {
        return $this->outInterface;
    }

    /**
     * Set the value of outInterface
     *
     * @return  self
     */ 
    public function setOutInterface($outInterface)
    {
        $this->outInterface = $outInterface;

        return $this;
    }

    /**
     * Get the value of fragment
     */ 
    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * Set the value of fragment
     *
     * @return  self
     */ 
    public function setFragment($fragment)
    {
        $this->fragment = $fragment;

        return $this;
    }

    /**
     * Get the value of sourcePort
     */ 
    public function getSourcePort()
    {
        return $this->sourcePort;
    }

    /**
     * Set the value of sourcePort
     * Peut être un port unique (80) ou une plage (1024:65535)
     *
     * @return  self
     */ 
    public function setSourcePort($sourcePort)
    {
        $this->sourcePort = $sourcePort;

        return $this;
    }

    /**
     * Get the value of destinationPort
     */ 
    public function getDestinationPort()
    {
        return $this->destinationPort;
    }

    /**
     * Set the value of destinationPort
     * Peut être un port unique (80) ou une plage (1024:65535)
     *
     * @return  self
     */ 
    public function setDestinationPort($destinationPort)
    {
        $this->destinationPort = $destinationPort;

        return $this;
    }

    /**
     * Get the value of states
     */ 
    public function getStates()
    {
        return $this->states;
    }

    /**
     * Set the value of states
     * Peut être une chaîne ("ESTABLISHED,RELATED") ou un tableau (["ESTABLISHED", "RELATED"])
     *
     * @return  self
     */ 
    public function setStates($states)
    {
        $this->states = $states;

        return $this;
    }
}