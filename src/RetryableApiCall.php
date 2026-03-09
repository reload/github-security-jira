<?php

declare(strict_types=1);

namespace GitHubSecurityJira;

use Throwable;

class RetryableApiCall
{
    /**
     * phpcs:disable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
     *
     * Execute a callable with exponential backoff retry.
     *
     * @return mixed
     */
    public static function execute(callable $callable, int $maxRetries = 3, int $initialDelayMs = 1000): mixed
    {
        // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callable();
            } catch (Throwable $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    $delayMs = $initialDelayMs * (2 ** $attempt);
                    \usleep($delayMs * 1000);
                }
            }
        }

        /** @var Throwable $lastException */
        throw $lastException;
    }
}
