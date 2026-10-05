<?php

declare(strict_types=1);

namespace App\Controller;

use App\Campaign\ForwardService;
use App\Campaign\MessageService;
use App\Campaign\ReactionService;
use App\Log\MessageLog;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Security\AuthenticationPrompt;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What a recipient can do with a delivered message (links in the mail):
 * forward, like/dislike, have it sent again, and the open-tracking pixel.
 * Only the subscriber it was sent to may act (administrators may forward
 * any message); anyone else is asked to verify the address first.
 *
 * @phpstan-import-type Message from MessageRepository
 */
final class MessageActionController extends AbstractController
{
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly MessageLog $messageLog,
        private readonly AuthenticationPrompt $prompt,
    ) {
    }

    /** v5 administrator convenience: forward any message as yourself. */
    #[Route('/forward/{muid}', name: 'message_forward_admin', methods: ['GET'])]
    #[IsGranted('messages.manage')]
    public function forwardAsAdministrator(string $muid, #[CurrentUser] SubscriberUser $user): Response
    {
        $this->message($muid);
        return $this->render('message/forward.html.twig', ['token' => $user->uuid, 'muid' => $muid]);
    }

    #[Route('/forward/{token}/{muid}', name: 'message_forward', methods: ['GET'])]
    public function forwardForm(string $token, string $muid, #[CurrentUser] ?SubscriberUser $user): Response
    {
        if (!$this->isOwner($token, $user)) {
            return $this->prompt($token, 'forward', $muid);
        }
        if (!$this->isGranted('messages.manage')) {
            $this->requireDelivered($token, $muid);
        }
        return $this->render('message/forward.html.twig', ['token' => strtolower($token), 'muid' => $muid]);
    }

    #[Route('/forward', name: 'message_forward_submit', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function forwardMessage(Request $request, #[CurrentUser] ?SubscriberUser $user, ForwardService $forwards): Response
    {
        $token = $this->forwarder($request, $user);
        $message = $this->message(trim($request->request->getString('muid')));
        return $this->forwardResult($forwards, $token, $message, $request->request->getString('bemail'), 'Forward message');
    }

    /** The forward form on an archive page. */
    #[Route('/forward-archive', name: 'message_forward_archive', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function forwardArchive(Request $request, #[CurrentUser] ?SubscriberUser $user, ForwardService $forwards): Response
    {
        $token = $this->forwarder($request, $user);
        $muid = trim($request->request->getString('muid'));
        $message = $muid !== '' ? $this->message($muid) : $this->messages->findByArchiveId($request->request->getInt('aid'));
        if ($message === null) {
            return $this->result('Forward archive', 'Cannot find the message associated with this archive.');
        }
        return $this->forwardResult($forwards, $token, $message, $request->request->getString('bemail'), 'Forward archive');
    }

    #[Route('/{reaction}/{token}/{muid}', name: 'message_reaction', requirements: ['reaction' => 'like|dislike'], methods: ['GET'])]
    public function reactionForm(string $reaction, string $token, string $muid, #[CurrentUser] ?SubscriberUser $user): Response
    {
        if (!$this->isOwner($token, $user)) {
            return $this->prompt($token, $reaction, $muid);
        }
        $this->requireDelivered($token, $muid);
        return $this->render('message/reaction.html.twig', ['reaction' => $reaction, 'token' => strtolower($token), 'muid' => $muid]);
    }

    #[Route('/{reaction}', name: 'message_reaction_submit', requirements: ['reaction' => 'like|dislike'], methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function react(string $reaction, Request $request, #[CurrentUser] ?SubscriberUser $user, ReactionService $reactions): Response
    {
        $token = $this->ownToken($request, $user);
        $muid = trim($request->request->getString('muid'));
        $recorded = $reaction === 'like' ? $reactions->like($token, $muid) : $reactions->dislike($token, $muid);
        if (!$recorded) {
            throw $this->createNotFoundException('Message not sent to this subscriber.');
        }
        return $this->result(ucfirst($reaction) . ' message', $reaction === 'like'
            ? 'Thank you for your LIKE.'
            : 'We apologise for the lack of relevance of our message to you.');
    }

    #[Route('/resend/{token}/{muid}', name: 'message_resend', methods: ['GET'])]
    public function resendForm(string $token, string $muid, #[CurrentUser] ?SubscriberUser $user): Response
    {
        if (!$this->isOwner($token, $user)) {
            return $this->prompt($token, 'resend', $muid);
        }
        $this->requireDelivered($token, $muid);
        return $this->render('message/resend.html.twig', ['token' => strtolower($token), 'muid' => $muid]);
    }

    #[Route('/resend', name: 'message_resend_submit', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function resend(Request $request, #[CurrentUser] ?SubscriberUser $user, MessageService $messages): Response
    {
        $token = $this->ownToken($request, $user);
        $muid = trim($request->request->getString('muid'));
        $this->requireDelivered($token, $muid);
        \assert($user !== null);
        $sent = $messages->sendTo($muid, $user->email, 'RESEND');
        return $this->result('Send message again', $sent ? 'The current version was sent.' : 'The message could not be sent.');
    }

    /** The {usertrack} image: counts an open of a delivered message. */
    #[Route('/ut/{token}/{muid}', name: 'message_open', methods: ['GET'])]
    public function open(string $token, string $muid, ReactionService $reactions): Response
    {
        $reactions->open($token, $muid);
        return new Response((string) base64_decode(self::PIXEL), Response::HTTP_OK, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /** @return Message */
    private function message(string $muid): array
    {
        return $this->messages->findByMuid($muid) ?? throw $this->createNotFoundException('Unknown message.');
    }

    /** @param Message $message */
    private function forwardResult(ForwardService $forwards, string $token, array $message, string $input, string $title): Response
    {
        if (!$this->isGranted('messages.manage')) {
            $this->requireDelivered($token, $message['m_uniqid']);
        }
        $outcome = $forwards->forward($token, $message, $input);
        return $this->result($title, $outcome['problem'] ?? 'Forwarded the current message to ' . count($outcome['sent']) . ' recipient(s).');
    }

    private function isOwner(string $token, ?SubscriberUser $user): bool
    {
        return $user !== null && strtolower(trim($token)) === $user->uuid;
    }

    private function requireDelivered(string $token, string $muid): void
    {
        if (!$this->messageLog->wasSent(strtolower(trim($token)), $muid)) {
            throw $this->createNotFoundException('Message not sent to this subscriber.');
        }
    }

    /** The posted subscriber token, which must be the signed-in subscriber's (else 403). */
    private function ownToken(Request $request, ?SubscriberUser $user): string
    {
        $token = strtolower(trim($request->request->getString('subscriber_token')));
        if (!$this->isOwner($token, $user)) {
            throw $this->createAccessDeniedException('Not your message.');
        }
        return $token;
    }

    /** The forwarder: the signed-in subscriber, or anyone when an administrator forwards. */
    private function forwarder(Request $request, ?SubscriberUser $user): string
    {
        $token = strtolower(trim($request->request->getString('subscriber_token')));
        if (!$this->isOwner($token, $user)) {
            $this->denyAccessUnlessGranted('messages.manage');
        }
        return $token;
    }

    private function prompt(string $token, string $action, string $muid): Response
    {
        $context = $this->prompt->for(strtolower(trim($token)), $action, $muid) ?? throw $this->createNotFoundException('Unknown subscriber.');
        return $this->render('auth/prompt.html.twig', $context);
    }

    private function result(string $title, string $message): Response
    {
        return $this->render('page/result.html.twig', ['title' => $title, 'message' => $message]);
    }
}
