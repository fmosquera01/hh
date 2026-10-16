<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;

include('../../../inc/includes.php');

Session::checkLoginUser();

Html::header(
    __('HH - Gestión de reparaciones', 'hh'),
    $_SERVER['PHP_SELF'],
    'plugins',
    Dashboard::class
);
?>
<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <h2><?= htmlspecialchars(__('Gestión de reparaciones', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        </div>
        <div class="card-body">
            <p><?= htmlspecialchars(__('HH se ejecuta dentro de GLPI y utiliza la sesión del usuario autenticado.', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <div class="alert alert-info" role="status">
                <strong><?= htmlspecialchars(__('Esqueleto inicial', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                <p><?= htmlspecialchars(__('La estructura de datos para el historial del valor por punto y las cotizaciones se crea al instalar el plugin. La pantalla de administración y los flujos de cotización se incorporarán en la siguiente etapa.', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
            <ul>
                <li><?= htmlspecialchars(__('Inicio de sesión y sesión: administrados por GLPI.', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(__('Inventario: aún no se ejecutan consultas ni se modifican elementos de GLPI.', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(__('Valor por punto: se conservará el valor aplicado en cada cotización para mantener el historial monetario.', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            </ul>
        </div>
    </div>
</div>
<?php
Html::footer();
