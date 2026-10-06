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

    /** Where subscriber links in a proof copy lead (proofs have no subscriber). */
    #[Route('/proof-link', name: 'proof_link', methods: ['GET'])]
    public function proofLink(): Response
    {
        return $this->render('page/result.html.twig', [
            'title' => 'Proof copy',
            'message' => 'This link is part of a proof copy of a message. A proof is not sent to a subscriber, so actions such as unsubscribing, confirming, forwarding, reacting or updating a profile are not available from it. In the message as sent, this link works for each recipient.',
        ]);
    }

    #[Route('/privacy', name: 'privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('page/privacy.html.twig');
    }
}
