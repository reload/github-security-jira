<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Unit;

use GitHubSecurityJira\Config;
use GitHubSecurityJira\GitHubGraphQLClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Softonic\GraphQL\Client as GraphQLClient;
use Softonic\GraphQL\Response;

class GitHubGraphQLClientTest extends TestCase
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

    public function testFetchOpenAlertsReturnsNodes(): void
    {
        $alertNode = require __DIR__ . '/../Fixtures/alert-response.php';
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('hasErrors')->willReturn(false);
        $mockResponse->method('getData')->willReturn([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'nodes' => [$alertNode],
                ],
            ],
        ]);

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->method('query')->willReturn($mockResponse);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $alerts = $client->fetchOpenAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('lodash', $alerts[0]['securityVulnerability']['package']['name']);
    }

    public function testFetchOpenAlertsReturnsEmptyWhenNoNodes(): void
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

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->method('query')->willReturn($mockResponse);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $this->assertSame([], $client->fetchOpenAlerts());
    }

    public function testFetchResolvedAlertsReturnsNodes(): void
    {
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('hasErrors')->willReturn(false);
        $mockResponse->method('getData')->willReturn([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'nodes' => [
                        [
                            'state' => 'FIXED',
                            'fixedAt' => '2024-01-15T10:30:00Z',
                            'dismissedAt' => null,
                            'dismissReason' => null,
                            'number' => 42,
                            'securityVulnerability' => [
                                'advisory' => [
                                    'ghsaId' => 'GHSA-1234',
                                    'summary' => 'Test advisory',
                                ],
                                'firstPatchedVersion' => ['identifier' => '1.0.1'],
                                'package' => ['name' => 'test-pkg', 'ecosystem' => 'NPM'],
                                'severity' => 'HIGH',
                                'vulnerableVersionRange' => '< 1.0.1',
                            ],
                            'vulnerableManifestFilename' => 'package-lock.json',
                            'vulnerableManifestPath' => '/package-lock.json',
                            'vulnerableRequirements' => '= 1.0.0',
                        ],
                    ],
                ],
            ],
        ]);

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->method('query')->willReturn($mockResponse);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $alerts = $client->fetchResolvedAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('FIXED', $alerts[0]['state']);
    }

    public function testFetchSecurityPullRequestsReturnsEdges(): void
    {
        $prEdge = require __DIR__ . '/../Fixtures/pr-response.php';
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('hasErrors')->willReturn(false);
        $mockResponse->method('getData')->willReturn([
            'search' => [
                'edges' => [$prEdge],
            ],
        ]);

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->method('query')->willReturn($mockResponse);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $prs = $client->fetchSecurityPullRequests();
        $this->assertCount(1, $prs);
    }

    public function testGraphQLErrorThrowsException(): void
    {
        $mockResponse = $this->createMock(Response::class);
        $mockResponse->method('hasErrors')->willReturn(true);
        $mockResponse->method('getErrors')->willReturn([
            ['message' => 'Some GraphQL error'],
        ]);

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->method('query')->willReturn($mockResponse);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GraphQL client error: Some GraphQL error');

        $client->fetchOpenAlerts();
    }
}
