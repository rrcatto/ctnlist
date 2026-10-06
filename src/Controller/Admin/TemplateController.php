<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\Model\TemplateDraft;
use App\Form\Type\TemplateType;
use App\Http\Pagination;
use App\Repository\TemplateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        $draft = $id === 0 ? new TemplateDraft() : TemplateDraft::fromTemplate($this->templates->find($id) ?? throw $this->createNotFoundException('Template does not exist.'));
        return $this->editor($id, $this->templateForm($id, $draft));
    }

    /** Save exactly what was entered; invalid input shows the editor again with everything kept. */
    #[Route('/template/{id}', name: 'admin_template_save', defaults: ['id' => 0], requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(int $id, Request $request): Response
    {
        if ($id !== 0 && $this->templates->find($id) === null) {
            throw $this->createNotFoundException('Template does not exist.');
        }
        $form = $this->templateForm($id, new TemplateDraft());
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->editor($id, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var TemplateDraft $draft */
        $draft = $form->getData();
        if ($id === 0) {
            $this->templates->create($draft->name, $draft->html, $draft->text);
            $this->addFlash('info', 'Template created.');
        } elseif ($this->templates->update($id, $draft->name, $draft->html, $draft->text)) {
            $this->addFlash('info', 'Template saved.');
        } else {
            throw $this->createNotFoundException('Template does not exist.');
        }
        return $this->redirectToRoute('admin_templates');
    }

    private function templateForm(int $id, TemplateDraft $draft): FormInterface
    {
        return $this->createForm(TemplateType::class, $draft, ['action' => $this->generateUrl('admin_template_save', ['id' => $id])]);
    }

    private function editor(int $id, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/template_form.html.twig', ['form' => $form, 'template_id' => $id], new Response(status: $status));
    }
}
