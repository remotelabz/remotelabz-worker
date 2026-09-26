<?php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class SystemdStatusService
{
    private const UNIT_EXTENSIONS = ['service', 'timer', 'slice', 'target', 'socket'];

    private const PROPERTIES = [
        'LoadState',
        'ActiveState',
        'SubState',
        'UnitFileState',
        'Description',
        'MainPID',
        'ActiveSinceTimestamp',
        'Type',
        'Result',
        'ExecMainStartTimestamp',
    ];

    private string $systemdDir;
    private LoggerInterface $logger;

    public function __construct(string $projectDir, LoggerInterface $logger)
    {
        $this->systemdDir = $projectDir . '/bin/systemd';
        $this->logger = $logger;
    }

    /**
     * @return string[] Liste des fichiers d'unités présents dans bin/systemd
     */
    public function getUnitFiles(): array
    {
        if (!is_dir($this->systemdDir)) {
            $this->logger->warning("Répertoire systemd introuvable : {$this->systemdDir}");
            return [];
        }

        $units = [];
        foreach (scandir($this->systemdDir) as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array($extension, self::UNIT_EXTENSIONS, true) && $file !== '.' && $file !== '..') {
                $units[] = $file;
            }
        }
        sort($units);
        return $units;
    }

    /**
     * @return array<string, array<string, mixed>> État de chaque unité, indexé par nom d'unité
     */
    public function getServicesStatus(): array
    {
        $statuses = [];
        foreach ($this->getUnitFiles() as $unit) {
            $statuses[$unit] = $this->getUnitStatus($unit);
        }
        return $statuses;
    }

    private function getUnitStatus(string $unit): array
    {
        $status = [
            'name' => $unit,
            'description' => '',
            'load_state' => 'not-found',
            'active_state' => 'inactive',
            'sub_state' => 'dead',
            'unit_file_state' => 'unknown',
            'main_pid' => null,
            'active_since' => null,
            'type' => null,
            'last_result' => null,
            'last_run' => null,
            'is_running' => false,
        ];

        $properties = implode(',', self::PROPERTIES);
        $process = new Process(['systemctl', 'show', '--no-pager', '-p', $properties, '--', $unit]);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning("systemctl show a échoué pour {$unit} : " . $process->getErrorOutput());
            return $status;
        }

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            switch ($key) {
                case 'LoadState':
                    $status['load_state'] = $value;
                    break;
                case 'ActiveState':
                    $status['active_state'] = $value;
                    break;
                case 'SubState':
                    $status['sub_state'] = $value;
                    break;
                case 'UnitFileState':
                    $status['unit_file_state'] = $value;
                    break;
                case 'Description':
                    $status['description'] = $value;
                    break;
                case 'MainPID':
                    $status['main_pid'] = $value === '0' ? null : (int) $value;
                    break;
                case 'ActiveSinceTimestamp':
                    $status['active_since'] = $value === '' ? null : $value;
                    break;
                case 'Type':
                    $status['type'] = $value === '' ? null : $value;
                    break;
                case 'Result':
                    $status['last_result'] = $value === '' ? null : $value;
                    break;
                case 'ExecMainStartTimestamp':
                    $status['last_run'] = $value === '' ? null : $value;
                    break;
            }
        }

        $status['is_running'] = $status['active_state'] === 'active' && $status['sub_state'] === 'running';

        return $status;
    }
}
