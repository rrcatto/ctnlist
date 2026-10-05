<?php

declare(strict_types=1);

namespace App\Controller;

use App\Log\MessageLog;
use App\Repository\MembershipRepository;
use App\Repository\SubscriberRepository;
use App\Security\AuthenticationPrompt;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use App\Subscriber\ProfileOptions;
use App\Subscriber\ProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The signed-in subscriber's own pages. Anonymous visitors are sent to the
 * login page, as in v5.
 */
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly MembershipRepository $memberships,
    ) {
    }

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function profile(#[CurrentUser] ?SubscriberUser $user): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        return $this->redirectToRoute('profile_subscriber', ['token' => $user->uuid]);
    }

    /** Opened from links in mail: the profile if it is yours, else verify ownership first. */
    #[Route('/profile/subscriber/{token}', name: 'profile_subscriber', methods: ['GET'])]
    public function subscriberProfile(string $token, #[CurrentUser] ?SubscriberUser $user, AuthenticationPrompt $prompt): Response
    {
        if ($user === null || strtolower(trim($token)) !== $user->uuid) {
            $context = $prompt->for(strtolower(trim($token)), 'profile') ?? throw $this->createNotFoundException('Unknown subscriber.');
            return $this->render('auth/prompt.html.twig', $context);
        }
        return $this->render('profile/show.html.twig', [
            'profile' => $this->subscribers->profile($user->id),
            'memberships' => $this->memberships->forSubscriber($user->id),
        ]);
    }

    #[Route('/edit-profile', name: 'profile_edit', methods: ['GET'])]
    public function edit(#[CurrentUser] ?SubscriberUser $user): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        return $this->render('profile/edit.html.twig', [
            'profile' => $this->subscribers->profile($user->id),
            'genders' => ProfileOptions::GENDERS,
            'provinces' => ProfileOptions::PROVINCES,
            'countries' => ProfileOptions::COUNTRIES,
        ]);
    }

    #[Route('/edit-profile', name: 'profile_save', methods: ['POST'])]
    public function save(Request $request, #[CurrentUser] ?SubscriberUser $user, ProfileService $profiles): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        if (!$this->isCsrfTokenValid(Csrf::TOKEN_ID, $request->request->getString(Csrf::FIELD))) {
            throw $this->createAccessDeniedException('Invalid request token.');
        }
        $profiles->update($user, $request->request->all());
        $this->addFlash('info', 'Your profile has been updated.');
        return $this->redirectToRoute('profile_edit');
    }

    #[Route('/my/messages', name: 'profile_messages', methods: ['GET'])]
    public function messages(#[CurrentUser] ?SubscriberUser $user, MessageLog $messageLog): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        return $this->render('profile/messages.html.twig', ['messages' => $messageLog->history($user->uuid)]);
    }
}
