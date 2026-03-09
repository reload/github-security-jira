<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Unit;

use GitHubSecurityJira\Config;
use GitHubSecurityJira\GitHubGraphQLClient;
use PHPUnit\Framework\TestCase;
use Softonic\GraphQL\Client as GraphQLClient;
use Softonic\GraphQL\Response;

class PaginationTest extends TestCase
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

    /**
     * @param array<string,mixed> $data
     */
    private function mockResponse(array $data): Response
    {
        $mock = $this->createMock(Response::class);
        $mock->method('hasErrors')->willReturn(false);
        $mock->method('getData')->willReturn($data);
        return $mock;
    }

    /**
     * @return array<string,mixed>
     */
    private function makeAlertNode(string $packageName): array
    {
        $data = require __DIR__ . '/../Fixtures/alert-response.php';
        $data['securityVulnerability']['package']['name'] = $packageName;
        return $data;
    }

    public function testFetchOpenAlertsPaginatesMultiplePages(): void
    {
        $page1 = $this->mockResponse([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor1'],
                    'nodes' => [$this->makeAlertNode('lodash')],
                ],
            ],
        ]);

        $page2 = $this->mockResponse([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cursor2'],
                    'nodes' => [$this->makeAlertNode('axios')],
                ],
            ],
        ]);

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($page1, $page2);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $alerts = $client->fetchOpenAlerts();

        $this->assertCount(2, $alerts);
        $this->assertSame('lodash', $alerts[0]['securityVulnerability']['package']['name']);
        $this->assertSame('axios', $alerts[1]['securityVulnerability']['package']['name']);
    }

    public function testFetchResolvedAlertsPaginates(): void
    {
        $page1 = $this->mockResponse([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1'],
                    'nodes' => [
                        ['state' => 'FIXED', 'number' => 1] + $this->makeAlertNode('pkg-a'),
                    ],
                ],
            ],
        ]);

        $page2 = $this->mockResponse([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'c2'],
                    'nodes' => [
                        ['state' => 'DISMISSED', 'number' => 2] + $this->makeAlertNode('pkg-b'),
                    ],
                ],
            ],
        ]);

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($page1, $page2);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $alerts = $client->fetchResolvedAlerts();
        $this->assertCount(2, $alerts);
    }

    public function testFetchSecurityPullRequestsPaginates(): void
    {
        $pr1 = require __DIR__ . '/../Fixtures/pr-response.php';
        $pr2 = $pr1;
        $pr2['node']['number'] = '102';
        $pr2['node']['title'] = 'Bump axios from 0.21.0 to 0.21.1';

        $page1 = $this->mockResponse([
            'search' => [
                'issueCount' => 2,
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'pr_c1'],
                'edges' => [$pr1],
            ],
        ]);

        $page2 = $this->mockResponse([
            'search' => [
                'issueCount' => 2,
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'pr_c2'],
                'edges' => [$pr2],
            ],
        ]);

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($page1, $page2);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $prs = $client->fetchSecurityPullRequests();
        $this->assertCount(2, $prs);
    }

    public function testSinglePageDoesNotMakeExtraRequests(): void
    {
        $response = $this->mockResponse([
            'repository' => [
                'vulnerabilityAlerts' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'nodes' => [$this->makeAlertNode('lodash')],
                ],
            ],
        ]);

        $mockClient = $this->createMock(GraphQLClient::class);
        $mockClient->expects($this->once())
            ->method('query')
            ->willReturn($response);

        $config = new Config();
        $client = new GitHubGraphQLClient($config, $mockClient);

        $alerts = $client->fetchOpenAlerts();
        $this->assertCount(1, $alerts);
    }
}
