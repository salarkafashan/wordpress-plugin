<?php

declare(strict_types=1);

namespace App\models;

use App\database\Database;

abstract class BaseModel
{
    /** @var object */
    protected $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }
}
