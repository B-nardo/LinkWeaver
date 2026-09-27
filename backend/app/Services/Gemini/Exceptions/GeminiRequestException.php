<?php

declare(strict_types=1);

namespace App\Services\Gemini\Exceptions;

/**
 * A failure that will recur identically on every attempt: a rejected key, a
 * malformed request, an unknown model, or a response that does not line up
 * with what was asked for. Retrying wastes quota, so these are fatal.
 */
final class GeminiRequestException extends GeminiException
{
    public static function rejected(int $status, string $message): self
    {
        return new self("Gemini rejected the request (HTTP {$status}): {$message}");
    }

    public static function malformedResponse(string $detail): self
    {
        return new self("Gemini returned a response that could not be used: {$detail}");
    }

    public static function countMismatch(int $sent, int $received): self
    {
        // Accepting this would pair vectors with the wrong pages, making every
        // similarity score meaningless while looking perfectly healthy.
        return new self(
            "Gemini returned {$received} embeddings for {$sent} inputs. Refusing to "
            .'guess which vector belongs to which page.'
        );
    }
}
