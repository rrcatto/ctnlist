<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Subscriber\SubscriberAdmin;
use App\Validator\EmailAddressList;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Bulk unsubscribe (BulkUnsubscribeType): leave one list, or global suppression. */
final class BulkUnsubscription
{
    public ?int $listId = null;

    #[Assert\Choice(choices: SubscriberAdmin::UNSUBSCRIBE_SCOPES, message: 'Choose what to do.')]
    public string $scope = 'list';

    #[Assert\Length(max: 255)]
    public string $reason = 'Bulk unsubscribe';

    #[Assert\NotBlank(message: 'Enter at least one email address.')]
    #[Assert\Length(max: 10000000)]
    #[EmailAddressList]
    public string $emails = '';

    #[Assert\Callback]
    public function validateList(ExecutionContextInterface $context): void
    {
        if ($this->scope === 'list' && $this->listId === null) {
            $context->buildViolation('Choose the list to remove the addresses from.')->atPath('listId')->addViolation();
        }
    }
}
