<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Campaign\MessageService;
use App\Config\SiteConfig;
use App\Http\Pagination;
use App\Queue\QueueBuilder;
use App\Queue\QueueOutcome;
use App\Queue\QueueProcessor;
use App\Repository\MessageRepository;
use App\Repository\QueueRepository;
use App\Security\Csrf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Building and sending the delivery queue (v5 Add2Q, Queue 4 Msgs, Start/
 * Stop sending) and proof copies.
 */
final class QueueController extends AbstractController
{
    /** v5 form defaults. */
    private const QUEUE_LIMIT = 500000;
    private const ROTATION_VOLUME = 1000000;
    private const SEND_LIMIT = 250000;
    private const ROTATION_SLOTS = 4;

    public function __construct(
        private readonly QueueRepository $queue,
        private readonly QueueBuilder $builder,
    ) {
    }

    #[Route('/queuelist/{muid}', name: 'admin_queue_message', methods: ['GET'])]
    #[IsGranted('messages.queue')]
    public function queueMessageForm(string $muid): Response
    {
        return $this->render('admin/queue_message.html.twig', ['muid' => $muid, 'limit' => self::QUEUE_LIMIT]);
    }

    #[Route('/queuelist', name: 'admin_queue_message_submit', methods: ['POST'])]
    #[IsGranted('messages.queue')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function queueMessage(Request $request): Response
    {
        set_time_limit(86400);
        $outcome = $this->builder->queueMessage($request->request->getString('muid'), $request->request->getInt('limit') ?: self::QUEUE_LIMIT);
        return $this->outcome('Queue message', $outcome);
    }

    #[Route('/advanced-queue', name: 'admin_queue_rotation', methods: ['GET'])]
    #[IsGranted('messages.queue')]
    public function rotationForm(MessageRepository $messages): Response
    {
        return $this->render('admin/queue_rotation.html.twig', [
            'messages' => $messages->choices(),
            'slots' => self::ROTATION_SLOTS,
            'volume' => self::ROTATION_VOLUME,
        ]);
    }

    #[Route('/advanced-queue', name: 'admin_queue_rotation_submit', methods: ['POST'])]
    #[IsGranted('messages.queue')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function queueRotation(Request $request): Response
    {
        set_time_limit(86400);
        $muids = [];
        for ($slot = 1; $slot <= self::ROTATION_SLOTS; $slot++) {
            $muids[] = $request->request->getString('muid' . $slot);
        }
        return $this->outcome('Queue multiple messages', $this->builder->queueRotation($muids, $request->request->getInt('mvolume')));
    }

    #[Route('/queue/{page}', name: 'admin_queue', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    #[IsGranted('queue.process')]
    public function list(int $page, Request $request): Response
    {
        $pagination = Pagination::fromRequest($request, $page, $this->queue->count());
        return $this->render('admin/queue.html.twig', [
            'rows' => $this->queue->page($pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
        ]);
    }

    #[Route('/queue/delete', name: 'admin_queue_delete', methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function delete(Request $request): Response
    {
        $this->queue->delete($request->request->getInt('queue_id'));
        return $this->redirectToRoute('admin_queue');
    }

    #[Route('/queue/clear', name: 'admin_queue_clear', methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function clear(): Response
    {
        $this->addFlash('info', 'Cleared ' . $this->queue->clear() . ' queue record(s).');
        return $this->redirectToRoute('admin_queue');
    }

    #[Route('/processqueue/{muid}/{limit}', name: 'admin_queue_process', defaults: ['muid' => '', 'limit' => self::SEND_LIMIT], requirements: ['limit' => '\d+'], methods: ['GET'])]
    #[IsGranted('queue.process')]
    public function processForm(string $muid, int $limit): Response
    {
        return $this->render('admin/queue_process.html.twig', ['muid' => $muid, 'limit' => $limit]);
    }

    #[Route('/processqueue', name: 'admin_queue_process_submit', methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function process(Request $request, QueueProcessor $processor): Response
    {
        set_time_limit(86400);
        $sent = $processor->process($request->request->getString('muid'), $request->request->getInt('limit') ?: self::SEND_LIMIT);
        return $this->result('Process queue', 'Sent ' . $sent . ' message(s).');
    }

    #[Route('/stop-send', name: 'admin_queue_stop', methods: ['GET'])]
    #[IsGranted('queue.process')]
    public function stopForm(): Response
    {
        return $this->render('admin/queue_stop.html.twig');
    }

    #[Route('/stop-send', name: 'admin_queue_stop_submit', methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function stop(QueueProcessor $processor): Response
    {
        $processor->requestStop();
        return $this->result('Stop sending', 'The stop request has been recorded.');
    }

    /** Proof copy to MAIL_TEST_ADDRESS (which must be a subscriber). */
    #[Route('/sendtome/{muid}', name: 'admin_proof', methods: ['GET'])]
    #[IsGranted('messages.manage')]
    public function proofForm(string $muid, SiteConfig $site): Response
    {
        return $this->render('admin/proof.html.twig', ['muid' => $muid, 'test_email' => $site->testEmail]);
    }

    #[Route('/sendtome/{muid}', name: 'admin_proof_submit', methods: ['POST'])]
    #[IsGranted('messages.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function proof(string $muid, SiteConfig $site, MessageService $messages): Response
    {
        $sent = $messages->sendTo($muid, $site->testEmail, 'PROOF');
        return $this->result('Proof send', $sent ? 'Proof message sent.' : 'Proof message could not be sent.');
    }

    private function outcome(string $title, QueueOutcome $outcome): Response
    {
        return $this->result($title, $outcome->problem ?? 'Finished queueing: ' . $outcome->queued);
    }

    private function result(string $title, string $message): Response
    {
        return $this->render('page/result.html.twig', ['title' => $title, 'message' => $message]);
    }
}
