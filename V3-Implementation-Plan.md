# Jira API v3 Client Implementation Plan

## Executive Summary

Replace the `lesstif/php-jira-rest-client` (v2 API) dependency with a custom minimal Jira v3 client that supports only the operations needed for syncing GitHub security alerts to Jira.

**Goal:** Create Jira tickets for GitHub code scanning and Dependabot alerts using Jira REST API v3.

**Current Stack:**
- `reload/github-security-jira` (this repo) - Main action
- `reload/jira-security-issue` (v2.0.11) - Wrapper for Jira operations
- `lesstif/php-jira-rest-client` (v5.11.0) - **DEPRECATED v2 API client**

**Strategy:** Replace the bottom two layers with a single, minimal v3 client.

---

## Part 1: Understanding Current Implementation

### Current Architecture

```
┌─────────────────────────────────────┐
│  GitHub Actions Workflow            │
│  (your workflow.yml)                │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  SyncCommand.php                    │
│  - Fetches alerts from GitHub       │
│  - Creates Issue objects            │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  SecurityAlertIssue.php             │
│  PullRequestIssue.php               │
│  (extends JiraSecurityIssue)        │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  reload/jira-security-issue         │
│  JiraSecurityIssue class            │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  lesstif/php-jira-rest-client       │
│  Uses Jira REST API v2 ❌          │
└─────────────────────────────────────┘
```

### What JiraSecurityIssue Does

From analyzing the source code, here are the critical operations:

#### 1. **Issue Creation** (`ensure()` method)
```php
public function ensure(): string
{
    // Check if issue exists
    if ($key = $this->exists()) {
        return $key;
    }

    // Create IssueField object
    $issueField = new IssueField();
    $issueField->setProjectKey($this->jiraProject)
               ->setIssueType(['name' => $issueType])
               ->setSummary($this->title)
               ->setDescription($this->body)
               ->setLabels($labels);

    if ($priority) {
        $issueField->setPriority(['name' => $priority]);
    }

    // Create issue via API
    $issueService = new IssueService($this->config);
    $ret = $issueService->create($issueField);

    // Add watchers and comments
    $this->addWatchers($ret->key);

    return $ret->key;
}
```

#### 2. **Duplicate Detection** (`exists()` method)
```php
protected function exists(): ?string
{
    // Search for issues with matching label
    $jql = sprintf(
        'project = "%s" AND labels = "%s"',
        $this->jiraProject,
        $this->keyLabel
    );

    $issueService = new IssueService($this->config);
    $ret = $issueService->search($jql, 0, 1, ['key']);

    return $ret->getIssues()[0]->key ?? null;
}
```

#### 3. **Add Watchers** (optional)
```php
protected function addWatchers(string $key): void
{
    // Find user accounts
    $usernames = [];
    foreach ($watchers as $email) {
        $user = $this->findUser($email);
        $usernames[] = $user->accountId;
    }

    // Add watchers to issue
    $issueService = new IssueService($this->config);
    foreach ($usernames as $username) {
        $issueService->addWatcher($key, $username);
    }

    // Add comment about watchers
    $comment = new Comment();
    $comment->setBody($commentText);
    if ($role) {
        $visibility = new Visibility();
        $visibility->setType('role')->setValue($role);
        $comment->setVisibility($visibility);
    }
    $issueService->addComment($key, $comment);
}
```

#### 4. **Find User by Email**
```php
protected function findUser(string $email): object
{
    $userService = new UserService($this->config);
    $users = $userService->findUsers(['query' => $email]);
    return $users[0];
}
```

### Required Jira API v3 Endpoints

| Operation | v2 Endpoint (deprecated) | v3 Endpoint | HTTP Method |
|-----------|-------------------------|-------------|-------------|
| Search Issues | `/rest/api/2/search` | `/rest/api/3/search` | POST |
| Create Issue | `/rest/api/2/issue` | `/rest/api/3/issue` | POST |
| Add Watcher | `/rest/api/2/issue/{key}/watchers` | `/rest/api/3/issue/{key}/watchers` | POST |
| Add Comment | `/rest/api/2/issue/{key}/comment` | `/rest/api/3/issue/{key}/comment` | POST |
| Find Users | `/rest/api/2/user/search` | `/rest/api/3/user/search` | GET |

---

## Part 2: Key Differences Between v2 and v3

### 1. Description Format (CRITICAL)

**v2 accepts:**
- Plain text
- Jira Wiki Markup
- HTML (limited)

**v3 requires:**
- Atlassian Document Format (ADF) - structured JSON

**Current code uses Wiki Markup:**
```php
// From SecurityAlertIssue.php
$body = <<<EOD
h2. {$this->repository}

* Package: {$package}
* Vulnerable version: {$vulnerable}
* Secure version: {$safe}
* [Link to GitHub Advisory|{$advisoryUrl}]
EOD;
```

**Must convert to ADF:**
```json
{
  "type": "doc",
  "version": 1,
  "content": [
    {
      "type": "heading",
      "attrs": { "level": 2 },
      "content": [{ "type": "text", "text": "Repository Name" }]
    },
    {
      "type": "bulletList",
      "content": [
        {
          "type": "listItem",
          "content": [
            {
              "type": "paragraph",
              "content": [{ "type": "text", "text": "Package: foo" }]
            }
          ]
        }
      ]
    }
  ]
}
```

### 2. User Identification

**v2:** Username or email
**v3:** Account ID (for Cloud) or user key (for Server)

For Atlassian Cloud (your use case), you MUST use `accountId`.

### 3. Field Schema

Most fields are compatible, but some differences:
- `issuetype` structure slightly different
- Priority format same
- Labels format same (array of strings)

---

## Part 3: Implementation Strategy

### Option A: Fork and Patch (QUICKEST - NOT RECOMMENDED LONG-TERM)

**Pros:**
- Minimal code changes
- Fastest to implement
- Uses existing patterns

**Cons:**
- Depends on unmaintained package
- Patch may break with updates
- Still carries v2 technical debt

**Steps:**
1. Create comprehensive patch for `lesstif/php-jira-rest-client`
2. Test thoroughly
3. Apply via composer patches

**Not recommended** because the package is explicitly v2-only and the description format change is complex.

---

### Option B: Create Minimal v3 Client (RECOMMENDED)

**Pros:**
- Clean, maintainable code
- Only implements what you need
- No external dependencies for Jira
- Full control over v3 migration
- Can reuse SecurityAlertIssue/PullRequestIssue structure

**Cons:**
- More initial work
- Need to implement HTTP client
- Need to implement ADF conversion

**Steps:** (Detailed below)

---

## Part 4: Detailed Implementation Plan (Option B)

### Phase 1: Create Base Jira v3 Client

**File:** `src/Jira/JiraV3Client.php`

```php
<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Jira;

use RuntimeException;

class JiraV3Client
{
    private string $host;
    private string $email;
    private string $token;
    private string $baseUrl;

    public function __construct(string $host, string $email, string $token)
    {
        $this->host = rtrim($host, '/');
        $this->email = $email;
        $this->token = $token;
        $this->baseUrl = $this->host . '/rest/api/3';
    }

    /**
     * Make authenticated HTTP request to Jira API
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $body = null
    ): object {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Authorization: Basic ' . base64_encode($this->email . ':' . $this->token),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("CURL error: {$error}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException(
                "Jira API error (HTTP {$httpCode}): {$response}"
            );
        }

        return json_decode($response);
    }

    /**
     * Search for issues using JQL
     */
    public function searchIssues(
        string $jql,
        int $startAt = 0,
        int $maxResults = 50,
        array $fields = ['key']
    ): object {
        $body = [
            'jql' => $jql,
            'startAt' => $startAt,
            'maxResults' => $maxResults,
            'fields' => $fields,
        ];

        return $this->request('POST', '/search', $body);
    }

    /**
     * Create a new issue
     */
    public function createIssue(array $fields): object
    {
        $body = ['fields' => $fields];
        return $this->request('POST', '/issue', $body);
    }

    /**
     * Add a watcher to an issue
     */
    public function addWatcher(string $issueKey, string $accountId): void
    {
        $this->request('POST', "/issue/{$issueKey}/watchers", $accountId);
    }

    /**
     * Add a comment to an issue
     */
    public function addComment(string $issueKey, array $comment): object
    {
        return $this->request('POST', "/issue/{$issueKey}/comment", $comment);
    }

    /**
     * Find users by query (email)
     */
    public function findUsers(string $query): array
    {
        $endpoint = '/user/search?' . http_build_query(['query' => $query]);
        $result = $this->request('GET', $endpoint);
        return is_array($result) ? $result : [];
    }
}
```

### Phase 2: Create ADF (Atlassian Document Format) Helper

**File:** `src/Jira/AdfBuilder.php`

This converts simple markup to ADF format.

```php
<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Jira;

/**
 * Build Atlassian Document Format (ADF) for Jira v3 API
 */
class AdfBuilder
{
    private array $content = [];

    public function __construct()
    {
        // Initialize empty document
    }

    /**
     * Add a heading
     */
    public function addHeading(string $text, int $level = 2): self
    {
        $this->content[] = [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
        return $this;
    }

    /**
     * Add a paragraph
     */
    public function addParagraph(string $text): self
    {
        $this->content[] = [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
        return $this;
    }

    /**
     * Add a bullet list
     */
    public function addBulletList(array $items): self
    {
        $listItems = array_map(function ($item) {
            return [
                'type' => 'listItem',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => $item],
                        ],
                    ],
                ],
            ];
        }, $items);

        $this->content[] = [
            'type' => 'bulletList',
            'content' => $listItems,
        ];
        return $this;
    }

    /**
     * Add a link
     */
    public function addLink(string $text, string $url): self
    {
        $this->content[] = [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => ['href' => $url],
                        ],
                    ],
                ],
            ],
        ];
        return $this;
    }

    /**
     * Add a code block
     */
    public function addCodeBlock(string $code, string $language = 'text'): self
    {
        $this->content[] = [
            'type' => 'codeBlock',
            'attrs' => ['language' => $language],
            'content' => [
                ['type' => 'text', 'text' => $code],
            ],
        ];
        return $this;
    }

    /**
     * Build the final ADF document
     */
    public function build(): array
    {
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $this->content,
        ];
    }

    /**
     * Create ADF from simple text with minimal formatting
     */
    public static function fromSimpleText(string $text): array
    {
        $builder = new self();

        $lines = explode("\n", $text);
        $currentList = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                // Empty line - flush current list
                if (!empty($currentList)) {
                    $builder->addBulletList($currentList);
                    $currentList = [];
                }
                continue;
            }

            // Check for heading (starts with h1. h2. etc in Jira wiki markup)
            if (preg_match('/^h(\d)\.\s*(.+)$/', $line, $matches)) {
                // Flush current list
                if (!empty($currentList)) {
                    $builder->addBulletList($currentList);
                    $currentList = [];
                }
                $builder->addHeading($matches[2], (int) $matches[1]);
                continue;
            }

            // Check for bullet point
            if (preg_match('/^\*\s+(.+)$/', $line, $matches)) {
                $currentList[] = $matches[1];
                continue;
            }

            // Regular paragraph
            if (!empty($currentList)) {
                $builder->addBulletList($currentList);
                $currentList = [];
            }
            $builder->addParagraph($line);
        }

        // Flush remaining list
        if (!empty($currentList)) {
            $builder->addBulletList($currentList);
        }

        return $builder->build();
    }
}
```

### Phase 3: Create New JiraSecurityIssue Base Class

**File:** `src/Jira/JiraIssue.php`

This replaces `reload/jira-security-issue` with v3-compatible version.

```php
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
    protected ?string $keyLabel = null;

    public function __construct()
    {
        // Initialize from environment
        $host = getenv('JIRA_HOST');
        $email = getenv('JIRA_USER');
        $token = getenv('JIRA_TOKEN');
        $this->project = getenv('JIRA_PROJECT') ?: '';

        if (!$host || !$email || !$token || !$this->project) {
            throw new RuntimeException('Missing required Jira configuration');
        }

        $this->client = new JiraV3Client($host, $email, $token);
        $this->issueType = getenv('JIRA_ISSUE_TYPE') ?: 'Bug';
        $this->priority = getenv('JIRA_ISSUE_PRIORITY') ?: null;

        // Add custom labels from environment
        $customLabels = getenv('JIRA_ISSUE_LABELS');
        if ($customLabels) {
            $this->labels = array_merge(
                $this->labels,
                array_map('trim', explode(',', $customLabels))
            );
        }

        // Add repository as label
        $repo = getenv('GITHUB_REPOSITORY');
        if ($repo) {
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
     * Set the key label used for duplicate detection
     */
    public function setKeyLabel(string $label): self
    {
        $this->keyLabel = $label;
        $this->labels[] = $label;
        return $this;
    }

    /**
     * Get unique identifier for this issue
     */
    abstract public function uniqueId(): string;

    /**
     * Check if issue already exists
     */
    public function exists(): ?string
    {
        if (!$this->keyLabel) {
            throw new RuntimeException('Key label not set');
        }

        $jql = sprintf(
            'project = "%s" AND labels = "%s"',
            $this->project,
            $this->keyLabel
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
        if ($existingKey) {
            return $existingKey;
        }

        // Validate required fields
        if (!$this->title || !$this->body) {
            throw new RuntimeException('Title and body are required');
        }

        // Convert body to ADF format
        $description = AdfBuilder::fromSimpleText($this->body);

        // Build issue fields
        $fields = [
            'project' => ['key' => $this->project],
            'issuetype' => ['name' => $this->issueType],
            'summary' => $this->title,
            'description' => $description,
            'labels' => array_unique($this->labels),
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
        if (!$watchers) {
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
                    $accountIds[] = $users[0]->accountId;
                } else {
                    $notFound[] = $email;
                }
            } catch (\Throwable $e) {
                $notFound[] = $email;
            }
        }

        // Add each watcher
        foreach ($accountIds as $accountId) {
            try {
                $this->client->addWatcher($issueKey, $accountId);
            } catch (\Throwable $e) {
                error_log("Error adding watcher: " . $e->getMessage());
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

        if ($customComment) {
            $text = $customComment;
        }

        // Build comment body in ADF
        $body = AdfBuilder::fromSimpleText($text);

        $comment = ['body' => $body];

        // Add visibility restriction if specified
        if ($restrictedRole) {
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
```

### Phase 4: Update SecurityAlertIssue and PullRequestIssue

**File:** `src/SecurityAlertIssue.php`

Update to extend new base class:

```php
<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use GitHubSecurityJira\Jira\JiraIssue;

/**
 * Jira issue for GitHub security vulnerability alerts
 */
class SecurityAlertIssue extends JiraIssue
{
    // ... keep existing properties and constructor ...

    // Change parent class from Reload\JiraSecurityIssue to JiraIssue
    // Remove any methods that are now in parent class
    // Keep uniqueId() as it's abstract in parent
}
```

**File:** `src/PullRequestIssue.php`

Similar updates:

```php
<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use GitHubSecurityJira\Jira\JiraIssue;

/**
 * Jira issue for Dependabot pull requests
 */
class PullRequestIssue extends JiraIssue
{
    // ... keep existing implementation ...
    // Update parent class reference
}
```

### Phase 5: Update composer.json

Remove old dependencies, no need for patches:

```json
{
    "name": "reload/github-security-jira",
    "description": "Create Jira tickets for GitHub security alerts",
    "license": "MIT",
    "require": {
        "php": ">=8.3",
        "softonic/graphql-client": "^2.1",
        "symfony/console": "^5",
        "symfony/yaml": "^6.1"
    },
    "autoload": {
        "psr-4": {
            "GitHubSecurityJira\\": "src/"
        }
    },
    "require-dev": {
        "phpstan/phpstan": "^1",
        "squizlabs/php_codesniffer": "^4.0",
        "phpstan/extension-installer": "^1.4",
        "phpstan/phpstan-deprecation-rules": "^1.2"
    }
}
```

---

## Part 5: Implementation Checklist

### Step 1: Create New Files
- [ ] Create `src/Jira/` directory
- [ ] Create `src/Jira/JiraV3Client.php`
- [ ] Create `src/Jira/AdfBuilder.php`
- [ ] Create `src/Jira/JiraIssue.php`

### Step 2: Update Existing Files
- [ ] Update `src/SecurityAlertIssue.php` to extend new `JiraIssue`
- [ ] Update `src/PullRequestIssue.php` to extend new `JiraIssue`
- [ ] Update `composer.json` to remove old dependencies
- [ ] Remove `patches/` directory (no longer needed)

### Step 3: Test Locally
- [ ] Run `composer install`
- [ ] Set up test environment variables
- [ ] Test with `--dry-run` flag first
- [ ] Test issue creation
- [ ] Test duplicate detection
- [ ] Test watcher functionality
- [ ] Verify ADF formatting in Jira

### Step 4: Test in GitHub Actions
- [ ] Update workflow to use your fork
- [ ] Test with code scanning alerts
- [ ] Test with dependabot alerts
- [ ] Verify no HTTP 410 errors
- [ ] Check created tickets in Jira

### Step 5: Refinement
- [ ] Add error handling for edge cases
- [ ] Add logging/debugging output
- [ ] Handle rate limiting if needed
- [ ] Add retry logic for transient failures
- [ ] Update documentation

---

## Part 6: Testing Strategy

### Unit Tests

Create tests for:
1. `AdfBuilder` - Test ADF generation
2. `JiraV3Client` - Test API calls (mock responses)
3. `JiraIssue` - Test duplicate detection logic

### Integration Tests

1. **Test against real Jira instance:**
   - Create a test project in your Jira
   - Run sync with test GitHub repo
   - Verify tickets created correctly

2. **Test scenarios:**
   - New alert → Creates ticket
   - Duplicate alert → Finds existing ticket
   - Watchers → Added correctly
   - Labels → Applied correctly
   - Description → Formatted correctly in ADF

### Manual Verification

1. Check Jira ticket contains:
   - Correct title
   - Formatted description (not raw JSON)
   - All labels
   - Links work
   - Watchers added
   - Comments visible to correct role

---

## Part 7: ADF Conversion Examples

### Example 1: Security Alert Description

**Current (Wiki Markup):**
```
h2. missionwired/repo-name

* Package: lodash
* Vulnerable version: 4.17.15
* Secure version: 4.17.21
* GHSA ID: GHSA-xxxx-yyyy-zzzz
* [View on GitHub|https://github.com/...]
```

**New (ADF via code):**
```php
$adf = new AdfBuilder();
$adf->addHeading('missionwired/repo-name', 2)
    ->addBulletList([
        'Package: lodash',
        'Vulnerable version: 4.17.15',
        'Secure version: 4.17.21',
        'GHSA ID: GHSA-xxxx-yyyy-zzzz',
    ])
    ->addLink('View on GitHub', 'https://github.com/...')
    ->build();
```

**Or use simple converter:**
```php
$text = <<<EOD
h2. missionwired/repo-name

* Package: lodash
* Vulnerable version: 4.17.15
* Secure version: 4.17.21

View on GitHub: https://github.com/...
EOD;

$adf = AdfBuilder::fromSimpleText($text);
```

---

## Part 8: Rollback Plan

If v3 client has issues:

### Quick Fix
1. Revert to previous commit
2. Re-add old dependencies
3. Use patch approach temporarily

### Debug v3 Issues
1. Check Jira API responses for error details
2. Verify ADF format using Jira's ADF validator
3. Test individual API endpoints with curl
4. Check authentication (email + token format)

---

## Part 9: Migration Timeline

### Phase 1 (Day 1): Setup and Core Client
- [ ] Create directory structure
- [ ] Implement `JiraV3Client.php`
- [ ] Test basic connectivity and auth
- [ ] Estimated: 2-4 hours

### Phase 2 (Day 1-2): ADF Support
- [ ] Implement `AdfBuilder.php`
- [ ] Test ADF generation
- [ ] Verify in Jira UI
- [ ] Estimated: 3-5 hours

### Phase 3 (Day 2): Base Issue Class
- [ ] Implement `JiraIssue.php`
- [ ] Test duplicate detection
- [ ] Test issue creation
- [ ] Estimated: 3-4 hours

### Phase 4 (Day 2-3): Update Child Classes
- [ ] Update `SecurityAlertIssue.php`
- [ ] Update `PullRequestIssue.php`
- [ ] Update `composer.json`
- [ ] Estimated: 2-3 hours

### Phase 5 (Day 3): Testing
- [ ] Local testing
- [ ] GitHub Actions testing
- [ ] Fix issues
- [ ] Estimated: 4-6 hours

**Total Estimated Time: 14-22 hours** (spread over 3 days)

---

## Part 10: Success Criteria

✅ **The migration is successful when:**

1. No dependencies on `lesstif/php-jira-rest-client`
2. No dependencies on `reload/jira-security-issue`
3. No HTTP 410 errors from Jira API
4. Tickets created successfully in Jira
5. Descriptions formatted correctly (readable, not JSON)
6. Duplicate detection works (no duplicate tickets)
7. Labels applied correctly
8. Watchers added successfully
9. GitHub Actions workflow runs without errors
10. All existing features still work

---

## Part 11: Future Enhancements

After successful migration:

1. **Add Issue Updates**
   - Update existing tickets when alert status changes
   - Close tickets when vulnerabilities are fixed

2. **Better Error Handling**
   - Retry logic for transient failures
   - Better error messages
   - Slack/email notifications on failure

3. **Rich ADF Formatting**
   - Add severity badges
   - Use panels for important info
   - Add tables for version comparisons

4. **Performance Optimization**
   - Batch API calls where possible
   - Cache user lookups
   - Parallel processing of alerts

5. **Monitoring & Metrics**
   - Track sync success rate
   - Monitor API usage
   - Alert on failures

---

## Resources

### Jira API v3 Documentation
- [REST API v3 Overview](https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/)
- [Create Issue](https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issues/#api-rest-api-3-issue-post)
- [Search for Issues](https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issue-search/#api-rest-api-3-search-post)
- [Add Watcher](https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issue-watchers/#api-rest-api-3-issue-issueidorkey-watchers-post)

### ADF Documentation
- [Atlassian Document Format](https://developer.atlassian.com/cloud/jira/platform/apis/document/structure/)
- [ADF Builder Tools](https://developer.atlassian.com/cloud/jira/platform/apis/document/playground/)

### Testing Tools
- [Jira API Console](https://developer.atlassian.com/console/myapps/)
- [Postman Collection](https://www.postman.com/atlassian/workspace/atlassian-public-api)

---

## Appendix: cURL Examples for Testing

### Test Authentication
```bash
curl -u "your-email@example.com:your-api-token" \
  -H "Accept: application/json" \
  "https://your-domain.atlassian.net/rest/api/3/myself"
```

### Test Search
```bash
curl -u "your-email@example.com:your-api-token" \
  -H "Content-Type: application/json" \
  -X POST \
  -d '{"jql":"project=ABC AND labels=test-label","maxResults":1}' \
  "https://your-domain.atlassian.net/rest/api/3/search"
```

### Test Create Issue
```bash
curl -u "your-email@example.com:your-api-token" \
  -H "Content-Type: application/json" \
  -X POST \
  -d '{
    "fields": {
      "project": {"key": "ABC"},
      "issuetype": {"name": "Bug"},
      "summary": "Test Issue",
      "description": {
        "type": "doc",
        "version": 1,
        "content": [
          {
            "type": "paragraph",
            "content": [{"type": "text", "text": "Test description"}]
          }
        ]
      },
      "labels": ["test"]
    }
  }' \
  "https://your-domain.atlassian.net/rest/api/3/issue"
```

---

## Summary

This plan provides a complete roadmap to replace the deprecated Jira v2 API client with a custom, minimal v3 client that:

1. ✅ Eliminates dependency on unmaintained packages
2. ✅ Uses only Jira API v3 endpoints
3. ✅ Maintains all existing functionality
4. ✅ Provides clean, maintainable code
5. ✅ Can be extended for future features

The implementation is straightforward and can be completed in 2-3 days with proper testing.
