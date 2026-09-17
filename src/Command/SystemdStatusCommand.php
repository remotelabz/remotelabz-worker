<?php
namespace App\Command;

use App\Service\SystemdStatusService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:systemd-status',
    description: 'Affiche l\'état de tous les services systemd de bin/systemd'
)]
class SystemdStatusCommand extends Command
{
    private SystemdStatusService $systemdStatusService;

    public function __construct(SystemdStatusService $systemdStatusService)
    {
        parent::__construct();
        $this->systemdStatusService = $systemdStatusService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Sortie au format JSON')
            ->addOption('unit', 'u', InputOption::VALUE_REQUIRED, "N'affiche que l'unité donnée (ex : remotelabz-worker.service)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statuses = $this->systemdStatusService->getServicesStatus();

        $io = new SymfonyStyle($input, $output);

        $unitFilter = $input->getOption('unit');
        if ($unitFilter !== null) {
            if (!array_key_exists($unitFilter, $statuses)) {
                $io->error("Unité introuvable : {$unitFilter}");
                return Command::FAILURE;
            }
            $statuses = [$unitFilter => $statuses[$unitFilter]];
        }

        if ($statuses === []) {
            $io->warning("Aucune unité systemd trouvée dans bin/systemd");
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($statuses as $status) {
            $rows[] = [
                $status['name'],
                $status['description'],
                $status['load_state'],
                $status['active_state'],
                $status['sub_state'],
                $status['unit_file_state'],
                $status['main_pid'] ?? '-',
                $status['active_since'] ?? '-',
            ];
        }

        $io->table(
            ['Unité', 'Description', 'Load', 'Active', 'Sub', 'Fichier', 'PID', 'Actif depuis'],
            $rows
        );

        $running = count(array_filter($statuses, fn($s) => $s['is_running']));
        $io->writeln(sprintf("%d/%d unité(s) en cours d'exécution", $running, count($statuses)));

        return Command::SUCCESS;
    }
}
