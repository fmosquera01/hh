<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;

include('../../../inc/includes.php');

Session::checkLoginUser();

$itemType = isset($_GET['itemtype']) && is_string($_GET['itemtype'])
    ? $_GET['itemtype']
    : '';
$itemId = filter_input(INPUT_GET, 'items_id', FILTER_VALIDATE_INT);
$itemId = $itemId !== false && $itemId !== null && $itemId > 0 ? $itemId : 0;

$item = null;
if ($itemType !== '' && $itemId > 0 && is_a($itemType, CommonDBTM::class, true)) {
    $candidate = new $itemType();
    if ($candidate->getFromDB($itemId)) {
        $item = $candidate;
    }
}

Html::header(
    __('Gestión de reparaciones', 'hh'),
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
            <?php if ($item instanceof CommonDBTM): ?>
                <h3><?= htmlspecialchars(__('Activo seleccionado', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                <dl class="row">
                    <dt class="col-sm-3"><?= htmlspecialchars(__('Tipo de activo', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd class="col-sm-9"><?= htmlspecialchars($itemType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                    <dt class="col-sm-3"><?= htmlspecialchars(__('ID en GLPI', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd class="col-sm-9"><?= (int) $itemId ?></dd>
                    <dt class="col-sm-3"><?= htmlspecialchars(__('Nombre', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd class="col-sm-9"><?= htmlspecialchars((string) ($item->fields['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </dl>
            <?php endif; ?>
            <div class="alert alert-info" role="status">
                <strong><?= htmlspecialchars(__('Etapa inicial', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                <p><?= htmlspecialchars(__('La pestaña nativa identifica automáticamente el activo. La lista y creación de cotizaciones, piezas, aprobaciones e historial todavía no están implementadas.', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
            <ul>
                <li><?= htmlspecialchars(__('Inicio de sesión y permisos de sesión: administrados por GLPI.', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(__('Los datos propios de HH se guardan en tablas del plugin, sin alterar las tablas nativas de GLPI.', 'hh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            </ul>
        </div>
    </div>
</div>
<?php
Html::footer();
