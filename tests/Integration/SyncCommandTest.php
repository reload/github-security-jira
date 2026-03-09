<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Integration;

use GitHubSecurityJira\SyncCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SyncCommandTest extends TestCase
{
    /**
     * @var array<string,string|false>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $vars = [
            'GITHUB_REPOSITORY', 'GH_SECURITY_TOKEN', 'JIRA_HOST',
            'JIRA_USER', 'JIRA_TOKEN', 'JIRA_PROJECT',
            'JIRA_ISSUE_LABELS', 'JIRA_WATCHERS',
        ];
        foreach ($vars as $var) {
            $this->originalEnv[$var] = \getenv($var);
        }

        \putenv('GITHUB_REPOSITORY=reload/github-security-jira');
        \putenv('GH_SECURITY_TOKEN=ghp_test');
        \putenv('JIRA_HOST=https://test.atlassian.net');
        \putenv('JIRA_USER=test@example.com');
        \putenv('JIRA_TOKEN=token');
        \putenv('JIRA_PROJECT=TEST');
        \putenv('JIRA_ISSUE_LABELS');
        \putenv('JIRA_WATCHERS');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $var => $value) {
            if ($value === false) {
                \putenv($var);
            } else {
                \putenv("{$var}={$value}");
            }
        }
    }

    public function testCommandIsRegistered(): void
    {
        $application = new Application('ghsec-jira');
        $command = new SyncCommand();
        $application->add($command);

        $this->assertTrue($application->has('sync'));
    }

    public function testCommandHasDryRunOption(): void
    {
        $command = new SyncCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertFalse($definition->getOption('dry-run')->acceptValue());
    }
}
