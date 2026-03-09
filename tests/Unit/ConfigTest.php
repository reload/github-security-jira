<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Unit;

use GitHubSecurityJira\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConfigTest extends TestCase
{
    /**
     * @var array<string,string|false>
     */
    private array $originalEnv = [];

    /**
     * @var array<string>
     */
    private array $envVarsToRestore = [
        'GITHUB_REPOSITORY',
        'GH_SECURITY_TOKEN',
        'JIRA_HOST',
        'JIRA_USER',
        'JIRA_TOKEN',
        'JIRA_PROJECT',
        'GITHUB_GRAPHQL_URL',
        'GITHUB_SERVER_URL',
        'JIRA_ISSUE_TYPE',
        'JIRA_RESTRICTED_COMMENT_ROLE',
        'JIRA_CLOSE_TRANSITION',
        'JIRA_WATCHERS',
        'JIRA_ISSUE_LABELS',
    ];

    protected function setUp(): void
    {
        foreach ($this->envVarsToRestore as $var) {
            $this->originalEnv[$var] = \getenv($var);
        }

        $this->setRequiredEnv();
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

    private function setRequiredEnv(): void
    {
        \putenv('GITHUB_REPOSITORY=reload/github-security-jira');
        \putenv('GH_SECURITY_TOKEN=ghp_test123');
        \putenv('JIRA_HOST=https://test.atlassian.net');
        \putenv('JIRA_USER=test@example.com');
        \putenv('JIRA_TOKEN=jira_test_token');
        \putenv('JIRA_PROJECT=TEST');
    }

    public function testRequiredVarsPresent(): void
    {
        $config = new Config();

        $this->assertSame('reload/github-security-jira', $config->githubRepository);
        $this->assertSame('reload', $config->githubOwner);
        $this->assertSame('github-security-jira', $config->githubRepo);
        $this->assertSame('ghp_test123', $config->ghSecurityToken);
        $this->assertSame('https://test.atlassian.net', $config->jiraHost);
        $this->assertSame('test@example.com', $config->jiraUser);
        $this->assertSame('jira_test_token', $config->jiraToken);
        $this->assertSame('TEST', $config->jiraProject);
    }

    public function testMissingRequiredVarThrows(): void
    {
        \putenv('JIRA_PROJECT');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Required env variable 'JIRA_PROJECT' not set or empty.");

        new Config();
    }

    public function testInvalidRepositoryFormatThrows(): void
    {
        \putenv('GITHUB_REPOSITORY=invalid-no-slash');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub repository invalid');

        new Config();
    }

    public function testDefaults(): void
    {
        \putenv('GITHUB_GRAPHQL_URL');
        \putenv('GITHUB_SERVER_URL');
        \putenv('JIRA_ISSUE_TYPE');
        \putenv('JIRA_RESTRICTED_COMMENT_ROLE');
        \putenv('JIRA_CLOSE_TRANSITION');
        \putenv('JIRA_WATCHERS');
        \putenv('JIRA_ISSUE_LABELS');

        $config = new Config();

        $this->assertSame('https://api.github.com/graphql', $config->githubGraphqlUrl);
        $this->assertSame('https://github.com', $config->githubServerUrl);
        $this->assertSame('Bug', $config->jiraIssueType);
        $this->assertSame('Developers', $config->jiraRestrictedCommentRole);
        $this->assertSame('Done', $config->jiraCloseTransition);
        $this->assertSame([], $config->jiraWatchers);
        $this->assertSame([], $config->jiraIssueLabels);
    }

    public function testLabelParsing(): void
    {
        \putenv('JIRA_ISSUE_LABELS=security,dependabot,critical');

        $config = new Config();

        $this->assertSame(['security', 'dependabot', 'critical'], $config->jiraIssueLabels);
    }

    public function testWatcherParsing(): void
    {
        \putenv('JIRA_WATCHERS=user1@test.com,user2@test.com');

        $config = new Config();

        $this->assertSame(['user1@test.com', 'user2@test.com'], $config->jiraWatchers);
    }

    public function testEmptyLabelsReturnsEmptyArray(): void
    {
        \putenv('JIRA_ISSUE_LABELS=');

        $config = new Config();

        $this->assertSame([], $config->jiraIssueLabels);
    }

    public function testCustomCloseTransition(): void
    {
        \putenv('JIRA_CLOSE_TRANSITION=Resolved');

        $config = new Config();

        $this->assertSame('Resolved', $config->jiraCloseTransition);
    }
}
