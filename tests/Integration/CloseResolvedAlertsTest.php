<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Integration;

use GitHubSecurityJira\AlertSyncService;
use GitHubSecurityJira\Config;
use GitHubSecurityJira\GitHubGraphQLClient;
use GitHubSecurityJira\JiraTransitionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Softonic\GraphQL\Client as GraphQLClient;
use Softonic\GraphQL\Response;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class CloseResolvedAlertsTest extends TestCase
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
        \putenv('JIRA_CLOSE_TRANSITION=Done');
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
    private function makeResolvedAlert(string $state = 'FIXED'): array
    {
        return [
            'state' => $state,
            'fixedAt' => '2024-01-15T10:30:00Z',
            'dismissedAt' => null,
            'dismissReason' => null,
            'number' => 42,
            'securityVulnerability' => [
                'advisory' => [
                    'ghsaId' => 'GHSA-1234-5678-abcd',
                    'summary' => 'Prototype Pollution in lodash',
                ],
                'firstPatchedVersion' => ['identifier' => '4.17.21'],
                'package' => ['name' => 'lodash', 'ecosystem' => 'NPM'],
                'severity' => 'HIGH',
                'vulnerableVersionRange' => '< 4.17.21',
            ],
            'vulnerableManifestFilename' => 'package-lock.json',
            'vulnerableManifestPath' => '/package-lock.json',
            'vulnerableRequirements' => '= 4.17.15',
        ];
    }

    public function testDryRunLogsWouldClose(): void
    {
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('hasErrors')->willReturn(false);
        $mockResponse->method('getData')->willReturn([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'nodes' => [$this->makeResolvedAlert()],
                ],
            ],
        ]);

        $mockGraphQL = $this->createMock(GraphQLClient::class);
        $mockGraphQL->method('query')->willReturn($mockResponse);

        $mockTransition = $this->createMock(JiraTransitionService::class);
        $mockTransition->method('findIssuesByLabels')->willReturn(['TEST-100']);

        $config = new Config();
        $githubClient = new GitHubGraphQLClient($config, $mockGraphQL);
        $syncService = new AlertSyncService($config, $githubClient, $mockTransition);

        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);

        $syncService->closeResolvedAlerts($output, true);

        $this->assertStringContainsString('Would close TEST-100 (alert FIXED)', $output->fetch());
    }

    public function testCloseIssueCalledWhenNotDryRun(): void
    {
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('hasErrors')->willReturn(false);
        $mockResponse->method('getData')->willReturn([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'nodes' => [$this->makeResolvedAlert()],
                ],
            ],
        ]);

        $mockGraphQL = $this->createMock(GraphQLClient::class);
        $mockGraphQL->method('query')->willReturn($mockResponse);

        $mockTransition = $this->createMock(JiraTransitionService::class);
        $mockTransition->method('findIssuesByLabels')->willReturn(['TEST-100']);
        $mockTransition->expects($this->once())
            ->method('closeIssue')
            ->with('TEST-100', $this->stringContains('FIXED'));

        $config = new Config();
        $githubClient = new GitHubGraphQLClient($config, $mockGraphQL);
        $syncService = new AlertSyncService($config, $githubClient, $mockTransition);

        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);

        $syncService->closeResolvedAlerts($output, false);
    }

    public function testTransitionFailureDoesNotStopProcessing(): void
    {
        $alerts = [
            $this->makeResolvedAlert(),
            $this->makeResolvedAlert('DISMISSED'),
        ];
        $alerts[1]['securityVulnerability']['package']['name'] = 'axios';
        $alerts[1]['securityVulnerability']['firstPatchedVersion']['identifier'] = '0.21.1';
        $alerts[1]['dismissedAt'] = '2024-02-01T00:00:00Z';
        $alerts[1]['dismissReason'] = 'not_used';

        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('hasErrors')->willReturn(false);
        $mockResponse->method('getData')->willReturn([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'nodes' => $alerts,
                ],
            ],
        ]);

        $mockGraphQL = $this->createMock(GraphQLClient::class);
        $mockGraphQL->method('query')->willReturn($mockResponse);

        $mockTransition = $this->createMock(JiraTransitionService::class);
        $mockTransition->method('findIssuesByLabels')
            ->willReturnOnConsecutiveCalls(['TEST-100'], ['TEST-200']);
        $mockTransition->method('closeIssue')
            ->willReturnCallback(function (string $key): void {
                if ($key === 'TEST-100') {
                    throw new RuntimeException('Transition failed');
                }
            });

        $config = new Config();
        $githubClient = new GitHubGraphQLClient($config, $mockGraphQL);
        $syncService = new AlertSyncService($config, $githubClient, $mockTransition);

        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);

        $syncService->closeResolvedAlerts($output, false);

        $outputText = $output->fetch();
        $this->assertStringContainsString('Failed to close TEST-100', $outputText);
        $this->assertStringContainsString('Closed TEST-200', $outputText);
    }

    public function testNoResolvedAlertsLogsMessage(): void
    {
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('hasErrors')->willReturn(false);
        $mockResponse->method('getData')->willReturn([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'nodes' => [],
                ],
            ],
        ]);

        $mockGraphQL = $this->createMock(GraphQLClient::class);
        $mockGraphQL->method('query')->willReturn($mockResponse);

        $config = new Config();
        $githubClient = new GitHubGraphQLClient($config, $mockGraphQL);
        $syncService = new AlertSyncService($config, $githubClient);

        $output = new BufferedOutput();
        $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);

        $syncService->closeResolvedAlerts($output, false);

        $this->assertStringContainsString('No resolved alerts found', $output->fetch());
    }
}
