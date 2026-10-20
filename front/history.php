<?php
declare(strict_types=1);
use GlpiPlugin\Hh\Dashboard;
include('../../../inc/includes.php');
Session::checkLoginUser();
global $DB;
$embedded = (($_GET['embedded'] ?? '') === '1');
$itemtype = (string)($_GET['itemtype'] ?? '');
$itemsId = (int)($_GET['items_id'] ?? 0);
$allowed = ['Computer' => 'Computadora', 'Phone' => 'Teléfono'];
if ($embedded && (!isset($allowed[$itemtype]) || $itemsId < 1)) { $itemtype = ''; $itemsId = 0; }
if (!Session::haveRight('config', UPDATE)) Html::displayRightError();

function hhHistoryEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hhHistoryRows(string $table, array $where = [], array $order = []): array {
    global $DB; $params = ['SELECT' => ['*'], 'FROM' => $table];
    if ($where !== []) $params['WHERE'] = $where;
    if ($order !== []) $params['ORDER'] = $order;
    $result = []; foreach ($DB->request($params) as $row) $result[] = $row; return $result;
}
function hhHistoryAsset(string $type, int $id): string {
    $tables = ['Computer' => 'glpi_computers', 'Phone' => 'glpi_phones'];
    if (!isset($tables[$type]) || $id < 1) return 'Activo no disponible';
    foreach (hhHistoryRows($tables[$type], ['id' => $id, 'is_deleted' => 0]) as $a) {
        $v = trim((string)($a['name'] ?? ''));
        if (($a['otherserial'] ?? '') !== '') $v .= ($v !== '' ? ' | ' : '') . 'Activo fijo: ' . $a['otherserial'];
        if (($a['serial'] ?? '') !== '') $v .= ($v !== '' ? ' | ' : '') . 'Serie: ' . $a['serial'];
        return $v !== '' ? $v : $type . ' #' . $id;
    }
    return 'Activo no disponible';
}
function hhHistoryStatus(string $s): string { return ['draft'=>'Borrador','approved'=>'Aprobada','rejected'=>'Rechazada'][$s] ?? $s; }
function hhHistoryDate($v): string { $s = trim((string)$v); if ($s === '' || str_starts_with($s, '0000-00-00')) return '—'; try { return (new DateTimeImmutable($s))->format('d/m/Y H:i'); } catch (Throwable $e) { return $s; } }

$statuses = ['draft'=>'Borrador','approved'=>'Aprobada','rejected'=>'Rechazada'];
$status = (string)($_GET['status'] ?? '');
$search = trim((string)($_GET['q'] ?? ''));
$viewId = filter_var($_GET['view'] ?? 0, FILTER_VALIDATE_INT);
$view = null; $lines = []; $events = [];
if ($viewId !== false && $viewId > 0) {
    foreach (hhHistoryRows('glpi_plugin_hh_quotes', ['id'=>(int)$viewId]) as $r) { $view = $r; break; }
    if ($view && (!$embedded || ((string)$view['itemtype'] === $itemtype && (int)$view['items_id'] === $itemsId))) {
        $lines = hhHistoryRows('glpi_plugin_hh_quotedetails', ['plugin_hh_quotes_id'=>(int)$viewId], ['id ASC']);
        $events = hhHistoryRows('glpi_plugin_hh_quotehistory', ['plugin_hh_quotes_id'=>(int)$viewId], ['date_creation ASC','id ASC']);
    } else { $view = null; }
}
$where = $embedded ? ['itemtype'=>$itemtype, 'items_id'=>$itemsId] : [];\n$where['status'] = ['approved', 'rejected'];
if ($status !== '' && in_array($status, ['approved', 'rejected'], true)) $where['status'] = $status;
$quotes = hhHistoryRows('glpi_plugin_hh_quotes', $where, ['date_mod DESC','id DESC']);
if ($search !== '' && !$embedded) {
    $needle = mb_strtolower($search);
    $quotes = array_values(array_filter($quotes, static fn($q) =>
        str_contains(mb_strtolower((string)$q['name']), $needle)
        || str_contains(mb_strtolower((string)($q['supplier_name_snapshot'] ?? '')), $needle)
        || str_contains(mb_strtolower((string)($q['supplier_quote_number'] ?? '')), $needle)
        || str_contains(mb_strtolower((string)($q['diagnosis'] ?? '')), $needle)
    ));
}
if (!$embedded) Html::header(__('Historial de reparaciones', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
$base = '/plugins/hh/front/history.php' . ($embedded ? '?embedded=1&itemtype=' . rawurlencode($itemtype) . '&items_id=' . $itemsId : '');
?>
<style>
.hh-history{font-size:12px;line-height:1.35}.hh-history .card{border-radius:8px;margin-bottom:9px}.hh-history .card-header{padding:.55rem .8rem}.hh-history .card-body{padding:.65rem .8rem}.hh-history h2{font-size:16px;margin:0}.hh-history h3{font-size:13px;margin:6px 0 8px}.hh-history .form-label{font-size:11px;margin-bottom:3px}.hh-history .form-control,.hh-history .form-select{font-size:12px;min-height:29px;padding:4px 7px}.hh-history .table{font-size:11.5px;margin-bottom:0}.hh-history .table th,.hh-history .table td{padding:5px 7px;vertical-align:middle}.hh-history .badge{font-size:10px}.hh-history .meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}.hh-history .meta>div{border:1px solid var(--tblr-border-color,#dce1e7);border-radius:6px;padding:6px 8px;overflow-wrap:anywhere}.hh-history .meta small{display:block;color:var(--tblr-muted,#667085);font-size:10px}.hh-history .meta strong{font-size:12px}@media(max-width:639px){.hh-history .meta{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:420px){.hh-history .meta{grid-template-columns:1fr}}
</style>
<div class="container-fluid hh-history">
 <div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><h2><?= $embedded ? 'Historial del activo' : 'Historial de reparaciones' ?></h2><?php if ($view): ?><a class="btn btn-sm btn-outline-secondary" href="<?=hhHistoryEscape($base)?>">Volver</a><?php endif; ?></div>
  <div class="card-body">
  <?php if ($view): ?>
   <h3><?=hhHistoryEscape((string)$view['name'])?> · <?=hhHistoryEscape(hhHistoryStatus((string)$view['status']))?></h3>
   <div class="meta mb-3">
    <div><small>Activo</small><strong><?=hhHistoryEscape(hhHistoryAsset((string)$view['itemtype'],(int)$view['items_id']))?></strong></div>
    <div><small>Fecha</small><strong><?=hhHistoryEscape(hhHistoryDate($view['quote_date'] ?? ''))?></strong></div>
    <div><small>Proveedor</small><strong><?=hhHistoryEscape((string)($view['supplier_name_snapshot'] ?: 'No especificado'))?></strong></div>
    <div><small>N.º cotización proveedor</small><strong><?=hhHistoryEscape((string)($view['supplier_quote_number'] ?: '—'))?></strong></div>
    <div><small>Tipo / prioridad</small><strong><?=hhHistoryEscape(['corrective'=>'Correctiva','preventive'=>'Preventiva','diagnostic'=>'Diagnóstico'][$view['repair_type']] ?? (string)$view['repair_type'])?> / <?=hhHistoryEscape(['low'=>'Baja','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$view['priority']] ?? (string)$view['priority'])?></strong></div>
    <div><small>Total</small><strong><?=number_format((float)$view['total_points'],2)?> puntos · $<?=number_format((float)$view['total_amount_snapshot'],2)?></strong></div>
   </div>
   <?php if (trim((string)($view['diagnosis'] ?? '')) !== ''): ?><p><strong>Diagnóstico:</strong> <?=nl2br(hhHistoryEscape((string)$view['diagnosis']))?></p><?php endif; ?>
   <?php if (trim((string)($view['observations'] ?? '')) !== ''): ?><p><strong>Observaciones / decisión:</strong> <?=nl2br(hhHistoryEscape((string)$view['observations']))?></p><?php endif; ?>
   <h3>Repuestos y servicios</h3><div class="table-responsive mb-3"><table class="table table-sm table-striped table-bordered"><thead><tr><th>Código</th><th>Artículo</th><th>Cant.</th><th>Puntos/u.</th><th>Total puntos</th><th>Total USD</th><th>Origen de pieza reutilizada</th></tr></thead><tbody>
   <?php if (!$lines): ?><tr><td colspan="7" class="text-center text-muted">Sin líneas de detalle.</td></tr><?php endif; ?>
   <?php foreach ($lines as $line): ?><tr><td><?=hhHistoryEscape((string)($line['code_snapshot'] ?? '—'))?></td><td><?=hhHistoryEscape((string)$line['description_snapshot'])?></td><td><?=number_format((float)$line['quantity'],2)?></td><td><?=number_format((float)$line['points_each'],2)?></td><td><?=number_format((float)$line['points_total'],2)?></td><td>$<?=number_format((float)$line['amount_snapshot'],2)?></td><td><?=!empty($line['source_itemtype']) && (int)($line['source_items_id'] ?? 0)>0 ? hhHistoryEscape(hhHistoryAsset((string)$line['source_itemtype'],(int)$line['source_items_id'])) : '—'?></td></tr><?php endforeach; ?>
   </tbody><tfoot><tr><th colspan="4" class="text-end">Total</th><th><?=number_format((float)$view['total_points'],2)?></th><th>$<?=number_format((float)$view['total_amount_snapshot'],2)?></th><th></th></tr></tfoot></table></div>
   <h3>Historial de estados</h3><div class="table-responsive"><table class="table table-sm table-striped table-bordered"><thead><tr><th>Fecha</th><th>Estado anterior</th><th>Estado nuevo</th><th>Usuario</th><th>Comentario</th></tr></thead><tbody>
   <?php if (!$events): ?><tr><td colspan="5" class="text-center text-muted">No hay eventos registrados.</td></tr><?php endif; ?>
   <?php foreach ($events as $event): $username=''; foreach (hhHistoryRows('glpi_users',['id'=>(int)$event['users_id']]) as $u) {$username=trim((string)($u['firstname'] ?? '').' '.(string)($u['realname'] ?? '')); if($username==='')$username=(string)($u['name'] ?? ''); break;} ?><tr><td><?=hhHistoryEscape(hhHistoryDate($event['date_creation']))?></td><td><?=hhHistoryEscape($event['old_status'] ? hhHistoryStatus((string)$event['old_status']) : '—')?></td><td><?=hhHistoryEscape(hhHistoryStatus((string)$event['new_status']))?></td><td><?=hhHistoryEscape($username !== '' ? $username : 'Usuario #'.(int)$event['users_id'])?></td><td><?=nl2br(hhHistoryEscape((string)($event['comment'] ?? '')))?></td></tr><?php endforeach; ?>
   </tbody></table></div>
  <?php else: ?>
   <?php if (!$embedded): ?><form method="get" class="row g-2 align-items-end mb-3"><div class="col-md-6"><label class="form-label">Buscar</label><input class="form-control" name="q" value="<?=hhHistoryEscape($search)?>" placeholder="Cotización, proveedor o diagnóstico"></div><div class="col-md-3"><label class="form-label">Estado</label><select class="form-select" name="status"><option value="">Todos</option><?php foreach($statuses as $k=>$v): ?><option value="<?=hhHistoryEscape($k)?>" <?=$status===$k?'selected':''?>><?=hhHistoryEscape($v)?></option><?php endforeach; ?></select></div><div class="col-md-3"><button class="btn btn-primary" type="submit">Filtrar</button> <a class="btn btn-outline-secondary" href="/plugins/hh/front/history.php">Limpiar</a></div></form><?php endif; ?>
   <div class="table-responsive"><table class="table table-sm table-striped table-hover"><thead><tr><th>Cotización</th><th>Fecha</th><th>Activo</th><th>Proveedor</th><th>Estado</th><th>Puntos</th><th>Total USD</th><th></th></tr></thead><tbody>
   <?php if (!$quotes): ?><tr><td colspan="8" class="text-center text-muted py-3">No hay cotizaciones que coincidan con los filtros.</td></tr><?php endif; ?>
   <?php foreach ($quotes as $quote): $s=(string)$quote['status']; $badge=$s==='approved'?'bg-success':($s==='rejected'?'bg-danger':'bg-secondary'); ?><tr><td><strong><?=hhHistoryEscape((string)$quote['name'])?></strong><br><small class="text-muted"><?=hhHistoryEscape(['corrective'=>'Correctiva','preventive'=>'Preventiva','diagnostic'=>'Diagnóstico'][$quote['repair_type']] ?? (string)$quote['repair_type'])?></small></td><td><?=hhHistoryEscape(hhHistoryDate($quote['quote_date'] ?? ''))?></td><td><?=hhHistoryEscape(hhHistoryAsset((string)$quote['itemtype'],(int)$quote['items_id']))?></td><td><?=hhHistoryEscape((string)($quote['supplier_name_snapshot'] ?: 'No especificado'))?></td><td><span class="badge <?=$badge?>"><?=hhHistoryEscape(hhHistoryStatus($s))?></span></td><td><?=number_format((float)$quote['total_points'],2)?></td><td>$<?=number_format((float)$quote['total_amount_snapshot'],2)?></td><td><a class="btn btn-sm btn-outline-primary" href="<?=hhHistoryEscape($base . ($embedded ? '&' : '?') . 'view=' . (int)$quote['id'])?>">Ver detalle</a></td></tr><?php endforeach; ?>
   </tbody></table></div>
  <?php endif; ?>
  </div>
 </div>
</div>
<?php if (!$embedded) Html::footer(); ?>
