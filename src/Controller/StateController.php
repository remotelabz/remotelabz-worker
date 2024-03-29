<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class StateController extends AbstractController
{

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
                $messagestatsRessourceProcess->run();
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
                //'cmd' => []
            ];
            $output=explode("\n", $messagestatsRessourceProcess->getOutput());
            
            $response['cpu']=100-(int) round(preg_replace('/^.+ni[, ]+([0-9\.]+) id,.+/', '$1', $output[11]));
            
            
            $messagestatsRessourceProcess = new Process([
                'df',
                '-h', '/'
            ]);

            try {
                $messagestatsRessourceProcess->run();
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
                $messagestatsRessourceProcess->run();
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

            //$response['cmd']=$output;
            



            return new JsonResponse($response);
        }
        else 
            return new JsonResponse(null);
    }
}