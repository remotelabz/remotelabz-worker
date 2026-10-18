<?php

namespace App\Service\Instance;

use Psr\Log\LoggerInterface;

/**
 * Client minimal du qemu-guest-agent (QGA) parlant JSON-RPC ligne a ligne sur
 * le socket Unix cree par QEMU (voir InstanceManager::create_qemu_device).
 *
 * Aucune dependance a libvirt : on ecrit une commande JSON sur le socket et on
 * lit la reponse. Le canal n'existe que si la VM a ete lancee avec les
 * arguments -chardev/-device correspondants ET si l'agent tourne dans l'invite.
 */
class QemuGuestAgentClient
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Retourne les interfaces reseau vues par l'agent.
     *
     * @return array<int, array{name:string, mac:?string, ipv4:string[], ipv6:string[]}>|null
     *         null si le socket est absent ou si l'agent ne repond pas.
     */
    public function getInterfaces(string $uuid, float $timeout = 2.0): ?array
    {
        $path = '/run/remotelabz/qga-' . $uuid . '.sock';
        if (!file_exists($path)) {
            return null;
        }

        $socket = @stream_socket_client('unix://' . $path, $errno, $errstr, $timeout);
        if ($socket === false) {
            $this->logger->debug('[QemuGuestAgentClient]::Socket inaccessible', [
                'instance' => $uuid,
                'error' => $errstr,
            ]);

            return null;
        }

        stream_set_timeout($socket, (int) $timeout);
        fwrite($socket, json_encode(['execute' => 'guest-network-get-interfaces']) . "\n");

        // L'agent peut emettre des evenements avant la reponse : on ignore
        // toute ligne qui ne contient ni "return" ni "error", dans la limite
        // du delai imparti.
        $deadline = microtime(true) + $timeout;
        $result = null;
        while (microtime(true) < $deadline) {
            $line = fgets($socket);
            if ($line === false) {
                break;
            }

            $data = json_decode(trim($line), true);
            if (!is_array($data)) {
                continue;
            }
            if (array_key_exists('return', $data)) {
                $result = $data['return'];
                break;
            }
            if (array_key_exists('error', $data)) {
                $this->logger->debug('[QemuGuestAgentClient]::Erreur de l\'agent', [
                    'instance' => $uuid,
                    'error' => $data['error'],
                ]);
                break;
            }
        }
        fclose($socket);

        if (!is_array($result)) {
            return null;
        }

        $interfaces = [];
        foreach ($result as $interface) {
            if (!is_array($interface)) {
                continue;
            }

            $ipv4 = [];
            $ipv6 = [];
            foreach (($interface['ip-addresses'] ?? []) as $address) {
                $ip = $address['ip-address'] ?? null;
                if ($ip === null) {
                    continue;
                }

                $type = $address['ip-address-type'] ?? '';
                if ($type === 'ipv4' && $ip !== '127.0.0.1') {
                    $ipv4[] = $ip;
                } elseif ($type === 'ipv6'
                    && $ip !== '::1'
                    && !str_starts_with(strtolower($ip), 'fe80:')
                ) {
                    $ipv6[] = $ip;
                }
            }

            $interfaces[] = [
                'name' => $interface['name'] ?? '',
                'mac' => isset($interface['hardware-address'])
                    ? strtolower($interface['hardware-address'])
                    : null,
                'ipv4' => $ipv4,
                'ipv6' => $ipv6,
            ];
        }

        return $interfaces;
    }
}
