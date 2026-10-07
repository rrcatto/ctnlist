<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\CattoMail\CattoMailActivity;
use App\CattoMail\CattoMailClient;
use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailException;
use App\CattoMail\CattoMailHealth;
use App\CattoMail\GlobalOptOut;
use App\CattoMail\SendJobAdmin;
use App\CattoMail\SendJobSync;
use App\CattoMail\WebhookProcessor;
use App\Config\ConfigFingerprint;
use App\Http\Pagination;
use App\Maintenance\MaintenanceActivity;
use App\Maintenance\StuckWork;
use App\Repository\CattoMailOptOutRepository;
use App\Repository\CattoMailSendRepository;
use App\Repository\CattoMailStatusRepository;
use App\Repository\CattoMailWebhookRepository;
use App\Repository\MessageRepository;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
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

    public function __construct(
        private readonly CattoMailSendRepository $outbox,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Recovery actions are recorded: who, what, which reference; never content or personal data. */
    private function audit(SubscriberUser $admin, string $action, string $reference): void
    {
        $this->logger->notice('catto-mail recovery: {action} {reference} requested by {admin}.', ['action' => $action, 'reference' => $reference, 'admin' => $admin->uuid]);
    }

    /**
     * The integration status page: configuration (never a secret), the last
     * connection check, API and worker activity, pending or stuck work, and
     * recent send runs.
     */
    #[Route('/delivery', name: 'admin_delivery', methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function index(CattoMailConfig $config, CattoMailHealth $health, CattoMailActivity $activity, CattoMailStatusRepository $status,
        CattoMailWebhookRepository $webhooks, Request $request, StuckWork $stuck, MaintenanceActivity $maintenance, CattoMailOptOutRepository $optOuts,
        ConfigFingerprint $fingerprint): Response
    {
        $muid = trim($request->query->getString('m'));
        if ($muid !== '') {
            return $this->redirectToRoute('admin_delivery_message', ['muid' => $muid]);
        }
        $activityNow = $activity->snapshot();
        $workerAge = $activityNow['worker_run'] !== null ? time() - (int) strtotime($activityNow['worker_run']) : null;
        return $this->render('admin/delivery.html.twig', [
            'problem' => $config->problem(),
            'api_root' => trim($config->baseUrl) === '' ? '' : self::withoutCredentials($config->apiRoot()),
            'connect_host' => $config->connectHost,
            'ca_file' => $config->caFile !== '',
            'secrets' => ['current' => $config->hasWebhookSecret(), 'previous' => $config->hasPreviousWebhookSecret()],
            'global_optout' => $config->globalOptOutEnabled,
            'tracking' => ['opens' => $config->trackOpens, 'clicks' => $config->trackClicks],
            'reconcile_after' => $config->reconcileAfterSeconds,
            'health' => $health->last(),
            'health_states' => CattoMailHealth::STATES,
            'activity' => $activityNow,
            // The worker should run every minute or so; well past the reconciliation interval it is not running.
            'worker_stale' => $config->isConfigured() && ($workerAge === null || $workerAge > max(600, 2 * $config->reconcileAfterSeconds)),
            // The worker ran with another .env, database or credentials than this web server.
            'worker_config_differs' => $activityNow['worker_config'] !== null && $activityNow['worker_config'] !== $fingerprint->value(),
            'counts' => $status->counts(),
            'stuck' => $stuck->counts(),
            'stuck_minutes' => intdiv($stuck->threshold(), 60),
            'maintenance' => $maintenance->snapshot(),
            'optouts' => $optOuts->needingAttention(),
            'runs' => $status->runs($stuck->staleBefore(), 0, 10),
            'webhooks' => $webhooks->recent(10),
        ]);
    }

    /** Check the connection now (one harmless authenticated read; nothing is created at catto-mail). */
    #[Route('/delivery/check', name: 'admin_delivery_check', methods: ['POST'])]
    #[IsGranted('logs.view')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function check(CattoMailHealth $health): Response
    {
        $result = $health->check();
        [, $label, $advice] = CattoMailHealth::STATES[$result['state']];
        $this->addFlash($result['state'] === CattoMailHealth::OK ? 'info' : 'danger', 'catto-mail connection: ' . $label . '. ' . $advice);
        return $this->redirectToRoute('admin_delivery');
    }

    /** One ctnlist send run and the catto-mail send jobs it became (more than one above 10,000 recipients or for several lists). */
    #[Route('/delivery/run/{uuid}', name: 'admin_delivery_run', requirements: ['uuid' => '[0-9a-f-]{36}'], methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function run(string $uuid, CattoMailStatusRepository $status, StuckWork $stuck): Response
    {
        $run = $status->run($uuid, $stuck->staleBefore()) ?? throw $this->createNotFoundException('Unknown send run.');
        $jobs = $this->outbox->jobsForRun($run['cr_id']);
        $categories = [];
        foreach ($jobs as $job) {
            $categories[$job['csj_id']] = $stuck->jobCategory($job);
        }
        return $this->render('admin/delivery_run.html.twig', ['run' => $run, 'jobs' => $jobs, 'categories' => $categories]);
    }

    #[Route('/delivery/runs/{page}', name: 'admin_delivery_runs', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function runs(int $page, Request $request, CattoMailStatusRepository $status, StuckWork $stuck): Response
    {
        $pagination = Pagination::fromRequest($request, $page, $status->runCount());
        return $this->render('admin/delivery_runs.html.twig', [
            'runs' => $status->runs($stuck->staleBefore(), $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
        ]);
    }

    /** Events, optionally only those about one catto-mail job (?j=<catto-mail id>, linked from job pages). */
    #[Route('/delivery/webhooks/{page}', name: 'admin_delivery_webhooks', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function webhooks(int $page, Request $request, CattoMailWebhookRepository $webhooks): Response
    {
        $filter = $request->query->getString('f');
        $filter = array_key_exists($filter, CattoMailWebhookRepository::FILTERS) ? $filter : '';
        $related = strtolower($request->query->getString('j'));
        $related = preg_match('/^[0-9a-f-]{36}$/', $related) === 1 ? $related : null;
        $pagination = Pagination::fromRequest($request, $page, $webhooks->count($filter, $related));
        return $this->render('admin/delivery_webhooks.html.twig', [
            'events' => $webhooks->page($filter, $pagination->offset(), $pagination->perPage, $related),
            'filter' => $filter,
            'related' => $related,
            'filters' => CattoMailWebhookRepository::FILTERS,
            'pagination' => $pagination,
        ]);
    }

    /** Process a stored event again (unprocessed or failed, body still kept); its effects are idempotent. */
    #[Route('/delivery/webhooks/{event}/reprocess', name: 'admin_delivery_reprocess', requirements: ['event' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function reprocess(string $event, CattoMailWebhookRepository $webhooks, WebhookProcessor $processor, #[CurrentUser] SubscriberUser $user): Response
    {
        $id = $webhooks->reopen($event);
        if ($id === null) {
            $this->addFlash('danger', 'That event cannot be processed again (already processed, or its body was pruned).');
        } else {
            $this->audit($user, 'reprocess webhook event', $event);
            $processor->processStored($id);
            $this->addFlash('info', 'The event was processed again; see its outcome below.');
        }
        return $this->redirectToRoute('admin_delivery_webhooks');
    }

    /** Hand an unsealed job to catto-mail now, with its stored ids and keys (what the worker would do next). */
    #[Route('/delivery/job/{id}/retry', name: 'admin_delivery_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function retry(int $id, SendJobAdmin $admin, #[CurrentUser] SubscriberUser $user): Response
    {
        $job = $this->outbox->job($id) ?? throw $this->createNotFoundException('Unknown send job.');
        $this->audit($user, 'retry send job', $job['csj_uuid']);
        try {
            $this->addFlash('info', 'Sent to catto-mail: ' . $admin->retrySending($id) . ' recipient(s) handed off.');
        } catch (\InvalidArgumentException | CattoMailException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('admin_delivery_job', ['id' => $id]);
    }

    /** Report a pending (or refused) global opt-out again; what the subscriber asked for is unchanged. */
    #[Route('/delivery/optout/{uuid}/retry', name: 'admin_delivery_optout_retry', requirements: ['uuid' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('subscribers.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function retryOptOut(string $uuid, GlobalOptOut $optOut, #[CurrentUser] SubscriberUser $user): Response
    {
        $this->audit($user, 'retry global opt-out', $uuid);
        try {
            $this->addFlash('info', 'Global opt-out: ' . str_replace('_', ' ', $optOut->retry($uuid)) . '.');
        } catch (\InvalidArgumentException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        return $this->redirectToRoute('admin_delivery');
    }

    /** Shown on the page: never user:password@ in a URL, however it was configured. */
    private static function withoutCredentials(string $url): string
    {
        return (string) preg_replace('#^(https?://)[^/@]*@#i', '$1', $url);
    }

    #[Route('/delivery/message/{muid}/{page}', name: 'admin_delivery_message', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function message(string $muid, int $page, Request $request, MessageRepository $messages): Response
    {
        $message = $messages->findByMuid($muid) ?? throw $this->createNotFoundException('Unknown message.');
        $pagination = Pagination::fromRequest($request, $page, $this->outbox->jobCountForMessage($muid));
        $jobs = $this->outbox->jobsForMessage($muid, $pagination->offset(), $pagination->perPage);
        $runs = [];
        foreach (array_unique(array_column($jobs, 'csj_cr_id')) as $runId) {
            $runs[$runId] = ['uuid' => $this->outbox->runUuid($runId), 'kind' => $this->outbox->runKind($runId)];
        }
        return $this->render('admin/delivery_message.html.twig', [
            'message' => $message,
            'jobs' => $jobs,
            'runs' => $runs,
            'pagination' => $pagination,
            'counts' => $this->outbox->statusCounts($muid),
        ]);
    }

    #[Route('/delivery/job/{id}/{page}', name: 'admin_delivery_job', defaults: ['page' => 1], requirements: ['id' => '\d+', 'page' => '\d+'], methods: ['GET'])]
    #[IsGranted('logs.view')]
    public function job(int $id, int $page, Request $request, StuckWork $stuck): Response
    {
        $job = $this->outbox->job($id) ?? throw $this->createNotFoundException('Unknown send job.');
        $status = $request->query->getString('s');
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $pagination = Pagination::fromRequest($request, $page, $this->outbox->recipientCountForJob($id, $status));
        return $this->render('admin/delivery_job.html.twig', [
            'job' => $job,
            'category' => $stuck->jobCategory($job),
            'run' => ['uuid' => $this->outbox->runUuid($job['csj_cr_id']), 'kind' => $this->outbox->runKind($job['csj_cr_id'])],
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
    public function refresh(int $id, SendJobSync $sync, #[CurrentUser] SubscriberUser $user): Response
    {
        $job = $this->outbox->job($id) ?? throw $this->createNotFoundException('Unknown send job.');
        $this->audit($user, 'reconcile send job', $job['csj_uuid']);
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
    public function requeue(int $id, SendJobAdmin $admin, #[CurrentUser] SubscriberUser $user): Response
    {
        $this->audit($user, 'return refused send job to the queue', (string) $id);
        try {
            $this->addFlash('info', $admin->requeueFailed($id) . ' subscriber(s) returned to the queue.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('admin_delivery_job', ['id' => $id]);
    }
}
