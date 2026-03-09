<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Unit;

use GitHubSecurityJira\Config;
use GitHubSecurityJira\SecurityAlertIssue;
use GitHubSecurityJira\SecurityIssueInterface;
use PHPUnit\Framework\TestCase;

class SecurityAlertIssueTest extends TestCase
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

    /**
     * @return array<string,mixed>
     */
    private function getAlertData(): array
    {
        return require __DIR__ . '/../Fixtures/alert-response.php';
    }

    public function testImplementsInterface(): void
    {
        $issue = new SecurityAlertIssue($this->getAlertData(), new Config());
        $this->assertInstanceOf(SecurityIssueInterface::class, $issue);
    }

    public function testUniqueIdWithSafeVersionRootManifest(): void
    {
        $data = $this->getAlertData();
        // Root manifest path: pathinfo dirname resolves to "."
        $data['vulnerableManifestPath'] = 'package-lock.json';

        $issue = new SecurityAlertIssue($data, new Config());

        $this->assertSame('lodash:4.17.21', $issue->uniqueId());
    }

    public function testUniqueIdWithSafeVersionSubdirectory(): void
    {
        $data = $this->getAlertData();
        $data['vulnerableManifestPath'] = 'frontend/package-lock.json';

        $issue = new SecurityAlertIssue($data, new Config());

        $this->assertSame('lodash:frontend:4.17.21', $issue->uniqueId());
    }

    public function testUniqueIdWithoutSafeVersionUsesGhsaId(): void
    {
        $data = $this->getAlertData();
        unset($data['securityVulnerability']['firstPatchedVersion']);
        $data['vulnerableManifestPath'] = 'package-lock.json';

        $issue = new SecurityAlertIssue($data, new Config());

        $this->assertSame('lodash:GHSA-1234-5678-abcd', $issue->uniqueId());
    }

    public function testUniqueIdSpacesReplacedWithUnderscores(): void
    {
        $data = $this->getAlertData();
        $data['vulnerableManifestPath'] = 'my dir/package-lock.json';

        $issue = new SecurityAlertIssue($data, new Config());

        $this->assertSame('lodash:my_dir:4.17.21', $issue->uniqueId());
    }
}
