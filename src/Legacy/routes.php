<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;

/**
 * The Fat-Free routes that have not been ported to Symfony controllers yet,
 * moved unchanged from the old front controller. LegacyBridge calls this with
 * the container-built legacy services; each migration phase deletes the routes
 * it ports. Pages go through $render, which hands the title and content to
 * LegacyPage; the bridge wraps them in the Twig layout.
 */
return static function (
    Base $fat,
    OptionsController $options,
    UsersController $user,
    SendlogController $sendlog,
    SmlogController $smlog,
    SiteLogController $sitelog,
    LegacyPage $page,
): void {
    $options->SetOption('version', (string) $fat->get('version'));

    $fat->set('r', max(1, min(200, (int) ($fat->get('GET.r') ?: 10))));

    // Content strings may contain F3 tokens ({{@BaseURL}}, design.ini
    // classes such as {{@pclass}}), resolved here before Twig wraps them.
    $render = static function (string $title, string $content) use ($page): void {
        $template = \Template::instance();
        $page->set($title, $template->resolve($template->parse($content)));
    };
    $admin = static function (?string $permission = null) use ($fat, $user): void {
        if ($permission !== null && $user->can($permission)) {
            return;
        }
        if ((int) $fat->get('uadmin') !== 1) {
            $fat->error(403, 'Access denied.');
        }
    };

    // Audit and activity reports.
    $fat->route('GET /sendlog', static function (Base $fat) use ($admin, $sendlog, $render): void { $admin('logs.view'); $render('Send Log', $sendlog->CreateSendlogHTMLList((string) $fat->get('GET.e'), (string) $fat->get('GET.t'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /sendlog/@p', static function (Base $fat, array $params) use ($admin, $sendlog, $render): void { $admin('logs.view'); $render('Send Log', $sendlog->CreateSendlogHTMLList((string) $fat->get('GET.e'), (string) $fat->get('GET.t'), (int) $params['p'], (int) $fat->get('r'))); });
    $fat->route('GET /sitelog', static function (Base $fat) use ($admin, $sitelog, $render): void { $admin('logs.view'); $render('Site Log', $sitelog->CreateSiteLogHTMLList((string) $fat->get('GET.q'), (string) $fat->get('GET.e'), (string) $fat->get('GET.u'), (string) $fat->get('GET.ip'), (string) $fat->get('GET.li'), (string) $fat->get('GET.from'), (string) $fat->get('GET.to'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /sitelog/@p', static function (Base $fat, array $params) use ($admin, $sitelog, $render): void { $admin('logs.view'); $render('Site Log', $sitelog->CreateSiteLogHTMLList((string) $fat->get('GET.q'), (string) $fat->get('GET.e'), (string) $fat->get('GET.u'), (string) $fat->get('GET.ip'), (string) $fat->get('GET.li'), (string) $fat->get('GET.from'), (string) $fat->get('GET.to'), (int) $params['p'], (int) $fat->get('r'))); });
    $fat->route('GET /message-views/@muid', static function (Base $fat, array $params) use ($admin, $smlog, $render): void { $admin('logs.view'); $render('Message activity', $smlog->CreateMessageReadsHTMLList((string) $params['muid'], (string) $fat->get('GET.e'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /message-views/@muid/@p', static function (Base $fat, array $params) use ($admin, $smlog, $render): void { $admin('logs.view'); $render('Message activity', $smlog->CreateMessageReadsHTMLList((string) $params['muid'], (string) $fat->get('GET.e'), (int) $params['p'], (int) $fat->get('r'))); });

};
