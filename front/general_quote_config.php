<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;
use GlpiPlugin\Hh\Rights;

include('../../../inc/includes.php');

Session::checkLoginUser();
Rights::require(Rights::POINT_VALUE);
global $DB;

$message = '';
$error = '';
$currentRate = 15.0;
foreach ($DB->request(['SELECT' => ['iva_rate'], 'FROM' => 'glpi_plugin_hh_general_quote_config', 'WHERE' => ['id' => 1], 'LIMIT' => 1]) as $row) {
    $currentRate = (float) $row['iva_rate'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rate = filter_var($_POST['iva_rate'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($rate === false || $rate < 0 || $rate > 100) {
        $error = 'Ingrese una tasa de IVA entre 0 y 100 %.';
    } else {
        $DB->update('glpi_plugin_hh_general_quote_config', [
            'iva_rate' => number_format((float) $rate, 3, '.', ''),
            'users_id' => (int) Session::getLoginUserID(),
        ], ['id' => 1]);
        $currentRate = (float) $rate;
        $message = 'Configuración de IVA guardada. Las nuevas cotizaciones utilizarán esta tasa; las anteriores conservarán su porcentaje.';
    }
}

Html::header('Configuración de IVA', $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
?>
<style>
.hh-iva{max-width:850px;margin:0 auto;font-size:13px}.hh-iva .hero{background:linear-gradient(120deg,#17365d,#245b83);color:#fff;border:0;border-radius:10px}
.hh-iva .hero .text-muted{color:#dce8f2!important}.hh-iva .card{border:1px solid #dce3eb;border-radius:9px;box-shadow:0 2px 8px #172b4d0d;overflow:hidden}
.hh-iva .card-header{background:#f4f7fb;padding:.7rem .9rem}.hh-iva .card-body{padding:.9rem}.hh-iva label{font-size:12px;font-weight:700}
.hh-iva .form-control{max-width:220px;font-size:14px}.hh-iva .iva-value{font-size:2rem;font-weight:800;letter-spacing:-.04em}
</style>
<div class="container-fluid hh-iva">
  <div class="card hero mb-3"><div class="card-body">
    <div class="small text-uppercase">Songa · Gestión de reparaciones</div>
    <h2 class="mb-1">Configuración de IVA</h2>
    <div class="text-muted small">Tasa aplicada automáticamente a las cotizaciones generales de activos que no son Handheld.</div>
  </div></div>
  <div class="card"><div class="card-header"><strong>Impuesto al valor agregado</strong></div><div class="card-body">
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
      <div class="mb-3"><label for="iva_rate" class="form-label">Porcentaje de IVA (%)</label>
        <input class="form-control" id="iva_rate" name="iva_rate" type="number" min="0" max="100" step="0.001" value="<?= htmlspecialchars(number_format($currentRate, 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required>
        <div class="form-hint">Valor inicial: 15 %. Puedes modificarlo cuando cambie la tasa tributaria.</div>
      </div>
      <div class="rounded border p-3 mb-3">
        <div class="text-muted small">Ejemplo de cálculo con subtotal de $100,00</div>
        <div class="iva-value text-primary"><?= number_format($currentRate, 2) ?> %</div>
        <div>Base imponible: <strong>$100,00</strong></div>
        <div>IVA: <strong>$<?= number_format(100 * $currentRate / 100, 2, '.', ',') ?></strong></div>
        <div>Total: <strong>$<?= number_format(100 + 100 * $currentRate / 100, 2, '.', ',') ?></strong></div>
      </div>
      <button class="btn btn-primary" type="submit">Guardar tasa de IVA</button>
      <a class="btn btn-secondary" href="/plugins/hh/front/index.php">Volver</a>
    </form>
  </div></div>
</div>
<?php Html::footer(); ?>
