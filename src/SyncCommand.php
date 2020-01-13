<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Softonic\GraphQL\ClientBuilder;

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

    protected $requiredOptions = [
        'GITHUB_REPOSITORY',
        'GH_SECURITY_TOKEN',
        'JIRA_HOST',
        'JIRA_USER',
        'JIRA_TOKEN',
        'JIRA_PROJECT',
    ];

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
    protected function configure()
    {
        $this
            ->setDescription('Sync GitHub Alert status to Jira')
            ->setHelp('This command allows you to synchronize the security status from GitHub security alerts to Jira.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do dry run (dont change anything)'
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Validate config.
        $this->validateConfig();
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        // Fetch alert data from GitHub.
        $alerts = $this->fetchAlertData();
        if (empty($alerts)) {
            $this->log($output, 'No alerts found.');
        }

        $alertsFound = [];

        // Go through each alert and create a Jira issue if one does not exist.
        foreach ($alerts as $alert) {
            $issue = new SecurityAlertIssue($alert);

            $existingKey = $issue->exists();

            if (!is_null($existingKey)) {
                $this->log($output, "Existing issue {$existingKey} covers {$issue->uniqueId()}.");
            } elseif (!$input->getOption('dry-run')) {
                $key = $issue->ensure();
                $this->log($output, "Created issue {$key} for {$issue->uniqueId()}.");
            } else {
                $this->log($output, "Would have created an issue for {$issue->uniqueId()} if not a dry run.");
            }

            $alertsFound[] = $issue->uniqueId();
        }

        $pull_requests = $this->fetchPullRequestData();

        foreach ($pull_requests as $pull_request) {
            $issue = new PullRequestIssue($pull_request['node']);

            if (in_array($issue->uniqueId(), $alertsFound)) {
                continue;
            }

            $existingKey = $issue->exists();

            if (!is_null($existingKey)) {
                $this->log($output, "Existing issue {$existingKey} covers {$issue->uniqueId()}.");
            } elseif (!$input->getOption('dry-run')) {
                $key = $issue->ensure();
                $this->log($output, "Created issue {$key} for {$issue->uniqueId()}.");
            } else {
                $this->log($output, "Would have created an issue for {$issue->uniqueId()} if not a dry run.");
            }
        }

    }

    /**
     * Fetch alert data from GitHub.
     */
    protected function fetchAlertData()
    {
        $query = <<<'GQL'
            query alerts($owner: String!, $repo: String!) {
              repository(owner: $owner, name: $repo) {
                vulnerabilityAlerts(first: 100) {
                  nodes {
                    securityVulnerability {
                      advisory {
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
                  }
                }
              }
            }
GQL;

        $repo = explode('/', getenv('GITHUB_REPOSITORY'));
        $variables = [
            'owner' => $repo[0],
            'repo' => $repo[1],
        ];

        $response = $this->getGHClient()->query($query, $variables);
        if ($response->hasErrors()) {
            $messages = array_map(function (array $error) {
                return $error['message'];
            }, $response->getErrors());

            throw new \RuntimeException(
                sprintf('GraphQL client error: %s. Original query: %s', implode(', ', $messages), $query)
            );
        }

        // Drill down to the response data we want, if there.
        $alert_data = $response->getData();
        $alerts = $alert_data['repository']['vulnerabilityAlerts']['nodes'] ?? [];

        return $alerts;
    }

    /**
     * Fetch Dependabot pull request data from GitHub.
     *
     * @return array<array<string,array<string,string>>>
     */
    protected function fetchPullRequestData(): array
    {
        $repo = \getenv('GITHUB_REPOSITORY');
        $author = 'author:app/dependabot author:app/dependabot-preview';

        $query = <<<GQL
{
  search(query: "type:pr state:open {$author} repo:{$repo} label:security", type: ISSUE, first: 100) {
    issueCount
    pageInfo {
      endCursor
      startCursor
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

        $variables = [];

        $response = $this->getGHClient()->query($query, $variables);

        if ($response->hasErrors()) {
            $messages = \array_map(static function (array $error) {
                return $error['message'];
            }, $response->getErrors());

            throw new RuntimeException(
                \sprintf('GraphQL client error: %s. Original query: %s', \implode(', ', $messages), $query),
            );
        }

        // Drill down to the response data we want, if there.
        $pr_data = $response->getData();
        $prs = $pr_data['search']['edges'] ?? [];

        return $pr_data['search']['edges'] ?? [];
    }

    /**
     * Create the GraphQL client with supplied Bearer token.
     *
     */
    protected function getGHClient()
    {

        $access_token = getenv('GH_SECURITY_TOKEN');
        $client = ClientBuilder::build('https://api.github.com/graphql', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$access_token}",
            ],
        ]);

        return $client;
    }

    /**
     * Validate the required options.
     */
    protected function validateConfig()
    {
        foreach ($this->requiredOptions as $option) {
            $var = getenv($option);
            if ($var === false || empty($var)) {
                throw new \RuntimeException("Required env variable '{$option}' not set or empty.");
            }
            if ($option == 'GITHUB_REPOSITORY') {
                if (count(explode('/', $var)) < 2) {
                    throw new \RuntimeException('GitHub repository invalid: ' . getenv('GITHUB_REPOSITORY'));
                }
            }
        }
    }

    protected function log(OutputInterface $output, string $message)
    {
        if ($output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
            return;
        }

        $timestamp = gmdate(DATE_ISO8601);
        $jira_project = getenv('JIRA_PROJECT');

        $output->writeln("{$timestamp} - {$jira_project} - {$message}");
    }

}
