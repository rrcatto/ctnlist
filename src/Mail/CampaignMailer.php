<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\SiteConfig;
use App\Log\MessageLog;
use App\Log\SendLog;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The campaign mail path, used for every delivery of campaign content
 * (queue, proof, resend, forward): v5 envelope and List-* headers, and on
 * success a Send Log row plus the smlog sent mark (invariants).
 */
final class CampaignMailer
{
    public function __construct(
        private readonly SiteConfig $site,
        private readonly SendLog $sendLog,
        private readonly MessageLog $messageLog,
    ) {
    }

    /** @param string $type MESSAGE, PROOF, RESEND or FORWARD-MESSAGE */
    public function send(MailConnection $connection, CampaignDelivery $delivery, string $type = 'MESSAGE'): bool
    {
        $type = strtoupper(trim($type)) !== '' ? strtoupper(trim($type)) : 'MESSAGE';
        $list = strtoupper(trim($delivery->listShortcode));
        $replyTo = trim($delivery->fromAddress) !== '' ? trim($delivery->fromAddress) : $this->site->fromAddress;
        $baseUrl = $this->site->baseUrl;

        $email = (new Email())
            ->subject($delivery->subject)
            ->html($delivery->html)
            ->text($delivery->text)
            ->replyTo(self::address($replyTo))
            ->from(self::address($replyTo, $delivery->fromName))
            ->sender(self::address('info+' . $delivery->subscriberUuid . '@' . $this->site->domain))
            ->to(self::address($delivery->email, $delivery->name));
        if (trim($this->site->bounceAddress) !== '') {
            $email->returnPath(self::address($this->site->bounceAddress));
        }

        $headers = $email->getHeaders();
        $headers->addTextHeader('List-Id', $this->site->listName . ' <' . $baseUrl . '>');
        if ($list !== '') {
            $headers->addTextHeader('List-Unsubscribe', '<' . $baseUrl . 'unsubscribe/' . $delivery->subscriberUuid . '/' . $list . '/' . $delivery->muid . '>');
        }
        $subscribeUrl = $baseUrl . 'subscribe?m=' . rawurlencode($delivery->muid) . ($list !== '' ? '&l=' . rawurlencode($list) : '');
        $headers->addTextHeader('List-Subscribe', '<' . $subscribeUrl . '>');
        $headers->addTextHeader('List-Post', 'NO');
        $headers->addTextHeader('List-Owner', '<mailto:' . $this->site->adminEmail . '> (' . $this->site->adminName . ')');
        $headers->addTextHeader('List-Archive', '<' . $baseUrl . 'archives>');
        $headers->addTextHeader('X-ctnlist-suid', $delivery->subscriberUuid);
        $headers->addTextHeader('X-ctnlist-muid', $delivery->muid);

        if (!$connection->send($email)) {
            return false;
        }
        $this->sendLog->record($delivery->muid, $type, $delivery->email, $list, $delivery->subject, $delivery->subscriberUuid);
        $this->messageLog->markSent($delivery->subscriberUuid, $delivery->muid, $list);
        return true;
    }

    private static function address(string $email, string $name = ''): Address
    {
        return trim($name) === '' ? new Address(trim($email)) : new Address(trim($email), trim($name));
    }
}
