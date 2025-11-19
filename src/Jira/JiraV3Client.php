<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Jira;

use RuntimeException;

/**
 * Minimal Jira REST API v3 client
 */
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
        mixed $body = null
    ): mixed {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Authorization: Basic ' . base64_encode($this->email . ':' . $this->token),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Failed to initialize CURL");
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            $jsonBody = json_encode($body);
            if ($jsonBody === false) {
                throw new RuntimeException("Failed to encode JSON body");
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("CURL error: {$error}");
        }

        if ($response === false) {
            throw new RuntimeException("CURL returned false response");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException(
                "Jira API error (HTTP {$httpCode}): {$response}\nURL: {$url}"
            );
        }

        $decoded = json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Failed to decode JSON response: " . json_last_error_msg());
        }

        return $decoded;
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

        $result = $this->request('POST', '/search', $body);

        if (!is_object($result)) {
            throw new RuntimeException("Expected object from search, got: " . gettype($result));
        }

        return $result;
    }

    /**
     * Create a new issue
     */
    public function createIssue(array $fields): object
    {
        $body = ['fields' => $fields];
        $result = $this->request('POST', '/issue', $body);

        if (!is_object($result)) {
            throw new RuntimeException("Expected object from createIssue, got: " . gettype($result));
        }

        return $result;
    }

    /**
     * Add a watcher to an issue
     */
    public function addWatcher(string $issueKey, string $accountId): void
    {
        // The body should be just the account ID as a string (quoted in JSON)
        $this->request('POST', "/issue/{$issueKey}/watchers", $accountId);
    }

    /**
     * Add a comment to an issue
     */
    public function addComment(string $issueKey, array $comment): object
    {
        $result = $this->request('POST', "/issue/{$issueKey}/comment", $comment);

        if (!is_object($result)) {
            throw new RuntimeException("Expected object from addComment, got: " . gettype($result));
        }

        return $result;
    }

    /**
     * Find users by query (email)
     */
    public function findUsers(string $query): array
    {
        $endpoint = '/user/search?' . http_build_query(['query' => $query]);
        $result = $this->request('GET', $endpoint);

        return is_array($result) ? $result : (array) $result;
    }
}
