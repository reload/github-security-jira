#!/usr/bin/env php
<?php
/**
 * Test script to verify ADF conversion works correctly
 */

require __DIR__.'/vendor/autoload.php';

use GitHubSecurityJira\Jira\AdfBuilder;

echo "Testing ADF Conversion...\n\n";

// Test 1: Simple wiki markup like SecurityAlertIssue creates
$wikiMarkup = <<<EOT
- Repository: [test/repo|https://github.com/test/repo]
- Alert: [Vulnerability Summary|https://github.com/test/repo/security/dependabot/1]
- Package: lodash (npm)
- Vulnerable version: < 4.17.21
- Secure version: 4.17.21

{noformat}
This is a test vulnerability description.
It spans multiple lines.
And contains important security information.
{noformat}
EOT;

echo "Input Wiki Markup:\n";
echo "=================\n";
echo $wikiMarkup;
echo "\n\n";

$adf = AdfBuilder::fromWikiMarkup($wikiMarkup);

echo "Output ADF (JSON):\n";
echo "==================\n";
echo json_encode($adf, JSON_PRETTY_PRINT);
echo "\n\n";

// Test 2: Verify structure
echo "Validation:\n";
echo "===========\n";

if (!isset($adf['type']) || $adf['type'] !== 'doc') {
    echo "❌ FAIL: Missing or incorrect document type\n";
} else {
    echo "✅ PASS: Document type is correct\n";
}

if (!isset($adf['content']) || !is_array($adf['content'])) {
    echo "❌ FAIL: Missing or invalid content array\n";
} else {
    echo "✅ PASS: Content array exists\n";
    echo "   Found " . count($adf['content']) . " content blocks\n";
}

// Check for bullet list
$hasBulletList = false;
$hasCodeBlock = false;
foreach ($adf['content'] as $block) {
    if ($block['type'] === 'bulletList') {
        $hasBulletList = true;
    }
    if ($block['type'] === 'codeBlock') {
        $hasCodeBlock = true;
    }
}

if ($hasBulletList) {
    echo "✅ PASS: Bullet list found\n";
} else {
    echo "❌ FAIL: No bullet list found\n";
}

if ($hasCodeBlock) {
    echo "✅ PASS: Code block found\n";
} else {
    echo "❌ FAIL: No code block found\n";
}

echo "\n";
echo "Test complete!\n";
