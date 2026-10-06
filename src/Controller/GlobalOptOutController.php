<?php

declare(strict_types=1);

namespace App\Controller;

use App\CattoMail\GlobalOptOut;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    public function request(Request $request, #[CurrentUser] ?SubscriberUser $user, GlobalOptOut $optOut): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        if (!$optOut->isAvailable()) {
            throw $this->createNotFoundException('Stopping email from every sender is not available on this site.');
        }
        // A deliberate act: the box must be ticked (also without JavaScript, which only adds a dialog).
        if ($request->request->getString('confirm') !== 'yes') {
            $this->addFlash('danger', 'Nothing was changed: tick the box to confirm that you want no email from any sender using this mail service.');
            return $this->redirect($this->generateUrl('profile_subscriber', ['token' => $user->uuid]) . '#no-contact');
        }
        $result = $optOut->request($user->id, $user->uuid, $user->email);
        $message = match ($result['cgo_status']) {
            'active' => 'Done. You have been unsubscribed from all our lists, and the mail service we use will not send you email from any sender. You can withdraw this on your profile page.',
            'rejected' => 'You have been unsubscribed from all our lists, but the mail service did not accept the request to stop email from other senders. Please contact us.',
            default => 'You have been unsubscribed from all our lists. Your request to the mail service is recorded and will be passed on shortly; you can see its state on your profile page.',
        };
        return $this->render('page/result.html.twig', ['title' => 'No email from any sender', 'message' => $message]);
    }

    #[Route('/no-contact/withdraw', name: 'global_optout_withdraw', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function withdraw(#[CurrentUser] ?SubscriberUser $user, GlobalOptOut $optOut): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        $state = $optOut->withdraw($user->id);
        $this->addFlash('info', match ($state) {
            'lifted' => 'Your request to receive no email from any sender has been withdrawn.',
            null => 'You have no request to withdraw.',
            default => 'Your withdrawal is recorded and will be passed on to the mail service shortly.',
        } . ' This does not re-subscribe you: to receive our lists again, subscribe to them.');
        return $this->redirectToRoute('profile');
    }
}
