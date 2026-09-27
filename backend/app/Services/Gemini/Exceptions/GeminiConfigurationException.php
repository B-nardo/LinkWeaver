<?php

declare(strict_types=1);

namespace App\Services\Gemini\Exceptions;

/**
 * The client was asked to run before it was configured. Names the environment
 * variable, because this is the failure a new contributor hits first.
 */
final class GeminiConfigurationException extends GeminiException
{
    public static function missing(string $envVar): self
    {
        return new self(
            "{$envVar} is not set. Get a key from https://aistudio.google.com/apikey "
            .'and set the Gemini values in your .env file.'
        );
    }
}
