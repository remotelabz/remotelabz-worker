<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimeOutException;
use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

class StateController extends AbstractController
{
    
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    #[Route('/healthcheck', name: 'healthcheck')]
    public function healthcheckAction(): JsonResponse
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

    #[Route('/service/{service}', name: 'manage_service')]
    public function manageServiceAction(Request $request, string $service): JsonResponse
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

    #[Route('//stats/{ressource}', name: 'stats_ressource')]
    public function statsRessourceAction(Request $request, string $ressource): JsonResponse
    {

        $response = [
            'cpu' => [],
            'memory' => [],
            'disk' => [],
            'lxcfs' => [],
            'openedfiles' => [],
            'lxclsrun' => [],
            'qemurun' => []
        ];

        //$action = $request->query->get('action');
        if ($ressource === "hardware") {
            
            $response['cpu']=$this->cpu_load();
            $response['disk']=$this->disk_usage();

            $result=$this->memory_usage();
            $response['memory']=$result['memory'];
            $response['memory_total']=$result['memory_total'];

            $response['lxcfs']=$this->lxcfs_load();
            $response['openedfiles']=$this->opened_file();
            $this->logger->info("Number of opened file: ".$response['openedfiles']);

            $response['lxclsrun']=$this->lxc_number();
            $this->logger->info("Number of LXC containers running: ".$response['lxclsrun']);

            $response['qemurun']=$this->qemu_number();
            $this->logger->info("Number of QEMU VM Running: ".$response['qemurun']);

            $response['lxcfs']=null;
            return new JsonResponse($response);
        }

        elseif ($ressource === "hardwarelight") {
            $response['cpu']=$this->cpu_load();
            $response['disk']=$this->disk_usage();
            $result=$this->memory_usage();
            $response['memory']=$result['memory'];
            $response['memory_total']=$result['memory_total'];
            $response['lxcfs']=$this->lxcfs_load();

            return new JsonResponse($response);
        } else 
            return new JsonResponse(null);
    }

    private function cpu_load(): int {
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
    
        $output=explode("\n", $messagestatsRessourceProcess->getOutput());
        
        return 100-(int) round(preg_replace('/^.+ni[, ]+([0-9\.]+) id,.+/', '$1', $output[11]));
    }

    private function disk_usage(): int {
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
        return (int) round(preg_replace('/^.+ ([0-9]+)% .+/', '$1', $output[1]));
    }

    private function memory_usage(): array {
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
        
        $result=array();
        $result['memory']=round(100 - ($avail / $total * 100));
        $result['memory_total']=$total/1000;
        return $result;
    }

    private function lxcfs_load(): int|sring {            
        $lxcfs=shell_exec("top -b -n2 -d0.2 -p `ps aux | grep -v \"grep\" | grep \"/usr/bin/lxcfs\" |awk '{print $2}'` | tail -1 |awk '{print $9}' | tr -d \"\n\"");
        if (!is_null($lxcfs) && $lxcfs)
            return (int) $lxcfs;
        else 
            return "";
    }

    private function opened_file(): string {
        $command = [
            'bash','-c','sudo lsof -w | wc -l'
        ];
        
        $process = new Process($command);
        try {
            $process->setTimeout(10);
            $process->run();
            return $process->getOutput();
        } catch (ProcessTimedOutException $e) {
            $this->logger->error($e->getMessage());
            return "Process too long";
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return "NA";
        }
        
    }

    private function lxc_number(): int {   
        $lxclsrun=shell_exec("sudo lxc-ls -f | grep RUNNING | wc -l");
        if (!is_null($lxclsrun) && $lxclsrun)
            return (int) $lxclsrun;
        else 
            return 0;
    }

    private function qemu_number(): int{
        $qemurun=shell_exec("sudo ps x | grep -e \"qemu\" | wc -l");
        if (!is_null($qemurun) && $qemurun)
            return (int) $qemurun;
        else 
            return 0;
    }
}