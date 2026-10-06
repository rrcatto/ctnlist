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
 * success a Send Log row plus the smlog sent mark (invariants). A proof has no
 * subscriber: no per-subscriber headers and no smlog row (it must not count as
 * that recipient's campaign delivery), only the PROOF Send Log row.
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
        $uuid = $delivery->subscriberUuid;

        $email = (new Email())
            ->subject($delivery->subject)
            ->html($delivery->html)
            ->text($delivery->text)
            ->replyTo(self::address($replyTo))
            ->from(self::address($replyTo, $delivery->fromName))
            ->to(self::address($delivery->email, $delivery->name));
        if ($uuid !== null) {
            $email->sender(self::address('info+' . $uuid . '@' . $this->site->domain));
        }
        if (trim($this->site->bounceAddress) !== '') {
            $email->returnPath(self::address($this->site->bounceAddress));
        }

        $headers = $email->getHeaders();
        $headers->addTextHeader('List-Id', $this->site->listName . ' <' . $baseUrl . '>');
        if ($list !== '' && $uuid !== null) {
            $headers->addTextHeader('List-Unsubscribe', '<' . $baseUrl . 'unsubscribe/' . $uuid . '/' . $list . '/' . $delivery->muid . '>');
        }
        $subscribeUrl = $baseUrl . 'subscribe?m=' . rawurlencode($delivery->muid) . ($list !== '' ? '&l=' . rawurlencode($list) : '');
        $headers->addTextHeader('List-Subscribe', '<' . $subscribeUrl . '>');
        $headers->addTextHeader('List-Post', 'NO');
        $headers->addTextHeader('List-Owner', '<mailto:' . $this->site->adminEmail . '> (' . $this->site->adminName . ')');
        $headers->addTextHeader('List-Archive', '<' . $baseUrl . 'archives>');
        if ($uuid !== null) {
            $headers->addTextHeader('X-ctnlist-suid', $uuid);
        }
        $headers->addTextHeader('X-ctnlist-muid', $delivery->muid);

        if (!$connection->send($email)) {
            return false;
        }
        $this->sendLog->record($delivery->muid, $type, $delivery->email, $list, $delivery->subject, $uuid);
        if ($uuid !== null) {
            $this->messageLog->markSent($uuid, $delivery->muid, $list);
        }
        return true;
    }

    private static function address(string $email, string $name = ''): Address
    {
        return trim($name) === '' ? new Address(trim($email)) : new Address(trim($email), trim($name));
    }
}
