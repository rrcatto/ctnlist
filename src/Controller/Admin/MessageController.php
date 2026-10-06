<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Campaign\MessageNotFound;
use App\Campaign\MessageService;
use App\Config\SiteConfig;
use App\Form\Model\MessageDraft;
use App\Form\Type\MessageType;
use App\Http\Pagination;
use App\Repository\ListRepository;
use App\Repository\MessageRepository;
use App\Repository\TemplateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Campaign messages: the operational list (statistics, queue, send and
 * proof controls) and the editor. Messages are saved exactly as entered,
 * including incomplete drafts with no lists; ALL is never added.
 */
#[IsGranted('messages.manage')]
final class MessageController extends AbstractController
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly TemplateRepository $templates,
        private readonly ListRepository $lists,
    ) {
    }

    #[Route('/messages/{page}', name: 'admin_messages', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    public function list(int $page, Request $request): Response
    {
        $pagination = Pagination::fromRequest($request, $page, $this->messages->count());
        return $this->render('admin/messages.html.twig', [
            'messages' => $this->messages->adminPage($pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
        ]);
    }

    #[Route('/message/{muid}', name: 'admin_message', defaults: ['muid' => ''], methods: ['GET'])]
    public function edit(string $muid, SiteConfig $site): Response
    {
        if ($muid === '') {
            $draft = new MessageDraft();
            $draft->subject = 'Your subject here';
            $draft->html = 'Enter your message here';
            $draft->fromAddress = $site->fromAddress;
        } else {
            $message = $this->messages->findByMuid($muid) ?? throw $this->createNotFoundException('Message does not exist.');
            $active = array_column($this->lists->all(), 'l_id');
            $selected = array_column($this->messages->lists($message['m_id']), 'l_id');
            $draft = MessageDraft::fromMessage($message, array_values(array_intersect($selected, $active)));
        }
        return $this->editor($this->messageForm($draft));
    }

    /** Save exactly what was entered (drafts may be incomplete); invalid input shows the editor again with everything kept. */
    #[Route('/message', name: 'admin_message_save', methods: ['POST'])]
    public function save(Request $request, MessageService $service): Response
    {
        $form = $this->messageForm(new MessageDraft());
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->editor($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var MessageDraft $draft */
        $draft = $form->getData();
        try {
            $service->save($draft->muid, $draft->input(), $draft->listIds);
            $this->addFlash('info', 'Message saved.');
        } catch (MessageNotFound) {
            $this->addFlash('danger', 'Message does not exist.');
        }
        return $this->redirectToRoute('admin_messages');
    }

    private function messageForm(MessageDraft $draft): FormInterface
    {
        return $this->createForm(MessageType::class, $draft, [
            'lists' => $this->lists->all(),
            'templates' => $this->templates->choices(),
            'action' => $this->generateUrl('admin_message_save'),
        ]);
    }

    private function editor(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $listsById = [];
        foreach ($this->lists->all() as $list) {
            $listsById[$list['l_id']] = $list;
        }
        /** @var MessageDraft $draft */
        $draft = $form->getData();
        return $this->render('admin/message_form.html.twig', ['form' => $form, 'muid' => (string) $draft->muid, 'lists' => $listsById], new Response(status: $status));
    }
}
