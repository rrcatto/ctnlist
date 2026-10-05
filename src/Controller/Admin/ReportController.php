<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Pagination;
use App\Log\MessageLog;
use App\Repository\SendLogRepository;
use App\Repository\SiteLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The Send Log, Site Log and message activity reports. */
#[IsGranted('logs.view')]
final class ReportController extends AbstractController
{
    #[Route('/sendlog/{page}', name: 'report_sendlog', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    public function sendLog(int $page, Request $request, SendLogRepository $sendLog): Response
    {
        $email = trim($request->query->getString('e'));
        $type = trim($request->query->getString('t'));
        $pagination = Pagination::fromRequest($request, $page, $sendLog->count($email, $type));
        return $this->render('report/sendlog.html.twig', [
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
        return $this->render('report/sitelog.html.twig', [
            'filters' => $filters,
            'rows' => $pagination->total === 0 ? [] : $siteLog->page($filters, $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
            'query' => self::query($filters + ['r' => (string) $pagination->perPage]),
        ]);
    }

    /** Subscribers who read a message, with their reactions (v5 message-views). */
    #[Route('/message-views/{muid}/{page}', name: 'report_message_views', defaults: ['page' => 1], requirements: ['page' => '\d+'], methods: ['GET'])]
    public function messageViews(string $muid, int $page, Request $request, MessageLog $messageLog): Response
    {
        $email = trim($request->query->getString('e'));
        $pagination = Pagination::fromRequest($request, $page, $messageLog->readersCount($muid, $email));
        return $this->render('report/message_views.html.twig', [
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
