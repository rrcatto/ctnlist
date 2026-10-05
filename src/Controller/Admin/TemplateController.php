<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Pagination;
use App\Repository\TemplateRepository;
use App\Security\Csrf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Message templates; {content} marks where the message goes. Saved as entered (drafts allowed). */
#[IsGranted('templates.manage')]
final class TemplateController extends AbstractController
{
    public function __construct(private readonly TemplateRepository $templates)
    {
    }

    #[Route('/templates/{page}', name: 'admin_templates', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    public function list(int $page, Request $request): Response
    {
        $pagination = Pagination::fromRequest($request, $page, $this->templates->count());
        return $this->render('admin/templates.html.twig', [
            'templates' => $this->templates->page($pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
        ]);
    }

    #[Route('/template/{id}', name: 'admin_template', defaults: ['id' => 0], requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $template = $id === 0
            ? ['t_id' => 0, 't_name' => '', 't_html' => '', 't_text' => '']
            : ($this->templates->find($id) ?? throw $this->createNotFoundException('Template does not exist.'));
        return $this->render('admin/template_form.html.twig', ['template' => $template]);
    }

    #[Route('/template', name: 'admin_template_save', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function save(Request $request): Response
    {
        $form = $request->request;
        $id = $form->getInt('t_id');
        if ($id === 0) {
            $this->templates->create($form->getString('t_name'), $form->getString('mt_html'), $form->getString('t_text'));
            $this->addFlash('info', 'Template created.');
        } elseif ($this->templates->update($id, $form->getString('t_name'), $form->getString('mt_html'), $form->getString('t_text'))) {
            $this->addFlash('info', 'Template saved.');
        } else {
            throw $this->createNotFoundException('Template does not exist.');
        }
        return $this->redirectToRoute('admin_templates');
    }
}
