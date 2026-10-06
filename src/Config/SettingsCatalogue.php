<?php

declare(strict_types=1);

namespace App\Config;

/**
 * The .env settings an administrator may override on the Settings page.
 * Everything else (secret keys, database and suppression-database settings,
 * URLs, paths, cookie names, the initial administrator) stays in .env only.
 *
 * `width` lays the field out on the Settings page (full, half, third or
 * quarter of the row); `heading` starts a sub-section before the field.
 *
 * Types: text, textarea, email, url, int, bool, dsn (MAILER_DSN, edited as
 * separate SMTP fields). `secret` values are
 * encrypted at rest and never written into the page.
 *
 * `default` is the value the application uses when .env does not set the
 * variable (as in SiteConfig and the services reading it).
 *
 * @phpstan-type Setting array{label: string, type: string, width?: string, heading?: string, default?: string, help?: string, min?: int, secret?: bool, optional?: bool}
 * @phpstan-type Group array{tab: string, label: string, description: string, settings: array<string, Setting>}
 */
final class SettingsCatalogue
{
    /** @var array<string, Group> */
    public const GROUPS = [
        'site' => [
            'tab' => 'Site',
            'label' => 'Site identity',
            'description' => 'The list’s name, organisation and the text shown in the site footer.',
            'settings' => [
                'APP_LIST_NAME' => ['width' => 'half', 'default' => 'ctnlist', 'label' => 'List name', 'type' => 'text', 'help' => 'Shown in the navigation, page titles and emails.'],
                'APP_ORGANISATION' => ['width' => 'half', 'label' => 'Organisation', 'type' => 'text', 'optional' => true],
                'APP_ABOUT_US' => ['width' => 'full', 'label' => 'About text', 'type' => 'textarea', 'optional' => true, 'help' => 'Shown in the footer.'],
            ],
        ],
        'contact' => [
            'tab' => 'Contact',
            'label' => 'Contact details',
            'description' => 'Contact details in the footer and the links that messages can include.',
            'settings' => [
                'APP_STREET_ADDRESS' => ['width' => 'full', 'label' => 'Street address', 'type' => 'text', 'optional' => true],
                'APP_TELEPHONE' => ['width' => 'third', 'label' => 'Telephone', 'type' => 'text', 'optional' => true],
                'APP_WHATSAPP' => ['width' => 'third', 'label' => 'WhatsApp number', 'type' => 'text', 'optional' => true],
                'APP_WHATSAPP_URL' => ['width' => 'third', 'label' => 'WhatsApp link', 'type' => 'url', 'optional' => true],
                'APP_FACEBOOK_URL' => ['width' => 'half', 'label' => 'Facebook page', 'type' => 'url', 'optional' => true, 'help' => 'Footer link and the {facebook} placeholder.'],
                'APP_X_URL' => ['width' => 'half', 'label' => 'X (Twitter) page', 'type' => 'url', 'optional' => true, 'help' => 'The {twitter} placeholder.'],
                'APP_ADVERTISE_URL' => ['width' => 'half', 'label' => 'Advertising page', 'type' => 'url', 'optional' => true, 'help' => 'The {advertise} placeholder.'],
                'APP_BOOKING_URL' => ['width' => 'half', 'label' => 'Booking form link', 'type' => 'text', 'optional' => true, 'help' => 'The {booking} placeholder; may contain {BaseURL}, {suid} and {muid}.'],
                'APP_CONTACT_URL' => ['width' => 'half', 'default' => '{BaseURL}contact-form/{suid}/{muid}', 'label' => 'Contact form link', 'type' => 'text', 'optional' => true, 'help' => 'The {contact} placeholder; may contain {BaseURL}, {suid} and {muid}.'],
            ],
        ],
        'sender' => [
            'tab' => 'Sender',
            'label' => 'Mail sender',
            'description' => 'Who mail comes from (also used as Reply-To), the administrator copy address and the proof address.',
            'settings' => [
                'MAIL_FROM_ADDRESS' => ['width' => 'half', 'label' => 'From address', 'type' => 'email'],
                'MAIL_FROM_NAME' => ['width' => 'half', 'label' => 'From name', 'type' => 'text', 'optional' => true],
                'MAIL_ADMIN_ADDRESS' => ['width' => 'half', 'label' => 'Administrator address', 'type' => 'email', 'optional' => true, 'help' => 'Receives copies of subscriber notifications and is shown in the footer.'],
                'MAIL_ADMIN_NAME' => ['width' => 'half', 'default' => 'Administrator', 'label' => 'Administrator name', 'type' => 'text', 'optional' => true],
                'MAIL_BOUNCE_ADDRESS' => ['width' => 'half', 'label' => 'Bounce address', 'type' => 'email', 'optional' => true, 'help' => 'Return path of transactional mail (catto-mail sets its own for campaign content).'],
                'MAIL_TEST_ADDRESS' => ['width' => 'half', 'label' => 'Proof address', 'type' => 'email', 'optional' => true, 'help' => 'Default recipient of proof copies; any address will do.'],
            ],
        ],
        'smtp' => [
            'tab' => 'SMTP',
            'label' => 'Transactional mail',
            'description' => 'The SMTP server for transactional mail (sign-in links, invitations, notifications, contact acknowledgements) and its pace. Campaign content (queue sends, proofs, resends, forwards) is delivered by catto-mail, configured in .env.',
            'settings' => [
                'MAILER_DSN' => ['width' => 'full', 'label' => 'SMTP server', 'type' => 'dsn', 'secret' => true, 'optional' => true, 'help' => 'Used for transactional mail only.'],
                'MAIL_RATE_PER_MINUTE' => ['heading' => 'Sending pace', 'width' => 'quarter', 'default' => '13', 'label' => 'Messages per minute', 'type' => 'int', 'min' => 0, 'help' => '0 sends as fast as the server accepts.'],
            ],
        ],
        'contact_form' => [
            'tab' => 'Contact form',
            'label' => 'Contact form',
            'description' => 'Where contact-form messages go and the acknowledgement visitors receive.',
            'settings' => [
                'CONTACT_MAIL_ADDRESS' => ['width' => 'half', 'label' => 'Messages go to', 'type' => 'email', 'optional' => true, 'help' => 'Defaults to the administrator address.'],
                'CONTACT_MAIL_NAME' => ['width' => 'half', 'label' => 'Acknowledgement from', 'type' => 'text', 'optional' => true],
                'CONTACT_MAIL_SUBJECT' => ['width' => 'full', 'default' => 'Your message has been received', 'label' => 'Acknowledgement subject', 'type' => 'text', 'optional' => true],
                'CONTACT_RATE_LIMIT' => ['width' => 'third', 'default' => '5', 'label' => 'Messages per hour', 'type' => 'int', 'min' => 1, 'help' => 'Contact messages accepted per hour from one IP address, and for one email address.'],
            ],
        ],
        'subscription' => [
            'tab' => 'Subscriptions',
            'label' => 'Subscription messages',
            'description' => 'The {subscription} placeholder: the normal text, or the confirmation request when a subscriber’s remaining emails reach the confirmation level.',
            'settings' => [
                'SUBSCRIPTION_MESSAGE' => ['width' => 'half', 'default' => 'Manage your list subscriptions: {preferences}', 'label' => 'Normal text', 'type' => 'textarea', 'optional' => true],
                'SUBSCRIPTION_CONFIRM_MESSAGE' => ['width' => 'half', 'default' => 'Confirm your list subscription: {confirm}', 'label' => 'Confirmation request', 'type' => 'textarea', 'optional' => true],
                'SUBSCRIPTION_CONFIRM_LEVEL' => ['width' => 'half', 'default' => '0', 'label' => 'Confirmation level', 'type' => 'int', 'min' => 0],
                'SUBSCRIPTION_CONFIRM_AMOUNT' => ['width' => 'half', 'default' => '0', 'label' => 'Emails on confirmation', 'type' => 'int', 'min' => 0],
            ],
        ],
        'archives' => [
            'tab' => 'Archives',
            'label' => 'Archives',
            'description' => 'The public archive of sent messages.',
            'settings' => [
                'APP_ARCHIVE_ENABLED' => ['width' => 'full', 'default' => 'false', 'label' => 'Public archives', 'type' => 'bool', 'help' => 'Publish sent messages on the Archives page.'],
            ],
        ],
        'signin' => [
            'tab' => 'Sign-in',
            'label' => 'Sign-in',
            'description' => 'Email sign-in links, how long a sign-in lasts, and how often subscribers may forward or resend.',
            'settings' => [
                'AUTH_MAGIC_LINK_TTL' => ['width' => 'third', 'default' => '1800', 'label' => 'Link lifetime (s)', 'type' => 'int', 'min' => 60, 'help' => 'How long a sign-in link stays valid.'],
                'AUTH_MAGIC_LINK_MAX_PER_EMAIL' => ['width' => 'third', 'default' => '5', 'label' => 'Links per address', 'type' => 'int', 'min' => 1, 'help' => 'Sign-in links one address may request…'],
                'AUTH_MAGIC_LINK_EMAIL_WINDOW' => ['width' => 'third', 'default' => '900', 'label' => 'Address window (s)', 'type' => 'int', 'min' => 60, 'help' => '… within this many seconds.'],
                'AUTH_SESSION_TTL' => ['width' => 'third', 'default' => '86400', 'label' => 'Session length (s)', 'type' => 'int', 'min' => 300, 'help' => 'How long a sign-in lasts.'],
                'AUTH_MAGIC_LINK_MAX_PER_IP' => ['width' => 'third', 'default' => '20', 'label' => 'Links per IP address', 'type' => 'int', 'min' => 1, 'help' => 'Sign-in links one IP address may request…'],
                'AUTH_MAGIC_LINK_IP_WINDOW' => ['width' => 'third', 'default' => '3600', 'label' => 'IP window (s)', 'type' => 'int', 'min' => 60, 'help' => '… within this many seconds.'],
                'FORWARD_RATE_LIMIT' => ['width' => 'third', 'default' => '10', 'label' => 'Forwards per hour', 'type' => 'int', 'min' => 1, 'help' => 'Forwards and resends one subscriber may make per hour (administrators are not limited).'],
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
