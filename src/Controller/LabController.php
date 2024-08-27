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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function __construct(KernelInterface $kernel, LoggerInterface $logger)
    {
        $this->kernel = $kernel;
        $this->workerDir = realpath(dirname(__FILE__) . "/../../");
        $this->logger = $logger;
    }

    /**
     * @Route("/worker/port/free", name="get_free_port")
     */
    public function getFreePortAction()
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

    /**
     * @Route("/images/{name}", name="get_image")
     */
    public function getImageAction(string $name)
    {
        $image = $name.".img";
        $process = new Process([ 'ls', '-1', $this->kernel->getProjectDir().'/images' ]);
        $exist = false;
        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            return new Response(
               null,
                500
            );
        }
        if ($process->getOutput() !== "") {
            $imageOutput = explode("\n", $process->getOutput());
            foreach($imageOutput as $output) {
                if ($output == $image) {
                    $exist = true;
                    break;
                }
            }
        }
        if ($exist == true) {
            $filePath = $this->kernel->getProjectDir().'/images/'.$image;
            $response = new StreamedResponse(function() use ($filePath) {
                readfile($filePath); exit;
            });
            
            $disposition = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $image
            );

            $response->headers->set('Content-Disposition', $disposition);
        }
        else {
            $response = new Response(null,404);
        }
        return $response;
    }
}
