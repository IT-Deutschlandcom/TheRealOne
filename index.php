<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
redirect(current_admin() === null ? '/login.php' : '/admin/dashboard.php');
