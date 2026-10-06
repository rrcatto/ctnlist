<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Non-secret, per-installation site settings from the installation's .env.
 * Available to Twig as `site`.
 */
final class SiteConfig
{
    public const VERSION = '6.0.2';

    /**
     * @param list<array<string, mixed>> $smtpServers MAIL_SMTP_SERVERS_JSON entries
     * @param list<array<string, mixed>> $syncDatabases SYNC_DATABASES_JSON entries
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $listName,
        public readonly string $domain,
        public readonly string $organisation,
        public readonly string $telephone,
        public readonly string $whatsApp,
        public readonly string $whatsAppUrl,
        public readonly string $streetAddress,
        public readonly string $aboutUs,
        public readonly string $advertiseUrl,
        public readonly string $facebookUrl,
        public readonly string $xUrl,
        public readonly string $bookingUrl,
        public readonly string $contactUrl,
        public readonly string $instanceId,
        public readonly string $adminEmail,
        public readonly string $adminName,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $bounceAddress,
        public readonly string $unsubscribeAddress,
        public readonly string $testEmail,
        public readonly int $bounceLimit,
        public readonly int $emailsPerMinute,
        public readonly array $smtpServers,
        public readonly array $syncDatabases,
        public readonly string $contactEmail,
        public readonly string $contactName,
        public readonly string $contactSubject,
        public readonly string $contactLogFile,
        public readonly string $subscriptionMessage,
        public readonly string $subscriptionConfirmMessage,
        public readonly int $subscriptionConfirmLevel,
        public readonly int $subscriptionConfirmAmount,
        public readonly bool $archiveEnabled,
    ) {
    }

    /**
     * Build from environment variables; empty values count as unset.
     *
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(array $env, string $instanceDir): self
    {
        $string = static function (string $name, string $default = '') use ($env): string {
            $value = $env[$name] ?? null;
            return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
        };
        $int = static fn(string $name, int $default): int => (int) $string($name, (string) $default);
        $list = static function (string $name) use ($string): array {
            $decoded = json_decode($string($name, '[]'), true);
            return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        };

        $adminEmail = $string('MAIL_ADMIN_ADDRESS', $string('APP_ADMIN_EMAIL'));
        return new self(
            baseUrl: rtrim($string('APP_BASE_URL', 'http://localhost/'), '/') . '/',
            listName: $string('APP_LIST_NAME', 'ctnlist'),
            domain: $string('APP_DOMAIN', 'localhost'),
            organisation: $string('APP_ORGANISATION'),
            telephone: $string('APP_TELEPHONE'),
            whatsApp: $string('APP_WHATSAPP'),
            whatsAppUrl: $string('APP_WHATSAPP_URL'),
            streetAddress: $string('APP_STREET_ADDRESS'),
            aboutUs: $string('APP_ABOUT_US'),
            advertiseUrl: $string('APP_ADVERTISE_URL'),
            facebookUrl: $string('APP_FACEBOOK_URL'),
            xUrl: $string('APP_X_URL'),
            bookingUrl: $string('APP_BOOKING_URL'),
            contactUrl: $string('APP_CONTACT_URL', '{BaseURL}contact-form/{suid}/{muid}'),
            instanceId: $string('APP_INSTANCE_ID'),
            adminEmail: $adminEmail,
            adminName: $string('MAIL_ADMIN_NAME', 'Administrator'),
            fromAddress: $string('MAIL_FROM_ADDRESS'),
            fromName: $string('MAIL_FROM_NAME'),
            bounceAddress: $string('MAIL_BOUNCE_ADDRESS'),
            unsubscribeAddress: $string('MAIL_UNSUBSCRIBE_ADDRESS'),
            testEmail: $string('MAIL_TEST_ADDRESS', $string('APP_ADMIN_EMAIL')),
            bounceLimit: $int('MAIL_BOUNCE_LIMIT', 2),
            emailsPerMinute: $int('MAIL_RATE_PER_MINUTE', 13),
            smtpServers: $list('MAIL_SMTP_SERVERS_JSON'),
            syncDatabases: $list('SYNC_DATABASES_JSON'),
            contactEmail: $string('CONTACT_MAIL_ADDRESS', $adminEmail),
            contactName: $string('CONTACT_MAIL_NAME', $string('APP_ORGANISATION', 'ctnlist')),
            contactSubject: $string('CONTACT_MAIL_SUBJECT', 'Your message has been received'),
            contactLogFile: $string('CONTACT_LOG_FILE', rtrim($instanceDir, '/') . '/logs/contact.log'),
            subscriptionMessage: $string('SUBSCRIPTION_MESSAGE', 'Manage your list subscriptions: {preferences}'),
            subscriptionConfirmMessage: $string('SUBSCRIPTION_CONFIRM_MESSAGE', 'Confirm your list subscription: {confirm}'),
            subscriptionConfirmLevel: $int('SUBSCRIPTION_CONFIRM_LEVEL', 0),
            subscriptionConfirmAmount: $int('SUBSCRIPTION_CONFIRM_AMOUNT', 0),
            archiveEnabled: filter_var($string('APP_ARCHIVE_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
        );
    }
}
