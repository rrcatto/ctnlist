<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\CattoMail\ConnectFlow;
use App\Form\FormErrors;
use App\Form\Type\MailServiceType;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Connect and Disconnect for the mail service, from the Delivery page
 * (ConnectFlow). Administrators only, whatever else `settings.manage`
 * allows: whoever chooses the mail service receives every campaign and every
 * recipient's address.
 *
 * The administrator's browser leaves for the mail service and comes back:
 * - connect (POST) answers with a page that forwards the browser, not with a
 *   redirect, because the page's Content-Security-Policy (form-action 'self')
 *   stops a form from ending at another site;
 * - callback (GET) changes nothing, as every GET here: it shows the Finish
 *   form, which complete (POST, CSRF) acts on. assets/app.js submits that
 *   form at once, so the administrator normally sees only the result.
 */
final class DeliveryConnectionController extends AbstractController
{
    public function __construct(private readonly ConnectFlow $flow)
    {
    }

    #[Route('/delivery/connect', name: 'admin_delivery_connect', methods: ['POST'])]
    #[IsGranted('settings.manage')]
    public function connect(Request $request, #[CurrentUser] SubscriberUser $admin): Response
    {
        $this->denyUnlessAdministrator($admin);
        // From Set up sending, the administrator comes back there; otherwise to the Delivery page.
        $return = $request->request->getString('return') === 'setup' ? 'setup' : 'delivery';
        $form = $this->createForm(MailServiceType::class, null, ['ask_address' => $this->flow->address() === '']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $address = $this->flow->start($form->has('service') ? (string) $form->get('service')->getData() : '',
                    $this->generateUrl('admin_delivery_connect_callback', [], UrlGeneratorInterface::ABSOLUTE_URL), $admin, $return);
                return $this->render('admin/delivery_connect_continue.html.twig', ['address' => $address, 'service' => (string) parse_url($address, PHP_URL_HOST)]);
            } catch (\InvalidArgumentException $e) {
                if ($return === 'setup' && !$form->has('service')) {
                    $this->addFlash('danger', $e->getMessage());
                    return $this->redirectToRoute('admin_sending_setup');
                }
                FormErrors::attach($form, $e);
            }
        }
        return $this->form($form);
    }

    /** Back from the mail service, with ?code=…&state=… or ?error=…&state=…: shows what happens next, changes nothing. */
    #[Route('/delivery/connect/callback', name: 'admin_delivery_connect_callback', methods: ['GET'])]
    #[IsGranted('settings.manage')]
    public function callback(Request $request, #[CurrentUser] SubscriberUser $admin): Response
    {
        $this->denyUnlessAdministrator($admin);
        return $this->answer($request->query->getString('state'), $request->query->getString('code'), $request->query->getString('error'),
            $request->query->getString('error_description'), $admin);
    }

    #[Route('/delivery/connect/complete', name: 'admin_delivery_connect_complete', methods: ['POST'])]
    #[IsGranted('settings.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function complete(Request $request, #[CurrentUser] SubscriberUser $admin): Response
    {
        $this->denyUnlessAdministrator($admin);
        $state = $request->request->getString('state');
        $code = $request->request->getString('code');
        try {
            $connected = $this->flow->complete($state, $code, $admin);
        } catch (\InvalidArgumentException $e) {
            if ($this->flow->expects($state, $admin)) {
                // The request still waits (the service was unreachable, or refused for now): Finish can be pressed again.
                return $this->answer($state, $code, '', '', $admin, $e->getMessage());
            }
            $this->addFlash('danger', $e->getMessage());
            return $this->redirectToRoute(($this->flow->pending()['return'] ?? 'delivery') === 'setup' ? 'admin_sending_setup' : 'admin_delivery');
        }
        $this->addFlash('info', sprintf('This site is connected to the mail service at %s%s. Its key and webhook secret are stored encrypted.',
            (string) parse_url($connected['service'], PHP_URL_HOST), $connected['client_name'] !== '' ? ', with the account "' . $connected['client_name'] . '"' : ''));
        return $this->redirectToRoute($connected['return'] === 'setup' ? 'admin_sending_setup' : 'admin_delivery');
    }

    #[Route('/delivery/disconnect', name: 'admin_delivery_disconnect', methods: ['POST'])]
    #[IsGranted('settings.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function disconnect(Request $request, #[CurrentUser] SubscriberUser $admin): Response
    {
        $this->denyUnlessAdministrator($admin);
        $forget = $request->request->getBoolean('forget');
        try {
            $this->flow->disconnect($admin, $forget);
            $this->addFlash('info', $forget
                ? 'The stored connection was forgotten. Its API key was not revoked: end the connection in the mail service\'s dashboard if it still exists there.'
                : 'The mail service is disconnected: it revoked the API key and removed the webhook endpoint, and the stored values are cleared.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('admin_delivery');
    }

    private function denyUnlessAdministrator(SubscriberUser $user): void
    {
        if (!$user->isAdministrator()) {
            throw $this->createAccessDeniedException('Only administrators can connect or disconnect the mail service.');
        }
    }

    /** The connect form by itself, with its errors (the Delivery page shows the same form). */
    private function form(FormInterface $form): Response
    {
        return $this->render('admin/delivery_connect.html.twig', ['form' => $form, 'obstacle' => $this->flow->obstacle(), 'address' => $this->flow->address()],
            new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function answer(string $state, string $code, string $error, string $description, SubscriberUser $admin, ?string $failure = null): Response
    {
        $expected = $this->flow->expects($state, $admin);
        return $this->render('admin/delivery_connect_callback.html.twig', [
            'expected' => $expected,
            'declined' => $error !== '',
            // The mail service's own words, shown as text and cut short: they arrive in the address.
            'description' => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $description)), 0, 300),
            'state' => $expected ? $state : '',
            'code' => $expected && $error === '' ? $code : '',
            'service' => (string) parse_url($this->flow->pending()['service'] ?? '', PHP_URL_HOST),
            'failure' => $failure,
        ], new Response(status: $failure === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
