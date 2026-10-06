<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Campaign\ForwardService;
use App\Validator\EmailAddressList;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Forwarding a message or an archive to friends (ForwardType). The
 * forwarder's token and the message come as hidden fields; who may forward
 * is checked by the controller.
 */
final class ForwardRequest
{
    #[Assert\NotBlank(message: 'Enter at least one email address.')]
    #[Assert\Length(max: 5000)]
    #[EmailAddressList(max: ForwardService::MAX_RECIPIENTS)]
    public string $emails = '';

    public ?string $token = null;

    public ?string $muid = null;

    /** The archive being forwarded (archive pages only). */
    public ?string $archiveId = null;
}
