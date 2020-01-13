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

        $jira_host = getenv('JIRA_HOST');
        $jira_user = getenv('JIRA_USER');
        $jira_password = getenv('JIRA_TOKEN');
        $jira_project = getenv('JIRA_PROJECT');

        $github_repo = getenv('GITHUB_REPOSITORY');

        $issue_type = getenv('JIRA_ISSUE_TYPE');

        $watchers = [];
        if (is_string(getenv('JIRA_WATCHERS'))) {
            $watchers = explode("\n", getenv('JIRA_WATCHERS')) ?? [];
        }

        $res_group = getenv('JIRA_RESTRICTED_GROUP');
        $res_comment = getenv('JIRA_RESTRICTED_COMMENT');

        // Fetch alert data from GitHub.
        $alerts = $this->fetchAlertData();
        if (empty($alerts)) {
            $this->logLine($output, 'No alerts found.');
        }

        // Go through each alert and create a Jira issue if one does not exist.
        foreach ($alerts as $alert) {
            $package = $alert['securityVulnerability']['package']['name'];
            $safeVersion = $alert['securityVulnerability']['firstPatchedVersion']['identifier'];
            $vulnerableVersionRange = $alert['securityVulnerability']['vulnerableVersionRange'];

            $issue = new JiraIssue(
                $jira_host,
                $jira_user,
                $jira_password,
                $jira_project,
                $github_repo,
                $package,
                $safeVersion,
                $vulnerableVersionRange
            );

            $issue->setField('severity', $alert['securityVulnerability']['severity'] ?? '');
            $issue->setField('ecosystem', $alert['securityVulnerability']['package']['ecosystem'] ?? '');
            $issue->setField('advisory_description', $alert['securityVulnerability']['advisory']['description'] ?? '');
            $issue->setField('manifest_path', $alert['vulnerableManifestPath']);
            foreach ($alert['securityVulnerability']['advisory']['references'] as $ref) {
                if (!empty($ref['url'])) {
                    $issue->addField('references', $ref['url']);
                }
            }

            if (!empty($issue_type)) {
                $issue->setField('issue_type', $issue_type);
            }
            $issue->setField('watchers', $watchers);
            $issue->setField('restricted_group', $res_group ?? '');
            $issue->setField('restricted_comment', $res_comment ?? []);

            $timestamp = gmdate(DATE_ISO8601);
            $this->log($output, "{$timestamp} - {$jira_project} - {$package}:{$vulnerableVersionRange} - ");

            // Determine whether there is an issue for this alert already.
            try {
                $key = $issue->existingIssue();
            } catch (\Throwable $t) {
                $this->logLine($output, "ERROR ACCESSING JIRA: {$t->getMessage()}.");
                exit(1);
            }
            if ($key) {
                $this->logLine($output, "Existing issue found: {$key}.");
                continue;
            }

            // Create the Jira issue.
            if (empty($input->getOption('dry-run'))) {
                $key = $issue->create();

                // Issue creation failed. Bail out.
                if (empty($key)) {
                    $this->logLine($output, 'ERROR CREATING ISSUE.');
                    exit(1);
                }
                $this->logLine($output, "Created issue {$key}");
            } else {
                $this->logLine($output, "Would have created an issue in {$jira_project} if not a dry run.");
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

        $output->write($message);
    }

    protected function logLine(OutputInterface $output, string $message)
    {
        if ($output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
            return;
        }

        $output->writeln($message);
    }
}
