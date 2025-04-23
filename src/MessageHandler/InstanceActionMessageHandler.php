<?php

namespace App\MessageHandler;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use App\Service\Instance\InstanceManager;
use Remotelabz\Message\Message\InstanceStateMessage;
use Remotelabz\Message\Message\InstanceActionMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class InstanceActionMessageHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    protected $instanceManager;
    private $logger;
    private $bus;

    public function __construct(
        InstanceManager $instanceManager,
        MessageBusInterface $bus,
        LoggerInterface $logger
    ) {
        $this->instanceManager = $instanceManager;
        $this->bus = $bus;
        $this->logger = $logger;
    }

    public function __invoke(InstanceActionMessage $message)
    {

       // The following generate an error on json param
       $message_array=json_decode($message->getContent(), true);
       //$this->logger->debug("Received \"".$message->getAction()."\" action message for instance with UUID ".$message->getUuid().".",$message_array);

        $returnState = "";
        $instanceType = "";
        $ReturnArray = null;

        try {
            switch ($message->getAction()) {
                case InstanceActionMessage::ACTION_CREATE:
                    $instanceType = InstanceStateMessage::TYPE_LAB;
                    $ReturnArray=$this->instanceManager->createLabInstance($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_CREATED;
                    break;

                case InstanceActionMessage::ACTION_DELETE:
                    $instanceType = InstanceStateMessage::TYPE_LAB;
                    //Add here a foreach to stop all instance device in the lab
                    $this->logger->debug("Delete action message received");  
                    $ReturnArray=$this->instanceManager->deleteLabInstance($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_DELETED;
                    break;

                case InstanceActionMessage::ACTION_START:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    if ($message_array["lab"]["virtuality"] == 1) {
                        if (strstr($message_array["lab"]["name"],"Sandbox_"))
                        {
                            $this->logger->debug("Start device from Sandbox detected");  
                            $from_sandbox=true;
                        }
                        else
                        {
                            $this->logger->debug("Start device from a classical lab");  
                            $from_sandbox=false;
                        }
                        $ReturnArray=$this->instanceManager->startDeviceInstance($message->getContent(), $message->getUuid(),$from_sandbox);
                    }
                    else {
                        $ReturnArray=$this->instanceManager->startRealDeviceInstance($message->getContent(), $message->getUuid());
                    }
                    
                    $returnState = $ReturnArray["state"];
                    //ReturnArray has a state in $ReturnArray['state']
                    break;

                case InstanceActionMessage::ACTION_STOP:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    $ReturnArray=$this->instanceManager->stopDeviceInstance($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_STOPPED;
                    break;

                case InstanceActionMessage::ACTION_RESET:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    $ReturnArray=$this->instanceManager->resetDeviceInstance($message->getContent(), $message->getUuid());
                    $returnState = $ReturnArray["state"];
                    break;
                
                case InstanceActionMessage::ACTION_CONNECT:
                    $ReturnArray=$this->instanceManager->connectToInternet($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_STARTED;
                    break;

                case InstanceActionMessage::ACTION_EXPORT_DEV:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    $ReturnArray= $this->instanceManager->exportDeviceInstance($message->getContent(), $message->getUuid());
                    $returnState = $ReturnArray["state"];
                    break;

                case InstanceActionMessage::ACTION_EXPORT_LAB:
                    $instanceType = InstanceStateMessage::TYPE_LAB;
                    $ReturnArray= $this->instanceManager->exportLabInstance($message->getContent(), $message->getUuid());
                    $returnState = $ReturnArray["state"];
                    break;

                case InstanceActionMessage::ACTION_DELETEDEV:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    $ReturnArray=$this->instanceManager->deleteDeviceInstance($message->getContent(), $message->getUuid());
                    $returnState = $ReturnArray['state'];
                    break;
                
                case InstanceActionMessage::ACTION_DELETEOS:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    $ReturnArray=$this->instanceManager->deleteOS($message->getContent());
                    $returnState = InstanceStateMessage::STATE_OS_DELETED;
                    break;
                
                case InstanceActionMessage::ACTION_RENAMEOS:
                        $instanceType = InstanceStateMessage::TYPE_DEVICE;
                        $ReturnArray=$this->instanceManager->renameOS($message->getContent(),$message->getUuid());
                        $returnState = $ReturnArray['state'];
                        break;

                case InstanceActionMessage::ACTION_COPY2WORKER_DEV:
                        $instanceType = InstanceStateMessage::TYPE_DEVICE;
                        $ReturnArray=$this->instanceManager->copy2worker($message->getContent());
                        $returnState = $ReturnArray['state'];
                        break;
            }
            
        } catch (ProcessFailedException $e) {
            $this->logger->critical(
                "Action \"" . $message->getAction() . "\" throwed an exception while executing a process.", [
                    "output" => $e->getProcess()->getErrorOutput(),
                    "process" => $e->getProcess()->getCommandLine(),
                    "instance" => $message->getUuid()
                ]);
            $returnState = InstanceStateMessage::STATE_ERROR;
            $ReturnArray=array("uuid"=>$message->getUuid(),"state"=>$returnState,
            "options"=> [
                "state" =>$message->getAction(),
                ]);
        } catch (Exception $e) {
            $this->logger->critical(
                "Action \"" . $message->getAction() . "\" throwed an exception.", [
                    "exception" => $e,
                    "message" => $e->getMessage(),
                    "instance" => $message->getUuid()
                ]);
            $returnState = InstanceStateMessage::STATE_ERROR;
            $ReturnArray=array("uuid"=>$message->getUuid(),"state"=>$returnState ,
            "options"=> [
                "state" =>$message->getAction(),
                ]);
        }

        // send back state
        $this->logger->info("State " . $returnState . " send back to the front", [
            "uuid" => $message->getUuid()
        ]);
        
        if ((($message->getAction() === InstanceActionMessage::ACTION_EXPORT_DEV) || ($message->getAction() === InstanceActionMessage::ACTION_EXPORT_LAB)) && ($returnState === InstanceStateMessage::STATE_ERROR)) {
            $this->logger->debug("export and error");
        }
        
        $this->logger->debug("Value of the ReturnArray before InstanceStateMessage ".$returnState." ".json_encode($ReturnArray));
        $this->logger->debug("Dispatching InstanceStateMessage", [
    "state" => $returnState,
    "uuid" => $ReturnArray["uuid"],
    "type" => $instanceType,
    "options" => $ReturnArray["options"]
]);

        $instanceStateMessage = new InstanceStateMessage(
    		$returnState,
    		$ReturnArray["uuid"],
    		$instanceType,
    		$ReturnArray["options"] ?? []
	);

	$this->bus->dispatch($instanceStateMessage);

    }

    public function setLogger(LoggerInterface $logger): void {
        $this->logger = $logger;
    }
}
