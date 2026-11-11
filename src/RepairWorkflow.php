<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonDBTM;
use Session;

/**
 * Synchronous repair workflow for native and GLPI 11 custom assets.
 * The configured request origin identifies repair intake tickets so ordinary
 * support tickets never change an asset's state by accident.
 */
final class RepairWorkflow
{
    public const STATUS_SYSTEMS = 'REPARACIÓN Sistemas';
    public const STATUS_SUPPLIER = 'REPARACIÓN Proveedor';
    public const STATUS_FREE = 'LIBRE';
    public const STATUS_DAMAGED = 'DAÑADO';

    public static function getConfig(): array
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['*'],
            'FROM' => 'glpi_plugin_hh_workflow_config',
            'WHERE' => ['id' => 1],
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return ['id' => 1, 'requesttypes_id' => 0, 'enabled' => 0];
    }

    public static function saveConfig(int $requestTypeId, bool $enabled): bool
    {
        global $DB;
        $data = [
            'requesttypes_id' => max(0, $requestTypeId),
            'enabled' => $enabled ? 1 : 0,
        ];
        if ($DB->update('glpi_plugin_hh_workflow_config', $data, ['id' => 1])) {
            return true;
        }
        return false;
    }

    /** Called immediately after GLPI creates an Item_Ticket relation. */
    public static function onTicketItemAdded(CommonDBTM $relation): void
    {
        global $DB, $CFG_GLPI;

        $fields = $relation->fields ?? [];
        $ticketId = (int) ($fields['tickets_id'] ?? 0);
        $itemtype = (string) ($fields['itemtype'] ?? '');
        $itemsId = (int) ($fields['items_id'] ?? 0);
        if ($ticketId < 1 || $itemsId < 1 || $itemtype === '') {
            return;
        }

        $config = self::getConfig();
        $requestTypeId = (int) ($config['requesttypes_id'] ?? 0);
        if ((int) ($config['enabled'] ?? 0) !== 1 || $requestTypeId < 1) {
            return;
        }

        $ticket = new \Ticket();
        if (!$ticket->getFromDB($ticketId)
            || (int) ($ticket->fields['requesttypes_id'] ?? 0) !== $requestTypeId) {
            return;
        }

        $assetTypes = $CFG_GLPI['asset_types'] ?? [];
        if (!in_array($itemtype, $assetTypes, true)
            || !class_exists($itemtype)
            || !is_a($itemtype, CommonDBTM::class, true)) {
            return;
        }

        $targetState = self::findStateId(self::STATUS_SYSTEMS);
        if ($targetState < 1) {
            \Toolbox::logError('HH: No se cambió el estado del activo porque no existe el estado ' . self::STATUS_SYSTEMS . '.');
            return;
        }

        if (!self::changeAssetState(
            $itemtype,
            $itemsId,
            $targetState,
            'receive_systems',
            $ticketId,
            'Recepción de equipo mediante catálogo de servicios'
        )) {
            \Toolbox::logError('HH: Falló el cambio inmediato a REPARACIÓN Sistemas para ' . $itemtype . ' #' . $itemsId . ' desde el ticket #' . $ticketId . '.');
        }
    }


    /** Verify that an asset is currently in the supplier-repair state. */
    public static function isInSupplierRepairState(string $itemtype, int $itemsId): bool
    {
        global $DB, $CFG_GLPI;
        if ($itemsId < 1 || !in_array($itemtype, $CFG_GLPI['asset_types'] ?? [], true)
            || !class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return false;
        }
        $asset = new $itemtype();
        if (!$asset->getFromDB($itemsId)) return false;
        $stateId = (int) ($asset->fields['states_id'] ?? 0);
        if ($stateId < 1 || !$DB->tableExists('glpi_states')) return false;
        foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_states', 'WHERE' => ['id' => $stateId], 'LIMIT' => 1]) as $state) {
            return self::normalize((string) ($state['name'] ?? '')) === self::normalize(self::STATUS_SUPPLIER);
        }
        return false;
    }

    /**
     * Find the latest linked ticket with the required supplier dispatch data.
     * Non-HH assets require guide + dispatch date; HH additionally requires
     * supplier-received date.
     */
    public static function getSupplierTrackingForAsset(string $itemtype, int $itemsId, bool $requireSupplierReceived): ?array
    {
        global $DB;
        if ($itemsId < 1 || !$DB->tableExists('glpi_items_tickets')
            || !$DB->tableExists('glpi_plugin_fields_ticketreparacions')) return null;
        $required = ['nmerodeguafield', 'fechaenvioaproveedorfieldtwo'];
        if ($requireSupplierReceived) $required[] = 'fecharecepcionproveedorfieldtwo';
        foreach ($required as $field) {
            if (!$DB->fieldExists('glpi_plugin_fields_ticketreparacions', $field)) return null;
        }
        foreach ($DB->request([
            'SELECT' => ['tickets_id'],
            'FROM' => 'glpi_items_tickets',
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId],
            'ORDER' => ['tickets_id DESC'],
        ]) as $link) {
            $ticketId = (int) ($link['tickets_id'] ?? 0);
            if ($ticketId < 1) continue;
            foreach ($DB->request([
                'SELECT' => $required,
                'FROM' => 'glpi_plugin_fields_ticketreparacions',
                'WHERE' => ['items_id' => $ticketId, 'itemtype' => 'Ticket', 'plugin_fields_containers_id' => 15],
                'LIMIT' => 1,
            ]) as $fields) {
                $guide = trim((string) ($fields['nmerodeguafield'] ?? ''));
                $sent = trim((string) ($fields['fechaenvioaproveedorfieldtwo'] ?? ''));
                $received = trim((string) ($fields['fecharecepcionproveedorfieldtwo'] ?? ''));
                $validDate = static function (string $value): bool {
                    if ($value === '' || str_starts_with($value, '0000-00-00')) return false;
                    try { new DateTimeImmutable($value); return true; } catch (Throwable $e) { return false; }
                };
                if ($guide !== '' && $validDate($sent) && (!$requireSupplierReceived || $validDate($received))) {
                    return [
                        'tickets_id' => $ticketId,
                        'guide_number' => $guide,
                        'sent_to_supplier_at' => $sent,
                        'supplier_received_at' => $received,
                    ];
                }
            }
        }
        return null;
    }

    public static function changeAssetState(
        string $itemtype,
        int $itemsId,
        int $newStateId,
        string $action,
        int $ticketId = 0,
        string $notes = ''
    ): bool {
        global $DB, $CFG_GLPI;

        if ($itemsId < 1 || $newStateId < 1
            || !in_array($itemtype, $CFG_GLPI['asset_types'] ?? [], true)
            || !class_exists($itemtype)
            || !is_a($itemtype, CommonDBTM::class, true)) {
            return false;
        }

        $asset = new $itemtype();
        if (!$asset->getFromDB($itemsId)) {
            return false;
        }

        $table = $itemtype::getTable();
        if (!$DB->fieldExists($table, 'states_id')) {
            return false;
        }

        $oldStateId = (int) ($asset->fields['states_id'] ?? 0);
        if ($oldStateId === $newStateId) {
            return true;
        }

        // Use the GLPI object API and explicitly enable native history.
        $asset->dohistory = true;
        if (!$asset->update(['id' => $itemsId, 'states_id' => $newStateId])) {
            return false;
        }

        $DB->insert('glpi_plugin_hh_asset_history', [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'tickets_id' => $ticketId > 0 ? $ticketId : null,
            'old_states_id' => $oldStateId,
            'new_states_id' => $newStateId,
            'action' => mb_substr($action, 0, 40),
            'notes' => $notes !== '' ? $notes : null,
            'users_id' => (int) Session::getLoginUserID(),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public static function findStateId(string $name): int
    {
        global $DB;
        if (!$DB->tableExists('glpi_states')) {
            return 0;
        }
        $wanted = self::normalize($name);
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_states']) as $row) {
            if (self::normalize((string) ($row['name'] ?? '')) === $wanted) {
                return (int) $row['id'];
            }
        }
        return 0;
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) {
                $value = $ascii;
            }
        }
        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }

    /** Counts are based on the complete, permission-filtered inventory. */
    public static function getDashboardCounts(array $assets): array
    {
        $counts = [
            'systems' => 0,
            'supplier' => 0,
            'free' => 0,
            'damaged' => 0,
            'assigned' => 0,
            'other' => 0,
            'total' => 0,
        ];
        $assignedStates = [
            self::normalize('ASIGNADO'),
            self::normalize('PRESTAMO'),
            self::normalize('RESERVADO'),
        ];
        foreach ($assets as $asset) {
            $counts['total']++;
            $status = self::normalize((string) ($asset['status_name'] ?? ''));
            if ($status === self::normalize(self::STATUS_SYSTEMS)) {
                $counts['systems']++;
            } elseif ($status === self::normalize(self::STATUS_SUPPLIER)) {
                $counts['supplier']++;
            } elseif ($status === self::normalize(self::STATUS_FREE)) {
                $counts['free']++;
            } elseif ($status === self::normalize(self::STATUS_DAMAGED)) {
                $counts['damaged']++;
            } elseif (in_array($status, $assignedStates, true)) {
                $counts['assigned']++;
            } else {
                $counts['other']++;
            }
        }
        return $counts;
    }

    public static function getAssetHistory(string $itemtype, int $itemsId): array
    {
        global $DB;
        $history = [];
        if ($itemsId < 1 || !$DB->tableExists('glpi_plugin_hh_asset_history')) {
            return [];
        }
        foreach ($DB->request([
            'SELECT' => '*',
            'FROM' => 'glpi_plugin_hh_asset_history',
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId],
            'ORDER' => ['date_creation DESC', 'id DESC'],
            'LIMIT' => 30,
        ]) as $row) {
            $row['old_state_name'] = Reports::dropdownName('glpi_states', (int) ($row['old_states_id'] ?? 0));
            $row['new_state_name'] = Reports::dropdownName('glpi_states', (int) ($row['new_states_id'] ?? 0));
            $history[] = $row;
        }
        return $history;
    }

    /** Read-only movement history. State transitions after reception are managed manually in GLPI. */
    public static function renderAssetPanel(CommonDBTM $asset): void
    {
        $itemtype = $asset::getType();
        $itemsId = (int) $asset->getID();
        $history = self::getAssetHistory($itemtype, $itemsId);

        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">Historial de recepción de reparación</h3></div><div class="card-body">';
        echo '<p class="text-muted">El estado cambia automáticamente a REPARACIÓN Sistemas al crear el ticket desde el formulario configurado. Los cambios posteriores de estado se realizan manualmente en GLPI.</p>';
        if ($history === []) {
            echo '<div class="text-muted">No hay movimientos registrados por la recepción automática.</div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Fecha</th><th>Movimiento</th><th>Estado anterior</th><th>Estado nuevo</th><th>Ticket</th><th>Usuario</th></tr></thead><tbody>';
            foreach ($history as $row) {
                echo '<tr><td>' . htmlspecialchars((string) $row['date_creation'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars((string) $row['action'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars((string) $row['old_state_name'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars((string) $row['new_state_name'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . ((int) ($row['tickets_id'] ?? 0) > 0 ? '<a href="/front/ticket.form.php?id=' . (int) $row['tickets_id'] . '">' . (int) $row['tickets_id'] . '</a>' : '—') . '</td>';
                echo '<td>' . (int) ($row['users_id'] ?? 0) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
    }
}
