<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\Queue\QueueBuilder;
use App\Queue\QueueProcessor;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\FakeCattoMail;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * catto-mail integration tests: FakeCattoMail at the HTTP boundary, and
 * webhooks delivered through the real HTTP kernel (handle, then terminate,
 * where stored events are processed) with catto-mail's signature format.
 */
abstract class CattoMailTestCase extends IntegrationTestCase
{
    protected const SECRET = 'whsec_test_current';
    protected const PREVIOUS_SECRET = 'whsec_test_previous';

    protected FakeCattoMail $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = $this->fakeCattoMail();
    }

    /**
     * POST a webhook event as catto-mail's worker does.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers extra or replaced headers
     */
    protected function webhook(string $type, array $data, ?string $eventId = null, string $secret = self::SECRET, ?int $timestamp = null, ?string $body = null, array $headers = []): Response
    {
        $eventId ??= $this->eventId();
        $body ??= (string) json_encode(['id' => $eventId, 'type' => $type, 'created_at' => '2026-10-06T10:00:00Z', 'data' => $data], JSON_UNESCAPED_SLASHES);
        $timestamp ??= $this->service(ClockInterface::class)->now()->getTimestamp();
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'Catto-Mail-Smarthost/0.1.7',
            'HTTP_SMARTHOST_EVENT_ID' => $eventId,
            'HTTP_SMARTHOST_EVENT_TYPE' => $type,
            'HTTP_SMARTHOST_DELIVERY_ATTEMPT' => '1',
            'HTTP_SMARTHOST_SIGNATURE' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create('/cattomail/webhook', 'POST', [], [], [], $server, $body);
        $kernel = self::$kernel ?? throw new \LogicException('No kernel.');
        $response = $kernel->handle($request);
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
        return $response;
    }

    protected function eventId(): string
    {
        return sprintf('0199b000-0000-7000-8000-%012x', random_int(1, 0xFFFFFFFFFFFF));
    }

    /**
     * Subscribers on a NEWS list and a queued, sent campaign to them.
     *
     * @param list<string> $emails
     * @return array{muid: string, ids: list<int>, uuids: list<string>, job: string}
     */
    protected function sentCampaign(array $emails): array
    {
        $news = $this->createList('NEWS', 'News');
        $ids = [];
        $uuids = [];
        foreach ($emails as $email) {
            $ids[] = $id = $this->createSubscriber($email);
            $this->setMembership($id, $news, true);
            $uuids[] = $this->subscriberUuid($id);
        }
        $muid = $this->createMessage('Campaign', [$news]);
        $this->service(QueueBuilder::class)->queueMessage($muid);
        $outcome = $this->service(QueueProcessor::class)->process();
        self::assertSame(count($emails), $outcome->handedOff, (string) $outcome->problem);
        return ['muid' => $muid, 'ids' => $ids, 'uuids' => $uuids, 'job' => $this->fake->lastSendJobId()];
    }

    /** @return array<string, mixed> catto-mail's MessageWebhookData for the recipient with this address */
    protected function messageData(string $jobId, string $email, string $status, string $eventType): array
    {
        foreach ($this->fake->messages[$jobId] ?? [] as $message) {
            if ($message['recipient_address'] === $email) {
                $message['current_status'] = $status;
                return ['message' => $message, 'event' => ['id' => $this->eventId(), 'message_id' => $message['id'], 'event_type' => $eventType,
                    'smtp_code' => 550, 'enhanced_status_code' => '5.1.1', 'remote_host' => 'mx.example.com', 'diagnostic' => 'User unknown', 'occurred_at' => '2026-10-06T10:02:00Z']];
            }
        }
        throw new \LogicException('No message for ' . $email);
    }
}
