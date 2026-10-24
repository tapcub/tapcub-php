<?php

declare(strict_types=1);

namespace TapCub;

/**
 * A batch that could not be delivered, or events the server rejected.
 *
 * getErrorCode(): server code (NO_API_KEY, BAD_API_KEY, VALIDATION …), HTTP_<status>, NETWORK, EVENT_REJECTED or QUEUE_FULL.
 */
class TapCubException extends \RuntimeException
{
    /** @var string */
    private $errorCode;
    /** @var int|null */
    private $status;
    /** @var list<array<string, mixed>> */
    private $events;
    /** @var list<array<string, mixed>>|null */
    private $results;
    /** @var float|null seconds, from Retry-After */
    public $retryAfter;

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>>|null $results
     */
    public function __construct(string $message, string $errorCode, ?int $status = null, array $events = [], ?array $results = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->events = $events;
        $this->results = $results;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** HTTP status, when there was a response */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /** @return list<array<string, mixed>> the events that were not stored */
    public function getEvents(): array
    {
        return $this->events;
    }

    /** @return list<array<string, mixed>>|null per-event results for EVENT_REJECTED */
    public function getResults(): ?array
    {
        return $this->results;
    }
}
