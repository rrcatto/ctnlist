<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Config\SiteConfig;
use App\Mail\TransactionalMailer;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The v5 contact/order form: every submission is appended to the contact
 * log file (CONTACT_LOG_FILE) and acknowledged by mail to the submitter,
 * blind-copied to the administrator and the contact address.
 */
final class ContactService
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly SubscriberRepository $subscribers,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $form submitted fields (name, email, cell, website, company, topic, message, realm)
     * @param array{subscriber_uuid: string, muid: string, ip: string, user_agent: string, forwarded_for: string} $context
     * @return string the message shown to the submitter
     */
    public function submit(array $form, array $context): string
    {
        $uuid = trim($context['subscriber_uuid']);
        $fields = [
            'name' => trim($form['name'] ?? ''),
            'email' => trim($form['email'] ?? ''),
            'Cell' => trim($form['cell'] ?? ''),
            'Web site' => trim($form['website'] ?? ''),
            'Company' => trim($form['company'] ?? ''),
            'Topic' => trim($form['topic'] ?? ''),
            'Comments' => trim($form['message'] ?? ''),
            'Booking-Form-URL' => trim($form['realm'] ?? ''),
            'SubscriberEmail' => $uuid !== '' ? (string) ($this->subscribers->findIdentityByUuid($uuid)['s_email'] ?? '') : '',
            'SubscriberUUID' => $uuid,
            'MessageMUID' => trim($context['muid']),
            'IPAddr' => $context['ip'],
            'UserAgent' => $context['user_agent'],
            'XFWDFOR' => $context['forwarded_for'],
        ];

        $text = '';
        $html = '';
        foreach ($fields as $key => $value) {
            $text .= "{$key}:\n{$value}\n\n";
            $html .= '<p><b>' . htmlspecialchars($key) . ':</b><br />' . nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }
        $this->log("~ ~ ~\n\n" . $this->clock->now()->format('Y-m-d H:i:s') . "\n" . $text);

        $recipients = $this->mailer->sendContactAcknowledgement($fields['email'], $fields['name'], $html, $text, $uuid, $fields['MessageMUID']);
        // The submitter plus at least one internal copy, as in v5.
        if ($recipients >= 2) {
            $this->log("EMAIL-OK\n");
            return 'Thank you for your submission! Email successfully sent. You will be contacted shortly.';
        }
        $this->log("EMAIL-FAIL\n");
        return 'There was a transmission problem. Please try re-submitting or contact the Administrator at ' . $this->site->adminEmail . '.';
    }

    private function log(string $entry): void
    {
        $file = $this->site->contactLogFile;
        if (@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) === false) {
            $this->logger->error('Contact form submission could not be written to {file}.', ['file' => $file]);
        }
    }
}
