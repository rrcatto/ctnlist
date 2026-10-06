<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Repository\ListRepository;
use App\Validator\InvalidField;
use App\Validator\ListShortcode;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Creating, editing and deleting topic lists. System lists (ALL) are
 * immutable. Rejected input throws InvalidField (naming the field) or
 * \InvalidArgumentException, with a message for the administrator.
 */
final class ListManager
{
    public function __construct(private readonly ListRepository $lists)
    {
    }

    /** @throws InvalidField for a malformed or taken shortcode or name */
    public function create(string $shortcode, string $name, string $description): int
    {
        $shortcode = strtoupper(trim($shortcode));
        $name = $this->validName($name);
        if (!preg_match(ListShortcode::PATTERN, $shortcode)) {
            throw new InvalidField('shortcode', 'List shortcode must contain 3 to 6 uppercase letters/numbers.');
        }
        if ($this->lists->findByShortcode($shortcode) !== null) {
            throw new InvalidField('shortcode', 'A list with that shortcode already exists.');
        }
        $this->assertNameFree($name, null);
        try {
            return $this->lists->create($shortcode, $name, trim($description));
        } catch (UniqueConstraintViolationException $e) {
            throw self::duplicate($e);
        }
    }

    /**
     * Change a topic list's name and description; the shortcode is permanent
     * and system lists are fixed.
     *
     * @throws ListNotFound
     * @throws InvalidField for a malformed or taken name
     */
    public function update(int $listId, string $name, string $description): void
    {
        $list = $this->lists->findById($listId) ?? throw new ListNotFound($listId);
        if ($list['l_system']) {
            throw new \InvalidArgumentException('System lists cannot be changed.');
        }
        $name = $this->validName($name);
        $this->assertNameFree($name, $listId);
        try {
            $this->lists->updateCustom($listId, $name, trim($description));
        } catch (UniqueConstraintViolationException $e) {
            throw self::duplicate($e);
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

    private function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidField('name', 'List name is required (at most 100 characters).');
        }
        return $name;
    }

    private function assertNameFree(string $name, ?int $exceptId): void
    {
        $existing = $this->lists->findByName($name);
        if ($existing !== null && $existing['l_id'] !== $exceptId) {
            throw new InvalidField('name', 'A list with that name already exists.');
        }
    }

    /** A unique-constraint race (another request created the same list first). */
    private static function duplicate(UniqueConstraintViolationException $e): InvalidField
    {
        return str_contains($e->getMessage(), 'uq_lists_shortcode')
            ? new InvalidField('shortcode', 'A list with that shortcode already exists.', $e)
            : new InvalidField('name', 'A list with that name already exists.', $e);
    }
}
