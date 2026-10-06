<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\AddressValidation;
use App\CattoMail\CattoMailWorker;
use App\Repository\CattoMailValidationRepository;

/**
 * Address validation: subscriber UUIDs as external_address_reference,
 * results mapped back by them and kept exactly as catto-mail classified
 * them; nothing about the subscriber changes.
 */
final class AddressValidationTest extends CattoMailTestCase
{
    public function testResultsMapBackBySubscriberAndChangeNothing(): void
    {
        [$listId, $subscribers] = $this->members(['ann@gmial.com', 'ben@example.com', 'cas@example.org', 'dee@example.net']);
        // Dee unsubscribed from the list: not validated.
        $this->setMembership($subscribers['dee@example.net'], $listId, true, true);
        $before = $this->db->fetchAllAssociative('SELECT s_id, s_email, s_delivery_state FROM subscribers ORDER BY s_id');
        $consent = $this->db->fetchAllAssociative('SELECT ls_s_id, ls_l_id, ls_confirmed, ls_unsubscribed FROM list_subscribers ORDER BY ls_id');

        $ids = $this->service(AddressValidation::class)->createForList($listId, 'News', null);
        self::assertCount(1, $ids);
        $local = $this->service(CattoMailValidationRepository::class)->job($ids[0]);
        self::assertNotNull($local);
        $remoteId = (string) $local['cvj_remote_id'];
        $create = $this->fake->requestsTo('POST', '/validation-jobs')[0]['body'];
        self::assertSame($local['cvj_uuid'], $create['external_reference']);
        $uuids = array_map(fn(int $id): string => $this->subscriberUuid($id), $subscribers);
        self::assertSame([
            ['address' => 'ann@gmial.com', 'external_address_reference' => $uuids['ann@gmial.com']],
            ['address' => 'ben@example.com', 'external_address_reference' => $uuids['ben@example.com']],
            ['address' => 'cas@example.org', 'external_address_reference' => $uuids['cas@example.org']],
        ], $create['addresses']);

        $this->fake->completeValidation($remoteId, [
            'ann@gmial.com' => ['overall_classification' => 'risky', 'is_domain_typo_suspected' => true, 'suggested_address' => 'ann@gmail.com',
                'suggestion_reason_code' => 'provider_domain_edit_distance', 'suggestion_confidence' => 'high'],
            'ben@example.com' => ['overall_classification' => 'unknown', 'smtp_status' => 'inconclusive', 'confidence' => 'low'],
            'cas@example.org' => ['overall_classification' => 'temporarily_unverifiable', 'domain_status' => 'temporary_failure', 'diagnostic_code' => 'dns.timeout'],
        ]);
        // Results arrive in a different order than submitted: mapping is by reference, never by position.
        $this->fake->validationAddresses[$remoteId] = array_reverse($this->fake->validationAddresses[$remoteId]);
        self::assertSame(200, $this->webhook('validation.completed', $this->fake->validationJobs[$remoteId])->getStatusCode());

        $rows = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM cattomail_validation_addresses WHERE cva_cvj_id = ?', [$ids[0]]) as $row) {
            $rows[$row['cva_address']] = $row;
        }
        self::assertSame(['risky', 'ann@gmail.com', true], [$rows['ann@gmial.com']['cva_classification'], $rows['ann@gmial.com']['cva_suggested_address'], (bool) $rows['ann@gmial.com']['cva_is_typo_suspected']]);
        self::assertSame(['unknown', 'inconclusive'], [$rows['ben@example.com']['cva_classification'], $rows['ben@example.com']['cva_smtp_status']], 'unknown stays unknown');
        self::assertSame('temporarily_unverifiable', $rows['cas@example.org']['cva_classification'], 'not converted to invalid');
        foreach ($rows as $address => $row) {
            self::assertSame($uuids[$address], $row['cva_s_uuid']);
        }

        self::assertSame($before, $this->db->fetchAllAssociative('SELECT s_id, s_email, s_delivery_state FROM subscribers ORDER BY s_id'), 'no address rewritten from a suggestion, no delivery state');
        self::assertSame($consent, $this->db->fetchAllAssociative('SELECT ls_s_id, ls_l_id, ls_confirmed, ls_unsubscribed FROM list_subscribers ORDER BY ls_id'), 'consent untouched');
        $job = $this->service(CattoMailValidationRepository::class)->job($ids[0]);
        self::assertSame(['completed', true], [$job['cvj_status'] ?? '', $job['cvj_results_complete'] ?? false]);
        self::assertSame(1, $job['cvj_counts']['unknown'] ?? null);
    }

    public function testNetworkRetriesNeverCreateASecondValidationJob(): void
    {
        [$listId] = $this->members(['ann@example.com', 'ben@example.com']);
        // catto-mail creates the job each time, but every response is lost.
        $this->fake->failNext('POST /validation-jobs', 'timeout', afterEffect: true, times: 3);

        $ids = $this->service(AddressValidation::class)->createForList($listId, 'News', null);
        self::assertSame('pending', $this->service(CattoMailValidationRepository::class)->job($ids[0])['cvj_status'] ?? '', 'kept for the worker');
        self::assertCount(1, $this->fake->validationJobs);

        self::assertSame(1, $this->service(CattoMailWorker::class)->run()['validation jobs submitted']);
        self::assertCount(1, $this->fake->validationJobs, 'the retry replayed the same key');
        $keys = array_unique(array_map(static fn(array $r): string => $r['headers']['idempotency-key'], $this->fake->requestsTo('POST', '/validation-jobs')));
        self::assertCount(1, $keys);
        self::assertSame(array_key_first($this->fake->validationJobs), $this->service(CattoMailValidationRepository::class)->job($ids[0])['cvj_remote_id'] ?? null);
    }

    public function testMoreThanTenThousandAddressesAreSplitIntoJobs(): void
    {
        $subscribers = [];
        for ($n = 1; $n <= 10001; $n++) {
            $subscribers[] = ['s_uuid' => sprintf('0199c000-0000-7000-8000-%012x', $n), 's_email' => "v{$n}@example.com"];
        }
        $ids = $this->service(AddressValidation::class)->create('Import', $subscribers, null);
        self::assertCount(2, $ids);
        self::assertSame([10000, 1], array_map(static fn(array $addresses): int => count($addresses), array_values($this->fake->validationAddresses)));
        self::assertSame(['Import (part 1 of 2)', 'Import (part 2 of 2)'], array_map(fn(int $id): string => $this->service(CattoMailValidationRepository::class)->job($id)['cvj_scope'] ?? '', $ids));
    }

    public function testPollingResolvesAJobWhoseWebhookWasMissed(): void
    {
        [$listId] = $this->members(['ann@example.com']);
        $ids = $this->service(AddressValidation::class)->createForList($listId, 'News', null);
        $remoteId = (string) ($this->service(CattoMailValidationRepository::class)->job($ids[0])['cvj_remote_id'] ?? '');
        $this->fake->completeValidation($remoteId, ['ann@example.com' => ['overall_classification' => 'deliverable']]);
        // No webhook arrives. Not yet due:
        self::assertSame(0, $this->service(CattoMailWorker::class)->run()['validation jobs reconciled']);

        $this->db->executeStatement("UPDATE cattomail_validation_jobs SET cvj_last_checked_at = cvj_last_checked_at - INTERVAL '1 hour' WHERE cvj_id = ?", [$ids[0]]);
        self::assertSame(1, $this->service(CattoMailWorker::class)->run()['validation jobs reconciled']);
        self::assertSame('deliverable', $this->db->fetchOne('SELECT cva_classification FROM cattomail_validation_addresses WHERE cva_cvj_id = ?', [$ids[0]]));
        self::assertSame('completed', $this->service(CattoMailValidationRepository::class)->job($ids[0])['cvj_status'] ?? '');
        $this->db->executeStatement("UPDATE cattomail_validation_jobs SET cvj_last_checked_at = cvj_last_checked_at - INTERVAL '1 hour' WHERE cvj_id = ?", [$ids[0]]);
        self::assertSame(0, $this->service(CattoMailWorker::class)->run()['validation jobs reconciled'], 'resolved: no more polling');
    }

    /**
     * @param list<string> $emails
     * @return array{0: int, 1: array<string, int>} list id and subscriber ids by address
     */
    private function members(array $emails): array
    {
        $listId = $this->createList('NEWS', 'News');
        $ids = [];
        foreach ($emails as $email) {
            $ids[$email] = $this->createSubscriber($email);
            $this->setMembership($ids[$email], $listId, true);
        }
        return [$listId, $ids];
    }
}
