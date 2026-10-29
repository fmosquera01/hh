<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Dashboard;

include('../../../inc/includes.php');

Session::checkLoginUser();
global $DB;
$embedded = (($_GET['embedded'] ?? '') === '1');
$contextItemType = (string)($_GET['itemtype'] ?? '');
$contextItemsId = (int)($_GET['items_id'] ?? 0);
$contextAssetName = ($embedded && in_array($contextItemType, ['Computer', 'Phone'], true) && $contextItemsId > 0) ? hhQAssetLabel($contextItemType, $contextItemsId) : '';
$purchaseValue = null;
$purchaseItemType = $contextItemType;
$purchaseItemsId = $contextItemsId;
if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    foreach (hhQRows('glpi_plugin_hh_quotes', ['id' => (int)$_GET['edit']], ['itemtype', 'items_id']) as $assetQuote) {
        $purchaseItemType = (string)$assetQuote['itemtype'];
        $purchaseItemsId = (int)$assetQuote['items_id'];
        break;
    }
}
if (in_array($purchaseItemType, ['Computer', 'Phone'], true) && $purchaseItemsId > 0) {
    foreach (hhQRows('glpi_infocoms', ['items_id' => $purchaseItemsId, 'itemtype' => $purchaseItemType], ['value'], []) as $info) {
        if (is_numeric($info['value'] ?? null) && (float)$info['value'] > 0) $purchaseValue = (float)$info['value'];
        break;
    }
}

if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
}

function hhQe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hhQRows(string $table, array $criteria = [], array $select = ['*'], array $order = []): array
{
    global $DB;
    $params = ['SELECT' => $select, 'FROM' => $table];
    if ($criteria !== []) $params['WHERE'] = $criteria;
    if ($order !== []) $params['ORDER'] = $order;
    $rows = [];
    foreach ($DB->request($params) as $row) $rows[] = $row;
    return $rows;
}

function hhQCurrentPointValue(): array
{
    $rows = hhQRows('glpi_plugin_hh_pointvalues', [], ['*'], ['valid_from DESC', 'id DESC']);
    $today = date('Y-m-d H:i:s');
    foreach ($rows as $row) {
        if ((string)$row['valid_from'] <= $today && (empty($row['valid_until']) || (string)$row['valid_until'] >= $today)) {
            return ['value' => (float)$row['value_per_point'], 'currency' => (string)$row['currency'], 'id' => (int)$row['id']];
        }
    }
    return ['value' => 1.0, 'currency' => 'USD', 'id' => 0];
}

function hhQAssetLabel(string $type, int $id): string
{
    global $DB;
    $tables = ['Computer' => 'glpi_computers', 'Phone' => 'glpi_phones'];
    if (!isset($tables[$type]) || $id < 1) return '';
    foreach ($DB->request(['SELECT' => ['name', 'serial', 'otherserial'], 'FROM' => $tables[$type], 'WHERE' => ['id' => $id, 'is_deleted' => 0], 'LIMIT' => 1]) as $r) {
        return trim((string)$r['name'] . ' | Serie: ' . (string)$r['serial'] . ' | Activo: ' . (string)$r['otherserial']);
    }
    return '';
}

function hhQNormalizeFieldDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00') return null;
    try { return (new DateTimeImmutable($value))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return null; }
}

function hhQRepairTrackingForTicket(int $ticketId): ?array
{
    if ($ticketId < 1) return null;
    foreach (hhQRows('glpi_plugin_fields_ticketreparacions', [
        'items_id' => $ticketId, 'itemtype' => 'Ticket', 'plugin_fields_containers_id' => 1,
    ], ['nmerodeguafield', 'fechaenvioaproveedorfield', 'fecharecepcionproveedorfield', 'fecharecepcionguiafield']) as $fields) {
        $guide = trim((string)($fields['nmerodeguafield'] ?? ''));
        $sent = trim((string)($fields['fechaenvioaproveedorfield'] ?? ''));
        $received = trim((string)($fields['fecharecepcionproveedorfield'] ?? ''));
        if ($guide !== '' && hhQNormalizeFieldDate($sent) !== null && hhQNormalizeFieldDate($received) !== null) {
            return [
                'tickets_id' => $ticketId, 'guide_number' => $guide,
                'sent_to_supplier_at' => $sent, 'supplier_received_at' => $received,
                'returned_at' => trim((string)($fields['fecharecepcionguiafield'] ?? '')),
            ];
        }
    }
    return null;
}

function hhQRepairTicketForAsset(string $type, int $id): ?array
{
    if (!in_array($type, ['Computer', 'Phone'], true) || $id < 1) return null;
    foreach (hhQRows('glpi_items_tickets', ['itemtype' => $type, 'items_id' => $id], ['tickets_id'], ['tickets_id DESC']) as $link) {
        $tracking = hhQRepairTrackingForTicket((int)($link['tickets_id'] ?? 0));
        if ($tracking !== null) return $tracking;
    }
    return null;
}

function hhQWriteHistory(int $quoteId, ?string $oldStatus, string $newStatus, string $comment = ''): void
{
    global $DB;
    $DB->insert('glpi_plugin_hh_quotehistory', [
        'plugin_hh_quotes_id' => $quoteId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'users_id' => (int)Session::getLoginUserID(),
        'comment' => $comment !== '' ? $comment : null,
    ]);
}

$notice = '';
$error = '';
$point = hhQCurrentPointValue();
$allowedTypes = ['Computer' => 'Computadora', 'Phone' => 'Teléfono'];
$assetOptions = [];
foreach ([['type' => 'Computer', 'table' => 'glpi_computers', 'label' => 'Computadora'], ['type' => 'Phone', 'table' => 'glpi_phones', 'label' => 'Teléfono']] as $assetConfig) {
    foreach (hhQRows($assetConfig['table'], ['is_deleted' => 0], ['id', 'name', 'serial', 'otherserial'], ['name ASC']) as $assetRow) {
        if (hhQRepairTicketForAsset($assetConfig['type'], (int)$assetRow['id']) === null) continue;
        $assetOptions[] = [
            'itemtype' => $assetConfig['type'],
            'id' => (int)$assetRow['id'],
            'label' => $assetConfig['label'] . ' #' . (int)$assetRow['id'] . ' — ' . (string)$assetRow['name']
                . ((string)($assetRow['otherserial'] ?? '') !== '' ? ' | Activo: ' . (string)$assetRow['otherserial'] : '')
                . ((string)($assetRow['serial'] ?? '') !== '' ? ' | Serie: ' . (string)$assetRow['serial'] : ''),
        ];
    }
}
$categories = ['part' => 'Repuesto', 'labor' => 'Mano de obra', 'service' => 'Servicio', 'other' => 'Otro'];
$statuses = ['draft' => 'Borrador', 'approved' => 'Aprobada', 'rejected' => 'Rechazada'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($embedded && in_array($contextItemType, ['Computer', 'Phone'], true) && $contextItemsId > 0) {
        $_POST['itemtype'] = $contextItemType;
        $_POST['items_id'] = (string)$contextItemsId;
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_point_value') {
        $value = filter_var($_POST['value_per_point'] ?? null, FILTER_VALIDATE_FLOAT);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($value === false || $value < 0 || $reason === '') {
            $error = 'Ingrese un valor por punto válido y el motivo del cambio.';
        } else {
            if ($point['id'] > 0) {
                $DB->update('glpi_plugin_hh_pointvalues', ['valid_until' => date('Y-m-d H:i:s', time() - 1)], ['id' => $point['id']]);
            }
            if ($DB->insert('glpi_plugin_hh_pointvalues', [
                'value_per_point' => number_format((float)$value, 4, '.', ''),
                'currency' => 'USD',
                'valid_from' => date('Y-m-d H:i:s'),
                'valid_until' => null,
                'reason' => mb_substr($reason, 0, 255),
                'users_id' => (int)Session::getLoginUserID(),
            ])) {
                $point = hhQCurrentPointValue();
                $notice = 'Valor del punto actualizado. Las cotizaciones guardadas conservan su valor histórico.';
            } else {
                $error = 'No se pudo guardar el valor del punto.';
            }
        }
    } elseif ($action === 'save_quote') {
        $quoteId = (int)($_POST['quote_id'] ?? 0);
        $existing = null;
        if ($quoteId > 0) {
            foreach (hhQRows('glpi_plugin_hh_quotes', ['id' => $quoteId], ['*'], []) as $row) { $existing = $row; break; }
            if (!$existing) $error = 'La cotización solicitada no existe.';
            elseif ((string)$existing['status'] !== 'draft') $error = 'Esta cotización ya tiene una decisión final y está bloqueada.';
        }

        $itemtype = (string)($_POST['itemtype'] ?? '');
        $itemsId = filter_var($_POST['items_id'] ?? '0', FILTER_VALIDATE_INT);
        $quoteDate = trim((string)($_POST['quote_date'] ?? date('Y-m-d')));
        $supplierId = filter_var($_POST['suppliers_id'] ?? '0', FILTER_VALIDATE_INT);
        $supplierQuoteNumber = trim((string)($_POST['supplier_quote_number'] ?? ''));
        $tracking = ($itemsId !== false && $itemsId > 0) ? hhQRepairTicketForAsset($itemtype, (int)$itemsId) : null;
        $ticketId = $tracking !== null ? (int)$tracking['tickets_id'] : null;
        $guideNumber = (string)($tracking['guide_number'] ?? '');
        $sentAt = $tracking !== null ? hhQNormalizeFieldDate((string)$tracking['sent_to_supplier_at']) : null;
        $supplierReceivedAt = $tracking !== null ? hhQNormalizeFieldDate((string)$tracking['supplier_received_at']) : null;
        $returnedAt = $tracking !== null ? hhQNormalizeFieldDate((string)$tracking['returned_at']) : null;
        $repairType = (string)($_POST['repair_type'] ?? 'corrective');
        $priority = (string)($_POST['priority'] ?? 'normal');
        $diagnosis = trim((string)($_POST['observations'] ?? ''));
        $observations = $diagnosis;
        $lineIds = $_POST['line_item_id'] ?? [];
        $quantities = $_POST['line_quantity'] ?? [];
        $linePoints = $_POST['line_points'] ?? [];
        $lineSourceTypes = $_POST['line_source_type'] ?? [];
        $lineSourceIds = $_POST['line_source_id'] ?? [];
        $lineWarranty = $_POST['line_is_warranty'] ?? [];

        if ($error === '' && (!array_key_exists($itemtype, $allowedTypes) || $itemsId === false || $itemsId < 1 || hhQAssetLabel($itemtype, (int)$itemsId) === '')) {
            $error = 'Seleccione un tipo de activo y un ID válido de un equipo existente.';
        }
        if ($error === '' && $tracking === null) {
            $error = 'Este activo no tiene un ticket asociado con número de guía, fecha de envío y fecha de recepción por el proveedor. No se puede guardar la cotización.';
        }
        if ($error === '' && $quoteId === 0) {
            foreach (hhQRows('glpi_plugin_hh_quotes', ['itemtype' => $itemtype, 'items_id' => (int)$itemsId, 'status' => 'draft'], ['id', 'name'], ['id DESC']) as $pending) {
                $error = 'Este activo ya tiene una cotización en borrador (' . (string)$pending['name'] . '). Se abrirá la existente para continuar editándola.';
                $_GET['edit'] = (string)$pending['id'];
                break;
            }
        }
        if ($error === '' && !in_array($repairType, ['corrective', 'preventive', 'diagnostic'], true)) $error = 'Tipo de reparación no válido.';
        if ($error === '' && !in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) $error = 'Prioridad no válida.';
        if ($error === '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $quoteDate)) $error = 'Fecha de cotización no válida.';

        $lines = [];
        if ($error === '') {
            if (!is_array($lineIds) || !is_array($quantities) || !is_array($linePoints) || !is_array($lineSourceTypes) || !is_array($lineSourceIds) || !is_array($lineWarranty)) {
                $error = 'Las líneas de la cotización no son válidas.';
            } else {
                foreach ($lineIds as $idx => $rawId) {
                    $itemId = filter_var($rawId, FILTER_VALIDATE_INT);
                    $qty = filter_var($quantities[$idx] ?? '1', FILTER_VALIDATE_FLOAT);
                    $pts = filter_var($linePoints[$idx] ?? null, FILTER_VALIDATE_FLOAT);
                    if ($itemId === false || $itemId < 1) continue;
                    if ($qty === false || $qty <= 0 || $pts === false || $pts < 0) {
                        $error = 'Revise las cantidades y puntos de cada línea.';
                        break;
                    }
                    $catalogItem = null;
                    foreach (hhQRows('glpi_plugin_hh_items', ['id' => (int)$itemId, 'is_active' => 1], ['*']) as $ci) { $catalogItem = $ci; break; }
                    if (!$catalogItem) { $error = 'Uno de los artículos seleccionados no existe o está inactivo.'; break; }
                    $applies = false;
                    foreach (hhQRows('glpi_plugin_hh_itemtypes', ['plugin_hh_items_id' => (int)$itemId]) as $mapping) {
                        if ((string)$mapping['itemtype'] === $itemtype && ((int)($mapping['models_id'] ?? 0) === 0 || (int)$mapping['models_id'] === 0)) { $applies = true; break; }
                        if ((string)$mapping['itemtype'] === $itemtype) {
                            $modelField = $itemtype === 'Computer' ? 'computermodels_id' : 'phonemodels_id';
                            $assetTable = $itemtype === 'Computer' ? 'glpi_computers' : 'glpi_phones';
                            $modelId = 0;
                            foreach (hhQRows($assetTable, ['id' => (int)$itemsId], [$modelField], []) as $assetRow) { $modelId = (int)($assetRow[$modelField] ?? 0); break; }
                            if ((int)$mapping['models_id'] === $modelId) $applies = true;
                        }
                    }
                    if (!$applies) { $error = 'El artículo ' . (string)$catalogItem['name'] . ' no aplica al tipo/modelo del activo seleccionado.'; break; }
                    $sourceType = (string)($lineSourceTypes[$idx] ?? '');
                    $sourceIdRaw = trim((string)($lineSourceIds[$idx] ?? ''));
                    $sourceId = $sourceIdRaw !== '' ? filter_var($sourceIdRaw, FILTER_VALIDATE_INT) : null;
                    if (($sourceIdRaw === '' && $sourceType !== '') || ($sourceIdRaw !== '' && ($sourceId === false || $sourceId < 1 || !in_array($sourceType, ['Computer', 'Phone'], true) || hhQAssetLabel($sourceType, (int)$sourceId) === ''))) {
                        $error = 'Para una pieza reutilizada, seleccione un activo donante válido.';
                        break;
                    }
                    if ($sourceIdRaw !== '' && $sourceType === $itemtype && (int)$sourceId === (int)$itemsId) {
                        $error = 'El activo que se está reparando no puede ser también el donante de la pieza reutilizada.';
                        break;
                    }
                    $lines[] = [
                        'item_id' => (int)$itemId,
                        'source_itemtype' => $sourceIdRaw !== '' ? $sourceType : null,
                        'source_items_id' => $sourceIdRaw !== '' ? (int)$sourceId : null,
                        'code' => (string)$catalogItem['code'],
                        'category' => (string)$catalogItem['category'],
                        'description' => (string)$catalogItem['name'],
                        'quantity' => (float)$qty,
                        'points_each' => (float)$pts,
                        'is_warranty' => (int)($lineWarranty[$idx] ?? 0) === 1 ? 1 : 0,
                    ];
                }
                if ($error === '' && $lines === []) $error = 'Agregue al menos un artículo del catálogo a la cotización.';
            }
        }

        if ($error === '') {
            $totalPoints = 0.0;
            foreach ($lines as $line) $totalPoints += $line['quantity'] * $line['points_each'];
            $name = $existing ? (string)$existing['name'] : '';
            if (!$existing) {
                $nextId = 1;
                foreach (hhQRows('glpi_plugin_hh_quotes', [], ['id'], ['id DESC']) as $last) { $nextId = (int)$last['id'] + 1; break; }
                $name = 'COT-' . date('Y') . '-' . str_pad((string)$nextId, 6, '0', STR_PAD_LEFT);
            }
            $supplierName = '';
            if ($supplierId !== false && $supplierId > 0) {
                foreach (hhQRows('glpi_suppliers', ['id' => (int)$supplierId], ['name']) as $s) { $supplierName = (string)$s['name']; break; }
            }
            $quoteData = [
                'name' => $name,
                'itemtype' => $itemtype,
                'items_id' => (int)$itemsId,
                'quote_date' => $quoteDate,
                'suppliers_id' => ($supplierId !== false && $supplierId > 0) ? (int)$supplierId : null,
                'supplier_name_snapshot' => $supplierName !== '' ? $supplierName : null,
                'supplier_quote_number' => $supplierQuoteNumber !== '' ? mb_substr($supplierQuoteNumber, 0, 100) : null,
                'tickets_id' => ($ticketId !== null && $ticketId !== false) ? (int)$ticketId : null,
                'guide_number' => $guideNumber !== '' ? mb_substr($guideNumber, 0, 100) : null,
                'sent_to_supplier_at' => $sentAt,
                'supplier_received_at' => $supplierReceivedAt,
                'returned_at' => $returnedAt,
                'repair_type' => $repairType,
                'priority' => $priority,
                'diagnosis' => $diagnosis !== '' ? $diagnosis : null,
                'observations' => $observations !== '' ? $observations : null,
                'status' => 'draft',
                'total_points' => number_format($totalPoints, 2, '.', ''),
                'point_value_snapshot' => number_format((float)$point['value'], 4, '.', ''),
                'currency' => 'USD',
                'total_amount_snapshot' => number_format($totalPoints * (float)$point['value'], 2, '.', ''),
                'users_id' => (int)Session::getLoginUserID(),
            ];
            if ($existing) {
                unset($quoteData['name'], $quoteData['status'], $quoteData['users_id']);
                $ok = $DB->update('glpi_plugin_hh_quotes', $quoteData, ['id' => $quoteId]);
                $savedId = $quoteId;
            } else {
                $ok = $DB->insert('glpi_plugin_hh_quotes', $quoteData);
                $savedId = $ok ? (int)$DB->insertId() : 0;
            }
            if ($ok && $savedId > 0) {
                foreach (hhQRows('glpi_plugin_hh_quotedetails', ['plugin_hh_quotes_id' => $savedId], ['id']) as $oldLine) {
                    $DB->delete('glpi_plugin_hh_quotedetails', ['id' => (int)$oldLine['id']]);
                }
                foreach ($lines as $line) {
                    $lineTotalPoints = $line['quantity'] * $line['points_each'];
                    $DB->insert('glpi_plugin_hh_quotedetails', [
                        'plugin_hh_quotes_id' => $savedId,
                        'plugin_hh_items_id' => $line['item_id'],
                        'code_snapshot' => $line['code'],
                        'category_snapshot' => $line['category'],
                        'description_snapshot' => mb_substr($line['description'], 0, 255),
                        'quantity' => number_format($line['quantity'], 2, '.', ''),
                        'points_each' => number_format($line['points_each'], 2, '.', ''),
                        'points_total' => number_format($lineTotalPoints, 2, '.', ''),
                        'point_value_snapshot' => number_format((float)$point['value'], 4, '.', ''),
                        'amount_snapshot' => number_format($lineTotalPoints * (float)$point['value'], 2, '.', ''),
                        'source_itemtype' => $line['source_itemtype'],
                        'source_items_id' => $line['source_items_id'],
                        'is_warranty' => $line['is_warranty'],
                    ]);
                }
                if (!$existing) hhQWriteHistory($savedId, null, 'draft', 'Cotización creada como borrador.');
                $notice = 'Cotización ' . $name . ' guardada como borrador.';
                $_GET['edit'] = (string)$savedId;
            } else {
                $error = 'No se pudo guardar la cotización.';
            }
        }
    } elseif ($action === 'decide_quote') {
        $quoteId = filter_var($_POST['quote_id'] ?? 0, FILTER_VALIDATE_INT);
        $decision = (string)($_POST['decision'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        $quote = null;
        if ($quoteId !== false && $quoteId > 0) {
            foreach (hhQRows('glpi_plugin_hh_quotes', ['id' => (int)$quoteId], ['*']) as $r) { $quote = $r; break; }
        }
        if (!$quote) $error = 'No se encontró la cotización.';
        elseif ((string)$quote['status'] !== 'draft') $error = 'La cotización ya tiene una decisión final y está bloqueada.';
        elseif (!in_array($decision, ['approved', 'rejected'], true)) $error = 'Decisión no válida.';
        else {
            $DB->update('glpi_plugin_hh_quotes', ['status' => $decision, 'observations' => trim((string)($quote['observations'] ?? '') . ($comment !== '' ? "\nDecisión: " . $comment : ''))], ['id' => (int)$quoteId]);
            hhQWriteHistory((int)$quoteId, 'draft', $decision, $comment);
            $notice = 'Cotización ' . (string)$quote['name'] . ' ' . ($decision === 'approved' ? 'aprobada' : 'rechazada') . '. Queda bloqueada y disponible en el historial.';
            unset($_GET['edit']);
        }
    } elseif ($action === 'delete_line' && isset($_POST['quote_id'])) {
        // Line editing is handled by saving the draft with the desired rows.
        $error = 'Quite la línea del formulario y vuelva a guardar el borrador.';
    }
}

if ($embedded && in_array($contextItemType, ['Computer', 'Phone'], true) && $contextItemsId > 0 && empty($_GET['edit']) && empty($_GET['new'])) {
    foreach (hhQRows('glpi_plugin_hh_quotes', ['itemtype' => $contextItemType, 'items_id' => $contextItemsId, 'status' => 'draft'], ['id'], ['id DESC']) as $pendingDraft) {
        $_GET['edit'] = (string)$pendingDraft['id'];
        break;
    }
}
$editId = filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT);
$editQuote = null;
$editLines = [];
if ($editId !== false && $editId > 0) {
    foreach (hhQRows('glpi_plugin_hh_quotes', ['id' => (int)$editId], ['*']) as $r) { $editQuote = $r; break; }
    if ($editQuote) $editLines = hhQRows('glpi_plugin_hh_quotedetails', ['plugin_hh_quotes_id' => (int)$editId], ['*'], ['id ASC']);
}
$quoteCriteria = ($embedded && in_array($contextItemType, ['Computer', 'Phone'], true) && $contextItemsId > 0) ? ['itemtype' => $contextItemType, 'items_id' => $contextItemsId] : [];
$allQuotes = hhQRows('glpi_plugin_hh_quotes', $quoteCriteria, ['*'], ['date_mod DESC', 'id DESC']);
$catalogItems = [];
foreach (hhQRows('glpi_plugin_hh_items', ['is_active' => 1], ['*'], ['name ASC']) as $item) {
    $maps = hhQRows('glpi_plugin_hh_itemtypes', ['plugin_hh_items_id' => (int)$item['id']]);
    $types = [];
    foreach ($maps as $map) $types[] = ['itemtype' => (string)$map['itemtype'], 'models_id' => (int)($map['models_id'] ?? 0)];
    $catalogItems[] = ['id' => (int)$item['id'], 'code' => (string)$item['code'], 'name' => (string)$item['name'], 'category' => (string)$item['category'], 'points' => (float)$item['points_each'], 'types' => $types];
}
$suppliers = hhQRows('glpi_suppliers', ['is_deleted' => 0], ['id', 'name'], ['name ASC']);

$assetLabel = '';
if ($editQuote !== null) {
    $assetLabel = hhQAssetLabel((string) $editQuote['itemtype'], (int) $editQuote['items_id']);
}
$defaultItemType = $editQuote !== null
    ? (string) $editQuote['itemtype']
    : (string) ($_GET['itemtype'] ?? '');
$defaultItemsId = $editQuote !== null
    ? (int) $editQuote['items_id']
    : (int) ($_GET['items_id'] ?? 0);
$editAllowed = !$editQuote || (string)$editQuote['status'] === 'draft';
$initialLines = [];
foreach ($editLines as $line) $initialLines[] = [
    'item_id' => (int)($line['plugin_hh_items_id'] ?? 0),
    'quantity' => (float)$line['quantity'],
    'points_each' => (float)$line['points_each'],
    'is_warranty' => (int)($line['is_warranty'] ?? 0),
    'source_itemtype' => (string)($line['source_itemtype'] ?? ''),
    'source_items_id' => (int)($line['source_items_id'] ?? 0),
];

$warrantyItems = [];
if ($defaultItemType !== '' && $defaultItemsId > 0) {
    $todayDate = new DateTimeImmutable('today');
    foreach (hhQRows('glpi_plugin_hh_quotes', ['itemtype' => $defaultItemType, 'items_id' => $defaultItemsId, 'status' => 'approved'], ['id', 'tickets_id', 'returned_at'], ['id DESC']) as $priorQuote) {
        if ((int)($priorQuote['id'] ?? 0) === (int)($editQuote['id'] ?? 0)) continue;
        $returnValue = trim((string)($priorQuote['returned_at'] ?? ''));
        if ($returnValue === '' && (int)($priorQuote['tickets_id'] ?? 0) > 0) {
            $tracking = hhQRepairTrackingForTicket((int)$priorQuote['tickets_id']);
            $returnValue = trim((string)($tracking['returned_at'] ?? ''));
        }
        if ($returnValue === '') continue;
        try {
            $returnedDate = new DateTimeImmutable($returnValue);
            $expiresDate = $returnedDate->modify('+60 days');
            if ($returnedDate > $todayDate || $todayDate > $expiresDate) continue;
        } catch (Throwable $e) { continue; }
        foreach (hhQRows('glpi_plugin_hh_quotedetails', ['plugin_hh_quotes_id' => (int)$priorQuote['id']], ['plugin_hh_items_id', 'category_snapshot']) as $priorLine) {
            $partId = (int)($priorLine['plugin_hh_items_id'] ?? 0);
            if ($partId > 0 && (string)($priorLine['category_snapshot'] ?? '') === 'part') $warrantyItems[$partId] = true;
        }
    }
}
$warrantyItems = array_map('intval', array_keys($warrantyItems));

if (!$embedded) Html::header(__('Cotizaciones', 'hh'), $_SERVER['PHP_SELF'], 'plugins', Dashboard::class);
?>
<div class="container-fluid hh-quotes">
<style>
/* Diseño autónomo cuando se muestra dentro del iframe de la ficha del activo. */
.hh-quotes{--hh-navy:#17365d;--hh-blue:#245b83;color:#263445;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px;line-height:1.45}
.hh-quotes *{box-sizing:border-box}
.hh-quotes .card{background:#fff;border:1px solid #d9e0e8;border-radius:10px;margin-bottom:16px;overflow:hidden;box-shadow:0 2px 8px rgba(20,35,55,.06)}
.hh-quotes .card-header{padding:14px 16px;background:#f5f7fa;border-bottom:1px solid #d9e0e8}
.hh-quotes .card-body{padding:16px}
.hh-quotes h2{font-size:21px;line-height:1.3;margin:0 0 10px;font-weight:700}
.hh-quotes h3{font-size:17px;line-height:1.35;margin:12px 0;font-weight:700}
.hh-quotes h4{font-size:15px;font-weight:700}
.hh-quotes p{margin:8px 0 14px}
.hh-quotes .row{display:flex;flex-wrap:wrap;margin-left:-6px;margin-right:-6px;row-gap:12px}
.hh-quotes .row>[class*="col-"]{padding-left:6px;padding-right:6px;min-width:0}
.hh-quotes .col-md-2{width:16.6667%}.hh-quotes .col-md-3{width:25%}.hh-quotes .col-md-4{width:33.3333%}.hh-quotes .col-md-5{width:41.6667%}.hh-quotes .col-md-6{width:50%}.hh-quotes .col-md-8{width:66.6667%}.hh-quotes .col-md-12,.hh-quotes .col-12{width:100%}
.hh-quotes .form-label{display:block;font-weight:600;margin-bottom:5px;color:#27364b}
.hh-quotes .form-control,.hh-quotes .form-select{display:block;width:100%;min-width:0;min-height:36px;padding:7px 9px;border:1px solid #c8d1dc;border-radius:6px;background:#fff;color:#263445;font:inherit}
.hh-quotes textarea.form-control{min-height:64px;resize:vertical}
.hh-quotes .btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;border:1px solid transparent;border-radius:6px;padding:7px 11px;font:inherit;font-weight:600;text-decoration:none;cursor:pointer;white-space:normal}
.hh-quotes .btn-sm{font-size:12px;padding:5px 8px}
.hh-quotes .btn-primary{background:#245b83;color:#fff}.hh-quotes .btn-outline-primary{border-color:#245b83;color:#245b83;background:#fff}.hh-quotes .btn-secondary,.hh-quotes .btn-outline-secondary{background:#f1f4f8;border-color:#cbd5e1;color:#334155}.hh-quotes .btn-success{background:#16804a;color:#fff}.hh-quotes .btn-danger,.hh-quotes .btn-outline-danger{background:#fff;border-color:#c43b45;color:#b4232c}
.hh-quotes .alert{padding:10px 12px;border-radius:7px;margin:10px 0 14px;border:1px solid transparent}.hh-quotes .alert-success{background:#e8f7ee;border-color:#a7dfbb;color:#14532d}.hh-quotes .alert-danger{background:#fff0f0;border-color:#f0b4b4;color:#8b1e1e}.hh-quotes .alert-info{background:#edf6ff;border-color:#b6d9f7;color:#19476b}.hh-quotes .alert-warning{background:#fff8e6;border-color:#f3d58a;color:#77520a}
.hh-quotes .table-responsive{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.hh-quotes table{width:100%;border-collapse:collapse;font-size:13px}
.hh-quotes th,.hh-quotes td{border-bottom:1px solid #e3e8ef;padding:8px 7px;text-align:left;vertical-align:middle}
.hh-quotes th{background:#f5f7fa;font-weight:700}.hh-quotes .text-end{text-align:right}.hh-quotes .text-center{text-align:center}.hh-quotes .text-muted{color:#667085!important}.hh-quotes .small{font-size:12px}.hh-quotes .d-flex{display:flex}.hh-quotes .flex-wrap{flex-wrap:wrap}.hh-quotes .justify-content-between{justify-content:space-between}.hh-quotes .align-items-center{align-items:center}.hh-quotes .align-items-end{align-items:flex-end}.hh-quotes .gap-3{gap:12px}.hh-quotes .gap-2{gap:8px}.hh-quotes .mb-0{margin-bottom:0}.hh-quotes .mb-2{margin-bottom:8px}.hh-quotes .mb-3{margin-bottom:12px}.hh-quotes .mb-4{margin-bottom:16px}.hh-quotes .mt-2{margin-top:8px}.hh-quotes .mt-auto{margin-top:auto}.hh-quotes .pt-3{padding-top:12px}.hh-quotes .p-4{padding:16px}.hh-quotes .p-3{padding:12px}.hh-quotes .rounded{border-radius:8px}.hh-quotes .border{border:1px solid #d9e0e8}.hh-quotes .w-100{width:100%}.hh-quotes hr{border:0;border-top:1px solid #e0e6ed;margin:16px 0}.hh-quotes .badge{display:inline-block;padding:4px 7px;border-radius:5px;color:#fff}.hh-quotes .bg-success{background:#16804a}.hh-quotes .bg-danger{background:#bd2934}.hh-quotes .hh-quote-form-card{background:#fff}
.hh-quotes .hh-quote-hero{color:#fff}
.hh-quotes .hh-quote-hero .text-muted{color:#dce8f2!important}
@media(max-width:767px){.hh-quotes .row>[class*="col-md-"]{width:100%}.hh-quotes .hh-source-fields{grid-template-columns:1fr}.hh-quotes .hh-quote-form-card{padding:.75rem}.hh-quotes .card-body{padding:12px}.hh-quotes .hh-lines-table{min-width:670px}.hh-quotes .btn{font-size:13px}}
.hh-quotes{--hh-navy:#17365d;--hh-blue:#245b83}
.hh-quotes .hh-warranty-active{background:#e8f7ee!important;box-shadow:inset 4px 0 #16804a}.hh-quotes .hh-warranty-active td{background:#e8f7ee!important}.hh-quotes .hh-quote-hero{border:0;border-radius:.8rem;background:linear-gradient(120deg,var(--hh-navy),var(--hh-blue));color:#fff;overflow:hidden}
.hh-quotes .hh-quote-hero .text-muted{color:#dce8f2!important}
.hh-quotes .hh-stat{border:1px solid var(--tblr-border-color,#dce1e7);border-radius:.65rem;padding:1rem;height:100%;background:var(--tblr-card-bg,#fff)}
.hh-quotes .hh-stat-label{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:var(--tblr-secondary-color,#667085)}
.hh-quotes .hh-stat-value{font-size:1.35rem;font-weight:700;margin-top:.25rem}
.hh-quotes .hh-section{border:1px solid var(--tblr-border-color,#dce1e7);border-radius:.7rem;overflow:hidden}
.hh-quotes .hh-section-head{background:var(--tblr-bg-surface-secondary,#f5f7fa);padding:.9rem 1rem;border-bottom:1px solid var(--tblr-border-color,#dce1e7)}
.hh-quotes .hh-section-body{padding:1rem}
.hh-quotes .hh-lines-table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.025em;color:var(--tblr-secondary-color,#667085);white-space:nowrap}
.hh-quotes .hh-source-fields{display:grid;grid-template-columns:minmax(130px,1fr) minmax(90px,.6fr);gap:.4rem;margin-top:.45rem}
.hh-quotes .hh-help{font-size:.82rem;color:var(--tblr-secondary-color,#667085)}
.hh-quotes .hh-total{font-size:1.1rem;font-weight:700}
.hh-quotes .hh-quote-form-card{border:1px solid var(--tblr-border-color,#dce1e7);border-radius:.75rem;padding:1.1rem;background:var(--tblr-card-bg,#fff)}
.hh-quotes .card{box-shadow:0 .15rem .6rem rgba(20,35,55,.06);border-radius:.75rem;overflow:hidden}
.hh-quotes .card-header,.hh-quotes .hh-section-head{padding:1rem 1.25rem}
.hh-quotes .hh-quote-form-card{box-shadow:0 .15rem .5rem rgba(20,35,55,.04)}
.hh-quotes .form-label{font-weight:600;color:var(--tblr-body-color,#27364b)}
.hh-quotes .hh-lines-table tbody td{vertical-align:middle;padding:.7rem .55rem}
.hh-quotes .hh-lines-table tfoot{background:var(--tblr-bg-surface-secondary,#f5f7fa)}
.hh-quotes .hh-lines-table tfoot th{padding:.85rem .55rem}
.hh-quotes .hh-section-head .card-title{font-weight:700}
.hh-quotes .hh-quote-hero .display-6{letter-spacing:-.04em}
.hh-quotes .btn{border-radius:.45rem}
@media(max-width:767px){.hh-quotes .hh-source-fields{grid-template-columns:1fr}.hh-quotes .hh-quote-form-card{padding:.75rem}}

/* Compact visual overrides: CSS only; keep form fields and behavior unchanged. */
.hh-quotes #hh-quote-form{padding:.55rem .65rem;margin-bottom:.65rem!important}
.hh-quotes #hh-quote-form>.row{row-gap:5px}
.hh-quotes #hh-quote-form .form-label{font-size:11px;margin-bottom:2px}
.hh-quotes #hh-quote-form .form-control,.hh-quotes #hh-quote-form .form-select{min-height:28px;padding:3px 6px;font-size:11.5px}
.hh-quotes #hh-quote-form textarea.form-control{min-height:38px;height:38px;resize:vertical}
.hh-quotes #hh-quote-form .text-muted{font-size:10px}
@media(min-width:640px){.hh-quotes #hh-quote-form .col-md-4{width:33.33333333%}.hh-quotes #hh-quote-form .col-md-6{width:50%}.hh-quotes #hh-quote-form .col-md-5{width:33.33333333%}.hh-quotes #hh-quote-form .col-md-3{width:33.33333333%}.hh-quotes #hh-quote-form .col-md-2{width:33.33333333%}}@media(min-width:480px) and (max-width:639.98px){.hh-quotes #hh-quote-form [class*="col-md-"]{width:50%}}@media(max-width:479.98px){.hh-quotes #hh-quote-form [class*="col-md-"]{width:100%}}

.hh-quotes{font-size:12.5px;line-height:1.32}
.hh-quotes .card{margin-bottom:9px;border-radius:8px}
.hh-quotes .card-header,.hh-quotes .hh-section-head{padding:.55rem .8rem}
.hh-quotes .card-body,.hh-quotes .hh-section-body{padding:.65rem .8rem}
.hh-quotes h2{font-size:17px;line-height:1.25;margin-bottom:6px}
.hh-quotes h3{font-size:14px;line-height:1.25;margin:7px 0}
.hh-quotes h4{font-size:12.5px;margin-bottom:5px}
.hh-quotes p{margin:5px 0 8px}
.hh-quotes .row{row-gap:7px}
.hh-quotes .form-label{font-size:11.5px;margin-bottom:3px;line-height:1.2}
.hh-quotes .form-control,.hh-quotes .form-select{font-size:12px;min-height:30px;padding:4px 7px;border-radius:5px}
.hh-quotes textarea.form-control{min-height:43px;padding:5px 7px}
.hh-quotes .btn{font-size:12px;padding:5px 9px;line-height:1.25}
.hh-quotes .btn-sm{font-size:11px;padding:3px 6px}
.hh-quotes .alert{padding:7px 9px;margin:6px 0 9px}
.hh-quotes .hh-quote-form-card{padding:.7rem;margin-bottom:.7rem!important;border-radius:8px}
.hh-quotes .hh-section{border-radius:8px}
.hh-quotes .hh-stat{padding:.65rem;border-radius:7px}
.hh-quotes .hh-stat-label{font-size:.68rem}
.hh-quotes .hh-stat-value{font-size:1.05rem;margin-top:.1rem}
.hh-quotes .hh-lines-table{font-size:11.5px}
.hh-quotes .hh-lines-table thead th{font-size:10.5px;padding:5px 6px}
.hh-quotes .hh-lines-table tbody td{padding:5px 6px}
.hh-quotes .hh-lines-table tfoot th{padding:6px}
.hh-quotes .hh-help{font-size:10.5px;line-height:1.25}
.hh-quotes hr{margin:.65rem 0}
.hh-quotes .mb-4{margin-bottom:.75rem!important}
.hh-quotes .mb-3{margin-bottom:.6rem!important}
.hh-quotes .pt-3{padding-top:.5rem!important}
@media(max-width:767px){.hh-quotes .hh-quote-form-card{padding:.55rem}.hh-quotes .row{row-gap:6px}}

</style>
<?php if (!$embedded): ?> <div class="card hh-quote-hero mb-3"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3"><div><div class="text-muted small mb-1">HH · GESTIÓN TÉCNICA</div><h2 class="mb-1">Cotizaciones de reparación</h2><p class="mb-0 text-muted">Costos, repuestos, trazabilidad de piezas recuperadas y decisiones en un solo lugar.</p></div><div class="text-end"><div class="text-muted small">Valor vigente por punto</div><div class="display-6 fw-bold">$<?=number_format((float)$point['value'],4)?></div><div class="text-muted small">USD · valor histórico por cotización</div></div></div></div></div><?php endif; ?>
 <div class="card mb-3">
  <div class="card-header"><h2 class="card-title">Cotizaciones de reparación</h2></div>
  <div class="card-body">
   <?php if ($notice !== ''): ?><div class="alert alert-success"><?=hhQe($notice)?></div><?php endif; ?>
   <?php if ($error !== ''): ?><div class="alert alert-danger"><?=hhQe($error)?></div><?php endif; ?>
   <?php if (!$embedded): ?><div class="alert alert-info">Valor vigente: <strong>$<?=number_format((float)$point['value'], 4)?></strong> por punto (USD). Cada cotización guarda una copia del valor aplicado; los cambios futuros no alteran cotizaciones ya guardadas.</div>
   <form method="post" class="hh-quote-form-card mb-4">
    <input type="hidden" name="_glpi_csrf_token" value="<?=hhQe(Session::getNewCSRFToken())?>">
    <input type="hidden" name="action" value="save_point_value">
    <h3>Configurar valor del punto</h3>
    <div class="row g-2 align-items-end">
     <div class="col-md-3"><label class="form-label">Valor en USD por punto</label><input class="form-control" type="number" name="value_per_point" min="0" step="0.0001" value="<?=hhQe(number_format((float)$point['value'],4,'.',''))?>" required></div>
     <div class="col-md-6"><label class="form-label">Motivo del cambio</label><input class="form-control" name="reason" maxlength="255" placeholder="Ej. actualización de tarifa anual" required></div>
     <div class="col-md-3"><button class="btn btn-outline-primary" type="submit">Guardar valor del punto</button></div>
    </div>
   </form><?php endif; ?>

   <h3><?= $editQuote ? 'Editar cotización ' . hhQe((string)$editQuote['name']) : 'Nueva cotización' ?></h3>
   <?php if (!$editAllowed): ?>
    <div class="alert alert-warning">Esta cotización está <?=hhQe($statuses[(string)$editQuote['status']] ?? (string)$editQuote['status'])?> y no se puede modificar.</div>
   <?php else: ?>
   <form method="post" id="hh-quote-form" class="hh-quote-form-card mb-4">
    <input type="hidden" name="_glpi_csrf_token" value="<?=hhQe(Session::getNewCSRFToken())?>">
    <input type="hidden" name="action" value="save_quote">
    <input type="hidden" name="quote_id" value="<?=$editQuote ? (int)$editQuote['id'] : 0?>">
    <div class="row g-2">
     <?php if ($embedded && $contextAssetName !== ''): ?>
     <div class="col-md-4"><label class="form-label">Activo</label><input class="form-control" value="<?=hhQe($contextAssetName)?>" readonly><input type="hidden" name="itemtype" id="hh-asset-type" value="<?=hhQe($contextItemType)?>"><input type="hidden" name="items_id" value="<?=$contextItemsId?>"></div>
     <?php else: ?>
     <div class="col-md-4"><label class="form-label">Tipo de activo</label><select class="form-select" name="itemtype" id="hh-asset-type" required><option value="">Seleccione...</option><option value="Computer" <?=$defaultItemType==='Computer'?'selected':''?>>Computadora</option><option value="Phone" <?=$defaultItemType==='Phone'?'selected':''?>>Teléfono</option></select></div>
     <div class="col-md-4"><label class="form-label">ID del activo GLPI</label><input class="form-control" type="number" min="1" name="items_id" value="<?=$defaultItemsId > 0 ? $defaultItemsId : ''?>" required><small class="text-muted">ID numérico de la ficha del activo.</small></div>
     <?php endif; ?>
     <div class="col-md-4"><label class="form-label">Fecha</label><input class="form-control" type="date" name="quote_date" value="<?=hhQe((string)($editQuote['quote_date'] ?? date('Y-m-d')))?>" required></div>
     <div class="col-md-4"><label class="form-label">Proveedor</label><select class="form-select" name="suppliers_id"><option value="0">No especificado</option><?php foreach($suppliers as $supplier): ?><option value="<?=(int)$supplier['id']?>" <?=$editQuote && (int)$editQuote['suppliers_id']===(int)$supplier['id']?'selected':''?>><?=hhQe((string)$supplier['name'])?></option><?php endforeach; ?></select></div>
     <div class="col-md-4"><label class="form-label">N.º cotización del proveedor</label><input class="form-control" name="supplier_quote_number" maxlength="100" value="<?=hhQe((string)($editQuote['supplier_quote_number'] ?? ''))?>"></div>
     <div class="col-md-4"><label class="form-label">Tipo de reparación</label><select class="form-select" name="repair_type"><option value="corrective" <?=$editQuote && $editQuote['repair_type']==='corrective'?'selected':''?>>Correctiva</option><option value="preventive" <?=$editQuote && $editQuote['repair_type']==='preventive'?'selected':''?>>Preventiva</option><option value="diagnostic" <?=$editQuote && $editQuote['repair_type']==='diagnostic'?'selected':''?>>Diagnóstico</option></select></div>
     <div class="col-md-4"><label class="form-label">Prioridad</label><select class="form-select" name="priority"><option value="low" <?=$editQuote && $editQuote['priority']==='low'?'selected':''?>>Baja</option><option value="normal" <?=(!$editQuote || $editQuote['priority']==='normal')?'selected':''?>>Normal</option><option value="high" <?=$editQuote && $editQuote['priority']==='high'?'selected':''?>>Alta</option><option value="urgent" <?=$editQuote && $editQuote['priority']==='urgent'?'selected':''?>>Urgente</option></select></div>
     <div class="col-md-12"><label class="form-label">Diagnóstico / Observaciones</label><textarea class="form-control" name="observations" rows="2"><?=hhQe(trim((string)($editQuote['diagnosis'] ?? '') . ((string)($editQuote['diagnosis'] ?? '') !== '' && (string)($editQuote['observations'] ?? '') !== '' ? "\n" : '') . (string)($editQuote['observations'] ?? '')))?></textarea></div>
    </div>
    <hr><div class="d-flex justify-content-between align-items-center mb-2"><h4 class="mb-0">Detalle de repuestos y servicios</h4><button type="button" class="btn btn-outline-primary btn-sm" id="hh-add-line">Agregar línea</button></div>
    <div class="table-responsive"><table class="table table-sm table-striped align-middle hh-lines-table"><thead><tr><th style="min-width:200px">Artículo</th><th style="min-width:100px">Reuso de pieza</th><th style="min-width:95px">Garantía</th><th style="width:70px">Cantidad</th><th style="width:70px">Puntos</th><th style="width:80px">Total puntos</th><th style="width:80px">Total USD</th><th></th></tr></thead><tbody id="hh-lines"></tbody><tfoot><tr><th colspan="5" class="text-end">Totales</th><th id="hh-total-points" class="hh-total">0.00</th><th id="hh-total-amount" class="hh-total">$0.00</th><th></th></tr></tfoot></table></div>
    <p class="text-muted small">Al seleccionar un artículo se cargan sus puntos actuales. Puedes ajustarlos en esta cotización; el valor queda guardado como histórico en sus líneas. La cotización queda como borrador hasta que se apruebe o rechace.</p>
    <div class="mt-2 mb-3 small">
      <div id="hh-repair-cost-ratio" class="fw-semibold">Costo de reparación: calculando porcentaje...</div>
      <div id="hh-repair-cost-warning" class="alert alert-warning py-2 mt-2 mb-0" style="display:none">No se recomienda reparar: el costo es igual o superior al 40 % del valor de compra del equipo.</div>
      <?php if ($purchaseValue !== null): ?><div class="text-muted">Valor de compra registrado en GLPI: $<?=number_format($purchaseValue, 2)?></div><?php else: ?><div class="text-muted">No se puede calcular el porcentaje porque el equipo no tiene un valor de compra válido registrado en GLPI.</div><?php endif; ?>
    </div>
    <button class="btn btn-primary" type="submit">Guardar borrador</button> <a class="btn btn-secondary" href="<?= $embedded ? '/plugins/hh/front/quotes.php?embedded=1&itemtype='.rawurlencode($contextItemType).'&items_id='.$contextItemsId.'&new=1' : '/plugins/hh/front/quotes.php' ?>">Nueva / cancelar</a>
   </form>
   <?php endif; ?>
   <?php if ($editQuote && (string)$editQuote['status']==='draft'): ?>
    <form method="post" class="border rounded p-3 mb-4">
     <input type="hidden" name="_glpi_csrf_token" value="<?=hhQe(Session::getNewCSRFToken())?>">
     <input type="hidden" name="action" value="decide_quote"><input type="hidden" name="quote_id" value="<?=(int)$editQuote['id']?>">
     <div class="row g-2 align-items-end"><div class="col-md-8"><label class="form-label">Comentario de aprobación/rechazo</label><input class="form-control" name="comment" maxlength="2000" placeholder="Motivo o referencia de la decisión"></div><div class="col-md-2"><button class="btn btn-success w-100" name="decision" value="approved" type="submit">Aprobar</button></div><div class="col-md-2"><button class="btn btn-danger w-100" name="decision" value="rejected" type="submit">Rechazar</button></div></div>
     <p class="text-muted small mt-2 mb-0">Solo se permite decidir una cotización ya guardada. La decisión es final y bloquea su edición.</p>
    </form>
   <?php endif; ?>
  </div>
 </div>

 <?php if (!$embedded): ?>
 <div class="card hh-section mb-4">
  <div class="hh-section-head"><h3 class="card-title mb-0">Cotización actual</h3><small class="text-muted">Solo borradores pendientes de decisión. Cada activo puede tener un único borrador.</small></div>
  <div class="table-responsive"><table class="table table-hover table-striped mb-0"><thead><tr><th>N.º</th><th>Fecha</th><th>Activo</th><th>Proveedor</th><th class="text-end">Puntos</th><th class="text-end">Total USD</th><th>Acción</th></tr></thead><tbody>
  <?php $draftCount = 0; foreach ($allQuotes as $quote): if ((string)$quote['status'] !== 'draft') continue; $draftCount++; $label = hhQAssetLabel((string)$quote['itemtype'], (int)$quote['items_id']); ?>
   <tr><td><strong><?=hhQe((string)$quote['name'])?></strong><div class="small text-muted">Borrador editable</div></td><td><?=hhQe((string)($quote['quote_date'] ?? ''))?></td><td><?=hhQe($allowedTypes[(string)$quote['itemtype']] ?? (string)$quote['itemtype'])?> #<?=(int)$quote['items_id']?><div class="small text-muted"><?=hhQe($label)?></div></td><td><?=hhQe((string)($quote['supplier_name_snapshot'] ?? '—'))?></td><td class="text-end"><?=number_format((float)$quote['total_points'],2)?></td><td class="text-end fw-bold">$<?=number_format((float)$quote['total_amount_snapshot'],2)?></td><td><a class="btn btn-sm btn-primary" href="/plugins/hh/front/quotes.php?<?= $embedded ? 'embedded=1&itemtype='.rawurlencode($contextItemType).'&items_id='.$contextItemsId.'&' : '' ?>edit=<?=(int)$quote['id']?>">Abrir / editar</a></td></tr>
  <?php endforeach; ?>
  <?php if ($draftCount === 0): ?><tr><td colspan="7" class="text-center text-muted p-4">No hay borradores pendientes. Puede crear una cotización nueva.</td></tr><?php endif; ?>
  </tbody></table></div>
 </div>
 <div class="card hh-section">
  <div class="hh-section-head"><h3 class="card-title mb-0">Historial de cotizaciones</h3><small class="text-muted">Las cotizaciones aprobadas o rechazadas se conservan aquí y no bloquean una nueva cotización.</small></div>
  <div class="table-responsive"><table class="table table-hover table-striped mb-0"><thead><tr><th>N.º</th><th>Fecha</th><th>Activo</th><th>Proveedor</th><th>Decisión</th><th class="text-end">Puntos</th><th class="text-end">Total USD</th><th>Detalle</th></tr></thead><tbody>
  <?php $historyCount = 0; foreach ($allQuotes as $quote): if ((string)$quote['status'] === 'draft') continue; $historyCount++; $label = hhQAssetLabel((string)$quote['itemtype'], (int)$quote['items_id']); ?>
   <tr><td><?=hhQe((string)$quote['name'])?></td><td><?=hhQe((string)($quote['quote_date'] ?? ''))?></td><td><?=hhQe($allowedTypes[(string)$quote['itemtype']] ?? (string)$quote['itemtype'])?> #<?=(int)$quote['items_id']?><div class="small text-muted"><?=hhQe($label)?></div></td><td><?=hhQe((string)($quote['supplier_name_snapshot'] ?? '—'))?></td><td><span class="badge bg-<?=$quote['status'] === 'approved' ? 'success' : 'danger'?>"><?=hhQe($statuses[(string)$quote['status']] ?? (string)$quote['status'])?></span></td><td class="text-end"><?=number_format((float)$quote['total_points'],2)?></td><td class="text-end">$<?=number_format((float)$quote['total_amount_snapshot'],2)?></td><td><a class="btn btn-sm btn-outline-secondary" href="/plugins/hh/front/quotes.php?<?= $embedded ? 'embedded=1&itemtype='.rawurlencode($contextItemType).'&items_id='.$contextItemsId.'&' : '' ?>edit=<?=(int)$quote['id']?>">Ver detalle</a></td></tr>
  <?php endforeach; ?>
  <?php if ($historyCount === 0): ?><tr><td colspan="8" class="text-center text-muted p-4">Todavía no hay decisiones registradas.</td></tr><?php endif; ?>
  </tbody></table></div>
 </div>
 <?php endif; ?>
</div>
<script>
(() => {
 const catalog = <?=json_encode($catalogItems, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
 const initial = <?=json_encode($initialLines, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
 const assets = <?=json_encode($assetOptions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
 const warrantyItems = <?=json_encode($warrantyItems)?>;
 const pointValue = <?=json_encode((float)$point['value'])?>;
  const purchaseValue = <?=json_encode($purchaseValue)?>;
 const typeEl = document.getElementById('hh-asset-type');
 const tbody = document.getElementById('hh-lines');
 const addBtn = document.getElementById('hh-add-line');
 if (!tbody || !addBtn) return;
 const catLabel = {part:'Repuesto',labor:'Mano de obra',service:'Servicio',other:'Otro'};
 function allowed(item) {
   const t = typeEl.value;
   return item.types.some(x => x.itemtype === t);
 }
 function row(data={item_id:0,quantity:1,points_each:null}) {
   const tr=document.createElement('tr');
   const tdItem=document.createElement('td'), tdSource=document.createElement('td'), tdWarranty=document.createElement('td'), tdQty=document.createElement('td'), tdPoints=document.createElement('td'), tdPts=document.createElement('td'), tdMoney=document.createElement('td'), tdAction=document.createElement('td');
   const select=document.createElement('select'); select.className='form-select form-select-sm'; select.name='line_item_id[]'; select.required=true;
   const empty=document.createElement('option'); empty.value=''; empty.textContent='Seleccione artículo'; select.appendChild(empty);
   catalog.filter(allowed).forEach(item=>{const o=document.createElement('option');o.value=String(item.id);o.textContent=item.name;o.dataset.points=String(item.points);select.appendChild(o);});
   select.value=String(data.item_id||'');
   const autoWarranty = warrantyItems.includes(Number(data.item_id||0));
   const sourceType=document.createElement('input');sourceType.type='hidden';sourceType.name='line_source_type[]';sourceType.value=data.source_itemtype||'';
   const sourceId=document.createElement('input');sourceId.type='hidden';sourceId.name='line_source_id[]';sourceId.value=String(data.source_items_id||'');
   const sourceSelect=document.createElement('select');sourceSelect.className='form-select form-select-sm';sourceSelect.title='Activo del que se recuperó la pieza';
   const noReuse=document.createElement('option');noReuse.value='';noReuse.textContent='No reutilizada';sourceSelect.appendChild(noReuse);
   const currentAssetId = document.querySelector('[name="items_id"]')?.value || '';
   assets.filter(a=>!(a.itemtype===typeEl.value && String(a.id)===String(currentAssetId))).forEach(a=>{const o=document.createElement('option');o.value=a.itemtype+':'+String(a.id);o.textContent=a.label;sourceSelect.appendChild(o);});
   const savedSource = assets.find(a=>a.itemtype===(data.source_itemtype||'') && String(a.id)===String(data.source_items_id||''));
   sourceSelect.value=savedSource ? savedSource.itemtype+':'+String(savedSource.id) : '';
   sourceId.value=savedSource ? String(savedSource.id) : '';
   sourceType.value=savedSource ? savedSource.itemtype : '';
   const sourceHelp=document.createElement('div');sourceHelp.className='hh-help mt-1';sourceHelp.textContent='';
   tdSource.append(sourceSelect,sourceId,sourceType,sourceHelp);
   sourceSelect.addEventListener('change',()=>{const parts=sourceSelect.value.split(':');sourceType.value=parts.length===2?parts[0]:'';sourceId.value=parts.length===2?parts[1]:'';if(sourceSelect.value!=='')pts.value='0';totals();});
   const warrantyHidden=document.createElement('input');warrantyHidden.type='hidden';warrantyHidden.name='line_is_warranty[]';warrantyHidden.value=data.is_warranty?'1':'0';
    const warrantyCheck=document.createElement('input');warrantyCheck.type='checkbox';warrantyCheck.className='form-check-input';warrantyCheck.checked=!!Number(data.is_warranty||0)||autoWarranty;warrantyCheck.title=autoWarranty?'Este componente fue cotizado en una reparación retornada hace 60 días o menos. Verifique si aplica garantía.':'Marcar si el artículo está cubierto por garantía de una reparación anterior';
    const warrantyLabel=document.createElement('label');warrantyLabel.className='form-check-label small ms-1';warrantyLabel.textContent='Cubierto';
    const warrantyWrap=document.createElement('div');warrantyWrap.className='form-check';warrantyWrap.append(warrantyCheck,warrantyLabel,warrantyHidden);tdWarranty.appendChild(warrantyWrap);
    const qty=document.createElement('input');qty.type='number';qty.min='0.01';qty.step='0.01';qty.value=String(data.quantity||1);qty.name='line_quantity[]';qty.className='form-control form-control-sm';qty.required=true;
   const pts=document.createElement('input');pts.type='number';pts.min='0';pts.step='0.01';pts.value=data.points_each!==null&&data.points_each!==undefined?String(data.points_each):'0';pts.name='line_points[]';pts.className='form-control form-control-sm';pts.required=true;
   if(warrantyCheck.checked){warrantyHidden.value='1';pts.value='0';}
   const pointsTotal=document.createElement('span');const amount=document.createElement('span');
   const remove=document.createElement('button');remove.type='button';remove.className='btn btn-sm btn-outline-danger';remove.textContent='Quitar';remove.addEventListener('click',()=>{tr.remove();totals();});
   select.addEventListener('change',()=>{const opt=select.options[select.selectedIndex];const inWarranty=warrantyItems.includes(Number(select.value||0));if(inWarranty){warrantyCheck.checked=true;warrantyHidden.value='1';tr.classList.add('hh-warranty-active');}else{tr.classList.remove('hh-warranty-active');}if(opt&&opt.dataset.points!==undefined)pts.value=warrantyCheck.checked?'0':opt.dataset.points;totals();});
    warrantyCheck.addEventListener('change',()=>{warrantyHidden.value=warrantyCheck.checked?'1':'0';tr.classList.toggle('hh-warranty-active',warrantyCheck.checked);if(warrantyCheck.checked)pts.value='0';totals();});
   qty.addEventListener('input',totals);pts.addEventListener('input',totals);
   if(warrantyCheck.checked)tr.classList.add('hh-warranty-active');
   tdItem.appendChild(select);tdQty.appendChild(qty);tdPoints.appendChild(pts);tdPts.appendChild(pointsTotal);tdMoney.appendChild(amount);tdAction.appendChild(remove);
   [tdItem,tdSource,tdWarranty,tdQty,tdPoints,tdPts,tdMoney,tdAction].forEach(td=>tr.appendChild(td));tbody.appendChild(tr);tr._data={qty,pts,pointsTotal,amount};totals();
 }
 function totals(){
    let p=0,a=0;
    [...tbody.querySelectorAll('tr')].forEach(tr=>{
      const d=tr._data;if(!d)return;
      const rowP=(parseFloat(d.qty.value)||0)*(parseFloat(d.pts.value)||0);
      const rowA=rowP*pointValue;p+=rowP;a+=rowA;
      d.pointsTotal.textContent=rowP.toFixed(2);d.amount.textContent='$'+rowA.toFixed(2);
    });
    document.getElementById('hh-total-points').textContent=p.toFixed(2);
    document.getElementById('hh-total-amount').textContent='$'+a.toFixed(2);
    const ratio=document.getElementById('hh-repair-cost-ratio');
    const warning=document.getElementById('hh-repair-cost-warning');
    if(ratio){
      if(purchaseValue!==null&&Number(purchaseValue)>0){
        const pct=(a/Number(purchaseValue))*100;
        ratio.textContent='Costo de reparación: '+pct.toFixed(1)+' % del valor de compra';
        if(warning)warning.style.display=pct>=40?'block':'none';
      }else{
        ratio.textContent='Porcentaje del valor de compra: no disponible';
        if(warning)warning.style.display='none';
      }
    }
  }
  function refreshRows(){
    const old=[...tbody.querySelectorAll('tr')].map(tr=>({
      item_id:tr.querySelector('[name="line_item_id[]"]').value,
      quantity:tr.querySelector('[name="line_quantity[]"]').value,
      points_each:tr.querySelector('[name="line_points[]"]').value,
      source_itemtype:tr.querySelector('[name="line_source_type[]"]').value,
      source_items_id:tr.querySelector('[name="line_source_id[]"]').value,
      is_warranty:tr.querySelector('[name="line_is_warranty[]"]').value
    }));
    tbody.replaceChildren();
    const valid=old.filter(x=>catalog.some(i=>String(i.id)===String(x.item_id)&&allowed(i)));
    valid.forEach(x=>row({item_id:Number(x.item_id),quantity:Number(x.quantity),points_each:Number(x.points_each),source_itemtype:x.source_itemtype,source_items_id:Number(x.source_items_id||0),is_warranty:Number(x.is_warranty||0)}));
    if(!tbody.children.length)row();
  }
  addBtn.addEventListener('click',()=>row());
  typeEl.addEventListener('change',refreshRows);
  if(initial.length){initial.forEach(x=>row(x));} else {row();}
})();
</script>
<?php if (!$embedded) Html::footer(); ?>
