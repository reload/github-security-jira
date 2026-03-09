<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use RuntimeException;
use Softonic\GraphQL\Client as GraphQLClient;
use Softonic\GraphQL\ClientBuilder;

class GitHubGraphQLClient
{
    private GraphQLClient $client;

    public function __construct(
        private readonly Config $config,
        ?GraphQLClient $client = null,
    ) {
        $this->client = $client ?? ClientBuilder::build($config->githubGraphqlUrl, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$config->ghSecurityToken}",
            ],
        ]);
    }

    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * Fetch open vulnerability alerts with pagination.
     *
     * @return array<array<string,mixed>>
     */
    public function fetchOpenAlerts(): array
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        return $this->fetchAlertsByStates('[OPEN]');
    }

    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * Fetch resolved vulnerability alerts with pagination.
     *
     * @return array<array<string,mixed>>
     */
    public function fetchResolvedAlerts(): array
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        return $this->fetchAlertsByStates('[FIXED, DISMISSED, AUTO_DISMISSED]');
    }

    /**
     * Fetch Dependabot security pull requests with pagination.
     *
     * @return array<array<string,array<string,string>>>
     */
    public function fetchSecurityPullRequests(): array
    {
        $repo = $this->config->githubRepository;
        $author = 'author:app/dependabot author:app/dependabot-preview';
        $allEdges = [];
        $cursor = null;

        do {
            $afterClause = $cursor !== null ? \sprintf(', after: "%s"', $cursor) : '';

            $query = <<<GQL
{
  search(query: "type:pr state:open {$author} repo:{$repo} label:security", type: ISSUE, first: 100{$afterClause}) {
    issueCount
    pageInfo {
      hasNextPage
      endCursor
    }
    edges {
      node {
        ... on PullRequest {
          number
          title
          url
        }
      }
    }
  }
}
GQL;

            $data = $this->executeQuery($query, []);
            $edges = $data['search']['edges'] ?? [];
            $allEdges = \array_merge($allEdges, $edges);

            $hasNextPage = $data['search']['pageInfo']['hasNextPage'] ?? false;
            $cursor = $data['search']['pageInfo']['endCursor'] ?? null;
        } while ($hasNextPage && $cursor !== null);

        return $allEdges;
    }

    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * Fetch alerts by state(s) with cursor-based pagination.
     *
     * @return array<array<string,mixed>>
     */
    private function fetchAlertsByStates(string $statesClause): array
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        $allNodes = [];
        $cursor = null;

        $resolvedFields = '';
        if ($statesClause !== '[OPEN]') {
            $resolvedFields = <<<'GQL'
                    state
                    dismissedAt
                    fixedAt
                    dismissReason
GQL;
        }

        do {
            $afterClause = $cursor !== null ? \sprintf(', after: "%s"', $cursor) : '';

            $query = <<<GQL
            query alerts(\$owner: String!, \$repo: String!) {
              repository(owner: \$owner, name: \$repo) {
                vulnerabilityAlerts(first: 100, states: {$statesClause}{$afterClause}) {
                  pageInfo {
                    hasNextPage
                    endCursor
                  }
                  nodes {
{$resolvedFields}
                    securityVulnerability {
                      advisory {
                        ghsaId
                        description
                        identifiers {
                          type
                          value
                        }
                        references {
                          url
                        }
                        severity
                        summary
                      }
                      firstPatchedVersion {
                        identifier
                      }
                      package {
                        name
                        ecosystem
                      }
                      severity
                      updatedAt
                      vulnerableVersionRange
                    }
                    repository {
                      nameWithOwner
                    }
                    vulnerableManifestFilename
                    vulnerableManifestPath
                    vulnerableRequirements
                    number
                  }
                }
              }
            }
GQL;

            $variables = [
                'owner' => $this->config->githubOwner,
                'repo' => $this->config->githubRepo,
            ];

            $data = $this->executeQuery($query, $variables);

            $nodes = $data['repository']['vulnerabilityAlerts']['nodes'] ?? [];
            $allNodes = \array_merge($allNodes, $nodes);

            $hasNextPage = $data['repository']['vulnerabilityAlerts']['pageInfo']['hasNextPage'] ?? false;
            $cursor = $data['repository']['vulnerabilityAlerts']['pageInfo']['endCursor'] ?? null;
        } while ($hasNextPage && $cursor !== null);

        return $allNodes;
    }

    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * Execute a GraphQL query and return the data.
     *
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    private function executeQuery(string $query, array $variables): array
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        /** @var array<string,mixed> */
        return RetryableApiCall::execute(function () use ($query, $variables): array {
            $response = $this->client->query($query, $variables);

            if ($response->hasErrors()) {
                $messages = \array_map(static function (array $error) {
                    return $error['message'];
                }, $response->getErrors());

                throw new RuntimeException(
                    \sprintf('GraphQL client error: %s. Original query: %s', \implode(', ', $messages), $query),
                );
            }

            return $response->getData();
        });
    }
}
