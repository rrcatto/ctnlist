<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\FormErrors;
use App\Form\Model\ListDetails;
use App\Form\Type\ListType;
use App\Repository\ListRepository;
use App\Security\Csrf;
use App\Subscriber\ListManager;
use App\Subscriber\ListNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Mailing-list administration. */
#[IsGranted('lists.manage')]
final class ListController extends AbstractController
{
    public function __construct(
        private readonly ListRepository $lists,
        private readonly ListManager $manager,
    ) {
    }

    #[Route('/lists', name: 'admin_lists', methods: ['GET'])]
    public function index(): Response
    {
        return $this->indexPage($this->createListForm());
    }

    #[Route('/lists', name: 'admin_lists_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $this->createListForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ListDetails $details */
            $details = $form->getData();
            try {
                $id = $this->manager->create($details->shortcode, $details->name, $details->description);
                $this->addFlash('info', "List {$details->shortcode} created with ID {$id}.");
                return $this->redirectToRoute('admin_lists');
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->indexPage($form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{id}/edit', name: 'admin_lists_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $list = $this->lists->findById($id) ?? throw $this->createNotFoundException('Unknown list.');
        if ($list['l_system']) {
            $this->addFlash('danger', 'System lists cannot be changed.');
            return $this->redirectToRoute('admin_lists');
        }
        $form = $this->createForm(ListType::class, ListDetails::fromList($list));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ListDetails $details */
            $details = $form->getData();
            try {
                $this->manager->update($id, $details->name, $details->description);
                $this->addFlash('info', "List {$list['l_shortcode']} saved.");
                return $this->redirectToRoute('admin_lists');
            } catch (ListNotFound $e) {
                throw $this->createNotFoundException($e->getMessage(), $e);
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->render('admin/list_edit.html.twig', ['list' => $list, 'form' => $form],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/lists/delete', name: 'admin_lists_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function delete(Request $request): Response
    {
        try {
            $this->manager->delete($request->request->getInt('list_id'));
            $this->addFlash('info', 'List deleted.');
        } catch (ListNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('admin_lists');
    }

    private function createListForm(): FormInterface
    {
        return $this->createForm(ListType::class, new ListDetails(), ['create' => true, 'action' => $this->generateUrl('admin_lists_create')]);
    }

    private function indexPage(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/lists.html.twig', ['lists' => $this->lists->all(false), 'form' => $form], new Response(status: $status));
    }
}
