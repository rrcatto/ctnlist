<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\CattoMailOptOutRepository;
use App\Repository\MembershipRepository;
use App\Repository\QueueRepository;
use Psr\Clock\ClockInterface;

/**
 * An explicit recipient request not to receive email from any sender using
 * this catto-mail installation, reported with POST /v1/global-suppressions.
 *
 * This is deliberately separate from unsubscribing: an ordinary list,
 * newsletter, campaign or "all ctnlist lists" unsubscribe stays ctnlist
 * state and is never sent to catto-mail. Only this explicit request (offered
 * only when CATTOMAIL_GLOBAL_OPTOUT_ENABLED says catto-mail granted the
 * capability) creates a catto-mail opt-out. Because the recipient wants no
 * email at all, ctnlist also unsubscribes them from all its lists. A
 * withdrawal is reported with POST /v1/global-suppressions/{id}/lift (it
 * does not re-subscribe anyone).
 *
 * @phpstan-import-type OptOut from CattoMailOptOutRepository
 */
final class GlobalOptOut
{
    public const UNSUBSCRIBE_REASON = 'Asked for no email from any sender using catto-mail (global opt-out)';

    public function __construct(
        private readonly CattoMailOptOutRepository $optOuts,
        private readonly CattoMailClient $client,
        private readonly CattoMailConfig $config,
        private readonly MembershipRepository $memberships,
        private readonly QueueRepository $queue,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->config->globalOptOutEnabled && $this->config->isConfigured();
    }

    /** @return OptOut|null */
    public function current(int $subscriberId): ?array
    {
        return $this->optOuts->currentForSubscriber($subscriberId);
    }

    /**
     * Record the explicit request and report it (or leave it for the worker
     * when catto-mail cannot be reached; the stored key makes the retry safe).
     *
     * @return OptOut
     */
    public function request(int $subscriberId, string $subscriberUuid, string $email): array
    {
        if (!$this->isAvailable()) {
            throw new \InvalidArgumentException('Stopping email from every sender is not available on this site.');
        }
        $optOut = $this->optOuts->currentForSubscriber($subscriberId) ?? $this->optOuts->create($subscriberId, $email);
        $this->memberships->unsubscribeAll($subscriberId, self::UNSUBSCRIBE_REASON, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->queue->deleteForSubscriber($subscriberUuid);
        $this->report($optOut);
        return $this->optOuts->find($optOut['cgo_id']) ?? $optOut;
    }

    /**
     * The recipient withdrew the request: POST /v1/global-suppressions/{id}/lift
     * (naturally idempotent; the worker retries it). Lifting removes only this
     * opt-out at catto-mail and re-subscribes nobody here.
     *
     * @return string|null the opt-out's state afterwards (lifted, or lift_pending until reported), null when there was none
     */
    public function withdraw(int $subscriberId): ?string
    {
        $optOut = $this->optOuts->currentForSubscriber($subscriberId);
        if ($optOut === null) {
            return null;
        }
        if ($optOut['cgo_status'] !== 'lift_pending') {
            $this->optOuts->requestLift($optOut['cgo_id']);
            $this->report($this->optOuts->find($optOut['cgo_id']) ?? $optOut);
        }
        return ($this->optOuts->find($optOut['cgo_id']) ?? $optOut)['cgo_status'];
    }

    /**
     * Report a pending opt-out or lift to catto-mail; retryable failures are
     * kept for the worker.
     *
     * @param OptOut $optOut
     * @return bool false when it is kept for a retry or was refused
     */
    public function report(array $optOut): bool
    {
        try {
            if ($optOut['cgo_remote_id'] === null && in_array($optOut['cgo_status'], ['pending', 'lift_pending'], true)) {
                // Created first even when already withdrawn: the stored key may have reached catto-mail.
                $remote = $this->client->createGlobalOptOut($optOut['cgo_idempotency_key'], $optOut['cgo_email'], $optOut['cgo_uuid']);
                $this->optOuts->markActive($optOut['cgo_id'], (string) ($remote['id'] ?? ''));
                $optOut['cgo_remote_id'] = (string) ($remote['id'] ?? '');
            }
            if ($optOut['cgo_status'] === 'lift_pending' && $optOut['cgo_remote_id'] !== null && $optOut['cgo_remote_id'] !== '') {
                $this->client->liftGlobalOptOut($optOut['cgo_remote_id']);
                $this->optOuts->markLifted($optOut['cgo_id']);
            }
            return true;
        } catch (CattoMailRejected $e) {
            if ($optOut['cgo_status'] === 'lift_pending' && $optOut['cgo_remote_id'] !== null) {
                // The opt-out itself stands; only the withdrawal was refused.
                $this->optOuts->keepActive($optOut['cgo_id'], $e->getMessage());
            } else {
                $this->optOuts->markRejected($optOut['cgo_id'], $e->getMessage());
            }
            return false;
        } catch (CattoMailUnavailable $e) {
            $this->optOuts->noteAttempt($optOut['cgo_id'], $e->getMessage());
            return false;
        }
    }
}
