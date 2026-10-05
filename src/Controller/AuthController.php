<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SubscriberRepository;
use App\Security\Csrf;
use App\Security\MagicLinkRequester;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** Requesting sign-in links (redeeming them is MagicLinkAuthenticator's job). */
final class AuthController extends AbstractController
{
    public function __construct(private readonly MagicLinkRequester $requester)
    {
    }

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(): Response
    {
        return $this->render('auth/login.html.twig', ['return_action' => 'profile']);
    }

    #[Route('/login', name: 'login_request', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function requestLink(Request $request): Response
    {
        $messageId = $request->request->getInt('return_message_id');
        $listId = $request->request->getInt('return_list_id');
        $this->requester->request(
            $request->request->getString('email'),
            trim($request->request->getString('return_action')) ?: 'profile',
            $messageId > 0 ? $messageId : null,
            $listId > 0 ? $listId : null
        );
        return $this->render('auth/link_sent.html.twig', ['message' => 'If the address is valid, a secure sign-in link has been sent.']);
    }

    /** From the authentication prompt on a subscriber link. */
    #[Route('/auth/request', name: 'auth_request', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function requestForSubscriber(Request $request, SubscriberRepository $subscribers): Response
    {
        $identity = $subscribers->findIdentityByUuid($request->request->getString('subscriber_token'))
            ?? throw $this->createNotFoundException('Unknown subscriber.');
        $this->requester->request(
            $identity['s_email'],
            $request->request->getString('return_action'),
            $request->request->getInt('message_id') ?: null,
            $request->request->getInt('list_id') ?: null
        );
        return $this->render('auth/link_sent.html.twig', ['message' => 'If the request is valid, a secure sign-in link has been sent.']);
    }
}
