<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Rights;
use GlpiPlugin\Hh\RepairWorkflow;
use GlpiPlugin\Hh\Reports;

include('../../../inc/includes.php');

Session::checkLoginUser();
global $DB, $CFG_GLPI;

$itemtype = trim((string) ($_REQUEST['itemtype'] ?? ''));
$itemsId = filter_var($_REQUEST['items_id'] ?? 0, FILTER_VALIDATE_INT);
$embedded = (int) ($_REQUEST['embedded'] ?? 0) === 1;
$allowedTypes = $CFG_GLPI['asset_types'] ?? [];

if ($itemsId === false || $itemsId < 1 || !in_array($itemtype, $allowedTypes, true)
    || !class_exists($itemtype)) {
    Html::displayErrorAndDie('Activo no válido para cotización general.');
}
$asset = new $itemtype();
if (!$asset->getFromDB((int) $itemsId) || !$asset->can((int) $itemsId, READ)) {
    Html::displayRightError();
}
if ($itemtype === 'Phone') {
    $phoneTypeId = (int) ($asset->fields['phonetypes_id'] ?? 0);
    if (RepairWorkflow::normalize(Reports::dropdownName('glpi_phonetypes', $phoneTypeId)) === 'handheld') {
        Html::displayErrorAndDie('Los Handheld utilizan el flujo especializado de puntos y no admiten cotizaciones generales.');
    }
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

$view = (string) ($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'drafts', 'history'], true)) { $view = 'all'; }
$backUrl = '/plugins/hh/front/general_quotes.php?embedded=1&itemtype=' . rawurlencode($itemtype) . '&items_id=' . (int) $itemsId . '&view=' . rawurlencode($view);
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($value): string => '$' . number_format((float) $value, 2, '.', ',');
$ivaRate = 15.0;
foreach ($DB->request(['SELECT' => ['iva_rate'], 'FROM' => 'glpi_plugin_hh_general_quote_config', 'WHERE' => ['id' => 1], 'LIMIT' => 1]) as $ivaConfig) {
    $ivaRate = max(0.0, min(100.0, (float) $ivaConfig['iva_rate']));
}

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
        $supplierId = filter_var($_POST['suppliers_id'] ?? '', FILTER_VALIDATE_INT);
        $supplierName = '';
        if ($supplierId !== false && $supplierId > 0) {
            foreach ($DB->request([
                'SELECT' => ['name'],
                'FROM' => 'glpi_suppliers',
                'WHERE' => ['id' => (int) $supplierId, 'is_deleted' => 0],
                'LIMIT' => 1,
            ]) as $supplier) {
                $supplierName = (string) $supplier['name'];
                break;
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
        // IVA is calculated server-side using the current configured rate.
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
        if ($supplierId === false || $supplierId < 1 || $supplierName === '' || $description === '' || $quoteDate === '') {
            Session::addMessageAfterRedirect('Seleccione un proveedor registrado en GLPI e indique fecha y descripción de la reparación.', false, ERROR);
            Html::redirect($backUrl);
        }
        $subtotal = $total;
        if ($totalInput === '' && $mode === 'detailed' && count($lines) > 0) {
            $unpricedLines = count(array_filter($lines, static fn($line) => $line['line_total'] === null));
            $subtotal = $unpricedLines === 0 ? $lineSum : 0.0;
        }
        if ($subtotal <= 0) {
            Session::addMessageAfterRedirect('Ingrese el total de la cotización. Si el proveedor no desglosa los valores, utilice modalidad Importe global.', false, ERROR);
            Html::redirect($backUrl);
        }
        // La cotización del proveedor se registra sin IVA; los impuestos se calculan
        // posteriormente en el proceso de compra/facturación.
        $taxableBase = round(max(0, $subtotal - $discount + $transport), 2);
        $tax = 0.0;
        $total = $taxableBase;
        $data = [
            'itemtype' => $itemtype, 'items_id' => (int) $itemsId, 'quote_number' => $quoteNumber ?: null,
            'suppliers_id' => $supplierId ?: null, 'supplier_name_snapshot' => mb_substr($supplierName, 0, 180),
            'quote_date' => $quoteDate, 'valid_until' => $validUntil ?: null, 'currency' => $currency,
            'quote_mode' => $mode, 'description' => mb_substr($description, 0, 255),
            'subtotal' => round($subtotal, 2),
            'discount' => $discount, 'tax' => 0, 'iva_rate_snapshot' => 0, 'transport' => $transport, 'total_amount' => $total,
            'observations' => $notes,
        ];
        if ($isNew) {
            $data['users_id'] = (int) Session::getLoginUserID();
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

    if (!$quote) { Html::displayErrorAndDie('Cotización no encontrada.'); }
    if (($action === 'submit' && !Rights::can(Rights::UPDATE))
        || (in_array($action, ['approve', 'reject', 'select', 'cancel'], true) && !Rights::can(Rights::APPROVE))) {
        Html::displayRightError();
    }
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
]) as $row) {
    if ($view === 'drafts' && (string) ($row['status'] ?? '') !== 'draft') { continue; }
    if ($view === 'history' && (string) ($row['status'] ?? '') === 'draft') { continue; }
    $quotes[] = $row;
}
$editingQuote = null;
$editingLines = [];
$editId = max(0, (int) ($_GET['edit'] ?? 0));
if ($editId > 0 && Rights::can(Rights::UPDATE)) {
    foreach ($quotes as $candidate) {
        if ((int) $candidate['id'] === $editId && $candidate['status'] === 'draft') {
            $editingQuote = $candidate;
            break;
        }
    }
    if ($editingQuote) {
        foreach ($DB->request([
            'SELECT' => '*', 'FROM' => 'glpi_plugin_hh_general_quotelines',
            'WHERE' => ['general_quotes_id' => $editId], 'ORDER' => ['id'],
        ]) as $line) { $editingLines[] = $line; }
    }
}
$suppliers = [];
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_suppliers', 'WHERE' => ['is_deleted' => 0], 'ORDER' => ['name']]) as $row) { $suppliers[] = $row; }
$quoteStats = ['total' => count($quotes), 'draft' => 0, 'pending' => 0, 'approved' => 0, 'selected' => 0];
foreach ($quotes as $quoteRow) {
    $statusKey = (string) ($quoteRow['status'] ?? '');
    if (isset($quoteStats[$statusKey])) { $quoteStats[$statusKey]++; }
}

if (!$embedded) { Html::header('Cotizaciones de reparación', $_SERVER['PHP_SELF'], 'assets', 'hh'); }
?>
<style>
.hh-gq{--gq-navy:#17365d;--gq-blue:#245b83;font:12px/1.35 system-ui,-apple-system,"Segoe UI",sans-serif;color:#263445;padding:.35rem}
.hh-gq *{box-sizing:border-box}.hh-gq .gq-hero{background:linear-gradient(120deg,#17365d,#245b83);color:#fff;border:0;border-radius:10px;box-shadow:0 3px 12px #17365d22}
.hh-gq .gq-hero .text-muted{color:#dce8f2!important}.hh-gq .gq-hero h3{font-size:1.05rem;font-weight:750;margin:0 0 .2rem}
.hh-gq .gq-subtitle{font-size:.72rem;opacity:.9}.hh-gq .card{border:1px solid #dce3eb;border-radius:9px;box-shadow:0 2px 8px #172b4d0d;margin-bottom:.6rem;overflow:hidden}
.hh-gq .card-header{background:#f4f7fb;border-bottom:1px solid #dce3eb;padding:.55rem .75rem}.hh-gq .card-body{padding:.65rem .75rem}
.hh-gq h3{font-size:.9rem;font-weight:700;margin:0}.hh-gq label{display:block;font-size:.7rem;font-weight:700;color:#334155;margin-bottom:.2rem}
.hh-gq .form-control,.hh-gq .form-select{display:block;width:100%;font-size:.76rem;min-height:31px;padding:.3rem .45rem;border:1px solid #cbd5e1;border-radius:5px;background:#fff;color:#263445}
.hh-gq textarea.form-control{min-height:45px}.hh-gq .btn{display:inline-flex;align-items:center;justify-content:center;font-size:.74rem;font-weight:650;padding:.34rem .6rem;border-radius:6px;text-decoration:none;cursor:pointer}
.hh-gq .btn-primary{background:#245b83;border-color:#245b83;color:#fff}.hh-gq .btn-success{background:#16804a;border-color:#16804a;color:#fff}
.hh-gq .quote-card{border-left:4px solid #245b83}.hh-gq .quote-card.status-approved{border-left-color:#16804a}.hh-gq .quote-card.status-rejected{border-left-color:#bd2934}.hh-gq .quote-card.status-selected{border-left-color:#0f766e}
.hh-gq .status{display:inline-block;font-size:.62rem;font-weight:750;letter-spacing:.035em;padding:.2rem .45rem;border-radius:20px;background:#e7edf5;color:#34465d}
.hh-gq .status-pending{background:#fff1cc;color:#815600}.hh-gq .status-approved{background:#dff5e7;color:#17613a}.hh-gq .status-rejected{background:#fde7e7;color:#a12a32}.hh-gq .status-selected{background:#d9f2ee;color:#11665c}.hh-gq .status-cancelled{background:#edf0f4;color:#596579}
.hh-gq .line-row{display:grid;grid-template-columns:minmax(120px,2fr) 70px 110px 24px;gap:.35rem;margin:.3rem 0}
.hh-gq .hh-gq-totals{display:flex;flex-wrap:wrap;gap:.35rem .85rem;padding:.5rem .6rem;border-radius:7px;background:#f3f6fa;border:1px solid #e1e7ef}
.hh-gq .hh-gq-totals span{color:#64748b}.hh-gq .hh-gq-totals strong{color:#24364b}.hh-gq .hh-gq-grand{margin-left:auto;color:#17365d!important;font-size:.88rem}
.hh-gq .table{width:100%;border-collapse:collapse;font-size:.7rem}.hh-gq .table th,.hh-gq .table td{padding:.38rem .45rem;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:middle}.hh-gq .table th{background:#f3f6fa;color:#475569}
.hh-gq .table-bordered th,.hh-gq .table-bordered td{border:1px solid #e2e8f0}.hh-gq .table-responsive{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.hh-gq .alert{font-size:.72rem;padding:.45rem .6rem;border-radius:6px;border:1px solid #cbd5e1;background:#f1f7fd;color:#244765}
.hh-gq .gq-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.45rem;margin-bottom:.6rem}.hh-gq .gq-kpi{border:1px solid #dce3eb;border-radius:8px;padding:.5rem .6rem;background:#fff;box-shadow:0 1px 5px #172b4d0a}.hh-gq .gq-kpi-label{font-size:.62rem;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.hh-gq .gq-kpi-value{font-size:1.05rem;font-weight:800;color:#17365d}.hh-gq .gq-kpi.pending .gq-kpi-value{color:#946200}.hh-gq .gq-kpi.approved .gq-kpi-value{color:#16804a}.hh-gq .gq-kpi.selected .gq-kpi-value{color:#0f766e}
.hh-gq .d-flex{display:flex;align-items:center}.hh-gq .flex-wrap{flex-wrap:wrap}.hh-gq .justify-content-between{justify-content:space-between}.hh-gq .align-items-center{align-items:center}.hh-gq .gap-2{gap:.5rem}
.hh-gq .mt-1{margin-top:.25rem}.hh-gq .mt-2{margin-top:.5rem}.hh-gq .mt-3{margin-top:.75rem}.hh-gq .mb-0{margin-bottom:0}.hh-gq .mb-1{margin-bottom:.25rem}.hh-gq .mb-2{margin-bottom:.5rem}.hh-gq .mb-3{margin-bottom:.75rem}
.hh-gq .text-muted{color:#64748b}.hh-gq .text-end{text-align:right}.hh-gq .small{font-size:.72rem}.hh-gq .text-success{color:#16804a}.hh-gq .border{border:1px solid #dce3eb}
.hh-gq .row{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:.55rem;margin:0}.hh-gq .row>[class*="col-"]{grid-column:span 12;min-width:0}.hh-gq .row>.col-6{grid-column:span 6}.hh-gq .row>.col-md-3{grid-column:span 3}.hh-gq .row>.col-md-6{grid-column:span 6}
.hh-gq .btn-light{background:#fff;color:#17365d;border:1px solid #dbe5ef}.hh-gq .btn-outline-secondary{background:#fff;color:#475569;border:1px solid #cbd5e1}.hh-gq .btn-outline-primary{background:#fff;color:#245b83;border:1px solid #245b83}.hh-gq .btn-outline-danger{background:#fff;color:#b4232c;border:1px solid #c43b45}
.hh-gq a{color:#245b83;text-decoration:none}.hh-gq a:hover{text-decoration:underline}.hh-gq details summary{cursor:pointer;font-weight:650}.hh-gq form{margin:0}
@media(max-width:650px){.hh-gq .gq-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.hh-gq{font-size:11px}.hh-gq .row>.col-md-3,.hh-gq .row>.col-md-6{grid-column:span 6}.hh-gq .gq-hero .d-flex{align-items:flex-start}}
@media(max-width:420px){.hh-gq .row>[class*="col-"]{grid-column:span 12}.hh-gq .row>.col-6{grid-column:span 6}.hh-gq .gq-hero .d-flex{flex-direction:column}.hh-gq .gq-hero .btn{width:100%}.hh-gq .hh-gq-grand{margin-left:0;width:100%}}
</style>
<div class="hh-gq">
  <div class="card gq-hero mb-2"><div class="card-body d-flex justify-content-between align-items-center gap-2"><div><div class="gq-subtitle">SONGA · GESTIÓN DE REPARACIONES</div><h3>Cotizaciones de reparación</h3><div class="text-muted small"><?= $e($assetName) ?> · <?= $e($itemtype) ?> #<?= (int) $itemsId ?></div><div class="gq-subtitle mt-1">Comparación de proveedores, costos e historial de decisiones</div></div>
    <?php if (Rights::can(Rights::CREATE) && $view !== 'history'): ?><button class="btn btn-light" type="button" onclick="document.getElementById('gq-new').hidden=false;this.hidden=true">+ Nueva cotización</button><?php endif; ?>
  </div></div>
  <div class="gq-kpi-grid">
    <div class="gq-kpi"><div class="gq-kpi-label">Cotizaciones</div><div class="gq-kpi-value"><?= (int) $quoteStats['total'] ?></div></div>
    <div class="gq-kpi"><div class="gq-kpi-label">Borradores</div><div class="gq-kpi-value"><?= (int) $quoteStats['draft'] ?></div></div>
    <div class="gq-kpi pending"><div class="gq-kpi-label">Pendientes</div><div class="gq-kpi-value"><?= (int) $quoteStats['pending'] ?></div></div>
    <div class="gq-kpi approved"><div class="gq-kpi-label">Aprobadas</div><div class="gq-kpi-value"><?= (int) ($quoteStats['approved'] + $quoteStats['selected']) ?></div></div>
  </div>
  <section class="card" id="gq-new" <?= $editingQuote ? '' : 'hidden' ?>>
    <div class="card-header"><h3><?= $editingQuote ? 'Editar cotización en borrador' : 'Nueva cotización · Proveedor' ?></h3></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>">
        <input type="hidden" name="action" value="save"><input type="hidden" name="quote_id" value="<?= (int) ($editingQuote['id'] ?? 0) ?>"><input type="hidden" name="itemtype" value="<?= $e($itemtype) ?>"><input type="hidden" name="items_id" value="<?= (int) $itemsId ?>">
        <div class="row g-2">
          <div class="col-12 col-md-6"><label>Proveedor registrado en GLPI</label><select class="form-select" name="suppliers_id" required><option value="">Seleccione un proveedor registrado</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>" <?= (int) ($editingQuote['suppliers_id'] ?? 0) === (int) $supplier['id'] ? 'selected' : '' ?>><?= $e($supplier['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-md-3"><label>N.º cotización</label><input class="form-control" name="quote_number" maxlength="100" value="<?= $e($editingQuote['quote_number'] ?? '') ?>"></div>
          <div class="col-6 col-md-3"><label>Fecha</label><input class="form-control" type="date" name="quote_date" value="<?= $e($editingQuote['quote_date'] ?? date('Y-m-d')) ?>" required></div>
          <div class="col-6 col-md-3"><label>Vigencia hasta</label><input class="form-control" type="date" name="valid_until" value="<?= $e($editingQuote['valid_until'] ?? '') ?>"></div>
          <div class="col-6 col-md-3"><label>Moneda</label><select class="form-select" name="currency"><?php foreach (['USD','EUR','COP','PEN'] as $currency): ?><option <?= ($editingQuote['currency'] ?? 'USD') === $currency ? 'selected' : '' ?>><?= $currency ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label>Descripción de la reparación / alcance</label><input class="form-control" name="description" maxlength="255" required placeholder="Ej. Reparación de monitor, cambio de panel..." value="<?= $e($editingQuote['description'] ?? '') ?>"></div>
          <div class="col-12"><label>Formato recibido del proveedor</label><select class="form-select" name="quote_mode" id="gq-mode" onchange="document.getElementById('gq-lines').hidden=this.value!=='detailed'"><option value="global" <?= ($editingQuote['quote_mode'] ?? 'global') === 'global' ? 'selected' : '' ?>>Importe global (sin desglose)</option><option value="detailed" <?= ($editingQuote['quote_mode'] ?? '') === 'detailed' ? 'selected' : '' ?>>Detalle libre de conceptos</option></select></div>
          <div class="col-12" id="gq-lines" <?= ($editingQuote['quote_mode'] ?? '') === 'detailed' ? '' : 'hidden' ?>><label>Conceptos (no requiere catálogo ni códigos)</label><div id="gq-line-list"><?php if ($editingLines): foreach ($editingLines as $line): ?><div class="line-row"><input class="form-control line-description" name="line_description[]" placeholder="Concepto o trabajo" value="<?= $e($line['description']) ?>"><input class="form-control" name="line_quantity[]" type="number" min="0.01" step="0.01" value="<?= $e($line['quantity']) ?>" placeholder="Cant."><input class="form-control" name="line_price[]" type="number" min="0" step="0.01" placeholder="Precio unitario (opcional)" value="<?= $line['unit_price'] !== null ? $e($line['unit_price']) : '' ?>"><span></span></div><?php endforeach; else: ?><div class="line-row"><input class="form-control line-description" name="line_description[]" placeholder="Concepto o trabajo"><input class="form-control" name="line_quantity[]" type="number" min="0.01" step="0.01" value="1" placeholder="Cant."><input class="form-control" name="line_price[]" type="number" min="0" step="0.01" placeholder="Precio unitario (opcional)"><span></span></div><?php endif; ?></div><button class="btn btn-outline-secondary" type="button" onclick="const n=document.querySelector('.line-row').cloneNode(true);n.querySelectorAll('input').forEach(i=>i.value='');document.getElementById('gq-line-list').appendChild(n)">Agregar concepto</button></div>
          <div class="col-6 col-md-3"><label>Subtotal antes de IVA (o calculado desde conceptos)</label><input class="form-control" id="gq-subtotal" name="total_amount" type="number" min="0.01" step="0.01" placeholder="0.00" value="<?= $e($editingQuote['subtotal'] ?? '') ?>"></div>
          <div class="col-6 col-md-3"><label>Descuento antes de IVA</label><input class="form-control" id="gq-discount" name="discount" type="number" min="0" step="0.01" value="<?= $e($editingQuote['discount'] ?? '0') ?>"></div>
          <div class="col-6 col-md-3"><label>Transporte / otros</label><input class="form-control" id="gq-transport" name="transport" type="number" min="0" step="0.01" value="<?= $e($editingQuote['transport'] ?? '0') ?>"></div>
          <div class="col-12"><div class="hh-gq-totals" id="gq-live-total"><span>Subtotal menos descuento: <strong id="gq-live-base">$0.00</strong></span><span class="hh-gq-grand">Total cotizado: <strong id="gq-live-grand">$0.00</strong></span></div></div>
          <div class="col-12"><label>Observaciones y condiciones</label><textarea class="form-control" name="observations" rows="2"><?= $e($editingQuote['observations'] ?? '') ?></textarea></div>
          <div class="col-12"><label>Documento original (PDF, JPG o PNG; máximo 12 MB)</label><input class="form-control" type="file" name="quote_file" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div>
        <div class="alert alert-info small mt-2 mb-0">Registre el importe de la cotización del proveedor sin IVA. Los impuestos se calcularán posteriormente en el proceso de compra o facturación.</div><div class="d-flex gap-2 mt-3"><button class="btn btn-primary" type="submit"><?= $editingQuote ? 'Guardar cambios' : 'Guardar borrador' ?></button><button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('gq-new').hidden=true">Cancelar</button></div>
      </form>
    </div>
  </section>
  <?php if (!$quotes): ?><div class="alert alert-light border small"><?php if ($view === 'drafts'): ?>No hay cotizaciones en borrador para este activo.<?php elseif ($view === 'history'): ?>Todavía no hay cotizaciones en el historial de este activo.<?php else: ?>Todavía no hay cotizaciones para este activo. Puedes registrar una aunque no exista ticket ni esté en estado de reparación.<?php endif; ?></div><?php endif; ?>
  <?php foreach ($quotes as $q): ?>
    <article class="card quote-card status-<?= $e($q['status']) ?>"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-2"><div><strong><?= $e($q['supplier_name_snapshot']) ?></strong> <span class="status status-<?= $e($q['status']) ?>"><?= $e(strtoupper((string) $q['status'])) ?></span><div class="text-muted small"><?= $e($q['quote_number'] ?: 'Sin número') ?> · <?= $e($q['quote_date']) ?> · <?= $e($q['description']) ?></div></div><div class="text-end"><strong><?= $e($q['currency']) ?> <?= number_format((float) $q['total_amount'], 2) ?></strong><div class="text-muted small"><?= $q['quote_mode'] === 'global' ? 'Importe global' : 'Detalle libre' ?> · Sin IVA</div></div></div>
      <?php if (!empty($q['attachment_path'])): ?><div class="small mt-2"><a href="<?= $e($backUrl . '&download=' . (int) $q['id']) ?>" target="_blank" rel="noopener">Ver documento del proveedor: <?= $e($q['attachment_name']) ?></a></div><?php endif; ?>
      <?php
        $quoteLines = [];
        foreach ($DB->request(['SELECT' => '*', 'FROM' => 'glpi_plugin_hh_general_quotelines', 'WHERE' => ['general_quotes_id' => (int) $q['id']], 'ORDER' => ['id']]) as $line) { $quoteLines[] = $line; }
        $quoteEvents = [];
        foreach ($DB->request(['SELECT' => '*', 'FROM' => 'glpi_plugin_hh_general_quotehistory', 'WHERE' => ['general_quotes_id' => (int) $q['id']], 'ORDER' => ['date_creation DESC', 'id DESC']]) as $event) { $quoteEvents[] = $event; }
      ?>
      <div class="hh-gq-totals small mt-2"><span>Subtotal: <strong><?= $e($q['currency']) ?> <?= number_format((float) $q['subtotal'], 2) ?></strong></span><span>Descuento: <strong><?= $e($q['currency']) ?> <?= number_format((float) $q['discount'], 2) ?></strong></span><span>Transporte / otros: <strong><?= $e($q['currency']) ?> <?= number_format((float) $q['transport'], 2) ?></strong></span><span class="hh-gq-grand">Total cotizado (sin IVA): <strong><?= $e($q['currency']) ?> <?= number_format((float) $q['total_amount'], 2) ?></strong></span></div><?php if ($quoteLines): ?><div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-1"><thead><tr><th>Concepto</th><th>Cantidad</th><th>Precio unitario</th><th>Total línea</th></tr></thead><tbody><?php foreach ($quoteLines as $line): ?><tr><td><?= $e($line['description']) ?></td><td><?= number_format((float) $line['quantity'], 2) ?></td><td><?= $line['unit_price'] !== null ? $e($q['currency']) . ' ' . number_format((float) $line['unit_price'], 2) : 'No desglosado' ?></td><td><?= $line['line_total'] !== null ? $e($q['currency']) . ' ' . number_format((float) $line['line_total'], 2) : 'No desglosado' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
      <?php if ($quoteEvents): ?><details class="small mt-2"><summary>Historial de decisiones (<?= count($quoteEvents) ?>)</summary><ul class="mb-0 mt-1"><?php foreach ($quoteEvents as $event): ?><li><?= $e($event['date_creation']) ?> · <?= $e($event['old_status'] ?: 'Nueva') ?> → <?= $e($event['new_status']) ?> · <?= $e($event['comment'] ?? '') ?></li><?php endforeach; ?></ul></details><?php endif; ?>
      <?php if ($q['status'] === 'draft' && Rights::can(Rights::UPDATE)): ?><a class="btn btn-sm btn-outline-secondary mt-2" href="<?= $e($backUrl . '&edit=' . (int) $q['id']) ?>">Editar borrador</a><form method="post" class="mt-2"><input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>"><input type="hidden" name="itemtype" value="<?= $e($itemtype) ?>"><input type="hidden" name="items_id" value="<?= (int) $itemsId ?>"><input type="hidden" name="quote_id" value="<?= (int) $q['id'] ?>"><button class="btn btn-sm btn-outline-primary" name="action" value="submit">Enviar a aprobación</button></form><?php endif; ?>
      <?php if ($q['status'] === 'pending' && Rights::can(Rights::APPROVE)): ?><form method="post" class="d-flex flex-wrap gap-2 mt-2"><input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>"><input type="hidden" name="itemtype" value="<?= $e($itemtype) ?>"><input type="hidden" name="items_id" value="<?= (int) $itemsId ?>"><input type="hidden" name="quote_id" value="<?= (int) $q['id'] ?>"><input class="form-control form-control-sm" name="comment" placeholder="Comentario de aprobación / rechazo"><button class="btn btn-sm btn-success" name="action" value="approve">Aprobar</button><button class="btn btn-sm btn-outline-danger" name="action" value="reject">Rechazar</button></form><?php endif; ?>
      <?php if ($q['status'] === 'approved' && Rights::can(Rights::APPROVE)): ?><form method="post" class="mt-2"><input type="hidden" name="_glpi_csrf_token" value="<?= $e(Session::getNewCSRFToken()) ?>"><input type="hidden" name="itemtype" value="<?= $e($itemtype) ?>"><input type="hidden" name="items_id" value="<?= (int) $itemsId ?>"><input type="hidden" name="quote_id" value="<?= (int) $q['id'] ?>"><input class="form-control form-control-sm mb-1" name="comment" placeholder="Motivo de selección del proveedor" required><button class="btn btn-sm btn-primary" name="action" value="select">Seleccionar proveedor</button></form><?php endif; ?>
      <?php if ($q['status'] === 'selected'): ?><div class="small text-success mt-2">Proveedor seleccionado · <?= $e($q['selection_reason']) ?></div><?php endif; ?>
    </div></article>
  <?php endforeach; ?>
</div>
<script>
(function(){
 const root=document.querySelector('.hh-gq'); if(!root)return;
 const subtotal=root.querySelector('#gq-subtotal'), discount=root.querySelector('#gq-discount'), transport=root.querySelector('#gq-transport');
 const baseOut=root.querySelector('#gq-live-base'), totalOut=root.querySelector('#gq-live-grand');
 const money=n=>'$'+(Number.isFinite(n)?n:0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
 function update(){
   let amount=parseFloat(subtotal?.value)||0;
   if(amount<=0){
     amount=0;let hasLines=false,allPriced=true;
     root.querySelectorAll('[name="line_description[]"]').forEach((desc)=>{
       if(!desc.value.trim())return;
       hasLines=true;const row=desc.closest('.line-row');const qty=parseFloat(row?.querySelector('[name="line_quantity[]"]')?.value)||0;const priceText=row?.querySelector('[name="line_price[]"]')?.value??'';
       if(priceText.trim()===''){allPriced=false;return;}
       const price=parseFloat(priceText);if(Number.isFinite(price))amount+=qty*price;else allPriced=false;
     });
     if(hasLines&&!allPriced)amount=0;
   }
   const total=Math.max(0,amount-(parseFloat(discount?.value)||0)+(parseFloat(transport?.value)||0));
   if(baseOut)baseOut.textContent=money(total);if(totalOut)totalOut.textContent=money(total);
 }
 root.addEventListener('input',update);update();
 // El iframe informa su altura al contenedor para evitar un segundo scroll vertical.
 const notifyParent=()=>{const height=Math.max(document.documentElement.scrollHeight,document.body.scrollHeight);window.parent.postMessage({type:'hh-gq-resize',height:height+8},window.location.origin);};
 if(window.parent!==window){notifyParent();if('ResizeObserver' in window){new ResizeObserver(notifyParent).observe(document.body);}window.addEventListener('load',notifyParent);window.addEventListener('resize',notifyParent);new MutationObserver(notifyParent).observe(root,{childList:true,subtree:true,attributes:true});}
})();
</script>

<?php if (!$embedded) { Html::footer(); } ?>
