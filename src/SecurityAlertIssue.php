<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use Reload\JiraSecurityIssue;

class SecurityAlertIssue extends JiraSecurityIssue
{

    /**
     * @var string
     */
    protected string $package;

    /**
     * @var string
     */
    protected string $safeVersion;

    /**
     * @var string
     */
    protected string $vulnerableVersionRange;

    /**
     * @var string
     */
    protected string $manifestPath;

    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        $this->package = $data['securityVulnerability']['package']['name'];
        $this->safeVersion = $data['securityVulnerability']['firstPatchedVersion']['identifier'];
        $this->vulnerableVersionRange = $data['securityVulnerability']['vulnerableVersionRange'];
        $this->manifestPath = \pathinfo($data['vulnerableManifestPath'], \PATHINFO_DIRNAME);

        $references = [];

        foreach ($data['securityVulnerability']['advisory']['references'] as $ref) {
            if (!\array_key_exists('url', $ref) || !\is_string($ref['url'])) {
                continue;
            }

            $references[] = $ref['url'];
        }

        $advisory_description = \wordwrap($data['securityVulnerability']['advisory']['description'] ?? '', 100);
        $ecosystem = $data['securityVulnerability']['package']['ecosystem'] ?? '';
        $githubRepo = \getenv('GITHUB_REPOSITORY') ?: '';

        $body = <<<EOT
- Repository: [{$githubRepo}|https://github.com/{$githubRepo}]
- Package: {$this->package} ($ecosystem)
- Vulnerable version: {$this->vulnerableVersionRange}
- Secure version: {$this->safeVersion}

EOT;

        if (\is_array($references) && (\count($references) > 0)) {
                $body .= "- Links: \n-- " . \implode("\n-- ", $references);
        }

        $body .= <<<EOT


{noformat}
{$advisory_description}
{noformat}
EOT;

        parent::__construct();

        $this->setKeyLabel($githubRepo);
        $this->setKeyLabel($this->uniqueId());
        $this->setTitle("{$this->package} ({$this->safeVersion})");
        $this->setBody($body);
    }

    /**
     * The unique ID of the severity.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        if ($this->manifestPath === '.') {
            return "{$this->package}:{$this->safeVersion}";
        }

        return "{$this->package}:{$this->manifestPath}:{$this->safeVersion}";
    }
}
