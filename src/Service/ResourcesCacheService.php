<?php
namespace App\Service;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Psr\Log\LoggerInterface;

class ResourcesCacheService
{
    /** Groupe de volumes qui porte le pool thin des instances LXC. */
    private const LXC_VG = 'rlz-vg';

    /** Pool thin dans lequel sont créés les LV (rootfs) des instances LXC. */
    private const LXC_THIN_POOL = 'rlz-lxc-pool';

    private $logger;
    private $cacheDir;

    public function __construct(LoggerInterface $logger, string $projectDir)
    {
        $this->logger = $logger;
        $this->cacheDir = $projectDir . '/var/cache/resources';
        
        // Créer le répertoire s'il n'existe pas
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Collecte toutes les ressources et les sauvegarde en cache
     */
    public function updateCache(): void
    {
        try {
            $hardwareStats = $this->collectHardwareStats();
            $this->saveToCache('hardware_stats.json', $hardwareStats);
            
            $hardwareLightStats = $this->collectHardwareLightStats();
            $this->saveToCache('hardware_light_stats.json', $hardwareLightStats);
            
            $this->logger->info('Cache des ressources mis à jour avec succès');
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la mise à jour du cache : ' . $e->getMessage());
        }
    }

    private function collectHardwareStats(): array
    {
        $response = [
            'cpu' => $this->cpu_load(),
            'disk' => $this->disk_usage(),
            'openedfiles' => $this->opened_file(),
            'lxclsrun' => $this->lxc_number(),
            'qemurun' => $this->qemu_number(),
            'lxcfs' => $this->lxcfs_load()
        ];

        $memoryResult = $this->memory_usage();
        $response['memory'] = $memoryResult['memory'];
        $response['memory_total'] = $memoryResult['memory_total'];

        $this->logger->info("Number of opened file: " . $response['openedfiles']);
        $this->logger->info("Number of LXC containers running: " . $response['lxclsrun']);
        $this->logger->info("Number of QEMU VM Running: " . $response['qemurun']);
        $this->logger->info("LXCFS load: " . $response['lxcfs']);

        return $response;
    }

    private function collectHardwareLightStats(): array
    {
        $response = [
            'cpu' => $this->cpu_load(),
            'disk' => $this->disk_usage(),
            'lxcfs' => $this->lxcfs_load()
        ];

        $memoryResult = $this->memory_usage();
        $response['memory'] = $memoryResult['memory'];
        $response['memory_total'] = $memoryResult['memory_total'];

        return $response;
    }

    private function saveToCache(string $filename, array $data): void
    {
        $filepath = $this->cacheDir . '/' . $filename;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($filepath, $json) === false) {
            throw new \Exception("Impossible d'écrire le fichier cache : $filepath");
        }
    }

    private function cpu_load(): int
    {
        $process = new Process(['vmstat', '1', '2']);
        
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error('Erreur CPU load : ' . $e->getMessage());
            return 0;
        }

        $output = explode("\n", $process->getOutput());
        
        // vmstat retourne 3 lignes : header, première mesure, deuxième mesure
        // On prend la deuxième mesure (dernière ligne) qui est plus précise
        $lastLine = trim($output[count($output) - 2] ?? '');
        
        if (empty($lastLine)) {
            return 0;
        }

        // Splitter par espaces et récupérer les colonnes us, sy, wa
        $columns = preg_split('/\s+/', $lastLine);
        
        // vmstat: us, sy, id, wa, st
        // Colonnes : 12=us, 13=sy, 14=id, 15=wa, 16=st (peut varier selon version)
        if (count($columns) >= 16) {
            $us = (int)$columns[12];  // user time
            $sy = (int)$columns[13];  // system time
            $wa = (int)$columns[15];  // wait time
            
            return $us + $sy + $wa;
        }

        return 0;
    }

    private function disk_usage(): array
    {
        // 1. Liste de tous les VG (pour ne pas en oublier même sans LV monté, ex: rlz-vg)
        $vgsProcess = new Process(['sudo', '-n', 'vgs', '--noheadings', '-o', 'vg_name']);

        try {
            $vgsProcess->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error('Erreur disk usage (vgs) : ' . $e->getMessage());
            return [];
        }

        $vgNames = array_filter(array_map('trim', explode("\n", $vgsProcess->getOutput())));
        $result = array_fill_keys($vgNames, null); // null = pas de LV monté => NA côté Twig
        $vgMountedLvs = array_fill_keys($vgNames, 0); // nb de points de montage par VG
        $vgMaxUsage = array_fill_keys($vgNames, 0);   // taux max par VG

        // 2. Espace réellement alloué dans le thin pool LXC. Contrairement à un
        //    VG classique, l'espace libre du VG ne dit rien du remplissage du
        //    pool (sur-allocation) : c'est ce taux qui compte pour les LXC.
        $poolPercent = $this->lxc_thin_pool_usage();
        $result[self::LXC_THIN_POOL] = $poolPercent;

        // Le pool est un composant de rlz-vg : il entre dans son agrégat.
        if ($poolPercent !== null && isset($vgMountedLvs[self::LXC_VG])) {
            $vgMountedLvs[self::LXC_VG]++;
            $vgMaxUsage[self::LXC_VG] = max($vgMaxUsage[self::LXC_VG], $poolPercent);
        }

        // 3. Liste des LV avec leur VG, leur nom, leur chemin device et le pool
        //    éventuel (renseigné pour les volumes thin d'instances)
        $lvsProcess = new Process(['sudo', '-n', 'lvs', '--noheadings', '--separator', ';', '-o', 'vg_name,lv_name,lv_path,pool_lv']);

        try {
            $lvsProcess->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error('Erreur disk usage (lvs) : ' . $e->getMessage());
            return $result;
        }

        $lvLines = array_filter(array_map('trim', explode("\n", $lvsProcess->getOutput())));

        foreach ($lvLines as $line) {
            [$vgName, $lvName, $lvPath, $poolLv] = array_pad(array_map('trim', explode(';', $line)), 4, '');

            // 4. Un LV sans chemin device (ex: le thin pool) ne peut pas être
            //    monté : on l'ignore. Sans ce test, « findmnt '' » renvoyait
            //    « / » et faussait tous les taux.
            if ($lvPath === '') {
                continue;
            }

            // 5. Point de montage réel de ce LV (pas forcément monté)
            $findmntProcess = new Process(['findmnt', '-n', '-o', 'TARGET', $lvPath]);
            $findmntProcess->run();

            if (!$findmntProcess->isSuccessful()) {
                continue; // LV non monté (swap, LV brut, etc.)
            }

            $mountPoint = trim($findmntProcess->getOutput());
            if ($mountPoint === '' || $mountPoint[0] !== '/') {
                continue;
            }

            // 6. Taux de remplissage réel du filesystem, comme df -h
            $dfProcess = new Process(['df', '--output=pcent', $mountPoint]);

            try {
                $dfProcess->mustRun();
            } catch (ProcessFailedException $e) {
                $this->logger->error("Erreur disk usage (df sur $mountPoint) : " . $e->getMessage());
                continue;
            }

            $dfLines = explode("\n", trim($dfProcess->getOutput()));
            $percent = (int) rtrim(trim($dfLines[1] ?? '0%'), '%');

            // 7. Taux détaillé par LV (ex: lib-lxc → /var/lib/lxc, rlz-lv → /opt).
            //    On ignore les volumes thin d'instances (lxc_<uuid>) : créés à la
            //    volée dans le pool, ils ne doivent pas polluer les statistiques.
            if ($poolLv === '') {
                $result[$lvName] = $percent;
            }

            // 8. Agrégat par VG (retraité juste après la boucle)
            $vgMountedLvs[$vgName] = ($vgMountedLvs[$vgName] ?? 0) + 1;
            $vgMaxUsage[$vgName] = max($vgMaxUsage[$vgName] ?? 0, $percent);
        }

        // 9. On n'expose la clé d'un VG que si elle apporte une information : un
        //    VG avec un unique point de montage est redondant avec la clé de son
        //    LV (ex: ubuntu-vg ≡ ubuntu-lv). La clé rlz-vg est toujours conservée
        //    car le placement des labs s'appuie dessus (WorkerManager) ; sa
        //    valeur est le taux le plus élevé de ses LV et de son pool thin.
        foreach ($vgNames as $vgName) {
            if ($vgName === self::LXC_VG || $vgMountedLvs[$vgName] >= 2) {
                $result[$vgName] = $vgMountedLvs[$vgName] > 0 ? $vgMaxUsage[$vgName] : null;
            } else {
                unset($result[$vgName]);
            }
        }

        return $result;
    }

    /**
     * Taux de remplissage (data_percent, alias « Allocated pool data ») du pool
     * thin dans lequel sont stockés les rootfs des instances LXC.
     *
     * C'est cet indicateur — et non l'espace libre du VG — qui permet de savoir
     * si de nouveaux volumes thin peuvent encore être provisionnés.
     *
     * @return int|null Pourcentage arrondi, ou null si le pool est introuvable/inactif
     */
    private function lxc_thin_pool_usage(): ?int
    {
        // LC_ALL=C : data_percent peut être formaté avec une virgule selon la
        // locale (ex: « 12,67 »), ce qui fausserait le cast en float.
        $process = new Process([
            'sudo', '-n', 'lvs', '--noheadings',
            '-o', 'data_percent',
            self::LXC_VG . '/' . self::LXC_THIN_POOL,
        ], null, ['LC_ALL' => 'C']);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error('Erreur disk usage (lvs thin pool) : ' . $e->getMessage());
            return null;
        }

        // data_percent est vide tant que le pool n'est pas actif
        $value = trim($process->getOutput());
        if ($value === '') {
            return null;
        }

        return (int) round((float) $value);
    }

    private function memory_usage(): array
    {
        $process = new Process(['cat', '/proc/meminfo']);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error('Erreur memory usage : ' . $e->getMessage());
            return ['memory' => 0, 'memory_total' => 0];
        }

        $output = explode("\n", $process->getOutput());
        array_pop($output);
        
        $meminfo = [];
        foreach ($output as $line) {
            if (empty($line)) continue;
            
            [$key, $val] = explode(":", $line);
            $meminfo[trim($key)] = (int)preg_replace('/^([0-9\.]+)\ +.*$/', '$1', trim($val));
        }

        $total = $meminfo['MemTotal'] ?? 0;
        $avail = $meminfo['MemAvailable'] ?? 0;

        return [
            'memory' => $total > 0 ? round(100 - ($avail / $total * 100)) : 0,
            'memory_total' => $total > 0 ? $total / 1000 : 0
        ];
    }

    private function lxcfs_load(): int|string
    {
        $command = "PID=\$(pgrep lxcfs) && ps -p \$PID -o %cpu --no-header";
        $lxcfs = shell_exec($command);
        
        if (!is_null($lxcfs) && $lxcfs !== '') {
            return (int)round((float)trim($lxcfs));
        }
        
        return "";
    }

    private function opened_file(): int
    {
        try {
            $content = file_get_contents('/proc/sys/fs/file-nr');
            if ($content === false) {
                return 0;
            }
            
            $columns = preg_split('/\s+/', trim($content));
            return (int)$columns[0];
        } catch (\Exception $e) {
            $this->logger->error('Erreur opened files : ' . $e->getMessage());
            return 0;
        }
    }

    private function lxc_number(): int
    {
        $lxclsrun = shell_exec("sudo lxc-ls -1 --running | wc -l");
        
        if (!is_null($lxclsrun) && $lxclsrun) {
            return (int)$lxclsrun;
        }
        
        return 0;
    }

    private function qemu_number(): int
    {
        $qemurun = shell_exec("sudo ps x | grep -e \"qemu\" | wc -l");
        
        if (!is_null($qemurun) && $qemurun) {
            return (int)$qemurun;
        }
        
        return 0;
    }
}