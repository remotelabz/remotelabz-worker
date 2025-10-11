<?php
namespace App\Service;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Psr\Log\LoggerInterface;

class ResourcesCacheService
{
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

    private function disk_usage(): int
    {
        $process = new Process(['df', '-h', '/']);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->logger->error('Erreur disk usage : ' . $e->getMessage());
            return 0;
        }

        $output = explode("\n", $process->getOutput());
        return (int)round(preg_replace('/^.+ ([0-9]+)% .+/', '$1', $output[1] ?? '0'));
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
        $lxclsrun = shell_exec("sudo lxc-ls -f | grep RUNNING | wc -l");
        
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