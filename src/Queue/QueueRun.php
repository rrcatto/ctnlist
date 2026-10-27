<?php

declare(strict_types=1);

namespace App\Queue;

use App\CattoMail\SendOutcome;
use App\Maintenance\DatabaseLock;
use App\Repository\OptionRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * A queue run started with Start sending. The request is recorded (request()) and the
 * page answers at once; the run itself happens after the answer has been sent
 * (QueueRunListener at kernel.terminate calls run()), and its progress is kept in
 * `options` for the Sending page, which follows it as it goes (view()). The sending is
 * QueueProcessor's, under its lock: one run at a time, freed if the process dies.
 *
 * @phpstan-type Run array{muid: string, subject: string, limit: int, by: string, state: string, requested_at: string,
 *     started_at: ?string, finished_at: ?string, staged: int, handed_off: int, outcome: ?string}
 */
final class QueueRun
{
    /** Request attribute: run the recorded queue run once the response has been sent. */
    public const START = '_queue_run_start';

    public const STARTING = 'starting';
    public const RUNNING = 'running';
    public const FINISHED = 'finished';
    /** Shown by view(): recorded as running, but nothing holds the queue lock (the process was ended). */
    public const INTERRUPTED = 'interrupted';
    /** Shown by view(): requested, and not begun within START_WITHIN seconds. */
    public const NOT_STARTED = 'not_started';

    private const KEY = 'QueueRun';
    private const START_WITHIN = 120;
    /** Progress is saved at most this often (seconds), not once per message. */
    private const SAVE_EVERY = 2;

    public function __construct(
        private readonly QueueProcessor $processor,
        private readonly OptionRepository $options,
        private readonly DatabaseLock $lock,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record a run of up to $limit messages: of the message $muid ('' for the whole queue). False,
     * and nothing recorded, while another run is starting or running.
     */
    public function request(string $muid, string $subject, int $limit, string $by): bool
    {
        if (in_array($this->view()['state'] ?? null, [self::STARTING, self::RUNNING], true)) {
            return false;
        }
        $this->save(['muid' => $muid, 'subject' => $subject, 'limit' => max(0, $limit), 'by' => $by, 'state' => self::STARTING, 'requested_at' => $this->now(),
            'started_at' => null, 'finished_at' => null, 'staged' => 0, 'handed_off' => 0, 'outcome' => null]);
        return true;
    }

    /** Send the recorded run, if it is still waiting to start (QueueRunListener). */
    public function run(): void
    {
        $run = $this->stored();
        if ($run === null || $run['state'] !== self::STARTING) {
            return;
        }
        // Held from before the run is marked running, so view() never sees a running run without its lock.
        // QueueProcessor takes the same lock again: PostgreSQL's advisory locks count per session.
        if (!$this->lock->acquire(DatabaseLock::QUEUE)) {
            $this->save(array_merge($run, ['state' => self::FINISHED, 'finished_at' => $this->now(), 'outcome' => 'The queue is already being sent.']));
            return;
        }
        try {
            $run = array_merge($run, ['state' => self::RUNNING, 'started_at' => $this->now()]);
            $this->save($run);
            $this->logger->notice('Queue run of up to {limit} messages started by {admin}.', ['limit' => $run['limit'], 'admin' => $run['by']]);
            $saved = microtime(true);
            try {
                $outcome = $this->processor->process($run['muid'], $run['limit'], function (int $staged) use (&$run, &$saved): void {
                    $run['staged'] = $staged;
                    if (microtime(true) - $saved >= self::SAVE_EVERY) {
                        $this->save($run);
                        $saved = microtime(true);
                    }
                });
            } catch (\Throwable $e) {
                $this->save(array_merge($run, ['state' => self::FINISHED, 'finished_at' => $this->now(),
                    'outcome' => 'The run stopped with an error after ' . $run['staged'] . ' message(s); the rest stay queued. The application log has the details.']));
                throw $e;
            }
            $this->save(array_merge($run, ['state' => self::FINISHED, 'finished_at' => $this->now(), 'staged' => $outcome->staged,
                'handed_off' => $outcome->handedOff, 'outcome' => self::describe($outcome)]));
        } finally {
            $this->lock->release(DatabaseLock::QUEUE);
        }
    }

    /**
     * The last run as it stands now, or null if there has been none: its recorded state, or
     * INTERRUPTED (recorded as running, but nothing is sending) or NOT_STARTED.
     *
     * @return Run|null
     */
    public function view(): ?array
    {
        $run = $this->stored();
        if ($run === null) {
            return null;
        }
        if ($run['state'] === self::RUNNING && $this->lock->isFree(DatabaseLock::QUEUE)) {
            $run['state'] = self::INTERRUPTED;
        } elseif ($run['state'] === self::STARTING && strtotime($run['requested_at']) < $this->clock->now()->getTimestamp() - self::START_WITHIN
            && $this->lock->isFree(DatabaseLock::QUEUE)) {
            $run['state'] = self::NOT_STARTED;
        }
        return $run;
    }

    /** What a finished run did, for the administrator. */
    public static function describe(SendOutcome $outcome): string
    {
        $message = 'Handed ' . $outcome->handedOff . ' message(s) to catto-mail.';
        if ($outcome->staged > $outcome->handedOff && $outcome->status !== SendOutcome::FAILED) {
            $message .= ' ' . ($outcome->staged - $outcome->handedOff) . ' more are waiting for catto-mail and will be retried automatically.';
        }
        if ($outcome->problem !== null) {
            $message .= ' ' . ($outcome->status === SendOutcome::DEFERRED ? 'catto-mail could not be reached: ' : 'Problem: ') . $outcome->problem;
        }
        return $message;
    }

    /** @return Run|null */
    private function stored(): ?array
    {
        $run = json_decode((string) $this->options->get(self::KEY), true);
        if (!is_array($run) || !is_string($run['state'] ?? null) || !is_string($run['requested_at'] ?? null)) {
            return null;
        }
        return [
            'muid' => (string) ($run['muid'] ?? ''),
            'subject' => (string) ($run['subject'] ?? ''),
            'limit' => (int) ($run['limit'] ?? 0),
            'by' => (string) ($run['by'] ?? ''),
            'state' => $run['state'],
            'requested_at' => $run['requested_at'],
            'started_at' => isset($run['started_at']) ? (string) $run['started_at'] : null,
            'finished_at' => isset($run['finished_at']) ? (string) $run['finished_at'] : null,
            'staged' => (int) ($run['staged'] ?? 0),
            'handed_off' => (int) ($run['handed_off'] ?? 0),
            'outcome' => isset($run['outcome']) ? (string) $run['outcome'] : null,
        ];
    }

    /** @param Run $run */
    private function save(array $run): void
    {
        $this->options->set(self::KEY, (string) json_encode($run));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
