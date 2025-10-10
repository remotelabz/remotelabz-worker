<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

class CachedStateController extends AbstractController
{
    protected $logger;
    private $cacheDir;

    public function __construct(LoggerInterface $logger, string $projectDir)
    {
        $this->logger = $logger;
        // Le répertoire cache sera dans var/cache/resources
        $this->cacheDir = $projectDir . '/var/cache/resources';
    }

    #[Route('//stats/{ressource}', name: 'stats_ressource')]
    public function statsRessourceAction(Request $request, string $ressource): JsonResponse
    {
        // Retourner directement depuis le cache
        return $this->getFromCache($ressource);
    }

    private function getFromCache(string $ressource): JsonResponse
    {
        $filename = match($ressource) {
            'hardware' => 'hardware_stats.json',
            'hardwarelight' => 'hardware_light_stats.json',
            default => null
        };

        if ($filename === null) {
            return new JsonResponse(null);
        }

        $filepath = $this->cacheDir . '/' . $filename;

        if (file_exists($filepath)) {
            try {
                $data = json_decode(file_get_contents($filepath), true);
                return new JsonResponse($data);
            } catch (\Exception $e) {
                $this->logger->error("Erreur lecture cache : " . $e->getMessage());
                return new JsonResponse(null, 500);
            }
        }

        $this->logger->warning("Fichier cache manquant : $filepath");
        return new JsonResponse(null, 503); // Service Unavailable
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
}