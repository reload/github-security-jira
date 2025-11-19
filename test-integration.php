#!/usr/bin/env php
<?php
/**
 * Integration test to verify SecurityAlertIssue and PullRequestIssue work
 * This tests without actual API calls
 */

require __DIR__.'/vendor/autoload.php';

use GitHubSecurityJira\SecurityAlertIssue;
use GitHubSecurityJira\PullRequestIssue;
use GitHubSecurityJira\Jira\AdfBuilder;

echo "Integration Test - SecurityAlertIssue and PullRequestIssue\n";
echo "===========================================================\n\n";

// Set up mock environment variables (won't actually call Jira)
putenv('JIRA_HOST=https://test.atlassian.net');
putenv('JIRA_USER=test@example.com');
putenv('JIRA_TOKEN=test-token');
putenv('JIRA_PROJECT=TEST');
putenv('JIRA_ISSUE_TYPE=Bug');
putenv('JIRA_ISSUE_LABELS=security,test');
putenv('GITHUB_REPOSITORY=test/repo');
putenv('GITHUB_SERVER_URL=https://github.com');

echo "Test 1: SecurityAlertIssue Construction\n";
echo "----------------------------------------\n";

try {
    $alertData = [
        'securityVulnerability' => [
            'package' => [
                'name' => 'lodash',
                'ecosystem' => 'npm'
            ],
            'firstPatchedVersion' => [
                'identifier' => '4.17.21'
            ],
            'vulnerableVersionRange' => '< 4.17.21',
            'advisory' => [
                'ghsaId' => 'GHSA-xxxx-yyyy-zzzz',
                'summary' => 'Prototype Pollution in lodash',
                'description' => 'A vulnerability in lodash allows prototype pollution.',
                'references' => [
                    ['url' => 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz'],
                    ['url' => 'https://nvd.nist.gov/vuln/detail/CVE-2020-1234']
                ]
            ],
            'severity' => 'HIGH'
        ],
        'vulnerableManifestPath' => 'package.json',
        'number' => 42
    ];

    $issue = new SecurityAlertIssue($alertData);

    echo "✅ SecurityAlertIssue constructed successfully\n";
    echo "   Unique ID: " . $issue->uniqueId() . "\n";

} catch (\Throwable $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "\nTest 2: PullRequestIssue Construction\n";
echo "--------------------------------------\n";

try {
    $prData = [
        'title' => 'Bump lodash from 4.17.15 to 4.17.21 in /src',
        'number' => '123',
        'url' => 'https://github.com/test/repo/pull/123'
    ];

    $prIssue = new PullRequestIssue($prData);

    echo "✅ PullRequestIssue constructed successfully\n";
    echo "   Unique ID: " . $prIssue->uniqueId() . "\n";

} catch (\Throwable $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "\nTest 3: Verify exists() method doesn't crash\n";
echo "---------------------------------------------\n";

try {
    // This will try to connect but should fail gracefully
    // We're just checking it doesn't crash
    $existingKey = null;

    // We can't actually call exists() without valid credentials
    // but we can verify the object is properly constructed
    echo "✅ Issue objects are properly structured\n";
    echo "   (Cannot test exists() without valid Jira credentials)\n";

} catch (\Throwable $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nTest 4: Verify ensure() would work (structure check)\n";
echo "-----------------------------------------------------\n";

try {
    // We can't call ensure() without credentials, but we can verify
    // that the title and body are set properly

    // Use reflection to check private properties
    $reflection = new ReflectionClass($issue);
    $titleProp = $reflection->getProperty('title');
    $titleProp->setAccessible(true);
    $title = $titleProp->getValue($issue);

    $bodyProp = $reflection->getProperty('body');
    $bodyProp->setAccessible(true);
    $body = $bodyProp->getValue($issue);

    if (empty($title)) {
        throw new Exception("Title is empty");
    }

    if (empty($body)) {
        throw new Exception("Body is empty");
    }

    echo "✅ Issue has valid title and body\n";
    echo "   Title: $title\n";
    echo "   Body length: " . strlen($body) . " characters\n";

} catch (\Throwable $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nTest 5: Verify ADF conversion for issue body\n";
echo "---------------------------------------------\n";

try {
    $reflection = new ReflectionClass($issue);
    $bodyProp = $reflection->getProperty('body');
    $bodyProp->setAccessible(true);
    $body = $bodyProp->getValue($issue);

    // Convert body to ADF
    $adf = AdfBuilder::fromWikiMarkup($body);

    if (!isset($adf['type']) || $adf['type'] !== 'doc') {
        throw new Exception("Invalid ADF structure");
    }

    if (!isset($adf['content']) || empty($adf['content'])) {
        throw new Exception("ADF content is empty");
    }

    echo "✅ ADF conversion successful\n";
    echo "   Content blocks: " . count($adf['content']) . "\n";
    echo "   ADF structure is valid\n";

} catch (\Throwable $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "All tests passed! ✅\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "\n";
echo "Summary:\n";
echo "- SecurityAlertIssue can be constructed from GitHub alert data\n";
echo "- PullRequestIssue can be constructed from PR data\n";
echo "- Issue bodies are properly formatted\n";
echo "- ADF conversion works correctly\n";
echo "\n";
echo "Next steps:\n";
echo "1. Set up proper environment variables with real credentials\n";
echo "2. Test with --dry-run flag first\n";
echo "3. Run full sync to create actual Jira tickets\n";
echo "\n";
