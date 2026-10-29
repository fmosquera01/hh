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
$allowedTypes = ['Computer' => 'Computadoras', 'Phone' => 'Teléfonos'];

function hhCatalogEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hhCatalogModels(string $itemtype): array
{
    global $DB;
    $table = match ($itemtype) {
        'Computer' => 'glpi_computermodels',
        'Phone' => 'glpi_phonemodels',
        default => '',
    };
    if ($table === '') {
        return [];
    }
    $models = [];
    foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => $table, 'ORDER' => ['name ASC']]) as $row) {
        $models[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }
    return $models;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_item') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'part');
        $itemtype = (string) ($_POST['itemtype'] ?? '');
        $modelsIdRaw = (string) ($_POST['models_id'] ?? '');
        $modelsId = $modelsIdRaw !== '' ? filter_var($modelsIdRaw, FILTER_VALIDATE_INT) : null;
        $points = filter_var($_POST['points_each'] ?? '0', FILTER_VALIDATE_FLOAT);
        $warrantyDays = filter_var($_POST['warranty_days'] ?? '60', FILTER_VALIDATE_INT);
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 180
            || !in_array($category, ['part', 'labor', 'service', 'other'], true)
            || !array_key_exists($itemtype, $allowedTypes)
            || ($modelsIdRaw !== '' && ($modelsId === false || $modelsId < 1))
            || $points === false || $points < 0
            || $warrantyDays === false || $warrantyDays < 0 || $warrantyDays > 3650) {
            $error = 'Revise el nombre, categoría, tipo/modelo de activo y puntos ingresados.';
        } else {
            $modelTable = $itemtype === 'Computer' ? 'glpi_computermodels' : 'glpi_phonemodels';
            if ($modelsId !== null) {
                $modelFound = false;
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $modelTable, 'WHERE' => ['id' => (int) $modelsId], 'LIMIT' => 1]) as $unused) {
                    $modelFound = true;
                }
                if (!$modelFound) {
                    $error = 'El modelo seleccionado no existe para el tipo de activo elegido.';
                }
            }
            if ($error === '') {
                $lastId = 0;
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_hh_items', 'ORDER' => ['id DESC'], 'LIMIT' => 1]) as $last) {
                    $lastId = (int) $last['id'];
                }
                $code = 'HH-' . ($category === 'part' ? 'REP' : ($category === 'labor' ? 'MO' : ($category === 'service' ? 'SER' : 'OTR'))) . '-' . str_pad((string) ($lastId + 1), 6, '0', STR_PAD_LEFT);
                $data = [
                    'code' => $code,
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'category' => $category,
                    'points_each' => number_format((float) $points, 2, '.', ''),
                    'warranty_days' => (int) $warrantyDays,
                    'is_active' => $active,
                    'users_id' => (int) Session::getLoginUserID(),
                ];
                if ($DB->insert('glpi_plugin_hh_items', $data)) {
                    $itemId = (int) $DB->insertId();
                    $mapping = [
                        'plugin_hh_items_id' => $itemId,
                        'itemtype' => $itemtype,
                        'models_id' => $modelsId !== null ? (int) $modelsId : null,
                    ];
                    if ($DB->insert('glpi_plugin_hh_itemtypes', $mapping)) {
                        $message = 'Artículo creado correctamente con código automático ' . $code . '.';
                    } else {
                        $error = 'El artículo se guardó, pero no se pudo guardar su tipo/modelo de activo. Revise la tabla del plugin.';
                    }
                } else {
                    $error = 'No se pudo guardar el artículo. Actualice la página e inténtelo nuevamente.';
                }
            }
        }
    } elseif ($action === 'toggle_item') {
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $DB->update('glpi_plugin_hh_items', ['is_active' => (int) ($_POST['active'] ?? 0) ? 0 : 1], ['id' => $id]);
            $message = 'Estado del artículo actualizado.';
        }
    }
}

$items = [];
foreach ($DB->request(['SELECT' => '*', 'FROM' => 'glpi_plugin_hh_items', 'ORDER' => ['name ASC']]) as $row) {
    $itemId = (int) $row['id'];
    $applicability = [];
    foreach ($DB->request(['SELECT' => ['itemtype', 'models_id'], 'FROM' => 'glpi_plugin_hh_itemtypes', 'WHERE' => ['plugin_hh_items_id' => $itemId]]) as $map) {
        $type = (string) $map['itemtype'];
        $label = $allowedTypes[$type] ?? $type;
        $modelId = (int) ($map['models_id'] ?? 0);
        if ($modelId > 0) {
            $modelTable = $type === 'Computer' ? 'glpi_computermodels' : ($type === 'Phone' ? 'glpi_phonemodels' : '');
            if ($modelTable !== '') {
                foreach ($DB->request(['SELECT' => ['name'], 'FROM' => $modelTable, 'WHERE' => ['id' => $modelId], 'LIMIT' => 1]) as $model) {
                    $label .= ' — ' . (string) $model['name'];
                }
            }
        } else {
            $label .= ' — Todos los modelos';
        }
        $applicability[] = $label;
    }
    $row['applicability'] = $applicability !== [] ? implode(', ', $applicability) : 'Sin tipo/modelo asignado';
    $items[] = $row;
}

$computerModels = hhCatalogModels('Computer');
$phoneModels = hhCatalogModels('Phone');
Html::header(__('Catálogo de repuestos y servicios', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
?>
<div class="container-fluid">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Catálogo de repuestos y servicios</h2></div>
    <div class="card-body">
      <?php if ($message !== ''): ?><div class="alert alert-success"><?= hhCatalogEscape($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert alert-danger"><?= hhCatalogEscape($error) ?></div><?php endif; ?>
      <p class="text-muted">El código se genera automáticamente al guardar. Elija el tipo de activo y, opcionalmente, un modelo específico. Si deja el modelo vacío, el artículo aplica a todos los modelos de ese tipo.</p>
      <form method="post" class="mb-4">
        <input type="hidden" name="_glpi_csrf_token" value="<?= hhCatalogEscape(Session::getNewCSRFToken()) ?>">
        <input type="hidden" name="action" value="save_item">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Nombre del artículo</label><input class="form-control" name="name" maxlength="180" required></div>
          <div class="col-md-2"><label class="form-label">Categoría</label><select class="form-select" name="category"><option value="part">Repuesto</option><option value="labor">Mano de obra</option><option value="service">Servicio</option><option value="other">Otro</option></select></div>
          <div class="col-md-3"><label class="form-label">Tipo de activo</label><select class="form-select" name="itemtype" id="hh-itemtype" required><option value="">Seleccione...</option><option value="Computer">Computadoras</option><option value="Phone">Teléfonos</option></select></div>
          <div class="col-md-3"><label class="form-label">Modelo (opcional)</label><select class="form-select" name="models_id" id="hh-model"><option value="">Todos los modelos del tipo seleccionado</option></select></div>
          <div class="col-md-3"><label class="form-label">Puntos por unidad</label><input class="form-control" type="number" name="points_each" min="0" step="0.01" value="0" required></div>
          <div class="col-md-3"><label class="form-label">Garantía del repuesto (días)</label><input class="form-control" type="number" name="warranty_days" min="0" max="3650" step="1" value="60" required><small class="text-muted">0 = sin garantía</small></div>
          <div class="col-md-3 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" checked> Activo</label></div>
          <div class="col-12"><label class="form-label">Descripción</label><textarea class="form-control" name="description" rows="2"></textarea></div>
          <div class="col-12"><button class="btn btn-primary" type="submit">Guardar artículo</button> <a class="btn btn-secondary" href="/plugins/hh/front/index.php">Volver a Gestión de reparaciones</a></div>
        </div>
      </form>
      <h3>Artículos registrados (<?= count($items) ?>)</h3>
      <div class="table-responsive"><table class="table table-striped table-hover">
        <thead><tr><th>Código</th><th>Nombre</th><th>Categoría</th><th>Tipo/modelo de activo</th><th class="text-end">Puntos</th><th>Garantía</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
        <?php foreach ($items as $row): ?>
          <tr>
            <td><?= hhCatalogEscape((string) $row['code']) ?></td>
            <td><?= hhCatalogEscape((string) $row['name']) ?><div class="text-muted small"><?= hhCatalogEscape((string) ($row['description'] ?? '')) ?></div></td>
            <td><?= hhCatalogEscape((string) $row['category']) ?></td>
            <td><?= hhCatalogEscape((string) $row['applicability']) ?></td>
            <td class="text-end"><?= number_format((float) $row['points_each'], 2) ?></td>
            <td><?= (int) ($row['warranty_days'] ?? 60) ?> días</td>
            <td><?= (int) $row['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></td>
            <td><form method="post"><input type="hidden" name="_glpi_csrf_token" value="<?= hhCatalogEscape(Session::getNewCSRFToken()) ?>"><input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="active" value="<?= (int) $row['is_active'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int) $row['is_active'] === 1 ? 'Desactivar' : 'Activar' ?></button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?><tr><td colspan="8" class="text-center text-muted">Todavía no hay artículos registrados.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </div>
</div>
<script>
(() => {
  const type = document.getElementById('hh-itemtype');
  const model = document.getElementById('hh-model');
  const models = {
    Computer: <?= json_encode($computerModels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    Phone: <?= json_encode($phoneModels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
  };
  function refreshModels() {
    model.replaceChildren();
    const all = document.createElement('option');
    all.value = '';
    all.textContent = type.value ? 'Todos los modelos del tipo seleccionado' : 'Seleccione primero el tipo de activo';
    model.appendChild(all);
    (models[type.value] || []).forEach((entry) => {
      const option = document.createElement('option');
      option.value = String(entry.id);
      option.textContent = entry.name;
      model.appendChild(option);
    });
    model.disabled = !type.value;
  }
  type.addEventListener('change', refreshModels);
  refreshModels();
})();
</script>
<?php Html::footer(); ?>
