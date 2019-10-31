<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Watcher;
use JiraRestApi\JiraException;
use JiraRestApi\User\UserService;

class JiraIssue
{
    protected $issueService;
    protected $userService;

    protected $jiraHost;
    protected $jiraUser;
    protected $jiraPassword;
    protected $jiraProject;
    protected $GitHubRepo;
    protected $package;
    protected $safeVersion;
    protected $vulnerableVersionRange;

    protected $fields = ['issue_type' => 'Bug'];

    public function __construct(
        string $jira_host,
        string $jira_user,
        string $jira_password,
        string $jira_project,
        string $github_repo,
        string $package,
        string $safeVersion,
        string $vulnerableVersionRange
    ) {
        $this->jiraHost = $jira_host;
        $this->jiraUser = $jira_user;
        $this->jiraPassword = $jira_password;
        $this->jiraProject = $jira_project;
        $this->GitHubRepo = $github_repo;
        $this->package = $package;
        $this->safeVersion = $safeVersion;
        $this->vulnerableVersionRange = $vulnerableVersionRange;

        $this->issueService = new IssueService(new ArrayConfiguration([
            'jiraHost' => $this->jiraHost,
            'jiraUser' => $this->jiraUser,
            'jiraPassword' => $this->jiraPassword,
        ]));

        $this->userService = new UserService(new ArrayConfiguration([
            'jiraHost' => $this->jiraHost,
            'jiraUser' => $this->jiraUser,
            'jiraPassword' => $this->jiraPassword,
        ]));
    }

    /**
     * Create the issue in Jira and set watchers and add a restricted comment.
     *
     * @return string|null
     *   The ticket id of the created issue.
     */
    public function create(): ?string
    {
        $issueField = new IssueField();
        $issueField
            ->setProjectKey($this->jiraProject)
            ->setSummary("{$this->package} ({$this->safeVersion})")
            ->setDescription($this->getBody())
            ->setIssueType($this->getField('issue_type'))
            ->addLabel($this->GitHubRepo)
            ->addLabel($this->package)
            ->addLabel($this->getUniqueId());

        // Create issue.
        try {
            $issue = $this->issueService->create($issueField);
        } catch (\Throwable $t) {
            echo "Could not create issue {$this->project}:{$this->safeVersion}: {$t->getMessage()}" . PHP_EOL;
            return null;
        }

        // Add watchers.
        foreach ($this->getField('watchers') as $watchers_email) {
            try {
                $watchers_accountid = $this->getUserFieldFromEmail($watchers_email);
                $this->issueService->addWatcher($issue->key, $watchers_accountid);
            } catch (\Throwable $t) {
                echo "Could not add watcher {$watchers_email} to issue {$issue->key}: {$t->getMessage()}" . PHP_EOL;
            }
        }

        // Set restricted comment.
        if (!empty($this->getField('restricted_group')) && !empty($this->getField('restricted_comment'))) {
            try {
                $this->issueService->addComment($issue->key, $this->restrictedComment());
            } catch (\Throwable $t) {
                echo "Could not add comment to issue {$issue->key}: {$t->getMessage()}" . PHP_EOL;
            }
        }

        return $issue->key;
    }

    /**
     * Look up an existing issue in Jira.
     *
     * This method performs a JQL search in Jira to determine whether the
     * current issue already exists in this project. This is determined by
     * looking at the repo name and the uniqueId label.
     *
     * @return string|null
     *   The ID of the issue found or null if no issue was found.
     */
    public function existingIssue(): ?string
    {
        $jql = "PROJECT = '{$this->jiraProject}' "
            . "AND labels IN ('{$this->GitHubRepo}') "
            . "AND labels IN ('{$this->getUniqueId()}') "
            . "ORDER BY created DESC";
        $result = $this->issueService->search($jql);

        if ($result->total > 0) {
            return reset($result->issues)->key;
        }

        return null;
    }

    public function setField(string $field, $value)
    {
        $this->fields[$field] = $value;
    }

    public function getField(string $field)
    {
        return $this->fields[$field] ?? null;
    }

    public function addField(string $field, string $value)
    {
        $this->fields[$field][] = trim($value);
    }

    /**
     * Method to generate a unique id for this alert.
     *
     * It uses the package name, the manifest base path (ie not including
     * 'composer.lock' or similar), and also the target version (safe version)
     * to create a string that is safe to use as eg a Jira label.
     *
     * @return string
     */
    protected function getUniqueId(): string
    {
        $strings[] = $this->package;
        if (!empty($this->getField('manifest_path'))) {
            $manifest_base_path = pathinfo($this->getField('manifest_path'), PATHINFO_DIRNAME);
            if (!empty($manifest_base_path) && $manifest_base_path != '.') {
                $strings[] = $manifest_base_path;
            }
        }
        $strings[] = $this->safeVersion;
        return implode(':', $strings);
    }

    /**
     * Return formatted body string.
     *
     * @return string
     */
    protected function getBody(): string
    {
        $advisory_description = wordwrap($this->getField('advisory_description'), 100);
        $references = implode(', ', $this->getField('references'));
        $ecosystem = $this->getField('ecosystem') ? '(' . $this->getField('ecosystem') . ')' : '';
        return <<<EOT
- Repository: [{$this->GitHubRepo}|https://github.com/{$this->GitHubRepo}]
- Package: {$this->package} $ecosystem
- Severity: {$this->getField('severity')}
- Vulnerable version: {$this->vulnerableVersionRange}
- Secure version: {$this->safeVersion}
- Links: {$references}
{noformat}
{$advisory_description}
{noformat}
EOT;
    }

    /**
     * Return array containing formatted values for restricted comment.
     *
     * @return array
     *   The array containing settings and text for restricted comment.
     */
    protected function restrictedComment(): array
    {
        return [
            'visibility' => [
                'type' => 'role',
                'value' => $this->getField('restricted_group'),
            ],
            'body' => $this->getField('restricted_comment') . PHP_EOL
                    . $this->formatWatchers($this->getField('watchers')),
        ];
    }

    protected function formatWatchers(array $watchers): string
    {
        if (empty($watchers)) {
            return '';
        }

        $watchers_keys = array_map(function (string $watchers_email) {
            return $this->getUserFieldFromEmail($watchers_email, 'key');
        }, $watchers);
        return 'Watchers: [~' . implode('], [~', $watchers_keys) . '].';
    }

    /**
     * Helper method to lookup a user in Jira.
     *
     * @param string $email
     *   The email address to lookup.
     * @param string $user_field
     *   The user field to return. Optional. Defaults to accountId.
     *
     * @return string|null
     *   The user field value.
     */
    protected function getUserFieldFromEmail(string $email, string $user_field = 'accountId'): ?string
    {
        try {
            $paramArray = [
                'query' => $email,
                'project' => $this->jiraProject,
                'maxResults' => 1
            ];

            $users = $this->userService->findAssignableUsers($paramArray);

            if (empty($users)) {
                return null;
            }
        } catch (JiraException $e) {
            echo "ERROR: Could not query Jira with email '${email}'. " . $e->getMessage();
            return null;
        }

        $user = array_pop($users);
        return $user->$user_field;
    }
}
