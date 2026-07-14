<?php

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }

    return false;
}

function demeter_require_web_login_unless_trusted(): void
{
    if (is_trusted_requester()) {
        return;
    }

    require __DIR__ . '/../login/lib.php';

    $allowedUsersList = isset($allowedUsers) && is_array($allowedUsers) ? $allowedUsers : [];

    if (
        !array_any($allowedUsersList, function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        require __DIR__ . '/../login/403.php';
        die();
    }
}

if (!defined('DEMETER_SKIP_LOGINCHECK_AUTO')) {
    demeter_require_web_login_unless_trusted();
}
