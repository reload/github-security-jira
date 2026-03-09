<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use RuntimeException;

class Config
{
    public readonly string $githubRepository;
    public readonly string $githubOwner;
    public readonly string $githubRepo;
    public readonly string $ghSecurityToken;
    public readonly string $githubGraphqlUrl;
    public readonly string $githubServerUrl;
    public readonly string $jiraHost;
    public readonly string $jiraUser;
    public readonly string $jiraToken;
    public readonly string $jiraProject;
    public readonly string $jiraIssueType;
    public readonly string $jiraRestrictedCommentRole;
    public readonly string $jiraCloseTransition;
    /** @var array<string> */
    public readonly array $jiraWatchers;
    /** @var array<string> */
    public readonly array $jiraIssueLabels;

    public function __construct()
    {
        $required = [
            'GITHUB_REPOSITORY',
            'GH_SECURITY_TOKEN',
            'JIRA_HOST',
            'JIRA_USER',
            'JIRA_TOKEN',
            'JIRA_PROJECT',
        ];

        foreach ($required as $var) {
            $value = \getenv($var);
            if (!\is_string($value) || $value === '') {
                throw new RuntimeException("Required env variable '{$var}' not set or empty.");
            }
        }

        $this->githubRepository = \getenv('GITHUB_REPOSITORY') ?: '';
        $parts = \explode('/', $this->githubRepository);

        if (\count($parts) < 2) {
            throw new RuntimeException(
                'GitHub repository invalid: ' . $this->githubRepository
            );
        }

        $this->githubOwner = $parts[0];
        $this->githubRepo = $parts[1];
        $this->ghSecurityToken = \getenv('GH_SECURITY_TOKEN') ?: '';
        $this->githubGraphqlUrl = \getenv('GITHUB_GRAPHQL_URL') ?: 'https://api.github.com/graphql';
        $this->githubServerUrl = \getenv('GITHUB_SERVER_URL') ?: 'https://github.com';
        $this->jiraHost = \getenv('JIRA_HOST') ?: '';
        $this->jiraUser = \getenv('JIRA_USER') ?: '';
        $this->jiraToken = \getenv('JIRA_TOKEN') ?: '';
        $this->jiraProject = \getenv('JIRA_PROJECT') ?: '';
        $this->jiraIssueType = \getenv('JIRA_ISSUE_TYPE') ?: 'Bug';
        $this->jiraRestrictedCommentRole = \getenv('JIRA_RESTRICTED_COMMENT_ROLE') ?: 'Developers';
        $this->jiraCloseTransition = \getenv('JIRA_CLOSE_TRANSITION') ?: 'Done';

        $watchers = \getenv('JIRA_WATCHERS') ?: '';
        $this->jiraWatchers = $watchers !== '' ? \array_filter(\array_map('trim', \explode(',', $watchers))) : [];

        $labels = \getenv('JIRA_ISSUE_LABELS') ?: '';
        $this->jiraIssueLabels = $labels !== '' ? \array_filter(\array_map('trim', \explode(',', $labels))) : [];
    }
}
