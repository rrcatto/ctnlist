<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\SiteConfig;
use App\Form\Model\ContactRequest;
use App\Http\RequestThrottle;
use App\Form\Type\ContactType;
use App\Repository\SubscriberRepository;
use App\Security\SubscriberUser;
use App\Subscriber\ContactService;
use App\Subscriber\Engagement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The contact/order form. From a message's {contact}/{booking} link it is
 * prefilled for the subscriber and counts as strong engagement (v5 priority
 * +12345, or +23456 when the message is known); otherwise it requires
 * signing in.
 */
final class ContactController extends AbstractController
{
    private const TOO_MANY = 'Too many messages have been sent from here recently. Please try again later.';

    #[Route('/contact-form', name: 'contact', methods: ['GET'])]
    public function form(#[CurrentUser] ?SubscriberUser $user): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        return $this->page($this->contactForm(new ContactRequest()));
    }

    #[Route('/contact-form/{token}/{muid}', name: 'contact_subscriber', defaults: ['muid' => ''], methods: ['GET'])]
    public function subscriberForm(string $token, string $muid, SubscriberRepository $subscribers, Engagement $engagement): Response
    {
        $engagement->bump($token, $muid === '' ? 12345 : 23456);
        $request = new ContactRequest();
        $request->suid = $token;
        $request->muid = $muid;
        $identity = $subscribers->findIdentityByUuid($token);
        $profile = $identity === null ? null : $subscribers->profile($identity['s_id']);
        if ($profile !== null) {
            $request->name = trim($profile['s_fname'] . ' ' . $profile['s_lname']);
            $request->email = (string) $profile['s_email'];
            $request->cell = (string) $profile['s_phone'];
            $request->company = (string) $profile['s_business'];
            $request->website = (string) $profile['s_url'];
        }
        return $this->page($this->contactForm($request));
    }

    #[Route('/contact-form', name: 'contact_submit', methods: ['POST'])]
    public function submit(Request $request, ContactService $contact, SiteConfig $site, RequestThrottle $throttle): Response
    {
        $form = $this->contactForm(new ContactRequest());
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->redirectToRoute('contact');
        }
        if (!$form->isValid()) {
            return $this->page($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var ContactRequest $data */
        $data = $form->getData();
        if (!$throttle->allow('contact', ['ip:' . $request->getClientIp(), 'email:' . $data->email])) {
            return $this->render('page/result.html.twig', ['title' => 'Contact', 'message' => self::TOO_MANY],
                new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
        }
        $message = $contact->submit($data->fields() + ['realm' => $site->bookingUrl], [
            'subscriber_uuid' => (string) $data->suid,
            'muid' => (string) $data->muid,
            'ip' => (string) $request->getClientIp(),
            'user_agent' => (string) $request->headers->get('User-Agent', ''),
            'forwarded_for' => (string) $request->headers->get('X-Forwarded-For', ''),
        ]);
        return $this->render('page/result.html.twig', ['title' => 'Contact', 'message' => $message]);
    }

    private function contactForm(ContactRequest $data): FormInterface
    {
        return $this->createForm(ContactType::class, $data, ['action' => $this->generateUrl('contact_submit')]);
    }

    private function page(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('contact/form.html.twig', ['form' => $form], new Response(status: $status));
    }
}
