<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Repository\ListRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Creating and deleting topic lists. System lists (ALL) are immutable.
 * Validation failures throw \InvalidArgumentException with a message for the
 * administrator.
 */
final class ListManager
{
    public function __construct(private readonly ListRepository $lists)
    {
    }

    public function create(string $shortcode, string $name, string $description): int
    {
        $shortcode = strtoupper(trim($shortcode));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9]{3,6}$/', $shortcode)) {
            throw new \InvalidArgumentException('List shortcode must contain 3 to 6 uppercase letters/numbers.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('List name is required (at most 100 characters).');
        }
        try {
            return $this->lists->create($shortcode, $name, trim($description));
        } catch (UniqueConstraintViolationException) {
            throw new \InvalidArgumentException('A list with that shortcode or name already exists.');
        }
    }

    /** @throws ListNotFound */
    public function delete(int $listId): void
    {
        $list = $this->lists->findById($listId) ?? throw new ListNotFound($listId);
        if ($list['l_system']) {
            throw new \InvalidArgumentException('System lists cannot be deleted.');
        }
        try {
            $this->lists->deleteCustom($listId);
        } catch (ForeignKeyConstraintViolationException) {
            throw new \InvalidArgumentException('The list cannot be deleted while messages or other records refer to it.');
        }
    }
}
