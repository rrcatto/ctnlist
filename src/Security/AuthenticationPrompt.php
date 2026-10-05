<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Subscriber\EmailNormaliser;

/**
 * The "verify you own this address" step for subscriber links (profile,
 * confirm, unsubscribe, forward, …) opened by someone not signed in as that
 * subscriber: offers a sign-in link that returns to the action.
 */
final class AuthenticationPrompt
{
    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly MessageRepository $messages,
    ) {
    }

    /**
     * Template variables for templates/auth/prompt.html.twig, or null when
     * the subscriber token is unknown (callers answer 404).
     *
     * @return array{masked_email: string, token: string, action: string, message_id: ?int, list_id: ?int}|null
     */
    public function for(string $subscriberToken, string $action, string $muid = '', ?int $listId = null): ?array
    {
        $identity = $this->subscribers->findIdentityByUuid($subscriberToken);
        if ($identity === null) {
            return null;
        }
        $message = $muid !== '' ? $this->messages->findByMuid($muid) : null;
        return [
            'masked_email' => EmailNormaliser::mask($identity['s_email']),
            'token' => $subscriberToken,
            'action' => $action,
            'message_id' => $message['m_id'] ?? null,
            'list_id' => $listId,
        ];
    }
}
