<?php
namespace App\Controller;

use App\Bridge\Network\OVS;
use Psr\Log\LoggerInterface;
use App\Bridge\Network\IPTools;
use App\Bridge\Network\IPTables\Rule;
use Symfony\Component\Process\Process;
use App\Bridge\Network\IPTables\IPTables;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class LabController extends AbstractController
{
    private $kernel;
    private $workerDir;
    protected $logger;

    public function __construct(KernelInterface $kernel, LoggerInterface $logger) {
        $this->workerDir = realpath(dirname(__FILE__) . "/../../");
    }

    #[Route('/worker/port/free', name: 'get_free_port')]
    public function getFreePortAction(): Response
    {
        $process = new Process([ $this->kernel->getProjectDir().'/scripts/get-available-port.sh' ]);
        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            return new Response(
                $this->renderView('response.json.twig', [
                    'code' => $exception->getProcess()->getExitCode(),
                    'output' => [
                        'standard' => $exception->getProcess()->getOutput(),
                        'error' => $exception->getProcess()->getErrorOutput()
                    ]
                ]),
                500
            );
        }
        return new Response($process->getOutput());
    }
}
