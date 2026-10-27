<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Campaign\MessageNotFound;
use App\Campaign\MessageService;
use App\CattoMail\SendOutcome;
use App\Config\SiteConfig;
use App\Controller\RendersRegions;
use App\Form\FormErrors;
use App\Form\Model\QueueRotation;
use App\Form\Type\ProofType;
use App\Form\Type\QueueRotationType;
use App\Http\Pagination;
use App\Http\RequestNumber;
use App\Mail\ProofMailer;
use App\Queue\QueueBuilder;
use App\Queue\QueueOutcome;
use App\Queue\QueueProcessor;
use App\Queue\QueueRun;
use App\Repository\MessageRepository;
use App\Repository\QueueRepository;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Building and sending the delivery queue (v5 Add2Q, Queue 4 Msgs, Start/
 * Stop sending) and proof copies.
 */
final class QueueController extends AbstractController
{
    use RendersRegions;

    /** v5 form defaults. */
    private const QUEUE_LIMIT = 500000;
    private const ROTATION_VOLUME = 1000000;
    private const SEND_LIMIT = 250000;

    /** Next steps offered on the result pages (shown when the user holds the permission). */
    private const QUEUE_LINK = ['label' => 'View the queue', 'route' => 'admin_queue', 'permission' => 'queue.process'];
    private const MESSAGES_LINK = ['label' => 'Back to messages', 'route' => 'admin_messages', 'permission' => 'messages.manage'];

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
        $outcome = $this->builder->queueMessage($request->request->getString('muid'), RequestNumber::of($request->request, 'limit') ?: self::QUEUE_LIMIT);
        return $this->outcome('Queue message', $outcome);
    }

    #[Route('/advanced-queue', name: 'admin_queue_rotation', methods: ['GET'])]
    #[IsGranted('messages.queue')]
    public function rotationForm(MessageRepository $messages): Response
    {
        return $this->rotationPage($this->rotationFormFor($messages));
    }

    #[Route('/advanced-queue', name: 'admin_queue_rotation_submit', methods: ['POST'])]
    #[IsGranted('messages.queue')]
    public function queueRotation(Request $request, MessageRepository $messages): Response
    {
        $form = $this->rotationFormFor($messages);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->rotationPage($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var QueueRotation $rotation */
        $rotation = $form->getData();
        set_time_limit(86400);
        return $this->outcome('Queue multiple messages', $this->builder->queueRotation($rotation->muids(), (int) $rotation->volume));
    }

    private function rotationFormFor(MessageRepository $messages): FormInterface
    {
        return $this->createForm(QueueRotationType::class, new QueueRotation(self::ROTATION_VOLUME), [
            'action' => $this->generateUrl('admin_queue_rotation_submit'),
            'messages' => $messages->choices(),
        ]);
    }

    private function rotationPage(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/queue_rotation.html.twig', ['form' => $form], new Response(status: $status));
    }

    #[Route('/queue/{page}', name: 'admin_queue', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    #[IsGranted('queue.process')]
    public function list(int $page, Request $request): Response
    {
        $pagination = Pagination::fromRequest($request, $page, $this->queue->count());
        return $this->renderPage($request, 'admin/queue.html.twig', ['queue-entries' => 'admin/_queue_entries.html.twig'], [
            'rows' => $this->queue->page($pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
        ]);
    }

    #[Route('/queue/delete', name: 'admin_queue_delete', methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function delete(Request $request): Response
    {
        if ($this->queue->delete($request->request->getInt('queue_id'))) {
            $this->addFlash('info', 'The entry was removed from the queue.');
        }
        return $this->redirectAfterAction($request, 'admin_queue');
    }

    #[Route('/queue/clear', name: 'admin_queue_clear', methods: ['POST'])]
    #[IsGranted('queue.process')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function clear(Request $request): Response
    {
        $this->addFlash('info', 'Cleared ' . $this->queue->clear() . ' queue record(s).');
        return $this->redirectAfterAction($request, 'admin_queue');
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
    public function process(Request $request, QueueRun $run, MessageRepository $messages, #[CurrentUser] SubscriberUser $user): Response
    {
        $muid = $request->request->getString('muid');
        $subject = $muid === '' ? '' : (string) ($messages->findByMuid($muid)['m_subject'] ?? '');
        if ($run->request($muid, $subject, RequestNumber::of($request->request, 'limit') ?: self::SEND_LIMIT, $user->uuid)) {
            // Sent once this answer has gone (QueueRunListener); the Sending page follows it.
            $request->attributes->set(QueueRun::START, true);
        } else {
            $this->addFlash('info', 'Sending is already under way; its progress is below.');
        }
        return $this->redirectToRoute('admin_queue_sending');
    }

    /** The current or last queue run: what it has done so far, and Stop while it is sending. */
    #[Route('/sending', name: 'admin_queue_sending', methods: ['GET'])]
    #[IsGranted('queue.process')]
    public function sending(Request $request, QueueRun $run): Response
    {
        $current = $run->view();
        return $this->renderPage($request, 'admin/queue_sending.html.twig', ['queue-run' => 'admin/_queue_run.html.twig'], [
            'run' => $current,
            'queued' => $this->queue->count($current['muid'] ?? ''),
            'live' => in_array($current['state'] ?? null, [QueueRun::STARTING, QueueRun::RUNNING], true),
        ]);
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
    public function stop(Request $request, QueueProcessor $processor): Response
    {
        $processor->requestStop();
        $this->addFlash('info', 'The stop request has been recorded: sending stops before the next message.');
        return $this->redirectAfterAction($request, 'admin_queue_sending');
    }

    /** Proof copy to any address, by default MAIL_TEST_ADDRESS (no subscriber record needed). */
    #[Route('/sendtome/{muid}', name: 'admin_proof', methods: ['GET'])]
    #[IsGranted('messages.manage')]
    public function proofForm(string $muid, SiteConfig $site, ProofMailer $proofs): Response
    {
        return $this->proofPage($muid, $site, $this->proofFormFor($muid, $site), $proofs);
    }

    #[Route('/sendtome/{muid}', name: 'admin_proof_submit', methods: ['POST'])]
    #[IsGranted('messages.manage')]
    public function proof(string $muid, Request $request, SiteConfig $site, MessageService $messages, ProofMailer $proofs): Response
    {
        $form = $this->proofFormFor($muid, $site);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string} $data */
            $data = $form->getData();
            $email = trim($data['email']) ?: trim($site->testEmail);
            try {
                $outcome = $messages->sendProof($muid, $email);
                return $this->result('Proof send', match ($outcome->status) {
                    // Mailpit keeps the proof: it is read there and never reaches the address.
                    SendOutcome::SUBMITTED => $proofs->enabled()
                        ? 'Proof message for ' . $email . ' sent to Mailpit (' . $proofs->target() . '). Read it in Mailpit: it is not delivered to that address.'
                        : 'Proof message sent to ' . $email . ' (handed to catto-mail).',
                    SendOutcome::DEFERRED => 'catto-mail could not be reached; the proof to ' . $email . ' is kept and will be retried automatically.',
                    default => 'Proof message could not be sent: ' . $outcome->problem,
                }, [self::MESSAGES_LINK]);
            } catch (MessageNotFound $e) {
                throw $this->createNotFoundException($e->getMessage(), $e);
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->proofPage($muid, $site, $form, $proofs, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function proofFormFor(string $muid, SiteConfig $site): FormInterface
    {
        return $this->createForm(ProofType::class, ['email' => self::validEmail($site->testEmail) ? trim($site->testEmail) : ''], [
            'action' => $this->generateUrl('admin_proof_submit', ['muid' => $muid]),
        ]);
    }

    private function proofPage(string $muid, SiteConfig $site, FormInterface $form, ProofMailer $proofs, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/proof.html.twig', [
            // Where proofs go: Mailpit's SMTP address (MAIL_PROOF_SMTP), or '' for the mail service.
            'mailpit' => $proofs->enabled() ? $proofs->target() : '',
            'mailpit_problem' => $proofs->problem(),
            'muid' => $muid,
            'form' => $form,
            'test_email' => $site->testEmail,
            'test_email_valid' => self::validEmail($site->testEmail),
        ], new Response(status: $status));
    }

    private static function validEmail(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function outcome(string $title, QueueOutcome $outcome): Response
    {
        return $this->result($title, $outcome->problem ?? 'Finished queueing: ' . $outcome->queued, [self::QUEUE_LINK, self::MESSAGES_LINK]);
    }

    /** @param list<array{label: string, route: string, permission: string}> $links */
    private function result(string $title, string $message, array $links = []): Response
    {
        return $this->render('page/result.html.twig', ['title' => $title, 'message' => $message, 'links' => $links]);
    }
}
