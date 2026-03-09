<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

interface SecurityIssueInterface
{
    public function uniqueId(): string;

    public function exists(): ?string;

    public function ensure(): string;
}
