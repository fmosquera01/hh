<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;
use GlpiPlugin\Hh\RepairWorkflow;
use GlpiPlugin\Hh\Rights;

include('../../../inc/includes.php');

Session::checkLoginUser();
Rights::require(Rights::CATALOG);

$message = '';
$error = '';
$current = RepairWorkflow::getConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
    $requestTypeId = filter_var($_POST['requesttypes_id'] ?? 0, FILTER_VALIDATE_INT);
    $enabled = isset($_POST['enabled']);
    if ($requestTypeId === false || $requestTypeId < 1) {
        $error = 'Seleccione el origen de solicitud FormularioReparacion.';
    } elseif (RepairWorkflow::findStateId(RepairWorkflow::STATUS_SYSTEMS) < 1
        || RepairWorkflow::findStateId(RepairWorkflow::STATUS_SUPPLIER) < 1) {
        $error = 'No se encontraron los estados REPARACIÓN Sistemas y/o REPARACIÓN Proveedor en los estados de GLPI.';
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
          <div class="form-hint">Seleccione el registro FormularioReparacion creado en Configuración > Desplegables > Origen de la solicitud.</div>
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
            <li>LIBRE y DAÑADO se utilizan para los indicadores del dashboard.</li>
          </ul>
        </div>
        <button class="btn btn-primary" type="submit">Guardar configuración</button>
        <a class="btn btn-secondary" href="/plugins/hh/front/index.php">Volver</a>
      </form>
    </div>
  </div>
</div>
<?php Html::footer(); ?>
