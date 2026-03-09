<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Unit;

use GitHubSecurityJira\Config;
use GitHubSecurityJira\PullRequestIssue;
use GitHubSecurityJira\SecurityIssueInterface;
use PHPUnit\Framework\TestCase;

class PullRequestIssueTest extends TestCase
{
    /**
     * @var array<string,string|false>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $vars = [
            'GITHUB_REPOSITORY', 'GH_SECURITY_TOKEN', 'JIRA_HOST',
            'JIRA_USER', 'JIRA_TOKEN', 'JIRA_PROJECT', 'GITHUB_SERVER_URL',
            'JIRA_ISSUE_LABELS', 'JIRA_ISSUE_TYPE', 'JIRA_WATCHERS',
            'JIRA_RESTRICTED_COMMENT_ROLE',
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
        \putenv('GITHUB_SERVER_URL=https://github.com');
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

    public function testImplementsInterface(): void
    {
        $data = (require __DIR__ . '/../Fixtures/pr-response.php')['node'];
        $issue = new PullRequestIssue($data, new Config());
        $this->assertInstanceOf(SecurityIssueInterface::class, $issue);
    }

    public function testUniqueIdWithSubdirectory(): void
    {
        $data = [
            'number' => '101',
            'title' => 'Bump lodash from 4.17.15 to 4.17.21 in /frontend',
            'url' => 'https://github.com/reload/github-security-jira/pull/101',
        ];

        $issue = new PullRequestIssue($data, new Config());

        $this->assertSame('lodash:frontend:4.17.21', $issue->uniqueId());
    }

    public function testUniqueIdWithoutSubdirectory(): void
    {
        $data = [
            'number' => '102',
            'title' => 'Bump axios from 0.21.0 to 0.21.1',
            'url' => 'https://github.com/reload/github-security-jira/pull/102',
        ];

        $issue = new PullRequestIssue($data, new Config());

        $this->assertSame('axios:0.21.1', $issue->uniqueId());
    }

    public function testRegexParsesPackageName(): void
    {
        $data = [
            'number' => '103',
            'title' => 'Bump @angular/core from 12.0.0 to 12.0.1 in /frontend',
            'url' => 'https://github.com/reload/github-security-jira/pull/103',
        ];

        $issue = new PullRequestIssue($data, new Config());

        $this->assertSame('@angular/core:frontend:12.0.1', $issue->uniqueId());
    }
}
