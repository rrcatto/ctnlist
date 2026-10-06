<?php

declare(strict_types=1);

namespace App\Controller;

use App\Campaign\ForwardService;
use App\Campaign\MessageService;
use App\Campaign\ReactionService;
use App\CattoMail\SendOutcome;
use App\Config\SiteConfig;
use App\Form\Model\ForwardRequest;
use App\Form\Type\ForwardType;
use App\Http\RequestThrottle;
use App\Log\MessageLog;
use App\Repository\ArchiveRepository;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Security\AuthenticationPrompt;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
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
        private readonly RequestThrottle $throttle,
    ) {
    }

    /** v5 administrator convenience: forward any message as yourself. */
    #[Route('/forward/{muid}', name: 'message_forward_admin', methods: ['GET'])]
    #[IsGranted('messages.manage')]
    public function forwardAsAdministrator(string $muid, #[CurrentUser] SubscriberUser $user): Response
    {
        $this->message($muid);
        return $this->forwardPage($this->createForwardForm(self::forwardData($user->uuid, $muid)));
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
        return $this->forwardPage($this->createForwardForm(self::forwardData(strtolower($token), $muid)));
    }

    #[Route('/forward', name: 'message_forward_submit', methods: ['POST'])]
    public function forwardMessage(Request $request, #[CurrentUser] ?SubscriberUser $user, ForwardService $forwards): Response
    {
        $form = $this->createForwardForm(new ForwardRequest());
        $form->handleRequest($request);
        /** @var ForwardRequest $data */
        $data = $form->getData();
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->forwardPage($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $token = $this->forwarder((string) $data->token, $user);
        return $this->forwardResult($forwards, $token, $this->message(trim((string) $data->muid)), $data->emails, 'Forward message');
    }

    /** The forward form on an archive page; invalid input shows the archive again with the form. */
    #[Route('/forward-archive', name: 'message_forward_archive', methods: ['POST'])]
    public function forwardArchive(Request $request, #[CurrentUser] ?SubscriberUser $user, ForwardService $forwards, ArchiveRepository $archives, SiteConfig $site): Response
    {
        if (!$site->archiveEnabled) {
            throw $this->createNotFoundException('The archive is not published.');
        }
        $form = $this->createForm(ForwardType::class, new ForwardRequest(), ['archive' => true, 'action' => $this->generateUrl('message_forward_archive')]);
        $form->handleRequest($request);
        /** @var ForwardRequest $data */
        $data = $form->getData();
        if (!$form->isSubmitted() || !$form->isValid()) {
            $archive = $archives->find((int) $data->archiveId) ?? throw $this->createNotFoundException('Unknown archive.');
            return $this->render('archive/show.html.twig', ['archive' => $archive, 'form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        $token = $this->forwarder((string) $data->token, $user);
        $muid = trim((string) $data->muid);
        $message = $muid !== '' ? $this->message($muid) : $this->messages->findByArchiveId((int) $data->archiveId);
        if ($message === null) {
            return $this->result('Forward archive', 'Cannot find the message associated with this archive.');
        }
        return $this->forwardResult($forwards, $token, $message, $data->emails, 'Forward archive');
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
        if (!$this->isGranted('messages.manage') && !$this->throttle->allow('forward', ['subscriber:' . $token])) {
            return $this->tooMany('Send message again');
        }
        $outcome = $messages->sendTo($muid, $user->email, 'RESEND');
        return $this->result('Send message again', match ($outcome->status) {
            SendOutcome::SUBMITTED => 'The current version was sent.',
            SendOutcome::DEFERRED => 'The current version will be sent shortly.',
            default => 'The message could not be sent.',
        });
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
        if (!$this->isGranted('messages.manage') && !$this->throttle->allow('forward', ['subscriber:' . $token])) {
            return $this->tooMany($title);
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
    private function forwarder(string $token, ?SubscriberUser $user): string
    {
        $token = strtolower(trim($token));
        if (!$this->isOwner($token, $user)) {
            $this->denyAccessUnlessGranted('messages.manage');
        }
        return $token;
    }

    private static function forwardData(string $token, string $muid): ForwardRequest
    {
        $data = new ForwardRequest();
        $data->token = $token;
        $data->muid = $muid;
        return $data;
    }

    private function createForwardForm(ForwardRequest $data): FormInterface
    {
        return $this->createForm(ForwardType::class, $data, ['action' => $this->generateUrl('message_forward_submit')]);
    }

    private function forwardPage(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('message/forward.html.twig', ['form' => $form], new Response(status: $status));
    }

    private function prompt(string $token, string $action, string $muid): Response
    {
        $context = $this->prompt->for(strtolower(trim($token)), $action, $muid) ?? throw $this->createNotFoundException('Unknown subscriber.');
        return $this->render('auth/prompt.html.twig', $context);
    }

    private function tooMany(string $title): Response
    {
        return $this->render('page/result.html.twig', ['title' => $title, 'message' => 'You have sent too many messages recently. Please try again later.'],
            new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
    }

    private function result(string $title, string $message): Response
    {
        return $this->render('page/result.html.twig', ['title' => $title, 'message' => $message]);
    }
}
