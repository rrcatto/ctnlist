<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\CattoMail\CattoMailClient;
use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailException;
use App\CattoMail\SendJobAdmin;
use App\CattoMail\SendJobSync;
use App\Http\Pagination;
use App\Repository\CattoMailSendRepository;
use App\Repository\CattoMailWebhookRepository;
use App\Repository\MessageRepository;
use App\Security\Csrf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What catto-mail did with campaign content: its send jobs per message and
 * each recipient's message state, plus a message's event history read live
 * from catto-mail (GET /v1/messages/{id}/events). `remote_accepted` means
 * the receiving server accepted the message, not that it reached an inbox;
 * `open_recorded` is a recorded open, not proof that a person read it.
 */
final class DeliveryController extends AbstractController
{
    public const STATUSES = ['staged', 'handed_off', 'not_sent', 'created', 'queued', 'submitted', 'deferred', 'outcome_unknown',
        'remote_accepted', 'soft_bounced', 'hard_bounced', 'complained', 'failed', 'suppressed'];

    public function __construct(private readonly CattoMailSendRepository $outbox)
    {
    }

    #[Route('/delivery', name: 'admin_delivery', methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function index(CattoMailConfig $config, CattoMailWebhookRepository $webhooks, MessageRepository $messages, Request $request): Response
    {
        $muid = trim($request->query->getString('m'));
        if ($muid !== '') {
            return $this->redirectToRoute('admin_delivery_message', ['muid' => $muid]);
        }
        return $this->render('admin/delivery.html.twig', [
            'problem' => $config->problem(),
            'webhook_secrets' => count($config->webhookSecrets()),
            'global_optout' => $config->globalOptOutEnabled,
            'webhooks' => $webhooks->recent(20),
        ]);
    }

    #[Route('/delivery/message/{muid}', name: 'admin_delivery_message', methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function message(string $muid, MessageRepository $messages): Response
    {
        $message = $messages->findByMuid($muid) ?? throw $this->createNotFoundException('Unknown message.');
        return $this->render('admin/delivery_message.html.twig', [
            'message' => $message,
            'jobs' => $this->outbox->jobsForMessage($muid),
            'counts' => $this->outbox->statusCounts($muid),
        ]);
    }

    #[Route('/delivery/job/{id}/{page}', name: 'admin_delivery_job', defaults: ['page' => 1], requirements: ['id' => '\d+', 'page' => '\d+'], methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function job(int $id, int $page, Request $request): Response
    {
        $job = $this->outbox->job($id) ?? throw $this->createNotFoundException('Unknown send job.');
        $status = $request->query->getString('s');
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $pagination = Pagination::fromRequest($request, $page, $this->outbox->recipientCountForJob($id, $status));
        return $this->render('admin/delivery_job.html.twig', [
            'job' => $job,
            'summary' => json_decode((string) $job['csj_summary'], true) ?: [],
            'status' => $status,
            'statuses' => self::STATUSES,
            'recipients' => $this->outbox->recipientsForJob($id, $pagination->offset(), $pagination->perPage, $status),
            'pagination' => $pagination,
        ]);
    }

    #[Route('/delivery/recipient/{uuid}', name: 'admin_delivery_recipient', requirements: ['uuid' => '[0-9a-f-]{36}'], methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function recipient(string $uuid, CattoMailClient $client): Response
    {
        $recipient = $this->outbox->recipientByUuid($uuid) ?? throw $this->createNotFoundException('Unknown recipient.');
        $events = [];
        $problem = null;
        if ($recipient['crp_remote_message_id'] !== null) {
            try {
                $cursor = null;
                do {
                    $page = $client->listMessageEvents($recipient['crp_remote_message_id'], $cursor);
                    $events = [...$events, ...$page['data']];
                    $cursor = $page['next_cursor'];
                } while ($cursor !== null && count($events) < 500);
            } catch (CattoMailException $e) {
                $problem = $e->getMessage();
            }
        }
        return $this->render('admin/delivery_recipient.html.twig', ['recipient' => $recipient, 'job' => $this->outbox->job($recipient['crp_csj_id']),
            'events' => $events, 'problem' => $problem]);
    }

    #[Route('/delivery/job/{id}/refresh', name: 'admin_delivery_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('logs.view')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function refresh(int $id, SendJobSync $sync): Response
    {
        $job = $this->outbox->job($id) ?? throw $this->createNotFoundException('Unknown send job.');
        try {
            $this->addFlash('info', 'catto-mail: ' . $sync->reconcile($job) . '.');
        } catch (CattoMailException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('admin_delivery_job', ['id' => $id]);
    }

    #[Route('/delivery/job/{id}/requeue', name: 'admin_delivery_requeue', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function requeue(int $id, SendJobAdmin $admin): Response
    {
        try {
            $this->addFlash('info', $admin->requeueFailed($id) . ' subscriber(s) returned to the queue.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('admin_delivery_job', ['id' => $id]);
    }
}
