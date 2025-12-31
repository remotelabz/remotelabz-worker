<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Remotelabz\Message\Message\WorkerHandshakeMessage;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class WorkerStartedEventSubscriber implements EventSubscriberInterface
{
    private $bus;
    private $logger;
    private $worker_ip;

    public function __construct(
        MessageBusInterface $bus,
        LoggerInterface $logger,
        string $worker_ip
    ) {
        $this->bus = $bus;
        $this->logger = $logger;
        $this->worker_ip=$worker_ip;
    }
    public static function getSubscribedEvents(): array
    {
        // return the subscribed events, their methods and priorities
        return [
            WorkerStartedEvent::class => 'dispatchHandshake'
        ];
    }

    public function dispatchHandshake()
    {
        $message = new WorkerHandshakeMessage($this->worker_ip);
        $this->logger->info('Dispatching handshake message', [
            'id' => $message->getId()
        ]);

        $this->bus->dispatch(
            $message
        );
    }
}