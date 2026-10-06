<?php

declare(strict_types=1);

namespace App\Controller;

use App\CattoMail\GlobalOptOut;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The signed-in subscriber's explicit request not to receive email from any
 * sender using the catto-mail installation (and its withdrawal). This is not
 * an unsubscribe: unsubscribing stays in ctnlist (ConsentController).
 */
final class GlobalOptOutController extends AbstractController
{
    #[Route('/no-contact', name: 'global_optout', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function request(#[CurrentUser] ?SubscriberUser $user, GlobalOptOut $optOut): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        try {
            $optOut->request($user->id, $user->uuid, $user->email);
        } catch (\InvalidArgumentException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        return $this->render('page/result.html.twig', ['title' => 'No email from any sender', 'message' =>
            'Done. You have been unsubscribed from all our lists, and the mail service we use will not send you email from any sender. You can withdraw this on your profile page.']);
    }

    #[Route('/no-contact/withdraw', name: 'global_optout_withdraw', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function withdraw(#[CurrentUser] ?SubscriberUser $user, GlobalOptOut $optOut): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        $optOut->withdraw($user->id);
        $this->addFlash('info', 'Your request to receive no email from any sender has been withdrawn. To receive our lists again, subscribe to them.');
        return $this->redirectToRoute('profile');
    }
}
