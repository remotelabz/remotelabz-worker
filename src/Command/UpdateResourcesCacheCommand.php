<?php
namespace App\Command;

use App\Service\ResourcesCacheService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateResourcesCacheCommand extends Command
{
    protected static $defaultName = 'app:update-resources-cache';
    protected static $defaultDescription = 'Update system resource cache';

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