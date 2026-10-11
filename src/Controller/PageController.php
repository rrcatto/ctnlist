<?php

declare(strict_types=1);

namespace App\Controller;

use App\Util\Markdown;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Markup;

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

    /**
     * The acceptable use policy, built from docs/AUP.md of the release: the
     * rules of the mail service this site sends its mailing lists through.
     * App\Util\Markdown escapes all of the document's text, so its HTML is
     * printed as it is.
     */
    #[Route('/acceptable-use-policy', name: 'acceptable_use_policy', methods: ['GET'])]
    public function acceptableUsePolicy(#[Autowire('%kernel.project_dir%/docs/AUP.md')] string $file): Response
    {
        $markdown = is_file($file) ? (string) file_get_contents($file) : '';
        if (trim($markdown) === '') {
            throw $this->createNotFoundException();
        }
        return $this->render('page/policy.html.twig', [
            'title' => Markdown::title($markdown) ?? 'Acceptable Use Policy',
            'policy' => new Markup(Markdown::toHtml($markdown), 'UTF-8'),
        ]);
    }
}
