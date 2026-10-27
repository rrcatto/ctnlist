<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\RendersRegions;
use App\Http\Pagination;
use App\Log\FlaggedActivity;
use App\Log\MessageLog;
use App\Repository\SendLogRepository;
use App\Repository\SiteLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The Send Log, Site Log, Flagged activity and message activity reports. */
#[IsGranted('logs.view')]
final class ReportController extends AbstractController
{
    use RendersRegions;

    #[Route('/sendlog/{page}', name: 'report_sendlog', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    public function sendLog(int $page, Request $request, SendLogRepository $sendLog): Response
    {
        $email = trim($request->query->getString('e'));
        $type = trim($request->query->getString('t'));
        $pagination = Pagination::fromRequest($request, $page, $sendLog->count($email, $type));
        return $this->renderPage($request, 'report/sendlog.html.twig', ['sendlog-results' => 'report/_sendlog_results.html.twig'], [
            'by_type' => $sendLog->totalsByType(),
            'by_month' => $sendLog->totalsByMonth(),
            'types' => $sendLog->types(),
            'filters' => ['e' => $email, 't' => $type],
            'rows' => $pagination->total === 0 ? [] : $sendLog->page($email, $type, $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
            'query' => self::query(['e' => $email, 't' => $type, 'r' => (string) $pagination->perPage]),
        ]);
    }

    #[Route('/sitelog/{page}', name: 'report_sitelog', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    public function siteLog(int $page, Request $request, SiteLogRepository $siteLog): Response
    {
        $filters = [];
        foreach (SiteLogRepository::FILTERS as $key) {
            $filters[$key] = trim($request->query->getString($key));
        }
        $pagination = Pagination::fromRequest($request, $page, $siteLog->count($filters));
        return $this->renderPage($request, 'report/sitelog.html.twig', ['sitelog-results' => 'report/_sitelog_results.html.twig'], [
            'filters' => $filters,
            'rows' => $pagination->total === 0 ? [] : $siteLog->page($filters, $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
            'query' => self::query($filters + ['r' => (string) $pagination->perPage]),
        ]);
    }

    /**
     * Bots on the public forms: refused submissions, the IP addresses behind
     * them, and new records nobody confirmed (shown to holders of
     * subscribers.view, since they are subscriber addresses).
     */
    #[Route('/flagged', name: 'report_flagged', methods: ['GET'])]
    public function flagged(FlaggedActivity $activity): Response
    {
        return $this->render('report/flagged.html.twig', [
            'refused' => $activity->refused(),
            'refused_shown' => FlaggedActivity::REFUSED_SHOWN,
            'addresses' => $activity->addresses(),
            'records' => $this->isGranted('subscribers.view') ? $activity->unconfirmedRecords() : null,
            'record_days' => FlaggedActivity::RECORD_DAYS,
        ]);
    }

    /** Subscribers who read a message, with their reactions (v5 message-views). */
    #[Route('/message-views/{muid}/{page}', name: 'report_message_views', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    public function messageViews(string $muid, int $page, Request $request, MessageLog $messageLog): Response
    {
        $email = trim($request->query->getString('e'));
        $pagination = Pagination::fromRequest($request, $page, $messageLog->readersCount($muid, $email));
        return $this->renderPage($request, 'report/message_views.html.twig', ['message-views-results' => 'report/_message_views_results.html.twig'], [
            'muid' => $muid,
            'email' => $email,
            'rows' => $pagination->total === 0 ? [] : $messageLog->readersPage($muid, $email, $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
            'query' => self::query(['e' => $email]),
        ]);
    }

    /** @param array<string, string> $values empty values are left out */
    private static function query(array $values): string
    {
        $query = http_build_query(array_filter($values, static fn(string $v): bool => $v !== ''));
        return $query === '' ? '' : '?' . $query;
    }
}
