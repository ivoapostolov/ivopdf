<?php

declare(strict_types=1);

/**
 * Development only: Composer copies this file to integrations/<name>/vendor/ivopdf/ivopdf/, so the repository root
 * is five levels up. When loaded from integrations/dev-core itself (symlinked install) it is two levels up.
 */
foreach ([dirname(__DIR__, 5), dirname(__DIR__, 2)] as $root) {
    if (is_file($root . '/autoload.php') && is_dir($root . '/src/Compat')) {
        require_once $root . '/autoload.php';
        return;
    }
}
throw new RuntimeException('ivopdf dev-core: repository root with autoload.php not found.');
