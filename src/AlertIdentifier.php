<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

class AlertIdentifier
{
    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * Build a unique ID from alert data, matching SecurityAlertIssue::uniqueId() logic.
     *
     * @param array<string,mixed> $alertData
     */
    public static function fromAlertData(array $alertData): string
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        $package = $alertData['securityVulnerability']['package']['name'];
        $safeVersion = $alertData['securityVulnerability']['firstPatchedVersion']['identifier'] ?? null;
        $ghsaId = $alertData['securityVulnerability']['advisory']['ghsaId'];
        $manifestPath = \pathinfo($alertData['vulnerableManifestPath'], \PATHINFO_DIRNAME);

        $identifier = $safeVersion ?? $ghsaId;

        if ($manifestPath === '.' || $manifestPath === '/') {
            return "{$package}:{$identifier}";
        }

        return str_ireplace(" ", "_", "{$package}:{$manifestPath}:{$identifier}");
    }
}
