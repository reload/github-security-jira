<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Unit;

use ArrayObject;
use GitHubSecurityJira\Config;
use GitHubSecurityJira\JiraTransitionService;
use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\IssueSearchResult;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Transition;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JiraTransitionServiceTest extends TestCase
{
    /**
     * @var array<string,string|false>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $vars = [
            'GITHUB_REPOSITORY', 'GH_SECURITY_TOKEN', 'JIRA_HOST',
            'JIRA_USER', 'JIRA_TOKEN', 'JIRA_PROJECT', 'JIRA_CLOSE_TRANSITION',
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
        \putenv('JIRA_CLOSE_TRANSITION=Done');
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

    public function testCloseIssueCallsTransition(): void
    {
        $transition = new Transition();
        $transition->id = '31';
        $transition->name = 'Done';

        $mockIssueService = $this->createMock(IssueService::class);
        $mockIssueService->method('getTransition')
            ->with('TEST-123')
            ->willReturn(new ArrayObject([$transition]));
        $mockIssueService->expects($this->once())
            ->method('transition')
            ->with('TEST-123', $this->isInstanceOf(Transition::class));

        $config = new Config();
        $service = new JiraTransitionService($config, $mockIssueService);
        $service->closeIssue('TEST-123', 'Alert resolved: *FIXED*');
    }

    public function testCloseIssueThrowsWhenTransitionNotFound(): void
    {
        $mockIssueService = $this->createMock(IssueService::class);
        $mockIssueService->method('getTransition')
            ->willReturn(new ArrayObject([]));

        $config = new Config();
        $service = new JiraTransitionService($config, $mockIssueService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Transition 'Done' not available");

        $service->closeIssue('TEST-123', 'Alert resolved');
    }

    public function testFindIssuesByLabelsBuildsCorrectJql(): void
    {
        $issue = new Issue();
        $issue->key = 'TEST-456';

        $searchResult = new IssueSearchResult();
        $searchResult->issues = [$issue];

        $mockIssueService = $this->createMock(IssueService::class);
        $mockIssueService->expects($this->once())
            ->method('search')
            ->with($this->callback(function (string $jql): bool {
                return \str_contains($jql, 'project = "TEST"')
                    && \str_contains($jql, 'labels = "reload/github-security-jira"')
                    && \str_contains($jql, 'labels = "lodash:4.17.21"')
                    && \str_contains($jql, 'status != "Done"');
            }))
            ->willReturn($searchResult);

        $config = new Config();
        $service = new JiraTransitionService($config, $mockIssueService);
        $keys = $service->findIssuesByLabels(['reload/github-security-jira', 'lodash:4.17.21']);

        $this->assertSame(['TEST-456'], $keys);
    }

    public function testFindIssuesByLabelsReturnsEmptyWhenNoMatches(): void
    {
        $searchResult = new IssueSearchResult();
        $searchResult->issues = [];

        $mockIssueService = $this->createMock(IssueService::class);
        $mockIssueService->method('search')->willReturn($searchResult);

        $config = new Config();
        $service = new JiraTransitionService($config, $mockIssueService);

        $this->assertSame([], $service->findIssuesByLabels(['repo', 'id']));
    }
}
