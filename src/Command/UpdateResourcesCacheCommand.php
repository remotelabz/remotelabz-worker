<?php
namespace App\Command;

use App\Service\ResourcesCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:update-resources-cache', description: 'Update system resource cache')]
class UpdateResourcesCacheCommand extends Command
{
    private $resourcesCacheService;

    public function __construct(ResourcesCacheService $resourcesCacheService)
    {
        parent::__construct();
        $this->resourcesCacheService = $resourcesCacheService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Updating resource cache...');
        $this->resourcesCacheService->updateCache();
        $output->writeln('Update resource cache done');

        return Command::SUCCESS;
    }
}
