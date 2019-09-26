<?php

namespace App\Bridge\Hypervisor;

interface HypervisorInterface
{
    public function start($device);
    public function stop($device);
}