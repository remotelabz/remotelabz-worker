<?php

namespace App\Service\Instance;

use Psr\Log\LoggerInterface;
use Remotelabz\Message\Message\InstanceLogMessage;
use Symfony\Component\Messenger\MessageBusInterface;

class LogDispatcher
{
    protected $bus;
    protected $uuid;
    protected $logger;

    public function __construct(
        LoggerInterface $logger,
        MessageBusInterface $bus
    ) {
        $this->bus = $bus;
        $this->logger = $logger;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function emergency($message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->emergency($message, $context);
    }

    public function alert($message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->alert($message, $context);
    }

    public function critical($message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->critical($message, $context);
    }

    public function error($message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->error($message, $context);
        $this->setUuid($context['instance']);
        $this->bus->dispatch(
            new InstanceLogMessage($message, $this->uuid, InstanceLogMessage::TYPE_ERROR, $scope)
        );
    }

    public function warning($message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->warning($message, $context);
        $this->setUuid($context['instance']);
        $this->bus->dispatch(
            new InstanceLogMessage($message, $this->uuid, InstanceLogMessage::TYPE_WARNING, $scope)
        );
    }

    public function notice($message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->notice($message, $context);
    }

    public function info($message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->info($message, $context);
        //if (array_key_exists("instance",$context)) { // In case of CopyOS2Worker, for example, this message is not link to an instance
            $this->setUuid($context['instance']);
            $this->bus->dispatch(
                new InstanceLogMessage($message, $this->uuid, InstanceLogMessage::TYPE_INFO, $scope)
            );
        //}
    }

    public function debug($message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->debug($message, $context);
    }

    public function log($level, $message, $scope = InstanceLogMessage::SCOPE_PRIVATE, array $context = array()) {
        $this->logger->log($level, $message, $context);
        $this->setUuid($context['instance']);
        $this->bus->dispatch(
            new InstanceLogMessage($message, $this->uuid, $level, $scope)
        );
    }
    
}
