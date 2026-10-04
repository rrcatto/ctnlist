<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ListRepository;
use App\Repository\MessageRepository;

/**
 * Where a magic link returns the subscriber: the action they were asked to
 * authenticate for (stored with the login token), else their profile.
 */
final class LoginReturnPath
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly ListRepository $lists,
    ) {
    }

    public function for(string $action, int $messageId, int $listId, string $subscriberUuid): string
    {
        $token = rawurlencode($subscriberUuid);
        $muid = $messageId > 0 ? rawurlencode((string) $this->messages->findMuidById($messageId)) : '';
        $shortcode = $listId > 0 ? rawurlencode((string) $this->lists->findShortcodeById($listId)) : '';
        $profile = "/profile/subscriber/{$token}";

        return match ($action) {
            'confirm' => $shortcode !== ''
                ? "/confirm/{$token}/{$shortcode}" . ($muid !== '' ? "/{$muid}" : '')
                : $profile,
            'unsubscribe' => $shortcode !== ''
                ? "/unsubscribe/{$token}/{$shortcode}" . ($muid !== '' ? "/{$muid}" : '')
                : $profile,
            'forward', 'like', 'dislike', 'resend' => $muid !== '' ? "/{$action}/{$token}/{$muid}" : $profile,
            'messages' => '/my/messages',
            default => $profile,
        };
    }
}
