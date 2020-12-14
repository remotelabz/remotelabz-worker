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
        $this->logger->debug("Received \"".$message->getAction()."\" action message for instance with UUID ".$message->getUuid().".", json_decode($message->getContent(), true)
        );

        $returnState = "";
        $instanceType = "";

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
                    $this->instanceManager->startDeviceInstance($message->getContent(), $message->getUuid());
                    $returnState = InstanceStateMessage::STATE_STARTED;
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
            }

            $this->logger->info("Action " . $message->getAction() . " executed succesfully.", [
                "uuid" => $message->getUuid()
            ]);
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
        $this->bus->dispatch(
            new InstanceStateMessage($instanceType, $message->getUuid(), $returnState)
        );
    }

    public function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }
}
