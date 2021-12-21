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
    /** @var LoggerInterface $logger */
    private $logger;
    private $bus;

    public function __construct(
        InstanceManager $instanceManager,
        MessageBusInterface $bus
    ) {
        $this->instanceManager = $instanceManager;
        $this->bus = $bus;
    }

    public function __invoke(InstanceActionMessage $message)
    {

       // The following generate an error on json param
       // $this->logger->debug("Received \"".$message->getAction()."\" action message for instance with UUID ".$message->getUuid().".", json_decode($message->getContent(), true));

        $returnState = "";
        $instanceType = "";
        $exportDeviceReturnArray = null;

        try {
            switch ($message->getAction()) {
                case InstanceActionMessage::ACTION_CREATE:
                    $instanceType = InstanceStateMessage::TYPE_LAB;
                    $this->instanceManager->createLabInstance($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_CREATED;
                    break;

                case InstanceActionMessage::ACTION_DELETE:
                    $instanceType = InstanceStateMessage::TYPE_LAB;
                    $this->instanceManager->deleteLabInstance($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_DELETED;
                    break;

                case InstanceActionMessage::ACTION_START:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    if (!$this->instanceManager->startDeviceInstance($message->getContent(), $message->getUuid()))
                        $returnState = InstanceStateMessage::STATE_STARTED;
                    else $returnState = InstanceStateMessage::STATE_ERROR;
                    break;

                case InstanceActionMessage::ACTION_STOP:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    $this->instanceManager->stopDeviceInstance($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_STOPPED;
                    break;
                
                case InstanceActionMessage::ACTION_CONNECT:
                    $this->instanceManager->connectToInternet($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_STARTED;
                    break;

                case InstanceActionMessage::ACTION_EXPORT:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    $exportDeviceReturnArray= $this->instanceManager->exportDeviceInstance($message->getContent(), $message->getUuid());
                    $returnState = $exportDeviceReturnArray["state"];
                    break;
                
                //When an error is generated and we want to delete on filesystem the file created
                case InstanceActionMessage::ACTION_DELETEDEV:
                    $instanceType = InstanceStateMessage::TYPE_DEVICE;
                    $this->instanceManager->deleteDeviceInstance($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_ERROR;
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
        } catch (Exception $e) {
            $this->logger->critical(
                "Action \"" . $message->getAction() . "\" throwed an exception.", [
                    "exception" => $e,
                    "message" => $e->getMessage(),
                    "instance" => $message->getUuid()
                ]);
            $returnState = InstanceStateMessage::STATE_ERROR;
        }

        // send back state
        $this->logger->info("State " . $returnState . " send back to the front", [
            "uuid" => $message->getUuid()
        ]);
        $return_array="";
            if (($message->getAction() === InstanceActionMessage::ACTION_EXPORT) && ($returnState === InstanceStateMessage::STATE_ERROR)) {
                $this->logger->debug("export and error");
                $return_array=$exportDeviceReturnArray["uuid"];
            }
            else {
                $this->logger->debug("no export or no error");
                $return_array=$message->getUuid();
            }


            $this->logger->debug("value of return array before InstanceStateMessage :".json_encode($return_array));

        $this->bus->dispatch(
            new InstanceStateMessage($instanceType, $return_array,$returnState,$exportDeviceReturnArray)
        );
    }

    public function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }
}
