<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ListRepository;
use App\Repository\MembershipRepository;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Security\AuthenticationPrompt;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use App\Subscriber\ConsentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function unsubscribeForm(string $token, string $shortcode, string $muid, #[CurrentUser] ?SubscriberUser $user): Response
    {
        $list = $this->listFor($token, $shortcode);
        if (!$this->isOwner($token, $user)) {
            return $this->prompt($token, 'unsubscribe', $muid, $list);
        }
        $this->denyAccessUnlessGranted('lists.unsubscribe');
        return $this->render('consent/unsubscribe.html.twig', ['token' => strtolower($token), 'list' => $list, 'muid' => $muid]);
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
            return $this->render('consent/subscribe.html.twig', ['lists' => [$list], 'message_id' => $message['m_id']]);
        }

        if ($user !== null) {
            return $this->render('consent/subscribe.html.twig', ['memberships' => $memberships->forSubscriber($user->id)]);
        }
        return $this->render('consent/subscribe.html.twig', ['lists' => $this->lists->all(), 'message_id' => null]);
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
