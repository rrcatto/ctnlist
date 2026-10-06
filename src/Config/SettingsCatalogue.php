<?php

declare(strict_types=1);

namespace App\Config;

/**
 * The .env settings an administrator may override on the Settings page.
 * Everything else (secret keys, database and suppression-database settings,
 * URLs, paths, cookie names, the initial administrator) stays in .env only.
 *
 * Types: text, textarea, email, url, int, bool, dsn (MAILER_DSN, edited as
 * separate SMTP fields), servers (MAIL_SMTP_SERVERS_JSON). `secret` values are
 * encrypted at rest and never written into the page.
 *
 * `default` is the value the application uses when .env does not set the
 * variable (as in SiteConfig and the services reading it).
 *
 * @phpstan-type Setting array{label: string, type: string, default?: string, help?: string, min?: int, secret?: bool, optional?: bool}
 * @phpstan-type Group array{label: string, description: string, settings: array<string, Setting>}
 */
final class SettingsCatalogue
{
    /** @var array<string, Group> */
    public const GROUPS = [
        'site' => [
            'label' => 'Site identity',
            'description' => 'The list’s name, organisation and the text shown in the site footer.',
            'settings' => [
                'APP_LIST_NAME' => ['default' => 'ctnlist', 'label' => 'List name', 'type' => 'text', 'help' => 'Shown in the navigation, page titles and emails.'],
                'APP_ORGANISATION' => ['label' => 'Organisation', 'type' => 'text', 'optional' => true],
                'APP_ABOUT_US' => ['label' => 'About text', 'type' => 'textarea', 'optional' => true, 'help' => 'Shown in the footer.'],
            ],
        ],
        'contact' => [
            'label' => 'Contact details',
            'description' => 'Contact details in the footer and the links that messages can include.',
            'settings' => [
                'APP_STREET_ADDRESS' => ['label' => 'Street address', 'type' => 'text', 'optional' => true],
                'APP_TELEPHONE' => ['label' => 'Telephone', 'type' => 'text', 'optional' => true],
                'APP_WHATSAPP' => ['label' => 'WhatsApp number', 'type' => 'text', 'optional' => true],
                'APP_WHATSAPP_URL' => ['label' => 'WhatsApp link', 'type' => 'url', 'optional' => true],
                'APP_FACEBOOK_URL' => ['label' => 'Facebook page', 'type' => 'url', 'optional' => true, 'help' => 'Footer link and the {facebook} placeholder.'],
                'APP_X_URL' => ['label' => 'X (Twitter) page', 'type' => 'url', 'optional' => true, 'help' => 'The {twitter} placeholder.'],
                'APP_ADVERTISE_URL' => ['label' => 'Advertising page', 'type' => 'url', 'optional' => true, 'help' => 'The {advertise} placeholder.'],
                'APP_BOOKING_URL' => ['label' => 'Booking form link', 'type' => 'text', 'optional' => true, 'help' => 'The {booking} placeholder; may contain {BaseURL}, {suid} and {muid}.'],
                'APP_CONTACT_URL' => ['default' => '{BaseURL}contact-form/{suid}/{muid}', 'label' => 'Contact form link', 'type' => 'text', 'optional' => true, 'help' => 'The {contact} placeholder; may contain {BaseURL}, {suid} and {muid}.'],
            ],
        ],
        'sender' => [
            'label' => 'Mail sender',
            'description' => 'Who mail comes from (also used as Reply-To), the administrator copy address and the proof address.',
            'settings' => [
                'MAIL_FROM_ADDRESS' => ['label' => 'From address', 'type' => 'email'],
                'MAIL_FROM_NAME' => ['label' => 'From name', 'type' => 'text', 'optional' => true],
                'MAIL_ADMIN_ADDRESS' => ['label' => 'Administrator address', 'type' => 'email', 'optional' => true, 'help' => 'Receives copies of subscriber notifications and is shown in the footer.'],
                'MAIL_ADMIN_NAME' => ['default' => 'Administrator', 'label' => 'Administrator name', 'type' => 'text', 'optional' => true],
                'MAIL_BOUNCE_ADDRESS' => ['label' => 'Bounce address', 'type' => 'email', 'optional' => true, 'help' => 'Return path for campaign mail.'],
                'MAIL_UNSUBSCRIBE_ADDRESS' => ['label' => 'Unsubscribe address', 'type' => 'email', 'optional' => true],
                'MAIL_TEST_ADDRESS' => ['label' => 'Proof (test) address', 'type' => 'email', 'optional' => true, 'help' => 'Default recipient of proof copies; any address will do.'],
            ],
        ],
        'smtp' => [
            'label' => 'SMTP servers',
            'description' => 'The main server and the campaign failover servers, and the sending pace. Changes apply to the next email sent; a send that is already running keeps the settings it started with.',
            'settings' => [
                'MAILER_DSN' => ['label' => 'Main SMTP server', 'type' => 'dsn', 'secret' => true, 'optional' => true, 'help' => 'Used for transactional mail, and for campaigns when no failover servers are set.'],
                'MAIL_SMTP_SERVERS_JSON' => ['default' => '[]', 'label' => 'Campaign failover servers', 'type' => 'servers', 'secret' => true, 'optional' => true, 'help' => 'Campaigns use the active servers in this order, moving to the next when one fails. Without any, campaigns use the main server.'],
                'MAIL_RATE_PER_MINUTE' => ['default' => '13', 'label' => 'Messages per minute', 'type' => 'int', 'min' => 0, 'help' => '0 sends as fast as the server accepts.'],
                'MAIL_BATCH_SIZE' => ['default' => '600', 'label' => 'Batch size', 'type' => 'int', 'min' => 1, 'help' => 'Queue entries fetched per batch (main server).'],
                'MAIL_BATCH_DELAY' => ['default' => '0', 'label' => 'Pause between batches (seconds)', 'type' => 'int', 'min' => 0],
                'MAIL_BOUNCE_LIMIT' => ['default' => '2', 'label' => 'Bounce limit', 'type' => 'int', 'min' => 0],
            ],
        ],
        'contact_form' => [
            'label' => 'Contact form',
            'description' => 'Where contact-form messages go and the acknowledgement visitors receive.',
            'settings' => [
                'CONTACT_MAIL_ADDRESS' => ['label' => 'Send messages to', 'type' => 'email', 'optional' => true, 'help' => 'Defaults to the administrator address.'],
                'CONTACT_MAIL_NAME' => ['label' => 'Acknowledgement sender name', 'type' => 'text', 'optional' => true],
                'CONTACT_MAIL_SUBJECT' => ['default' => 'Your message has been received', 'label' => 'Acknowledgement subject', 'type' => 'text', 'optional' => true],
            ],
        ],
        'subscription' => [
            'label' => 'Subscription messages',
            'description' => 'The {subscription} placeholder: the normal text, or the confirmation request when a subscriber’s remaining emails reach the confirmation level.',
            'settings' => [
                'SUBSCRIPTION_MESSAGE' => ['default' => 'Manage your list subscriptions: {preferences}', 'label' => 'Normal text', 'type' => 'textarea', 'optional' => true],
                'SUBSCRIPTION_CONFIRM_MESSAGE' => ['default' => 'Confirm your list subscription: {confirm}', 'label' => 'Confirmation request', 'type' => 'textarea', 'optional' => true],
                'SUBSCRIPTION_CONFIRM_LEVEL' => ['default' => '0', 'label' => 'Confirmation level', 'type' => 'int', 'min' => 0],
                'SUBSCRIPTION_CONFIRM_AMOUNT' => ['default' => '0', 'label' => 'Emails granted on confirmation', 'type' => 'int', 'min' => 0],
            ],
        ],
        'archives' => [
            'label' => 'Archives',
            'description' => 'The public archive of sent messages.',
            'settings' => [
                'APP_ARCHIVE_ENABLED' => ['default' => 'false', 'label' => 'Public archives', 'type' => 'bool', 'help' => 'Publish sent messages on the Archives page.'],
            ],
        ],
        'signin' => [
            'label' => 'Sign-in',
            'description' => 'Email sign-in links and how long a sign-in lasts.',
            'settings' => [
                'AUTH_MAGIC_LINK_TTL' => ['default' => '1800', 'label' => 'Link lifetime (seconds)', 'type' => 'int', 'min' => 60],
                'AUTH_MAGIC_LINK_MAX_PER_EMAIL' => ['default' => '5', 'label' => 'Links per address', 'type' => 'int', 'min' => 1],
                'AUTH_MAGIC_LINK_EMAIL_WINDOW' => ['default' => '900', 'label' => '… within (seconds)', 'type' => 'int', 'min' => 60],
                'AUTH_MAGIC_LINK_MAX_PER_IP' => ['default' => '20', 'label' => 'Links per IP address', 'type' => 'int', 'min' => 1],
                'AUTH_MAGIC_LINK_IP_WINDOW' => ['default' => '3600', 'label' => '… within (seconds)', 'type' => 'int', 'min' => 60],
                'AUTH_SESSION_TTL' => ['default' => '86400', 'label' => 'Sign-in lasts (seconds)', 'type' => 'int', 'min' => 300],
            ],
        ],
    ];

    /** @return array<string, string> every overridable variable name => its group key */
    public static function names(): array
    {
        $names = [];
        foreach (self::GROUPS as $group => $definition) {
            foreach (array_keys($definition['settings']) as $name) {
                $names[$name] = $group;
            }
        }
        return $names;
    }
}
