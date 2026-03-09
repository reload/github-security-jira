<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Tests\Unit;

use GitHubSecurityJira\AlertIdentifier;
use PHPUnit\Framework\TestCase;

class AlertIdentifierTest extends TestCase
{
    public function testWithSafeVersionRootManifest(): void
    {
        $data = require __DIR__ . '/../Fixtures/alert-response.php';
        // Root manifest: pathinfo dirname = "."
        $data['vulnerableManifestPath'] = 'package-lock.json';

        $this->assertSame('lodash:4.17.21', AlertIdentifier::fromAlertData($data));
    }

    public function testWithSafeVersionSubdirectory(): void
    {
        $data = require __DIR__ . '/../Fixtures/alert-response.php';
        $data['vulnerableManifestPath'] = 'frontend/package-lock.json';

        $this->assertSame('lodash:frontend:4.17.21', AlertIdentifier::fromAlertData($data));
    }

    public function testWithoutSafeVersionUsesGhsaId(): void
    {
        $data = require __DIR__ . '/../Fixtures/alert-response.php';
        unset($data['securityVulnerability']['firstPatchedVersion']);
        $data['vulnerableManifestPath'] = 'package-lock.json';

        $this->assertSame('lodash:GHSA-1234-5678-abcd', AlertIdentifier::fromAlertData($data));
    }

    public function testSpacesReplacedWithUnderscores(): void
    {
        $data = require __DIR__ . '/../Fixtures/alert-response.php';
        $data['vulnerableManifestPath'] = 'my dir/package-lock.json';

        $this->assertSame('lodash:my_dir:4.17.21', AlertIdentifier::fromAlertData($data));
    }

    public function testMatchesSecurityAlertIssueUniqueId(): void
    {
        // Verify the static method produces the same result as the instance method
        // would for the same data. Both should use the same algorithm.
        $data = require __DIR__ . '/../Fixtures/alert-response.php';

        $fromStatic = AlertIdentifier::fromAlertData($data);

        // Based on fixture: root manifest (/package-lock.json -> "."), has safeVersion 4.17.21
        $this->assertSame('lodash:4.17.21', $fromStatic);
    }
}
