<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Config\SiteConfig;
use App\Log\MessageActivity;
use App\Log\MessageLog;
use App\Mail\TransactionalMailer;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The v5 contact/order form. Every submission is appended to the contact
 * log file (CONTACT_LOG_FILE) and mailed twice: the organisation's copy, to
 * the contact address and the administrator, names the subscriber and the
 * message of the link that was used; the acknowledgement to the submitter
 * holds only what they wrote. Anyone holding a subscriber's link can submit
 * the form, so nothing sent to the submitter may identify that subscriber.
 * A submission the organisation received is counted as a booking for the
 * message and raises the subscriber's priority.
 */
final class ContactService
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly SubscriberRepository $subscribers,
        private readonly MessageRepository $messages,
        private readonly MessageLog $messageLog,
        private readonly Engagement $engagement,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $form submitted fields (name, email, cell, website, company, topic, message)
     * @param array{subscriber_uuid: string, muid: string, ip: string, user_agent: string, forwarded_for: string} $context
     * @return string the message shown to the submitter
     */
    public function submit(array $form, array $context): string
    {
        $uuid = trim($context['subscriber_uuid']);
        $muid = trim($context['muid']);
        $written = [
            'name' => trim($form['name'] ?? ''),
            'email' => trim($form['email'] ?? ''),
            'Cell' => trim($form['cell'] ?? ''),
            'Web site' => trim($form['website'] ?? ''),
            'Company' => trim($form['company'] ?? ''),
            'Topic' => trim($form['topic'] ?? ''),
            'Comments' => trim($form['message'] ?? ''),
        ];
        // For the organisation only: whose link was used, for which message, and from where.
        $internal = [
            'SubscriberEmail' => $uuid !== '' ? (string) ($this->subscribers->findIdentityByUuid($uuid)['s_email'] ?? '') : '',
            'SubscriberUUID' => $uuid,
            'MessageSubject' => $muid !== '' ? (string) ($this->messages->findByMuid($muid)['m_subject'] ?? '') : '',
            'MessageMUID' => $muid,
            'IPAddr' => $context['ip'],
            'UserAgent' => $context['user_agent'],
            'XFWDFOR' => $context['forwarded_for'],
        ];
        [$html, $text] = self::lines($written + $internal);
        $this->log("~ ~ ~\n\n" . $this->clock->now()->format('Y-m-d H:i:s') . "\n" . $text);

        // Without a link, someone writing from a subscriber's address is that subscriber.
        $subscriber = $uuid !== '' ? $uuid : (string) ($this->subscribers->findIdentityByEmail($written['email'])['s_uuid'] ?? '');
        if (!$this->mailer->sendContactNotice($written['email'], $written['name'], $html, $text, $subscriber, $muid)) {
            $this->log("EMAIL-FAIL\n");
            return 'There was a transmission problem. Please try re-submitting or contact the Administrator at ' . $this->site->adminEmail . '.';
        }
        $this->messageLog->record($subscriber, $muid, MessageActivity::Booking);
        if ($subscriber !== '') {
            $this->engagement->bump($subscriber, 100000);
        }

        [$html, $text] = self::lines($written);
        $acknowledged = $this->mailer->sendContactAcknowledgement($written['email'], $written['name'], $html, $text, $subscriber, $muid);
        $this->log($acknowledged ? "EMAIL-OK\n" : "EMAIL-OK\nACKNOWLEDGEMENT-FAIL\n");
        return 'Thank you for your submission! Email successfully sent. You will be contacted shortly.';
    }

    /**
     * @param array<string, string> $fields
     * @return array{string, string} the HTML and text parts, a labelled paragraph for each field
     */
    private static function lines(array $fields): array
    {
        $html = '';
        $text = '';
        foreach ($fields as $key => $value) {
            $html .= '<p><b>' . htmlspecialchars($key) . ':</b><br />' . nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
            $text .= "{$key}:\n{$value}\n\n";
        }
        return [$html, $text];
    }

    private function log(string $entry): void
    {
        $file = $this->site->contactLogFile;
        if (@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) === false) {
            $this->logger->error('Contact form submission could not be written to {file}.', ['file' => $file]);
        }
    }
}
