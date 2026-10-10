<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Config\SiteConfig;
use App\Log\MessageActivity;
use App\Log\MessageLog;
use App\Mail\TransactionalMailer;
use App\Repository\MembershipRepository;
use App\Repository\SubscriberRepository;
use App\Security\SubscriberUser;
use App\Suppression\SuppressionChecker;
use Psr\Clock\ClockInterface;

/**
 * Per-list consent changed by the subscriber: confirming and unsubscribing,
 * with the v5 engagement, smlog and notification effects. Globally
 * suppressed addresses cannot be confirmed; a global unsubscribe disables
 * every local membership and records the suppression.
 *
 * @phpstan-import-type MailingList from \App\Repository\ListRepository
 */
final class ConsentService
{
    /** Unsubscribe scopes offered on the form. */
    public const SCOPES = ['list', 'global', 'bounce', 'spam'];

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly SubscriberRepository $subscribers,
        private readonly SuppressionChecker $suppression,
        private readonly Engagement $engagement,
        private readonly MessageLog $messageLog,
        private readonly TransactionalMailer $mailer,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param MailingList $list
     * @return string the message shown to the subscriber
     */
    public function confirm(SubscriberUser $user, array $list, string $muid = ''): string
    {
        if ($this->suppression->isSuppressed($user->email)) {
            return 'This email address or domain is globally suppressed and cannot be confirmed for bulk mail.';
        }
        $this->memberships->confirm($user->id, $list['l_id'], $this->now());
        $priority = $this->subscribers->findRecipientByUuid($user->uuid)['s_priority'] ?? 0;
        $this->engagement->set($user->uuid, $priority + 10);
        if ($muid !== '') {
            $this->messageLog->record($user->uuid, $muid, MessageActivity::Confirm);
        }

        $this->notify($user->email, $user->uuid, trim($user->firstName . ' ' . $user->lastName), 'CONFIRM', $list, $muid,
            $user->email . ' has confirmed subscription to ' . $list['l_name'],
            '<p>' . self::e($user->email) . ' has confirmed their subscription to <strong>' . self::e($list['l_name']) . '</strong>.</p>',
            "{$user->email} has confirmed their subscription to {$list['l_name']}.\n");
        return 'Your subscription to ' . $list['l_name'] . ' is confirmed.';
    }

    /**
     * @param MailingList $list
     * @param string $scope list (this list only), or global/bounce/spam (all
     *                      local lists plus a global suppression of that kind)
     * @param bool $byAdministrator records the suppression as an administrator action
     * @return string the message shown to the subscriber
     */
    public function unsubscribe(SubscriberUser $user, array $list, string $scope, string $reason, string $muid = '', bool $byAdministrator = false): string
    {
        return $this->unsubscribeSubscriber($user->id, $user->uuid, $user->email, trim($user->firstName . ' ' . $user->lastName), $list, $scope, $reason, $muid, $byAdministrator);
    }

    /**
     * The one-click unsubscribe link of a delivered message (List-Unsubscribe,
     * RFC 8058): this list only. Ordinary unsubscribe state stays in ctnlist;
     * it is never reported to catto-mail.
     *
     * @param array{s_id: int, s_uuid: string, s_email: string, s_fname: string, s_lname: string} $subscriber
     * @param MailingList $list
     */
    public function unsubscribeByLink(array $subscriber, array $list, string $muid = ''): string
    {
        return $this->unsubscribeSubscriber($subscriber['s_id'], $subscriber['s_uuid'], $subscriber['s_email'], trim($subscriber['s_fname'] . ' ' . $subscriber['s_lname']),
            $list, 'list', 'One-click unsubscribe link', $muid, false);
    }

    /** @param MailingList $list */
    private function unsubscribeSubscriber(int $id, string $uuid, string $email, string $name, array $list, string $scope, string $reason, string $muid, bool $byAdministrator): string
    {
        $reason = trim($reason);
        $global = in_array($scope, ['global', 'bounce', 'spam'], true);
        $suppressed = false;
        if ($global) {
            $this->memberships->unsubscribeAll($id, $reason, $this->now());
            $type = ['global' => 'USER', 'bounce' => 'BOUNCE', 'spam' => 'SPAM'][$scope];
            if ($byAdministrator) {
                $type = $scope === 'global' ? 'ADMIN' : $type . '-ADMIN';
            }
            $suppressed = $this->suppression->suppressEmail($email, $type, $reason);
        } elseif (!$this->memberships->unsubscribe($id, $list['l_id'], $reason, $this->now())) {
            // Already unsubscribed (a second click, a replayed one-click link): nothing changed,
            // so nothing is recorded and nobody is notified again.
            return 'You have been unsubscribed from ' . $list['l_name'] . '.';
        }

        $this->engagement->reset($uuid);
        if ($muid !== '') {
            $this->messageLog->record($uuid, $muid, MessageActivity::Unsubscribe);
        }

        $why = $reason !== '' ? $reason : 'No reason supplied';
        $resubscribe = rtrim($this->site->baseUrl, '/') . '/confirm/' . rawurlencode($uuid) . '/' . rawurlencode($list['l_shortcode'])
            . ($muid !== '' ? '/' . rawurlencode($muid) : '');
        $this->notify($email, $uuid, $name, 'UNSUBSCRIBE', $list, $muid,
            $email . ' has been unsubscribed from ' . $list['l_name'],
            '<p>' . self::e($email) . ' has been unsubscribed from <strong>' . self::e($list['l_name']) . '</strong>.</p>'
                . '<p>Reason: ' . self::e($why) . '</p><p><a href="' . self::e($resubscribe) . '">Re-subscribe</a></p>',
            "{$email} has been unsubscribed from {$list['l_name']}.\nReason: {$why}\nRe-subscribe: {$resubscribe}\n");

        if (!$global) {
            return 'You have been unsubscribed from ' . $list['l_name'] . '.';
        }
        return $suppressed
            ? 'You have been unsubscribed from all local lists and added to the global suppression database.'
            : 'You have been unsubscribed from all local lists, but the global suppression database could not be updated. Please try again later.';
    }

    /** @param MailingList $list */
    private function notify(string $email, string $uuid, string $name, string $type, array $list, string $muid, string $subject, string $html, string $text): void
    {
        $this->mailer->sendNotification($muid, $type, $this->site->fromAddress, $email, $name, $subject, $html, $text, $uuid, $list['l_shortcode']);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
