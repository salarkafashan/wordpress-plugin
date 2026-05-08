<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\controllers\ConfirmController;

(new ConfirmController())->handle();
