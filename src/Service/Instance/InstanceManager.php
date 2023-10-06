<?php

namespace App\Service\Instance;

use App\Bridge\Network\IPTables\IPTables;
use App\Bridge\Network\IPTables\Rule;
use App\Bridge\Network\IPTools;
use App\Bridge\Network\OVS;
use App\Exception\BadDescriptorException;
use ErrorException;
use App\Service\Instance\LogDispatcher;
use Psr\Log\LoggerInterface;
use Remotelabz\Message\Message\InstanceLogMessage;
use Remotelabz\Message\Message\InstanceStateMessage;
use Remotelabz\Message\Message\InstanceActionMessage;
use Remotelabz\NetworkBundle\Entity\Network;
use Remotelabz\NetworkBundle\Entity\IP;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Process\Exception\ProcessFailedException;

class InstanceManager extends AbstractController
{
    protected $kernel;
    protected $logger;
    protected $params;
    protected $front_ip;

    public function __construct(
        LogDispatcher $logger,
        KernelInterface $kernel,
        ParameterBagInterface $params,
        string $front_ip
    ) {
        $this->kernel = $kernel;
        $this->logger = $logger;
        $this->params = $params;
        $this->front_ip = $front_ip;
    }

    public function createLabInstance(string $descriptor, string $uuid) {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        //$this->logger->debug("from_export ? :".$labInstance['from_export']);
        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS
        if (!IPTools::networkInterfaceExists($bridgeName)) {
            $this->logger->debug("Bridge doesn't exists. Creating bridge for lab instance.", InstanceLogMessage::SCOPE_PRIVATE, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
            OVS::bridgeAdd($bridgeName, true);
        } else {
            $this->logger->debug("Bridge already exists. Skipping bridge creation for lab instance.", InstanceLogMessage::SCOPE_PRIVATE, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        }

        $result=array("state"=> InstanceStateMessage::STATE_CREATED, "uuid"=> $uuid, "options" => null);
        return $result;
    }

    public function deleteLabInstance(string $descriptor, string $uuid) {
        /** @var array $labInstance */
        $result=null;
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance);
        }
        $this->logger->debug("Lab instance to deleted : ", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS
        OVS::bridgeDelete($bridgeName, true);

        // Iptable
        $rule=Rule::create()
                ->setJump($labInstance['bridgeName']);
        if (IPTables::exists(IPTables::CHAIN_FORWARD,$rule)) {
            IPTables::delete(
                    IPTables::CHAIN_FORWARD,
                    $rule
                );
            }
        IPTables::delete_chain($labInstance['bridgeName']);

        try {
            $labUser = $labInstance['owner']['uuid'];
            $ownedBy = $labInstance['ownedBy'];
            $labInstanceUuid = $labInstance['uuid'];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        $instancePath = $this->kernel->getProjectDir() . "/instances";
        $instancePath .= ($ownedBy === 'group') ? '/group' : '/user';
        $instancePath .= '/' . $labUser;
        $instancePath .= '/' . $labInstanceUuid;

        if (file_exists($instancePath)) {
            $filesystem = new Filesystem();
            $filesystem->remove($instancePath);
        }
        $error=false;
        foreach ($labInstance["deviceInstances"] as $deviceInstance){
            $this->logger->debug("Device instance to delete : ", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $deviceInstance]);

            if ($deviceInstance['device']['hypervisor']['name'] === 'qemu') {
                $result=$this->stop_device_qemu($deviceInstance["uuid"],$deviceInstance,$labInstance);
                //$this->qemu_delete($deviceInstance["operatingSystem"]["image"]);
                //Have to test if the imagename is an url or a filename
                $this->qemu_delete($deviceInstance["uuid"],$this->kernel->getProjectDir()."/instances/user/".$deviceInstance["owner"]["uuid"]."/".basename($deviceInstance["device"]["operatingSystem"]["image"]));
            } elseif ($deviceInstance['device']['hypervisor']['name'] === 'lxc' && $this->lxc_exist($deviceInstance["uuid"])) {
                $result=$this->stop_device_lxc($deviceInstance["uuid"],$deviceInstance,$labInstance);
                $this->lxc_destroy($deviceInstance["uuid"]);
            }

            /*if ($deviceInstance["device"]["hypervisor"]["name"]==="lxc" && $this->lxc_exist($deviceInstance["uuid"])) 
            {
                $this->logger->debug("Device instance to deleted is an LXC container : ", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
                
                $result=$this->lxc_stop($deviceInstance["uuid"]);
                

                if ($result['state']===InstanceStateMessage::STATE_STOPPED)
                    $this->logger->info("LXC container stopped successfully!", InstanceLogMessage::SCOPE_PUBLIC, [
                        'instance' => $deviceInstance['uuid']]);
                else {
                    $this->logger->error("LXC container stopped with error!", InstanceLogMessage::SCOPE_PUBLIC, [
                        'instance' => $deviceInstance['uuid']]);
                }

                $result=$this->lxc_delete($deviceInstance["uuid"]);
                if ($result["state"]===InstanceStateMessage::STATE_ERROR) {
                    $this->logger->error("Error after lxc_delete in deleteLabInstance with device ".$deviceInstance["uuid"], InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $deviceInstance["uuid"]]);
                    $error=($error || true);
                }
            }*/
        }
        $this->logger->debug("All device deleted", InstanceLogMessage::SCOPE_PRIVATE);
        //TODO: The result of each result of each device instance stop is not used. 
        if (!$error) {
            $this->logger->debug("No error in deleteLabInstance");
            $result=array("state"=> InstanceStateMessage::STATE_DELETED, "uuid"=> $uuid, "options" => null);
        } else
            $this->logger->error("Error in deleteLabInstance",InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $uuid]);
        return $result;
    }

    /**
     * Start an instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to start.
     * @param boolean if the DeviceInstance is called from a Sandbox
     * @throws ProcessFailedException When a process failed to run.
     * @return array("state","uuid", "options") options is an array
     */
    public function startDeviceInstance(string $descriptor, string $uuid,$sandbox=false) {

        /** @var array $labInstance */
        $result=null;
        //$this->logger->setUuid($uuid);
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
            $error=true;
        }

        // OVS

        if (!IPTools::networkInterfaceExists($bridgeName)) {
            OVS::bridgeAdd($bridgeName, true);
            $this->logger->info("Bridge doesn't exists. Creating bridge for lab instance.", InstanceLogMessage::SCOPE_PUBLIC, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        } else {
            $this->logger->debug("Bridge already exists. Skipping bridge creation for lab instance.", InstanceLogMessage::SCOPE_PUBLIC, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        }
        // Secure OVS
        $InternetInterface=$this->getParameter('app.network.lab.internet_interface');
        $VPNInterface=$this->getParameter('app.vpn.interface');
        
        IPTables::create_chain($bridgeName);
        
        $rule=Rule::create()
        ->setInInterface($bridgeName)
        ->setOutInterface($InternetInterface)
        ->setJump('ACCEPT');
        if (!IPTables::exists($bridgeName,$rule)) {
            IPTables::append(
                $bridgeName,
                $rule
            );
        }

        $rule=Rule::create()
        ->setOutInterface($bridgeName)
        ->setInInterface($InternetInterface)
        ->setJump('ACCEPT');
        if (!IPTables::exists($bridgeName,$rule)) {
            IPTables::append(
                $bridgeName,
                $rule
            );
        }

        if ($VPNInterface != "localhost") {
        $rule=Rule::create()
        ->setInInterface($bridgeName)
        ->setOutInterface($VPNInterface)
        ->setJump('ACCEPT');
        if (!IPTables::exists($bridgeName,$rule)) {
            IPTables::append(
                $bridgeName,
                $rule
            );
        }

        $rule=Rule::create()
        ->setOutInterface($bridgeName)
        ->setInInterface($VPNInterface)
        ->setJump('ACCEPT');
        if (!IPTables::exists($bridgeName,$rule)) {
            IPTables::append(
                $bridgeName,
                $rule
            );
        }
        }
        
        $rule=Rule::create()
                ->setJump($bridgeName);
        if (!IPTables::exists(IPTables::CHAIN_FORWARD,$rule)) {
            IPTables::append(
                IPTables::CHAIN_FORWARD,
                $rule
            );
        }

        $labNetwork = new Network($labInstance['network']['ip']['addr'], $labInstance['network']['netmask']['addr']);  
        $gateway = $labNetwork->getLastAddress();
        
        if (!IPTools::networkIPExists($bridgeName, $gateway)) {
            $this->logger->debug("Adding IP address to OVS bridge.", InstanceLogMessage::SCOPE_PRIVATE, [
                'bridge' => $bridgeName,
                'ip' => $gateway
            ]);
            IPTools::addrAdd($bridgeName, $gateway."/".$labInstance['network']['netmask']['addr']);
        }
        $this->logger->debug("OVS bridge set up.", InstanceLogMessage::SCOPE_PRIVATE, [
            'bridge' => $bridgeName
        ]);
        IPTools::linkSet($bridgeName, IPTools::LINK_SET_UP);

        // DHCP
        /*
        if (IPTools::NetworkIfExistDHCP("localhost",8000,"/etc/kea/kea-dhcp4.conf",$labNetwork))
            $this->logger->debug("Network ".$labNetwork->__toString()." already in the DHCP configuration file");
        else
            $this->logger->debug("DHCP registration of network ".$labNetwork->__toString());
        // Network interfaces
        $this->logger->debug("First IP and last for DHCP registration ".$labNetwork->getFirstAddress()->getAddr()." ".$labNetwork->getLastAddress()->getAddr());
        */

        /*        $filename="/etc/kea/kea-dhcp4.conf";
        $fileContent="";
        try {
            $fileContent = file_get_contents($filename);
        }
            catch(ErrorException $e) {
                throw new Exception("Error opening file");
        }
        try {
            $tab = json_decode($fileContent, true);
        }
            catch(ErrorException $e) {
                throw new Exception("Error json_decode of DHCP configuration file");
        }

        $this->logger->debug("File 1 content: ".$fileContent);
        $this->logger->debug("Tab 1 content: ".json_decode($fileContent, true));
*/

      //  IPTools::addnetworkDHCP("localhost",8000,"/etc/kea/kea-dhcp4.conf",$labNetwork,$labNetwork->getFirstAddress(),$labNetwork->getLastAddress());
        

        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] != 'started');
        });

        if (!count($deviceInstance)) {
            $this->logger->info("Device instance is already started. Aborting.", InstanceLogMessage::SCOPE_PUBLIC, [
                'instance' => $deviceInstance['uuid']
            ]);
            // instance is already started or whatever
            return;
        } else {
            $deviceIndex = array_key_first($deviceInstance);
            $deviceInstance = $deviceInstance[$deviceIndex];
        }

        try {
            $this->logger->debug("Lab instance is starting", InstanceLogMessage::SCOPE_PRIVATE, [
                'labInstance' => $labInstance,
                'instance' => $deviceInstance['uuid']
            ]);
            $labUser = $labInstance['owner']['uuid'];
            $ownedBy = $labInstance['ownedBy'];
            $labInstanceUuid = $labInstance['uuid'];
            $img = [
                "source" => $deviceInstance['device']['operatingSystem']['image']
            ];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
            $error=true;
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

        if ($deviceInstance['device']['hypervisor']['name'] === 'qemu') {
            $download_ok=true;
            $this->logger->info('QEMU vm is starting', InstanceLogMessage::SCOPE_PUBLIC, [
                "image" => $deviceInstance['device']['operatingSystem']['name'],
                'instance' => $deviceInstance['uuid']
            ]);
            // Start qemu
            $image_dst=$this->kernel->getProjectDir() . "/images/" . basename($img["source"]);
            if ( !$filesystem->exists($image_dst) ) {
                $this->logger->info('Remote image is not in cache. Downloading...', InstanceLogMessage::SCOPE_PUBLIC, [
                    "image" => $img['source'],
                    'instance' => $deviceInstance['uuid']
                ]);

                if (filter_var($img["source"], FILTER_VALIDATE_URL)) {
                    $url=$img["source"];
                  //  $context=null;
                }
                else {
                    //The source uploaded on the front.
                    $url="http://".$this->front_ip."/uploads/images/".basename($img["source"]);
                    // Only to download from the front when self-signed certificate
                    /*$context = stream_context_create( [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ],
                    ]);*/
                }
                $this->logger->debug('Download image from url : ', InstanceLogMessage::SCOPE_PRIVATE, [
                    "image" => $img['source'],
                    'instance' => $deviceInstance['uuid']
                ]);
                $download_ok=$this->download_http_image($url,$deviceInstance['uuid']);
            }

            if ($download_ok) {
                $img['destination'] = $instancePath . '/' . basename($img['source']);
                $img['source'] = $this->kernel->getProjectDir() . "/images/" . basename($img['source']);

                if (!$filesystem->exists($img['source'])) {
                    $this->logger->info('VM image doesn\'t exist. Download image source...', InstanceLogMessage::SCOPE_PUBLIC, [
                        'source' => $img['source'],
                        'instance' => $deviceInstance['uuid']
                    ]);
                }

                if (!$filesystem->exists($img['destination'])) {
                    $this->logger->info('VM never started. Creating new image from source...', InstanceLogMessage::SCOPE_PUBLIC, [
                        'source' => $img['source'],
                        'destination' => $img['destination'],
                        'instance' => $deviceInstance['uuid']
                    ]);

                    if ($this->qemu_create_relative_img($img['source'], $img['destination'],$deviceInstance['uuid']))
                        $this->logger->info('VM image created.', InstanceLogMessage::SCOPE_PUBLIC, [
                            'path' => $img['destination'],
                            'instance' => $deviceInstance['uuid']
                        ]);
                    else {
                        $this->logger->error('VM image creation in error.', InstanceLogMessage::SCOPE_PUBLIC, [
                            'path' => $img['destination'],
                            'instance' => $deviceInstance['uuid']
                        ]);
                        $result=array("state" => InstanceStateMessage::STATE_ERROR,
                            "uuid"=>$uuid,
                            "options" => null);
                    }
                }
                // If no error in the previous process, when can continue
                if ($result === null) {

                    $parameters = [
                        'system' => [
                            '-m',
                            $deviceInstance['device']['flavor']['memory'],
                            '-drive',
                            'file='.$img['destination'],
                        ],
                        'smp' => ['-smp'],
                        'network' => [],
                        'local' => [],
                        'usb' => [],
                        'access' => [],
                    ];

                    $smp_parameters=$deviceInstance['device']['nbCpu'];
                    
                    if ( array_key_exists('nbCore',$deviceInstance['device']) )
                        $smp_parameters=$smp_parameters.',cores='.$deviceInstance['device']['nbCore'];

                    if ( array_key_exists('nbThread',$deviceInstance['device']) )
                        $smp_parameters=$smp_parameters.',threads='.$deviceInstance['device']['nbThread'];
                    
                    if ( array_key_exists('nbSocket',$deviceInstance['device']) )
                        $smp_parameters=$smp_parameters.',sockets='.$deviceInstance['device']['nbSocket'];
                    
                    array_push($parameters['smp'],$smp_parameters);

                    foreach($deviceInstance['networkInterfaceInstances'] as $nic) {
                        $nicTemplate = $nic['networkInterface'];
                        $nicName = substr(str_replace(' ', '_', $nicTemplate['name']), 0, 6) . '-' . substr($nic['uuid'], 0, 8);
                        $nicVlan = null;
                        if (array_key_exists('vlan', $nicTemplate) && $nicTemplate['vlan'] > 0) {
                            $nicVlan = $nicTemplate['vlan'];
                        }

                        if (!IPTools::networkInterfaceExists($nicName)) {
                            IPTools::tuntapAdd($nicName, IPTools::TUNTAP_MODE_TAP);
                            $this->logger->debug("Network interface created.", InstanceLogMessage::SCOPE_PRIVATE, [
                                'NIC' => $nicName
                            ]);
                        }

                        if (!OVS::ovsPortExists($bridgeName, $nicName)) {
                            OVS::portAdd($bridgeName, $nicName, true, ($nicVlan !== null ? 'tag='.$nicVlan : ''));
                            $this->logger->debug("Network interface added to OVS bridge.", InstanceLogMessage::SCOPE_PRIVATE, [
                                'NIC' => $nicName,
                                'bridge' => $bridgeName
                            ]);
                        }
                        IPTools::linkSet($nicName, IPTools::LINK_SET_UP);
                        $this->logger->debug("Network interface set up.", InstanceLogMessage::SCOPE_PRIVATE, [
                            'NIC' => $nicName
                        ]);

                        array_push($parameters['network'],'-device','e1000,netdev='.$nicName.',mac='.$nic['macAddress'],
                            '-netdev', 'tap,ifname='.$nicName.',id='.$nicName.',script=no');
                    }

                    
                    
                    array_push($parameters['local'], '-k', 'fr');
                    array_push($parameters['local'],
                        '-rtc', 'base=localtime,clock=host', // For qemu 3 compatible
                        '-vga', 'qxl'
                    );
                    

                    //Add usb support
                    array_push($parameters['usb'],
                        '-usb', '-device','usb-tablet,bus=usb-bus.0',
                        '-device','usb-ehci,id=ehci'
                    );
                    
                    $result=$this->remote_access_start($deviceInstance,$sandbox);
                    $this->logger->debug("Error state after remote access wanted", InstanceLogMessage::SCOPE_PRIVATE, [
                        'instance' => $deviceInstance['uuid'],
                        'result-error' => $result["error"]
                    ]);

                    
                    if ($result["error"]===false) {
                    $access_param=$result["arg"];
                        foreach ($access_param as $param) {
                            array_push($parameters['access'],$param);
                        //$this->logger->debug("param access:".$param);
                        }

                        if (!$this->qemu_start($parameters,$uuid)){
                            $this->logger->info("Virtual machine started successfully", InstanceLogMessage::SCOPE_PUBLIC, [
                                'instance' => $deviceInstance['uuid']
                                ]);
                            $this->logger->info("This device can be configured on network:".$labNetwork. " with the gateway ".$gateway, InstanceLogMessage::SCOPE_PUBLIC, [
                                    'instance' => $deviceInstance['uuid']
                                ]);
                            $result=array(
                                "state" => InstanceStateMessage::STATE_STARTED,
                                "uuid" => $deviceInstance['uuid'],
                                "options" => null
                                );
                        }
                        else {
                            $this->logger->error("Virtual machine QEMU doesn't start !", InstanceLogMessage::SCOPE_PUBLIC, [
                                'instance' => $deviceInstance['uuid']
                                ]);
                            $result=array(
                                "state" => InstanceStateMessage::STATE_ERROR,
                                "uuid" => $deviceInstance['uuid'],
                                "options" => null
                            );
                        }
                    } else {
                        $this->logger->error("Remote access process doesn't start correctly !", InstanceLogMessage::SCOPE_PUBLIC, [
                            'instance' => $deviceInstance['uuid']
                        ]);
                        $result=array(
                            "state" => InstanceStateMessage::STATE_ERROR,
                            "uuid" => $deviceInstance['uuid'],
                            "options" => null
                    );
                }
                }
            }
            else {
                $this->logger->error("Download QEMU image in error ! Perhaps, image file is too large", InstanceLogMessage::SCOPE_PUBLIC, [
                    'instance' => $deviceInstance['uuid']
                    ]);
                $this->qemu_delete($deviceInstance['uuid'],$image_dst);
                $result=array(
                    "state" => InstanceStateMessage::STATE_ERROR,
                    "uuid" => $deviceInstance['uuid'],
                    "options" => null
                );
            }
        }
        elseif ($deviceInstance['device']['hypervisor']['name'] === 'lxc' ){//&& $deviceInstance['device']['name'] == 'Service') {
            $this->logger->info('LXC container is starting', InstanceLogMessage::SCOPE_PUBLIC, [
                "image" => $deviceInstance['device']['operatingSystem']['name'],
                'instance' => $deviceInstance['uuid']
            ]);
            $error=false;
            if (!$this->lxc_exist($uuid)) {
                //$this->lxc_clone("Service",$uuid);
                if (!$this->lxc_clone(basename($deviceInstance['device']['operatingSystem']['image']),$uuid)){
                    $this->logger->info("New device created successfully",InstanceLogMessage::SCOPE_PUBLIC,[
                        'instance' => $deviceInstance['uuid']
                    ]);
                    $result=array(
                        "state" => InstanceStateMessage::STATE_STARTED,
                        "uuid" => $deviceInstance['uuid'],
                        "options" => null
                    );
                }
                else {
                    $this->logger->info("Error in LXC clone process",InstanceLogMessage::SCOPE_PUBLIC,[
                        'instance' => $deviceInstance['uuid']
                    ]);
                    $result=array(
                        "state" => InstanceStateMessage::STATE_ERROR,
                        "uuid" => $deviceInstance['uuid'],
                        "options" => null
                    );
                    $error=true;
                }
            }
            if (!$error) {
                //Return the last IP - 1 to address the LXC service container
                //$ip_addr=new IP(long2ip(ip2long($labNetwork->getIp()) + (pow(2, 32 - $labNetwork->getCidrNetmask()) - 3)));
                
                //$this->build_template($uuid,$instancePath,'template.txt',$bridgeName,$ip_addr,$gateway);
                /*$mask="24";
                $this->lxc_create_network($uuid,$bridgeName,$ip_addr,$gateway,$mask);
                */
                $first_ip=$labNetwork->getFirstAddress();
                $last_ip=long2ip(ip2long($labNetwork->getLastAddress())-1);
                $end_range=long2ip(ip2long($labNetwork->getLastAddress())-2);
                $this->logger->info("This device can be configured on network:".$labNetwork. " with the gateway ".$gateway, InstanceLogMessage::SCOPE_PUBLIC, [
                    'instance' => $deviceInstance['uuid']
                    ]);
                $org_file='template.txt';
                if ($deviceInstance["device"]["operatingSystem"]["name"] === "Service") {                
                    $ip_addr=long2ip(ip2long($labNetwork->getLastAddress())-1);
                    $org_file='template.txt';
                    $netmask=$labNetwork->getNetmask();
                    $this->lxc_add_dhcp_dnsmasq(basename($deviceInstance["device"]["operatingSystem"]["image"]),$uuid,$first_ip,$end_range,$netmask,$labNetwork->getLastAddress());
                }
                else {
                    $ip_addr=$first_ip;
                    $org_file='template-noip.txt';
                }

                if ($sandbox)
                    $org_file='template.txt';

                $this->build_template($uuid,$instancePath,$org_file,$bridgeName,$ip_addr,$deviceInstance["networkInterfaceInstances"],$gateway,$sandbox);


                foreach($deviceInstance['networkInterfaceInstances'] as $nic) {
                    //OVS::setInterface($nic["networkInterface"]["uuid"],array("tag" => $nic["vlan"]));
                }

                if ($this->remote_access_start($deviceInstance,$sandbox)["error"]===false) {
                    $this->logger->info("Remote access process started", InstanceLogMessage::SCOPE_PUBLIC, [
                        'instance' => $deviceInstance['uuid']
                        ]);

                    $result=$this->lxc_start($uuid,$instancePath.'/'.$org_file.'-new',$bridgeName,$gateway);

                    if ($result["state"] === InstanceStateMessage::STATE_STARTED ) {
                        $this->logger->info("LXC container started successfully", InstanceLogMessage::SCOPE_PUBLIC, [
                            'instance' => $deviceInstance['uuid']
                            ]);
                        if ($deviceInstance["device"]["operatingSystem"]["name"] === "Service") {
                            $this->logger->info("LXC container is configured with IP:".$ip_addr, InstanceLogMessage::SCOPE_PUBLIC, [
                                'instance' => $deviceInstance['uuid']
                                ]);
                        }

                        

                    } else {
                        $this->logger->error("LXC container not started. Error", InstanceLogMessage::SCOPE_PUBLIC, [
                            'instance' => $deviceInstance['uuid']
                            ]);
                        $result=array("state" => InstanceStateMessage::STATE_ERROR,
                            "uuid"=>$deviceInstance['uuid'],
                            "options" => null);
                    }
                } else {
                    $this->logger->error("Remote access process failed", InstanceLogMessage::SCOPE_PUBLIC, [
                        'instance' => $deviceInstance['uuid']
                        ]);
                    $result=array(
                        "state" => InstanceStateMessage::STATE_ERROR,
                        "uuid" => $deviceInstance['uuid'],
                        "options" => null
                    );
                }
            }
        }

        if ($result["state"] === InstanceStateMessage::STATE_STARTED ) {
            $this->logger->info("Device started successfully", InstanceLogMessage::SCOPE_PUBLIC, [
                'instance' => $deviceInstance['uuid']
        ]);
        }
        else {
            $this->logger->error("Device not started successfully", InstanceLogMessage::SCOPE_PUBLIC, [
                'instance' => $deviceInstance['uuid']
            ]);
            $result=array(
                "state" => InstanceStateMessage::STATE_ERROR,
                "uuid" => $deviceInstance['uuid'],
                "options" => null
            );
        }

        $this->logger->debug("Return value after start device process", InstanceLogMessage::SCOPE_PRIVATE, [
            'return' => $result
        ]);

        return $result;

    }

    // Return an array 
    // "error" => boolean
    // "arg" => all arguments need to start the device
    // false otherwise
    private function remote_access_start($deviceInstance,$sandbox) {
        $result=[ "error" => false,
        "arg" => array()
        ];
        $error=false;
        if ($remote_port=$this->isLogin($deviceInstance)) {
            $this->logger->info("Login access requested.", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $deviceInstance['uuid']
            //'controlProtocolTypeInstances' => $deviceInstance['controlProtocolTypeInstances']
            ]);
            
            $this->logger->debug("Starting ttyd process...", InstanceLogMessage::SCOPE_PRIVATE, [
                'instance' => $deviceInstance['uuid']
                ]);
            $remote_interface=$this->getParameter('app.network.data.interface');
            if ($this->ttyd_start($deviceInstance['uuid'],$remote_interface,$remote_port,$sandbox,"login")) {
                $this->logger->debug("ttyd process started", InstanceLogMessage::SCOPE_PUBLIC, [
                        'instance' => $deviceInstance['uuid']
                        ]);
            }
            else {
                $this->logger->error("ttyd starting process in error !", InstanceLogMessage::SCOPE_PRIVATE, [
                    'instance' => $deviceInstance['uuid']
                    ]);
                $result["arg"]=array("state" => InstanceStateMessage::STATE_ERROR,
                        "uuid"=>$deviceInstance['uuid'],
                        "options" => null);
                $error=true;
            }
        }
        $result["error"]=$result["error"] || $error;
        
        $this->logger->debug("Error state after ttyd for login", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $deviceInstance['uuid'],
            'result-error' => $result["error"]
        ]);

        if ($remote_port=$this->isSerial($deviceInstance)) {
            $this->logger->info("Serial access requested. Adding server to Serial access.", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $deviceInstance['uuid']
            //'controlProtocolTypeInstances' => $deviceInstance['controlProtocolTypeInstances']
            ]);
            $new_free_port=$this->getFreePort();
            array_push($result["arg"],'-chardev','socket,id=serial0,server,telnet,port='.($new_free_port).',host=127.0.0.1,nowait');
            array_push($result["arg"],'-serial','chardev:serial0');

            $this->logger->debug("Starting ttyd process for serial access...", InstanceLogMessage::SCOPE_PRIVATE, [
                'instance' => $deviceInstance['uuid']
                ]);
            $remote_interface=$this->getParameter('app.network.data.interface');
            $error=$this->ttyd_start($deviceInstance['uuid'],$remote_interface,$remote_port,$sandbox,"serial",$new_free_port);
            if ($error==false) {
                $this->logger->debug("Ttyd process started", InstanceLogMessage::SCOPE_PUBLIC, [
                        'instance' => $deviceInstance['uuid']
                        ]);
            }
            else {
                $this->logger->error("Ttyd starting process in error !", InstanceLogMessage::SCOPE_PRIVATE, [
                    'instance' => $deviceInstance['uuid'],
                    'error' => $error
                    ]);
                    $result["arg"]=array("state" => InstanceStateMessage::STATE_ERROR,
                        "uuid"=>$deviceInstance['uuid'],
                        "options" => null);
                }
        }

        $result["error"]=$result["error"] || $error;

        $this->logger->debug("Error state after ttyd for serial", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $deviceInstance['uuid'],
            'result-error' => $result["error"]
        ]);

        if ($vncPort=$this->isVNC($deviceInstance)) {
            $this->logger->info("VNC access requested.", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $deviceInstance['uuid']
            //'deviceInstance' => $deviceInstance
            ]);
            $vncAddress = "0.0.0.0";

            $this->logger->debug("Starting websockify process...", InstanceLogMessage::SCOPE_PRIVATE, [
                'instance' => $deviceInstance['uuid']
                ]);
            
            $error=$this->websockify_start($deviceInstance['uuid'],$vncAddress,$vncPort);
            if ($error===true)
                $this->logger->debug("websockify doesn't start", InstanceLogMessage::SCOPE_PRIVATE, [
                    'instance' => $deviceInstance['uuid'],
                    'result-error' => $error
                ]);
            else {
                $this->logger->debug("websockify started", InstanceLogMessage::SCOPE_PRIVATE, [
                    'instance' => $deviceInstance['uuid'],
                    'result-error' => $error
                ]);
                array_push($result["arg"],'-vnc',$vncAddress.":".($vncPort - 5900));
            }
        }
        $result["error"]=$result["error"] || $error;

        $this->logger->debug("Error state after websockify", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $deviceInstance['uuid'],
            'result-error' => $result["error"]
        ]);

        return $result;
    }

 /**
 * @return true if error, false if no error occurs
 */
public function websockify_start($uuid,$IpAddress,$Port){
    $error=false;
    $command = ['websockify', '-D'];
    if ($this->getParameter('app.services.proxy.wss')) {
        $this->logger->debug("Websocket use wss", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
            ]);
        array_push($command,'--cert='.$this->getParameter('app.services.proxy.cert'),'--key='.$this->getParameter('app.services.proxy.key'));
    } else
        $this->logger->debug("Websocket doesn't use wss", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
            ]);
    array_push($command, $IpAddress.':' . ($Port + 1000), $IpAddress.':'.$Port);
    
    $process = new Process($command);
    try {
        $this->logger->debug("Websockify starting command: ".implode(" ",$command), InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
            ]);
        $process->mustRun();
    }   catch (ProcessFailedException $exception) {
            $this->logger->error("Websockify starting process in error !", InstanceLogMessage::SCOPE_PRIVATE, [
                'instance' => $uuid
                ]);
            $error=true;
            }
        if (!$error) {
        $command="ps aux | grep " . $IpAddress . ":" . $Port . " | grep websockify | grep -v grep | awk '{print $2}'";
        $this->logger->debug("List websockify: ".$command, InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
            ]);

        $process = Process::fromShellCommandline($command);
        try {
            $process->mustRun();
            //$pidInstance = $process->getOutput();
            }   catch (ProcessFailedException $exception) {
            $this->logger->error("Listing process to find websockify process error !".$exception, InstanceLogMessage::SCOPE_PRIVATE, [
                'instance' => $uuid
                ]);
            $this->logger->error("Websockify starting process in error !", InstanceLogMessage::SCOPE_PRIVATE, [
                    'instance' => $uuid
                ]);
            $error=true;
            }
        }
    return $error;
}

 /**
 * @return true if error, false otherwise
 * @param $sandbox : boolean true if from a sandbox
 * @param string $remote_protocol : serial or login
 */
public function ttyd_start($uuid,$interface,$port,$sandbox,$remote_protocol,$device_remote_port=null){
    $error=false;
    $command = ['screen','-S',$uuid,'-dm','ttyd'];
    if ($sandbox)
        $this->logger->debug("Ttyd called from sandbox", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
        ]);
    else
        $this->logger->debug("Ttyd called from lab", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
        ]);
    if ($this->getParameter('app.services.proxy.wss')) {
        $this->logger->debug("Ttyd use https", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
            ]);
        //array_push($command,'-S','-C',$this->getParameter('app.services.proxy.cert'),'-K',$this->getParameter('app.services.proxy.key'));
    } else
        $this->logger->debug("Ttyd without https", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
            ]);
        if ($remote_protocol === "login") {
            if ($sandbox) {
                $this->logger->debug("Start device from Sandbox detected");  
                array_push($command, '-p',$port,'-b','/device/'.$uuid,'lxc-attach','-n',$uuid);
            }
            else {
                $this->logger->debug("Start device from lab detected");
                //array_push($command, '-p',$port,'-b','/device/'.$uuid,'lxc-console','-n',$uuid);
                array_push($command, '-p',$port,'-b','/device/'.$uuid,'lxc-attach','-n',$uuid,'--','login');
            }
        }
        elseif ($remote_protocol === "serial") {
                $this->logger->debug("Start serial detected");
                array_push($command, '-p',$port,'-b','/device/'.$uuid,'telnet','localhost',$device_remote_port);
                //array_push($command, '-p',$port,'telnet','localhost',$device_remote_port);
        }
        

    $this->logger->debug("Ttyd command", InstanceLogMessage::SCOPE_PRIVATE, [
        'instance' => $uuid,
        'command' => $command
            ]);

    $process = new Process($command);
    try {
        $process->start();
    }   catch (ProcessFailedException $exception) {
        $error=true;
        $this->logger->debug("Ttyd error command", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid,
            'exception' => $exception
                ]);
    }
    $command="ps aux | grep ". $port . " | grep ttyd | grep -v grep | awk '{print $2}'";
    $this->logger->debug("List ttyd:".$command, InstanceLogMessage::SCOPE_PRIVATE, [
        'instance' => $uuid
        ]);
        
    $process = Process::fromShellCommandline($command);
    try { 
        $process->mustRun();
        $pidInstance = $process->getOutput();
        $this->logger->debug("pid process list of ttyd started ", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid,
            'pid' => $pidInstance,
            'error' => $error
            ]);
    }   catch (ProcessFailedException $exception) {
        $error=true;
        $this->logger->error("Listing process to find ttyd process error !".$exception, InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
            ]);
    }
    if ($pidInstance==""){
        $error=true;
        $this->logger->error("ttyd not started !", InstanceLogMessage::SCOPE_PRIVATE, [
            'instance' => $uuid
            ]);    
    }
    return $error;
}





    /**
     * Create a qemu image file
     * @param string $img_base The base of the image
     * @param string $dst the file in which only the difference from $img_base will be saved
     * @return true if no error and false if an error occurs
     */
    public function qemu_create_relative_img($img_base,$dst,$uuid){
        $result=null;
        $process = new Process([ 'qemu-img', 'create', '-f', 'qcow2', '-F', 'qcow2', '-b', $img_base,$dst]);
        try {
            $process->mustRun();
            $result=true;
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("QEMU commit error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE, [
                'instance' => $uuid
                ]);
            $this->logger->error("QEMU commit error !", InstanceLogMessage::SCOPE_PUBLIC, [
                    'instance' => $uuid
                    ]);
            $result=false;
        }
        return $result;
    }

    /**
     * Configure the dnsmasq configuration file to add the network in a range
     * @param string $uuid the $uuid of the device
     * @param string $first_ip the first IP of the range
     * @param string $last_ip the last IP of the range
     * @param string $netmask the netmaks of the network
     */
    public function lxc_add_dhcp_dnsmasq($image,$uuid,$first_ip,$last_ip,$netmask,$gateway){
        $line_to_add=$first_ip.",".$last_ip.",".$netmask.",1h";     
        $file_path="/var/lib/lxc/".$uuid."/rootfs/etc/dnsmasq.conf";
        $source_file_path="/var/lib/lxc/".$image."/rootfs/etc/dnsmasq.conf";
        $command="sed \
            -e \"s/RANGE_TO_DEFINED/".$line_to_add."/g\" \
            -e \"s/GW_TO_DEFINED/".$gateway."/g\" \
            ".$source_file_path." > ".$file_path;

        $process = Process::fromShellCommandline($command);
        $this->logger->debug("Add dhcp range to dnsmasq configuration:".$command, InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $uuid]);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Dhcp adding in error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $uuid]);
            }
    }

    /**
     * Build a template file for LXC container with 1 network interface configured with the $network_addr IP
     * and the $gateway_IP as gateway
     * @param string $uuid the $uuid of the device
     * @param string $path the absolute path to the template to configured
     * @param string $bridgeName the bridge name on with we have to connect this container
     * @param string $network_addr the IP of the container
     * @param string $gateway_IP the gateway the container uses
     * @param boolean $from_sandbox true if this function is called from a sandbox
     */
    public function build_template($uuid,$instance_path,$filename,string $bridgeName,string $network_addr,array $networkinterfaceinstance,string $gateway_IP,$from_sandbox) {
        if (!is_null($networkinterfaceinstance) && count($networkinterfaceinstance)>0)
            $this->logger->debug("Build template networkinterfaceinstance.", InstanceLogMessage::SCOPE_PRIVATE, [
                "networkinterfaceinstance" => $networkinterfaceinstance[0]
            ]);    
        $path=$instance_path."/".$filename;
        $command = [
                'cp',
                $this->kernel->getProjectDir().'/scripts/'.$filename,
                $path
        ];
            
            $this->logger->debug("Copying LXC template to instance path.", InstanceLogMessage::SCOPE_PRIVATE, [
                "command" => implode(' ',$command)
            ]);
            
            $process = new Process($command);
            $process->setTimeout(600);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Copying LXC template error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
            }

            //sed  -e "s/XVLANY/$VLAN/g" -e "s/NAME-CONT/$NAME/g" -e "s/IP-ADM/$ADMIN_IP\/$ADMIN_MASK/g" -e "s/IP-DATA/$DATA_IP\/$DATA_MASK/g" ${SOURCE} > $FILE
            $this->logger->debug("networkinterfaceinstance in template", InstanceLogMessage::SCOPE_PRIVATE, [
                "array" => $networkinterfaceinstance,
                "size" => count($networkinterfaceinstance)
            ]);
        
                $IP=$network_addr;
                $IP_GW=$gateway_IP;
                $MASK="24";
                $INTERFACE="eth0";
                $BRIDGE_NAME=$bridgeName;
                //random mac
            if (!is_null($networkinterfaceinstance) && count($networkinterfaceinstance)>0)
            {
                $MAC_ADDR=$networkinterfaceinstance[0]["macAddress"];
            } else
                $MAC_ADDR=$this->macgen();

            $command="sed \
            -e \"s/NAME-CONT/".$uuid."/g\" \
            -e \"s/INTERFACE/".$INTERFACE."/g\" \
            -e \"s/BRIDGE_NAME/".$BRIDGE_NAME."/g\" \
            -e \"s/IP_GW/".$IP_GW."/g\" \
            -e \"s/IP/".$IP."\/".$MASK."/g\" \
            -e \"s/VLAN_UP/".str_replace("/","\/",$instance_path)."\/set_vlan/g\" \
            -e \"s/MAC_ADDR/".$MAC_ADDR."/g\" ".$path." > ".$path."-new";

            $process = Process::fromShellCommandline($command);
            $this->logger->debug("Build template with sed:".$command, InstanceLogMessage::SCOPE_PRIVATE);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("sed exec to build template error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
            }


            $size=count($networkinterfaceinstance);
            if ($size>0) {
                $this->logger->debug("More than one interface detected.", InstanceLogMessage::SCOPE_PRIVATE);
                $command="";
                $file=$path."-new";
                $i=0;
                $vlan=$networkinterfaceinstance[$i]["networkInterface"]["vlan"];
                $this->logger->debug("Detect vlan ".$vlan, InstanceLogMessage::SCOPE_PRIVATE);

                    if ($vlan>0) {
                        $command="echo \"lxc.net.".$i.".script.up = ".$instance_path."/set_vlan".$i."\" >> ".$file.";";
                        $process = Process::fromShellCommandline($command);
                        $this->logger->debug("Add line to script up:".$command, InstanceLogMessage::SCOPE_PRIVATE);
                        try {
                            $process->mustRun();
                        }   catch (ProcessFailedException $exception) {
                            $this->logger->error("Error when add line script_up ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                        }
                        $command="";
                        $command=$command."echo \"#!/bin/sh\" > ".$instance_path."/set_vlan".$i.";";
                        $command=$command.'echo "/usr/bin/ovs-vsctl set port \${LXC_NET_PEER} tag='.$vlan.'" >> '.$instance_path.'/set_vlan'.$i.';';

                       // $command="sed -e \"s/VLAN/".$networkinterfaceinstance[$i]["networkInterface"]["vlan"]."/g\" ".$this->kernel->getProjectDir()."/scripts/set_vlan >> ".$instance_path."/set_vlan";

                        $this->logger->debug("Copying set_vlan".$i." script to instance path for vlan.".$networkinterfaceinstance[$i]["networkInterface"]["vlan"], InstanceLogMessage::SCOPE_PRIVATE, [
                            "command" => $command
                        ]);

                        $process = Process::fromShellCommandline($command);
                        $this->logger->debug("Set VLAN ".$networkinterfaceinstance[$i]["networkInterface"]["vlan"]." for the interface :".$command, InstanceLogMessage::SCOPE_PRIVATE);
                        try {
                            $process->mustRun();
                        }   catch (ProcessFailedException $exception) {
                            $this->logger->error("Set VLAN error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                        }
                        chmod($instance_path."/set_vlan".$i,"755");
                    }

                for ($i=1; $i<$size; $i++){
                    $command=$command."echo \"lxc.net.".$i.".type = veth\" >> ".$file.";";
                    $command=$command."echo \"lxc.net.".$i.".name = eth".$i."\" >> ".$file.";";
                    $command=$command."echo \"lxc.net.".$i.".link = ".$BRIDGE_NAME."\" >> ".$file.";";
                    $command=$command."echo \"lxc.net.".$i.".flags = up\" >> ".$file.";";
                    $command=$command."echo \"lxc.net.".$i.".hwaddr = ".$networkinterfaceinstance[$i]["macAddress"]."\" >> ".$file.";";
                    $process = Process::fromShellCommandline($command);
                    $this->logger->debug("Add interface in template file: ".$command, InstanceLogMessage::SCOPE_PRIVATE);
                    try {
                        $process->mustRun();
                    }   catch (ProcessFailedException $exception) {
                        $this->logger->error("Error to add interface in template file ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                    }
                    $command="";
                    $vlan=$networkinterfaceinstance[$i]["networkInterface"]["vlan"];
                    if ($vlan>0) {
                        $command="echo \"lxc.net.".$i.".script.up = ".$instance_path."/set_vlan".$i."\" >> ".$file.";";
                        $process = Process::fromShellCommandline($command);
                        $this->logger->debug("Add line to script up:".$command, InstanceLogMessage::SCOPE_PRIVATE);
                        try {
                            $process->mustRun();
                        }   catch (ProcessFailedException $exception) {
                            $this->logger->error("Error when add line script_up ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                        }
                        $command="";
                        $command=$command."echo \"#!/bin/sh\" > ".$instance_path."/set_vlan".$i.";";
                        $command=$command.'echo "/usr/bin/ovs-vsctl set port \${LXC_NET_PEER} tag='.$vlan.'" >> '.$instance_path.'/set_vlan'.$i.';';

                       // $command="sed -e \"s/VLAN/".$networkinterfaceinstance[$i]["networkInterface"]["vlan"]."/g\" ".$this->kernel->getProjectDir()."/scripts/set_vlan >> ".$instance_path."/set_vlan";

                        $this->logger->debug("Copying set_vlan".$i." script to instance path for vlan.".$networkinterfaceinstance[$i]["networkInterface"]["vlan"], InstanceLogMessage::SCOPE_PRIVATE, [
                            "command" => $command
                        ]);

                        $process = Process::fromShellCommandline($command);
                        $this->logger->debug("Set VLAN ".$networkinterfaceinstance[$i]["networkInterface"]["vlan"]." for the interface :".$command, InstanceLogMessage::SCOPE_PRIVATE);
                        try {
                            $process->mustRun();
                        }   catch (ProcessFailedException $exception) {
                            $this->logger->error("Set VLAN error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                        }
                        chmod($instance_path."/set_vlan".$i,"755");
                }
            
            
            }
                $process = Process::fromShellCommandline($command);
                $this->logger->debug("Add more network interfaces to the template :".$command, InstanceLogMessage::SCOPE_PRIVATE);
                try {
                    $process->mustRun();
                }   catch (ProcessFailedException $exception) {
                    $this->logger->error("echo exec to add more network interfaces to the template error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                }


                            #Add in the templace for each interface 

            
            }

    }
    /**
     * Test if the LXC container with name $name exists
     * @param string $name : the name of the LXC container to test
     */
    public function lxc_exist(string $name) {
        
        //$process = new Process(['lxc-ls','-f','|grep','-e',"$name",'|grep','-v','grep']);      
        //$process = new Process('lxc-ls -f | grep "$VAR1" | grep -v "$VAR2"');
        //$cmd="lxc-ls -f | grep ".$name;
        $process = Process::fromShellCommandline("lxc-ls -f");

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            $this->logger->error("Execution lxc list error", InstanceLogMessage::SCOPE_PRIVATE, $exception->getMessage());
        }
        //Filter each ligne because the fromShellCommandLine("lxc-ls -f |grep $name | grep -v grep") doesn't work.
        //May be because the | and a - in the $name generate an issue
        //The pipe seems not to work in an array
        $exist=false;
        foreach( explode(' ',preg_replace('/\s+/',' ',$process->getOutput())) as $chr) {
            //$this->logger->debug("line:".$chr, InstanceLogMessage::SCOPE_PRIVATE);    
            if (strcmp($chr,$name) == 0){
                $exist=true || $exist;
            }
            else {
                $exist=false || $exist;
            }
        }

        //$this->logger->debug("LXC container $name existence testing. Process return:".$exist, InstanceLogMessage::SCOPE_PRIVATE);
        
        if ($exist) {
            $this->logger->debug("The LXC container $name exists", InstanceLogMessage::SCOPE_PRIVATE);
        }
        else {
            $this->logger->debug("The LXC container $name doesn't exist", InstanceLogMessage::SCOPE_PRIVATE);
        }

        return $exist;
    }

    /**
     * Fuction to create a network for LXD
     * @param string $uuid the $uuid of the device
     * @param string $bridgeName the bridge name on with we have to connect this container
     * @param string $network_addr the IP of the container
     * @param string $gateway_IP the gateway the container uses
     */

     public function create_lxd_network($uuid,string $bridgeName,string $network_addr,string $gateway_IP,string $mask) {
        $ipv4_address='ipv4.address='.$network_addr.'/'.$mask;
        $command = [
            'lxc','network','create','uuid-network','--type=bridge',$ipv4_address,'ipv6.address=none'
        ];
     }
    /**
     * Function to start qemu
     * TODO finish this function qemu_start
     * @param array $parameters Array of all parameters to the command qemu.
     * @param string $uuid UUID of the device instance to start.
     */
    public function qemu_start(array $parameters,string $uuid ){
        // TODO return value to detect error
        $arch = posix_uname()['machine'];
        $error=false;
        $command = [
            'qemu-system-' . $arch,
            '-enable-kvm',
            '-machine', 'accel=kvm:tcg',
            '-cpu', 'max',
            '-display', 'none',
            '-daemonize',
            '-name', "$uuid"
        ];

        foreach ($parameters as $parametersType) {
            foreach ($parametersType as $parameter) {
                if (!is_null($parameter) && $parameter != "") {
/*                    if (gettype($parameter)==="string")
                        $this->logger->debug('parameters string:'.$parameter);
                    if (gettype($parameter)==="array")
                        $this->logger->debug('parameters array:',$parameter);*/
                    array_push($command, $parameter);
                }
            }
        }

        $this->logger->debug("Starting QEMU virtual machine.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',preg_replace('/\s+/', ' ', $command))
        ]);
        $process = new Process($command);
        try {
            $process->mustRun();
        }   catch (ProcessFailedException $exception) {

            $message=explode("in /",explode("Error Output:",$exception)[1])[0];
            $this->logger->debug("Detect write lock", InstanceLogMessage::SCOPE_PRIVATE,[ 'message' => $message]);
            if (str_contains($message,"lock\nIs another process using the image")) {
                //$this->logger->debug("Detect write lock", InstanceLogMessage::SCOPE_PRIVATE,[ 'instance' => $uuid]);
                $this->logger->debug("QEMU virtual machine already started", InstanceLogMessage::SCOPE_PRIVATE,[ 'instance' => $uuid]);
                $error=false;
            } else {
                $this->logger->error("Starting QEMU virtual machine error! ".$message, InstanceLogMessage::SCOPE_PUBLIC,
                [ 'instance' => $uuid]);
                //$this->logger->debug("Starting QEMU virtual machine error! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                $error=true;
            }
        }
        return $error;
    }

    /**
     * Function to clone a LXC container
     * @param string $src_lxc_name Name of the LXC contianer to clone.
     * @param string $dst_lxc_name Name of the new LXC container created.
     */
    public function lxd_clone(string $src_lxc_name,string $dst_lxc_name){
        $command = [
            'lxc copy',
            "$src_lxc_name",
            "$dst_lxc_name"
        ];
        $this->logger->info("Cloning LXD container in progress", InstanceLogMessage::SCOPE_PUBLIC, [
            'instance' => $dst_lxc_name]
        );
        $this->logger->debug("Cloning LXD container.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command),
            'instance' => $dst_lxc_name
        ]);

        $process = new Process($command);
        try {
            $process->mustRun();
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("LXD container cloned error ! ", InstanceLogMessage::SCOPE_PRIVATE, [
                'error' => $exception->getMessage(),
                'instance' => $dst_lxc_name
            ]);
        }

        $this->logger->info("LXD container cloned successfully", InstanceLogMessage::SCOPE_PUBLIC, [
            'instance' => $dst_lxc_name]);
    }

    /**
     * Function to clone a LXC container
     * @param string $src_lxc_name Name of the LXC contianer to clone.
     * @param string $dst_lxc_name Name of the new LXC container created.
     * @return $error: true if error or false if no error
     */
    public function lxc_clone(string $src_lxc_name,string $dst_lxc_name){
        $error=null;
        $command = [
            'lxc-copy',
            '-n',
            "$src_lxc_name",
            '-N',
            "$dst_lxc_name"
        ];
        $this->logger->info("Cloning LXC container in progress", InstanceLogMessage::SCOPE_PUBLIC, [
            'instance' => $dst_lxc_name]
        );
        $this->logger->debug("Cloning LXC container.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);

        $process = new Process($command);
        $process->setTimeout(600);
        try {
            $process->mustRun();
            $error=false;
        }   catch (ProcessFailedException $exception) {
            $error=true;
            $this->logger->error("LXC container cloned is in error ! ", InstanceLogMessage::SCOPE_PUBLIC, [
                'error' => $exception->getMessage(),
                'instance' => $dst_lxc_name
            ]);
        }
        if (!$error)
            $this->logger->info("LXC container cloned successfully", InstanceLogMessage::SCOPE_PUBLIC, [
                'instance' => $dst_lxc_name]);

        return $error;
        
    }

    /**
     * Delete a lxc container
     * @param string $uuid UUID of LXC contianer to delete.
     */
    public function lxc_delete(string $uuid){
        $result=null;
        $command = [
            'lxc-destroy',
            '-n',
            "$uuid"
        ];

        $this->logger->debug("Deleting LXC container.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);

        $process = new Process($command);
        $process->setTimeout(600);
        try {
            $process->mustRun();
            $result=array(
                "state" => InstanceStateMessage::STATE_DELETED,
                "uuid" => $uuid,
                "options" => null
                );
            $error=false;
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("LXC container deleted error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $uuid]);
            $result=array(
                "state" => InstanceStateMessage::STATE_ERROR,
                "uuid" => $uuid,
                "options" => null
                );
            $error=true;
        }
        if (!$error)
            $this->logger->info("LXC container deleted successfully!", InstanceLogMessage::SCOPE_PUBLIC, [
                'instance' => $uuid]);
        
        return $result;
    }


    /**
     * Function to start lxc
     * @param array $parameters Array of all parameters to the command qemu.
     * @param string $uuid UUID of the device instance to start.
     * @param string $template the absolute path to the template file of the LXC container
     * @return array $result is an array("state","uuid")
     */
    public function lxc_start(string $lxc_name,string $template){
        $result=null;
        $command = [
            'lxc-start',
            '-n',
            $lxc_name,
            '-f',
            $template
        ];

        $this->logger->debug("Starting LXC container.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);

        $process = new Process($command);
        $process->setTimeout(600);
        try {
            $process->mustRun();
            $result=array(
                "state" => InstanceStateMessage::STATE_STARTED,
                "uuid" => $lxc_name,
                "options" => null
            );
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("LXC container started error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE,
                ["instance" => $lxc_name]);
            $result=array(
                "state" => InstanceStateMessage::STATE_ERROR,
                "uuid" => $lxc_name,
                "options" => null
            );
        }

        return $result;

    }

    /**
     * Stop a device instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a device instance.
     * @param string $uuid UUID of the device instance to stop.
     * @throws ProcessFailedException When a process failed to run.
     * @return void
     */

    public function stopDeviceInstance(string $descriptor, string $uuid) {
        $this->logger->setUuid($uuid);

        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance);
        }
        $this->logger->debug("Device instance stopping", InstanceLogMessage::SCOPE_PRIVATE, [
            'labInstance' => $labInstance
        ]);

        // Network interfaces
        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] != 'stopped');
        });

        if (!count($deviceInstance)) {
            $this->logger->debug("Device instance is already stopped.", InstanceLogMessage::SCOPE_PUBLIC, [
                'instance' => $deviceInstance['uuid']]);
            // instance is already stopped or whatever
        } else {
            $deviceIndex = array_key_first($deviceInstance);
            $deviceInstance = $deviceInstance[$deviceIndex];
        }

        if ($deviceInstance['device']['hypervisor']['name'] === 'qemu') {
            $result=$this->stop_device_qemu($uuid,$deviceInstance,$labInstance);
        } elseif ($deviceInstance['device']['hypervisor']['name'] === 'lxc') {
            $result=$this->stop_device_lxc($uuid,$deviceInstance,$labInstance);
        }

        // OVS
        /*
        $activeDeviceCount = count(array_filter($labInstance['deviceInstances'], function ($deviceInstance) {
            return $deviceInstance['state'] == InstanceStateMessage::STATE_STARTED;
        })) - 1;

        if ($activeDeviceCount <= 0) {
            // OVS::bridgeDelete($bridgeName, true);
        }*/

        return $result;

        // $filesystem = new Filesystem();
        // $filesystem->remove($this->workerDir . '/instances/' . $labUser . '/' . $labInstanceUuid . '/' . $uuid);
    }
    

    /**
    * @return true if no error, false if error
    */
    public function qemu_stop($uuid) {
        $result=true;
        $process = Process::fromShellCommandline("ps aux | grep -e " . $uuid . " | grep -e qemu | grep -v grep | awk '{print $2}'");
        try {
            $process->mustRun();
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("Process listing error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE,[
                'instance' => $uuid]);
                $result=false;
        }

        $pidInstance = $process->getOutput();

        if ($pidInstance != "") {
            $pidInstance = explode("\n", $pidInstance);

            foreach ($pidInstance as $pid) {
                if ($pid != "") {
                    $process = new Process(['kill', '-9', $pid]);
                    try {
                        $process->mustRun();
                    }   catch (ProcessFailedException $exception) {
                        $this->logger->error("Killing exec error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE,[
                            'instance' => $uuid]);
                        $result=false;
                    }
                }
            }
        }
        return $result;
    }


    /**
     * Function to start lxc
     * TODO finish this function lxc_stop
     * @param array $parameters Array of all parameters to the command qemu.
     * @param string $uuid UUID of the device instance to start.
     * @return array $result is an array("state","uuid")
     */
    public function lxc_stop(string $lxc_name){
        $result=null;
        $command = [
            'lxc-stop',
            '-n',
            "$lxc_name"
        ];

        $this->logger->debug("Stopping LXC container.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);

        $process = new Process($command);
        try {
            $process->mustRun();
            $result=array(
                "state" => InstanceStateMessage::STATE_STOPPED,
                "uuid" => $lxc_name
            );
        }   catch (ProcessFailedException $exception) {
            if (!str_contains($exception,$lxc_name." is not running")) {
                $this->logger->error("LXC container stopping error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $lxc_name]);
                $result=array(
                    "state" => InstanceStateMessage::STATE_ERROR,
                    "uuid" => $lxc_name,
                    "options" => null
                );
            }
            else {
                $this->logger->debug("LXC container not running :".$exception, InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $lxc_name]);
                $result=array(
                    "state" => InstanceStateMessage::STATE_STOPPED,
                    "uuid" => $lxc_name,
                    "options" => null
                );  
            }
        }
        return $result;
    }

    public function connectToInternet(string $descriptor)
    {
        /** @var array $labInstance */
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $labNetwork = $this->params->get('app.network.lab.cidr');
        $dataNetwork = $this->params->get('app.network.data.cidr');
        $bridgeInt = $this->params->get('app.network.lab.internet_interface');
        $bridgeIntGateway = $this->params->get('app.data.network.gateway');

        IPTools::linkSet($bridgeInt, IPTools::LINK_SET_UP);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

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
        $bridgeInt = $this->params->get('app.network.lab.internet_interface');
        $bridgeIntGateway = $this->params->get('app.data.network.gateway');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

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
        $bridgeInt = $this->params->get('app.network.lab.internet_interface');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

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
        $bridgeInt = $this->params->get('app.network.lab.internet_interface');

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        $bridge = $labInstance['bridgeName'];

        OVS::UnlinkTwoOVS($bridge, $bridgeInt);
    }

    /**
     * Export an instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to export.
     * @throws ProcessFailedException When a process failed to run.
     * @return array ["state", "uuid", "options"=array("newOs_id", "newDevice_id", "new_os_name", "new_os_imagename")]
     */
    public function exportDeviceInstance(string $descriptor, string $uuid) {
        $this->logger->setUuid($uuid);
        $result="";
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] == 'exporting');
        });

        $this->logger->debug("export process, instance descriptor argument: ".$descriptor,InstanceLogMessage::SCOPE_PRIVATE);
        if (!count($deviceInstance)) {
            $this->logger->info("Device instance is already started. Aborting.", InstanceLogMessage::SCOPE_PUBLIC, [
                'instance' => $deviceInstance['uuid']
            ]);
            // instance is already started or whatever
            $result=array(
                "state" => InstanceStateMessage::STATE_STARTED,
                "uuid" => $deviceInstance['uuid'],
                "options" => array(
                "newOS_id" => $labInstance["newOS_id"],
                "newDevice_id" => $labInstance["newDevice_id"],
                "new_os_name" => $labInstance["new_os_name"],
                "new_os_imagename" => $labInstance["new_os_imagename"],
                "state" => InstanceActionMessage::ACTION_EXPORT,
                    )
            );
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
            $newImageName = $labInstance['new_os_imagename'];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }
        
        $hypervisor=$deviceInstance['device']['operatingSystem']['hypervisor']['name'];
        $imagefilename=$newImageName;
        
        // Test here if hypervisor is qemu
        if (($hypervisor === "qemu") || ($hypervisor === "file")) {
                $this->logger->debug("Qemu image exportation detected");
                $instancePath = $this->kernel->getProjectDir() . "/instances";
                $instancePath .= ($ownedBy === 'group') ? '/group' : '/user';
                $instancePath .= '/' . $labUser;
                $instancePath .= '/' . $labInstanceUuid;
                $instancePath .= '/' . $uuid;

                $imagePath = $this->kernel->getProjectDir() . "/images";

                $newImagePath = $imagePath . '/' . $imagefilename;
                $copyInstancePath = $instancePath . '/snap-' . basename($img["source"]);

                // Test if the image instance file exists. If the device has not been started before the export, the image instance doesn't exist.
                    
                $filename=$instancePath . '/' . basename($img["source"]);
                $this->logger->debug("Test if image exist $filename",InstanceLogMessage::SCOPE_PRIVATE);
                    
                if (file_exists($filename)) {
                    $this->logger->info("Starting export image...", InstanceLogMessage::SCOPE_PUBLIC, [
                        'instance' => $deviceInstance['uuid']
                    ]);

                    $command = [
                    'cp',
                    $imagePath . '/' . basename($img["source"]),
                    $newImagePath
                    ];

                    $this->logger->debug("Copying base image.", InstanceLogMessage::SCOPE_PRIVATE, [
                        "command" => implode(' ',$command)
                    ]);

                    $process = new Process($command);
                    $process->setTimeout(600);
                    try {
                        $process->mustRun();
                    }   catch (ProcessFailedException $exception) {
                        $this->logger->error("Copying QEMU image file error ! ", InstanceLogMessage::SCOPE_PRIVATE, $exception->getMessage());
                    }

                    $command = [
                        'cp',
                        $instancePath . '/' . basename($img["source"]),
                        $copyInstancePath
                    ];

                    $this->logger->debug("Copying backing image file.", InstanceLogMessage::SCOPE_PRIVATE, [
                        "command" => implode(' ',$command),
                        'instance' => $deviceInstance['uuid']
                    ]);

                    $process = new Process($command);
                    $process->setTimeout(600);
                    try {
                        $process->mustRun();
                    }   catch (ProcessFailedException $exception) {
                        $this->logger->error("Copying QEMU image file error ! ", InstanceLogMessage::SCOPE_PRIVATE, ['instance' => $deviceInstance['uuid'], 'error' => $exception->getMessage()]);
                    }

                    $command = [
                        'qemu-img',
                        'rebase',
                        '-f','qcow2','-F','qcow2',
                        '-b',
                        $newImagePath,
                        $copyInstancePath
                    ];

                    $this->logger->debug("Rebasing backing and base image", InstanceLogMessage::SCOPE_PRIVATE, [
                        "command" => implode(' ',$command),
                        'instance' => $deviceInstance['uuid']

                    ]);

                    $process = new Process($command);
                    $process->setTimeout(600);
                    try {
                        $process->mustRun();
                    }   catch (ProcessFailedException $exception) {
                        $this->logger->error("Export: Rebase QEMU process error ! ", InstanceLogMessage::SCOPE_PRIVATE, ['instance' => $deviceInstance['uuid'], 'error' => $exception->getMessage()]);
                    }

                    $command = [
                        'qemu-img',
                        'commit',
                        $copyInstancePath
                    ];

                    $this->logger->debug("Commit change on image.", InstanceLogMessage::SCOPE_PRIVATE, [
                        "command" => implode(' ',$command)
                    ]);

                    $process = new Process($command);
                    $process->setTimeout(600);
                    try {
                        $process->mustRun();
                    }   catch (ProcessFailedException $exception) {
                        $this->logger->error("Export: QEMU commit error ! ", InstanceLogMessage::SCOPE_PRIVATE, ['instance' => $deviceInstance['uuid'], 'error' => $exception->getMessage()]);
                    }

                    $this->qemu_delete($deviceInstance['uuid'],$copyInstancePath);
                
                $this->logger->info("Image exported successfully!",InstanceLogMessage::SCOPE_PUBLIC,[
                    'instance' => $deviceInstance['uuid']
                ]);
                $result=array(
                    "state" => InstanceStateMessage::STATE_EXPORTED,
                    "uuid" => $deviceInstance['uuid'],
                    "options" => array(
                    "newOS_id" => $labInstance["newOS_id"],
                    "newDevice_id" => $labInstance["newDevice_id"],
                    "new_os_name" => $labInstance["new_os_name"],
                    "new_os_imagename" => $labInstance["new_os_imagename"],
                    "state" => InstanceActionMessage::ACTION_EXPORT
                    )
                );
            }
            else {
                $result=array(
                    "state" => InstanceStateMessage::STATE_ERROR,
                    "uuid" => $deviceInstance['uuid'],
                    "options" => array(
                    "newOS_id" => $labInstance["newOS_id"],
                    "newDevice_id" => $labInstance["newDevice_id"],
                    "new_os_name" => $labInstance["new_os_name"],
                    "new_os_imagename" => $labInstance["new_os_imagename"],
                    "state" => InstanceActionMessage::ACTION_EXPORT
                    )
                );

            }
        }
        elseif ($hypervisor === "lxc") {
            $this->logger->debug("LXC device for export",InstanceLogMessage::SCOPE_PRIVATE,[
                'instance' => $deviceInstance['uuid']
                ]);
            if (!$this->lxc_exist($deviceInstance['uuid'])) {
                $this->logger->info("You have to start at least one time the device !",InstanceLogMessage::SCOPE_PUBLIC,[
                    'instance' => $deviceInstance['uuid']
                    ]);
                    
                // LXC container
                $result=array(
                    "state" => InstanceStateMessage::STATE_ERROR,
                    "uuid" => $deviceInstance['uuid'],
                    "options" => array(
                    "newOS_id" => $labInstance["newOS_id"],
                    "newDevice_id" => $labInstance["newDevice_id"],
                    "new_os_name" => $labInstance["new_os_name"],
                    "new_os_imagename" => $labInstance["new_os_imagename"],
                    "state" => InstanceActionMessage::ACTION_EXPORT
                    )
                );
            }
            else {
                
                if (!$this->lxc_clone($deviceInstance['uuid'],basename($imagefilename,".img"))) {
                    $this->logger->info("New device created successfully",InstanceLogMessage::SCOPE_PUBLIC,[
                        'instance' => $deviceInstance['uuid']
                    ]);
                    $this->lxc_delete($deviceInstance['uuid']);
                    $result=array(
                        "state" => InstanceStateMessage::STATE_EXPORTED,
                        "uuid" => $deviceInstance['uuid'],
                        "options" => array(
                        "newOS_id" => $labInstance["newOS_id"],
                        "newDevice_id" => $labInstance["newDevice_id"],
                        "new_os_name" => $labInstance["new_os_name"],
                        "new_os_imagename" => $labInstance["new_os_imagename"],
                        "state" => InstanceActionMessage::ACTION_EXPORT
                        )
                    );
                } else {
                    $this->logger->info("Error in LXC clone process",InstanceLogMessage::SCOPE_PUBLIC,[
                        'instance' => $deviceInstance['uuid']
                    ]);
                    $result=array(
                        "state" => InstanceStateMessage::STATE_ERROR,
                        "uuid" => $deviceInstance['uuid'],
                        "options" => array(
                        "newOS_id" => $labInstance["newOS_id"],
                        "newDevice_id" => $labInstance["newDevice_id"],
                        "new_os_name" => $labInstance["new_os_name"],
                        "new_os_imagename" => $labInstance["new_os_imagename"],
                        "state" => InstanceActionMessage::ACTION_EXPORT)
                    );
                }
            }
        }
        else {
            $this->logger->info("You have to start at least one time the device !",InstanceLogMessage::SCOPE_PUBLIC,[
                'instance' => $deviceInstance['uuid']
                ]);
                
            // LXC container
            $result=array(
                "state" => InstanceStateMessage::STATE_ERROR,
                "uuid" => $deviceInstance['uuid'],
                "options" => array(
                "newOS_id" => $labInstance["newOS_id"],
                "newDevice_id" => $labInstance["newDevice_id"],
                "new_os_name" => $labInstance["new_os_name"],
                "new_os_imagename" => $labInstance["new_os_imagename"],
                "state" => InstanceActionMessage::ACTION_EXPORT)
            );
        }
        return $result;
    }

    /**
     * Delete an image on the filesystem
     *
     * @param string $uuid uuid of the instance
     * @param string $file file in absolute path to delete
     * @throws ProcessFailedException When a process failed to run.
     * @return void
     */
    public function qemu_delete($uuid,$file){
    $command = [
        'rm',
        $file
    ];
    $this->logger->debug("Delete image.", InstanceLogMessage::SCOPE_PRIVATE, [
        "command" => implode(' ',$command)
    ]);

    $process = new Process($command);
    try {
        $process->mustRun();
    }   catch (ProcessFailedException $exception) {
        if (str_contains($exception->getMessage(),"No such file or directory")) {
            $this->logger->debug("QEMU image is already deleted", InstanceLogMessage::SCOPE_PRIVATE,[ 'instance' => $uuid]);
        }
        else {
            $this->logger->error("QEMU delete image in error", InstanceLogMessage::SCOPE_PRIVATE,[
                'instance' => $uuid,
                'error' => $exception->getMessage()
            ]);
        }
    }
}


    /**
     * Delete an instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to delete.
     * @throws BadDescriptorException When a process failed to run.
     * @return void
     */
    public function deleteDeviceInstance(string $descriptor, string $uuid) {
        $this->logger->setUuid($uuid);
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $this->logger->debug("JSON received in deleteDeviceInstance", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

            throw new BadDescriptorException($labInstance);
        }

        //Delete on filesystem : $labInstance[5]

    }

    /**
     * Delete the file or the container of an OS
     * @return Array("state","uuid","options"=array())
     */
    public function deleteOS(string $descriptor, int $id){
        $operatingSystem = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $this->logger->debug("JSON received in deleteOS", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $operatingSystem]);
        
        switch($operatingSystem["hypervisor"]["name"]){
            case "qemu":
                $this->qemu_delete($operatingSystem["uuid"],$this->kernel->getProjectDir()."/images/".$operatingSystem["imageFilename"]);
                break;
            case "lxc":
                $this->lxc_delete($operatingSystem["imageFilename"]);
                break;
        }
        //No uuid because we have no instance in this function
        return array("uuid"=>"","state"=>InstanceStateMessage::STATE_DELETED ,"options"=> null);

    }

    /**
     * Delete the file or the container of an OS
     * @return Array("state","uuid","options"=array())
     */
    public function renameOS(string $descriptor, int $id){
        $error=false;
        $name_received = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $this->logger->debug("JSON received in renameOS", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $name_received]);
        
        $source=$name_received['old_name'];
        $destination=$name_received['new_name'];
        $hypervisor=$name_received['hypervisor'];

        $this->logger->debug("new name", InstanceLogMessage::SCOPE_PRIVATE, ["hypervisor" => $hypervisor ,
    "source" => $source, "new_name" => $destination]);

        switch($hypervisor){
            case "qemu":
                $this->logger->debug("Rename image qemu from ".$this->kernel->getProjectDir()."/images/".$source." to ".$this->kernel->getProjectDir()."/images/".$destination, InstanceLogMessage::SCOPE_PRIVATE, []);
                $this->qemu_rename($this->kernel->getProjectDir()."/images/".$source,$this->kernel->getProjectDir()."/images/".$destination);
                break;
            case "lxc":
                if (!$this->lxc_clone($source,$destination))
                    $this->lxc_destroy($source);
                else
                    $error=true;
                break;
        }
        if (!$error)
            //No uuid because we have no instance in this function
            return array("uuid"=>$id,"state"=>InstanceStateMessage::STATE_RENAMED ,"options"=> null);
        else
            return array("uuid"=>$id,"state"=>InstanceStateMessage::STATE_ERROR ,
            "options"=> [
                "state" =>InstanceActionMessage::ACTION_RENAMEOS,
                "old_name" => $source,
                "new_name" => $destination]
        );
    }

    /**
     * Function to destroy a LXC container
     * @param string $src_lxc_name Name of the LXC contianer to clone.
     * @return $error: true if error or false if no error
     */
    public function lxc_destroy(string $src_lxc_name){
        $error=null;
        $command = [
            'lxc-destroy',
            '-n',
            "$src_lxc_name"
        ];
        $this->logger->info("Destroy LXC container in progress", InstanceLogMessage::SCOPE_PUBLIC, [
            'instance' => $src_lxc_name]
        );
        $this->logger->debug("Destroying LXC container.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);

        $process = new Process($command);
        try {
            $process->mustRun();
            $error=false;
        }   catch (ProcessFailedException $exception) {
            $error=true;
            $this->logger->error("LXC container destroy is in error ! ", InstanceLogMessage::SCOPE_PUBLIC, [
                'error' => $exception->getMessage(),
                'instance' => $src_lxc_name
            ]);
        }
        if (!$error)
            $this->logger->info("LXC container destroyed successfully", InstanceLogMessage::SCOPE_PUBLIC, [
                'instance' => $src_lxc_name]);

        return $error;
    }

    /**
     * Copy an image on the filesystem
     *
     * @param string $file file in absolute path to delete
     * @throws ProcessFailedException When a process failed to run.
     * @return void
     */
    public function qemu_rename($source,$destination){
        $command = [
            'mv',
            $source,
            $destination
        ];
        $this->logger->debug("Rename image.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);
    
        $process = new Process($command);
        try {
            $process->mustRun();
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("Export: QEMU rename image file ! ", InstanceLogMessage::SCOPE_PRIVATE,[
                'error' => $exception->getMessage(),
                'instance' => $source]);
        }
    }

    public function macgen(){
        $adress = '00';
        for ($i=0;$i<5;$i++) {
            $oct = strtoupper(dechex(mt_rand(0,255)));
            strlen($oct)<2 ? $adress .= ":0$oct" : $adress .= ":$oct"; 
        }
        return $adress;
    }

    private function get_filesize_download($source) {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $url=$source;
            $context=null;
        }
        else {
            //The source uploaded on the front. 
            $url="http://".$this->front_ip."/uploads/images/".basename($source);
            // Only to download from the front when self-signed certificate
            $context = stream_context_create( [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);
        }
        $headers = get_headers($source, 1,$context);
        $this->logger->debug('Headers of the web page to download the image : ', InstanceLogMessage::SCOPE_PRIVATE, [
            "headers" => $headers
        ]);
        $headers = array_change_key_case($headers);
        $fileSize = 0.0;
        if(isset($headers['content-length'])){
            // On 302 request, with http to https, content-length is an array !!
            // In that case, we take the second value
            if (is_array($headers['content-length']))
                $fileSize = (float) $headers['content-length'][1];
            else 
                $fileSize = (float) $headers['content-length'];
        }
        return $fileSize;
    }


    // $source : image source to download
    // $uuid : $uuid of the device that need this image
    private function download_http_image($source,$uuid){
        $fileSize=$this->get_filesize_download($source);
       
        $this->logger->info('The image size to download is '.round($fileSize*1e-6, 2).'MB.', InstanceLogMessage::SCOPE_PUBLIC, [
            "image" => $source,
            'instance' => $uuid
        ]);
        $image_dst=$this->kernel->getProjectDir() . "/images/" . basename($source);

        $command = [
            'curl','-s',
            $source,
            '--output',
            $image_dst
        ];

        $process = new Process($command);
        $process->setTimeout(12000);
        try {
            $process->mustRun();
            $fileSize_downloaded=filesize($image_dst);
            $this->logger->info('Image download in progress: '.$process->getOutput(), InstanceLogMessage::SCOPE_PUBLIC, [
                "image" => $source,
                'instance' => $uuid,
                'size_downloaded' => $fileSize_downloaded,
                'size_origin' => $fileSize
            ]);
        }
        catch (ProcessFailedException $exception) {
            $this->logger->error('Image download in error.', InstanceLogMessage::SCOPE_PUBLIC, [
                "image" => $source,
                'instance' => $uuid,
                'size_downloaded' => $exception->getMessage(),
                'size_origin' => $fileSize
            ]);
            return false;
        }
      

        return true;
    }

    // $source : image source to download
    // $uuid : $uuid of the device that need this image
    private function download_http_image_old_version($source,$uuid,$context){
    // check image size
    $this->logger->debug('download process : ', InstanceLogMessage::SCOPE_PRIVATE, [
        "image" => $source,
        'instance' => $uuid
    ]);
    $headers = get_headers($source, 1,$context);
    $this->logger->debug('headers in download process : ', InstanceLogMessage::SCOPE_PRIVATE, [
        "headers" => $headers
    ]);
    $headers = array_change_key_case($headers);
    $fileSize = 0.0;
    if(isset($headers['content-length'])){
        // On 302 request, with http to https, content-length is an array !!
        // In that case, we take the second value
        if (is_array($headers['content-length']))
            $fileSize = (float) $headers['content-length'][1];
        else 
            $fileSize = (float) $headers['content-length'];
    }
    
    $this->logger->info('Image size is '.round($fileSize*1e-6, 2).'MB.', InstanceLogMessage::SCOPE_PUBLIC, [
        "image" => $source,
        'instance' => $uuid
    ]);
    
    $chunkSize = 1024 * 1024;
    $fd = fopen($source, 'rb',false, $context);
    $downloaded = 0.0;
    $lastNotification = 0.0;

    while (!feof($fd)) {
        $buffer = fread($fd, $chunkSize);
        $image_dst=$this->kernel->getProjectDir() . "/images/" . basename($source);
        file_put_contents($image_dst, $buffer, FILE_APPEND);
        if (ob_get_level() > 0)
            ob_flush();
        flush();
        clearstatcache();
        $downloaded = (float) filesize($this->kernel->getProjectDir() . "/images/" . basename($source));
        $downloadedPercent = floor(($downloaded/$fileSize) * 100.0);
        if ($downloadedPercent - $lastNotification >= 5.0) {
            $this->logger->info('Downloading image... '.$downloadedPercent.'%', InstanceLogMessage::SCOPE_PUBLIC, [
                "image" => $source,
                'instance' => $uuid
                ]);
            $lastNotification = $downloadedPercent;
        }
    }
    if ($downloaded === $fileSize) {
        $this->logger->info('Image download complete.', InstanceLogMessage::SCOPE_PUBLIC, [
                "image" => $source,
                'instance' => $uuid,
                'size_downloaded' => $downloaded,
                'size_origin' => $fileSize
                
        ]);
    
        $result=true;
    }
    else {
        $this->logger->error('Image download in error.', InstanceLogMessage::SCOPE_PUBLIC, [
            "image" => $source,
            'instance' => $uuid,
            'size_downloaded' => $downloaded,
            'size_origin' => $fileSize
            
        ]);
        $result=false;
    }
        fclose($fd);
    return $result;
    }

    //$deviceInstance : array
    // $result : port number is vnc is true otherwise return false
    private function isVNC($deviceInstance) {
        $result=false;
        foreach ($deviceInstance['controlProtocolTypeInstances'] as $control_protocol) {
            if (strtolower($control_protocol['controlProtocolType']['name'])==="vnc") {
                $result=$control_protocol['port'];
            }
        }
        return $result;
    }

    //$deviceInstance : array
    // $result : port number is vnc is true otherwise return false
    private function isSerial($deviceInstance) {
        $result=false;
        foreach ($deviceInstance['controlProtocolTypeInstances'] as $control_protocol) {
            if (strtolower($control_protocol['controlProtocolType']['name'])==="serial") {
                $result=$control_protocol['port'];
            }
        }
        return $result;
    }
    
    //$deviceInstance : array
    // $result : port number is vnc is true otherwise return false
    private function isLogin($deviceInstance) {
        $result=false;
        foreach ($deviceInstance['controlProtocolTypeInstances'] as $control_protocol) {
            if (strtolower($control_protocol['controlProtocolType']['name'])==="login") {
                $result=$control_protocol['port'];
            }
        }
        return $result;
    }

    private function getFreePort()
    {
        $process = new Process([ $this->kernel->getProjectDir().'/scripts/get-available-port.sh' ]);
        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            return false;
        }
        
        return (int) $process->getOutput();
    }

    private function stop_device_qemu($uuid,$deviceInstance,$labInstance) {
        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

            try {
                $labUser = $labInstance['owner']['uuid'];
                $ownedBy = $labInstance['ownedBy'];
                $labInstanceUuid = $labInstance['uuid'];
            } catch (ErrorException $e) {
                throw new BadDescriptorException($labInstance, "", 0, $e);
            }
            if ($this->qemu_stop($uuid)) {
                $this->logger->info("QEMU VM stopped successfully", InstanceLogMessage::SCOPE_PUBLIC, [
                    'instance' => $uuid]);
                $result=array(
                        "state" => InstanceStateMessage::STATE_STOPPED,
                        "uuid" => $uuid,
                        "options" => null
                    );
                }
            else {
                $this->logger->info("QEMU VM doesn't stop - Error !", InstanceLogMessage::SCOPE_PUBLIC, [
                    'instance' => $uuid]);
                $result=array(
                    "state" => InstanceStateMessage::STATE_ERROR,
                    "uuid" => $uuid,
                    "options" => null
                );
                
                }

                if ($vncPort=$this->isVNC($deviceInstance)) {
                    $vncAddress = "0.0.0.0";

                $process = Process::fromShellCommandline("ps aux | grep " . $vncAddress . ":" . $vncPort . " | grep websockify | grep -v grep | awk '{print $2}'");
                $error=false;
                try {
                    $process->mustRun();
                }   catch (ProcessFailedException $exception) {
                    $this->logger->error("Process listing error to find vnc error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE,
                    ['instance' => $uuid
                    ]);
                    $error=true;
                }
                if (!$error)
                    $pidWebsockify = $process->getOutput();
                $error=false;

                if (!empty($pidWebsockify)) {
                    $pidWebsockify = explode("\n", $pidWebsockify);

                    foreach ($pidWebsockify as $pid) {
                        if (!empty($pid)) {
                            $pid = str_replace("\n", '', $pid);
                            $process = new Process(['kill', '-9', $pid]);
                            try {
                                $process->mustRun();
                            }   catch (ProcessFailedException $exception) {
                                $this->logger->error("Killing websockify error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE,
                                ['instance' => $uuid
                                ]);
                            }
                            $this->logger->debug("Killing websockify process", InstanceLogMessage::SCOPE_PRIVATE, [
                                "PID" => $pid
                            ]);
                        }
                    }
                }
            }
            // Network interfaces

            foreach($deviceInstance['networkInterfaceInstances'] as $networkInterfaceInstance) {
                $networkInterface = $networkInterfaceInstance['networkInterface'];
                $networkInterfaceName = substr(str_replace(' ', '_', $networkInterface['name']), 0, 6) . '-' . substr($networkInterfaceInstance['uuid'], 0, 8);

                if (OVS::ovsPortExists($bridgeName, $networkInterfaceName)) {
                    OVS::portDelete($bridgeName, $networkInterfaceName, true);
                }

                if (IPTools::networkInterfaceExists($networkInterfaceName)) {
                    IPTools::linkSet($networkInterfaceName, IPTools::LINK_SET_DOWN);
                    $this->logger->debug("Interface ".$networkInterfaceName." set down");
                    IPTools::linkDelete($networkInterfaceName);
                    $this->logger->debug("Interface ".$networkInterfaceName." deleted");
                }
            }
        return $result;
    }

    private function stop_device_lxc($uuid,$deviceInstance,$labInstance) {
            $this->logger->debug("Device instance stopping LXC", InstanceLogMessage::SCOPE_PRIVATE, [
                'labInstance' => $labInstance
            ]);
            $result=$this->lxc_stop($uuid);
            if ($result['state']===InstanceStateMessage::STATE_STOPPED)
                $this->logger->info("LXC container stopped successfully!", InstanceLogMessage::SCOPE_PUBLIC, [
                        'instance' => $deviceInstance['uuid']]);
            else {
                $this->logger->error("LXC container stopped with error!", InstanceLogMessage::SCOPE_PUBLIC, [
                    'instance' => $deviceInstance['uuid']]);
            }

            if ($vncPort=$this->isLogin($deviceInstance)) {
                $vncAddress = "0.0.0.0";
                $cmd="ps aux | grep -i screen | grep ".$deviceInstance['uuid']." | grep -v grep | awk '{print $2}'";
                $this->logger->debug("Find process ttyd command:".$cmd, InstanceLogMessage::SCOPE_PRIVATE, [
                    'deviceInstance' => $deviceInstance
                ]);
                $this->logger->info("Find process ttyd command:".$cmd, InstanceLogMessage::SCOPE_PRIVATE, [
                    'instance' => $deviceInstance["uuid"]
                ]);
                $process = Process::fromShellCommandline($cmd);
                $error=false;
                try {
                    $process->mustRun();
                }   catch (ProcessFailedException $exception) {
                    $this->logger->error("Process listing error to find Login connexion error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE,
                        ['instance' => $deviceInstance['uuid']
                    ]);
                    $error=true;
                }
                if (!$error)
                    $pidscreen = $process->getOutput();
                $error=false;

                if (!empty($pidscreen)) {
                    $pidscreen = explode("\n", $pidscreen);

                    foreach ($pidscreen as $pid) {
                        if (!empty($pid)) {
                            $pid = str_replace("\n", '', $pid);
                            $process = new Process(['kill', '-9', $pid]);
                            try {
                                $process->mustRun();
                            }   catch (ProcessFailedException $exception) {
                                $this->logger->error("Killing screen error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE, ['instance' => $deviceInstance['uuid']]);
                                $result=array("state" => InstanceStateMessage::STATE_ERROR,
                                    "uuid"=>$deviceInstance['uuid'],
                                    "options" => null);
                            }
                            $this->logger->debug("Killing screen process", InstanceLogMessage::SCOPE_PRIVATE, [
                                "PID" => $pid
                            ]);
                        }
                    }
                }
            }
        return $result;
    }
}