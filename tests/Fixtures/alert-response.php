<?php

declare(strict_types=1);

return [
    'securityVulnerability' => [
        'advisory' => [
            'ghsaId' => 'GHSA-1234-5678-abcd',
            'description' => 'A prototype pollution vulnerability in lodash allows attackers to manipulate object prototypes.',
            'identifiers' => [
                ['type' => 'CVE', 'value' => 'CVE-2021-12345'],
                ['type' => 'GHSA', 'value' => 'GHSA-1234-5678-abcd'],
            ],
            'references' => [
                ['url' => 'https://nvd.nist.gov/vuln/detail/CVE-2021-12345'],
                ['url' => 'https://github.com/advisories/GHSA-1234-5678-abcd'],
            ],
            'severity' => 'HIGH',
            'summary' => 'Prototype Pollution in lodash',
        ],
        'firstPatchedVersion' => [
            'identifier' => '4.17.21',
        ],
        'package' => [
            'name' => 'lodash',
            'ecosystem' => 'NPM',
        ],
        'severity' => 'HIGH',
        'updatedAt' => '2024-01-15T10:30:00Z',
        'vulnerableVersionRange' => '< 4.17.21',
    ],
    'repository' => [
        'nameWithOwner' => 'reload/github-security-jira',
    ],
    'vulnerableManifestFilename' => 'package-lock.json',
    'vulnerableManifestPath' => 'package-lock.json',
    'vulnerableRequirements' => '= 4.17.15',
    'number' => 42,
];
