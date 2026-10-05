<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Pagination;
use App\Repository\ArchiveRepository;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Public campaign archives (immutable copies made when a message was first queued). */
final class ArchiveController extends AbstractController
{
    public function __construct(private readonly ArchiveRepository $archives)
    {
    }

    #[Route('/archives/{page}', name: 'archives', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    public function list(int $page, Request $request): Response
    {
        $pagination = Pagination::fromRequest($request, $page, $this->archives->count());
        return $this->render('archive/list.html.twig', [
            'archives' => $this->archives->page($pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
        ]);
    }

    /**
     * One archive; signed-in visitors can forward it. The token/MUID form
     * comes from the {archive} link in a delivered message.
     */
    #[Route('/archive/{id}/{token}/{muid}', name: 'archive', defaults: ['token' => '', 'muid' => ''], requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, string $token, string $muid, #[CurrentUser] ?SubscriberUser $user): Response
    {
        $archive = $this->archives->find($id) ?? throw $this->createNotFoundException('Unknown archive.');
        $this->archives->countView($id);
        return $this->render('archive/show.html.twig', [
            'archive' => $archive,
            'forward_token' => $user === null ? null : ($token !== '' ? $token : $user->uuid),
            'muid' => $muid,
        ]);
    }
}
