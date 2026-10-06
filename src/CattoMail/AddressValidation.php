<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\CattoMailValidationRepository;
use Doctrine\DBAL\Connection;

/**
 * Validates subscribers' addresses with catto-mail. Each job (at most
 * 10,000 addresses) is stored with its Idempotency-Key and addresses before
 * it is submitted; each address carries the subscriber UUID as
 * external_address_reference, and results are mapped back by it.
 *
 * Results are information for administrators, stored exactly as catto-mail
 * classified them: `unknown` stays unknown, `temporarily_unverifiable` is not
 * invalid, a suggested address is only ever shown, never applied, and a
 * deliverable address says nothing about consent. Nothing about a subscriber
 * (address, memberships, campaign selection) changes automatically.
 *
 * @phpstan-import-type ValidationJob from CattoMailValidationRepository
 */
final class AddressValidation
{
    public function __construct(
        private readonly CattoMailValidationRepository $validations,
        private readonly CattoMailClient $client,
        private readonly CattoMailConfig $config,
        private readonly Connection $db,
    ) {
    }

    /**
     * Create (and try to submit) jobs for the members of a list who have not
     * unsubscribed from it.
     *
     * @return list<int> the local job ids
     */
    public function createForList(int $listId, string $listName, ?int $createdBy): array
    {
        $problem = $this->config->problem();
        if ($problem !== null) {
            throw new CattoMailRejected($problem, 0, 'not-configured');
        }
        /** @var list<array{s_uuid: string, s_email: string}> $subscribers */
        $subscribers = array_map(static fn(array $r): array => ['s_uuid' => (string) $r['s_uuid'], 's_email' => (string) $r['s_email']], $this->db->fetchAllAssociative(
            'SELECT s.s_uuid, s.s_email FROM subscribers s JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             WHERE ls.ls_l_id = ? AND ls.ls_unsubscribed = FALSE ORDER BY s.s_id',
            [$listId]
        ));
        return $this->create('List ' . $listName, $subscribers, $createdBy);
    }

    /**
     * @param list<array{s_uuid: string, s_email: string}> $subscribers
     * @return list<int>
     */
    public function create(string $scope, array $subscribers, ?int $createdBy): array
    {
        $ids = [];
        $chunks = array_chunk($subscribers, CattoMailConfig::MAX_VALIDATION_ADDRESSES);
        foreach ($chunks as $n => $chunk) {
            $ids[] = $this->validations->create(count($chunks) > 1 ? sprintf('%s (part %d of %d)', $scope, $n + 1, count($chunks)) : $scope, $chunk, $createdBy);
        }
        foreach ($ids as $id) {
            $job = $this->validations->job($id);
            if ($job !== null) {
                try {
                    $this->submit($job);
                } catch (CattoMailUnavailable) {
                    // Kept as pending; the worker submits it with the same Idempotency-Key.
                }
            }
        }
        return $ids;
    }

    /**
     * @param ValidationJob $job
     * @throws CattoMailException
     */
    public function submit(array $job): void
    {
        if ($job['cvj_remote_id'] !== null || $job['cvj_status'] !== 'pending') {
            return;
        }
        try {
            $remote = $this->client->createValidationJob($job['cvj_idempotency_key'], $job['cvj_uuid'], $this->validations->submission($job['cvj_id']));
        } catch (CattoMailRejected $e) {
            $this->validations->fail($job['cvj_id'], $e->getMessage());
            throw $e;
        } catch (CattoMailUnavailable $e) {
            $this->validations->noteAttempt($job['cvj_id'], $e->getMessage());
            throw $e;
        }
        $this->validations->setRemote($job['cvj_id'], (string) ($remote['id'] ?? ''));
        $this->apply($remote);
    }

    /**
     * catto-mail's job state (validation.* webhook or GET); fetches the
     * results once the job is completed.
     *
     * @param array<string, mixed> $remote a ValidationJob
     * @throws CattoMailException while fetching results
     */
    public function apply(array $remote): string
    {
        $job = $this->validations->jobByRemote((string) ($remote['id'] ?? ''), (string) ($remote['external_reference'] ?? ''));
        if ($job === null) {
            return 'unknown validation job';
        }
        if ($job['cvj_remote_id'] === null && is_string($remote['id'] ?? null)) {
            $this->validations->setRemote($job['cvj_id'], $remote['id']);
        }
        $status = (string) ($remote['status'] ?? '');
        $counts = is_array($remote['classification_counts'] ?? null) ? array_map('intval', $remote['classification_counts']) : [];
        $this->validations->updateState($job['cvj_id'], $status, (int) ($remote['processed_count'] ?? 0), $counts,
            DeliveryProblems::timestamp(is_string($remote['completed_at'] ?? null) ? $remote['completed_at'] : null));
        if ($status !== 'completed') {
            return 'validation job ' . $status;
        }
        return 'validation job completed, ' . $this->fetchResults($job['cvj_id']) . ' results stored';
    }

    /**
     * @param ValidationJob $job
     * @throws CattoMailException
     */
    public function refresh(array $job): string
    {
        if ($job['cvj_remote_id'] === null) {
            $this->submit($job);
            return 'submitted';
        }
        return $this->apply($this->client->getValidationJob($job['cvj_remote_id']));
    }

    /** @return int results stored */
    private function fetchResults(int $jobId): int
    {
        $job = $this->validations->job($jobId);
        if ($job === null || $job['cvj_remote_id'] === null || $job['cvj_results_complete']) {
            return 0;
        }
        $stored = 0;
        $cursor = null;
        do {
            $page = $this->client->listValidationAddresses($job['cvj_remote_id'], $cursor);
            foreach ($page['data'] as $result) {
                $checked = DeliveryProblems::timestamp(is_string($result['checked_at'] ?? null) ? $result['checked_at'] : null);
                if ($this->validations->storeResult($jobId, $result, $checked)) {
                    $stored++;
                }
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $this->validations->markResultsComplete($jobId);
        return $stored;
    }
}
