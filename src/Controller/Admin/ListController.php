<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ListRepository;
use App\Security\Csrf;
use App\Subscriber\ListManager;
use App\Subscriber\ListNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        return $this->render('admin/lists.html.twig', ['lists' => $this->lists->all(false)]);
    }

    #[Route('/lists', name: 'admin_lists_create', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function create(Request $request): Response
    {
        try {
            $id = $this->manager->create(
                $request->request->getString('shortcode'),
                $request->request->getString('name'),
                $request->request->getString('description')
            );
            $this->addFlash('info', "List created with ID {$id}.");
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', 'Unable to create list: ' . $e->getMessage());
        }
        return $this->redirectToRoute('admin_lists');
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
}
