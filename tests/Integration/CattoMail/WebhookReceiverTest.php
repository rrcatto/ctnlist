<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\DeliveryProblems;

/**
 * The webhook endpoint: signature verification over the raw body, stale
 * timestamps, secret rotation, de-duplication by event id, quick
 * acknowledgement with processing after the response, unknown and
 * out-of-order events, and the hard-bounce / complaint mapping.
 */
final class WebhookReceiverTest extends CattoMailTestCase
{
    public function testValidEventIsStoredAcknowledgedAndProcessed(): void
    {
        $sent = $this->sentCampaign(['ann@example.com', 'ben@example.com']);
        $this->fake->deliver($sent['job']);

        $response = $this->webhook('send.completed', $this->fake->sendJobs[$sent['job']], $eventId = $this->eventId());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"accepted"}', $response->getContent());
        $row = $this->db->fetchAssociative('SELECT cwe_type, cwe_processed_at, cwe_outcome FROM cattomail_webhook_events WHERE cwe_event_id = ?', [$eventId]) ?: [];
        self::assertSame('send.completed', $row['cwe_type']);
        self::assertNotNull($row['cwe_processed_at'], 'processed once the response was sent');
        self::assertSame('completed', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_remote_id = ?', [$sent['job']]));
        self::assertSame(['remote_accepted', 'remote_accepted'], $this->db->fetchFirstColumn('SELECT crp_status FROM cattomail_recipients WHERE crp_muid = ? ORDER BY crp_id', [$sent['muid']]));
    }

    public function testDuplicateDeliveriesAreAcknowledgedWithoutASecondEffect(): void
    {
        $sent = $this->sentCampaign(['ann@example.com', 'ben@example.com']);
        $this->fake->deliver($sent['job'], ['ben@example.com' => 'hard_bounced']);
        $data = $this->messageData($sent['job'], 'ben@example.com', 'hard_bounced', 'hard_bounce');
        $eventId = $this->eventId();

        self::assertSame('{"status":"accepted"}', $this->webhook('message.hard_bounced', $data, $eventId)->getContent());
        self::assertSame('{"status":"duplicate"}', $this->webhook('message.hard_bounced', $data, $eventId)->getContent(), 'same event id: acknowledged, not applied');
        self::assertSame(200, $this->webhook('message.hard_bounced', $data, $eventId)->getStatusCode());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_webhook_events WHERE cwe_event_id = ?', [$eventId]));
        // Even a different event id for the same bounce cannot count it twice.
        $this->webhook('message.hard_bounced', $data);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT s_bounces FROM subscribers WHERE s_id = ?', [$sent['ids'][1]]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT m_bounces FROM messages WHERE m_uniqid = ?', [$sent['muid']]));
    }

    public function testSignaturesAreVerifiedBeforeAnythingElse(): void
    {
        $data = ['id' => '0199a000-0000-7000-8000-00000000abcd', 'status' => 'completed'];
        $body = (string) json_encode(['id' => $id = $this->eventId(), 'type' => 'send.completed', 'created_at' => '2026-10-06T10:00:00Z', 'data' => $data]);
        $now = time();
        $signed = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, self::SECRET);

        $cases = [
            'altered body' => $this->webhook('send.completed', $data, $id, body: str_replace('completed', 'failed', $body), headers: ['Smarthost-Signature' => $signed]),
            'altered timestamp' => $this->webhook('send.completed', $data, $id, body: $body, headers: ['Smarthost-Signature' => str_replace('t=' . $now, 't=' . ($now - 2), $signed)]),
            'stale timestamp' => $this->webhook('send.completed', $data, $id, timestamp: $now - 301, body: $body),
            'unknown secret' => $this->webhook('send.completed', $data, $id, secret: 'whsec_somebody_else', body: $body),
            'no signature' => $this->webhook('send.completed', $data, $id, body: $body, headers: ['Smarthost-Signature' => '']),
            'not JSON but signed garbage header' => $this->webhook('send.completed', $data, $id, body: $body, headers: ['Smarthost-Signature' => 't=' . $now . ',v1=zz']),
        ];
        foreach ($cases as $case => $response) {
            self::assertSame(401, $response->getStatusCode(), $case);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_webhook_events'), 'nothing stored');

        self::assertSame(200, $this->webhook('send.completed', $data, $id, secret: self::PREVIOUS_SECRET, body: $body)->getStatusCode(), 'previous secret during rotation');
        self::assertSame(400, $this->webhook('send.completed', $data, headers: ['Smarthost-Event-Id' => $this->eventId()])->getStatusCode(), 'header and body event ids differ');
        self::assertSame(400, $this->webhook('send.completed', [], body: '{"type":"send.completed"}')->getStatusCode(), 'no event id');
    }

    public function testUnknownEventTypesAreKeptButChangeNothing(): void
    {
        $response = $this->webhook('message.future_event', ['message' => ['external_recipient_reference' => 'x']], $eventId = $this->eventId());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ignored unknown event type', $this->db->fetchOne('SELECT cwe_outcome FROM cattomail_webhook_events WHERE cwe_event_id = ?', [$eventId]));
        self::assertSame('test event', $this->webhook('webhook.test', [])->getStatusCode() === 200
            ? $this->db->fetchOne("SELECT cwe_outcome FROM cattomail_webhook_events WHERE cwe_type = 'webhook.test'") : null);
    }

    public function testOutOfOrderEventsNeverRegressState(): void
    {
        $sent = $this->sentCampaign(['ann@example.com', 'ben@example.com']);
        // catto-mail processes the job; the bounce notification overtakes the completion.
        $this->fake->deliver($sent['job'], ['ben@example.com' => 'hard_bounced']);
        $this->webhook('message.hard_bounced', $this->messageData($sent['job'], 'ben@example.com', 'hard_bounced', 'hard_bounce'));
        self::assertSame('hard_bounced', $this->db->fetchOne('SELECT s_delivery_state FROM subscribers WHERE s_id = ?', [$sent['ids'][1]]));

        // A delayed, older view of the job (processing, the bounce not yet in it) arrives next.
        $stale = $this->fake->sendJobs[$sent['job']];
        $stale['status'] = 'processing';
        $this->fake->messages[$sent['job']][1]['current_status'] = 'submitted';
        $this->webhook('send.completed', $stale);
        self::assertSame('hard_bounced', $this->db->fetchOne('SELECT crp_status FROM cattomail_recipients WHERE crp_email = ?', ['ben@example.com']), 'a final state is never replaced');

        $this->fake->messages[$sent['job']][1]['current_status'] = 'hard_bounced';
        $this->webhook('send.completed', $this->fake->sendJobs[$sent['job']]);
        self::assertSame('completed', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_remote_id = ?', [$sent['job']]));
        $stale['status'] = 'dispatched';
        $this->webhook('send.completed', $stale);
        self::assertSame('completed', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_remote_id = ?', [$sent['job']]), 'the job never moves back');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT s_bounces FROM subscribers WHERE s_id = ?', [$sent['ids'][1]]));
    }

    public function testHardBounceTakesTheSubscriberOutOfCampaignsButKeepsConsent(): void
    {
        $sent = $this->sentCampaign(['ann@example.com', 'ben@example.com']);
        $this->fake->deliver($sent['job'], ['ben@example.com' => 'hard_bounced']);
        $other = $this->createMessage('Next', [(int) $this->db->fetchOne("SELECT l_id FROM lists WHERE l_shortcode = 'NEWS'")]);
        $this->db->executeStatement("INSERT INTO queue (q_muid, q_s_uuid, q_email) VALUES (?, ?, 'ben@example.com')", [$other, $sent['uuids'][1]]);

        $this->webhook('message.hard_bounced', $this->messageData($sent['job'], 'ben@example.com', 'hard_bounced', 'hard_bounce'));

        self::assertSame(['hard_bounced', 1], array_values((array) $this->db->fetchAssociative('SELECT s_delivery_state, s_bounces FROM subscribers WHERE s_id = ?', [$sent['ids'][1]])));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue WHERE q_s_uuid = ?', [$sent['uuids'][1]]), 'queued deliveries removed');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND ls_unsubscribed', [$sent['ids'][1]]), 'consent unchanged');
        self::assertSame(1, $this->service(\App\Queue\QueueBuilder::class)->queueMessage($other)->queued, 'only the other subscriber can be queued');
        self::assertSame([], $this->fake->requestsTo('POST', '/global-suppressions'), 'nothing reported back to catto-mail');
    }

    public function testComplaintUnsubscribesFromEveryListWithoutGlobalOptOut(): void
    {
        $sent = $this->sentCampaign(['ann@example.com', 'ben@example.com']);
        $this->fake->deliver($sent['job'], ['ann@example.com' => 'complained']);

        $this->webhook('message.complained', $this->messageData($sent['job'], 'ann@example.com', 'complained', 'complaint'));

        self::assertSame('complained', $this->db->fetchOne('SELECT s_delivery_state FROM subscribers WHERE s_id = ?', [$sent['ids'][0]]));
        $memberships = $this->db->fetchAllAssociative('SELECT ls_unsubscribed, ls_unsubscribe_reason FROM list_subscribers WHERE ls_s_id = ?', [$sent['ids'][0]]);
        self::assertNotEmpty($memberships);
        foreach ($memberships as $membership) {
            self::assertTrue((bool) $membership['ls_unsubscribed']);
            self::assertSame(DeliveryProblems::COMPLAINT_REASON, $membership['ls_unsubscribe_reason']);
        }
        self::assertSame('ok', $this->db->fetchOne('SELECT s_delivery_state FROM subscribers WHERE s_id = ?', [$sent['ids'][1]]));
        self::assertSame([], $this->fake->requestsTo('POST', '/global-suppressions'), 'a complaint is not a catto-mail opt-out request');
    }

    /** catto-mail may report the same outcome in several events (and polling sees it too): the effect is applied once. */
    public function testBounceAndComplaintEffectsApplyOnceWhateverTheEventId(): void
    {
        $sent = $this->sentCampaign(['ann@example.com', 'ben@example.com']);
        $this->fake->deliver($sent['job'], ['ann@example.com' => 'complained', 'ben@example.com' => 'hard_bounced']);
        foreach ([1, 2] as $copy) {
            $this->webhook('message.hard_bounced', $this->messageData($sent['job'], 'ben@example.com', 'hard_bounced', 'hard_bounce'), $this->eventId());
            $this->webhook('message.complained', $this->messageData($sent['job'], 'ann@example.com', 'complained', 'complaint'), $this->eventId());
        }
        $this->webhook('send.completed', $this->fake->sendJobs[$sent['job']]);

        self::assertSame(1, (int) $this->db->fetchOne('SELECT s_bounces FROM subscribers WHERE s_id = ?', [$sent['ids'][1]]), 'subscriber bounce count once');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT m_bounces FROM messages WHERE m_uniqid = ?', [$sent['muid']]), 'message bounce count once');
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM list_subscription_events e JOIN list_subscribers ls ON ls.ls_id = e.lse_ls_id
             JOIN lists l ON l.l_id = ls.ls_l_id WHERE ls.ls_s_id = ? AND l.l_shortcode = 'NEWS' AND e.lse_event = 'unsubscribed'", [$sent['ids'][0]]
        ), 'one unsubscription per list');
    }

    /** Clearing the block lets the subscriber be selected again; it restores no membership or consent. */
    public function testClearingTheBlockDoesNotResubscribe(): void
    {
        $sent = $this->sentCampaign(['ann@example.com']);
        $this->fake->deliver($sent['job'], ['ann@example.com' => 'complained']);
        $this->webhook('message.complained', $this->messageData($sent['job'], 'ann@example.com', 'complained', 'complaint'));
        $subscribers = $this->service(\App\Repository\SubscriberRepository::class);

        self::assertSame('complained', $subscribers->clearDeliveryProblem($sent['ids'][0]));
        self::assertSame(['state' => 'ok', 'at' => null], $subscribers->deliveryState($sent['ids'][0]));
        self::assertSame('ok', $subscribers->clearDeliveryProblem($sent['ids'][0]), 'nothing left to clear');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND NOT ls_unsubscribed', [$sent['ids'][0]]),
            'still unsubscribed from every list');
        $next = $this->createMessage('Next', [(int) $this->db->fetchOne("SELECT l_id FROM lists WHERE l_shortcode = 'NEWS'")]);
        self::assertSame(0, $this->service(\App\Queue\QueueBuilder::class)->queueMessage($next)->queued, 'so not queued until they rejoin');
    }

    public function testEventsAboutOtherJobsOrRecipientsAreIgnored(): void
    {
        $sent = $this->sentCampaign(['ann@example.com']);
        $this->fake->deliver($sent['job'], ['ann@example.com' => 'hard_bounced']);
        $data = $this->messageData($sent['job'], 'ann@example.com', 'hard_bounced', 'hard_bounce');
        $data['message']['send_job_id'] = '0199a000-0000-7000-8000-0000000fffff';
        $this->webhook('message.hard_bounced', $data, $id = $this->eventId());
        self::assertSame('recipient does not belong to that send job', $this->db->fetchOne('SELECT cwe_outcome FROM cattomail_webhook_events WHERE cwe_event_id = ?', [$id]));
        self::assertSame('ok', $this->db->fetchOne('SELECT s_delivery_state FROM subscribers WHERE s_id = ?', [$sent['ids'][0]]));

        $data['message']['external_recipient_reference'] = '0199a000-0000-7000-8000-0000000eeeee';
        $this->webhook('message.hard_bounced', $data, $id = $this->eventId());
        self::assertSame('unknown recipient', $this->db->fetchOne('SELECT cwe_outcome FROM cattomail_webhook_events WHERE cwe_event_id = ?', [$id]));
    }
}
