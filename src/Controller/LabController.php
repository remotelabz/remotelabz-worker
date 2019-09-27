<?php
namespace App\Controller;

use Symfony\Component\Process\Process;
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

    public function __construct(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
        $this->workerDir = realpath(dirname(__FILE__) . "/../../");
    }

    /**
     * @Route("/lab/device/{uuid}/start", name="device_lab_post", defaults={"_format"="json"}, methods={"POST"})
     */
    public function deviceStartAction(Request $request, $uuid)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('json' === $request->getContentType()) {
            $descriptor = $request->getContent();
            try {
                $this->startDeviceInstance($descriptor, $uuid);
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
            return new Response(null, 200);
        } else {
            return new Response(null, 415);
        }
    }

    /**
     * @Route("/lab/device/{uuid}/stop", name="device_lab_stop", defaults={"_format"="json"}, methods={"POST"})
     */
    public function deviceStopAction(Request $request, $uuid)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('json' === $request->getContentType()) {
            $descriptor = $request->getContent();
            try {
                $this->stopDeviceInstance($descriptor, $uuid);
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
            return new Response(null, 200);
        } else {
            return new Response(null, 415);
        }
    }

    /**
     * @Route("/lab/connect/internet", name="connect_lab_internet", defaults={"_format"="json"}, methods={"POST"})
     */
    public function connectToInternetAction(Request $request)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('json' === $request->getContentType()) {
            $descriptor = $request->getContent();
            try {
                $this->connectToInternet($descriptor);
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
            return new Response(null, 200);
        } else {
            return new Response(null, 415);
        }
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
                $this->renderView('response.xml.twig', [
                    'code' => $exception->getProcess()->getExitCode(),
                    'message' => $exception->getMessage()
                ]),
                500
            );
        }

        return new Response($process->getOutput());
    }

    /**
     * Start a new instance described by JSON descriptor for device specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to start.
     * @throws ProcessFailedException When a process failed to run.
     * @return void
     */
    public function startDeviceInstance(string $descriptor, string $uuid) {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            return;
        }

        $bridgeName = $labInstance['bridgeName'];

        // OVS

        if (!$this->networkInterfaceExists($bridgeName)) {
            $process = new Process([ 'ovs-vsctl', 'add-br', $bridgeName ]);
            $process->mustRun();
        }

        // TODO: add command sudo ip addr add $(echo ${NETWORK_LAB} | cut -d. -f1-3).1/24 dev ${BRIDGE_NAME}
        
        $process = new Process([ 'sudo', 'ip', 'link', 'set', $bridgeName, 'up' ]);
        $process->mustRun();

        // Network interfaces

        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['isStarted'] === false);
        });

        if (count($deviceInstance) == 0) {
            // instance is already started or whatever
            return;
        }

        $deviceInstance = reset($deviceInstance);
        $deviceInstanceUuid = $deviceInstance['uuid'];
        $labUser = $labInstance['userId'];
        $labInstanceUuid = $labInstance['uuid'];
        $img = [
            "source" => $deviceInstance['device']['operatingSystem']['image']
        ];

        $process = new Process([ 'mkdir', '-p', $this->workerDir . "/instances/" . $labUser . "/" . $labInstanceUuid . "/" . $deviceInstanceUuid ]);
        $process->mustRun();

        if (filter_var($img["source"], FILTER_VALIDATE_URL)) {
            if (!file_exists($this->workerDir . "/images/" . basename($img["source"]))) {
                $file = file_get_contents($img["source"]);
                file_put_contents($this->workerDir . "/images/" . basename($img["source"]), $file);
            }
        }

        $img['destination'] = $this->workerDir . "/instances/" . $labUser . "/" . $labInstanceUuid . "/" . $deviceInstanceUuid . "/" . basename($img['source']);
        $img['source'] = $this->workerDir . "/images/" . basename($img['source']);

        $process = new Process([ 'qemu-img', 'create', '-f', 'qcow2', '-b', $img['source'], $img['destination']]);
        $process->mustRun();

        $parameters = [
            'system' => [
                '-m',
                $deviceInstance['device']['flavor']['memory'],
                '-hda',
                $img['destination']
            ],
            'network' => [],
            'access' => [],
            'local' => []
        ];

        $alreadyHasControlNic = false;

        foreach($deviceInstance['networkInterfaceInstances'] as $networkInterfaceInstance) {
            $networkInterface = $networkInterfaceInstance['networkInterface'];
            $networkInterfaceName = substr($networkInterface['name'], 0, 6) . '-' . substr($networkInterfaceInstance['uuid'], 0, 8);

            if (!$this->networkInterfaceExists($networkInterfaceName)) {
                $process = new Process(['sudo', 'ip', 'tuntap', 'add', 'name', $networkInterfaceName, 'mode', 'tap']);
                $process->mustRun();
            }

            if (!$this->ovsPortExists($bridgeName, $networkInterfaceName)) {
                $process = new Process(['ovs-vsctl', 'add-port', $bridgeName, $networkInterfaceName]);
                $process->mustRun();
            }

            $process = new Process(['sudo', 'ip', 'link', 'set', $networkInterfaceName, 'up']);
            $process->mustRun();

            $parameters['network'] += [ '-net', 'nic,macaddr=' . $networkInterface['macAddress'],
                '-net', 'tap,ifname=' . $networkInterfaceName . ',script=no'
            ];

            if ($networkInterface['settings']['protocol'] === 'VNC' && (!$alreadyHasControlNic)) {
                $vncAddress = $networkInterface['settings']['ip'] ?: "0.0.0.0";
                $vncPort = $networkInterfaceInstance['remotePort'];

                $process = new Process(['websockify', '-D', $vncAddress . ':' . ($vncPort + 1000), $vncAddress.':'.$vncPort]);
                $process->mustRun();

                array_push($parameters['access'], '-vnc', $vncAddress.':'.($vncPort - 5900));
                array_push($parameters['local'], '-k', 'fr');

                $alreadyHasControlNic = true;
            }
        }

        array_push($parameters['local'],
            '-localtime',
            '-smp', '4',
            '-vga', 'qxl'
        );
        
        $arch = posix_uname()['machine'];

        $command = [
            'qemu-system-' . $arch,
            '-cpu', 'max',
            '-display', 'none',
            '-daemonize',
            '-name', $deviceInstanceUuid
        ];
        
        foreach ($parameters as $parametersType) {
            foreach ($parametersType as $parameter) {
                array_push($command, $parameter);
            }
        }

        $process = new Process($command);
        $process->mustRun();
    }

    /**
     * Stop a new instance described by JSON descriptor for device specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to stop.
     * @throws ProcessFailedException When a process failed to run.
     * @return void
     */
    public function stopDeviceInstance(string $descriptor, string $uuid) {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            return;
        }

        $bridgeName = "br-" . substr($labInstance['uuid'], 0, 8);

        // Network interfaces

        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return $deviceInstance['uuid'] == $uuid;
        });

        $deviceInstance = reset($deviceInstance);
        $deviceInstanceUuid = $deviceInstance['uuid'];
        $labUser = $labInstance['userId'];
        $labInstanceUuid = $labInstance['uuid'];

        $process = Process::fromShellCommandline("ps aux | grep -e " . $deviceInstanceUuid . " | grep -v grep | awk '{print $2}'");
        $process->mustRun();
        
        $pidInstance = $process->getOutput();
        
        if ($pidInstance != "") {
            $pidInstance = explode("\n", $pidInstance);

            foreach ($pidInstance as $pid) {
                if ($pid != "") {
                    $process = new Process(['kill', '-9', $pid]);
                    $process->mustRun();
                }
            }
        }

        // Network interfaces

        foreach($deviceInstance['networkInterfaceInstances'] as $networkInterfaceInstance) {
            $networkInterface = $networkInterfaceInstance['networkInterface'];
            $networkInterfaceName = substr($networkInterface['name'], 0, 6) . '-' . substr($networkInterfaceInstance['uuid'], 0, 8);

            if ($networkInterface['settings']['protocol'] === 'VNC') {
                $vncAddress = $networkInterface['settings']['ip'] ?: "0.0.0.0";
                $vncPort = $networkInterfaceInstance['remotePort'];
                
                $process = Process::fromShellCommandline("ps aux | grep " . $vncAddress . ":" . $vncPort . " | grep websockify | grep -v grep | awk '{print $2}'");
                $process->mustRun();
                
                $pidWebsockify = $process->getOutput();
                
                if ($pidWebsockify != "") {
                    $pidWebsockify = explode("\n", $pidWebsockify);

                    foreach ($pidWebsockify as $pid) {
                        if ($pid != "") {
                            $process = new Process(['kill', '-9', $pid]);
                            $process->mustRun();
                        }
                    }
                }
            }

            if ($this->ovsPortExists($bridgeName, $networkInterfaceName)) {
                $process = new Process(['ovs-vsctl', '--with-iface', 'del-port', $bridgeName, $networkInterfaceName]);
                $process->mustRun();
            }

            if ($this->networkInterfaceExists($networkInterfaceName)) {
                $process = new Process(['sudo', 'ip', 'link', 'set', $networkInterfaceName, 'down']);
                $process->mustRun();

                $process = new Process(['sudo', 'ip', 'link', 'delete', $networkInterfaceName]);
                $process->mustRun();
            }
        }

        // OVS

        $activeDeviceCount = count(array_filter($labInstance['deviceInstances'], function ($deviceInstance) {
            return $deviceInstance['isStarted'] === true;
        })) - 1;

        if ($activeDeviceCount <= 0) {
            $process = new Process([ 'ovs-vsctl', '--if-exist', 'del-br', $bridgeName ]);
            $process->mustRun();
        }

        $process = new Process(['rm', '-rf', $this->workerDir . '/instances/' . $labUser . '/' . $labInstanceUuid . '/' . $deviceInstanceUuid]);
        $process->run();
    }

    function networkInterfaceExists(string $name) : bool {
        $process = new Process(['sudo', 'ip', 'link', 'show', $name]);
        $process->run();

        return $process->getExitCode() === 0 ? true : false;
    }

    function ovsPortExists(string $bridgeName, string $portName) : bool {
        $process = Process::fromShellCommandline('ovs-vsctl list-ports ' . $bridgeName . ' | grep ' . $portName . ' -c');
        $process->run();

        return ((int)$process->getOutput()) > 0 ? true : false;
    }
    
    public function connectToInternet(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            return;
        }
    }

    
}
