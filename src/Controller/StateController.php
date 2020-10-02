<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
}