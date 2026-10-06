<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Pagination;
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

/**
 * Roles, their members, ACL permissions and role assignment.
 *
 * roles.manage reaches these pages and manages roles and membership;
 * changing a custom role's permissions also needs acl.manage (so acl.manage
 * is only useful together with roles.manage).
 */
final class RoleController extends AbstractController
{
    /** Subscribers shown for one assignment search. */
    private const SEARCH_LIMIT = 20;

    public function __construct(
        private readonly RoleRepository $roles,
        private readonly RoleManager $manager,
    ) {
    }

    /** Overview: roles with member counts, the permission matrix, role creation and subscriber search for assignment. */
    #[Route('/roles', name: 'admin_roles', methods: ['GET'])]
    #[IsGranted('roles.manage')]
    public function index(Request $request, SubscriberRepository $subscribers): Response
    {
        $search = trim($request->query->getString('q'));
        $results = $search === '' ? [] : $subscribers->search($search, self::SEARCH_LIMIT + 1);
        $more = count($results) > self::SEARCH_LIMIT;
        $results = array_slice($results, 0, self::SEARCH_LIMIT);
        return $this->render('admin/roles.html.twig', [
            'roles' => $this->roles->all(),
            'permissions' => $this->roles->permissions(),
            'granted' => $this->roles->grantedPermissionIds(),
            'member_counts' => $this->roles->memberCounts(),
            'search' => $search,
            'results' => $results,
            'more_results' => $more,
            'held' => $this->roles->rolesForSubscribers(array_column($results, 's_id')),
        ]);
    }

    /** One role: details, permissions and a page of its members. */
    #[Route('/roles/{id}/{page}', name: 'admin_role', defaults: ['page' => 1], requirements: ['id' => '\d+', 'page' => '\d+'], methods: ['GET'])]
    #[IsGranted('roles.manage')]
    public function show(int $id, int $page, Request $request): Response
    {
        $role = $this->roles->find($id) ?? throw $this->createNotFoundException('Role does not exist.');
        $pagination = Pagination::fromRequest($request, $page, $this->roles->memberCount($id));
        return $this->render('admin/role.html.twig', [
            'role' => $role,
            'permissions' => $this->roles->permissions(),
            'granted' => $this->roles->grantedPermissionIds()[$id] ?? [],
            'members' => $this->roles->members($id, $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
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
        ), 'Role created.', $this->generateUrl('admin_roles'));
    }

    #[Route('/roles/{id}/edit', name: 'admin_role_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('roles.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function update(int $id, Request $request): Response
    {
        return $this->attempt(fn() => $this->manager->update(
            $id,
            $request->request->getString('name'),
            $request->request->getString('description')
        ), 'Role updated.', $this->generateUrl('admin_role', ['id' => $id]));
    }

    #[Route('/roles/{id}/delete', name: 'admin_role_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('roles.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function delete(int $id): Response
    {
        try {
            $this->manager->delete($id);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
            return $this->redirectToRoute('admin_role', ['id' => $id]);
        } catch (RoleNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        $this->addFlash('info', 'Role deleted.');
        return $this->redirectToRoute('admin_roles');
    }

    #[Route('/roles/permissions', name: 'admin_roles_permissions', methods: ['POST'])]
    #[IsGranted('roles.manage')]
    #[IsGranted('acl.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function permissions(Request $request, #[CurrentUser] SubscriberUser $user): Response
    {
        $roleId = $request->request->getInt('role_id');
        return $this->attempt(fn() => $this->manager->setPermissions(
            $roleId,
            array_values($request->request->all('permission_ids')),
            $user
        ), 'Permissions updated.', $this->generateUrl('admin_role', ['id' => $roleId]));
    }

    #[Route('/roles/assign', name: 'admin_roles_assign', methods: ['POST'])]
    #[IsGranted('roles.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function assign(Request $request, #[CurrentUser] SubscriberUser $user): Response
    {
        return $this->attempt(fn() => $this->addFlash('info', $this->manager->assign(
            $request->request->getInt('subscriber_id'),
            $request->request->getString('role_key'),
            $user
        ) ? 'Role assigned.' : 'The subscriber already holds that role.'), null, $this->back($request));
    }

    #[Route('/roles/unassign', name: 'admin_roles_unassign', methods: ['POST'])]
    #[IsGranted('roles.manage')]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function unassign(Request $request): Response
    {
        return $this->attempt(fn() => $this->manager->unassign(
            $request->request->getInt('subscriber_id'),
            $request->request->getInt('role_id')
        ), 'Role removed from the subscriber.', $this->back($request));
    }

    /** Run a RoleManager action, flash the outcome and redirect. */
    private function attempt(callable $action, ?string $success, string $redirect): Response
    {
        try {
            $action();
            if ($success !== null) {
                $this->addFlash('info', $success);
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (RoleNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        return $this->redirect($redirect);
    }

    /** The page a role form came from (`back`), limited to the roles pages. */
    private function back(Request $request): string
    {
        $back = $request->request->getString('back');
        return preg_match('#^/roles(?:/\d+(?:/\d+)?)?(?:\?[^\s]*)?$#', $back) === 1 ? $back : $this->generateUrl('admin_roles');
    }
}
