<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use Reload\JiraSecurityIssue;

class SecurityAlertIssue extends JiraSecurityIssue implements SecurityIssueInterface
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
     * @var array<string,mixed>
     */
    private array $rawData;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data, Config $config)
    {
        $this->rawData = $data;
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
        $githubRepo = $config->githubRepository;
        $githubUrl = $config->githubServerUrl;
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

        foreach ($config->jiraIssueLabels as $label) {
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
        return AlertIdentifier::fromAlertData($this->rawData);
    }
}
