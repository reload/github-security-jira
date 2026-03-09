<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The default sync command.
 */
class SyncCommand extends Command
{
    /**
     * Name of the command.
     *
     * @var string
     */
    protected static $defaultName = 'sync';

    /**
     * {@inheritDoc}
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Sync GitHub Alert status to Jira')
            ->setHelp('This command allows you to synchronize the security status from GitHub security alerts to Jira.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do dry run (dont change anything)',
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $githubClient = new GitHubGraphQLClient($config);
        $syncService = new AlertSyncService($config, $githubClient);
        $dryRun = (bool) $input->getOption('dry-run');

        $alertIds = $syncService->syncOpenAlerts($output, $dryRun);
        $syncService->syncPullRequests($output, $dryRun, $alertIds);
        $syncService->closeResolvedAlerts($output, $dryRun);

        return Command::SUCCESS;
    }
}
