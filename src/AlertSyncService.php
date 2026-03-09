<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class AlertSyncService
{
    private ?JiraTransitionService $jiraTransitionService = null;

    public function __construct(
        private readonly Config $config,
        private readonly GitHubGraphQLClient $githubClient,
        ?JiraTransitionService $jiraTransitionService = null,
    ) {
        $this->jiraTransitionService = $jiraTransitionService;
    }

    /**
     * Sync open alerts to Jira and return processed unique IDs.
     *
     * @return array<string>
     */
    public function syncOpenAlerts(OutputInterface $output, bool $dryRun): array
    {
        $alerts = $this->githubClient->fetchOpenAlerts();

        if (empty($alerts)) {
            $this->log($output, 'No alerts found.');
        }

        $alertsFound = [];

        foreach ($alerts as $alert) {
            $issue = new SecurityAlertIssue($alert, $this->config);

            $existingKey = $issue->exists();

            if (!\is_null($existingKey)) {
                $this->log($output, "Existing issue {$existingKey} covers {$issue->uniqueId()}.");
            } elseif (!$dryRun) {
                $key = $issue->ensure();
                $this->log($output, "Created issue {$key} for {$issue->uniqueId()}.");
            } else {
                $this->log($output, "Would have created an issue for {$issue->uniqueId()} if not a dry run.");
            }

            $alertsFound[] = $issue->uniqueId();
        }

        return $alertsFound;
    }

    /**
     * Sync Dependabot security pull requests, skipping any already covered by alerts.
     *
     * @param array<string> $skipIds
     */
    public function syncPullRequests(OutputInterface $output, bool $dryRun, array $skipIds): void
    {
        $pullRequests = $this->githubClient->fetchSecurityPullRequests();

        foreach ($pullRequests as $pullRequest) {
            $issue = new PullRequestIssue($pullRequest['node'], $this->config);

            if (\in_array($issue->uniqueId(), $skipIds)) {
                continue;
            }

            $existingKey = $issue->exists();

            if (!\is_null($existingKey)) {
                $this->log($output, "Existing issue {$existingKey} covers {$issue->uniqueId()}.");
            } elseif (!$dryRun) {
                $key = $issue->ensure();
                $this->log($output, "Created issue {$key} for {$issue->uniqueId()}.");
            } else {
                $this->log($output, "Would have created an issue for {$issue->uniqueId()} if not a dry run.");
            }
        }
    }

    /**
     * Find and close Jira tickets for resolved GitHub alerts.
     */
    public function closeResolvedAlerts(OutputInterface $output, bool $dryRun): void
    {
        $resolvedAlerts = $this->githubClient->fetchResolvedAlerts();

        if (empty($resolvedAlerts)) {
            $this->log($output, 'No resolved alerts found.');
            return;
        }

        $transitionService = $this->getJiraTransitionService();

        foreach ($resolvedAlerts as $alert) {
            try {
                $uniqueId = AlertIdentifier::fromAlertData($alert);
                $state = $alert['state'] ?? 'UNKNOWN';
                $package = $alert['securityVulnerability']['package']['name'] ?? 'unknown';
                $summary = $alert['securityVulnerability']['advisory']['summary'] ?? '';

                $labels = [
                    $this->config->githubRepository,
                    $uniqueId,
                ];

                $issueKeys = $transitionService->findIssuesByLabels($labels);

                if (empty($issueKeys)) {
                    continue;
                }

                $comment = $this->buildClosureComment($alert, $state, $package, $summary);

                foreach ($issueKeys as $issueKey) {
                    if ($dryRun) {
                        $this->log(
                            $output,
                            "Would close {$issueKey} (alert {$state}) if not a dry run."
                        );
                        continue;
                    }

                    try {
                        $transitionService->closeIssue($issueKey, $comment);
                        $this->log($output, "Closed {$issueKey} — alert {$state} for {$package}.");
                    } catch (Throwable $e) {
                        $this->log(
                            $output,
                            "Failed to close {$issueKey}: {$e->getMessage()}"
                        );
                    }
                }
            } catch (Throwable $e) {
                $this->log($output, "Error processing resolved alert: {$e->getMessage()}");
            }
        }
    }

    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * @param array<string,mixed> $alert
     */
    private function buildClosureComment(array $alert, string $state, string $package, string $summary): string
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        $lines = [
            "Alert resolved: *{$state}*",
            "Package: {$package}",
        ];

        if ($summary !== '') {
            $lines[] = "Advisory: {$summary}";
        }

        if ($state === 'FIXED' && !empty($alert['fixedAt'])) {
            $lines[] = "Fixed at: {$alert['fixedAt']}";
        }

        if (($state === 'DISMISSED' || $state === 'AUTO_DISMISSED') && !empty($alert['dismissedAt'])) {
            $lines[] = "Dismissed at: {$alert['dismissedAt']}";
        }

        if (!empty($alert['dismissReason'])) {
            $lines[] = "Dismiss reason: {$alert['dismissReason']}";
        }

        return \implode("\n", $lines);
    }

    private function getJiraTransitionService(): JiraTransitionService
    {
        if ($this->jiraTransitionService === null) {
            $this->jiraTransitionService = new JiraTransitionService($this->config);
        }

        return $this->jiraTransitionService;
    }

    private function log(OutputInterface $output, string $message): void
    {
        if ($output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
            return;
        }

        $timestamp = \gmdate(\DATE_ATOM);

        $output->writeln("{$timestamp} - {$this->config->jiraProject} - {$message}");
    }
}
