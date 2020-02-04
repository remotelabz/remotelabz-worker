<?php

namespace App\MessageHandler;

use App\Message\TestMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class TestMessageHandler implements MessageHandlerInterface
{
    /** @var LoggerInterface $logger */
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function __invoke(TestMessage $message)
    {
        $this->logger->info($message->getContent());
    }
}