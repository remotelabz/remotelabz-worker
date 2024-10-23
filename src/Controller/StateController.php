<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

class StateController extends AbstractController
{
    
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /** 
     * @Route("/healthcheck", name="healthcheck")
     */
    public function healthcheckAction()
    {
        $messageServiceStateProcess = new Process([
            'systemctl',
            'status',
            'remotelabz-worker',
        ]);

        $returnCode = $messageServiceStateProcess->run();

        $response = [
            'remotelabz-worker' => []
        ];

        if ($returnCode === 0) {
            $response['remotelabz-worker']['isStarted'] = true;
        } else {
            $response['remotelabz-worker']['isStarted'] = false;
        }

        return new JsonResponse($response);
    }

    /** 
     * @Route("/service/{service}", name="manage_service")
     */
    public function manageServiceAction(Request $request, string $service)
    {
        $action = $request->query->get('action');

        $messageServiceStateProcess = new Process([
            'sudo',
            'systemctl',
            $action,
            $service,
        ]);


        try {
            $messageServiceStateProcess->mustRun();
        } catch (ProcessFailedException $e) {
            return new JsonResponse([
                'exitCode' => $messageServiceStateProcess->getExitCode(),
                'error' => $messageServiceStateProcess->getErrorOutput()
            ], 500);
        }

        return new JsonResponse();
    }

    /** 
     * @Route("/stats/{ressource}", name="stats_ressource")
     */
    public function statsRessourceAction(Request $request, string $ressource)
    {

        //$action = $request->query->get('action');
        if ($ressource === "hardware") {
            $messagestatsRessourceProcess = new Process([
                'top',
                '-b','-n2','-p1','-d1'
            ]);


            try {
                $messagestatsRessourceProcess->mustRun();
            } catch (ProcessFailedException $e) {
                return new JsonResponse([
                    'exitCode' => $messagestatsRessourceProcess->getExitCode(),
                    'error' => $messagestatsRessourceProcess->getErrorOutput()
                ], 500);
            }

            $response = [
                'cpu' => [],
                'memory' => [],
                'disk' => [],
                'lxcfs' => []
            ];
            
            $output=explode("\n", $messagestatsRessourceProcess->getOutput());
            
            $response['cpu']=100-(int) round(preg_replace('/^.+ni[, ]+([0-9\.]+) id,.+/', '$1', $output[11]));
            
            
            $messagestatsRessourceProcess = new Process([
                'df',
                '-h', '/'
            ]);

            try {
                $messagestatsRessourceProcess->mustRun();
            } catch (ProcessFailedException $e) {
                return new JsonResponse([
                    'exitCode' => $messagestatsRessourceProcess->getExitCode(),
                    'error' => $messagestatsRessourceProcess->getErrorOutput()
                ], 500);
            }
            $output=explode("\n", $messagestatsRessourceProcess->getOutput());
            $response['disk']=(int) round(preg_replace('/^.+ ([0-9]+)% .+/', '$1', $output[1]));

            $messagestatsRessourceProcess = new Process([
                'cat',
                '/proc/meminfo'
            ]);

            try {
                $messagestatsRessourceProcess->mustRun();
            } catch (ProcessFailedException $e) {
                return new JsonResponse([
                    'exitCode' => $messagestatsRessourceProcess->getExitCode(),
                    'error' => $messagestatsRessourceProcess->getErrorOutput()
                ], 500);
            }
            $output=explode("\n", $messagestatsRessourceProcess->getOutput());
            array_pop($output) ;
            $meminfo = array();
            foreach ($output as $line) {
                list($key, $val) = explode(":", $line);
                $meminfo[$key] = (int) preg_replace('/^([0-9\.]+)\ +.*$/','$1',trim($val));
            }
            $total=$meminfo["MemTotal"];
            $cached=$meminfo["Cached"];
            $avail=$meminfo["MemAvailable"];
            $response['memory']=round(100 - ($avail / $total * 100));
            $response['memory_total']=$total/1000;

            
            $lxcfs=shell_exec("top -b -n2 -d0.2 -p `ps aux | grep -v \"grep\" | grep \"/usr/bin/lxcfs\" |awk '{print $2}'` | tail -1 |awk '{print $9}' | tr -d \"\n\"");
            if (!is_null($lxcfs) && $lxcfs)
                $response['lxcfs']=(int) $lxcfs;
            else 
                $response['lxcfs']="";

               
            $lsof=shell_exec("sudo lsof -w | wc -l");
            if (!is_null($lsof) && $lsof)
                $response['openedfiles']=(int) $lsof;
            else 
                $response['openedfiles']="";

            $this->logger->info("Number of opened file: ".$lsof);


            $lxclsrun=shell_exec("sudo lxc-ls -f | grep RUNNING");
            if (!is_null($lxclsrun) && $lxclsrun)
                $response['lxclsrun']=(int) $lxclsrun;
            else 
                $response['lxclsrun']=0;

            $this->logger->info("Number of LXC containers running: ".$lxclsrun);

            $qemurun=shell_exec("sudo ps a | grep -v grep | grep -e \"qemu\"");
            if (!is_null($qemurun) && $qemurun)
                $response['qemurun']=(int) $qemurun;
            else 
                $response['qemurun']=0;

            $this->logger->info("Number of QEMU VM Running: ".$qemurun);

            return new JsonResponse($response);
        }
        else 
            return new JsonResponse(null);
    }
}