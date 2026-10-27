<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\CattoMail\CattoMailException;
use App\CattoMail\ConnectFlow;
use App\CattoMail\ConnectionStore;
use App\CattoMail\SendingDomainCheck;
use App\CattoMail\SendingSetup;
use App\Controller\RendersRegions;
use App\Form\FormErrors;
use App\Form\Type\MailServiceType;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings › Set up sending (owner decision 2026-10-27): the four steps to this site's first
 * message through the mail service, one open at a time (SendingSetup). Anyone with
 * `settings.manage` can follow them; the actions are an administrator's, as Connect is: the mail
 * service receives every campaign and every recipient's address.
 *
 * The steps region redraws itself every 30 seconds while the sending domain is being set up at
 * the mail service (the worker asks the mail service every minute meanwhile); a GET only reads
 * what is stored.
 */
#[IsGranted('settings.manage')]
final class SendingSetupController extends AbstractController
{
    use RendersRegions;

    public const REGION = 'sending-setup';

    public function __construct(
        private readonly SendingSetup $setup,
        private readonly ConnectFlow $connect,
    ) {
    }

    #[Route('/delivery/setup', name: 'admin_sending_setup', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] SubscriberUser $user, ConnectionStore $store): Response
    {
        $state = $this->setup->state();
        $administrator = $user->isAdministrator();
        $back = $this->generateUrl('admin_sending_setup', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $setupUrl = $state['from_domain']['setup_url'];
        return $this->renderPage($request, 'admin/sending_setup.html.twig', [self::REGION => 'admin/_sending_setup.html.twig'], [
            'state' => $state,
            'is_administrator' => $administrator,
            'connection' => $store->details(),
            'stored_address' => $store->address(),
            'connect_obstacle' => $this->connect->obstacle(),
            'sending_domain' => $this->connect->sendingDomain(),
            'address_form' => $administrator && $state['address'] === '' ? $this->createForm(MailServiceType::class, null, ['action' => $this->generateUrl('admin_sending_setup_address')])->createView() : null,
            'connect_form' => $administrator && $state['address'] !== '' ? $this->createForm(MailServiceType::class, null, ['ask_address' => false])->createView() : null,
            // The domain's page at the mail service, with the way back here.
            'domain_steps' => DeliveryController::withBack($setupUrl, $back),
            'admin_email' => $user->email,
        ]);
    }

    /** Step 1, when no address is known yet: keep the one the administrator gives. */
    #[Route('/delivery/setup/address', name: 'admin_sending_setup_address', methods: ['POST'])]
    public function address(Request $request, #[CurrentUser] SubscriberUser $user, ConnectionStore $store): Response
    {
        $this->denyUnlessAdministrator($user);
        $form = $this->createForm(MailServiceType::class, null, ['action' => $this->generateUrl('admin_sending_setup_address')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $origin = ConnectFlow::serviceOrigin((string) $form->get('service')->getData());
                $store->rememberAddress($origin, $user->id);
                $this->addFlash('info', 'The mail service\'s address is kept: ' . $origin . '. Next, connect this site to it.');
                return $this->redirectAfterAction($request, 'admin_sending_setup');
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        $why = [];
        foreach ($form->getErrors(true) as $error) {
            if ($error instanceof FormError) {
                $why[] = $error->getMessage();
            }
        }
        $this->addFlash('danger', 'The address was not kept: ' . ($why === [] ? 'give the mail service\'s address.' : implode(' ', $why)));
        return $this->redirectAfterAction($request, 'admin_sending_setup');
    }

    /** Step 3: ask the mail service now whether the From domain can send (the worker also asks every minute). */
    #[Route('/delivery/setup/check', name: 'admin_sending_setup_check', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function check(Request $request, SendingDomainCheck $domains): Response
    {
        try {
            $domains->refresh();
            $state = $domains->fromAddress();
            $this->addFlash('info', $state['state'] === SendingDomainCheck::OK
                ? 'The mail service says mail from ' . $state['domain'] . ' can be sent.'
                : 'The mail service says ' . ($state['domain'] !== '' ? $state['domain'] : 'the From domain') . ' is not ready yet.');
        } catch (CattoMailException $e) {
            $this->addFlash('danger', 'The mail service could not be asked: ' . $e->getMessage());
        }
        return $this->redirectAfterAction($request, 'admin_sending_setup');
    }

    /** Step 4: a short test message to the administrator, through the mail service. */
    #[Route('/delivery/setup/test', name: 'admin_sending_setup_test', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function test(Request $request, #[CurrentUser] SubscriberUser $user): Response
    {
        $this->denyUnlessAdministrator($user);
        $outcome = $this->setup->sendTest($user->email);
        $this->addFlash($outcome->problem === null ? 'info' : 'danger', match (true) {
            $outcome->problem === null => 'The mail service accepted the test message to ' . $user->email . '. Look for it in that mailbox; it can take a minute.',
            $outcome->status === \App\CattoMail\SendOutcome::DEFERRED => 'The mail service could not be reached; the test message is kept and sent by the worker. ' . $outcome->problem,
            default => 'The test message was not sent: ' . $outcome->problem,
        });
        return $this->redirectAfterAction($request, 'admin_sending_setup');
    }

    private function denyUnlessAdministrator(SubscriberUser $user): void
    {
        if (!$user->isAdministrator()) {
            throw $this->createAccessDeniedException('Only administrators set up sending.');
        }
    }
}
