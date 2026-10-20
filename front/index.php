<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;

include('../../../inc/includes.php');

Session::checkLoginUser();

Html::header(__('Gestión de reparaciones', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);

$catalogUrl = '/plugins/hh/front/catalog.php';
?>
<div class="container-fluid">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Gestión de reparaciones</h2></div>
    <div class="card-body">
      <p class="text-muted">Administración de repuestos y servicios, cotizaciones e historial de reparaciones. La información de este módulo se almacena en las tablas del plugin, sin modificar las tablas nativas de GLPI.</p>
      <div class="row g-3">
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
            <p class="text-muted">La gestión de cotizaciones será el siguiente módulo que se conectará con el catálogo y los activos.</p>
            <button class="btn btn-outline-secondary mt-auto" type="button" disabled>Próximamente</button>
          </div>
        </div>
        <div class="col-sm-6 col-xl-4">
          <div class="card card-body h-100">
            <h3><i class="ti ti-history me-2"></i>Historial</h3>
            <p class="text-muted">Consulta de cotizaciones, estados y reparaciones asociadas a cada activo.</p>
            <button class="btn btn-outline-secondary mt-auto" type="button" disabled>Próximamente</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php Html::footer(); ?>
