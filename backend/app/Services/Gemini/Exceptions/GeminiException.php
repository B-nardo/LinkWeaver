<?php

declare(strict_types=1);

namespace App\Services\Gemini\Exceptions;

use RuntimeException;

/**
 * Base for every Gemini failure. Subclasses exist to answer one question the
 * queue needs settled: is retrying this worth a worker's time?
 */
abstract class GeminiException extends RuntimeException {}
