<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\SiteConfig;
use App\Repository\SubscriberRepository;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use App\Subscriber\ContactService;
use App\Subscriber\Engagement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The contact/order form. From a message's {contact}/{booking} link it is
 * prefilled for the subscriber and counts as strong engagement (v5 priority
 * +12345, or +23456 when the message is known); otherwise it requires
 * signing in.
 */
final class ContactController extends AbstractController
{
    #[Route('/contact-form', name: 'contact', methods: ['GET'])]
    public function form(#[CurrentUser] ?SubscriberUser $user, SiteConfig $site): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        return $this->render('contact/form.html.twig', ['token' => '', 'muid' => '', 'values' => [], 'realm' => $site->bookingUrl]);
    }

    #[Route('/contact-form/{token}/{muid}', name: 'contact_subscriber', defaults: ['muid' => ''], methods: ['GET'])]
    public function subscriberForm(string $token, string $muid, SubscriberRepository $subscribers, Engagement $engagement, SiteConfig $site): Response
    {
        $engagement->bump($token, $muid === '' ? 12345 : 23456);
        $profile = null;
        $identity = $subscribers->findIdentityByUuid($token);
        if ($identity !== null) {
            $profile = $subscribers->profile($identity['s_id']);
        }
        return $this->render('contact/form.html.twig', [
            'token' => $token,
            'muid' => $muid,
            'realm' => $site->bookingUrl,
            'values' => $profile === null ? [] : [
                'name' => trim($profile['s_fname'] . ' ' . $profile['s_lname']),
                'email' => $profile['s_email'],
                'cell' => $profile['s_phone'],
                'company' => $profile['s_business'],
                'website' => $profile['s_url'],
            ],
        ]);
    }

    #[Route('/contact-form', name: 'contact_submit', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function submit(Request $request, ContactService $contact): Response
    {
        $form = array_map('strval', array_intersect_key(
            $request->request->all(),
            array_flip(['name', 'email', 'cell', 'website', 'company', 'topic', 'message', 'realm'])
        ));
        $message = $contact->submit($form, [
            'subscriber_uuid' => $request->request->getString('suid'),
            'muid' => $request->request->getString('muid'),
            'ip' => (string) $request->getClientIp(),
            'user_agent' => (string) $request->headers->get('User-Agent', ''),
            'forwarded_for' => (string) $request->headers->get('X-Forwarded-For', ''),
        ]);
        return $this->render('page/result.html.twig', ['title' => 'Contact', 'message' => $message]);
    }
}
