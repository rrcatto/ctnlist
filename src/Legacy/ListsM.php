<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;

class ListsM extends \DB\SQL\Mapper
{
    public const ALL_SHORTCODE = 'ALL';

    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'lists');
    }

    public function readById(int $id): bool
    {
        $this->load(['l_id = :id', ':id' => $id]);
        return $this->valid();
    }

    public function readByShortcode(string $shortcode): bool
    {
        $this->load(['l_shortcode = :shortcode', ':shortcode' => strtoupper(trim($shortcode))]);
        return $this->valid();
    }
}