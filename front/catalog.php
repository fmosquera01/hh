<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
}

global $DB;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRFToken();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_item') {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'part');
        $points = filter_var($_POST['points_each'] ?? '0', FILTER_VALIDATE_FLOAT);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if (!preg_match('/^[A-Z0-9._-]{1,40}$/', $code) || $name === '' || mb_strlen($name) > 180
            || !in_array($category, ['part', 'labor', 'service', 'other'], true)
            || $points === false || $points < 0) {
            $error = 'Revise el código, nombre, categoría y puntos ingresados.';
        } else {
            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_plugin_hh_items',
                'WHERE' => ['code' => $code],
                'LIMIT' => 1,
            ]);
            $existingId = 0;
            foreach ($existing as $row) { $existingId = (int) $row['id']; }
            $data = [
                'code' => $code,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'category' => $category,
                'points_each' => number_format((float) $points, 2, '.', ''),
                'is_active' => $active,
            ];
            if ($existingId > 0) {
                $DB->update('glpi_plugin_hh_items', $data, ['id' => $existingId]);
                $message = 'Artículo actualizado.';
            } else {
                $data['users_id'] = (int) Session::getLoginUserID();
                if ($DB->insert('glpi_plugin_hh_items', $data)) {
                    $message = 'Artículo creado en el catálogo.';
                } else {
                    $error = 'No se pudo guardar el artículo. Verifique que el código no esté duplicado.';
                }
            }
        }
    } elseif ($action === 'toggle_item') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $DB->update('glpi_plugin_hh_items', ['is_active' => (int) ($_POST['active'] ?? 0) ? 0 : 1], ['id' => $id]);
            $message = 'Estado del artículo actualizado.';
        }
    }
}

$items = [];
foreach ($DB->request([
    'SELECT' => '*',
    'FROM' => 'glpi_plugin_hh_items',
    'ORDER' => ['name ASC'],
]) as $row) {
    $items[] = $row;
}

Html::header(__('Catálogo de repuestos y servicios', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
?>
<div class="container-fluid">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Catálogo de repuestos y servicios</h2></div>
    <div class="card-body">
      <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
      <p class="text-muted">El código es único. Si vuelve a guardar un código existente, se actualizará ese artículo. Los artículos inactivos se conservan para el historial de cotizaciones.</p>
      <form method="post" class="mb-4">
        <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_item">
        <div class="row g-3">
          <div class="col-md-2"><label class="form-label">Código</label><input class="form-control" name="code" maxlength="40" required placeholder="REP-001"></div>
          <div class="col-md-4"><label class="form-label">Nombre</label><input class="form-control" name="name" maxlength="180" required></div>
          <div class="col-md-2"><label class="form-label">Categoría</label><select class="form-select" name="category"><option value="part">Repuesto</option><option value="labor">Mano de obra</option><option value="service">Servicio</option><option value="other">Otro</option></select></div>
          <div class="col-md-2"><label class="form-label">Puntos por unidad</label><input class="form-control" type="number" name="points_each" min="0" step="0.01" value="0" required></div>
          <div class="col-md-2 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" checked> Activo</label></div>
          <div class="col-12"><label class="form-label">Descripción</label><textarea class="form-control" name="description" rows="2"></textarea></div>
          <div class="col-12"><button class="btn btn-primary" type="submit">Guardar artículo</button></div>
        </div>
      </form>
      <h3>Artículos registrados (<?= count($items) ?>)</h3>
      <div class="table-responsive"><table class="table table-striped table-hover">
        <thead><tr><th>Código</th><th>Nombre</th><th>Categoría</th><th class="text-end">Puntos</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
        <?php foreach ($items as $row): ?>
          <tr>
            <td><?= htmlspecialchars((string) $row['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><div class="text-muted small"><?= htmlspecialchars((string) ($row['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div></td>
            <td><?= htmlspecialchars((string) $row['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td class="text-end"><?= number_format((float) $row['points_each'], 2) ?></td>
            <td><?= (int) $row['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></td>
            <td><form method="post"><input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="active" value="<?= (int) $row['is_active'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $row['is_active'] === 1 ? 'Desactivar' : 'Activar' ?></button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?><tr><td colspan="6" class="text-center text-muted">Todavía no hay artículos registrados.</td></tr><?php endif; ?>
        </tbody></table></div>
      <a class="btn btn-secondary" href="<?= htmlspecialchars('/' . ltrim(Plugin::getWebDir('hh', false), '/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/front/index.php">Volver al módulo</a>
    </div>
  </div>
</div>
<?php Html::footer(); ?>
