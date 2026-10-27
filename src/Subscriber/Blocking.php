<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\CattoMail\AccountBlocks;
use App\Repository\CattoMailBlockRepository;
use App\Repository\MembershipRepository;
use App\Repository\QueueRepository;
use App\Repository\SubscriberRepository;
use App\Suppression\SuppressionChecker;
use Psr\Clock\ClockInterface;

/**
 * An administrator's blocks (owner decision 2026-10-27): an address, or every address at a
 * domain, that this site never mails again. catto-mail keeps each one as an account block
 * (AccountBlocks), so every application on the account stops too; where the installation also
 * keeps the banlist (SUPPRESSION_PROVIDER=banlist), the block is recorded there as well, as v5
 * did. A blocked address is unsubscribed from every list at once (a domain's addresses when the
 * queue is next built, as for a banlist entry). Unblocking stops nothing more and re-subscribes
 * nobody.
 *
 * @phpstan-import-type Block from CattoMailBlockRepository
 */
final class Blocking
{
    public const UNSUBSCRIBE_REASON = 'Blocked by an administrator';

    public function __construct(
        private readonly AccountBlocks $blocks,
        private readonly CattoMailBlockRepository $repository,
        private readonly SuppressionChecker $suppression,
        private readonly SubscriberRepository $subscribers,
        private readonly MembershipRepository $memberships,
        private readonly QueueRepository $queue,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string $type the banlist's type where one is kept (ADMIN, BOUNCE-ADMIN, SPAM-ADMIN)
     * @return Block
     */
    public function blockAddress(string $email, ?int $adminId, string $type = 'ADMIN', string $reason = ''): array
    {
        $block = $this->blocks->block('address', $email, $adminId);
        if ($this->suppression->keepsGlobalList()) {
            $this->suppression->suppressEmail($email, $type, $reason !== '' ? $reason : self::UNSUBSCRIBE_REASON);
        }
        $identity = $this->subscribers->findIdentityByEmail($email);
        if ($identity !== null) {
            $this->memberships->unsubscribeAll($identity['s_id'], self::UNSUBSCRIBE_REASON, $this->clock->now()->format('Y-m-d H:i:s'));
            $this->queue->deleteForSubscriber($identity['s_uuid']);
        }
        return $block;
    }

    /** @return Block */
    public function blockDomain(string $domain, ?int $adminId): array
    {
        $block = $this->blocks->block('domain', $domain, $adminId);
        if ($this->suppression->keepsGlobalList()) {
            $this->suppression->suppressDomain($block['cbl_value'], 'SPAM');
        }
        return $block;
    }

    /** @return string the block's state afterwards (lifted, or lift_pending until catto-mail is told) */
    public function unblock(string $uuid, ?int $adminId): string
    {
        return $this->blocks->unblock($uuid, $adminId);
    }

    /** @return array{address: Block|null, domain: Block|null} the blocks that stop the address now */
    public function covering(string $email): array
    {
        return $this->repository->covering($email);
    }
}
