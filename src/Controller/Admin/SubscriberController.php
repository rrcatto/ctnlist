<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\SiteConfig;
use App\Form\FormErrors;
use App\Form\Model\BulkSubscription;
use App\Form\Model\BulkUnsubscription;
use App\Form\Model\SubscriberProfile;
use App\Form\Type\BulkSubscribeType;
use App\Form\Type\BulkUnsubscribeType;
use App\Form\Type\ExportType;
use App\Form\Type\SubscriberType;
use App\Http\Pagination;
use App\Repository\CattoMailValidationRepository;
use App\Repository\ListRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriberRepository;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use App\Subscriber\SubscriberAdmin;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Subscriber administration: search, edit, bulk operations, import/export and sync. */
final class SubscriberController extends AbstractController
{
    /** A subscriber UUID in a URL (never confused with a page number of /subscribers/{page}). */
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly ListRepository $lists,
        private readonly SubscriberAdmin $admin,
        private readonly MembershipRepository $memberships,
        private readonly CattoMailValidationRepository $validations,
    ) {
    }

    #[Route('/subscribers/{page}', name: 'admin_subscribers', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    #[IsGranted('subscribers.view')]
    public function list(int $page, Request $request): Response
    {
        return $this->report($page, $request, false);
    }

    /** Subscribers who have interacted with a message. */
    #[Route('/activesubscribers/{page}', name: 'admin_subscribers_active', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    #[IsGranted('subscribers.view')]
    public function active(int $page, Request $request): Response
    {
        return $this->report($page, $request, true);
    }

    /** The subscriber form: administrators, or the subscriber themselves. Showing it changes nothing. */
    #[Route('/subscribers/{token}/{muid}', name: 'admin_subscriber', defaults: ['muid' => ''], requirements: ['token' => self::UUID, 'muid' => '[0-9a-f]*'], methods: ['GET'])]
    public function edit(string $token, string $muid, #[CurrentUser] ?SubscriberUser $user): Response
    {
        $identity = $this->identity($token, $user);
        return $this->subscriberPage($identity, $muid, $this->subscriberForm($identity, $muid));
    }

    #[Route('/subscribers/{token}/{muid}', name: 'admin_subscriber_save', defaults: ['muid' => ''], requirements: ['token' => self::UUID, 'muid' => '[0-9a-f]*'], methods: ['POST'])]
    public function save(string $token, string $muid, Request $request, #[CurrentUser] ?SubscriberUser $user): Response
    {
        $identity = $this->identity($token, $user);
        $form = $this->subscriberForm($identity, $muid);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var SubscriberProfile $profile */
            $profile = $form->getData();
            try {
                $this->addFlash('info', $this->admin->save($identity['s_uuid'], $profile->input(), $muid, $this->isGranted('subscribers.manage')));
                return $this->redirectToRoute('admin_subscriber', ['token' => $identity['s_uuid'], 'muid' => $muid]);
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->subscriberPage($identity, $muid, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Return a subscriber whose address hard-bounced or complained to campaign selection (does not re-subscribe them). */
    #[Route('/subscribers/{token}/delivery-ok', name: 'admin_subscriber_delivery_ok', requirements: ['token' => self::UUID], methods: ['POST'])]
    #[IsGranted('subscribers.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function clearDeliveryProblem(string $token, #[CurrentUser] SubscriberUser $user, LoggerInterface $logger): Response
    {
        $identity = $this->subscribers->findIdentityByUuid($token) ?? throw $this->createNotFoundException('The subscriber does not exist.');
        $cleared = $this->subscribers->clearDeliveryProblem($identity['s_id']);
        if ($cleared === 'ok') {
            $this->addFlash('info', 'There was no delivery block to clear.');
        } else {
            // No address or other personal data in the log: the subscriber and the administrator by UUID.
            $logger->notice('catto-mail delivery block ({state}) of subscriber {subscriber} cleared by {admin}.',
                ['state' => $cleared, 'subscriber' => $identity['s_uuid'], 'admin' => $user->uuid]);
            $this->addFlash('info', 'The ' . str_replace('_', ' ', $cleared) . ' block is cleared: this subscriber can be selected for campaigns again. '
                . 'Their list memberships and consent are unchanged' . ($cleared === 'complained' ? ' (the lists they were unsubscribed from stay unsubscribed; only they can rejoin)' : '') . '.');
        }
        return $this->redirectToRoute('admin_subscriber', ['token' => $identity['s_uuid']]);
    }

    #[Route('/bulk-subscribe', name: 'admin_bulk_subscribe', methods: ['GET', 'POST'])]
    #[IsGranted('subscribers.manage')]
    public function bulkSubscribe(Request $request): Response
    {
        $form = $this->bulkSubscribeForm(false);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var BulkSubscription $data */
            $data = $form->getData();
            try {
                set_time_limit(86400);
                return $this->result('Bulk subscribe', 'Number subscribed: ' . $this->admin->bulkSubscribe($data->emails, $data->priority, (int) $data->listId));
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->render('admin/bulk_subscribe.html.twig', ['form' => $form], self::status($form));
    }

    #[Route('/bulk-unsubscribe', name: 'admin_bulk_unsubscribe', methods: ['GET', 'POST'])]
    #[IsGranted('subscribers.manage')]
    public function bulkUnsubscribe(Request $request): Response
    {
        $form = $this->createForm(BulkUnsubscribeType::class, new BulkUnsubscription(), ['lists' => $this->lists->all()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var BulkUnsubscription $data */
            $data = $form->getData();
            try {
                return $this->result('Bulk unsubscribe', 'Number unsubscribed: ' . $this->admin->bulkUnsubscribe($data->emails, (int) $data->listId, $data->reason, $data->scope));
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->render('admin/bulk_unsubscribe.html.twig', ['form' => $form, 'scopes' => BulkUnsubscribeType::SCOPES], self::status($form));
    }

    #[Route('/import', name: 'admin_import', methods: ['GET', 'POST'])]
    #[IsGranted('subscribers.manage')]
    public function import(Request $request): Response
    {
        $form = $this->bulkSubscribeForm(true);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var BulkSubscription $data */
            $data = $form->getData();
            $contents = $data->file === null ? false : @file_get_contents($data->file->getPathname());
            if ($contents === false) {
                $form->get('file')->addError(new FormError('The uploaded file could not be read.'));
            } else {
                try {
                    set_time_limit(86400);
                    return $this->result('Import subscribers', 'Number subscribed: ' . $this->admin->bulkSubscribe($contents, $data->priority, (int) $data->listId));
                } catch (\InvalidArgumentException $e) {
                    $form->get('file')->addError(new FormError($e->getMessage()));
                }
            }
        }
        return $this->render('admin/import.html.twig', ['form' => $form], self::status($form));
    }

    /**
     * Writes export-subscribers.txt and export-remove.txt to the installation's
     * logs/. GET shows the form; only the POST writes files.
     */
    #[Route('/export', name: 'admin_export', methods: ['GET', 'POST'])]
    #[IsGranted('subscribers.manage')]
    public function export(Request $request): Response
    {
        $form = $this->createForm(ExportType::class, ['listId' => null, 'offset' => 0, 'limit' => 10000000], ['lists' => $this->lists->all()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{listId: ?int, offset: int, limit: int} $data */
            $data = $form->getData();
            return $this->result('Export subscribers', implode(' ', $this->admin->export($data['offset'], $data['limit'], (int) $data['listId'])));
        }
        return $this->render('admin/export.html.twig', ['form' => $form], self::status($form));
    }

    /** SYNC_DATABASES_JSON synchronisation: GET explains it; only the POST writes to the other databases. */
    #[Route('/sync', name: 'admin_sync', methods: ['GET'])]
    #[IsGranted('subscribers.manage')]
    public function syncForm(SiteConfig $site): Response
    {
        $targets = array_filter($site->syncDatabases, static fn(array $server): bool => (int) ($server['active'] ?? 0) === 1 && (string) ($server['domain'] ?? '') !== $site->domain);
        return $this->render('admin/sync.html.twig', ['targets' => count($targets)]);
    }

    #[Route('/sync', name: 'admin_sync_submit', methods: ['POST'])]
    #[IsGranted('subscribers.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function sync(): Response
    {
        set_time_limit(86400);
        return $this->result('Synchronise subscribers', 'Number synchronised: ' . $this->admin->synchronise());
    }

    private function report(int $page, Request $request, bool $activeOnly): Response
    {
        $email = $request->query->getString('e');
        $listId = $request->query->getInt('l');
        $includeUnsubscribed = $request->query->getBoolean('u');
        $pagination = Pagination::fromRequest($request, $page, $this->subscribers->reportCount($email, $activeOnly, $includeUnsubscribed, $listId));
        return $this->render('admin/subscribers.html.twig', [
            'active' => $activeOnly,
            'rows' => $this->subscribers->reportPage($email, $activeOnly, $includeUnsubscribed, $listId, $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
            'lists' => $this->lists->all(),
            'filters' => ['e' => $email, 'l' => $listId, 'u' => $includeUnsubscribed],
            'query' => '?' . http_build_query(['e' => $email, 'r' => $pagination->perPage, 'u' => $includeUnsubscribed ? 1 : 0, 'l' => $listId]),
        ]);
    }

    /**
     * @return array{s_id: int, s_uuid: string, s_email: string}
     */
    private function identity(string $token, ?SubscriberUser $user): array
    {
        if (!$this->isGranted('subscribers.manage') && ($user === null || strtolower($token) !== $user->uuid)) {
            throw $this->createAccessDeniedException();
        }
        return $this->subscribers->findIdentityByUuid($token) ?? throw $this->createNotFoundException('The subscriber does not exist.');
    }

    /** @param array{s_id: int, s_uuid: string, s_email: string} $identity */
    private function subscriberForm(array $identity, string $muid): FormInterface
    {
        $profile = (array) $this->subscribers->profile($identity['s_id']);
        $data = SubscriberProfile::fromProfile([
            's_fname' => (string) ($profile['s_fname'] ?? ''),
            's_lname' => (string) ($profile['s_lname'] ?? ''),
            's_gender' => (string) ($profile['s_gender'] ?? ''),
            's_province' => (string) ($profile['s_province'] ?? ''),
            's_country' => (string) ($profile['s_country'] ?? ''),
        ], (int) ($this->subscribers->findRecipientByUuid($identity['s_uuid'])['s_priority'] ?? 0));
        return $this->createForm(SubscriberType::class, $data, [
            'administrator' => $this->isGranted('subscribers.manage'),
            'lists' => $this->lists->all(),
            'action' => $this->generateUrl('admin_subscriber_save', ['token' => $identity['s_uuid'], 'muid' => $muid]),
        ]);
    }

    /** @param array{s_id: int, s_uuid: string, s_email: string} $identity */
    private function subscriberPage(array $identity, string $muid, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/subscriber_form.html.twig', [
            'email' => $identity['s_email'],
            'form' => $form,
            'memberships' => $this->memberships->forSubscriber($identity['s_id']),
            'delivery' => $this->subscribers->deliveryState($identity['s_id']),
            'validation' => $this->validations->latestForSubscriber($identity['s_uuid']),
        ], new Response(status: $status));
    }

    private function bulkSubscribeForm(bool $import): FormInterface
    {
        $data = new BulkSubscription();
        $data->priority = $import ? 0 : 10;
        $data->listId = $this->lists->all()[0]['l_id'] ?? null;
        return $this->createForm(BulkSubscribeType::class, $data, ['lists' => $this->lists->all(), 'import' => $import]);
    }

    private static function status(FormInterface $form): Response
    {
        return new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function result(string $title, string $message): Response
    {
        return $this->render('page/result.html.twig', ['title' => $title, 'message' => $message, 'links' => [
            ['label' => 'Back to subscribers', 'route' => 'admin_subscribers', 'permission' => 'subscribers.view'],
        ]]);
    }
}
