<?php

declare(strict_types=1);

namespace App\Controller;

use App\CattoMail\GlobalOptOut;
use App\CattoMail\UnsubscribeLinks;
use App\Form\Model\SubscribeRequest;
use App\Form\Type\SubscribeType;
use App\Repository\ListRepository;
use App\Repository\MembershipRepository;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Security\AuthenticationPrompt;
use App\Security\Csrf;
use App\Security\MagicLinkRequester;
use App\Security\SubscriberUser;
use App\Subscriber\ConsentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Per-list consent links (/confirm, /unsubscribe, usually from mail) and the
 * subscribe entry point. Only the subscriber the link belongs to may act on
 * it; anyone else is asked to verify the address first. The optional MUID
 * ties the action to the message it came from (smlog).
 *
 * @phpstan-import-type MailingList from ListRepository
 */
final class ConsentController extends AbstractController
{
    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly ListRepository $lists,
        private readonly MessageRepository $messages,
        private readonly AuthenticationPrompt $prompt,
        private readonly ConsentService $consent,
    ) {
    }

    #[Route('/confirm/{token}/{shortcode}/{muid}', name: 'consent_confirm', defaults: ['muid' => ''], methods: ['GET'])]
    public function confirmForm(string $token, string $shortcode, string $muid, #[CurrentUser] ?SubscriberUser $user): Response
    {
        $list = $this->listFor($token, $shortcode);
        if (!$list['l_active']) {
            throw $this->createNotFoundException('Inactive list.');
        }
        if (!$this->isOwner($token, $user)) {
            return $this->prompt($token, 'confirm', $muid, $list);
        }
        $this->denyAccessUnlessGranted('lists.confirm');
        return $this->render('consent/confirm.html.twig', ['token' => strtolower($token), 'list' => $list, 'muid' => $muid]);
    }

    #[Route('/confirm', name: 'consent_confirm_submit', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function confirm(Request $request, #[CurrentUser] ?SubscriberUser $user): Response
    {
        $user = $this->actingSubscriber($request, $user, 'lists.confirm');
        $list = $this->lists->findByShortcode($request->request->getString('list_shortcode'));
        $message = $list === null
            ? 'The subscription could not be confirmed.'
            : $this->consent->confirm($user, $list, trim($request->request->getString('muid')));
        return $this->render('page/result.html.twig', ['title' => 'Subscription confirmed', 'message' => $message]);
    }

    #[Route('/unsubscribe/{token}/{shortcode}/{muid}', name: 'consent_unsubscribe', defaults: ['muid' => ''], methods: ['GET'])]
    public function unsubscribeForm(string $token, string $shortcode, string $muid, #[CurrentUser] ?SubscriberUser $user, GlobalOptOut $optOut): Response
    {
        $list = $this->listFor($token, $shortcode);
        if (!$this->isOwner($token, $user)) {
            return $this->prompt($token, 'unsubscribe', $muid, $list);
        }
        $this->denyAccessUnlessGranted('lists.unsubscribe');
        return $this->render('consent/unsubscribe.html.twig', ['token' => strtolower($token), 'list' => $list, 'muid' => $muid,
            'global_optout_available' => $optOut->isAvailable()]);
    }

    #[Route('/unsubscribe', name: 'consent_unsubscribe_submit', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function unsubscribe(Request $request, #[CurrentUser] ?SubscriberUser $user): Response
    {
        $user = $this->actingSubscriber($request, $user, 'lists.unsubscribe');
        $list = $this->lists->findByShortcode($request->request->getString('list_shortcode'));
        $scope = $request->request->getString('scope') ?: 'list';
        $message = $list === null || !in_array($scope, ConsentService::SCOPES, true)
            ? 'The subscription could not be updated.'
            : $this->consent->unsubscribe(
                $user,
                $list,
                $scope,
                $request->request->getString('reason'),
                trim($request->request->getString('muid')),
                $this->isGranted('subscribers.manage')
            );
        return $this->render('page/result.html.twig', ['title' => 'Unsubscribed', 'message' => $message]);
    }

    /**
     * The one-click unsubscribe link catto-mail sends as List-Unsubscribe
     * (RFC 8058). It needs no sign-in or CSRF token: the signature proves it
     * came from the message. GET only asks (link scanners must not
     * unsubscribe anyone); POST, which mail clients send with
     * `List-Unsubscribe=One-Click`, unsubscribes from that list only.
     */
    #[Route('/unsubscribe-link/{token}/{shortcode}/{muid}/{signature}', name: 'consent_unsubscribe_link', methods: ['GET', 'POST'])]
    public function unsubscribeLink(string $token, string $shortcode, string $muid, string $signature, Request $request, UnsubscribeLinks $links): Response
    {
        if (!$links->isValid($token, $shortcode, $muid, $signature)) {
            throw $this->createNotFoundException('Unknown unsubscribe link.');
        }
        $identity = $this->subscribers->findIdentityByUuid($token) ?? throw $this->createNotFoundException('Unknown subscriber.');
        $list = $this->lists->findByShortcode($shortcode) ?? throw $this->createNotFoundException('Unknown list.');
        $muid = $muid === '-' ? '' : $muid;
        if ($request->isMethod('POST')) {
            $message = $this->consent->unsubscribeByLink($identity, $list, $muid);
            return $this->render('page/result.html.twig', ['title' => 'Unsubscribed', 'message' => $message]);
        }
        return $this->render('consent/unsubscribe_link.html.twig', ['list' => $list, 'email' => $identity['s_email']]);
    }

    /**
     * The subscribe page. From the {subscribe} placeholder and the
     * List-Subscribe header it carries ?m=MUID[&l=SHORTCODE]: the list is
     * resolved (asking when the message has several) and a signed-in
     * subscriber goes straight to its confirmation. Otherwise signed-in
     * subscribers see every list with their membership, and visitors get a
     * subscribe form that emails them a link to confirm (double opt-in;
     * nothing is subscribed until they confirm).
     */
    #[Route('/subscribe', name: 'subscribe', methods: ['GET'])]
    public function subscribe(Request $request, #[CurrentUser] ?SubscriberUser $user, MembershipRepository $memberships): Response
    {
        $muid = trim($request->query->getString('m'));
        $shortcode = strtoupper(trim($request->query->getString('l')));
        $message = $muid !== '' ? $this->messages->findByMuid($muid) : null;
        $list = null;

        if ($message !== null) {
            if ($shortcode !== '') {
                $list = $this->lists->findByShortcode($shortcode);
            } else {
                $assigned = $this->messages->lists($message['m_id']);
                if (count($assigned) === 1) {
                    $list = $this->lists->findById($assigned[0]['l_id']);
                } elseif (count($assigned) > 1) {
                    return $this->render('consent/choose_list.html.twig', [
                        'muid' => $muid,
                        'lists' => array_values(array_filter($assigned, static fn(array $l): bool => $l['l_active'])),
                    ]);
                }
            }
        }

        if ($message !== null && $list !== null && $list['l_active']) {
            if ($user !== null) {
                return $this->redirectToRoute('consent_confirm', ['token' => $user->uuid, 'shortcode' => $list['l_shortcode'], 'muid' => $muid]);
            }
            return $this->subscribePage([$list], $message['m_id']);
        }

        if ($user !== null) {
            return $this->render('consent/subscribe.html.twig', ['memberships' => $memberships->forSubscriber($user->id)]);
        }
        return $this->subscribePage($this->lists->all(), null);
    }

    /**
     * The visitors' subscribe form: emails a link to confirm the chosen list
     * (MagicLinkRequester, return action "confirm"). Invalid input shows the
     * form again with the address and list kept.
     */
    #[Route('/subscribe/request', name: 'subscribe_request', methods: ['POST'])]
    public function subscribeRequest(Request $request, MagicLinkRequester $requester): Response
    {
        $lists = $this->lists->all();
        $form = $this->subscribeForm($lists, new SubscribeRequest());
        $form->handleRequest($request);
        /** @var SubscribeRequest $data */
        $data = $form->getData();
        $messageId = (int) $data->messageId > 0 ? (int) $data->messageId : null;
        if (!$form->isSubmitted() || !$form->isValid()) {
            // Coming from a message, the page offered only that message's list.
            $chosen = array_values(array_filter($lists, static fn(array $list): bool => $list['l_id'] === $data->listId));
            return $this->subscribePage($messageId !== null && $chosen !== [] ? $chosen : $lists, $messageId, $form);
        }
        $requester->request($data->email, 'confirm', $messageId, $data->listId);
        return $this->render('auth/link_sent.html.twig', ['message' => 'If the address is valid, we have emailed you a link to confirm your subscription. You are subscribed once you confirm.']);
    }

    /** @param list<array{l_id: int, l_name: string}> $lists */
    private function subscribeForm(array $lists, SubscribeRequest $data): FormInterface
    {
        return $this->createForm(SubscribeType::class, $data, ['lists' => $lists, 'action' => $this->generateUrl('subscribe_request')]);
    }

    /** @param list<array{l_id: int, l_name: string}> $lists */
    private function subscribePage(array $lists, ?int $messageId, ?FormInterface $form = null): Response
    {
        if ($form === null) {
            $data = new SubscribeRequest();
            $data->listId = $lists[0]['l_id'] ?? null;
            $data->messageId = $messageId === null ? null : (string) $messageId;
            $form = $this->subscribeForm($lists, $data);
        }
        return $this->render('consent/subscribe.html.twig', ['lists' => $lists, 'form' => $form],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @return MailingList the list of a consent link; 404 for an unknown subscriber or list */
    private function listFor(string $token, string $shortcode): array
    {
        if ($this->subscribers->findIdentityByUuid($token) === null) {
            throw $this->createNotFoundException('Unknown subscriber.');
        }
        return $this->lists->findByShortcode($shortcode) ?? throw $this->createNotFoundException('Unknown list.');
    }

    private function isOwner(string $token, ?SubscriberUser $user): bool
    {
        return $user !== null && strtolower(trim($token)) === $user->uuid;
    }

    /** @param MailingList $list */
    private function prompt(string $token, string $action, string $muid, array $list): Response
    {
        $context = $this->prompt->for(strtolower(trim($token)), $action, $muid, $list['l_id']) ?? throw $this->createNotFoundException();
        return $this->render('auth/prompt.html.twig', $context);
    }

    /** The signed-in subscriber the form was for, holding the permission; otherwise 404/403. */
    private function actingSubscriber(Request $request, ?SubscriberUser $user, string $permission): SubscriberUser
    {
        $token = strtolower(trim($request->request->getString('subscriber_token')));
        if ($this->subscribers->findIdentityByUuid($token) === null) {
            throw $this->createNotFoundException('Unknown subscriber.');
        }
        if ($user === null || $token !== $user->uuid) {
            throw $this->createAccessDeniedException('Not your subscription.');
        }
        $this->denyAccessUnlessGranted($permission);
        return $user;
    }
}
