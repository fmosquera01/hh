<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Security/Session.php';
require_once __DIR__ . '/Auth/LdapAuthenticator.php';

SecureSession::start();

function hh_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hh_redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function hh_require_auth(): void
{
    if (!SecureSession::authenticated()) {
        hh_redirect('login.php');
    }
}
