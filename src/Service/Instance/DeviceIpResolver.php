<?php

namespace App\Service\Instance;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Resout les adresses IP d'une DeviceInstance (VM QEMU ou conteneur LXC).
 *
 * Cote worker, l'IP n'est connue d'aucune autre couche :
 *   - conteneur LXC a IP statique : l'IP est deja connue du worker lors du
 *     create_lxc_device(), mais lxc-info -iH la redonne de facon homogene ;
 *   - conteneur LXC en DHCP : lxc-info -n <uuid> -iH interroge le noyau hote ;
 *   - VM QEMU : qemu-guest-agent en priorite, sinon repli sur la table ARP du
 *     pont (par MAC, connue du front et du worker) ou sur les baux DHCP Kea.
 *
 * Le service est volontairement separe d'InstanceManager (deja tres volumineux)
 * et ne conserve aucun etat : il relit le systeme a chaque appel.
 */
class DeviceIpResolver
{
    public function __construct(
        private QemuGuestAgentClient $qga,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param array  $deviceInstance fragment deviceInstances[i] du descripteur "worker"
     * @param string $bridgeName     pont OVS du lab (LabInstance::getBridgeName())
     *
     * @return array<int, array{name:string, mac:?string, ipv4:string[], ipv6:string[]}>
     */
    public function resolveDeviceInstanceIps(array $deviceInstance, string $bridgeName): array
    {
        $hypervisor = strtolower((string) ($deviceInstance['device']['hypervisor']['name'] ?? ''));
        $uuid = (string) ($deviceInstance['uuid'] ?? '');

        try {
            if ($hypervisor === 'lxc') {
                return $this->collectLxc($uuid, $deviceInstance);
            }

            if ($hypervisor === 'qemu') {
                return $this->collectQemu($uuid, $bridgeName, $deviceInstance);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[DeviceIpResolver]::Echec de resolution d\'IP', [
                'instance' => $uuid,
                'error' => $e->getMessage(),
            ]);
        }

        // Natif / switch : pas d'IP d'instance a collecter.
        return [];
    }

    /**
     * Conteneur : une commande par conteneur (pas de parcours global type
     * lxc-ls -f). -i liste les IP, -H supprime les en-tetes.
     */
    private function collectLxc(string $uuid, array $deviceInstance): array
    {
        $process = new Process(['lxc-info', '-n', $uuid, '-iH']);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $ipv4 = [];
        $ipv6 = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            $ip = trim($line);
            if ($ip === '') {
                continue;
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipv4[] = $ip;
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                && !str_starts_with(strtolower($ip), 'fe80:')
            ) {
                $ipv6[] = $ip;
            }
        }

        return $this->zipWithMacs($deviceInstance, $ipv4, $ipv6);
    }

    /**
     * VM : qemu-guest-agent d'abord, puis repli ARP / bail DHCP par MAC.
     */
    private function collectQemu(string $uuid, string $bridgeName, array $deviceInstance): array
    {
        $interfaces = $this->qga->getInterfaces($uuid);
        if (is_array($interfaces) && count($interfaces) > 0) {
            return $interfaces;
        }

        // Repli 1 : table ARP du pont, filtree par MAC connue.
        $byMac = $this->neighByMac($bridgeName);

        // Repli 2 : baux DHCP Kea (memfile).
        if (empty($byMac)) {
            $byMac = $this->leasesByMac();
        }

        $result = [];
        foreach (($deviceInstance['networkInterfaceInstances'] ?? []) as $nic) {
            $mac = isset($nic['macAddress']) ? strtolower((string) $nic['macAddress']) : null;
            $ip = ($mac !== null && isset($byMac[$mac])) ? $byMac[$mac] : null;

            $result[] = [
                'name' => $nic['networkInterface']['name'] ?? '',
                'mac' => $mac,
                'ipv4' => $ip !== null ? [$ip] : [],
                'ipv6' => [],
            ];
        }

        return $result;
    }

    /**
     * Table ARP (voisins) du pont : MAC (minuscule) => IPv4.
     *
     * @return array<string, string>
     */
    private function neighByMac(string $bridgeName): array
    {
        if ($bridgeName === '') {
            return [];
        }

        $process = new Process(['ip', '-4', 'neigh', 'show', 'dev', $bridgeName]);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $map = [];
        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+.*lladdr\s+([0-9a-f:]+)/i', trim($line), $matches)) {
                $map[strtolower($matches[2])] = $matches[1];
            }
        }

        return $map;
    }

    /**
     * Baux DHCP Kea (lease-database.type = memfile) : MAC (minuscule) => IPv4.
     * Format CSV : address,hwaddr,client_id,valid_lifetime,expire,subnet_id,...
     *
     * @return array<string, string>
     */
    private function leasesByMac(): array
    {
        $file = '/var/lib/kea/kea-leases4.csv';
        if (!is_readable($file)) {
            return [];
        }

        $map = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $columns = str_getcsv($line);
            if (count($columns) < 2
                || !filter_var($columns[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ) {
                continue;
            }

            $map[strtolower($columns[1])] = $columns[0];
        }

        return $map;
    }

    /**
     * lxc-info ne rend pas la MAC : on rattache les IP au premier NIC connu
     * (cas courant d'un conteneur mono-interface).
     */
    private function zipWithMacs(array $deviceInstance, array $ipv4, array $ipv6): array
    {
        $nics = $deviceInstance['networkInterfaceInstances'] ?? [];
        $count = max(count($nics), 1);

        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                'name' => $nics[$i]['networkInterface']['name'] ?? ('eth' . $i),
                'mac' => isset($nics[$i]['macAddress']) ? strtolower((string) $nics[$i]['macAddress']) : null,
                'ipv4' => $i === 0 ? $ipv4 : [],
                'ipv6' => $i === 0 ? $ipv6 : [],
            ];
        }

        return $result;
    }
}
