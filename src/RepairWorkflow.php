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

    /** Called immediately after GLPI creates a Ticket_Item relation. */
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

    public static function getOriginalStateForOpenRepair(string $itemtype, int $itemsId): int
    {
        global $DB;
        $rows = $DB->request([
            'SELECT' => ['action', 'old_states_id'],
            'FROM' => 'glpi_plugin_hh_asset_history',
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId],
            'ORDER' => ['id DESC'],
        ]);
        foreach ($rows as $row) {
            $action = (string) ($row['action'] ?? '');
            if ($action === 'finish') {
                return 0;
            }
            if ($action === 'receive_systems') {
                return (int) ($row['old_states_id'] ?? 0);
            }
        }
        return 0;
    }

    public static function renderAssetPanel(CommonDBTM $asset): void
    {
        $itemtype = $asset::getType();
        $itemsId = (int) $asset->getID();
        $fields = $asset->fields ?? [];
        $stateName = Reports::dropdownName('glpi_states', (int) ($fields['states_id'] ?? 0));
        $history = self::getAssetHistory($itemtype, $itemsId);
        $isRepair = in_array(self::normalize($stateName), [
            self::normalize(self::STATUS_SYSTEMS),
            self::normalize(self::STATUS_SUPPLIER),
        ], true);
        $canUpdate = Rights::can(Rights::UPDATE) && $asset->can($itemsId, UPDATE);

        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">Flujo general de reparación</h3></div><div class="card-body">';
        echo '<p><strong>Estado actual:</strong> ' . htmlspecialchars($stateName !== '' ? $stateName : 'Sin estado', ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p class="text-muted">La recepción desde el catálogo de servicios cambia el estado a REPARACIÓN Sistemas en tiempo real. Los movimientos posteriores se registran aquí y en el historial nativo de GLPI.</p>';

        if ($canUpdate && $isRepair) {
            echo '<div class="d-flex flex-wrap gap-2 mb-3">';
            if (self::normalize($stateName) === self::normalize(self::STATUS_SYSTEMS)) {
                self::actionForm($itemtype, $itemsId, 'send_supplier', 'Enviar a proveedor', 'btn btn-outline-primary');
                self::actionForm($itemtype, $itemsId, 'finish', 'Finalizar reparación', 'btn btn-success');
            } elseif (self::normalize($stateName) === self::normalize(self::STATUS_SUPPLIER)) {
                self::actionForm($itemtype, $itemsId, 'return_systems', 'Regresó del proveedor', 'btn btn-outline-secondary');
            }
            echo '</div>';
        } elseif (!$canUpdate) {
            echo '<div class="text-muted small mb-3">Se requiere permiso de actualización del activo y del plugin para cambiar la etapa de reparación.</div>';
        }

        echo '<h4 class="mt-3">Historial de movimientos</h4>';
        if ($history === []) {
            echo '<div class="text-muted">No hay movimientos registrados por el flujo general.</div>';
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

    private static function actionForm(string $itemtype, int $itemsId, string $action, string $label, string $class): void
    {
        echo '<form method="post" action="/plugins/hh/front/workflow.php" class="d-inline">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="itemtype" value="' . htmlspecialchars($itemtype, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="items_id" value="' . $itemsId . '">';
        echo '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
        echo '<button type="submit" class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button></form>';
    }

    public static function processAction(string $itemtype, int $itemsId, string $action): bool
    {
        global $CFG_GLPI;
        if (!in_array($itemtype, $CFG_GLPI['asset_types'] ?? [], true) || !class_exists($itemtype)) {
            return false;
        }
        $asset = new $itemtype();
        if (!$asset->getFromDB($itemsId) || !$asset->can($itemsId, UPDATE) || !Rights::can(Rights::UPDATE)) {
            return false;
        }

        $currentState = (int) ($asset->fields['states_id'] ?? 0);
        $systemsState = self::findStateId(self::STATUS_SYSTEMS);
        $supplierState = self::findStateId(self::STATUS_SUPPLIER);

        if ($action === 'send_supplier') {
            if ($systemsState < 1 || $currentState !== $systemsState) {
                return false;
            }
            return $supplierState > 0 && self::changeAssetState($itemtype, $itemsId, $supplierState, $action, 0, 'Equipo enviado a proveedor');
        }
        if ($action === 'return_systems') {
            if ($supplierState < 1 || $currentState !== $supplierState) {
                return false;
            }
            return $systemsState > 0 && self::changeAssetState($itemtype, $itemsId, $systemsState, $action, 0, 'Equipo recibido del proveedor para validación');
        }
        if ($action === 'finish') {
            if ($systemsState < 1 || $currentState !== $systemsState) {
                return false;
            }
            $target = self::getOriginalStateForOpenRepair($itemtype, $itemsId);
            if ($target < 1) {
                return false;
            }
            return self::changeAssetState($itemtype, $itemsId, $target, 'finish', 0, 'Reparación finalizada; se restaura el estado previo a la recepción');
        }
        return false;
    }
}
