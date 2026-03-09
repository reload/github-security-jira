<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Transition;
use RuntimeException;

class JiraTransitionService
{
    private IssueService $issueService;

    public function __construct(
        private readonly Config $config,
        ?IssueService $issueService = null,
    ) {
        $this->issueService = $issueService ?? new IssueService(new ArrayConfiguration([
            'jiraHost' => $config->jiraHost,
            'jiraUser' => $config->jiraUser,
            'jiraPassword' => $config->jiraToken,
            'useTokenBasedAuth' => true,
        ]));
    }

    /**
     * Transition an issue to the configured close state with a comment.
     */
    public function closeIssue(string $issueKey, string $comment): void
    {
        RetryableApiCall::execute(function () use ($issueKey, $comment): void {
            $transitionId = $this->findTransitionId($issueKey, $this->config->jiraCloseTransition);

            if ($transitionId === null) {
                throw new RuntimeException(
                    "Transition '{$this->config->jiraCloseTransition}' not available for issue {$issueKey}."
                );
            }

            $transition = new Transition();
            $transition->setTransitionId($transitionId);
            $transition->setCommentBody($comment);

            $this->issueService->transition($issueKey, $transition);
        });
    }

    /**
     * Find open Jira issues matching the given labels.
     *
     * @param array<string> $labels
     * @return array<string> Issue keys
     */
    public function findIssuesByLabels(array $labels): array
    {
        /** @var array<string> */
        return RetryableApiCall::execute(function () use ($labels): array {
            $labelConditions = \array_map(
                static fn (string $label): string => \sprintf('labels = "%s"', $label),
                $labels
            );

            $jql = \sprintf(
                'project = "%s" AND %s AND status != "%s"',
                $this->config->jiraProject,
                \implode(' AND ', $labelConditions),
                $this->config->jiraCloseTransition
            );

            $result = $this->issueService->search($jql);
            $keys = [];

            foreach ($result->getIssues() as $issue) {
                $keys[] = $issue->key;
            }

            return $keys;
        });
    }

    private function findTransitionId(string $issueKey, string $transitionName): ?string
    {
        $transitions = $this->issueService->getTransition($issueKey);

        foreach ($transitions as $transition) {
            if ($transition->name === $transitionName) {
                return $transition->id;
            }
        }

        return null;
    }
}
