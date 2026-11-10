<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;
use GlpiPlugin\Hh\Reports;
use GlpiPlugin\Hh\Rights;

include('../../../inc/includes.php');

Session::checkLoginUser();
Rights::require(Rights::REPORTS);

$year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT);
$year = ($year !== false && $year >= 2000 && $year <= 2100) ? (int) $year : (int) date('Y');
$search = trim((string) ($_GET['q'] ?? ''));
$typeFilter = trim((string) ($_GET['itemtype'] ?? ''));
$inventory = Reports::getInventory($search, $typeFilter !== '' ? $typeFilter : null);
$months = Reports::getPointMovements($year);
$totalAssets = count($inventory);
$repairedAssets = count(array_filter($inventory, static fn(array $asset): bool => $asset['repair_count'] > 0));
$totalRepairs = array_sum(array_column($inventory, 'repair_count'));
$totalPoints = array_sum(array_column($inventory, 'points_consumed'));
$totalPurchased = array_sum(array_column($months, 'purchased'));
$totalConsumed = array_sum(array_column($months, 'consumed'));
$maxPoints = max(1.0, ...array_column($months, 'consumed'));
$assetTypes = Reports::getAssetTypes();

function hhReportEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

Html::header(__('Dashboard gerencial HH', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
?>
<style>
.hh-reports{font-size:13px}.hh-reports .kpi{border:1px solid var(--tblr-border-color);border-radius:9px;padding:16px;height:100%;background:var(--tblr-bg-surface)}.hh-reports .kpi-label{color:var(--tblr-muted);font-size:12px}.hh-reports .kpi-value{font-size:1.65rem;font-weight:700;line-height:1.3}.hh-reports .chart-bars{display:grid;grid-template-columns:repeat(12,minmax(22px,1fr));gap:8px;align-items:end;height:190px;padding:10px 4px 0}.hh-reports .chart-col{display:flex;flex-direction:column;align-items:center;justify-content:end;height:100%;gap:5px}.hh-reports .chart-bar{width:min(100%,34px);min-height:2px;background:var(--tblr-primary);border-radius:4px 4px 0 0}.hh-reports .chart-month{font-size:10px;color:var(--tblr-muted)}.hh-reports .table{font-size:12px}.hh-reports .table th,.hh-reports .table td{vertical-align:middle;white-space:nowrap}
</style>
<div class="container-fluid hh-reports">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div><h2 class="mb-1">Dashboard gerencial HH</h2><div class="text-muted">Inventario global y comportamiento de reparaciones y puntos</div></div>
  <a class="btn btn-outline-secondary" href="/plugins/hh/front/index.php">Volver a HH</a>
 </div>
 <form method="get" class="card card-body mb-3">
  <div class="row g-2 align-items-end">
   <div class="col-sm-3"><label class="form-label">Año de análisis</label><select class="form-select" name="year"><?php for($y=(int)date('Y');$y>=(int)date('Y')-5;$y--): ?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
   <div class="col-sm-4"><label class="form-label">Tipo de activo</label><select class="form-select" name="itemtype"><option value="">Todos los tipos</option><?php foreach($assetTypes as $type): ?><option value="<?=hhReportEscape($type)?>" <?=$typeFilter===$type?'selected':''?>><?=hhReportEscape($type::getTypeName(1))?></option><?php endforeach; ?></select></div>
   <div class="col-sm-3"><label class="form-label">Buscar activo</label><input class="form-control" name="q" value="<?=hhReportEscape($search)?>" placeholder="Nombre, serie o activo fijo"></div>
   <div class="col-sm-2"><button class="btn btn-primary w-100" type="submit">Aplicar</button></div>
  </div>
 </form>
 <div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Activos en inventario</div><div class="kpi-value"><?=number_format($totalAssets)?></div><div class="text-muted small">Con filtros aplicados</div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Activos con reparaciones aprobadas</div><div class="kpi-value"><?=number_format($repairedAssets)?></div><div class="text-muted small">De todos los activos consultados</div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Reparaciones aprobadas</div><div class="kpi-value"><?=number_format($totalRepairs)?></div><div class="text-muted small">Histórico HH</div></div></div>
  <div class="col-6 col-xl-3"><div class="kpi"><div class="kpi-label">Puntos asociados a reparaciones</div><div class="kpi-value"><?=number_format($totalPoints,2)?></div><div class="text-muted small">Suma de cotizaciones aprobadas</div></div></div>
 </div>
 <div class="row g-3 mb-3">
  <div class="col-xl-8"><div class="card h-100"><div class="card-header d-flex justify-content-between"><h3 class="card-title mb-0">Consumo mensual de puntos</h3><span class="text-muted"><?=$year?></span></div><div class="card-body">
   <div class="d-flex gap-4 mb-2"><div><div class="text-muted small">Comprados</div><strong class="text-success"><?=number_format($totalPurchased,2)?> pts</strong></div><div><div class="text-muted small">Consumidos</div><strong class="text-danger"><?=number_format($totalConsumed,2)?> pts</strong></div></div>
   <div class="chart-bars"><?php $labels=['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']; foreach($months as $i=>$month): $height=round(((float)$month['consumed']/$maxPoints)*145); ?><div class="chart-col" title="<?=number_format((float)$month['consumed'],2)?> puntos"><div class="small text-muted"><?=number_format((float)$month['consumed'],0)?></div><div class="chart-bar" style="height:<?=$height?>px"></div><div class="chart-month"><?=$labels[$i]?></div></div><?php endforeach; ?></div>
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
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><h3 class="card-title mb-0">Inventario completo y reparaciones asociadas</h3><span class="text-muted small"><?=number_format($totalAssets)?> activos</span></div>
  <div class="table-responsive"><table class="table table-hover table-striped mb-0"><thead><tr><th>Tipo</th><th>Activo</th><th>Activo fijo</th><th>Serie</th><th>Fabricante</th><th>Compra</th><th>Fin garantía</th><th class="text-end">Reparaciones</th><th class="text-end">Puntos aprobados</th></tr></thead><tbody>
  <?php foreach($inventory as $asset): ?><tr><td><?=hhReportEscape($asset['type_name'])?></td><td><?=hhReportEscape($asset['name'])?></td><td><?=hhReportEscape($asset['otherserial'])?></td><td><?=hhReportEscape($asset['serial'])?></td><td><?=hhReportEscape($asset['manufacturer'])?></td><td><?=hhReportEscape($asset['purchase_date'])?></td><td><?=hhReportEscape($asset['warranty_end'])?></td><td class="text-end"><?=number_format((int)$asset['repair_count'])?></td><td class="text-end"><?=number_format((float)$asset['points_consumed'],2)?></td></tr><?php endforeach; ?>
  <?php if($inventory===[]): ?><tr><td colspan="9" class="text-center text-muted p-4">No se encontraron activos para los filtros seleccionados.</td></tr><?php endif; ?>
  </tbody></table></div>
 </div>
 <div class="alert alert-info mt-3 mb-0"><strong>Alcance de esta primera entrega:</strong> consulta de activos registrados en los tipos habilitados en GLPI, incluidas las definiciones personalizadas, y cruce con cotizaciones aprobadas de HH. Las exportaciones Excel/PDF, análisis de renovación y filtros financieros avanzados se incorporarán en las siguientes etapas.</div>
</div>
<?php Html::footer(); ?>
