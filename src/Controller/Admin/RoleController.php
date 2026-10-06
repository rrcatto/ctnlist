<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\FormErrors;
use App\Form\Model\RoleDetails;
use App\Form\Type\RolePermissionsType;
use App\Form\Type\RoleType;
use App\Form\Type\SubscriberSearchType;
use App\Http\Pagination;
use App\Repository\RoleRepository;
use App\Repository\SubscriberRepository;
use App\Security\Csrf;
use App\Security\RoleManager;
use App\Security\RoleNotFound;
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
        private readonly SubscriberRepository $subscribers,
    ) {
    }

    /** Overview: roles with member counts, the permission matrix, role creation and subscriber search for assignment. */
    #[Route('/roles', name: 'admin_roles', methods: ['GET'])]
    #[IsGranted('roles.manage')]
    public function index(Request $request): Response
    {
        return $this->overview($request, $this->createRoleForm());
    }

    /** One role: details, permissions and a page of its members. */
    #[Route('/roles/{id}/{page}', name: 'admin_role', defaults: ['page' => 1], requirements: ['id' => '\d+', 'page' => '\d+'], methods: ['GET'])]
    #[IsGranted('roles.manage')]
    public function show(int $id, int $page, Request $request): Response
    {
        return $this->rolePage($this->role($id), $page, $request);
    }

    #[Route('/roles', name: 'admin_roles_create', methods: ['POST'])]
    #[IsGranted('roles.manage')]
    public function create(Request $request): Response
    {
        $form = $this->createRoleForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RoleDetails $details */
            $details = $form->getData();
            try {
                $this->manager->create($details->key, $details->name, $details->description);
                $this->addFlash('info', 'Role created.');
                return $this->redirectToRoute('admin_roles');
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->overview($request, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/roles/{id}/edit', name: 'admin_role_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('roles.manage')]
    public function update(int $id, Request $request): Response
    {
        $role = $this->role($id);
        $form = $this->detailsForm($role);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RoleDetails $details */
            $details = $form->getData();
            try {
                $this->manager->update($id, $details->name, $details->description);
                $this->addFlash('info', 'Role updated.');
                return $this->redirectToRoute('admin_role', ['id' => $id]);
            } catch (RoleNotFound $e) {
                throw $this->createNotFoundException($e->getMessage(), $e);
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->rolePage($role, 1, $request, details: $form);
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

    #[Route('/roles/{id}/permissions', name: 'admin_roles_permissions', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('roles.manage')]
    #[IsGranted('acl.manage')]
    public function permissions(int $id, Request $request, #[CurrentUser] SubscriberUser $user): Response
    {
        $role = $this->role($id);
        $form = $this->permissionsForm($role);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var array{permissions: list<int>} $data */
                $data = $form->getData();
                $this->manager->setPermissions($id, $data['permissions'], $user);
                $this->addFlash('info', 'Permissions updated.');
                return $this->redirectToRoute('admin_role', ['id' => $id]);
            } catch (RoleNotFound $e) {
                throw $this->createNotFoundException($e->getMessage(), $e);
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        return $this->rolePage($role, 1, $request, permissions: $form);
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

    /** @return array{r_id: int, r_key: string, r_name: string, r_description: string, r_system: bool} */
    private function role(int $id): array
    {
        return $this->roles->find($id) ?? throw $this->createNotFoundException('Role does not exist.');
    }

    private function overview(Request $request, FormInterface $create, int $status = Response::HTTP_OK): Response
    {
        $search = $this->createForm(SubscriberSearchType::class, null, ['action' => $this->generateUrl('admin_roles') . '#assign', 'help' => 'Shows up to ' . self::SEARCH_LIMIT . ' matching subscribers.']);
        $search->handleRequest($request);
        $query = $request->isMethod('GET') && $search->isSubmitted() && $search->isValid() ? trim((string) $search->get('q')->getData()) : '';
        $results = $query === '' ? [] : $this->subscribers->search($query, self::SEARCH_LIMIT + 1);
        $more = count($results) > self::SEARCH_LIMIT;
        $results = array_slice($results, 0, self::SEARCH_LIMIT);
        return $this->render('admin/roles.html.twig', [
            'roles' => $this->roles->all(),
            'permissions' => $this->roles->permissions(),
            'granted' => $this->roles->grantedPermissionIds(),
            'member_counts' => $this->roles->memberCounts(),
            'search_form' => $search,
            'search' => $query,
            'results' => $results,
            'more_results' => $more,
            'held' => $this->roles->rolesForSubscribers(array_column($results, 's_id')),
            'form' => $create,
        ], new Response(status: $status));
    }

    /** @param array{r_id: int, r_key: string, r_name: string, r_description: string, r_system: bool} $role */
    private function rolePage(array $role, int $page, Request $request, ?FormInterface $details = null, ?FormInterface $permissions = null): Response
    {
        $pagination = Pagination::fromRequest($request, $page, $this->roles->memberCount($role['r_id']));
        return $this->render('admin/role.html.twig', [
            'role' => $role,
            'permissions' => $this->roles->permissions(),
            'granted' => $this->roles->grantedPermissionIds()[$role['r_id']] ?? [],
            'members' => $this->roles->members($role['r_id'], $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
            'details_form' => $role['r_system'] ? null : ($details ?? $this->detailsForm($role))->createView(),
            'permissions_form' => ($permissions ?? $this->permissionsForm($role))->createView(),
        ], new Response(status: $details !== null || $permissions !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function createRoleForm(): FormInterface
    {
        return $this->createForm(RoleType::class, new RoleDetails(), ['create' => true, 'action' => $this->generateUrl('admin_roles_create')]);
    }

    /** @param array{r_id: int, r_key: string, r_name: string, r_description: string, r_system: bool} $role */
    private function detailsForm(array $role): FormInterface
    {
        return $this->createForm(RoleType::class, RoleDetails::fromRole($role), ['action' => $this->generateUrl('admin_role_update', ['id' => $role['r_id']])]);
    }

    /** @param array{r_id: int, r_key: string, r_name: string, r_description: string, r_system: bool} $role */
    private function permissionsForm(array $role): FormInterface
    {
        return $this->createForm(RolePermissionsType::class, ['permissions' => $this->roles->grantedPermissionIds()[$role['r_id']] ?? []], [
            'permissions' => $this->roles->permissions(),
            'action' => $this->generateUrl('admin_roles_permissions', ['id' => $role['r_id']]),
            'disabled' => $role['r_system'] || !$this->isGranted('acl.manage'),
        ]);
    }
}
