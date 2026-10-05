<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Campaign\MessageNotFound;
use App\Campaign\MessageService;
use App\Config\SiteConfig;
use App\Http\Pagination;
use App\Repository\ListRepository;
use App\Repository\MessageRepository;
use App\Repository\TemplateRepository;
use App\Security\Csrf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
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
            $message = ['m_uniqid' => '', 'm_t_id' => 0, 'm_from_name' => '', 'm_from_address' => $site->fromAddress,
                'm_subject' => 'Your subject here', 'm_priority' => 0, 'm_max_send' => 0, 'm_html' => 'Enter your message here', 'm_text' => ''];
            $selected = [];
        } else {
            $message = $this->messages->findByMuid($muid) ?? throw $this->createNotFoundException('Message does not exist.');
            $selected = array_column($this->messages->lists($message['m_id']), 'l_id');
        }
        return $this->render('admin/message_form.html.twig', [
            'message' => $message,
            'selected' => $selected,
            'lists' => $this->lists->all(),
            'templates' => $this->templates->choices(),
        ]);
    }

    #[Route('/message', name: 'admin_message_save', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function save(Request $request, MessageService $service): Response
    {
        $form = $request->request;
        try {
            $service->save(trim($form->getString('m_uniqid')) ?: null, [
                'm_t_id' => $form->getInt('m_t_id'),
                'm_from_name' => $form->getString('m_from_name'),
                'm_from_address' => $form->getString('m_from_address'),
                'm_subject' => $form->getString('m_subject'),
                'm_priority' => $form->getInt('m_priority'),
                'm_max_send' => $form->getInt('m_max_send'),
                'm_html' => $form->getString('mt_html'),
                'm_text' => $form->getString('m_text'),
            ], array_map('intval', array_values($form->all('list_ids'))));
            $this->addFlash('info', 'Message saved.');
        } catch (MessageNotFound) {
            $this->addFlash('danger', 'Message does not exist.');
        }
        return $this->redirectToRoute('admin_messages');
    }
}
