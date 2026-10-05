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
    SubscribersController $subscriber,
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
    $loggedIn = static function () use ($fat): void {
        if (!(bool) $fat->get('uloggedin')) {
            $fat->reroute('/login');
        }
    };
    $actionForm = static function (string $title, string $action, string $button, string $class = 'btn-primary') use ($fat): string {
        return '<form method="post" action="' . htmlspecialchars((string) $fat->get('BaseURL') . ltrim($action, '/')) . '" class="card card-body">'
            . Csrf::field($fat) . '<h1 class="h4">' . htmlspecialchars($title) . '</h1>'
            . '<button class="btn ' . htmlspecialchars($class) . '" type="submit">' . htmlspecialchars($button) . '</button></form>';
    };


    // Passwordless authentication and profile management.
    // Subscriber create/edit, search, import/export and integrations.
    $fat->route('GET /subscribe/@token', static fn(Base $fat, array $params) => $render('Subscriber', $subscriber->CreateSubscriberHTMLform((string) $params['token'])));
    $fat->route('GET /subscribe/@token/@muid', static fn(Base $fat, array $params) => $render('Subscriber', $subscriber->CreateSubscriberHTMLform((string) $params['token'], (string) $params['muid'])));
    $fat->route('POST /subscribe', static fn() => $render('Subscriber', $subscriber->save()));
    $showSubscribers = static function (Base $fat, int $page, bool $active) use ($admin, $subscriber, $render): void {
        $admin('subscribers.view');
        $render($active ? 'Active subscribers' : 'Subscribers', $subscriber->CreateSubscribersHTMLList(
            (string) $fat->get('GET.e'),
            $page,
            (int) $fat->get('r'),
            $active,
            (int) $fat->get('GET.u'),
            (int) $fat->get('GET.l')
        ));
    };
    $fat->route('GET /subscribers', static fn(Base $fat) => $showSubscribers($fat, 1, false));
    $fat->route('GET /subscribers/@p', static fn(Base $fat, array $params) => $showSubscribers($fat, (int) $params['p'], false));
    $fat->route('GET /activesubscribers', static fn(Base $fat) => $showSubscribers($fat, 1, true));
    $fat->route('GET /activesubscribers/@p', static fn(Base $fat, array $params) => $showSubscribers($fat, (int) $params['p'], true));
    $fat->route('GET /bulk-subscribe', static function () use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Bulk subscribe', $subscriber->CreateBulkSubscribeHTMLform());
    });
    $fat->route('POST /bulk-subscribe', static function (Base $fat) use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Bulk subscribe', $subscriber->BulkSubscribeForm((string) $fat->get('POST.bemail'), (int) $fat->get('POST.s_priority'), (int) $fat->get('POST.list_id')));
    });
    $fat->route('GET /bulk-unsubscribe', static function () use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Bulk unsubscribe', $subscriber->CreateBulkUnsubscribeHTMLform());
    });
    $fat->route('POST /bulk-unsubscribe', static function (Base $fat) use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Bulk unsubscribe', $subscriber->BulkUnsubscribeForm(
            (string) $fat->get('POST.bemail'),
            (int) $fat->get('POST.list_id'),
            (string) $fat->get('POST.reason'),
            (string) $fat->get('POST.scope')
        ));
    });
    $fat->route('GET /import', static function () use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Import subscribers', $subscriber->CreateImportHTMLform());
    });
    $fat->route('POST /import', static function () use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Import subscribers', $subscriber->ImportUploadedFile());
    });
    $fat->route('GET /export', static function (Base $fat) use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Export subscribers', $subscriber->exportPDO(0, 10000000, (int) $fat->get('GET.l')));
    });
    $fat->route('GET /export/@offset/@limit', static function (Base $fat, array $params) use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Export subscribers', $subscriber->exportPDO((int) $params['offset'], (int) $params['limit'], (int) $fat->get('GET.l')));
    });
    $fat->route('GET /sync', static function () use ($admin, $subscriber, $render): void {
        $admin('subscribers.manage');
        $render('Synchronise subscribers', '<p>Number synchronised: ' . $subscriber->SyncSubscribers() . '</p>');
    });
    $fat->route('POST /ecwid-subscribe', static function (Base $fat) use ($subscriber): void {
        $ok = $subscriber->EcwidSubscribe((string) $fat->get('POST.email'), (string) ($fat->get('POST.list_shortcode') ?: ListsM::ALL_SHORTCODE));
        http_response_code($ok ? 200 : 400);
        header('Content-Type: application/json');
        echo json_encode(['success' => $ok]);
    });

    // Message and template administration.
    // Audit and activity reports.
    $fat->route('GET /sendlog', static function (Base $fat) use ($admin, $sendlog, $render): void { $admin('logs.view'); $render('Send Log', $sendlog->CreateSendlogHTMLList((string) $fat->get('GET.e'), (string) $fat->get('GET.t'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /sendlog/@p', static function (Base $fat, array $params) use ($admin, $sendlog, $render): void { $admin('logs.view'); $render('Send Log', $sendlog->CreateSendlogHTMLList((string) $fat->get('GET.e'), (string) $fat->get('GET.t'), (int) $params['p'], (int) $fat->get('r'))); });
    $fat->route('GET /sitelog', static function (Base $fat) use ($admin, $sitelog, $render): void { $admin('logs.view'); $render('Site Log', $sitelog->CreateSiteLogHTMLList((string) $fat->get('GET.q'), (string) $fat->get('GET.e'), (string) $fat->get('GET.u'), (string) $fat->get('GET.ip'), (string) $fat->get('GET.li'), (string) $fat->get('GET.from'), (string) $fat->get('GET.to'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /sitelog/@p', static function (Base $fat, array $params) use ($admin, $sitelog, $render): void { $admin('logs.view'); $render('Site Log', $sitelog->CreateSiteLogHTMLList((string) $fat->get('GET.q'), (string) $fat->get('GET.e'), (string) $fat->get('GET.u'), (string) $fat->get('GET.ip'), (string) $fat->get('GET.li'), (string) $fat->get('GET.from'), (string) $fat->get('GET.to'), (int) $params['p'], (int) $fat->get('r'))); });
    $fat->route('GET /message-views/@muid', static function (Base $fat, array $params) use ($admin, $smlog, $render): void { $admin('logs.view'); $render('Message activity', $smlog->CreateMessageReadsHTMLList((string) $params['muid'], (string) $fat->get('GET.e'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /message-views/@muid/@p', static function (Base $fat, array $params) use ($admin, $smlog, $render): void { $admin('logs.view'); $render('Message activity', $smlog->CreateMessageReadsHTMLList((string) $params['muid'], (string) $fat->get('GET.e'), (int) $params['p'], (int) $fat->get('r'))); });

};
