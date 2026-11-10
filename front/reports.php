<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;
use GlpiPlugin\Hh\Reports;
use GlpiPlugin\Hh\RepairAnalytics;
use GlpiPlugin\Hh\Rights;

include('../../../inc/includes.php');

Session::checkLoginUser();
Rights::require(Rights::REPORTS);

$year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT);
$year = ($year !== false && $year >= 2000 && $year <= 2100) ? (int) $year : (int) date('Y');
$search = trim((string) ($_GET['q'] ?? ''));
$assetTypes = Reports::getAssetTypes();
$typeFilter = trim((string) ($_GET['itemtype'] ?? ''));
$subtypeFilter = trim((string) ($_GET['subtype'] ?? ''));
$modelFilter = trim((string) ($_GET['model'] ?? ''));

// Initial dashboard view: Phone + HANDHELD. Explicit user selections always win.
if (!array_key_exists('itemtype', $_GET) && in_array('Phone', $assetTypes, true)) {
    $typeFilter = 'Phone';
}
$baseInventory = Reports::getInventory($search, $typeFilter !== '' ? $typeFilter : null);
// Apply the class constraint independently of the inventory query.
if ($typeFilter !== '') {
    $baseInventory = array_values(array_filter(
        $baseInventory,
        static fn(array $asset): bool => ($asset['itemtype'] ?? '') === $typeFilter
    ));
}

// Build one option per real dropdown ID, using only assets from the selected GLPI class.
$subtypeOptions = [];
foreach ($baseInventory as $asset) {
    $id = (int) ($asset['subtype_id'] ?? 0);
    $name = trim((string) ($asset['subtype_name'] ?? ''));
    if ($id > 0 && $name !== '') {
        $subtypeOptions[$asset['itemtype'] . ':' . $id] = $name;
    }
}
asort($subtypeOptions, SORT_NATURAL | SORT_FLAG_CASE);

// Initial subtype is HANDHELD, but only when it exists for the selected GLPI class.
if (!array_key_exists('subtype', $_GET) && $typeFilter === 'Phone') {
    foreach ($subtypeOptions as $key => $label) {
        $normalizedLabel = mb_strtolower((string) preg_replace('/[\\s_-]+/u', '', trim($label)));
        if ($normalizedLabel === 'handheld') {
            $subtypeFilter = $key;
            break;
        }
    }
}
if ($subtypeFilter !== '' && !array_key_exists($subtypeFilter, $subtypeOptions)) {
    $subtypeFilter = '';
}

// Model options are unique model IDs and are constrained by both selected GLPI class and subtype.
$modelOptions = [];
foreach ($baseInventory as $asset) {
    $subtypeKey = ($asset['itemtype'] ?? '') . ':' . (int) ($asset['subtype_id'] ?? 0);
    $id = (int) ($asset['model_id'] ?? 0);
    $name = trim((string) ($asset['model_name'] ?? ''));
    if ($id > 0 && $name !== '' && ($subtypeFilter === '' || $subtypeFilter === $subtypeKey)) {
        $modelOptions[$asset['itemtype'] . ':' . $id] = $name;
    }
}
asort($modelOptions, SORT_NATURAL | SORT_FLAG_CASE);
if ($modelFilter !== '' && !array_key_exists($modelFilter, $modelOptions)) {
    $modelFilter = '';
}
$inventory = array_values(array_filter($baseInventory, static function (array $asset) use ($subtypeFilter, $modelFilter): bool {
    $subtypeKey = $asset['itemtype'] . ':' . (int) $asset['subtype_id'];
    $modelKey = $asset['itemtype'] . ':' . (int) $asset['model_id'];
    return ($subtypeFilter === '' || $subtypeFilter === $subtypeKey)
        && ($modelFilter === '' || $modelFilter === $modelKey);
}));
$repairAnalytics = RepairAnalytics::getExecutedRepairSummary($inventory);
$activeRepairsByAsset = RepairAnalytics::getActiveRepairAssets($inventory);
$monthlyRepairs = RepairAnalytics::getMonthlyCompletedRepairs($year, $inventory);
$maxMonthlyRepairs = max(1, ...$monthlyRepairs);
$partHistory = $repairAnalytics['part_history'];
foreach ($inventory as &$asset) {
    $key = $asset['itemtype'] . ':' . (int)$asset['id'];
    $stats = $repairAnalytics['by_asset'][$key] ?? [];
    $asset['completed_repairs'] = (int)($stats['completed_repairs'] ?? 0);
    $asset['parts_units'] = (float)($stats['parts_units'] ?? 0);
    $asset['parts_lines'] = (int)($stats['parts_lines'] ?? 0);
    $asset['last_returned_at'] = (string)($stats['last_returned_at'] ?? '');
    $asset['currently_in_repair'] = isset($activeRepairsByAsset[$key]);
    $asset['active_repair_guide'] = (string)($activeRepairsByAsset[$key]['guide_number'] ?? '');
}
unset($asset);

// Asset age is calculated from the GLPI purchase date. Five years is a review threshold,
// not an automatic replacement rule; assets without a purchase date remain unclassified.
$today = new \DateTimeImmutable('today');
$assetsFiveYearsOrOlder = 0;
$assetsWithPurchaseDate = 0;
foreach ($inventory as &$asset) {
    $asset['age_years'] = null;
    $asset['age_label'] = 'Sin fecha de compra';
    $purchaseDate = trim((string) ($asset['purchase_date'] ?? ''));
    if ($purchaseDate !== '' && $purchaseDate !== '0000-00-00') {
        try {
            $purchase = new \DateTimeImmutable($purchaseDate);
            if ($purchase <= $today) {
                $age = $purchase->diff($today);
                $asset['age_years'] = $age->y + ($age->m / 12) + ($age->d / 365.25);
                $asset['age_label'] = number_format($asset['age_years'], 1) . ' años';
                $assetsWithPurchaseDate++;
                if ($asset['age_years'] >= 5) {
                    $assetsFiveYearsOrOlder++;
                    $asset['age_label'] .= ' · Revisar renovación';
                }
            }
        } catch (\Throwable $e) {
            $asset['age_label'] = 'Fecha no válida';
        }
    }
}
unset($asset);
$months = Reports::getPointMovements($year);
$totalAssets = count($inventory);
$totalAssetsInRepair = count($activeRepairsByAsset);
$repairedAssets = count(array_filter($inventory, static fn(array $asset): bool => $asset['repair_count'] > 0));
$totalRepairs = array_sum(array_column($inventory, 'repair_count'));
$totalPoints = array_sum(array_column($inventory, 'points_consumed'));
$totalCompletedRepairs = array_sum(array_column($inventory, 'completed_repairs'));
$totalPartsUnits = array_sum(array_column($inventory, 'parts_units'));
$totalPartLines = count($partHistory);
$totalPurchased = array_sum(array_column($months, 'purchased'));
$totalConsumed = array_sum(array_column($months, 'consumed'));
$maxPoints = max(1.0, ...array_column($months, 'consumed'), ...array_column($months, 'purchased'));

$ageBuckets = ['< 1 año' => 0, '1–2 años' => 0, '3–4 años' => 0, '5–6 años' => 0, '7+ años' => 0, 'Sin fecha' => 0];
$warrantyBuckets = ['Vigente' => 0, 'Vencida' => 0, 'Sin información' => 0];
$modelRepairs = [];
$mostRepairedAssets = [];
foreach ($inventory as $asset) {
    $age = $asset['age_years'];
    if ($age === null) $ageBuckets['Sin fecha']++;
    elseif ($age < 1) $ageBuckets['< 1 año']++;
    elseif ($age < 3) $ageBuckets['1–2 años']++;
    elseif ($age < 5) $ageBuckets['3–4 años']++;
    elseif ($age < 7) $ageBuckets['5–6 años']++;
    else $ageBuckets['7+ años']++;
    $end = trim((string) ($asset['warranty_end'] ?? ''));
    if ($end === '' || $end === '0000-00-00') $warrantyBuckets['Sin información']++;
    elseif ($end >= $today->format('Y-m-d')) $warrantyBuckets['Vigente']++;
    else $warrantyBuckets['Vencida']++;
    $model = trim((string) ($asset['model_name'] ?? '')) ?: 'Modelo no especificado';
    if (!isset($modelRepairs[$model])) $modelRepairs[$model] = ['model'=>$model,'repairs'=>0];
    $modelRepairs[$model]['repairs'] += (int) ($asset['repair_count'] ?? 0);
    if ((int) ($asset['repair_count'] ?? 0) > 0) $mostRepairedAssets[] = $asset;
}
usort($modelRepairs, static fn(array $a,array $b): int => $b['repairs'] <=> $a['repairs']);
$modelRepairs = array_slice($modelRepairs, 0, 8);
usort($mostRepairedAssets, static fn(array $a,array $b): int => (int)$b['repair_count'] <=> (int)$a['repair_count']);
$mostRepairedAssets = array_slice($mostRepairedAssets, 0, 8);
$maxAgeBucket = max(1, ...array_values($ageBuckets));
$maxWarrantyBucket = max(1, ...array_values($warrantyBuckets));

if (($_GET['export'] ?? '') === 'excel') {
    $filename = 'HH_inventario_' . $year . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $output = fopen('php://output', 'wb');
    if ($output !== false) {
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['INVENTARIO Y REPARACIONES HH'], ';');
        fputcsv($output, ['Año de análisis', $year], ';');
        fputcsv($output, ['Tipo GLPI', $typeFilter, 'Tipo de activo', $subtypeFilter, 'Modelo', $modelFilter, 'Búsqueda', $search], ';');
        fputcsv($output, [], ';');
        fputcsv($output, ['Tipo GLPI', 'Tipo activo', 'Modelo', 'Activo', 'Activo fijo', 'Serie', 'Fabricante', 'Fecha compra', 'Antigüedad años', 'Fin garantía', 'Estado', 'Guía proveedor', 'Reparaciones aprobadas', 'Retornos confirmados', 'Repuestos unidades', 'Último retorno', 'Puntos consumidos'], ';');
        foreach ($inventory as $asset) {
            fputcsv($output, [
                $asset['type_name'], $asset['subtype_name'], $asset['model_name'], $asset['name'],
                $asset['otherserial'], $asset['serial'], $asset['manufacturer'], $asset['purchase_date'],
                $asset['age_years'] === null ? '' : number_format((float)$asset['age_years'], 2, '.', ''),
                $asset['warranty_end'], $asset['currently_in_repair'] ? 'En reparación' : 'En inventario',
                $asset['active_repair_guide'], $asset['repair_count'], $asset['completed_repairs'],
                number_format((float)$asset['parts_units'], 2, '.', ''), $asset['last_returned_at'],
                number_format((float)$asset['points_consumed'], 2, '.', ''),
            ], ';');
        }
        fputcsv($output, [], ';');
        fputcsv($output, ['DETALLE DE REPUESTOS CON RETORNO CONFIRMADO'], ';');
        fputcsv($output, ['Tipo GLPI', 'ID activo', 'Cotización', 'Fecha retorno', 'Código repuesto', 'Repuesto', 'Cantidad', 'Puntos'], ';');
        foreach ($partHistory as $line) {
            fputcsv($output, [
                $line['itemtype'], $line['items_id'], $line['quote_name'], $line['returned_at'],
                $line['code'], $line['description'], $line['quantity'], $line['points'],
            ], ';');
        }
        fclose($output);
    }
    exit;
}


function hhReportEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

Html::header(__('Dashboard gerencial HH', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
?>
<style>
.hh-reports{font-size:13px}.hh-reports .kpi{border:1px solid var(--tblr-border-color);border-radius:9px;padding:16px;height:100%;background:var(--tblr-bg-surface)}.hh-reports .kpi-label{color:var(--tblr-muted);font-size:12px}.hh-reports .kpi-value{font-size:1.65rem;font-weight:700;line-height:1.3}.hh-reports .chart-bars{display:grid;grid-template-columns:repeat(12,minmax(22px,1fr));gap:8px;align-items:end;height:190px;padding:10px 4px 0}.hh-reports .chart-col{display:flex;flex-direction:column;align-items:center;justify-content:end;height:100%;gap:5px}.hh-reports .chart-pair{display:flex;align-items:end;justify-content:center;gap:3px;height:145px;width:100%}.hh-reports .chart-bar{width:12px;min-height:2px;border-radius:4px 4px 0 0}.hh-reports .chart-bar.purchase{background:var(--tblr-success)}.hh-reports .chart-bar.consumption{background:var(--tblr-danger)}.hh-reports .chart-month{font-size:10px;color:var(--tblr-muted)}.hh-reports .table{font-size:12px}.hh-reports .table th,.hh-reports .table td{vertical-align:middle;white-space:nowrap}
.hh-reports .analysis-card{border:1px solid var(--tblr-border-color);border-radius:10px;background:var(--tblr-bg-surface);height:100%}
.hh-reports .analysis-head{padding:14px 16px;border-bottom:1px solid var(--tblr-border-color);font-weight:700}
.hh-reports .analysis-body{padding:14px 16px}
.hh-reports .metric-row{display:grid;grid-template-columns:95px 1fr 35px;gap:9px;align-items:center;margin:11px 0;font-size:12px}
.hh-reports .metric-track{height:11px;background:var(--tblr-bg-surface-secondary);border-radius:20px;overflow:hidden}
.hh-reports .metric-fill{height:100%;border-radius:20px;background:var(--tblr-primary)}
.hh-reports .metric-fill.warning{background:var(--tblr-warning)}.hh-reports .metric-fill.danger{background:var(--tblr-danger)}.hh-reports .metric-fill.success{background:var(--tblr-success)}
.hh-reports .rank-row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid var(--tblr-border-color);font-size:12px}
.hh-reports .rank-row:last-child{border-bottom:0}
.hh-reports .repair-month-bars{display:grid;grid-template-columns:repeat(12,minmax(22px,1fr));gap:8px;align-items:end;height:155px;padding:10px 4px 0}
.hh-reports .repair-month-col{height:100%;display:flex;flex-direction:column;justify-content:end;align-items:center;gap:6px}
.hh-reports .repair-month-bar{width:min(24px,70%);min-height:2px;border-radius:5px 5px 0 0;background:var(--tblr-primary)}
.hh-reports .repair-month-label{font-size:10px;color:var(--tblr-muted)}
@page{size:landscape;margin:10mm}

@media print{.hh-no-print,#page,.navbar,.sidebar{display:none!important}.hh-reports{font-size:10px}.hh-reports .card,.hh-reports .analysis-card{break-inside:avoid;box-shadow:none!important}.hh-reports .table-responsive{overflow:visible!important}.hh-reports .table{font-size:9px}}

</style>
<div class="container-fluid hh-reports">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h2 class="mb-1">Dashboard gerencial HH</h2><div class="text-muted">Inventario global y comportamiento de reparaciones y puntos</div></div>
  <div class="d-flex gap-2 flex-wrap hh-no-print">
   <a class="btn btn-outline-success" href="?<?=hhReportEscape(http_build_query(array_merge($_GET, ['export' => 'excel'])))?>">Exportar Excel (CSV)</a>
   <button class="btn btn-outline-danger" type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
   <a class="btn btn-outline-secondary" href="/plugins/hh/front/index.php">Volver a HH</a>
  </div>
 </div>
 <form method="get" class="card card-body mb-3 hh-no-print">
  <div class="row g-2 align-items-end">
   <div class="col-sm-2"><label class="form-label">Año de análisis</label><select class="form-select" name="year"><?php for($y=(int)date('Y');$y>=(int)date('Y')-5;$y--): ?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
   <div class="col-sm-2"><label class="form-label">Tipo de elemento GLPI</label><select class="form-select" name="itemtype"><option value="">Todos</option><?php foreach($assetTypes as $type): ?><option value="<?=hhReportEscape($type)?>" <?=$typeFilter===$type?'selected':''?>><?=hhReportEscape($type::getTypeName(1))?></option><?php endforeach; ?></select></div>
   <div class="col-sm-2"><label class="form-label">Tipo de activo</label><select class="form-select" name="subtype"><option value="">Todos</option><?php foreach($subtypeOptions as $key=>$label): ?><option value="<?=hhReportEscape($key)?>" <?=$subtypeFilter===$key?'selected':''?>><?=hhReportEscape($label)?></option><?php endforeach; ?></select></div>
   <div class="col-sm-2"><label class="form-label">Modelo</label><select class="form-select" name="model"><option value="">Todos</option><?php foreach($modelOptions as $key=>$label): ?><option value="<?=hhReportEscape($key)?>" <?=$modelFilter===$key?'selected':''?>><?=hhReportEscape($label)?></option><?php endforeach; ?></select></div>
   <div class="col-sm-2"><label class="form-label">Buscar activo</label><input class="form-control" name="q" value="<?=hhReportEscape($search)?>" placeholder="Nombre, serie o activo fijo"></div>
   <div class="col-sm-2"><button class="btn btn-primary w-100" type="submit">Aplicar</button></div>
  </div>
 </form>
 <div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Equipos actualmente en reparación</div><div class="kpi-value"><?=number_format($totalAssetsInRepair)?></div><div class="text-muted small">En proveedor, sin fecha de retorno</div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Activos en inventario</div><div class="kpi-value"><?=number_format($totalAssets)?></div><div class="text-muted small">Con filtros aplicados</div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Activos con 5+ años</div><div class="kpi-value"><?=number_format($assetsFiveYearsOrOlder)?></div><div class="text-muted small">Revisar renovación · <?=$assetsWithPurchaseDate?> con fecha de compra</div></div></div>
  <div class="col-6 col-xl-2"><div class="kpi"><div class="kpi-label">Activos con reparaciones aprobadas</div><div class="kpi-value"><?=number_format($repairedAssets)?></div><div class="text-muted small">De todos los activos consultados</div></div></div>
  <div class="col-6 col-xl-2"><div class="kpi"><div class="kpi-label">Reparaciones aprobadas</div><div class="kpi-value"><?=number_format($totalRepairs)?></div><div class="text-muted small">Histórico HH</div></div></div>
  <div class="col-6 col-xl-2"><div class="kpi"><div class="kpi-label">Puntos asociados a reparaciones</div><div class="kpi-value"><?=number_format($totalPoints,2)?></div><div class="text-muted small">Suma de cotizaciones aprobadas</div></div></div>
  <div class="col-6 col-xl-2"><div class="kpi"><div class="kpi-label">Reparaciones con retorno registrado</div><div class="kpi-value"><?=number_format($totalCompletedRepairs)?></div><div class="text-muted small">Aprobadas y con fecha de retorno</div></div></div>
  <div class="col-6 col-xl-2"><div class="kpi"><div class="kpi-label">Unidades de repuestos registradas</div><div class="kpi-value"><?=number_format($totalPartsUnits,2)?></div><div class="text-muted small"><?=number_format($totalPartLines)?> líneas de repuestos</div></div></div>
 </div>
 <div class="row g-3 mb-3">
  <div class="col-xl-8"><div class="card h-100"><div class="card-header d-flex justify-content-between"><h3 class="card-title mb-0">Consumo mensual de puntos</h3><span class="text-muted"><?=$year?></span></div><div class="card-body">
   <div class="d-flex gap-4 mb-2"><div><div class="text-muted small">Comprados</div><strong class="text-success"><?=number_format($totalPurchased,2)?> pts</strong></div><div><div class="text-muted small">Consumidos</div><strong class="text-danger"><?=number_format($totalConsumed,2)?> pts</strong></div><div><div class="text-muted small">Neto anual</div><strong><?=number_format($totalPurchased-$totalConsumed,2)?> pts</strong></div></div>
   <div class="d-flex gap-3 small mb-2"><span class="text-success">■ Compras</span><span class="text-danger">■ Consumo</span></div>
   <div class="chart-bars"><?php $labels=['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']; foreach($months as $i=>$month): $buyHeight=round(((float)$month['purchased']/$maxPoints)*145); $useHeight=round(((float)$month['consumed']/$maxPoints)*145); ?><div class="chart-col" title="Comprados: <?=number_format((float)$month['purchased'],2)?> | Consumidos: <?=number_format((float)$month['consumed'],2)?> puntos"><div class="chart-pair"><div class="chart-bar purchase" style="height:<?=$buyHeight?>px"></div><div class="chart-bar consumption" style="height:<?=$useHeight?>px"></div></div><div class="chart-month"><?=$labels[$i]?></div></div><?php endforeach; ?></div>
   <div class="table-responsive mt-3"><table class="table table-sm table-striped"><thead><tr><th>Mes</th><th class="text-end">Comprados</th><th class="text-end">Consumidos</th><th class="text-end">Neto</th><th class="text-end">Variación consumo</th></tr></thead><tbody><?php foreach($months as $i=>$month): $previous=(float)($months[$i-1]['consumed'] ?? 0); $current=(float)$month['consumed']; $delta=$current-$previous; $pct=$previous>0?($delta/$previous)*100:null; ?><tr><td><?=$labels[$i]?></td><td class="text-end"><?=number_format((float)$month['purchased'],2)?></td><td class="text-end"><?=number_format($current,2)?></td><td class="text-end"><?=number_format((float)$month['purchased']-$current,2)?></td><td class="text-end"><?php if($i===0 || ($previous==0 && $current==0)): ?>—<?php elseif($previous==0): ?>Nuevo consumo<?php else: ?><span class="<?=$delta>0?'text-danger':($delta<0?'text-success':'text-muted')?>"><?=$delta>0?'▲':($delta<0?'▼':'—')?> <?=number_format(abs((float)$pct),1)?>%</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
  </div></div></div>
  <div class="col-xl-4"><div class="card h-100"><div class="card-header"><h3 class="card-title mb-0">Cobertura del inventario</h3></div><div class="card-body">
   <div class="d-flex justify-content-between mb-2"><span>Con reparaciones aprobadas</span><strong><?=number_format($repairedAssets)?></strong></div>
   <div class="progress mb-3" style="height:12px"><div class="progress-bar bg-warning" style="width:<?=$totalAssets>0?min(100,($repairedAssets/$totalAssets)*100):0?>%"></div></div>
   <div class="d-flex justify-content-between mb-2"><span>Sin reparaciones registradas</span><strong><?=number_format(max(0,$totalAssets-$repairedAssets))?></strong></div>
   <div class="progress" style="height:12px"><div class="progress-bar bg-success" style="width:<?=$totalAssets>0?min(100,(($totalAssets-$repairedAssets)/$totalAssets)*100):0?>%"></div></div>
   <p class="text-muted small mt-3 mb-0">La ausencia de reparaciones en HH no implica que el activo nunca haya sido reparado fuera del sistema.</p>
  </div></div></div>
 </div>
 <div class="card">
  
 <div class="row g-3 mb-3">
  <div class="col-xl-4"><section class="analysis-card"><div class="analysis-head">Antigüedad del inventario</div><div class="analysis-body">
   <?php foreach($ageBuckets as $label=>$value): $width=($value/$maxAgeBucket)*100; ?><div class="metric-row"><span><?=hhReportEscape($label)?></span><div class="metric-track"><div class="metric-fill <?=$label==='7+ años'?'danger':($label==='5–6 años'?'warning':'')?>" style="width:<?=$width?>%"></div></div><strong class="text-end"><?=$value?></strong></div><?php endforeach; ?>
   <p class="text-muted small mt-3 mb-0">Calculado desde la fecha de compra registrada en GLPI.</p>
  </div></section></div>
  <div class="col-xl-4"><section class="analysis-card"><div class="analysis-head">Estado de garantía</div><div class="analysis-body">
   <?php foreach($warrantyBuckets as $label=>$value): $width=($value/$maxWarrantyBucket)*100; ?><div class="metric-row"><span><?=hhReportEscape($label)?></span><div class="metric-track"><div class="metric-fill <?=$label==='Vencida'?'danger':($label==='Vigente'?'success':'')?>" style="width:<?=$width?>%"></div></div><strong class="text-end"><?=$value?></strong></div><?php endforeach; ?>
   <p class="text-muted small mt-3 mb-0">Según fecha final de garantía de la ficha financiera.</p>
  </div></section></div>
  <div class="col-xl-4"><section class="analysis-card"><div class="analysis-head">Modelos con más reparaciones</div><div class="analysis-body">
   <?php if($modelRepairs===[]): ?><p class="text-muted small">Sin datos para este filtro.</p><?php else: foreach($modelRepairs as $i=>$model): ?><div class="rank-row"><span><?=($i+1)?>. <?=hhReportEscape($model['model'])?></span><strong><?=$model['repairs']?></strong></div><?php endforeach; endif; ?>
  </div></section></div>
 </div>
 <div class="row g-3 mb-3">
  <div class="col-xl-7"><section class="analysis-card"><div class="analysis-head">Activos con mayor historial de reparaciones</div><div class="analysis-body">
   <?php if($mostRepairedAssets===[]): ?><p class="text-muted small">No hay activos reparados en el inventario filtrado.</p><?php else: foreach($mostRepairedAssets as $i=>$asset): ?><div class="rank-row"><span><?=($i+1)?>. <strong><?=hhReportEscape($asset['name'])?></strong><span class="text-muted"> · <?=hhReportEscape($asset['model_name'])?></span></span><strong><?=$asset['repair_count']?> repar.</strong></div><?php endforeach; endif; ?>
  </div></section></div>
  <div class="col-xl-5"><section class="analysis-card"><div class="analysis-head">Alertas para evaluación de renovación</div><div class="analysis-body">
   <div class="d-flex justify-content-between mb-2"><span>Antigüedad ≥ 5 años</span><strong><?=$assetsFiveYearsOrOlder?></strong></div>
   <div class="progress mb-3" style="height:12px"><div class="progress-bar bg-warning" style="width:<?=$totalAssets>0?min(100,$assetsFiveYearsOrOlder/$totalAssets*100):0?>%"></div></div>
   <div class="d-flex justify-content-between mb-2"><span>Garantía vencida</span><strong><?=$warrantyBuckets['Vencida']?></strong></div>
   <div class="progress mb-3" style="height:12px"><div class="progress-bar bg-danger" style="width:<?=$totalAssets>0?min(100,$warrantyBuckets['Vencida']/$totalAssets*100):0?>%"></div></div>
   <div class="d-flex justify-content-between mb-2"><span>Dos o más reparaciones</span><strong><?=count(array_filter($inventory,static fn(array $a):bool=>(int)$a['repair_count']>=2))?></strong></div>
   <p class="text-muted small mt-3 mb-0">Indicadores para priorizar revisión técnica; no ordenan reemplazos automáticamente.</p>
  </div></section></div>
 </div>

 <div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Reparaciones finalizadas por mes</h3><span class="text-muted small"><?=$year?></span></div>
  <div class="card-body">
   <div class="d-flex gap-4 mb-2"><div><div class="text-muted small">Retornos confirmados</div><strong><?=number_format(array_sum($monthlyRepairs))?></strong></div><div><div class="text-muted small">Promedio mensual</div><strong><?=number_format(array_sum($monthlyRepairs)/12,1)?></strong></div></div>
   <div class="repair-month-bars"><?php foreach($monthlyRepairs as $i=>$count): $height=($count/$maxMonthlyRepairs)*112; ?><div class="repair-month-col" title="<?=$count?> reparaciones"><strong class="small"><?=$count?></strong><div class="repair-month-bar" style="height:<?=$height?>px"></div><span class="repair-month-label"><?=$labels[$i]?></span></div><?php endforeach; ?></div>
   <p class="text-muted small mb-0 mt-2">Se cuentan cotizaciones aprobadas con fecha de retorno registrada, agrupadas por mes de retorno.</p>
  </div>
 </div>
 <div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><h3 class="card-title mb-0">Inventario completo y reparaciones asociadas</h3><span class="text-muted small"><?=number_format($totalAssets)?> activos</span></div>
  <div class="table-responsive"><table class="table table-hover table-striped mb-0"><thead><tr><th>Tipo elemento GLPI</th><th>Tipo activo</th><th>Modelo</th><th>Activo</th><th>Activo fijo</th><th>Serie</th><th>Fabricante</th><th>Compra</th><th>Antigüedad</th><th>Fin garantía</th><th>Estado actual</th><th>Guía proveedor</th><th class="text-end">Cotiz. aprobadas</th><th class="text-end">Retornos</th><th class="text-end">Repuestos (unid.)</th><th>Último retorno</th><th class="text-end">Puntos aprobados</th></tr></thead><tbody>
  <?php foreach($inventory as $asset): ?><tr><td><?=hhReportEscape($asset['type_name'])?></td><td><?=hhReportEscape($asset['subtype_name'])?></td><td><?=hhReportEscape($asset['model_name'])?></td><td><?=hhReportEscape($asset['name'])?></td><td><?=hhReportEscape($asset['otherserial'])?></td><td><?=hhReportEscape($asset['serial'])?></td><td><?=hhReportEscape($asset['manufacturer'])?></td><td><?=hhReportEscape($asset['purchase_date'])?></td><td><?=hhReportEscape($asset['age_label'])?></td><td><?=hhReportEscape($asset['warranty_end'])?></td><td><?php if($asset['currently_in_repair']): ?><span class="badge bg-warning text-dark">En reparación</span><?php else: ?><span class="badge bg-success">En inventario</span><?php endif; ?></td><td><?=hhReportEscape($asset['active_repair_guide'])?></td><td class="text-end"><?=number_format((int)$asset['repair_count'])?></td><td class="text-end"><?=number_format((int)$asset['completed_repairs'])?></td><td class="text-end"><?=number_format((float)$asset['parts_units'],2)?></td><td><?=hhReportEscape($asset['last_returned_at'])?></td><td class="text-end"><?=number_format((float)$asset['points_consumed'],2)?></td></tr><?php endforeach; ?>
  <?php if($inventory===[]): ?><tr><td colspan="17" class="text-center text-muted p-4">No se encontraron activos para los filtros seleccionados.</td></tr><?php endif; ?>
  </tbody></table></div>
 </div>
 <div class="card mt-3"><div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title mb-0">Repuestos registrados en reparaciones con retorno confirmado</h3><span class="text-muted small"><?=number_format(count($partHistory))?> líneas</span></div><div class="table-responsive"><table class="table table-hover table-striped mb-0"><thead><tr><th>Activo</th><th>Cotización</th><th>Fecha retorno</th><th>Código</th><th>Repuesto</th><th class="text-end">Cantidad</th><th class="text-end">Puntos</th></tr></thead><tbody><?php foreach($partHistory as $line): $assetLabel=$line['itemtype'].' #'.$line['items_id']; foreach($inventory as $candidate){if($candidate['itemtype']===$line['itemtype']&&(int)$candidate['id']===(int)$line['items_id']){$assetLabel=$candidate['name'];break;}} ?><tr><td><?=hhReportEscape($assetLabel)?></td><td><?=hhReportEscape($line['quote_name'])?></td><td><?=hhReportEscape($line['returned_at'])?></td><td><?=hhReportEscape($line['code'])?></td><td><?=hhReportEscape($line['description'])?></td><td class="text-end"><?=number_format((float)$line['quantity'],2)?></td><td class="text-end"><?=number_format((float)$line['points'],2)?></td></tr><?php endforeach; ?><?php if($partHistory===[]): ?><tr><td colspan="7" class="text-center text-muted p-4">Todavía no hay repuestos asociados a reparaciones aprobadas con fecha de retorno registrada.</td></tr><?php endif; ?></tbody></table></div></div>
 <div class="alert alert-info mt-3 mb-0"><strong>Criterio de ejecución:</strong> una cotización aprobada se considera reparación con retorno confirmado solo cuando tiene fecha de retorno registrada. Los repuestos se contabilizan desde el detalle de esa cotización; los borradores, rechazados y aprobados sin retorno no se presentan como piezas instaladas. El inventario se puede exportar en CSV compatible con Excel y el informe completo se puede imprimir o guardar como PDF desde el navegador.</div>
</div>
<?php Html::footer(); ?>
