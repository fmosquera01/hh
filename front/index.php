<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;
use GlpiPlugin\Hh\Rights;

include('../../../inc/includes.php');

Session::checkLoginUser();
Rights::require(Rights::READ);

Html::header(__('Gestión de activos y reparaciones', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);

$catalogUrl = '/plugins/hh/front/catalog.php';
$reportsAllowed = Rights::can(Rights::REPORTS);
?>
<div class="container-fluid">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Gestión de activos y reparaciones</h2></div>
    <div class="card-body">
      <p class="text-muted">Control general de reparaciones para activos nativos y personalizados. El módulo especializado HH conserva su esquema de puntos, garantías, contratos y cotizaciones.</p>
      <div class="row g-3">
        <?php if ($reportsAllowed): ?>
        <div class="col-sm-6 col-xl-4">
          <div class="card card-body h-100">
            <h3><i class="ti ti-chart-bar me-2"></i>Dashboard gerencial de activos</h3>
            <p class="text-muted">Inventario completo, reparaciones aprobadas y análisis mensual de puntos.</p>
            <a class="btn btn-primary mt-auto" href="/plugins/hh/front/reports.php">Ver reportes</a>
          </div>
        </div>
        <?php endif; ?>
        <?php if (Rights::can(Rights::CATALOG)): ?>
        <div class="col-sm-6 col-xl-4">
          <div class="card card-body h-100">
            <h3><i class="ti ti-settings me-2"></i>Flujo general de reparación</h3>
            <p class="text-muted">Seleccione el origen FormularioReparacion y active el cambio inmediato de estado.</p>
            <a class="btn btn-primary mt-auto" href="/plugins/hh/front/config.php">Configurar flujo</a>
          </div>
        </div>
        <?php endif; ?>
        <div class="col-sm-6 col-xl-4">
          <div class="card card-body h-100">
            <h3><i class="ti ti-package me-2"></i>Catálogo de repuestos y servicios</h3>
            <p class="text-muted">Registre artículos, asigne un código automático, defina puntos y especifique para qué tipo/modelo de activo aplica cada artículo.</p>
            <div class="mt-auto"><a class="btn btn-primary" href="<?= htmlspecialchars($catalogUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Administrar catálogo</a></div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-4">
          <div class="card card-body h-100">
            <h3><i class="ti ti-file-invoice me-2"></i>Cotizaciones</h3>
            <p class="text-muted">Cree cotizaciones por activo, agregue repuestos y servicios, calcule puntos y montos, y registre la decisión final.</p>
            <div class="mt-auto"><a class="btn btn-primary" href="/plugins/hh/front/quotes.php">Administrar cotizaciones</a></div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-4">
          <div class="card card-body h-100">
            <h3><i class="ti ti-coins me-2"></i>Puntos</h3>
            <p class="text-muted">Registre compras de puntos, consumos históricos y consulte el saldo disponible y todos sus movimientos.</p>
            <a class="btn btn-primary mt-auto" href="/plugins/hh/front/points.php">Administrar puntos</a>
          </div>
        </div>
        <div class="col-sm-6 col-xl-4">
          <div class="card card-body h-100">
            <h3><i class="ti ti-history me-2"></i>Historial</h3>
            <p class="text-muted">Consulta de cotizaciones, estados y reparaciones asociadas a cada activo.</p>
            <a class="btn btn-primary mt-auto" href="/plugins/hh/front/history.php">Consultar historial</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php Html::footer(); ?>
