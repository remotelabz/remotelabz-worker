<?php

namespace App\MessageHandler;

use App\Service\Instance\InstanceManager;
use Psr\Log\LoggerInterface;
use Remotelabz\Message\Message\SecurityMessage;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class SecurityMessageHandler implements MessageHandlerInterface
{
    private InstanceManager $instanceManager;
    private LoggerInterface $logger;

    public function __construct(
        InstanceManager $instanceManager,
        LoggerInterface $logger
    ) {
        $this->instanceManager = $instanceManager;
        $this->logger = $logger;
    }

    public function __invoke(SecurityMessage $message)
    {
        $payload = json_decode($message->getContent(), true);
        if (!is_array($payload)) {
            $this->logger->error("[SecurityMessageHandler:__invoke]::Invalid JSON was provided!", [
                'group' => $message->getGroup()
            ]);
            return;
        }

        $group = $message->getGroup() !== '' ? $message->getGroup() : (string) ($payload['group'] ?? '');
        $links = isset($payload['links']) && is_array($payload['links']) ? $payload['links'] : [];

        $this->logger->debug("[SecurityMessageHandler:__invoke]::Security topology received.", [
            'group' => $group,
            'links' => count($links)
        ]);

        try {
            $this->instanceManager->updateSharedSecurity($group, $links);
        } catch (\Exception $e) {
            $this->logger->critical("[SecurityMessageHandler:__invoke]::Unable to apply the sharing topology.", [
                'group' => $group,
                'exception' => $e,
                'message' => $e->getMessage()
            ]);
        }
    }
}
