<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\SiteConfig;
use App\Maintenance\Maintenance;
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

    /**
     * The privacy policy, built from docs/PRIVACY.md of the release like the
     * acceptable use policy below, with one more placeholder: how long the
     * Site Log keeps its rows ({{site_log_retention}}, from
     * APP_SITELOG_RETENTION, as ctnlist:maintenance applies it). Requests
     * about personal information go to the administrator's address, the one
     * the footer links to ({{administrator_email}}, MAIL_ADMIN_ADDRESS or else
     * APP_ADMIN_EMAIL): the document writes no address of its own.
     */
    #[Route('/privacy', name: 'privacy', methods: ['GET'])]
    public function privacy(SiteConfig $site, #[Autowire('%kernel.project_dir%/docs/PRIVACY.md')] string $file,
        #[Autowire('%env(default::APP_SITELOG_RETENTION)%')] ?string $siteLogRetention = null): Response
    {
        return $this->policy($site, $file, 'Privacy Policy', $site->listName . ' and your personal information: what is collected, why, who handles it and your rights.',
            ['{{site_log_retention}}' => self::siteLogRetention($siteLogRetention), '{{administrator_email}}' => $site->adminEmail], false);
    }

    /** How long the Site Log keeps a visit, in the words of the privacy policy ("are kept for …"). */
    public static function siteLogRetention(?string $setting): string
    {
        return 'for ' . Maintenance::siteLogRetention($setting)->words();
    }

    /**
     * The acceptable use policy, built from docs/AUP.md of the release: the
     * rules for this site's subscribers and for whoever writes and sends its
     * messages. The document names no product and no domain; its placeholders
     * are filled with this installation's own name, domain and organisation
     * (Settings), after rendering and escaped, so a configured value is shown
     * exactly as written. The page carries one address only: abuse@<domain>,
     * where abuse reports and complaints go (the document writes
     * abuse@{{domain}}). The administrator's and contact addresses are never
     * on it, not even in the footer. App\Util\Markdown escapes all of the
     * document's text, so its HTML is printed as it is.
     */
    #[Route('/acceptable-use-policy', name: 'acceptable_use_policy', methods: ['GET'])]
    public function acceptableUsePolicy(SiteConfig $site, #[Autowire('%kernel.project_dir%/docs/AUP.md')] string $file): Response
    {
        // One address on this page, the policy's abuse address: the footer leaves the administrator's out.
        return $this->policy($site, $file, 'Acceptable Use Policy', 'The rules for the subscribers of ' . $site->listName . ' and for whoever writes and sends its messages.', [], true);
    }

    /**
     * A policy document of the release as a page, its placeholders filled with
     * this installation's values after rendering, escaped.
     *
     * @param array<string, string> $more placeholders of this document only
     */
    private function policy(SiteConfig $site, string $file, string $fallbackTitle, string $description, array $more, bool $withoutAdministrator): Response
    {
        $markdown = is_file($file) ? (string) file_get_contents($file) : '';
        if (trim($markdown) === '') {
            throw $this->createNotFoundException();
        }
        $names = [
            '{{site_name}}' => $site->listName,
            '{{domain}}' => $site->domain,
            '{{organisation}}' => $site->organisation !== '' ? $site->organisation : 'the operator of ' . $site->listName,
        ] + $more;
        $escaped = array_map(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $names);
        return $this->render('page/policy.html.twig', [
            'title' => strtr(Markdown::title($markdown) ?? $fallbackTitle, $names),
            'description' => $description,
            'policy' => new Markup(strtr(Markdown::toHtml($markdown), $escaped), 'UTF-8'),
            'footer_without_administrator' => $withoutAdministrator,
        ]);
    }
}
