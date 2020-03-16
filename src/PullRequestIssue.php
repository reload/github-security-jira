<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use Reload\JiraSecurityIssue;

class PullRequestIssue extends JiraSecurityIssue
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
    protected string $manifestPath;

    /**
     * @param array<string,string> $data
     */
    public function __construct(array $data)
    {
        $this->package = \preg_filter('/.*Bump (.*) from.*/', '$1', $data['title']) ?? '';
        $this->manifestPath = \preg_filter('/.* in \/(.*)/', '$1', $data['title']) ?? '';
        $this->safeVersion = \preg_filter('/.*to ([^ ]+).*/', '$1', $data['title']) ?? '';

        $githubRepo = \getenv('GITHUB_REPOSITORY') ?: '';

        $body = <<<EOT
- Repository: [{$githubRepo}|https://github.com/{$githubRepo}]
- Package: {$this->package}
- Secure version: {$this->safeVersion}
- Pull request with more info: [#{$data['number']}|{$data['url']}]
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
        if ($this->manifestPath === '') {
            return "{$this->package}:{$this->safeVersion}";
        }

        return "{$this->package}:{$this->manifestPath}:{$this->safeVersion}";
    }
}
