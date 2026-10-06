<?php

declare(strict_types=1);

namespace App\CattoMail;

/** What happened to a sending run or a single send. */
final class SendOutcome
{
    public const SUBMITTED = 'submitted';
    /** catto-mail could not be reached; the persisted work is retried by the worker with the same keys. */
    public const DEFERRED = 'deferred';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        /** Recipients staged in the outbox by this run. */
        public readonly int $staged,
        /** Recipients handed off to catto-mail (their job was sealed) by this run. */
        public readonly int $handedOff,
        public readonly ?string $problem,
    ) {
    }

    public static function of(int $staged, int $handedOff, ?string $problem, bool $retryable): self
    {
        $status = $problem === null ? self::SUBMITTED : ($retryable ? self::DEFERRED : self::FAILED);
        return new self($status, $staged, $handedOff, $problem);
    }

    public function accepted(): bool
    {
        return $this->status !== self::FAILED;
    }
}
