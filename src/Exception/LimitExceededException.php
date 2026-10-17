<?php

declare(strict_types=1);

namespace IvoPdf\Exception;

/**
 * Thrown when a rendering exceeds a resource limit set in the options (Options::$maxRenderTime, Options::$maxMemory).
 * Unlike PHP's own "Allowed memory size exhausted" or max_execution_time fatal errors it can be caught, so a web
 * application can answer with an error page instead of losing the request.
 */
class LimitExceededException extends \RuntimeException
{
    /** 'time' or 'memory' */
    private string $limit;

    public function __construct(string $limit, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->limit = $limit;
    }

    /** Which limit was hit: 'time' (Options::$maxRenderTime) or 'memory' (Options::$maxMemory). */
    public function getLimit(): string
    {
        return $this->limit;
    }
}
