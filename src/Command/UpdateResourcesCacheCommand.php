<?php
namespace App\Command;

use App\Service\ResourcesCacheService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateResourcesCacheCommand extends Command
{
    protected static $defaultName = 'app:update-resources-cache';
    protected static $defaultDescription = 'Met à jour le cache des ressources système';

    private $resourcesCacheService;

    public function __construct(ResourcesCacheService $resourcesCacheService)
    {
        parent::__construct();
        $this->resourcesCacheService = $resourcesCacheService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Mise à jour du cache des ressources...');
        $this->resourcesCacheService->updateCache();
        $output->writeln('Cache mis à jour avec succès');

        return Command::SUCCESS;
    }
}