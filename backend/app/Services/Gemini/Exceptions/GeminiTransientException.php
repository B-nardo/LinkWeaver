<?php

declare(strict_types=1);

namespace App\Services\Gemini\Exceptions;

/**
 * A failure that may well succeed on a later attempt: a rate limit, or the
 * service being briefly unavailable. Jobs catching this should back off and
 * retry rather than fail the project.
 */
final class GeminiTransientException extends GeminiException
{
    public static function rateLimited(string $detail): self
    {
        return new self("Gemini rate limit reached: {$detail}");
    }

    public static function unavailable(int $status): self
    {
        return new self("Gemini returned HTTP {$status}; the service is temporarily unavailable.");
    }

    public static function connectionFailed(string $detail): self
    {
        return new self("Could not reach Gemini: {$detail}");
    }
}
