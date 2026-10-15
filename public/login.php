<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (SecureSession::authenticated()) {
    hh_redirect('index.php');
}

try {
    $client = new GlpiOAuthClient();
    $state = bin2hex(random_bytes(32));
    $_SESSION['glpi_oauth_state'] = $state;
    header('Location: ' . $client->authorizationUrl($state), true, 302);
    exit;
} catch (Throwable $exception) {
    error_log('[HH GLPI OAuth start] ' . $exception->getMessage());
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Autenticación no disponible | HH</title>
        <link rel="stylesheet" href="assets/app.css">
    </head>
    <body class="login-page">
    <main class="login-card">
        <div class="login-logo">HH</div>
        <h1>Autenticación no configurada</h1>
        <p class="muted">HH utiliza el inicio de sesión de GLPI. Contacte al administrador para completar la configuración OAuth.</p>
        <p><a class="button button-primary button-full" href="login.php">Reintentar</a></p>
    </main>
    </body>
    </html>
    <?php
}
