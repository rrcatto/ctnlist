<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\RoleRepository;
use App\Repository\SubscriberRepository;
use App\Security\Csrf;
use App\Security\RoleManager;
use App\Security\RoleNotFound;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Roles, ACL permissions and role assignment. */
final class RoleController extends AbstractController
{
    /** How many subscribers the assignment picker offers (by email). */
    private const SUBSCRIBER_PICKER_LIMIT = 500;

    public function __construct(
        private readonly RoleRepository $roles,
        private readonly SubscriberRepository $subscribers,
        private readonly RoleManager $manager,
    ) {
    }

    #[Route('/roles', name: 'admin_roles', methods: ['GET'])]
    #[IsGranted('roles.manage')]
    public function index(): Response
    {
        return $this->render('admin/roles.html.twig', [
            'roles' => $this->roles->all(),
            'permissions' => $this->roles->permissions(),
            'granted' => $this->roles->grantedPermissionIds(),
            'subscribers' => $this->subscribers->emails(self::SUBSCRIBER_PICKER_LIMIT),
        ]);
    }

    #[Route('/roles', name: 'admin_roles_create', methods: ['POST'])]
    #[IsGranted('roles.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function create(Request $request): Response
    {
        return $this->attempt(fn() => $this->manager->create(
            $request->request->getString('key'),
            $request->request->getString('name'),
            $request->request->getString('description')
        ), 'Role created.');
    }

    #[Route('/roles/permissions', name: 'admin_roles_permissions', methods: ['POST'])]
    #[IsGranted('acl.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function permissions(Request $request): Response
    {
        try {
            return $this->attempt(fn() => $this->manager->setPermissions(
                $request->request->getInt('role_id'),
                array_values($request->request->all('permission_ids'))
            ), 'Permissions updated.');
        } catch (RoleNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    #[Route('/roles/assign', name: 'admin_roles_assign', methods: ['POST'])]
    #[IsGranted('roles.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function assign(Request $request, #[CurrentUser] ?SubscriberUser $user): Response
    {
        return $this->attempt(fn() => $this->manager->assign(
            $request->request->getInt('subscriber_id'),
            $request->request->getString('role_key'),
            $user?->id
        ), 'Role assigned.');
    }

    private function attempt(callable $action, string $success): Response
    {
        try {
            $action();
            $this->addFlash('info', $success);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('admin_roles');
    }
}
