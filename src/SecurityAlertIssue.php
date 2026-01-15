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
     * @var int
     */
    protected int $alertNumber;

    /**
     * @var string
     */
    protected string $advisorySummary;

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
        $this->alertNumber = $data['number'];
        $this->advisorySummary = $data['securityVulnerability']['advisory']['summary'];

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
        $githubUrl = \getenv('GITHUB_SERVER_URL') ?: 'https://github.com';
        $safeVersion = $this->safeVersion ?? 'no fix';

        $body = <<<EOT
- Repository: [{$githubRepo}|{$githubUrl}/{$githubRepo}]
- Alert: [{$this->advisorySummary}|{$githubUrl}/{$githubRepo}/security/dependabot/{$this->alertNumber}]
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

        if ($labels) {
            foreach (\explode(',', $labels) as $label) {
                $this->setKeyLabel($label);
            }
        }

        $components = \getenv('JIRA_COMPONENTS');

        if ($components) {
            foreach (\explode(',', $components) as $component) {
                $this->setComponent(\trim($component));
            }
        }

        $componentMapping = \getenv('JIRA_COMPONENT_MAPPING');

        if ($componentMapping && $ecosystem) {
            $mappings = [];

            foreach (\explode(',', $componentMapping) as $mapping) {
                $parts = \explode(':', $mapping, 2);

                if (\count($parts) === 2) {
                    $mappings[\trim($parts[0])] = \trim($parts[1]);
                }
            }

            if (isset($mappings[$ecosystem])) {
                $this->setComponent($mappings[$ecosystem]);
            } elseif ($defaultComponent = \getenv('JIRA_COMPONENT_DEFAULT')) {
                $this->setComponent(\trim($defaultComponent));
            }
        }

        $priorityMapping = \getenv('JIRA_PRIORITY_MAPPING');

        if ($priorityMapping) {
            $mappings = [];

            foreach (\explode(',', $priorityMapping) as $mapping) {
                $parts = \explode(':', $mapping, 2);

                if (\count($parts) === 2) {
                    $mappings[\trim($parts[0])] = \trim($parts[1]);
                }
            }

            if (isset($mappings[$this->severity])) {
                $this->priority = $mappings[$this->severity];
            } elseif ($defaultPriority = \getenv('JIRA_PRIORITY_DEFAULT')) {
                $this->priority = \trim($defaultPriority);
            }
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

        return str_ireplace(" ", "_", "{$this->package}:{$this->manifestPath}:{$identifier}");
    }
}
