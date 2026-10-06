<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Pagination;
use App\Repository\ListRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriberRepository;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use App\Subscriber\ProfileOptions;
use App\Subscriber\SubscriberAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Subscriber administration: search, edit, bulk operations, import/export and sync. */
final class SubscriberController extends AbstractController
{
    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly ListRepository $lists,
        private readonly SubscriberAdmin $admin,
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

    /** The subscriber form: administrators, or the subscriber themselves. */
    #[Route('/subscribe/{token}/{muid}', name: 'admin_subscriber', defaults: ['muid' => ''], methods: ['GET'])]
    public function edit(string $token, string $muid, #[CurrentUser] ?SubscriberUser $user, MembershipRepository $memberships): Response
    {
        $administrator = $this->isGranted('subscribers.manage');
        if (!$administrator && ($user === null || strtolower($token) !== $user->uuid)) {
            throw $this->createAccessDeniedException();
        }
        $identity = $this->subscribers->findIdentityByUuid($token) ?? throw $this->createNotFoundException('The subscriber does not exist.');
        $profile = (array) $this->subscribers->profile($identity['s_id']);
        return $this->render('admin/subscriber_form.html.twig', [
            'token' => $identity['s_uuid'],
            'muid' => $muid,
            'profile' => $profile,
            'priority' => $this->subscribers->findRecipientByUuid($identity['s_uuid'])['s_priority'] ?? 0,
            'memberships' => $memberships->forSubscriber($identity['s_id']),
            'administrator' => $administrator,
            'lists' => $this->lists->all(),
            'genders' => ProfileOptions::GENDERS,
            'provinces' => ProfileOptions::PROVINCES,
            'countries' => ProfileOptions::COUNTRIES,
        ]);
    }

    #[Route('/subscribe', name: 'admin_subscriber_save', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function save(Request $request, #[CurrentUser] ?SubscriberUser $user): Response
    {
        $token = strtolower(trim($request->request->getString('subscriber_token')));
        $administrator = $this->isGranted('subscribers.manage');
        if (!$administrator && ($token === '' || $user === null || $token !== $user->uuid)) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->addFlash('info', $this->admin->save($token, $request->request->all(), trim($request->request->getString('muid')), $administrator));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
            if ($token === '') {
                return $this->redirectToRoute('admin_subscribers');
            }
        }
        $token = $token !== '' ? $token : (string) ($this->subscribers->findIdentityByEmail($request->request->getString('s_email'))['s_uuid'] ?? '');
        return $token === '' ? $this->redirectToRoute('admin_subscribers') : $this->redirectToRoute('admin_subscriber', ['token' => $token]);
    }

    #[Route('/bulk-subscribe', name: 'admin_bulk_subscribe', methods: ['GET'])]
    #[IsGranted('subscribers.manage')]
    public function bulkSubscribeForm(): Response
    {
        return $this->render('admin/bulk_subscribe.html.twig', ['lists' => $this->lists->all()]);
    }

    #[Route('/bulk-subscribe', name: 'admin_bulk_subscribe_submit', methods: ['POST'])]
    #[IsGranted('subscribers.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function bulkSubscribe(Request $request): Response
    {
        set_time_limit(86400);
        $count = $this->admin->bulkSubscribe($request->request->getString('bemail'), $request->request->getInt('s_priority'), $request->request->getInt('list_id'));
        return $this->result('Bulk subscribe', 'Number subscribed: ' . $count);
    }

    #[Route('/bulk-unsubscribe', name: 'admin_bulk_unsubscribe', methods: ['GET'])]
    #[IsGranted('subscribers.manage')]
    public function bulkUnsubscribeForm(): Response
    {
        return $this->render('admin/bulk_unsubscribe.html.twig', ['lists' => $this->lists->all()]);
    }

    #[Route('/bulk-unsubscribe', name: 'admin_bulk_unsubscribe_submit', methods: ['POST'])]
    #[IsGranted('subscribers.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function bulkUnsubscribe(Request $request): Response
    {
        try {
            $count = $this->admin->bulkUnsubscribe(
                $request->request->getString('bemail'),
                $request->request->getInt('list_id'),
                $request->request->getString('reason'),
                $request->request->getString('scope') ?: 'list'
            );
            return $this->result('Bulk unsubscribe', 'Number unsubscribed: ' . $count);
        } catch (\InvalidArgumentException $e) {
            return $this->result('Bulk unsubscribe', $e->getMessage());
        }
    }

    #[Route('/import', name: 'admin_import', methods: ['GET'])]
    #[IsGranted('subscribers.manage')]
    public function importForm(): Response
    {
        return $this->render('admin/import.html.twig', ['lists' => $this->lists->all()]);
    }

    #[Route('/import', name: 'admin_import_submit', methods: ['POST'])]
    #[IsGranted('subscribers.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function import(Request $request): Response
    {
        $file = $request->files->get('subscriber_file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->result('Import subscribers', 'No readable import file was uploaded.');
        }
        set_time_limit(86400);
        $count = $this->admin->bulkSubscribe((string) file_get_contents($file->getPathname()), $request->request->getInt('s_priority'), $request->request->getInt('list_id'));
        return $this->result('Import subscribers', 'Number subscribed: ' . $count);
    }

    /** Writes export-subscribers.txt and export-remove.txt to the installation's logs/. */
    #[Route('/export/{offset}/{limit}', name: 'admin_export', defaults: ['offset' => 0, 'limit' => 10000000], requirements: ['offset' => '\d+', 'limit' => '\d+'], methods: ['GET'])]
    #[IsGranted('subscribers.manage')]
    public function export(int $offset, int $limit, Request $request): Response
    {
        return $this->result('Export subscribers', implode(' ', $this->admin->export($offset, $limit, $request->query->getInt('l'))));
    }

    #[Route('/sync', name: 'admin_sync', methods: ['GET'])]
    #[IsGranted('subscribers.manage')]
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

    private function result(string $title, string $message): Response
    {
        return $this->render('page/result.html.twig', ['title' => $title, 'message' => $message, 'links' => [
            ['label' => 'Back to subscribers', 'route' => 'admin_subscribers', 'permission' => 'subscribers.view'],
        ]]);
    }
}
