<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
if (SecureSession::authenticated()) {
    hh_redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SecureSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'La sesión del formulario expiró. Recargue la página e intente nuevamente.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        try {
            $authenticator = new LdapAuthenticator();
            $user = $authenticator->authenticate($username, $password);
            if ($user !== null) {
                SecureSession::login($user);
                hh_redirect('index.php');
            }
            $error = 'No se pudo iniciar sesión. Verifique las credenciales e intente nuevamente.';
        } catch (Throwable $exception) {
            error_log('[HH LDAP] ' . $exception->getMessage());
            $error = Config::get('HH_ENV', 'production') === 'development'
                ? 'Error de configuración LDAP: ' . $exception->getMessage()
                : 'El servicio de autenticación no está disponible. Contacte a soporte IT.';
        } finally {
            $password = '';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión | HH</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
<main class="login-card">
    <div class="login-logo">HH</div>
    <p class="eyebrow">MESA DE SERVICIO · HARDWARE</p>
    <h1>Iniciar sesión</h1>
    <p class="muted">Utilice sus credenciales corporativas de Active Directory.</p>
    <?php if ($error !== ''): ?>
        <div class="alert" role="alert"><?= hh_escape($error) ?></div>
    <?php endif; ?>
    <form method="post" action="login.php" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= hh_escape(SecureSession::csrfToken()) ?>">
        <label for="username">Usuario corporativo</label>
        <input id="username" name="username" type="text" autocomplete="username" required maxlength="128" autofocus>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button class="button button-primary button-full" type="submit">Ingresar</button>
    </form>
    <p class="login-footnote">La contraseña se valida contra LDAP y no se almacena en HH.</p>
</main>
</body>
</html>
