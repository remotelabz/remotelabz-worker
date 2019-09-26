<?php

namespace App\Bridge;

class Bridge
{
    protected $instanceData;

    /**
     * @param string $instanceData JSON representation of lab instance data
     */
    public function __construct(string $instanceData)
    {
        $this->instanceData = json_decode($instanceData);
    }

    public function getInstanceData() {
        return $this->instanceData;
    }
}