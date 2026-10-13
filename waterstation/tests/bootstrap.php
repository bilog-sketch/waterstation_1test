<?php
// Minimal bootstrap: loads business logic without sessions/HTML.
require_once __DIR__ . '/../vendor/autoload.php';
class UserError extends RuntimeException {}
if (!function_exists('audit')) {
    function audit(string $action, string $entity, $entityId = null, string $details = ''): void {}
}
require_once __DIR__ . '/../includes/logic.php';
