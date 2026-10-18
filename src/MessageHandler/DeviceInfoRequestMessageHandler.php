<?php

namespace App\MessageHandler;

use App\Service\Instance\DeviceIpResolver;
use Psr\Log\LoggerInterface;
use Remotelabz\Message\Message\DeviceInfoMessage;
use Remotelabz\Message\Message\DeviceInfoRequestMessage;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Repond a une demande d'information (IP) sur une DeviceInstance.
 *
 * La demande est auto-portante : elle contient le descripteur JSON du
 * LabInstance (groupe "worker"), identique a celui de ACTION_START. Le worker
 * ne conserve donc aucun etat : il resout l'IP a partir du systeme.
 *
 * Important : ce handler repond TOUJOURS, y compris en erreur, sinon le verrou
 * anti-spam du front expire sans que l'UI ne recoive jamais de reponse.
 */
class DeviceInfoRequestMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private DeviceIpResolver $resolver,
        private MessageBusInterface $bus,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DeviceInfoRequestMessage $message): void
    {
        $uuid = $message->getUuid();
        $requestId = $message->getRequestId();

        try {
            $labInstance = json_decode($message->getContent(), true, 4096, JSON_OBJECT_AS_ARRAY);
            if (!is_array($labInstance)) {
                throw new \InvalidArgumentException('Invalid JSON descriptor');
            }

            $deviceInstance = null;
            foreach (($labInstance['deviceInstances'] ?? []) as $candidate) {
                if (($candidate['uuid'] ?? null) === $uuid) {
                    $deviceInstance = $candidate;
                    break;
                }
            }

            if ($deviceInstance === null) {
                throw new \RuntimeException('Device instance ' . $uuid . ' not found in descriptor');
            }

            $bridgeName = (string) ($labInstance['bridgeName'] ?? '');
            $ips = $this->resolver->resolveDeviceInstanceIps($deviceInstance, $bridgeName);

            $info = [
                'ips' => $ips,
                'collectedAt' => time(),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[DeviceInfoRequestMessageHandler]::' . $e->getMessage(), [
                'instance' => $uuid,
            ]);

            // Toujours repondre, meme en erreur.
            $this->bus->dispatch(new DeviceInfoMessage($uuid, $requestId, [], $e->getMessage()));
            return;
        }

        $this->logger->debug('[DeviceInfoRequestMessageHandler]::Informations collectees', [
            'instance' => $uuid,
            'ips' => array_column($ips, 'ipv4'),
        ]);

        $this->bus->dispatch(new DeviceInfoMessage($uuid, $requestId, $info));
    }
}
