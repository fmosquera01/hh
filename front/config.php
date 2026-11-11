<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;
use GlpiPlugin\Hh\RepairWorkflow;
use GlpiPlugin\Hh\Rights;

include('../../../inc/includes.php');

global $DB;

Session::checkLoginUser();
Rights::require(Rights::CATALOG);

$message = '';
$error = '';
$current = RepairWorkflow::getConfig();
$repairRequestTypeId = 0;
foreach ($DB->request([
    'SELECT' => ['id'],
    'FROM' => 'glpi_requesttypes',
    'WHERE' => ['name' => 'FormularioReparacion'],
    'LIMIT' => 1,
]) as $requestType) {
    $repairRequestTypeId = (int) $requestType['id'];
}
if ((int) ($current['requesttypes_id'] ?? 0) < 1 && $repairRequestTypeId > 0) {
    $current['requesttypes_id'] = $repairRequestTypeId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestTypeId = filter_var($_POST['requesttypes_id'] ?? 0, FILTER_VALIDATE_INT);
    $enabled = isset($_POST['enabled']);
    $selectedOrigin = '';
    if ($requestTypeId !== false && $requestTypeId > 0) {
        foreach ($DB->request([
            'SELECT' => ['name'],
            'FROM' => 'glpi_requesttypes',
            'WHERE' => ['id' => (int) $requestTypeId],
            'LIMIT' => 1,
        ]) as $requestType) {
            $selectedOrigin = trim((string) ($requestType['name'] ?? ''));
        }
    }
    if ($requestTypeId === false || $requestTypeId < 1 || mb_strtolower($selectedOrigin) !== 'formularioreparacion') {
        $error = 'Seleccione exactamente el origen de solicitud FormularioReparacion en GLPI.';
    } elseif (RepairWorkflow::findStateId(RepairWorkflow::STATUS_SYSTEMS) < 1
        || RepairWorkflow::findStateId(RepairWorkflow::STATUS_SUPPLIER) < 1
        || RepairWorkflow::findStateId(RepairWorkflow::STATUS_FREE) < 1) {
        $error = 'No se encontraron los estados REPARACIÓN Sistemas, REPARACIÓN Proveedor y/o LIBRE en los estados de GLPI.';
    } else {
        RepairWorkflow::saveConfig((int) $requestTypeId, $enabled);
        $current = RepairWorkflow::getConfig();
        $message = 'Configuración guardada. La recepción se procesará en tiempo real al asociar el activo al ticket.';
    }
}

Html::header(__('Configuración del flujo de reparación', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
?>
<div class="container-fluid">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Configuración del flujo general de reparación</h2></div>
    <div class="card-body">
      <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <div class="alert alert-warning">
        La automatización se ejecutará únicamente cuando el ticket tenga el origen de solicitud configurado como FormularioReparacion. Los tickets con otros orígenes no cambiarán el estado del activo.
      </div>
      <form method="post">
        <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-3">
          <label class="form-label">Origen de la solicitud utilizado por el formulario de recepción</label>
          <?php Dropdown::show('RequestType', ['name' => 'requesttypes_id', 'value' => (int) ($current['requesttypes_id'] ?? 0), 'display_emptychoice' => true]); ?>
          <div class="form-hint">Debe existir un origen llamado exactamente FormularioReparacion en Configuración > Desplegables > Origen de la solicitud. La configuración no permite seleccionar otro origen.</div>
        </div>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?= (int) ($current['enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
          <label class="form-check-label" for="enabled">Activar cambio automático e inmediato a REPARACIÓN Sistemas</label>
        </div>
        <div class="mb-3">
          <strong>Estados requeridos:</strong>
          <ul>
            <li>REPARACIÓN Sistemas: <?= RepairWorkflow::findStateId(RepairWorkflow::STATUS_SYSTEMS) > 0 ? '<span class="text-success">Encontrado</span>' : '<span class="text-danger">No encontrado</span>' ?></li>
            <li>REPARACIÓN Proveedor: <?= RepairWorkflow::findStateId(RepairWorkflow::STATUS_SUPPLIER) > 0 ? '<span class="text-success">Encontrado</span>' : '<span class="text-danger">No encontrado</span>' ?></li>
            <li>LIBRE: <?= RepairWorkflow::findStateId(RepairWorkflow::STATUS_FREE) > 0 ? '<span class="text-success">Encontrado</span>' : '<span class="text-danger">No encontrado</span>' ?></li>
            <li>DAÑADO se utiliza para los indicadores del dashboard.</li>
          </ul>
        </div>
        <button class="btn btn-primary" type="submit">Guardar configuración</button>
        <a class="btn btn-secondary" href="/plugins/hh/front/index.php">Volver</a>
      </form>
    </div>
  </div>
</div>
<?php Html::footer(); ?>
