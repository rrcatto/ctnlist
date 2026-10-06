<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** The "queue multiple messages" form: up to four messages in rotation, up to a total number of emails. */
final class QueueRotation
{
    public const SLOTS = 4;

    public ?string $message1 = null;
    public ?string $message2 = null;
    public ?string $message3 = null;
    public ?string $message4 = null;

    #[Assert\NotNull(message: 'Enter the number of emails to queue.')]
    #[Assert\Range(min: 1, max: 10000000, notInRangeMessage: 'Queue between {{ min }} and {{ max }} emails.')]
    public ?int $volume = null;

    public function __construct(int $volume)
    {
        $this->volume = $volume;
    }

    /** @return list<string> the selected message MUIDs, in slot order */
    public function muids(): array
    {
        return array_values(array_filter(
            [$this->message1, $this->message2, $this->message3, $this->message4],
            static fn(?string $muid): bool => $muid !== null && $muid !== ''
        ));
    }

    #[Assert\Callback]
    public function validateSelection(ExecutionContextInterface $context): void
    {
        if ($this->muids() === []) {
            $context->buildViolation('Select at least one message before queueing.')->atPath('message1')->addViolation();
        }
    }
}
