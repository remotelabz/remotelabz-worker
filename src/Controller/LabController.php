<?php
namespace App\Controller;

use App\Bridge\Network\OVS;
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
use Psr\Log\LoggerInterface;

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
     * @Route("/lab/device/{uuid}/start", name="device_lab_post", defaults={"_format"="json"}, methods={"POST"})
     */
    public function deviceStartAction(Request $request, $uuid)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('json' === $request->getContentType()) {
            $descriptor = $request->getContent();
            $this->logger->info("Start device ".$uuid);
            $this->logger->debug("deviceStartAction - Start device ".$uuid." ".$descriptor);
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
            $this->logger->info("Stop device ".$uuid);
            $this->logger->debug("deviceStopAction - Stop device ".$uuid." ".$descriptor);
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
                $this->logger->info("Received request to connect the lab to internet");
                $this->logger->debug("Received request to connect the lab to internet :".$descriptor);
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
     * @Route("/lab/disconnect/internet", name="disconnect_lab_internet", defaults={"_format"="json"}, methods={"POST"})
     */
    public function disconnectFromInternetAction(Request $request)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('json' === $request->getContentType()) {
            $descriptor = $request->getContent();
            try {
                $this->disconnectFromInternet($descriptor);
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
     * @Route("/lab/interconnect", name="interconnect_lab", defaults={"_format"="json"}, methods={"POST"})
     */
    public function interconnectAction(Request $request)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('json' === $request->getContentType()) {
            $descriptor = $request->getContent();
            try {
                $this->logger->info("Received request to connect the lab to internet");
                $this->logger->debug("Received request to connect the lab to internet :".$descriptor);
                $this->interconnect($descriptor);
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
     * @Route("/lab/disinterconnect", name="disinterconnect_lab", defaults={"_format"="json"}, methods={"POST"})
     */
    public function disinterconnectAction(Request $request)
    {
        if ('application/x-www-form-urlencoded' === $request->getContentType()) {
            //
        } elseif ('json' === $request->getContentType()) {
            $descriptor = $request->getContent();
            try {
                $this->disinterconnect($descriptor);
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

        if (!IPTools::networkInterfaceExists($bridgeName)) {
            OVS::bridgeAdd($bridgeName, true);
            $this->logger->info("Add bridge ".$bridgeName);
            $this->logger->debug("startDeviceInstance - Add bridge ".$bridgeName." ".$descriptor);
        }

        // TODO: add command sudo ip addr add $(echo ${NETWORK_LAB} | cut -d. -f1-3).1/24 dev ${BRIDGE_NAME}
        $labNetwork = explode('.', getenv('LAB_NETWORK'));
        $this->logger->debug("Set IP address of bridge ".$bridgeName." to ".$labNetwork[0] . '.' . $labNetwork[1] . '.' . $labNetwork[2] . '.254/24');
        
        $IP="".$labNetwork[0] . '.' . $labNetwork[1] . '.' . $labNetwork[2] . ".254/24";
        $this->logger->debug("startDeviceInstance - Check if ".$IP." exist");
        if (!IPTools::networkIPExists($bridgeName,$IP)) {
            IPTools::addrAdd($bridgeName,"$IP");
            $this->logger->debug("Set link ".$bridgeName." up");
        }
        IPTools::linkSet($bridgeName, IPTools::LINK_SET_UP);

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

        $filesystem = new Filesystem();
        $filesystem->mkdir($this->workerDir . "/instances/" . $labUser . "/" . $labInstanceUuid . "/" . $deviceInstanceUuid);

        if (filter_var($img["source"], FILTER_VALIDATE_URL)) {
            if (!$filesystem->exists($this->kernel->getProjectDir() . "/images/" . basename($img["source"]))) {
                $chunkSize = 1024 * 1024;
                $fd = fopen($img["source"], 'rb');

                while (!feof($fd)) {   
                    $buffer = fread($fd, $chunkSize);
                    file_put_contents($this->kernel->getProjectDir() . "/images/" . basename($img["source"]), $buffer, FILE_APPEND);
                    ob_flush();
                    flush();
                }

                fclose($fd);
            }
        }

        $img['destination'] = $this->kernel->getProjectDir() . "/instances/" . $labUser . "/" . $labInstanceUuid . "/" . $deviceInstanceUuid . "/" . basename($img['source']);
        $img['source'] = $this->kernel->getProjectDir() . "/images/" . basename($img['source']);

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

            if (!IPTools::networkInterfaceExists($networkInterfaceName)) {
                IPTools::tuntapAdd($networkInterfaceName, IPTools::TUNTAP_MODE_TAP);
            }

            if (!OVS::ovsPortExists($bridgeName, $networkInterfaceName)) {
                OVS::portAdd($bridgeName, $networkInterfaceName);
            }
            IPTools::linkSet($networkInterfaceName, IPTools::LINK_SET_UP);

            $parameters['network'] += [ '-net', 'nic,macaddr=' . $networkInterface['macAddress'],
                '-net', 'tap,ifname=' . $networkInterfaceName . ',script=no'
            ];

            if ($networkInterface['settings']['protocol'] === 'VNC' && (!$alreadyHasControlNic)) {
                if (array_key_exists('ip',$networkInterface['settings']))
                    $vncAddress = $networkInterface['settings']['ip'] ?: "0.0.0.0";
                else 
                    $vncAddress ="0.0.0.0";
                $vncPort = $networkInterfaceInstance['remotePort'];

                $process = new Process(['websockify', '-D', $vncAddress . ':' . ($vncPort + 1000), $vncAddress.':'.$vncPort]);
                $process->mustRun();

                array_push($parameters['access'], '-vnc', $vncAddress.':'.($vncPort - 5900));
                array_push($parameters['local'], '-k', 'fr');

                $alreadyHasControlNic = true;
            }
        }

        array_push($parameters['local'],
            '-rtc', 'base=localtime,clock=host', // For qemu 3 support
            '-smp', '4',
            '-vga', 'qxl'
        );
        
        $arch = posix_uname()['machine'];

        $command = [
            'qemu-system-' . $arch,
            '-enable-kvm',
            '-machine', 'accel=kvm:tcg',
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
        $this->logger->debug("startDeviceInstance - Start qemu ".implode(' ',$command));

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

        $bridgeName = $labInstance['bridgeName'];

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
                if (array_key_exists('ip',$networkInterface['settings']))
                    $vncAddress = $networkInterface['settings']['ip'] ?: "0.0.0.0";
                else
                    $vncAddress ="0.0.0.0";

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

            if (OVS::ovsPortExists($bridgeName, $networkInterfaceName)) {
                OVS::portDelete($bridgeName, $networkInterfaceName, true);
            }

            if (IPTools::networkInterfaceExists($networkInterfaceName)) {
                IPTools::linkSet($networkInterfaceName, IPTools::LINK_SET_DOWN);
                IPTools::linkDelete($networkInterfaceName);
            }
        }

        // OVS

        $activeDeviceCount = count(array_filter($labInstance['deviceInstances'], function ($deviceInstance) {
            return $deviceInstance['isStarted'] === true;
        })) - 1;

        if ($activeDeviceCount <= 0) {
            OVS::bridgeDelete($bridgeName, true);
        }

        $filesystem = new Filesystem();
        $filesystem->remove($this->workerDir . '/instances/' . $labUser . '/' . $labInstanceUuid . '/' . $deviceInstanceUuid);
    }
    
    public function connectToInternet(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        
        $labNetwork = getenv('LAB_NETWORK');
        $dataNetwork = getenv('DATA_NETWORK');
        $bridgeInt = getenv('BRIDGE_INT');
        $bridgeIntGateway = getenv('BRIDGE_INT_GW');

        if (!is_array($labInstance)) {
            // invalid json
            return;
        }
            
        $bridge=$labInstance['bridgeName'];
        $this->LinkTwoOVS($bridge,$bridgeInt);
        $this->logger->debug("connectToInternet - Identify bridgeName in instance:".$bridge);
  



        // Create new routing table for packet from the network of lab's device
        IPTools::ruleAdd('from ' . $labNetwork, 'lookup 4');
        if (!IPTools::routeExists($dataNetwork . ' dev ' . $bridgeInt, 4)) {
            IPTools::routeAdd($dataNetwork . ' dev ' . $bridgeInt, 4);
        }
        if (!IPTools::routeExists('default via ' . $bridgeIntGateway, 4)) {
            IPTools::routeAdd('default via ' . $bridgeIntGateway, 4);
        }

        IPTables::append(
            IPTables::CHAIN_POSTROUTING,
            Rule::create()
                ->setSource($labNetwork)
                ->setOutInterface($bridgeInt)
                ->setJump('MASQUERADE')
            ,
            'nat'
        );
        
    }

    public function disconnectFromInternet(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $labNetwork = getenv('LAB_NETWORK');
        $dataNetwork = getenv('DATA_NETWORK');
        $bridgeInt = getenv('BRIDGE_INT');
        $bridgeIntGateway = getenv('BRIDGE_INT_GW');

        if (!is_array($labInstance)) {
            // invalid json
            return;
        }

        $bridge=$labInstance['bridgeName'];

        $this->UnlinkTwoOVS($bridge,$bridgeInt);

        // Create new routing table for packet from the network of lab's device
        IPTools::ruleDelete('from ' . $labNetwork, 'lookup 4');
        if (IPTools::routeExists($dataNetwork . ' dev ' . $bridgeInt, 4)) {
            IPTools::routeDelete($dataNetwork . ' dev ' . $bridgeInt, 4);
        }
        if (IPTools::routeExists('default via ' . $bridgeIntGateway, 4)) {
            IPTools::routeDelete('default via ' . $bridgeIntGateway, 4);
        }
        IPTables::delete(
            IPTables::CHAIN_POSTROUTING,
            Rule::create()
                ->setSource($labNetwork)
                ->setOutInterface($bridgeInt)
                ->setJump('MASQUERADE')
            ,
            'nat'
        );
    }

    public function interconnect(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        
        $labNetwork = getenv('LAB_NETWORK');
        $dataNetwork = getenv('DATA_NETWORK');
        $bridgeInt = getenv('BRIDGE_INT');
        $bridgeIntGateway = getenv('BRIDGE_INT_GW');

        if (!is_array($labInstance)) {
            // invalid json
            return;
        }
        

        //$bridge = $labInstance['instances']['bridgeName'];
        
        $bridge=$labInstance['bridgeName'];
        $this->LinkTwoOVS($bridge,$bridgeInt);

        $this->logger->debug("connectToInternet - Identify bridgeName in instance:".$bridge);
  
        
        
    }

    private function LinkTwoOVS(string $bridge,string $bridgeInt)
    {
        // Create patch between lab's OVS and Worker's OVS
        OVS::portAdd($bridge, "Patch-ovs-" . $bridge, true);
        $this->logger->debug("connectToInternet - Add port To-ovs-" . $bridgeInt . " to bridge :".$bridgeInt);
        OVS::setInterface("Patch-ovs-" . $bridge, [
            'type' => 'patch',
            'options:peer' => "Patch-ovs-" . $bridgeInt
        ]);

        OVS::portAdd($bridgeInt, "Patch-ovs-" . $bridgeInt, true);
        OVS::setInterface("Patch-ovs-" . $bridgeInt, [
            'type' => 'patch',
            'options:peer' => "Patch-ovs-" . $bridge
        ]);
    }

    private function UnlinkTwoOVS(string $bridge,string $bridgeInt)
    {
    OVS::portDelete($bridgeInt, "Patch-ovs-" . $bridgeInt, true);
    OVS::portDelete($bridge, "Patch-ovs-" . $bridge, true);
    }

    public function disinterconnect(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $labNetwork = getenv('LAB_NETWORK');
        $dataNetwork = getenv('DATA_NETWORK');
        $bridgeInt = getenv('BRIDGE_INT');
        $bridgeIntGateway = getenv('BRIDGE_INT_GW');

        if (!is_array($labInstance)) {
            // invalid json
            return;
        }

        $bridge=$labInstance['bridgeName'];

        $this->UnlinkTwoOVS($bridge,$bridgeInt);

       
    }

    
}
