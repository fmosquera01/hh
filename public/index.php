<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
hh_require_auth();
$user = SecureSession::user() ?? [];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HH | Gestión de reparaciones</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php"><span class="brand-mark">HH</span><span>Gestión de reparaciones</span></a>
    <div class="user-area">
        <span><?= hh_escape((string) ($user['display_name'] ?? $user['username'] ?? 'Usuario')) ?></span>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= hh_escape(SecureSession::csrfToken()) ?>">
            <button class="button button-quiet" type="submit">Cerrar sesión</button>
        </form>
    </div>
</header>
<main class="container">
    <section class="welcome">
        <p class="eyebrow">MESA DE SERVICIO · HARDWARE</p>
        <h1>Gestión de reparaciones</h1>
        <p>Base inicial de HH para cotizaciones, historial de reparación y reutilización de repuestos.</p>
    </section>
    <section class="status-grid">
        <article class="panel">
            <div class="panel-icon">01</div>
            <h2>Equipos</h2>
            <p>La consulta de inventario GLPI 11 está pendiente de definir con el esquema real. Aún no se ejecutan consultas de inventario.</p>
            <span class="status">Integración pendiente</span>
        </article>
        <article class="panel">
            <div class="panel-icon">02</div>
            <h2>Cotizaciones</h2>
            <p>La siguiente etapa incorporará el detalle de repuestos, puntos, valor monetario aplicado y estados de aprobación.</p>
            <span class="status">Módulo pendiente</span>
        </article>
        <article class="panel">
            <div class="panel-icon">03</div>
            <h2>Valor del punto</h2>
            <p>Se mantendrá un historial de valores por vigencia. Cada cotización conservará una copia del valor usado para no alterar precios históricos.</p>
            <span class="status">Modelo de datos definido</span>
        </article>
    </section>
    <section class="notice">
        <strong>Conexión segura</strong>
        <p>Sesión autenticada con Active Directory/LDAP. La aplicación todavía no tiene acceso de lectura a GLPI ni realiza escrituras en GLPI.</p>
    </section>
</main>
<footer class="footer">HH · Aplicación interna · <?= date('Y') ?></footer>
</body>
</html>
