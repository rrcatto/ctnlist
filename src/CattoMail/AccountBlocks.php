<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\CattoMailBlockRepository;

/**
 * An administrator's block of an address or a whole domain (owner decision 2026-10-27),
 * kept by catto-mail as an account block: POST /v1/blocks stops this account's mail to it at
 * send time, from every application on the account; POST /v1/blocks/{id}/lift lifts it.
 * Recorded here first with its Idempotency-Key and reported at once, or by the worker when
 * catto-mail cannot be reached or is not connected yet. While pending or active it also stops the address here
 * (BlockAwareChecker: no confirmation, no queueing, no staging).
 *
 * Only an administrator's action creates one (a subscriber's page, Bulk unsubscribe). A
 * subscriber's own unsubscribe, even from every list, is never reported to catto-mail.
 *
 * @phpstan-import-type Block from CattoMailBlockRepository
 */
final class AccountBlocks
{
    public function __construct(
        private readonly CattoMailBlockRepository $blocks,
        private readonly CattoMailClient $client,
        private readonly CattoMailConfig $config,
    ) {
    }

    /**
     * Record a block and report it (or leave it for the worker; the stored key makes the
     * retry safe). Blocking a value that is already blocked returns that block.
     *
     * @param 'address'|'domain' $scope
     * @return Block
     */
    public function block(string $scope, string $value, ?int $adminId): array
    {
        $value = self::normalise($scope, $value);
        $block = $this->blocks->create($scope, $value, $adminId);
        if ($block['cbl_status'] === 'pending' && $this->config->isConfigured()) {
            $this->report($block);
        }
        return $this->blocks->find($block['cbl_id']) ?? $block;
    }

    /**
     * Lift a block (naturally idempotent at catto-mail; the worker retries it). Nobody is
     * re-subscribed: the block only stops mail.
     *
     * @return string the block's state afterwards (lifted, or lift_pending until reported)
     */
    public function unblock(string $uuid, ?int $adminId): string
    {
        $block = $this->blocks->findByUuid($uuid) ?? throw new \InvalidArgumentException('Unknown block.');
        if ($block['cbl_status'] === 'pending' && $block['cbl_remote_id'] === null && $block['cbl_attempts'] === 0) {
            // Never sent to catto-mail (not connected yet): there is nothing to lift there.
            $this->blocks->requestLift($block['cbl_id'], $adminId);
            $this->blocks->markLifted($block['cbl_id']);
        } elseif (in_array($block['cbl_status'], ['pending', 'active'], true)) {
            $this->blocks->requestLift($block['cbl_id'], $adminId);
            if ($this->config->isConfigured()) {
                $this->report($this->blocks->find($block['cbl_id']) ?? $block);
            }
        } elseif ($block['cbl_status'] === 'rejected') {
            // Never accepted by catto-mail: nothing to lift there.
            $this->blocks->markLifted($block['cbl_id']);
        }
        return ($this->blocks->find($block['cbl_id']) ?? $block)['cbl_status'];
    }

    /**
     * An administrator's retry: report a pending block or lift now (same stored key), or
     * report a refused block again.
     *
     * @return string the state afterwards
     */
    public function retry(string $uuid): string
    {
        $block = $this->blocks->findByUuid($uuid) ?? throw new \InvalidArgumentException('Unknown block.');
        if ($block['cbl_status'] === 'rejected') {
            $this->blocks->reopenRejected($block['cbl_id']);
        } elseif (!in_array($block['cbl_status'], ['pending', 'lift_pending'], true)) {
            return $block['cbl_status'];
        }
        $this->report($this->blocks->find($block['cbl_id']) ?? $block);
        return ($this->blocks->find($block['cbl_id']) ?? $block)['cbl_status'];
    }

    /**
     * Report a pending block or lift to catto-mail; retryable failures are kept for the worker.
     *
     * @param Block $block
     * @return bool false when it is kept for a retry or was refused
     */
    public function report(array $block): bool
    {
        try {
            if ($block['cbl_remote_id'] === null && in_array($block['cbl_status'], ['pending', 'lift_pending'], true)) {
                // Created first even when already being lifted: the stored key may have reached catto-mail.
                $remote = $this->client->createBlock($block['cbl_idempotency_key'], $block['cbl_scope'], $block['cbl_value'], $block['cbl_uuid']);
                $this->blocks->markActive($block['cbl_id'], (string) ($remote['id'] ?? ''));
                $block['cbl_remote_id'] = (string) ($remote['id'] ?? '');
            }
            if ($block['cbl_status'] === 'lift_pending' && $block['cbl_remote_id'] !== null && $block['cbl_remote_id'] !== '') {
                $this->client->liftBlock($block['cbl_remote_id']);
                $this->blocks->markLifted($block['cbl_id']);
            }
            return true;
        } catch (CattoMailRejected $e) {
            if ($block['cbl_status'] === 'lift_pending' && $block['cbl_remote_id'] !== null) {
                // The block itself stands; only the lift was refused (the account may not lift now).
                $this->blocks->keepActive($block['cbl_id'], $e->getMessage());
            } else {
                $this->blocks->markRejected($block['cbl_id'], $e->getMessage());
            }
            return false;
        } catch (CattoMailUnavailable $e) {
            $this->blocks->noteAttempt($block['cbl_id'], $e->getMessage());
            return false;
        }
    }

    /**
     * The value as catto-mail keeps it: an address with its domain lower-cased (the local
     * part is never case-folded there), a domain lower-cased, without a final dot.
     */
    public static function normalise(string $scope, string $value): string
    {
        $value = trim($value);
        if ($scope === 'domain') {
            $domain = strtolower(rtrim(ltrim($value, '@'), '.'));
            if (preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a domain name such as example.org.', $value));
            }
            return $domain;
        }
        $at = strrpos($value, '@');
        if ($scope !== 'address' || $at === false || $at === 0) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an email address.', $value));
        }
        return substr($value, 0, $at) . '@' . strtolower(substr($value, $at + 1));
    }
}
