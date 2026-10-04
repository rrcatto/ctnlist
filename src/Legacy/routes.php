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
    MessagesController $message,
    TemplatesController $template,
    ArchivesController $archive,
    mailer $mailer,
    QueueController $queue,
    ListService $listService,
    ListsController $listsController,
    RolesController $rolesController,
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
    $fat->route('GET /login', static fn() => $render('Login', $user->CreateLoginHTMLform()));
    $fat->route('POST /login', static function (Base $fat) use ($user, $render): void {
        Csrf::requireValid($fat);
        $returnAction = trim((string) $fat->get('POST.return_action')) ?: 'profile';
        $returnMessageId = (int) $fat->get('POST.return_message_id');
        $returnListId = (int) $fat->get('POST.return_list_id');
        $user->requestMagicLink(
            (string) $fat->get('POST.email'),
            $returnAction,
            $returnMessageId > 0 ? $returnMessageId : null,
            $returnListId > 0 ? $returnListId : null
        );
        $render('Check your email', '<p>If the address is valid, a secure sign-in link has been sent.</p>');
    });
    $fat->route('POST /auth/request', static function (Base $fat) use ($subscriber, $user, $render): void {
        Csrf::requireValid($fat);
        $token = strtolower(trim((string) $fat->get('POST.subscriber_token')));
        if (!$subscriber->RetrieveSubscriber($token)) {
            $fat->error(404);
        }
        $user->requestMagicLink(
            (string) $subscriber->subscriber->s_email,
            (string) $fat->get('POST.return_action'),
            (int) $fat->get('POST.message_id') ?: null,
            (int) $fat->get('POST.list_id') ?: null
        );
        $render('Check your email', '<p>If the request is valid, a secure sign-in link has been sent.</p>');
    });
    $fat->route('GET /profile', static function (Base $fat) use ($user, $loggedIn): void {
        $loggedIn();
        $fat->reroute('/profile/subscriber/' . rawurlencode((string) $user->user->s_uuid));
    });
    $fat->route('GET /profile/subscriber/@token', static function (Base $fat, array $params) use ($user, $render): void {
        $token = strtolower((string) $params['token']);
        if (!$user->matchesSubscriberToken($token)) {
            $render('Authentication required', $user->authenticationPrompt($token, 'profile'));
            return;
        }
        $render('My profile', $user->DisplayProfileHTML());
    });
    $fat->route('GET /edit-profile', static function () use ($user, $loggedIn, $render): void {
        $loggedIn();
        $render('Edit profile', $user->CreateEditProfileHTMLform());
    });
    $fat->route('POST /edit-profile', static function () use ($user, $loggedIn, $render): void {
        $loggedIn();
        $render('Edit profile', $user->save() . $user->CreateEditProfileHTMLform());
    });
    $fat->route('GET /my/messages', static function () use ($user, $loggedIn, $render): void {
        $loggedIn();
        $render('Messages sent to me', $user->DisplayMessageHistoryHTML());
    });

    // Per-list consent. The MUID route variants preserve message-linked smlog
    // activity.
    $showConfirm = static function (Base $fat, array $params) use ($subscriber, $user, $render): void {
        $render('Confirm subscription', $subscriber->CreateConfirmHTMLform(
            (string) $params['token'],
            strtoupper(trim((string) $params['shortcode'])),
            $user,
            trim((string) ($params['muid'] ?? ''))
        ));
    };
    $fat->route('GET /confirm/@token/@shortcode', $showConfirm);
    $fat->route('GET /confirm/@token/@shortcode/@muid', $showConfirm);
    $fat->route('POST /confirm', static fn() => $render('Subscription confirmed', $subscriber->ConfirmSubscription($user)));
    $showUnsubscribe = static function (Base $fat, array $params) use ($subscriber, $user, $render): void {
        $render('Unsubscribe', $subscriber->CreateUnsubscribeHTMLform(
            (string) $params['token'],
            strtoupper(trim((string) $params['shortcode'])),
            $user,
            trim((string) ($params['muid'] ?? ''))
        ));
    };
    $fat->route('GET /unsubscribe/@token/@shortcode', $showUnsubscribe);
    $fat->route('GET /unsubscribe/@token/@shortcode/@muid', $showUnsubscribe);
    $fat->route('POST /unsubscribe', static fn() => $render('Unsubscribed', $subscriber->Unsubscribe($user)));

    // Subscriber message actions.
    // Restore the v5 administrator convenience route for forwarding a message.
    $fat->route('GET /forward/@muid', static function (Base $fat, array $params) use ($admin, $user, $message, $render): void {
        $admin('messages.manage');
        $render(
            'Forward message',
            $message->CreateForwardHTMLform((string) $user->user->s_uuid, (string) $params['muid'])
        );
    });
    $fat->route('GET /forward/@token/@muid', static function (Base $fat, array $params) use ($user, $message, $render): void {
        $token = strtolower((string) $params['token']);
        $muid = (string) $params['muid'];
        if (!$user->matchesSubscriberToken($token)) {
            $render('Authentication required', $user->authenticationPrompt($token, 'forward', $muid));
            return;
        }
        $render('Forward message', $message->CreateForwardHTMLform($token, $muid));
    });
    $fat->route('POST /forward', static function (Base $fat) use ($admin, $user, $message, $render): void {
        $token = strtolower(trim((string) $fat->get('POST.subscriber_token')));
        if (!$user->matchesSubscriberToken($token)) {
            $admin('messages.manage');
        }
        $render('Forward message', $message->ForwardSubscribeMessage($token, (string) $fat->get('POST.muid'), (string) $fat->get('POST.bemail')));
    });
    $fat->route('POST /forward-archive', static function (Base $fat) use ($admin, $user, $message, $render): void {
        $token = strtolower(trim((string) $fat->get('POST.subscriber_token')));
        if (!$user->matchesSubscriberToken($token)) {
            $admin('messages.manage');
        }
        $render('Forward archive', $message->ForwardSubscribeArchive());
    });
    foreach (['like', 'dislike'] as $reaction) {
        $fat->route('GET /' . $reaction . '/@token/@muid', static function (Base $fat, array $params) use ($user, $message, $render, $reaction): void {
            $token = strtolower((string) $params['token']);
            $muid = (string) $params['muid'];
            if (!$user->matchesSubscriberToken($token)) {
                $render('Authentication required', $user->authenticationPrompt($token, $reaction, $muid));
                return;
            }
            $render(ucfirst($reaction) . ' message', $message->CreateReactionHTMLform($token, $muid, $reaction));
        });
        $fat->route('POST /' . $reaction, static function (Base $fat) use ($user, $message, $render, $reaction): void {
            $token = strtolower(trim((string) $fat->get('POST.subscriber_token')));
            $user->requireMatchingSubscriberToken($token);
            $result = $reaction === 'like'
                ? $message->like($token, (string) $fat->get('POST.muid'))
                : $message->dislike($token, (string) $fat->get('POST.muid'));
            $render(ucfirst($reaction) . ' message', $result);
        });
    }
    $fat->route('GET /resend/@token/@muid', static function (Base $fat, array $params) use ($user, $message, $render): void {
        $token = strtolower((string) $params['token']);
        $muid = (string) $params['muid'];
        if (!$user->matchesSubscriberToken($token)) {
            $render('Authentication required', $user->authenticationPrompt($token, 'resend', $muid));
            return;
        }
        $render('Send message again', $message->CreateResendHTMLform($token, $muid));
    });
    $fat->route('POST /resend', static function (Base $fat) use ($user, $message, $subscriber, $render): void {
        Csrf::requireValid($fat);
        $token = strtolower(trim((string) $fat->get('POST.subscriber_token')));
        $muid = trim((string) $fat->get('POST.muid'));
        $user->requireMatchingSubscriberToken($token);
        if (!$message->messageWasSentToSubscriber($token, $muid) || !$subscriber->RetrieveSubscriber($token)) {
            $fat->error(404);
        }
        $sent = $message->SendToAddress($muid, (string) $subscriber->subscriber->s_email, 'RESEND');
        $render('Send message again', '<p>' . ($sent > 0 ? 'The current version was sent.' : 'The message could not be sent.') . '</p>');
    });
    $fat->route('GET /ut/@token/@muid', static function (Base $fat, array $params) use ($message): void {
        $message->TrackOpen((string) $params['token'], (string) $params['muid']);
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, max-age=0');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    });

    // Subscriber create/edit, search, import/export and integrations.
    $fat->route('GET /subscribe', static function (Base $fat) use ($user, $message, $listService, $render): void {
        $muid = trim((string) $fat->get('GET.m'));
        $shortcode = strtoupper(trim((string) $fat->get('GET.l')));
        $messageId = 0;
        $list = null;

        if ($muid !== '' && $message->RetrieveMessage($muid)) {
            $messageId = (int) $message->message->m_id;
            if ($shortcode !== '') {
                $list = $listService->findByShortcode($shortcode);
            } else {
                // Links from messages sent without a list context carry only the
                // MUID. A single assigned list is unambiguous. When a message has
                // several lists, let the subscriber choose the list.
                $listIds = $listService->messageListIds($messageId);
                if (count($listIds) === 1) {
                    $list = $listService->findById($listIds[0]);
                } elseif (count($listIds) > 1) {
                    $choices = '<h1 class="h4">Choose a mailing list</h1><p>Select the list you want to join:</p><ul>';
                    foreach ($listIds as $listId) {
                        $assigned = $listService->findById($listId);
                        if ($assigned === null || !filter_var($assigned['l_active'], FILTER_VALIDATE_BOOLEAN)) {
                            continue;
                        }
                        $choiceUrl = (string) $fat->get('BaseURL') . 'subscribe?m=' . rawurlencode($muid)
                            . '&l=' . rawurlencode((string) $assigned['l_shortcode']);
                        $choices .= '<li><a href="' . htmlspecialchars($choiceUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                            . htmlspecialchars((string) $assigned['l_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
                    }
                    $render('Subscribe', $choices . '</ul>');
                    return;
                }
            }
        }

        if ($messageId > 0 && $list !== null && filter_var($list['l_active'], FILTER_VALIDATE_BOOLEAN)) {
            $shortcode = (string) $list['l_shortcode'];
            $listId = (int) $list['l_id'];
            if ($user->uloggedin) {
                $fat->reroute('/confirm/' . rawurlencode((string) $user->user->s_uuid)
                    . '/' . rawurlencode($shortcode) . '/' . rawurlencode($muid));
            }
            $render(
                'Subscribe',
                '<h1 class="h4">Subscribe to ' . htmlspecialchars((string) $list['l_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
                . '<p>Sign in by email to confirm this list subscription.</p>'
                . $user->CreateLoginHTMLform('confirm', $messageId, $listId)
            );
            return;
        }

        $render('Subscribe', '<h1 class="h4">Manage subscriptions</h1><p>Sign in to manage your list memberships.</p>' . $user->CreateLoginHTMLform());
    });
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
    $fat->route('GET /messages', static function (Base $fat) use ($admin, $message, $render): void {
        $admin('messages.manage');
        $render('Messages', $message->CreateMessagesHTMLList(1, (int) $fat->get('r')));
    });
    $fat->route('GET /messages/@p', static function (Base $fat, array $params) use ($admin, $message, $render): void {
        $admin('messages.manage');
        $render('Messages', $message->CreateMessagesHTMLList((int) $params['p'], (int) $fat->get('r')));
    });
    $fat->route('GET /message', static function () use ($admin, $message, $render): void {
        $admin('messages.manage');
        $render('Create message', $message->CreateMessageHTMLform());
    });
    $fat->route('GET /message/@muid', static function (Base $fat, array $params) use ($admin, $message, $render): void {
        $admin('messages.manage');
        $render('Edit message', $message->CreateMessageHTMLform((string) $params['muid']));
    });
    $fat->route('POST /message', static function () use ($admin, $message, $render): void {
        $admin('messages.manage');
        $render('Message saved', $message->save() . $message->CreateMessagesHTMLList(1, 25, true));
    });
    $fat->route('GET /templates', static function (Base $fat) use ($admin, $template, $render): void {
        $admin('templates.manage');
        $render('Templates', $template->CreateTemplatesHTMLList(1, (int) $fat->get('r')));
    });
    $fat->route('GET /templates/@p', static function (Base $fat, array $params) use ($admin, $template, $render): void {
        $admin('templates.manage');
        $render('Templates', $template->CreateTemplatesHTMLList((int) $params['p'], (int) $fat->get('r')));
    });
    $fat->route('GET /template', static function () use ($admin, $template, $render): void {
        $admin('templates.manage');
        $render('Create template', $template->CreateTemplateHTMLform());
    });
    $fat->route('GET /template/@tid', static function (Base $fat, array $params) use ($admin, $template, $render): void {
        $admin('templates.manage');
        $render('Edit template', $template->CreateTemplateHTMLform((int) $params['tid']));
    });
    $fat->route('POST /template', static function () use ($admin, $template, $render): void {
        $admin('templates.manage');
        $render('Template saved', $template->save() . $template->CreateTemplatesHTMLList(1, 25, true));
    });

    // Multiple lists, roles and ACL retained from v5.0.1.
    $fat->route('GET /lists', static function () use ($admin, $listsController, $render): void { $admin('lists.manage'); $render('Lists', $listsController->index()); });
    $fat->route('POST /lists', static function () use ($admin, $listsController, $render): void { $admin('lists.manage'); $render('Lists', $listsController->create() . $listsController->index()); });
    $fat->route('POST /lists/delete', static function () use ($admin, $listsController, $render): void { $admin('lists.manage'); $render('Lists', $listsController->delete() . $listsController->index()); });
    $fat->route('GET /roles', static function () use ($admin, $rolesController, $render): void { $admin('roles.manage'); $render('Roles and ACL', $rolesController->index()); });
    $fat->route('POST /roles', static function () use ($admin, $rolesController, $render): void { $admin('roles.manage'); $render('Roles and ACL', $rolesController->create() . $rolesController->index()); });
    $fat->route('POST /roles/permissions', static function () use ($admin, $rolesController, $render): void { $admin('acl.manage'); $render('Roles and ACL', $rolesController->savePermissions() . $rolesController->index()); });
    $fat->route('POST /roles/assign', static function () use ($admin, $rolesController, $render): void { $admin('roles.manage'); $render('Roles and ACL', $rolesController->assign() . $rolesController->index()); });

    // Queue construction and delivery.
    $fat->route('GET /advanced-queue', static function () use ($admin, $message, $render): void { $admin('messages.queue'); $render('Queue multiple messages', $message->CreateAdvancedQueueHTMLform()); });
    $fat->route('POST /advanced-queue', static function (Base $fat) use ($admin, $message, $render): void {
        $admin('messages.queue');
        Csrf::requireValid($fat);
        $muids = [];
        for ($i = 1; $i <= 4; $i++) {
            $value = trim((string) $fat->get('POST.muid' . $i));
            if ($value !== '') { $muids[] = $value; }
        }
        $render('Queue multiple messages', $message->AdvancedSendListToQueue($muids, (int) $fat->get('POST.mvolume')));
    });
    $fat->route('GET /queuelist/@muid', static function (Base $fat, array $params) use ($admin, $render): void {
        $admin('messages.queue');
        $muid = htmlspecialchars((string) $params['muid'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $render('Queue message', '<form method="post" action="{{@BaseURL}}queuelist" class="card card-body">' . Csrf::field($fat)
            . '<input type="hidden" name="muid" value="' . $muid . '"><label class="form-label">Maximum subscribers to add in this operation</label>'
            . '<input class="form-control mb-3" type="number" name="limit" value="500000"><button class="btn btn-primary">Queue this message</button></form>');
    });
    $fat->route('POST /queuelist', static function (Base $fat) use ($admin, $message, $render): void {
        $admin('messages.queue');
        Csrf::requireValid($fat);
        $render('Queue message', $message->SendListToQueue((string) $fat->get('POST.muid'), (int) ($fat->get('POST.limit') ?: 500000)));
    });
    $fat->route('GET /queue', static function (Base $fat) use ($admin, $queue, $render): void { $admin('queue.process'); $render('Queue', $queue->CreateQueueHTMLList(1, (int) $fat->get('r'))); });
    $fat->route('GET /queue/@p', static function (Base $fat, array $params) use ($admin, $queue, $render): void { $admin('queue.process'); $render('Queue', $queue->CreateQueueHTMLList((int) $params['p'], (int) $fat->get('r'))); });
    $fat->route('POST /queue/delete', static function (Base $fat) use ($admin, $queue, $render): void { $admin('queue.process'); Csrf::requireValid($fat); $queue->DeleteQueueItem((int) $fat->get('POST.queue_id')); $render('Queue', $queue->CreateQueueHTMLList(1, (int) $fat->get('r'))); });
    $fat->route('POST /queue/clear', static function (Base $fat) use ($admin, $queue, $render): void { $admin('queue.process'); Csrf::requireValid($fat); $count = $queue->ClearQueue(); $render('Queue', '<p>Cleared ' . $count . ' queue record(s).</p>'); });
    $processForm = static function (Base $fat, string $muid = '', int $limit = 250000): string {
        return '<form method="post" action="{{@BaseURL}}processqueue" class="card card-body">' . Csrf::field($fat)
            . '<h1 class="h4">Process delivery queue</h1><input type="hidden" name="muid" value="' . htmlspecialchars($muid) . '">'
            . '<label class="form-label">Maximum messages to send</label><input class="form-control mb-3" type="number" name="limit" value="' . $limit . '">'
            . '<button class="btn btn-primary" type="submit">Start sending</button></form>';
    };
    $fat->route('GET /processqueue', static function (Base $fat) use ($admin, $render, $processForm): void { $admin('queue.process'); $render('Process queue', $processForm($fat)); });
    $fat->route('GET /processqueue/@muid', static function (Base $fat, array $params) use ($admin, $render, $processForm): void { $admin('queue.process'); $render('Process queue', $processForm($fat, (string) $params['muid'])); });
    $fat->route('GET /processqueue/@muid/@limit', static function (Base $fat, array $params) use ($admin, $render, $processForm): void { $admin('queue.process'); $render('Process queue', $processForm($fat, (string) $params['muid'], (int) $params['limit'])); });
    $fat->route('POST /processqueue', static function (Base $fat) use ($admin, $queue, $render): void {
        $admin('queue.process'); Csrf::requireValid($fat);
        $sent = $queue->ProcessQueue((string) $fat->get('POST.muid'), (int) ($fat->get('POST.limit') ?: 250000));
        $render('Process queue', '<p>Sent ' . $sent . ' message(s).</p>');
    });
    $fat->route('GET /stop-send', static function (Base $fat) use ($admin, $actionForm, $render): void { $admin('queue.process'); $render('Stop sending', $actionForm('Stop queue processing', '/stop-send', 'Stop sending', 'btn-danger')); });
    $fat->route('POST /stop-send', static function (Base $fat) use ($admin, $options, $render): void { $admin('queue.process'); Csrf::requireValid($fat); $options->SetOption('SendQueue', 'N'); $render('Stop sending', '<p>The stop request has been recorded.</p>'); });
    $fat->route('GET /sendtome/@muid', static function (Base $fat, array $params) use ($admin, $render): void {
        $admin('messages.manage');
        $muid = (string) $params['muid'];
        $html = '<form method="post" action="{{@BaseURL}}sendtome/'
            . rawurlencode($muid) . '" class="card card-body">' . Csrf::field($fat)
            . '<h1 class="h4">Send proof message</h1><p>Send this message to '
            . htmlspecialchars((string) $fat->get('TestEmail'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '?</p>'
            . '<button class="btn btn-primary" type="submit">Send Proof</button></form>';
        $render('Proof send', $html);
    });
    $fat->route('POST /sendtome/@muid', static function (Base $fat, array $params) use ($admin, $message, $render): void {
        $admin('messages.manage');
        Csrf::requireValid($fat);
        $sent = $message->SendToAddress((string) $params['muid'], (string) $fat->get('TestEmail'), 'PROOF');
        $render('Proof send', '<p>' . ($sent > 0 ? 'Proof message sent.' : 'Proof message could not be sent.') . '</p>');
    });

    // Audit and activity reports.
    $fat->route('GET /sendlog', static function (Base $fat) use ($admin, $sendlog, $render): void { $admin('logs.view'); $render('Send Log', $sendlog->CreateSendlogHTMLList((string) $fat->get('GET.e'), (string) $fat->get('GET.t'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /sendlog/@p', static function (Base $fat, array $params) use ($admin, $sendlog, $render): void { $admin('logs.view'); $render('Send Log', $sendlog->CreateSendlogHTMLList((string) $fat->get('GET.e'), (string) $fat->get('GET.t'), (int) $params['p'], (int) $fat->get('r'))); });
    $fat->route('GET /sitelog', static function (Base $fat) use ($admin, $sitelog, $render): void { $admin('logs.view'); $render('Site Log', $sitelog->CreateSiteLogHTMLList((string) $fat->get('GET.q'), (string) $fat->get('GET.e'), (string) $fat->get('GET.u'), (string) $fat->get('GET.ip'), (string) $fat->get('GET.li'), (string) $fat->get('GET.from'), (string) $fat->get('GET.to'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /sitelog/@p', static function (Base $fat, array $params) use ($admin, $sitelog, $render): void { $admin('logs.view'); $render('Site Log', $sitelog->CreateSiteLogHTMLList((string) $fat->get('GET.q'), (string) $fat->get('GET.e'), (string) $fat->get('GET.u'), (string) $fat->get('GET.ip'), (string) $fat->get('GET.li'), (string) $fat->get('GET.from'), (string) $fat->get('GET.to'), (int) $params['p'], (int) $fat->get('r'))); });
    $fat->route('GET /message-views/@muid', static function (Base $fat, array $params) use ($admin, $smlog, $render): void { $admin('logs.view'); $render('Message activity', $smlog->CreateMessageReadsHTMLList((string) $params['muid'], (string) $fat->get('GET.e'), 1, (int) $fat->get('r'))); });
    $fat->route('GET /message-views/@muid/@p', static function (Base $fat, array $params) use ($admin, $smlog, $render): void { $admin('logs.view'); $render('Message activity', $smlog->CreateMessageReadsHTMLList((string) $params['muid'], (string) $fat->get('GET.e'), (int) $params['p'], (int) $fat->get('r'))); });

    // Archives and contextual contact/order form.
    $fat->route('GET /archives', static function (Base $fat) use ($archive, $render): void { $render('Archives', $archive->CreateArchivesHTMLList(1, (int) $fat->get('r'))); });
    $fat->route('GET /archives/@p', static function (Base $fat, array $params) use ($archive, $render): void { $render('Archives', $archive->CreateArchivesHTMLList((int) $params['p'], (int) $fat->get('r'))); });
    $fat->route('GET /archive/@aid', static function (Base $fat, array $params) use ($archive, $render): void { $render('Archive', $archive->ShowArchive((int) $params['aid'])); });
    $fat->route('GET /archive/@aid/@token/@muid', static function (Base $fat, array $params) use ($archive, $render): void { $render('Archive', $archive->ShowArchive((int) $params['aid'], (string) $params['token'], (string) $params['muid'])); });
    $contactForm = static function (Base $fat, string $token = '', string $muid = '') use ($subscriber): string {
        $values = ['name' => '', 'email' => '', 'cell' => '', 'company' => '', 'website' => ''];
        if ($token !== '' && $subscriber->RetrieveSubscriber($token)) {
            $values = [
                'name' => trim((string) $subscriber->subscriber->s_fname . ' ' . (string) $subscriber->subscriber->s_lname),
                'email' => (string) $subscriber->subscriber->s_email,
                'cell' => (string) $subscriber->subscriber->s_phone,
                'company' => (string) $subscriber->subscriber->s_business,
                'website' => (string) $subscriber->subscriber->s_url,
            ];
        }
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<form action="{{@BaseURL}}contact-form" method="post" class="card card-body">' . Csrf::field($fat)
            . '<input type="hidden" name="suid" value="' . $e($token) . '"><input type="hidden" name="muid" value="' . $e($muid) . '">'
            . '<input type="hidden" name="realm" value="' . $e((string) $fat->get('BookingURL')) . '">'
            . '<div class="row g-3"><div class="col-md-6"><label class="form-label">Full name</label><input class="form-control" name="name" value="' . $e($values['name']) . '"></div>'
            . '<div class="col-md-6"><label class="form-label">Email</label><input class="form-control" name="email" value="' . $e($values['email']) . '"></div>'
            . '<div class="col-md-6"><label class="form-label">Cell</label><input class="form-control" name="cell" value="' . $e($values['cell']) . '"></div>'
            . '<div class="col-md-6"><label class="form-label">Company</label><input class="form-control" name="company" value="' . $e($values['company']) . '"></div>'
            . '<div class="col-md-6"><label class="form-label">Website</label><input class="form-control" name="website" value="' . $e($values['website']) . '"></div>'
            . '<div class="col-md-6"><label class="form-label">Topic / subject</label><input class="form-control" name="topic"></div>'
            . '<div class="col-12"><label class="form-label">Message</label><textarea class="form-control" name="message" rows="8"></textarea></div>'
            . '<div class="col-12"><button class="btn btn-primary" type="submit">Send request</button></div></div></form>';
    };
    $fat->route('GET /contact-form', static function (Base $fat) use ($loggedIn, $render, $contactForm): void {
        $loggedIn();
        $render('Contact', $contactForm($fat));
    });
    $fat->route('GET /contact-form/@token', static function (Base $fat, array $params) use ($subscriber, $render, $contactForm): void {
        $token = (string) $params['token'];
        $subscriber->bumpPriority($token, 12345);
        $render('Contact', $contactForm($fat, $token));
    });
    $fat->route('GET /contact-form/@token/@muid', static function (Base $fat, array $params) use ($subscriber, $render, $contactForm): void {
        $token = (string) $params['token'];
        $subscriber->bumpPriority($token, 23456);
        $render('Contact', $contactForm($fat, $token, (string) $params['muid']));
    });
    $fat->route('POST /contact-form', static function (Base $fat) use ($mailer, $subscriber, $render): void {
        Csrf::requireValid($fat);
        $token = trim((string) $fat->get('POST.suid'));
        $subscriberEmail = $token !== '' ? $subscriber->getEmail($token) : '';
        $form = [
            'name' => trim((string) $fat->get('POST.name')),
            'email' => trim((string) $fat->get('POST.email')),
            'Cell' => trim((string) $fat->get('POST.cell')),
            'Web site' => trim((string) $fat->get('POST.website')),
            'Company' => trim((string) $fat->get('POST.company')),
            'Topic' => trim((string) $fat->get('POST.topic')),
            'Comments' => trim((string) $fat->get('POST.message')),
            'Booking-Form-URL' => trim((string) $fat->get('POST.realm')),
            'SubscriberEmail' => $subscriberEmail,
            'SubscriberUUID' => $token,
            'MessageMUID' => trim((string) $fat->get('POST.muid')),
            'IPAddr' => (string) $fat->get('IP'),
            'UserAgent' => (string) $fat->get('AGENT'),
            'XFWDFOR' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        ];
        $customer = new customers($fat, $mailer);
        $render('Contact', $customer->save($form));
    });

};
