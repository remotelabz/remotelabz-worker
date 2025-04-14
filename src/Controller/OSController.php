<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class OSController extends AbstractController
{

    #[Route('/os', name: 'os')]
    public function osAction(): JsonResponse
    {
        $response = [
            'IP' => [],
            'lxc' => [],
            'qemu' => []
        ];

        $lxcProcess = shell_exec("sudo lxc-ls");       
        $response['lxc'] = array_filter(array_map('trim', explode("\n", $lxcProcess)));
        
        $cmd="ip addr show dev ".$this->getParameter('app.network.lab.interface');
        $ip=shell_exec($cmd);
        if (preg_match('/inet ([0-9.]+)\/[0-9]+/', $ip, $matches)) {
            $response['IP'] = $matches[1];
        } else    $response['IP'] = "";
       
        $cmd="ls /opt/remotelabz-worker/images/";
        $response['qemu'] = array_filter(array_map('trim', explode("\n",shell_exec($cmd))));

        return new JsonResponse($response);
    }

   
}