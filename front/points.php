<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
}
global $DB;

function hhPe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hhPQuoteLabel(array $quote): string
{
    return (string)$quote['name'] . ' — ' . (string)($quote['quote_date'] ?? '') .
        ' — ' . number_format((float)$quote['total_points'], 2) . ' puntos';
}

$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $date = trim((string)($_POST['movement_date'] ?? date('Y-m-d')));
    $points = filter_var($_POST['points'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        $error = 'Ingrese una fecha válida.';
    } elseif ($points === false || $points <= 0) {
        $error = 'La cantidad de puntos debe ser mayor que cero.';
    } elseif ($action === 'purchase') {
        $supplierQuote = trim((string)($_POST['supplier_quote_number'] ?? ''));
        if ($supplierQuote === '') {
            $error = 'Ingrese el número de cotización de compra de puntos.';
        } else {
            $ok = $DB->insert('glpi_plugin_hh_pointmovements', [
                'movement_type' => 'purchase',
                'points' => number_format((float)$points, 2, '.', ''),
                'movement_date' => $date,
                'supplier_quote_number' => mb_substr($supplierQuote, 0, 100),
                'description' => 'Compra de puntos',
                'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,
                'users_id' => (int)Session::getLoginUserID(),
            ]);
            if ($ok) $notice = 'Compra de puntos registrada correctamente.';
            else $error = 'No se pudo registrar la compra.';
        }
    } elseif ($action === 'historical_consumption') {
        $quoteId = filter_var($_POST['quote_id'] ?? 0, FILTER_VALIDATE_INT);
        $quote = null;
        if ($quoteId !== false && $quoteId > 0) {
            foreach ($DB->request(['SELECT' => ['id','name','itemtype','items_id','status'], 'FROM' => 'glpi_plugin_hh_quotes', 'WHERE' => ['id' => (int)$quoteId], 'LIMIT' => 1]) as $row) {
                $quote = $row;
                break;
            }
        }
        if ($quoteId !== false && $quoteId > 0 && !$quote) {
            $error = 'La cotización seleccionada no existe.';
        } else {
            if ($quote) {
                foreach ($DB->request([
                    'SELECT' => ['id'], 'FROM' => 'glpi_plugin_hh_pointmovements',
                    'WHERE' => ['plugin_hh_quotes_id' => (int)$quoteId, 'movement_type' => 'consumption'], 'LIMIT' => 1,
                ]) as $existing) {
                    $error = 'Esta cotización ya tiene un consumo registrado. No se permite duplicarlo.';
                    break;
                }
            }
            if ($error === '') {
                $ok = $DB->insert('glpi_plugin_hh_pointmovements', [
                    'movement_type' => 'consumption',
                    'points' => number_format(-1 * (float)$points, 2, '.', ''),
                    'movement_date' => $date,
                    'plugin_hh_quotes_id' => $quote ? (int)$quote['id'] : null,
                    'itemtype' => $quote ? (string)$quote['itemtype'] : null,
                    'items_id' => $quote ? (int)$quote['items_id'] : null,
                    'description' => $quote ? 'Consumo histórico de ' . (string)$quote['name'] : 'Consumo histórico manual',
                    'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,
                    'users_id' => (int)Session::getLoginUserID(),
                ]);
                if ($ok) $notice = 'Consumo histórico registrado correctamente.';
                else $error = 'No se pudo registrar el consumo histórico.';
            }
        }
    } else {
        $error = 'Acción no válida.';
    }
}

$totalPurchased = 0.0;
$totalConsumed = 0.0;
$movements = [];
foreach ($DB->request(['SELECT' => ['*'], 'FROM' => 'glpi_plugin_hh_pointmovements', 'ORDER' => ['movement_date DESC', 'id DESC']]) as $movement) {
    $movements[] = $movement;
    if ((string)$movement['movement_type'] === 'purchase') $totalPurchased += (float)$movement['points'];
    else $totalConsumed += abs((float)$movement['points']);
}
$balance = $totalPurchased - $totalConsumed;
$quotes = [];
foreach ($DB->request(['SELECT' => ['id','name','quote_date','total_points','status'], 'FROM' => 'glpi_plugin_hh_quotes', 'ORDER' => ['quote_date DESC','id DESC']]) as $quote) {
    $quotes[] = $quote;
}

Html::header(__('Gestión de reparaciones', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
?>
<style>
.hh-points-kpi{border:1px solid var(--tblr-border-color);border-radius:8px;padding:18px;height:100%}
.hh-points-value{font-size:1.8rem;font-weight:700;line-height:1.2}
.hh-points-negative{color:var(--tblr-danger)}
</style>
<div class="container-fluid">
 <div class="d-flex align-items-center justify-content-between mb-3">
  <div><h2 class="mb-1">Control de puntos</h2><div class="text-muted">Compras, consumos automáticos e históricos de reparaciones</div></div>
  <a class="btn btn-outline-secondary" href="/plugins/hh/front/index.php">Volver a Gestión HH</a>
 </div>
 <?php if ($notice !== ''): ?><div class="alert alert-success"><?=hhPe($notice)?></div><?php endif; ?>
 <?php if ($error !== ''): ?><div class="alert alert-danger"><?=hhPe($error)?></div><?php endif; ?>
 <div class="row g-3 mb-4">
  <div class="col-md-4"><div class="hh-points-kpi"><div class="text-muted">Total comprado</div><div class="hh-points-value"><?=number_format($totalPurchased,2)?> pts</div></div></div>
  <div class="col-md-4"><div class="hh-points-kpi"><div class="text-muted">Total consumido</div><div class="hh-points-value"><?=number_format($totalConsumed,2)?> pts</div></div></div>
  <div class="col-md-4"><div class="hh-points-kpi"><div class="text-muted">Saldo disponible</div><div class="hh-points-value <?=$balance < 0 ? 'hh-points-negative' : ''?>"><?=number_format($balance,2)?> pts</div><?php if ($balance < 0): ?><div class="text-danger small fw-bold">Saldo negativo: se han consumido más puntos de los comprados.</div><?php endif; ?></div></div>
 </div>
 <div class="row g-3 mb-4">
  <div class="col-lg-6">
   <div class="card h-100"><div class="card-header"><h3 class="card-title mb-0">Registrar compra de puntos</h3></div><div class="card-body">
    <form method="post">
     <input type="hidden" name="_glpi_csrf_token" value="<?=hhPe(Session::getNewCSRFToken())?>"><input type="hidden" name="action" value="purchase">
     <div class="mb-3"><label class="form-label">N.º de cotización de compra</label><input class="form-control" name="supplier_quote_number" maxlength="100" required></div>
     <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Cantidad de puntos</label><input class="form-control" type="number" name="points" min="0.01" step="0.01" required></div><div class="col-md-6 mb-3"><label class="form-label">Fecha de compra</label><input class="form-control" type="date" name="movement_date" value="<?=date('Y-m-d')?>" required></div></div>
     <div class="mb-3"><label class="form-label">Observaciones</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
     <button class="btn btn-primary" type="submit">Registrar compra</button>
    </form>
   </div></div>
  </div>
  <div class="col-lg-6">
   <div class="card h-100"><div class="card-header"><h3 class="card-title mb-0">Registrar consumo anterior</h3></div><div class="card-body">
    <form method="post">
     <input type="hidden" name="_glpi_csrf_token" value="<?=hhPe(Session::getNewCSRFToken())?>"><input type="hidden" name="action" value="historical_consumption">
     <div class="mb-3"><label class="form-label">Cotización de reparación (opcional)</label><select class="form-select" name="quote_id"><option value="0">Sin cotización asociada / consumo manual</option><?php foreach ($quotes as $quote): ?><option value="<?=(int)$quote['id']?>"><?=hhPe(hhPQuoteLabel($quote))?> — <?=hhPe((string)$quote['status'])?></option><?php endforeach; ?></select><small class="text-muted">Si la reparación tiene una cotización registrada, selecciónela para relacionar el consumo y evitar duplicados.</small></div>
     <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Puntos consumidos</label><input class="form-control" type="number" name="points" min="0.01" step="0.01" required></div><div class="col-md-6 mb-3"><label class="form-label">Fecha del consumo</label><input class="form-control" type="date" name="movement_date" value="<?=date('Y-m-d')?>" required></div></div>
     <div class="mb-3"><label class="form-label">Observaciones / referencia</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
     <button class="btn btn-outline-primary" type="submit">Registrar consumo histórico</button>
    </form>
   </div></div>
  </div>
 </div>
 <div class="card"><div class="card-header"><h3 class="card-title mb-0">Movimientos de puntos</h3></div><div class="table-responsive">
  <table class="table table-hover table-striped mb-0"><thead><tr><th>Fecha</th><th>Tipo</th><th>Referencia</th><th>Detalle</th><th class="text-end">Puntos</th><th>Observaciones</th></tr></thead><tbody>
  <?php foreach ($movements as $movement): $purchase = (string)$movement['movement_type'] === 'purchase'; ?>
   <tr><td><?=hhPe((string)$movement['movement_date'])?></td><td><span class="badge <?=$purchase ? 'bg-success' : 'bg-warning text-dark'?>"><?=$purchase ? 'Compra' : 'Consumo'?></span></td><td><?=hhPe((string)($movement['supplier_quote_number'] ?: ($movement['plugin_hh_quotes_id'] ? 'Cotización HH #'.$movement['plugin_hh_quotes_id'] : 'Manual')))?></td><td><?=hhPe((string)($movement['description'] ?? ''))?></td><td class="text-end fw-semibold <?=$purchase ? 'text-success' : 'text-danger'?>"><?=$purchase ? '+' : ''?><?=number_format((float)$movement['points'],2)?></td><td><?=hhPe((string)($movement['notes'] ?? ''))?></td></tr>
  <?php endforeach; ?>
  <?php if (!$movements): ?><tr><td colspan="6" class="text-center text-muted p-4">Todavía no hay movimientos. Registre su primera compra de puntos.</td></tr><?php endif; ?>
  </tbody></table>
 </div></div>
</div>
<?php Html::footer(); ?>
