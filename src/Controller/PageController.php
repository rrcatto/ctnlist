<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Static site pages. */
final class PageController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    #[Route('/index', name: 'home_index', methods: ['GET'])]
    #[Route('/home', name: 'home_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('page/home.html.twig');
    }

    #[Route('/privacy', name: 'privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('page/privacy.html.twig');
    }

    #[Route('/store', name: 'store', methods: ['GET'])]
    public function store(): Response
    {
        return $this->render('page/store.html.twig');
    }
}
