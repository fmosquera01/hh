<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
if (!SecureSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Token de seguridad no válido. Recargue la página e intente nuevamente.');
}
SecureSession::logout();
hh_redirect('login.php');
