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

function demeter_is_allowed_logged_in_user(): bool
{
    $allowedUsersList = isset($GLOBALS['allowedUsers']) && is_array($GLOBALS['allowedUsers'])
        ? $GLOBALS['allowedUsers']
        : (isset($allowedUsers) && is_array($allowedUsers) ? $allowedUsers : []);

    if ($allowedUsersList === []) {
        return false;
    }

    $sessionEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($sessionEmail === '') {
        return false;
    }

    foreach ($allowedUsersList as $email) {
        if (strtolower(trim((string) $email)) === $sessionEmail) {
            return true;
        }
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

    if (!demeter_is_allowed_logged_in_user()) {
        require __DIR__ . '/../login/403.php';
        die();
    }
}

if (!defined('DEMETER_SKIP_LOGINCHECK_AUTO')) {
    demeter_require_web_login_unless_trusted();
}
