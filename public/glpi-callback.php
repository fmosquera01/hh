<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$errorCode = (string) ($_GET['error'] ?? '');
if ($errorCode !== '') {
    unset($_SESSION['glpi_oauth_state']);
    http_response_code(401);
    exit('No se pudo completar el inicio de sesión con GLPI. Puede volver a intentarlo desde HH.');
}

$code = (string) ($_GET['code'] ?? '');
$receivedState = (string) ($_GET['state'] ?? '');
$expectedState = $_SESSION['glpi_oauth_state'] ?? null;
unset($_SESSION['glpi_oauth_state']);

if ($code === '' || !is_string($expectedState) || $receivedState === '' || !hash_equals($expectedState, $receivedState)) {
    http_response_code(400);
    exit('Respuesta de autenticación inválida. Vuelva a iniciar sesión desde HH.');
}

try {
    $client = new GlpiOAuthClient();
    $tokenResponse = $client->exchangeCode($code);
    $accessToken = (string) ($tokenResponse['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('GLPI no devolvió un token de acceso.');
    }

    $user = $client->currentUser($accessToken);
    SecureSession::login($user);
    hh_redirect('index.php');
} catch (Throwable $exception) {
    error_log('[HH GLPI OAuth callback] ' . $exception->getMessage());
    http_response_code(502);
    echo 'No fue posible validar la sesión con GLPI. Verifique la configuración OAuth y vuelva a intentarlo.';
}
