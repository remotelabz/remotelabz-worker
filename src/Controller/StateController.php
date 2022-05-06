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
}