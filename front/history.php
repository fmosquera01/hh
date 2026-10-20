<?php
declare(strict_types=1);

include('../../../inc/includes.php');
Session::checkLoginUser();
global $DB;

$embedded = (($_GET['embedded'] ?? '') === '1');
$itemtype = (string)($_GET['itemtype'] ?? '');
$itemsId = (int)($_GET['items_id'] ?? 0);
$allowed = ['Computer' => 'Computadora', 'Phone' => 'Teléfono'];
function hhHistoryEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$assetName = '';
$tables = ['Computer' => 'glpi_computers', 'Phone' => 'glpi_phones'];
if (isset($tables[$itemtype]) && $itemsId > 0) {
    foreach ($DB->request(['SELECT' => ['name','serial','otherserial'], 'FROM' => $tables[$itemtype], 'WHERE' => ['id' => $itemsId, 'is_deleted' => 0], 'LIMIT' => 1]) as $asset) {
        $assetName = trim((string)$asset['name'] . ' | Serie: ' . (string)$asset['serial'] . ' | Activo fijo: ' . (string)$asset['otherserial']);
    }
}
if (!$embedded) Html::header(__('Historial de reparaciones', 'hh'), $_SERVER['PHP_SELF'], 'plugins');
$quotes = [];
if (isset($tables[$itemtype]) && $itemsId > 0) {
    foreach ($DB->request(['SELECT' => '*', 'FROM' => 'glpi_plugin_hh_quotes', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId], 'ORDER' => ['date_mod DESC', 'id DESC']]) as $quote) {
        $quotes[] = $quote;
    }
}
?>
<div class="container-fluid">
  <div class="card">
    <div class="card-header"><h3 class="card-title">Historial de reparaciones</h3></div>
    <div class="card-body">
      <?php if ($assetName !== ''): ?><p class="text-muted mb-3"><?=hhHistoryEscape($assetName)?></p><?php endif; ?>
      <div class="table-responsive"><table class="table table-striped table-hover">
        <thead><tr><th>Cotización</th><th>Fecha</th><th>Tipo</th><th>Proveedor</th><th>Estado</th><th class="text-end">Puntos</th><th class="text-end">Total USD</th></tr></thead>
        <tbody>
        <?php foreach ($quotes as $quote): ?>
          <tr>
            <td><?=hhHistoryEscape((string)$quote['name'])?></td>
            <td><?=hhHistoryEscape((string)($quote['quote_date'] ?? ''))?></td>
            <td><?=hhHistoryEscape($allowed[(string)$quote['itemtype']] ?? (string)$quote['itemtype'])?></td>
            <td><?=hhHistoryEscape((string)($quote['supplier_name_snapshot'] ?? '—'))?></td>
            <td><?=hhHistoryEscape(match ((string)$quote['status']) {'approved' => 'Aprobada', 'rejected' => 'Rechazada', default => 'Borrador'})?></td>
            <td class="text-end"><?=number_format((float)$quote['total_points'], 2)?></td>
            <td class="text-end">$<?=number_format((float)$quote['total_amount_snapshot'], 2)?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($quotes === []): ?><tr><td colspan="7" class="text-center text-muted p-4">Este activo todavía no tiene cotizaciones ni registros en el historial.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
<?php if (!$embedded) Html::footer(); ?>
