<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Rights;
use GlpiPlugin\Hh\RepairWorkflow;

include('../../../inc/includes.php');

Session::checkLoginUser();
global $DB, $CFG_GLPI;

$itemtype = trim((string) ($_REQUEST['itemtype'] ?? ''));
$itemsId = filter_var($_REQUEST['items_id'] ?? 0, FILTER_VALIDATE_INT);
$embedded = (int) ($_REQUEST['embedded'] ?? 0) === 1;
$allowedTypes = $CFG_GLPI['asset_types'] ?? [];

if ($itemsId === false || $itemsId < 1 || !in_array($itemtype, $allowedTypes, true)
    || !class_exists($itemtype) || in_array($itemtype, ['Computer', 'Phone'], true)) {
    Html::displayErrorAndDie('Activo no válido para cotización general.');
}
$asset = new $itemtype();
if (!$asset->getFromDB((int) $itemsId) || !$asset->can((int) $itemsId, READ)) {
    Html::displayRightError();
}
$assetName = (string) ($asset->fields['name'] ?? ($itemtype . ' #' . $itemsId));
$downloadId = filter_var($_GET['download'] ?? 0, FILTER_VALIDATE_INT);
if ($downloadId !== false && $downloadId > 0) {
    if (!Rights::can(Rights::READ)) { Html::displayRightError(); }
    $document = null;
    foreach ($DB->request([
        'SELECT' => ['attachment_path', 'attachment_name'],
        'FROM' => 'glpi_plugin_hh_general_quotes',
        'WHERE' => ['id' => (int) $downloadId, 'itemtype' => $itemtype, 'items_id' => (int) $itemsId],
        'LIMIT' => 1,
    ]) as $row) { $document = $row; break; }
    if (!$document || empty($document['attachment_path'])) { Html::displayErrorAndDie('Documento no encontrado.'); }
    $baseDirectory = realpath(GLPI_VAR_DIR . '/_plugins/hh/general_quotes');
    $filePath = realpath(GLPI_VAR_DIR . '/' . ltrim((string) $document['attachment_path'], '/'));
    if ($baseDirectory === false || $filePath === false || !str_starts_with($filePath, $baseDirectory . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
        Html::displayErrorAndDie('El documento no está disponible.');
    }
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$extension] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($filePath));
    header('Content-Disposition: inline; filename="' . rawurlencode((string) $document['attachment_name']) . '"');
    readfile($filePath);
    exit;
}

$backUrl = '/plugins/hh/front/general_quotes.php?embedded=1&itemtype=' . rawurlencode($itemtype) . '&items_id=' . (int) $itemsId;
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($value): string => '$' . number_format((float) $value, 2, '.', ',');

function hhGeneralQuoteHistory($quoteId, $old, $new, $comment = ''): void
{
    global $DB;
    $DB->insert('glpi_plugin_hh_general_quotehistory', [
        'general_quotes_id' => (int) $quoteId,
        'old_status' => $old !== '' ? $old : null,
        'new_status' => $new,
        'users_id' => (int) Session::getLoginUserID(),
        'comment' => $comment !== '' ? $comment : null,
        'date_creation' => date('Y-m-d H:i:s'),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkCSRF($_POST);
    $action = trim((string) ($_POST['action'] ?? ''));
    $quoteId = max(0, (int) ($_POST['quote_id'] ?? 0));
    $quote = null;
    if ($quoteId > 0) {
        foreach ($DB->request([
            'SELECT' => '*', 'FROM' => 'glpi_plugin_hh_general_quotes',
            'WHERE' => ['id' => $quoteId, 'itemtype' => $itemtype, 'items_id' => (int) $itemsId], 'LIMIT' => 1,
        ]) as $row) { $quote = $row; break; }
        if (!$quote) { Html::displayErrorAndDie('La cotización no pertenece a este activo.'); }
    }

    if ($action === 'save') {
        $isNew = $quoteId === 0;
        if (($isNew && !Rights::can(Rights::CREATE)) || (!$isNew && (!Rights::can(Rights::UPDATE) || ($quote['status'] ?? '') !== 'draft'))) {
            Html::displayRightError();
        }
        $mode = (string) ($_POST['quote_mode'] ?? 'global');
        if (!in_array($mode, ['global', 'detailed'], true)) { $mode = 'global'; }
        $supplierId = max(0, (int) ($_POST['suppliers_id'] ?? 0));
        $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
        if ($supplierId > 0) {
            foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_suppliers', 'WHERE' => ['id' => $supplierId], 'LIMIT' => 1]) as $supplier) {
                $supplierName = (string) $supplier['name']; break;
            }
        }
        $quoteNumber = trim((string) ($_POST['quote_number'] ?? ''));
        $quoteDate = trim((string) ($_POST['quote_date'] ?? date('Y-m-d')));
        $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'USD')));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
        $totalInput = trim((string) ($_POST['total_amount'] ?? ''));
        $total = is_numeric($totalInput) ? (float) $totalInput : 0.0;
        $discount = max(0, (float) ($_POST['discount'] ?? 0));
        $tax = max(0, (float) ($_POST['tax'] ?? 0));
        $transport = max(0, (float) ($_POST['transport'] ?? 0));
        $notes = trim((string) ($_POST['observations'] ?? ''));
        $lines = [];
        $lineSum = 0.0;
        if ($mode === 'detailed') {
            $descriptions = (array) ($_POST['line_description'] ?? []);
            $quantities = (array) ($_POST['line_quantity'] ?? []);
            $prices = (array) ($_POST['line_price'] ?? []);
            foreach ($descriptions as $index => $lineDescription) {
                $lineDescription = trim((string) $lineDescription);
                if ($lineDescription === '') { continue; }
                $quantity = max(0.01, (float) ($quantities[$index] ?? 1));
                $priceRaw = trim((string) ($prices[$index] ?? ''));
                $unitPrice = $priceRaw !== '' && is_numeric($priceRaw) ? max(0, (float) $priceRaw) : null;
                $lineTotal = $unitPrice === null ? null : round($quantity * $unitPrice, 2);
                if ($lineTotal !== null) { $lineSum += $lineTotal; }
                $lines[] = ['description' => mb_substr($lineDescription, 0, 255), 'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_total' => $lineTotal];
            }
        }
        if ($supplierName === '' || $description === '' || $quoteDate === '') {
            Session::addMessageAfterRedirect('Indique proveedor, fecha y descripción de la reparación.', false, ERROR);
            Html::redirect($backUrl);
        }
        if ($totalInput === '' && $mode === 'detailed' && count($lines) > 0 && count(array_filter($lines, static fn($line) => $line['line_total'] === null)) === 0) {
            $total = $lineSum;
        }
        if ($total <= 0) {
            Session::addMessageAfterRedirect('Ingrese el total de la cotización. Si el proveedor no desglosa los valores, utilice modalidad Importe global.', false, ERROR);
            Html::redirect($backUrl);
        }
        $total = round(max(0, $total - $discount + $tax + $transport), 2);
        $data = [
            'itemtype' => $itemtype, 'items_id' => (int) $itemsId, 'quote_number' => $quoteNumber ?: null,
            'suppliers_id' => $supplierId ?: null, 'supplier_name_snapshot' => mb_substr($supplierName, 0, 180),
            'quote_date' => $quoteDate, 'valid_until' => $validUntil ?: null, 'currency' => $currency,
            'quote_mode' => $mode, 'description' => mb_substr($description, 0, 255),
            'subtotal' => round($mode === 'detailed' && $lineSum > 0 ? $lineSum : (float) ($totalInput !== '' ? $totalInput : $total), 2),
            'discount' => $discount, 'tax' => $tax, 'transport' => $transport, 'total_amount' => $total,
            'observations' => $notes, 'users_id' => (int) Session::getLoginUserID(),
        ];
        if ($isNew) {
            $data['status'] = 'draft';
            $data['date_creation'] = date('Y-m-d H:i:s');
            $DB->insert('glpi_plugin_hh_general_quotes', $data);
            $quoteId = (int) $DB->insertId();
            hhGeneralQuoteHistory($quoteId, '', 'draft', 'Cotización creada');
        } else {
            $DB->update('glpi_plugin_hh_general_quotes', $data, ['id' => $quoteId]);
            $DB->delete('glpi_plugin_hh_general_quotelines', ['general_quotes_id' => $quoteId]);
        }
        foreach ($lines as $line) {
            $DB->insert('glpi_plugin_hh_general_quotelines', [
                'general_quotes_id' => $quoteId, 'description' => $line['description'],
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
            ]);
        }
        // Optional original supplier document. Only safe document formats are accepted.
        if (isset($_FILES['quote_file']) && (int) ($_FILES['quote_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string) $_FILES['quote_file']['tmp_name'];
            $original = basename((string) $_FILES['quote_file']['name']);
            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg'];
            if (in_array($extension, $allowedExtensions, true) && is_uploaded_file($tmp) && (int) $_FILES['quote_file']['size'] <= 12000000) {
                $directory = GLPI_VAR_DIR . '/_plugins/hh/general_quotes';
                if (!is_dir($directory)) { @mkdir($directory, 0770, true); }
                if (is_dir($directory) && is_writable($directory)) {
                    $stored = $quoteId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                    if (move_uploaded_file($tmp, $directory . '/' . $stored)) {
                        $DB->update('glpi_plugin_hh_general_quotes', [
                            'attachment_path' => '_plugins/hh/general_quotes/' . $stored,
                            'attachment_name' => mb_substr($original, 0, 255),
                        ], ['id' => $quoteId]);
                    }
                }
            }
        }
        Session::addMessageAfterRedirect('Cotización guardada correctamente.', true, INFO);
        Html::redirect($backUrl);
    }

    if (!$quote || !Rights::can(Rights::APPROVE)) { Html::displayRightError(); }
    $oldStatus = (string) $quote['status'];
    if ($action === 'submit' && $oldStatus === 'draft') {
        $newStatus = 'pending';
    } elseif ($action === 'approve' && $oldStatus === 'pending') {
        $newStatus = 'approved';
    } elseif ($action === 'reject' && $oldStatus === 'pending') {
        $newStatus = 'rejected';
    } elseif ($action === 'select' && $oldStatus === 'approved') {
        $newStatus = 'selected';
    } elseif ($action === 'cancel' && in_array($oldStatus, ['draft', 'pending', 'approved'], true)) {
        $newStatus = 'cancelled';
    } else {
        Html::displayErrorAndDie('Transición de cotización no permitida.');
    }
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if ($action === 'select' && $comment === '') {
        Session::addMessageAfterRedirect('Indique el motivo por el que se selecciona este proveedor.', false, ERROR);
        Html::redirect($backUrl);
    }
    if ($action === 'select') {
        // Only one selected quote per asset; preserve all other quote decisions.
        $DB->update('glpi_plugin_hh_general_quotes', ['status' => 'approved'], [
            'itemtype' => $itemtype, 'items_id' => (int) $itemsId, 'status' => 'selected',
        ]);
        $DB->update('glpi_plugin_hh_general_quotes', ['selection_reason' => $comment], ['id' => $quoteId]);
    }
    $DB->update('glpi_plugin_hh_general_quotes', [
        'status' => $newStatus,
        'approved_by' => in_array($newStatus, ['approved', 'selected'], true) ? (int) Session::getLoginUserID() : (int) ($quote['approved_by'] ?? 0),
        'approved_at' => in_array($newStatus, ['approved', 'selected'], true) ? date('Y-m-d H:i:s') : ($quote['approved_at'] ?? null),
    ], ['id' => $quoteId]);
    hhGeneralQuoteHistory($quoteId, $oldStatus, $newStatus, $comment);
    Session::addMessageAfterRedirect('Estado de cotización actualizado.', true, INFO);
    Html::redirect($backUrl);
}

$quotes = [];
foreach ($DB->request([
    'SELECT' => '*', 'FROM' => 'glpi_plugin_hh_general_quotes',
    'WHERE' => ['itemtype' => $itemtype, 'items_id' => (int) $itemsId],
    'ORDER' => ['date_creation DESC', 'id DESC'],
]) as $row) { $quotes[] = $row; }
$suppliers = [];
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_suppliers', 'WHERE' => ['is_deleted' => 0], 'ORDER' => ['name']]) as $row) { $suppliers[] = $row; }

if (!$embedded) { Html::header('Cotizaciones de reparación', $_SERVER['PHP_SELF'], 'assets', 'hh'); }
?>
<style>
.hh-gq{font-size:12px;line-height:1.3;color:var(--tblr-body-color);padding:.35rem}
.hh-gq .card{margin-bottom:.55rem}.hh-gq .card-header{padding:.5rem .7rem}.hh-gq .card-body{padding:.65rem .7rem}
.hh-gq h3{font-size:.92rem;margin:0}.hh-gq label{font-size:.72rem;font-weight:600;margin-bottom:.18rem}
.hh-gq .form-control,.hh-gq .form-select{font-size:.78rem;min-height:30px;padding:.28rem .45rem}
.hh-gq .table{font-size:.72rem}.hh-gq .btn{font-size:.74rem;padding:.3rem .55rem}
.hh-gq .quote-card{border-left:3px solid var(--tblr-primary)}
.hh-gq .status{font-size:.65rem;padding:.15rem .35rem;border-radius:1rem;background:var(--tblr-bg-surface-secondary)}
.hh-gq .line-row{display:grid;grid-template-columns:minmax(120px,2fr) 70px 95px 28px;gap:.3rem;margin-bottom:.3rem}
@media(max-width:560px){.hh-gq .line-row{grid-template-columns:1fr 1fr}.hh-gq .line-row .line-description{grid-column:1/-1}}
</style>
<div class="hh-gq">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div><h3>Cotizaciones de reparación</h3><div class="text-muted small"><?= $e($assetName) ?> · <?= $e($itemtype) ?> #<?= (int) $itemsId ?></div></div>
    <?php if (Rights::can(Rights::CREATE) || Rights::can(Rights::UPDATE)): ?>
      <button class="btn btn-primary" type="button" onclick="document.getElementById('gq-new').hidden=false;this.hidden=true">Nueva cotización</button>
    <?php endif; ?>
  </div>
  <section class="card" id="gq-new" hidden>
    <div class="card-header"><h3>Nueva cotización · Proveedor</h3></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>">
        <input type="hidden" name="action" value="save"><input type="hidden" name="itemtype" value="<?= $e($itemtype) ?>"><input type="hidden" name="items_id" value="<?= (int) $itemsId ?>">
        <div class="row g-2">
          <div class="col-12 col-md-6"><label>Proveedor registrado en GLPI</label><select class="form-select" name="suppliers_id"><option value="0">Seleccionar o escribir proveedor</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>"><?= $e($supplier['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-12 col-md-6"><label>Proveedor (si no está en GLPI)</label><input class="form-control" name="supplier_name" maxlength="180"></div>
          <div class="col-6 col-md-3"><label>N.º cotización</label><input class="form-control" name="quote_number" maxlength="100"></div>
          <div class="col-6 col-md-3"><label>Fecha</label><input class="form-control" type="date" name="quote_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-6 col-md-3"><label>Vigencia hasta</label><input class="form-control" type="date" name="valid_until"></div>
          <div class="col-6 col-md-3"><label>Moneda</label><select class="form-select" name="currency"><option>USD</option><option>EUR</option><option>COP</option><option>PEN</option></select></div>
          <div class="col-12"><label>Descripción de la reparación / alcance</label><input class="form-control" name="description" maxlength="255" required placeholder="Ej. Reparación de monitor, cambio de panel..."></div>
          <div class="col-12"><label>Formato recibido del proveedor</label><select class="form-select" name="quote_mode" id="gq-mode" onchange="document.getElementById('gq-lines').hidden=this.value!=='detailed'"><option value="global">Importe global (sin desglose)</option><option value="detailed">Detalle libre de conceptos</option></select></div>
          <div class="col-12" id="gq-lines" hidden><label>Conceptos (no requiere catálogo ni códigos)</label><div id="gq-line-list"><div class="line-row"><input class="form-control line-description" name="line_description[]" placeholder="Concepto o trabajo"><input class="form-control" name="line_quantity[]" type="number" min="0.01" step="0.01" value="1" placeholder="Cant."><input class="form-control" name="line_price[]" type="number" min="0" step="0.01" placeholder="Precio unitario (opcional)"><span></span></div></div><button class="btn btn-outline-secondary" type="button" onclick="const n=document.querySelector('.line-row').cloneNode(true);n.querySelectorAll('input').forEach(i=>i.value='');document.getElementById('gq-line-list').appendChild(n)">Agregar concepto</button></div>
          <div class="col-6 col-md-3"><label>Subtotal / importe informado</label><input class="form-control" name="total_amount" type="number" min="0.01" step="0.01" required placeholder="0.00"></div>
          <div class="col-6 col-md-3"><label>Descuento</label><input class="form-control" name="discount" type="number" min="0" step="0.01" value="0"></div>
          <div class="col-6 col-md-3"><label>Impuestos</label><input class="form-control" name="tax" type="number" min="0" step="0.01" value="0"></div>
          <div class="col-6 col-md-3"><label>Transporte / otros</label><input class="form-control" name="transport" type="number" min="0" step="0.01" value="0"></div>
          <div class="col-12"><label>Observaciones y condiciones</label><textarea class="form-control" name="observations" rows="2"></textarea></div>
          <div class="col-12"><label>Documento original (PDF, JPG o PNG; máximo 12 MB)</label><input class="form-control" type="file" name="quote_file" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div>
        <div class="d-flex gap-2 mt-3"><button class="btn btn-primary" type="submit">Guardar borrador</button><button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('gq-new').hidden=true">Cancelar</button></div>
      </form>
    </div>
  </section>
  <?php if (!$quotes): ?><div class="alert alert-light border small">Todavía no hay cotizaciones para este activo. Puedes registrar una aunque no exista ticket ni esté en estado de reparación.</div><?php endif; ?>
  <?php foreach ($quotes as $q): ?>
    <article class="card quote-card"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-2"><div><strong><?= $e($q['supplier_name_snapshot']) ?></strong> <span class="status"><?= $e(strtoupper((string) $q['status'])) ?></span><div class="text-muted small"><?= $e($q['quote_number'] ?: 'Sin número') ?> · <?= $e($q['quote_date']) ?> · <?= $e($q['description']) ?></div></div><div class="text-end"><strong><?= $e($q['currency']) ?> <?= number_format((float) $q['total_amount'], 2) ?></strong><div class="text-muted small"><?= $q['quote_mode'] === 'global' ? 'Importe global' : 'Detalle libre' ?></div></div></div>
      <?php if (!empty($q['attachment_path'])): ?><div class="small mt-2"><a href="<?= $e($backUrl . '&download=' . (int) $q['id']) ?>" target="_blank" rel="noopener">Ver documento del proveedor: <?= $e($q['attachment_name']) ?></a></div><?php endif; ?>
      <?php if ($q['status'] === 'draft' && Rights::can(Rights::UPDATE)): ?><form method="post" class="mt-2"><input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>"><input type="hidden" name="itemtype" value="<?= $e($itemtype) ?>"><input type="hidden" name="items_id" value="<?= (int) $itemsId ?>"><input type="hidden" name="quote_id" value="<?= (int) $q['id'] ?>"><button class="btn btn-sm btn-outline-primary" name="action" value="submit">Enviar a aprobación</button></form><?php endif; ?>
      <?php if ($q['status'] === 'pending' && Rights::can(Rights::APPROVE)): ?><form method="post" class="d-flex flex-wrap gap-2 mt-2"><input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>"><input type="hidden" name="itemtype" value="<?= $e($itemtype) ?>"><input type="hidden" name="items_id" value="<?= (int) $itemsId ?>"><input type="hidden" name="quote_id" value="<?= (int) $q['id'] ?>"><input class="form-control form-control-sm" name="comment" placeholder="Comentario de aprobación / rechazo"><button class="btn btn-sm btn-success" name="action" value="approve">Aprobar</button><button class="btn btn-sm btn-outline-danger" name="action" value="reject">Rechazar</button></form><?php endif; ?>
      <?php if ($q['status'] === 'approved' && Rights::can(Rights::APPROVE)): ?><form method="post" class="mt-2"><input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>"><input type="hidden" name="itemtype" value="<?= $e($itemtype) ?>"><input type="hidden" name="items_id" value="<?= (int) $itemsId ?>"><input type="hidden" name="quote_id" value="<?= (int) $q['id'] ?>"><input class="form-control form-control-sm mb-1" name="comment" placeholder="Motivo de selección del proveedor" required><button class="btn btn-sm btn-primary" name="action" value="select">Seleccionar proveedor</button></form><?php endif; ?>
      <?php if ($q['status'] === 'selected'): ?><div class="small text-success mt-2">Proveedor seleccionado · <?= $e($q['selection_reason']) ?></div><?php endif; ?>
    </div></article>
  <?php endforeach; ?>
</div>
<?php if (!$embedded) { Html::footer(); } ?>
