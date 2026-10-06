<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Config\SiteConfig;
use App\Mail\TransactionalMailer;
use App\Repository\SubscriberRepository;
use App\Security\SubscriberUser;

/**
 * Subscriber-edited profile details. Every subscriber-initiated change sends
 * a notification (BCC administrator) recorded in the Send Log, as in v5.
 */
final class ProfileService
{
    /** Editable fields and their maximum lengths. */
    private const FIELDS = [
        's_fname' => 100, 's_lname' => 100, 's_gender' => 30, 's_business' => 100,
        's_province' => 100, 's_country' => 100, 's_phone' => 30, 's_url' => 253,
    ];

    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly TransactionalMailer $mailer,
        private readonly SiteConfig $site,
    ) {
    }

    /** @param array<string, mixed> $input submitted form fields */
    public function update(SubscriberUser $user, array $input): void
    {
        $fields = [];
        foreach (self::FIELDS as $name => $length) {
            $fields[$name] = mb_substr(trim((string) ($input[$name] ?? '')), 0, $length);
        }
        $birthday = trim((string) ($input['s_birthday'] ?? ''));
        $fields['s_birthday'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday) === 1 ? $birthday : null;
        $this->subscribers->updateProfile($user->id, $fields);

        $list = $this->site->listName;
        $this->mailer->sendNotification(
            '',
            'UPDATE-USER',
            $this->site->fromAddress,
            $user->email,
            trim($fields['s_fname'] . ' ' . $fields['s_lname']),
            $list . ' notification: ' . $user->email . ' has updated their user profile',
            '<p>Profile for subscriber ' . htmlspecialchars($user->uuid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ' on ' . htmlspecialchars($list, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' has been updated.</p>',
            'Profile for subscriber ' . $user->uuid . ' on ' . $list . " has been updated.\n",
            $user->uuid,
        );
    }
}
