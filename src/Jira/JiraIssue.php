<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Jira;

use RuntimeException;

/**
 * Base class for creating Jira issues with duplicate detection
 */
abstract class JiraIssue
{
    protected JiraV3Client $client;
    protected string $project;
    protected ?string $issueType = null;
    protected ?string $priority = null;
    protected array $labels = [];
    protected ?string $title = null;
    protected ?string $body = null;
    protected array $keyLabels = [];

    public function __construct()
    {
        // Initialize from environment
        $host = getenv('JIRA_HOST');
        $email = getenv('JIRA_USER');
        $token = getenv('JIRA_TOKEN');
        $this->project = getenv('JIRA_PROJECT') ?: '';

        if (!is_string($host) || !is_string($email) || !is_string($token) || !$this->project) {
            throw new RuntimeException('Missing required Jira configuration');
        }

        $this->client = new JiraV3Client($host, $email, $token);
        $this->issueType = getenv('JIRA_ISSUE_TYPE') ?: 'Bug';
        $priorityEnv = getenv('JIRA_ISSUE_PRIORITY');
        $this->priority = is_string($priorityEnv) ? $priorityEnv : null;

        // Add custom labels from environment
        $customLabels = getenv('JIRA_ISSUE_LABELS');
        if (is_string($customLabels)) {
            $this->labels = array_merge(
                $this->labels,
                array_map('trim', explode(',', $customLabels))
            );
        }

        // Add repository as label
        $repo = getenv('GITHUB_REPOSITORY');
        if (is_string($repo)) {
            $this->labels[] = str_replace('/', '-', $repo);
        }
    }

    /**
     * Set the issue title/summary
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Set the issue body/description
     */
    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Set a key label used for duplicate detection and general labeling
     * This maintains compatibility with the old API where setKeyLabel was called multiple times
     */
    public function setKeyLabel(string $label): self
    {
        $this->keyLabels[] = $label;
        $this->labels[] = $label;
        return $this;
    }

    /**
     * Get unique identifier for this issue
     */
    abstract public function uniqueId(): string;

    /**
     * Check if issue already exists using any of the key labels
     * Returns the issue key if found, null otherwise
     */
    public function exists(): ?string
    {
        if (empty($this->keyLabels)) {
            throw new RuntimeException('Key label not set');
        }

        // Try to find issue by searching for all key labels
        // We'll search for issues that have ALL the key labels
        $labelConditions = array_map(function ($label) {
            return sprintf('labels = "%s"', addslashes($label));
        }, $this->keyLabels);

        $jql = sprintf(
            'project = "%s" AND %s',
            addslashes($this->project),
            implode(' AND ', $labelConditions)
        );

        try {
            $result = $this->client->searchIssues($jql, 0, 1, ['key']);

            if (isset($result->issues) && count($result->issues) > 0) {
                return $result->issues[0]->key;
            }
        } catch (\Throwable $e) {
            // Log error but don't fail
            error_log("Error searching for issue: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Ensure issue exists, create if needed
     */
    public function ensure(): string
    {
        // Check if already exists
        $existingKey = $this->exists();
        if ($existingKey !== null) {
            return $existingKey;
        }

        // Validate required fields
        if (!$this->title || !$this->body) {
            throw new RuntimeException('Title and body are required');
        }

        // Convert body from Wiki Markup to ADF format
        $description = AdfBuilder::fromWikiMarkup($this->body);

        // Build issue fields
        $fields = [
            'project' => ['key' => $this->project],
            'issuetype' => ['name' => $this->issueType],
            'summary' => $this->title,
            'description' => $description,
            'labels' => array_values(array_unique($this->labels)),
        ];

        if ($this->priority) {
            $fields['priority'] = ['name' => $this->priority];
        }

        // Create issue
        $result = $this->client->createIssue($fields);
        $issueKey = $result->key;

        // Add watchers if configured
        $this->addWatchers($issueKey);

        return $issueKey;
    }

    /**
     * Add watchers to the issue
     */
    protected function addWatchers(string $issueKey): void
    {
        $watchers = getenv('JIRA_WATCHERS');
        if (!is_string($watchers) || empty($watchers)) {
            return;
        }

        $watcherEmails = array_map('trim', explode(',', $watchers));
        $accountIds = [];
        $notFound = [];

        // Find account IDs for each watcher
        foreach ($watcherEmails as $email) {
            try {
                $users = $this->client->findUsers($email);
                if (!empty($users)) {
                    $user = is_array($users) ? $users[0] : $users;
                    if (is_object($user) && isset($user->accountId)) {
                        $accountIds[] = $user->accountId;
                    } else {
                        $notFound[] = $email;
                    }
                } else {
                    $notFound[] = $email;
                }
            } catch (\Throwable $e) {
                error_log("Error finding user {$email}: " . $e->getMessage());
                $notFound[] = $email;
            }
        }

        // Add each watcher
        foreach ($accountIds as $accountId) {
            try {
                $this->client->addWatcher($issueKey, $accountId);
            } catch (\Throwable $e) {
                error_log("Error adding watcher {$accountId}: " . $e->getMessage());
            }
        }

        // Add comment about watchers
        $this->addWatcherComment($issueKey, $accountIds, $notFound);
    }

    /**
     * Add a comment about watchers
     */
    protected function addWatcherComment(
        string $issueKey,
        array $accountIds,
        array $notFound
    ): void {
        $restrictedRole = getenv('JIRA_RESTRICTED_COMMENT_ROLE');
        $customComment = getenv('JIRA_RESTRICTED_COMMENT');

        // Build comment text
        if (!empty($accountIds)) {
            $text = sprintf(
                "This issue is being followed by %d watcher(s).",
                count($accountIds)
            );
        } else {
            $text = "No watchers on this issue, remember to notify relevant people.";
        }

        if (!empty($notFound)) {
            $text .= "\n\nCould not find users: " . implode(', ', $notFound);
        }

        if (is_string($customComment) && !empty($customComment)) {
            $text = $customComment;
        }

        // Build comment body in ADF
        $body = AdfBuilder::fromWikiMarkup($text);

        $comment = ['body' => $body];

        // Add visibility restriction if specified
        if (is_string($restrictedRole) && !empty($restrictedRole)) {
            $comment['visibility'] = [
                'type' => 'role',
                'value' => $restrictedRole,
            ];
        }

        try {
            $this->client->addComment($issueKey, $comment);
        } catch (\Throwable $e) {
            error_log("Error adding comment: " . $e->getMessage());
        }
    }
}
