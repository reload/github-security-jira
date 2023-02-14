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
     * @var string|null
     */
    protected ?string $safeVersion;

    /**
     * @var string
     */
    protected string $vulnerableVersionRange;

    /**
     * @var string
     */
    protected string $manifestPath;

    /**
     * @var string
     */
    protected string $id;

    /**
     * @var string
     */
    protected string $severity;

    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        $this->package = $data['securityVulnerability']['package']['name'];
        $this->safeVersion = $data['securityVulnerability']['firstPatchedVersion']['identifier'] ?? null;
        $this->vulnerableVersionRange = $data['securityVulnerability']['vulnerableVersionRange'];
        $this->manifestPath = \pathinfo($data['vulnerableManifestPath'], \PATHINFO_DIRNAME);
        $this->id = $data['securityVulnerability']['advisory']['ghsaId'];
        $this->severity = $data['securityVulnerability']['severity'];

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
        $safeVersion = $this->safeVersion ?? 'no fix';

        $body = <<<EOT
- Repository: [{$githubRepo}|https://github.com/{$githubRepo}]
- Package: {$this->package} ($ecosystem)
- Vulnerable version: {$this->vulnerableVersionRange}
- Secure version: {$safeVersion}

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
        $this->setTitle("{$this->package} ({$safeVersion}) - {$this->severity}");
        $this->setBody($body);

        $labels = \getenv('JIRA_ISSUE_LABELS');

        if (!$labels) {
            return;
        }

        foreach (\explode(',', $labels) as $label) {
            $this->setKeyLabel($label);
        }
    }

    /**
     * The unique ID of the severity.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        // If there is no safe version we use the GHSA ID as
        // identifier. If the security alert is later updated with a
        // known safe version a side effect of this is that a new Jira
        // issue will be created. We'll consider this a positive side
        // effect.
        $identifier = $this->safeVersion ?? $this->id;

        if ($this->manifestPath === '.') {
            return "{$this->package}:{$identifier}";
        }

        return str_ireplace(" ","_","{$this->package}:{$this->manifestPath}:{$identifier}");
    }
}
