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

    public function __construct(
        LogDispatcher $logger,
        KernelInterface $kernel,
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
            OVS::bridgeAdd($bridgeName, true);
            $this->logger->debug("Bridge doesn't exists. Creating bridge for lab instance.", InstanceLogMessage::SCOPE_PRIVATE, [
                'bridgeName' => $bridgeName,
                'instance' => $labInstance['uuid']
            ]);
        } else {
            $this->logger->debug("Bridge already exists. Skipping bridge creation for lab instance.", InstanceLogMessage::SCOPE_PRIVATE, [
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
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance);
        }
        $this->logger->debug("Lab to deleted : ", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // OVS
        OVS::bridgeDelete($bridgeName, true);

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

        $filesystem = new Filesystem();
        $filesystem->remove($instancePath);

        foreach ($labInstance["deviceInstances"] as $deviceInstance){
            if ($deviceInstance["device"]["hypervisor"]=="lxc")
                $this->delete_lxc($deviceInstance["uuid"]);
        }
        $this->logger->debug("All device deleted", InstanceLogMessage::SCOPE_PRIVATE);

    }

    /**
     * Start an instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to start.
     * @throws ProcessFailedException When a process failed to run.
     * @return true if no error, false else
     */
    public function startDeviceInstance(string $descriptor, string $uuid) {
        # TODO: send lab logs
        /** @var array $labInstance */
        $error=false;
        $this->logger->setUuid($uuid);
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
                'uuid' => $deviceInstance['uuid']
            ]);
            // instance is already started or whatever
            return;
        } else {
            $deviceIndex = array_key_first($deviceInstance);
            $deviceInstance = $deviceInstance[$deviceIndex];
        }

        try {
            $this->logger->debug("Lab instance starting", InstanceLogMessage::SCOPE_PRIVATE, [
                'labInstance' => $labInstance
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

        if ($deviceInstance['device']['hypervisor']== 'qemu') {
            
            if (filter_var($img["source"], FILTER_VALIDATE_URL)) {
                if (!$filesystem->exists($this->kernel->getProjectDir() . "/images/" . basename($img["source"]))) {
                    $this->logger->info('Remote image is not in cache. Downloading...', InstanceLogMessage::SCOPE_PUBLIC, [
                        "image" => $img['source']
                    ]);
                    // check image size
                    $headers = get_headers($img["source"], 1);
                    $headers = array_change_key_case($headers);
                    $fileSize = 0.0;
                    if(isset($headers['content-length'])){
                        $fileSize = (float) $headers['content-length'];
                    }

                    $this->logger->info('Image size is '.round($fileSize*1e-6, 2).'MB.', InstanceLogMessage::SCOPE_PUBLIC, [
                        "image" => $img['source']
                    ]);
                    $chunkSize = 1024 * 1024;
                    $fd = fopen($img["source"], 'rb');
                    $downloaded = 0.0;
                    $lastNotification = 0.0;

                    while (!feof($fd)) {
                        $buffer = fread($fd, $chunkSize);
                        file_put_contents($this->kernel->getProjectDir() . "/images/" . basename($img["source"]), $buffer, FILE_APPEND);
                        if (ob_get_level() > 0)
                            ob_flush();
                        flush();
                        clearstatcache();
                        $downloaded = (float) filesize($this->kernel->getProjectDir() . "/images/" . basename($img["source"]));
                        $downloadedPercent = floor(($downloaded/$fileSize) * 100.0);
                        if ($downloadedPercent - $lastNotification >= 5.0) {
                            $this->logger->info('Downloading image... '.$downloadedPercent.'%', InstanceLogMessage::SCOPE_PUBLIC, [
                                "image" => $img['source']
                            ]);
                            $lastNotification = $downloadedPercent;
                        }
                    }

                    $this->logger->info('Image download complete.', InstanceLogMessage::SCOPE_PUBLIC, [
                        "image" => $img['source']
                    ]);
                    fclose($fd);
                }
            }

            $img['destination'] = $instancePath . '/' . basename($img['source']);
            $img['source'] = $this->kernel->getProjectDir() . "/images/" . basename($img['source']);

            if (!$filesystem->exists($img['destination'])) {
                $this->logger->info('VM image doesn\'t exist. Creating new image from source...', InstanceLogMessage::SCOPE_PUBLIC, [
                    'source' => $img['source']
                ]);
                $process = new Process([ 'qemu-img', 'create', '-f', 'qcow2', '-b', $img['source'], $img['destination']]);
                try {
                    $process->mustRun();
                }   catch (ProcessFailedException $exception) {
                    $this->logger->error("QEMU commit error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                    $error=true;
                }
                $this->logger->info('VM image created.', InstanceLogMessage::SCOPE_PUBLIC, [
                    'path' => $img['destination']
                ]);
            }

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

            if ($deviceInstance['device']['vnc'] === true) {
                $this->logger->info("VNC access requested. Adding VNC server.", InstanceLogMessage::SCOPE_PUBLIC);
                $vncAddress = "0.0.0.0";
                $vncPort = $deviceInstance['remotePort'];

                $this->logger->debug("Starting websockify process...", InstanceLogMessage::SCOPE_PUBLIC);
                
                $command = ['websockify', '-D'];
                if ($this->getParameter('app.services.proxy.wss')) {
                    $this->logger->debug("Websocket use wss", InstanceLogMessage::SCOPE_PRIVATE);
                    array_push($command,'--cert='.$this->getParameter('app.services.proxy.cert'),'--key='.$this->getParameter('app.services.proxy.key'));
                } else
                    $this->logger->debug("Websocket without wss", InstanceLogMessage::SCOPE_PRIVATE);
                array_push($command, $vncAddress.':' . ($vncPort + 1000), $vncAddress.':'.$vncPort);
                
                $process = new Process($command);
                try {
                    $process->mustRun();
                }   catch (ProcessFailedException $exception) {
                    $this->logger->error("Websockify starting process in error !".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                    $error=true;
                }
                $command="ps aux | grep " . $vncAddress . ":" . $vncPort . " | grep websockify | grep -v grep | awk '{print $2}'";
                $this->logger->debug("List websockify:".$command, InstanceLogMessage::SCOPE_PRIVATE);

                try {
                    $pidProcess = Process::fromShellCommandline($command);
                }   catch (ProcessFailedException $exception) {
                    $this->logger->error("Listing process to find websockify process error !".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                    $error=true;
                }
                if (!$error)
                    $this->logger->debug("Websockify process started", InstanceLogMessage::SCOPE_PRIVATE);

                array_push($parameters['access'], '-vnc', $vncAddress.':'.($vncPort - 5900));
                array_push($parameters['local'], '-k', 'fr');
            }

            array_push($parameters['local'],
                '-rtc', 'base=localtime,clock=host', // For qemu 3 compatible
                '-smp', '4',
                '-vga', 'qxl'
            );
            
            if (!$this->start_qemu($parameters,$uuid)){
                $this->logger->info("Virtual Machine started successfully", InstanceLogMessage::SCOPE_PUBLIC);
                $this->logger->info("Virtual Machine can be configured on network:".$labNetwork, InstanceLogMessage::SCOPE_PUBLIC);
            }
            else {
                $this->logger->info("Virtual Machine doesn't start !", InstanceLogMessage::SCOPE_PUBLIC);
                $error=true;
            }

        }
        elseif ($deviceInstance['device']['hypervisor'] == 'lxc') {
            $this->logger->debug("Device is a LXC container", InstanceLogMessage::SCOPE_PRIVATE);
            if (!$this->exist_lxc($uuid)) {
                $this->clone_lxc("Service",$uuid);
            }
            //Return the last IP - 1 to address the LXC service container
            //$ip_addr=new IP(long2ip(ip2long($labNetwork->getIp()) + (pow(2, 32 - $labNetwork->getCidrNetmask()) - 3)));
            $ip_addr=long2ip(ip2long($labNetwork->getLastAddress())-1);
            $this->build_template($uuid,$instancePath.'/template.txt',$bridgeName,$ip_addr,$gateway);
            $first_ip=$labNetwork->getFirstAddress();
            $last_ip=long2ip(ip2long($ip_addr)-1);
            $this->add_dhcp_dnsmasq_lxc($uuid,$first_ip,$last_ip);

            if (!$this->start_lxc($uuid,$instancePath.'/template.txt-new',$bridgeName,$gateway)){
                $this->logger->info("LXC container started successfully", InstanceLogMessage::SCOPE_PUBLIC);
                $this->logger->info("LXC container is configured with IP:".$ip_addr, InstanceLogMessage::SCOPE_PUBLIC);
            }
            else 
                $error=true;
            }

        if (!$error) {
            $this->logger->info("Device started successfully", InstanceLogMessage::SCOPE_PUBLIC);
        }
        else {
            $this->stopDeviceInstance($descriptor,$uuid);
            return InstanceStateMessage::STATE_ERROR;
        }
    }

    /**
     * Configure the dnsmasq configuration file to add the network in a range
     * @param string $uuid the $uuid of the device
     * @param string $first_ip the first IP of the range
     * @param string $last_ip the last IP of the range
     */
    public function add_dhcp_dnsmasq_lxc($uuid,$first_ip,$last_ip){
        $line_to_add=$first_ip.",".$last_ip.",1h";     
        $file_path="/var/lib/lxc/".$uuid."/rootfs/etc/dnsmasq.conf";
        $source_file_path="/var/lib/lxc/Service/rootfs/etc/dnsmasq.conf";
        $command="sed \
            -e \"s/RANGE_TO_DEFINED/".$line_to_add."/g\" \
            ".$source_file_path." > ".$file_path;

        $process = Process::fromShellCommandline($command);
        $this->logger->debug("Add dhcp range to dnsmasq configuration:".$command, InstanceLogMessage::SCOPE_PRIVATE);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Dhcp adding in error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
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
     */
    public function build_template($uuid,$path,string $bridgeName,string $network_addr,string $gateway_IP) {
            $command = [
                'cp',
                $this->kernel->getProjectDir().'/scripts/template.txt',
                $path
            ];

            $this->logger->debug("Copying LXC template to instance path.", InstanceLogMessage::SCOPE_PRIVATE, [
                "command" => implode(' ',$command)
            ]);

            $process = new Process($command);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Copying LXC template error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
            }

            //sed  -e "s/XVLANY/$VLAN/g" -e "s/NAME-CONT/$NAME/g" -e "s/IP-ADM/$ADMIN_IP\/$ADMIN_MASK/g" -e "s/IP-DATA/$DATA_IP\/$DATA_MASK/g" ${SOURCE} > $FILE

            $IP=$network_addr;
            $IP_GW=$gateway_IP;
            $MASK="24";
            $INTERFACE="veth0";
            $BRIDGE_NAME=$bridgeName;
            $MAC_ADDR="00:50:50:14:12:16";
            $command="sed \
            -e \"s/NAME-CONT/".$uuid."/g\" \
            -e \"s/INTERFACE/".$INTERFACE."/g\" \
            -e \"s/BRIDGE_NAME/".$BRIDGE_NAME."/g\" \
            -e \"s/IP_GW/".$IP_GW."/g\" \
            -e \"s/IP/".$IP."\/".$MASK."/g\" \
            -e \"s/MAC_ADDR/".$MAC_ADDR."/g\" ".$path." > ".$path."-new";

            $process = Process::fromShellCommandline($command);
            $this->logger->debug("Build template with sed:".$command, InstanceLogMessage::SCOPE_PRIVATE);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("sed exec to build template error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
            }

    }
    /**
     * Test if the LXC container with name $name exists
     * @param string $name : the name of the LXC container to test
     */
    public function exist_lxc(string $name) {
        
        //$process = new Process(['lxc-ls','-f','|grep','-e',"$name",'|grep','-v','grep']);      
        //$process = new Process('lxc-ls -f | grep "$VAR1" | grep -v "$VAR2"');
        //$cmd="lxc-ls -f | grep ".$name;
        $process = Process::fromShellCommandline("lxc-ls -f");

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            $this->logger->error("Execution lxc-ls error", InstanceLogMessage::SCOPE_PRIVATE, $exception->getMessage());
        }
        //Filter each ligne because the fromShellCommandLine("lxc-ls -f |grep $name | grep -v grep") doesn't work.
        //May be because the | and a - in the $name generate an issue
        //The pipe seems not to work in an array
        $exist=false;
        foreach( explode(' ',preg_replace('/\s+/',' ',$process->getOutput())) as $chr) {
            $this->logger->debug("line:".$chr, InstanceLogMessage::SCOPE_PRIVATE);    
            if (strcmp($chr,$name) == 0){
                $exist=true || $exist;
            }
            else {
                $exist=false || $exist;
            }
        }

        $this->logger->debug("LXC container $name existence testing. Process return:".$exist, InstanceLogMessage::SCOPE_PRIVATE);
        
        if ($exist) {
            $this->logger->debug("The LXC container $name exists", InstanceLogMessage::SCOPE_PRIVATE);
        }
        else {
            $this->logger->debug("The LXC container $name doesn't exist", InstanceLogMessage::SCOPE_PRIVATE);
        }

        return $exist;
    }

    /**
     * Function to start qemu
     * TODO finish this function start_qemu
     * @param array $parameters Array of all parameters to the command qemu.
     * @param string $uuid UUID of the device instance to start.
     */
    public function start_qemu(array $parameters,string $uuid ){
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
                array_push($command, $parameter);
            }
        }

        $this->logger->debug("Starting QEMU virtual machine.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);
        $process = new Process($command);
        try {
            $process->mustRun();
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("Starting QEMU virtual machine error! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
            $error=true;
        }
        return $error;
    }

    /**
     * Function to clone a LXC container
     * @param string $src_lxc_name Name of the LXC contianer to clone.
     * @param string $dst_lxc_name Name of the new LXC container created.
     */
    public function clone_lxc(string $src_lxc_name,string $dst_lxc_name){
        $command = [
            'lxc-copy',
            '-n',
            "$src_lxc_name",
            '-N',
            "$dst_lxc_name"
        ];
        $this->logger->info("Cloning LXC container in progress", InstanceLogMessage::SCOPE_PUBLIC);
        $this->logger->debug("Cloning LXC container.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);

        $process = new Process($command);
        try {
            $process->mustRun();
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("LXC container cloned error ! ", InstanceLogMessage::SCOPE_PRIVATE, $exception->getMessage());
        }

        $this->logger->info("LXC container cloned successfully", InstanceLogMessage::SCOPE_PUBLIC);
        
    }

    /**
     * Delete a lxc container
     * @param string $uuid UUID of LXC contianer to delete.
     */
    public function delete_lxc(string $uuid){
        $command = [
            'lxc-destroy',
            '-n',
            "$uuid"
        ];

        $this->logger->debug("Deleting LXC container.", InstanceLogMessage::SCOPE_PRIVATE, [
            "command" => implode(' ',$command)
        ]);

        $process = new Process($command);
        try {
            $process->mustRun();
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("LXC container deleted error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
        }
        $this->logger->info("LXC container deleted successfully!", InstanceLogMessage::SCOPE_PUBLIC);
    }


    /**
     * Function to start lxc
     * TODO finish this function start_lxc
     * @param array $parameters Array of all parameters to the command qemu.
     * @param string $uuid UUID of the device instance to start.
     * @param string $template the absolute path to the template file of the LXC container
     */
    public function start_lxc(string $lxc_name,string $template){
        $error=false;
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
        try {
            $process->mustRun();
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("LXC container started error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
            $error=true;
        }

        return $error;
        
    }

    /**
     * Stop an instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to stop.
     * @throws ProcessFailedException When a process failed to run.
     * @return void
     */

     //May be rename the function to stopLabInstance. To verify if all devices in the lab are analyzed.
    public function stopDeviceInstance(string $descriptor, string $uuid) {
        $this->logger->setUuid($uuid);

        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);

        if (!is_array($labInstance)) {
            // invalid json
            $this->logger->error("Invalid JSON was provided!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance);
        }
        $this->logger->debug("Lab instance stopping", InstanceLogMessage::SCOPE_PRIVATE, [
            'labInstance' => $labInstance
        ]);

        try {
            $bridgeName = $labInstance['bridgeName'];
        } catch (ErrorException $e) {
            $this->logger->error("Bridge name is missing!", InstanceLogMessage::SCOPE_PRIVATE, ["instance" => $labInstance]);
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        // Network interfaces
        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] != 'stopped');
        });

        if (!count($deviceInstance)) {
            $this->logger->debug("Device instance is already stopped.", InstanceLogMessage::SCOPE_PUBLIC);
            // instance is already stopped or whatever
            return;
        } else {
            $deviceIndex = array_key_first($deviceInstance);
            $deviceInstance = $deviceInstance[$deviceIndex];
        }

        if ($deviceInstance['device']['hypervisor'] == 'qemu') {
            try {
                $labUser = $labInstance['owner']['uuid'];
                $ownedBy = $labInstance['ownedBy'];
                $labInstanceUuid = $labInstance['uuid'];
            } catch (ErrorException $e) {
                throw new BadDescriptorException($labInstance, "", 0, $e);
            }

            $process = Process::fromShellCommandline("ps aux | grep -e " . $uuid . " | grep -v grep | awk '{print $2}'");
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Process listing error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
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
                            $this->logger->error("Killing exec error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
                        }
                    }
                }
            }

            if ($deviceInstance['device']['vnc'] === true) {
                $vncAddress = "0.0.0.0";
                $vncPort = $deviceInstance['remotePort'];

                $process = Process::fromShellCommandline("ps aux | grep " . $vncAddress . ":" . $vncPort . " | grep websockify | grep -v grep | awk '{print $2}'");
                $error=false;
                try {
                    $process->mustRun();
                }   catch (ProcessFailedException $exception) {
                    $this->logger->error("Process listing error to find vnc error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
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
                                $this->logger->error("Killing websockify error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
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
        }

        // OVS
        $activeDeviceCount = count(array_filter($labInstance['deviceInstances'], function ($deviceInstance) {
            return $deviceInstance['state'] == InstanceStateMessage::STATE_STARTED;
        })) - 1;

        if ($activeDeviceCount <= 0) {
            // OVS::bridgeDelete($bridgeName, true);
        }

        if ($deviceInstance['device']['hypervisor'] == 'lxc') {
            $this->logger->debug("Device instance stopping LXC", InstanceLogMessage::SCOPE_PRIVATE, [
                'labInstance' => $labInstance
            ]);
            if (!$this->stop_lxc($uuid))
                $this->logger->info("LXC container stopped successfully!", InstanceLogMessage::SCOPE_PUBLIC);
            else
                return $deviceInstance['state'] == InstanceStateMessage::STATE_ERROR;
        }
        $this->logger->info("Device stopped successfully", InstanceLogMessage::SCOPE_PUBLIC);

        // $filesystem = new Filesystem();
        // $filesystem->remove($this->workerDir . '/instances/' . $labUser . '/' . $labInstanceUuid . '/' . $uuid);
    }
    
    /**
     * Function to start lxc
     * TODO finish this function start_lxc
     * @param array $parameters Array of all parameters to the command qemu.
     * @param string $uuid UUID of the device instance to start.
     */
    public function stop_lxc(string $lxc_name){
        $error=false;
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
        }   catch (ProcessFailedException $exception) {
            $this->logger->error("LXC container stopping error ! ".$exception, InstanceLogMessage::SCOPE_PRIVATE);
            $error=true;
        }
        return $error;
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
        $bridgeInt = $this->params->get('app.bridge.name');
        $bridgeIntGateway = $this->params->get('app.bridge.gateway');

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
        $bridgeInt = $this->params->get('app.bridge.name');

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
        $bridgeInt = $this->params->get('app.bridge.name');

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
     * @return [$state,$newOS_id,$newDev_id,$name,$imagename] Return the state of the instance : InstanceStateMessage::STATE_STARTED, InstanceStateMessage::STATE_EXPORTED or InstanceStateMessage::STATE_ERROR
     */
    public function exportDeviceInstance(string $descriptor, string $uuid) {
        $this->logger->setUuid($uuid);
        $labInstance = json_decode($descriptor, true, 4096, JSON_OBJECT_AS_ARRAY);
        $deviceInstance = array_filter($labInstance["deviceInstances"], function ($deviceInstance) use ($uuid) {
            return ($deviceInstance['uuid'] == $uuid && $deviceInstance['state'] == 'exporting');
        });

        if (!count($deviceInstance)) {
            $this->logger->info("Device instance is already started. Aborting.", InstanceLogMessage::SCOPE_PUBLIC, [
                'uuid' => $deviceInstance['uuid']
            ]);
            // instance is already started or whatever
            return [InstanceStateMessage::STATE_STARTED,$labInstance["newOS_id"],$labInstance["newDevice_id"],$labInstance["new_os_name"],$labInstance["new_os_imagename"]];
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
            $newImageName = $labInstance['new_os_name'];
        } catch (ErrorException $e) {
            throw new BadDescriptorException($labInstance, "", 0, $e);
        }

        $instancePath = $this->kernel->getProjectDir() . "/instances";
        $instancePath .= ($ownedBy === 'group') ? '/group' : '/user';
        $instancePath .= '/' . $labUser;
        $instancePath .= '/' . $labInstanceUuid;
        $instancePath .= '/' . $uuid;

        $imagePath = $this->kernel->getProjectDir() . "/images";

        $newImagePath = $imagePath . '/' . $newImageName;
        $copyInstancePath = $instancePath . '/snap-' . basename($img["source"]);

        // Test if the image instance file exists. If the device has not been started before the export, the image instance doesn't exist.
            
        $filename=$instancePath . '/' . basename($img["source"]);
        $this->logger->debug("Test if image exist $filename",InstanceLogMessage::SCOPE_PRIVATE);
            
        if (file_exists($filename)) {
            $this->logger->info("Starting export image...", InstanceLogMessage::SCOPE_PUBLIC);

            $command = [
            'cp',
            $imagePath . '/' . basename($img["source"]),
            $newImagePath
            ];

            $this->logger->debug("Copying base image.", InstanceLogMessage::SCOPE_PRIVATE, [
                "command" => implode(' ',$command)
            ]);

            $process = new Process($command);
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
                "command" => implode(' ',$command)
            ]);

            $process = new Process($command);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Copying QEMU image file error ! ", InstanceLogMessage::SCOPE_PRIVATE, $exception->getMessage());
            }

            $command = [
                'qemu-img',
                'rebase',
                '-b',
                $newImagePath,
                $copyInstancePath
            ];

            $this->logger->debug("Rebasing backing and base image", InstanceLogMessage::SCOPE_PRIVATE, [
                "command" => implode(' ',$command)
            ]);

            $process = new Process($command);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Export: Rebase QEMU process error ! ", InstanceLogMessage::SCOPE_PRIVATE, $exception->getMessage());
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
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Export: QEMU commit error ! ", InstanceLogMessage::SCOPE_PRIVATE, $exception->getMessage());
            }

            $command = [
                'rm',
                $copyInstancePath
            ];
            $this->logger->debug("Delete image.", InstanceLogMessage::SCOPE_PRIVATE, [
                "command" => implode(' ',$command)
            ]);

            $process = new Process($command);
            try {
                $process->mustRun();
            }   catch (ProcessFailedException $exception) {
                $this->logger->error("Export: QEMU delete image file ! ", InstanceLogMessage::SCOPE_PRIVATE, $exception->getMessage());
            }

            $this->logger->info("Image exported successfully!",InstanceLogMessage::SCOPE_PUBLIC);
            return [InstanceStateMessage::STATE_EXPORTED,$uuid,$labInstance["newOS_id"],$labInstance["newDevice_id"],$labInstance["new_os_name"],$labInstance["new_os_imagename"]];
        }
        else {
        $this->logger->info("You have to start at least one time the device !",InstanceLogMessage::SCOPE_PUBLIC);
        // Get the uuid of the device templace create from it's name
        // Return this uuid in the state message with setUuid()
        return [InstanceStateMessage::STATE_ERROR,$deviceInstance['uuid'],$labInstance["newOS_id"],$labInstance["newDevice_id"],$labInstance["new_os_name"],$labInstance["new_os_imagename"]];
        
        }
    }

    /**
     * Delete an instance described by JSON descriptor for device instance specified by UUID.
     *
     * @param string $descriptor JSON representation of a lab instance.
     * @param string $uuid UUID of the device instance to delete.
     * @throws ProcessFailedException When a process failed to run.
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

}