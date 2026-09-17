<?php
namespace App\Controller;

use App\Service\SystemdStatusService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class SystemdController extends AbstractController
{
    private SystemdStatusService $systemdStatusService;

    public function __construct(SystemdStatusService $systemdStatusService)
    {
        $this->systemdStatusService = $systemdStatusService;
    }

    #[Route('/api/systemd/status', name: 'api_systemd_status', methods: ['GET'])]
    public function statusAction(): JsonResponse
    {
        $statuses = $this->systemdStatusService->getServicesStatus();

        $services = [];
        foreach ($statuses as $status) {
            $services[] = $status;
        }

        return new JsonResponse([
            'generated_at' => date('c'),
            'total' => count($services),
            'running' => count(array_filter($services, fn($s) => $s['is_running'])),
            'services' => $services,
        ]);
    }
}
