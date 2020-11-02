<?php

namespace App\Service\Instance;

use App\Bridge\Network\IPTables\IPTables;
use App\Bridge\Network\IPTables\Rule;
use App\Bridge\Network\IPTools;
use App\Bridge\Network\OVS;
use App\Exception\BadDescriptorException;
use ErrorException;
use Psr\Log\LoggerInterface;
use Remotelabz\Message\Message\InstanceStateMessage;
use Remotelabz\NetworkBundle\Entity\Network;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

class InstanceManager
{
    protected $kernel;
    protected $logger;
    protected $params;

    public function __construct(
        KernelInterface $kernel,
        LoggerInterface $logger,
        ParameterBagInterface $params
    ) {
        $this->kernel = $kernel;
        $this->logger = $logger;
        $this->params = $params;
    }

    public function createLabInstance(string $descriptor, string $uuid) {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS

        if (!IPTools::networkInterfaceExists($bridgeName)) {
            OVS::bridgeAdd($bridgeName, true);
            $this->logger->info("Bridge doesn't exists. Creating bridge for lab instance.", [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        } else {
            $this->logger->debug("Bridge already exists. Skipping bridge creation for lab instance.", [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        }
    }

    public function deleteLabInstance(string $descriptor, string $uuid) {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS

        OVS::bridgeDelete($bridgeName, true);
    }

    /**
     * Find last IP of a network
     * @param string $network the network of form X.X.X.X/M
     */
    public function lastIP(string $network) :string {
        list($range, $netmask) = explode('/', $network, 2);
        $nb_host=1 << (32-$netmask); //number of host in the range
        return long2ip(ip2long($range)+$nb_host-2);

    }

    /**
     * Start an instance described by JSON descriptor for device instance specified by UUID.
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
            $this->logger->error("Invalid JSON was provided!", ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS

        if (!IPTools::networkInterfaceExists($bridgeName)) {
            OVS::bridgeAdd($bridgeName, true);
            $this->logger->info("Bridge doesn't exists. Creating bridge for lab instance.", [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        } else {
            $this->logger->debug("Bridge already exists. Skipping bridge creation for lab instance.", [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        }

        // TODO: add command sudo ip addr add $(echo ${NETWORK_LAB} | cut -d. -f1-3).1/24 dev ${BRIDGE_NAME}
        // $labNetwork = explode('.', $_ENV['LAB_NETWORK']);
        $labNetwork =  new Network($labInstance['network']['ip']['addr'], $labInstance['network']['netmask']['addr']);  
        
        $BridgeIP= new Network($this->lastIP($labNetwork),$labInstance['network']['netmask']['addr']);
        $this->logger->debug("Set IP address of bridge ".$bridgeName." to ".$BridgeIP);

        $this->logger->debug("startDeviceInstance - Check if ".$BridgeIP." exist");
        if (!IPTools::networkIPExists($bridgeName, $BridgeIP)) {
            IPTools::addrAdd($bridgeName, $BridgeIP);
            $this->logger->debug("Set link ".$bridgeName." up");
        }
        IPTools::linkSet($bridgeName, IPTools::LINK_SET_UP);

        // Network interfaces

        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] != 'started');
        });

        if (!count($deviceInstance)) {
            $this->logger->debug("Device instance is already started.");
            // instance is already started or whatever
            return;
        } else {
            $deviceIndex = array_key_first($deviceInstance);
            $deviceInstance = $deviceInstance[$deviceIndex];
        }
        
        try {
            $labUser = $labInstance['owner']['uuid'];
            $ownedBy = $labInstance['ownedBy'];
            $labInstanceUuid = $labInstance['uuid'];
            $img = [
                "source" => $deviceInstance['device']['operatingSystem']['image']
            ];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        $filesystem = new Filesystem();

        $instancePath = $this->kernel->getProjectDir() . "/instances";
        $instancePath .= ($ownedBy === 'group') ? '/group' : '/user';
        $instancePath .= '/' . $labUser;
        $instancePath .= '/' . $labInstanceUuid;
        $instancePath .= '/' . $uuid;

        $filesystem->mkdir($instancePath);
        if (!$filesystem->exists($this->kernel->getProjectDir() . "/images")) {
            $filesystem->mkdir($this->kernel->getProjectDir() . "/images");
        }

        if (filter_var($img["source"], FILTER_VALIDATE_URL)) {
            if (!$filesystem->exists($this->kernel->getProjectDir() . "/images/" . basename($img["source"]))) {
                $chunkSize = 1024 * 1024;
                $fd = fopen($img["source"], 'rb');

                while (!feof($fd)) {   
                    $buffer = fread($fd, $chunkSize);
                    file_put_contents($this->kernel->getProjectDir() . "/images/" . basename($img["source"]), $buffer, FILE_APPEND);
                    if (ob_get_level() > 0)
                        ob_flush();
                    flush();
                }

                fclose($fd);
            }
        }

        $img['destination'] = $instancePath . '/' . basename($img['source']);
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
            $this->logger->debug("network intance ".$networkInterfaceInstance['networkInterface']['name']);

            $networkInterface = $networkInterfaceInstance['networkInterface'];
            $networkInterfaceName = substr(str_replace(' ', '_', $networkInterface['name']), 0, 6) . '-' . substr($networkInterfaceInstance['uuid'], 0, 8);

            if (!IPTools::networkInterfaceExists($networkInterfaceName)) {
                IPTools::tuntapAdd($networkInterfaceName, IPTools::TUNTAP_MODE_TAP);
                $this->logger->debug("Interface ".$networkInterfaceName." created");

            }

            if (!OVS::ovsPortExists($bridgeName, $networkInterfaceName)) {
                OVS::portAdd($bridgeName, $networkInterfaceName, true);
                $this->logger->debug("Interface ".$networkInterfaceName." added to OVS ".$bridgeName);
            }
            IPTools::linkSet($networkInterfaceName, IPTools::LINK_SET_UP);
            $this->logger->debug("Interface ".$networkInterfaceName." set up");

            // Obsolete parameters
            /*$parameters['network'] += [ '-net', 'nic,macaddr=' . $networkInterface['macAddress'],
                '-net', 'tap,ifname=' . $networkInterfaceName . ',script=no'
            ];*/
            
            array_push($parameters['network'],'-device','e1000,netdev='.$networkInterfaceName.',mac='.$networkInterfaceInstance['macAddress'],
                '-netdev', 'tap,ifname='.$networkInterfaceName.',id='.$networkInterfaceName.',script=no');

            $this->logger->debug("parameters network ".implode(' ',$parameters['network']));

            //-device e1000,netdev=my$((i)),mac=${MAC}$(printf %02x $i) -netdev tap,ifname=t_${NAME}_1,id=my$((i)),script=no

           // If the VM has multiple network interface, only one is used to the vnc
            if (
                !$alreadyHasControlNic &&
                array_key_exists('accessType', $networkInterface)
            ) {
                if ($networkInterface['accessType'] === 'VNC' ) {
                    $vncAddress ="0.0.0.0";
                    $vncPort = $networkInterfaceInstance['remotePort'];

                    $process = new Process(['websockify', '-D', $vncAddress . ':' . ($vncPort + 1000), $vncAddress.':'.$vncPort]);
                    $process->mustRun();

                    array_push($parameters['access'], '-vnc', $vncAddress.':'.($vncPort - 5900));
                    array_push($parameters['local'], '-k', 'fr');

                    $alreadyHasControlNic = true;
                }
            }
        }

        array_push($parameters['local'],
            '-rtc', 'base=localtime,clock=host', // For qemu 3 compatible
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
            '-name', $uuid
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
     * Stop an instance described by JSON descriptor for device instance specified by UUID.
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
            $this->logger->error("Invalid JSON was provided!", ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // Network interfaces

        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] != 'stopped');
        });

        if (!count($deviceInstance)) {
            $this->logger->debug("Device instance is already stopped.");
            // instance is already stopped or whatever
            return;
        } else {
            $deviceIndex = array_key_first($deviceInstance);
            $deviceInstance = $deviceInstance[$deviceIndex];
        }

        try {
            $labUser = $labInstance['owner']['uuid'];
            $ownedBy = $labInstance['ownedBy'];
            $labInstanceUuid = $labInstance['uuid'];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        $process = Process::fromShellCommandline("ps aux | grep -e " . $uuid . " | grep -v grep | awk '{print $2}'");
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
            $networkInterfaceName = substr(str_replace(' ', '_', $networkInterface['name']), 0, 6) . '-' . substr($networkInterfaceInstance['uuid'], 0, 8);

            if (array_key_exists('accessType', $networkInterface)) {
                if ($networkInterface['accessType'] === 'VNC') {
                    $vncAddress = "0.0.0.0";
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
                                $this->logger->debug("Kill websockify process number".$pid);
                            }
                        }
                    }
                }

                // if (OVS::ovsPortExists($bridgeName, $networkInterfaceName)) {
                //     OVS::portDelete($bridgeName, $networkInterfaceName, true);
                // }

                if (IPTools::networkInterfaceExists($networkInterfaceName)) {
                    IPTools::linkSet($networkInterfaceName, IPTools::LINK_SET_DOWN);
                    $this->logger->debug("Interface ".$networkInterfaceName." set down");
                    IPTools::linkDelete($networkInterfaceName);
                    $this->logger->debug("Interface ".$networkInterfaceName." deleted");
                }
            }
        }

        // OVS

        $activeDeviceCount = count(array_filter($labInstance['deviceInstances'], function ($deviceInstance) {
            return $deviceInstance['state'] == InstanceStateMessage::STATE_STARTED;
        })) - 1;

        if ($activeDeviceCount <= 0) {
            // OVS::bridgeDelete($bridgeName, true);
        }

        // $filesystem = new Filesystem();
        // $filesystem->remove($this->workerDir . '/instances/' . $labUser . '/' . $labInstanceUuid . '/' . $uuid);
    }
    
    public function connectToInternet(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $labNetwork = $this->params->get('app.network.lab.cidr');
        $dataNetwork = $this->params->get('app.network.data.cidr');
        $bridgeInt = $this->params->get('app.bridge.name');
        $bridgeIntGateway = $this->params->get('app.bridge.gateway');

        IPTools::linkSet($bridgeInt, IPTools::LINK_SET_UP);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        $bridge = $labInstance['bridgeName'];
        OVS::LinkTwoOVS($bridge, $bridgeInt);
        $this->logger->debug("connectToInternet - Identify bridgeName in instance:".$bridge);

        // Create new routing table for packet from the network of lab's device
        IPTools::ruleAdd('from ' . $labNetwork, 'lookup 4');
        IPTools::ruleAdd('to ' . $labNetwork, 'lookup 4');
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
        $labNetwork = $this->params->get('app.network.lab.cidr');
        $dataNetwork = $this->params->get('app.network.data.cidr');
        $bridgeInt = $this->params->get('app.bridge.name');
        $bridgeIntGateway = $this->params->get('app.bridge.gateway');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        $bridge = $labInstance['bridgeName'];

        OVS::UnlinkTwoOVS($bridge, $bridgeInt);

        // Create new routing table for packet from the network of lab's device
        if (IPTools::ruleExists('from ' . $labNetwork, 'lookup 4')) {
            IPTools::ruleDelete('from ' . $labNetwork, 'lookup 4');
        }
        if (IPTools::routeExists($dataNetwork . ' dev ' . $bridgeInt, 4)) {
            IPTools::routeDelete($dataNetwork . ' dev ' . $bridgeInt, 4);
        }
        if (IPTools::routeExists('default via ' . $bridgeIntGateway, 4)) {
            IPTools::routeDelete('default via ' . $bridgeIntGateway, 4);
        }

        $rule = Rule::create()
            ->setSource($labNetwork)
            ->setOutInterface($bridgeInt)
            ->setJump('MASQUERADE')
        ;

        if (IPTables::exists(IPTables::CHAIN_POSTROUTING, $rule, 'nat')) {
            IPTables::delete(IPTables::CHAIN_POSTROUTING, $rule, 'nat');
        }
    }

    public function interconnect(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $bridgeInt = $this->params->get('app.bridge.name');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }
        

        //$bridge = $labInstance['instances']['bridgeName'];
        
        $bridge = $labInstance['bridgeName'];
        OVS::LinkTwoOVS($bridge, $bridgeInt);

        $this->logger->debug("connectToInternet - Identify bridgeName in instance:".$bridge);
    }

    public function disinterconnect(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $bridgeInt = $this->params->get('app.bridge.name');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        $bridge = $labInstance['bridgeName'];

        OVS::UnlinkTwoOVS($bridge, $bridgeInt);
    }
}
