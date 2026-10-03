<?php

declare(strict_types=1);

class RolesM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'roles');
    }

    public function readByKey(string $key): bool
    {
        $this->load(['r_key = :key', ':key' => trim($key)]);
        return $this->valid();
    }
}