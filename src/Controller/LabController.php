<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\KernelInterface;

class LabController extends AbstractController
{
    private $kernel;

    public function __construct(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
    }
    /**
     * @Route("/lab", name="lab_post", defaults={"_format"="xml"}, methods={"POST"})
     */
    public function startAction(Request $request)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {

        } elseif ('xml' === $request->getContentType()) {
            $lab = $request->getContent();
            # FIXME: Don't use sudo!
            $process = new Process([ $this->kernel->getProjectDir().'/scripts/start-lab.sh', $lab ]);
            try {
                $process->mustRun();
            } catch (ProcessFailedException $exception) {
                return new Response(
                    $this->renderView('response.xml.twig', [
                        'code' => $exception->getProcess()->getExitCode(),
                        'message' => $exception->getProcess()->getErrorOutput()
                    ]),
                    500
                );
            }
            return new Response($process->getOutput());
        } else {
            return new Response(null, 415, [ 'Content-Type' => 'text/plain' ]);
        }
    }

    /**
     * @Route("/lab/stop", name="lab_stop_post", defaults={"_format"="xml"}, methods={"POST"})
     */
    public function stopAction(Request $request)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {

        } elseif ('xml' === $request->getContentType()) {
            $lab = $request->getContent();
            # FIXME: Don't use sudo!
            $process = new Process([ $this->kernel->getProjectDir().'/scripts/stop-lab.sh', $lab ]);
            try {
                $process->mustRun();
            } catch (ProcessFailedException $exception) {
                return new Response(
                    $this->renderView('response.xml.twig', [
                        'code' => $exception->getProcess()->getExitCode(),
                        'message' => $exception->getProcess()->getErrorOutput()
                    ]),
                    500
                );
            }
            return new Response($process->getOutput());
        } else {
            return new Response(null, 415, [ 'Content-Type' => 'text/plain' ]);
        }
    }
}