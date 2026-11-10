<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonDBTM;

final class Reports
{
    /** Discover native and GLPI 11 custom asset classes registered by GLPI. */
    public static function getAssetTypes(): array
    {
        global $CFG_GLPI;
        $types = [];
        foreach (($CFG_GLPI['asset_types'] ?? []) as $type) {
            if (is_string($type) && class_exists($type) && is_a($type, CommonDBTM::class, true)) {
                $types[] = $type;
            }
        }
        return array_values(array_unique($types));
    }

    public static function getInventory(?string $search = null, ?string $filterType = null): array
    {
        global $DB;
        $assets = [];
        foreach (self::getAssetTypes() as $itemtype) {
            if ($filterType !== null && $filterType !== '' && $filterType !== $itemtype) {
                continue;
            }
            try {
                $table = $itemtype::getTable();
                if (!$DB->tableExists($table)) {
                    continue;
                }
                $isCustom = is_a($itemtype, 'Glpi\\Asset\\Asset', true);
                $fields = ['id', 'name'];
                foreach (['serial', 'otherserial', 'manufacturers_id', 'states_id', 'locations_id', 'date_creation', 'is_deleted', 'is_template'] as $field) {
                    if ($DB->fieldExists($table, $field)) {
                        $fields[] = $field;
                    }
                }
                $where = [];
                if ($DB->fieldExists($table, 'is_deleted')) {
                    $where['is_deleted'] = 0;
                }
                if ($DB->fieldExists($table, 'is_template')) {
                    $where['is_template'] = 0;
                }
                // Custom asset classes share a table; filter by their definition ID.
                if ($isCustom && $DB->fieldExists($table, 'assets_assetdefinitions_id')) {
                    $definition = $itemtype::getDefinition();
                    $definitionId = (int) ($definition->fields['id'] ?? 0);
                    if ($definitionId > 0) {
                        $where['assets_assetdefinitions_id'] = $definitionId;
                    }
                }
                $params = ['SELECT' => $fields, 'FROM' => $table, 'ORDER' => ['name ASC']];
                if ($where !== []) {
                    $params['WHERE'] = $where;
                }
                foreach ($DB->request($params) as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id < 1) {
                        continue;
                    }
                    $assets[$itemtype . ':' . $id] = [
                        'itemtype' => $itemtype,
                        'type_name' => $itemtype::getTypeName(1),
                        'id' => $id,
                        'name' => (string) ($row['name'] ?? ''),
                        'serial' => (string) ($row['serial'] ?? ''),
                        'otherserial' => (string) ($row['otherserial'] ?? ''),
                        'manufacturer' => self::dropdownName('glpi_manufacturers', (int) ($row['manufacturers_id'] ?? 0)),
                        'purchase_date' => '',
                        'purchase_value' => null,
                        'warranty_end' => '',
                        'repair_count' => 0,
                        'points_consumed' => 0.0,
                    ];
                }
            } catch (\Throwable $e) {
                // Skip unavailable or inactive custom definitions without breaking the report.
                continue;
            }
        }
        self::addFinancialAndRepairData($assets);
        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $assets = array_filter($assets, static function (array $asset) use ($needle): bool {
                return str_contains(mb_strtolower(implode(' ', [
                    $asset['name'], $asset['serial'], $asset['otherserial'],
                    $asset['manufacturer'], $asset['type_name'],
                ])), $needle);
            });
        }
        return array_values($assets);
    }

    private static function addFinancialAndRepairData(array &$assets): void
    {
        global $DB;
        if ($assets === []) {
            return;
        }
        $infocomRows = [];
        if ($DB->tableExists('glpi_infocoms')) {
            foreach ($DB->request([
                'SELECT' => ['itemtype', 'items_id', 'buy_date', 'value', 'warranty_date', 'warranty_duration'],
                'FROM' => 'glpi_infocoms',
            ]) as $info) {
                $infocomRows[(string) $info['itemtype'] . ':' . (int) $info['items_id']] = $info;
            }
        }
        $repairRows = [];
        if ($DB->tableExists('glpi_plugin_hh_quotes')) {
            foreach ($DB->request([
                'SELECT' => ['itemtype', 'items_id', 'status', 'total_points'],
                'FROM' => 'glpi_plugin_hh_quotes',
            ]) as $quote) {
                $key = (string) ($quote['itemtype'] ?? '') . ':' . (int) ($quote['items_id'] ?? 0);
                if (!isset($repairRows[$key])) {
                    $repairRows[$key] = ['count' => 0, 'points' => 0.0];
                }
                if ((string) ($quote['status'] ?? '') === 'approved') {
                    $repairRows[$key]['count']++;
                    $repairRows[$key]['points'] += (float) ($quote['total_points'] ?? 0);
                }
            }
        }
        foreach ($assets as $key => &$asset) {
            if (isset($infocomRows[$key])) {
                $info = $infocomRows[$key];
                $asset['purchase_date'] = (string) ($info['buy_date'] ?? '');
                $asset['purchase_value'] = is_numeric($info['value'] ?? null) ? (float) $info['value'] : null;
                $start = (string) ($info['warranty_date'] ?? '');
                $months = (int) ($info['warranty_duration'] ?? 0);
                if ($start !== '' && $start !== '0000-00-00' && $months > 0) {
                    try {
                        $asset['warranty_end'] = (new \DateTimeImmutable($start))->modify('+' . $months . ' months')->format('Y-m-d');
                    } catch (\Throwable $e) {
                        $asset['warranty_end'] = '';
                    }
                }
            }
            if (isset($repairRows[$key])) {
                $asset['repair_count'] = $repairRows[$key]['count'];
                $asset['points_consumed'] = $repairRows[$key]['points'];
            }
        }
        unset($asset);
    }

    public static function getPointMovements(int $year): array
    {
        global $DB;
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = ['purchased' => 0.0, 'consumed' => 0.0];
        }
        if (!$DB->tableExists('glpi_plugin_hh_pointmovements')) {
            return array_values($months);
        }
        foreach ($DB->request([
            'SELECT' => ['movement_date', 'movement_type', 'points'],
            'FROM' => 'glpi_plugin_hh_pointmovements',
        ]) as $movement) {
            $movementDate = (string) ($movement['movement_date'] ?? '');
            if (substr($movementDate, 0, 4) !== (string) $year) {
                continue;
            }
            $month = (int) substr($movementDate, 5, 2);
            if (!isset($months[$month])) {
                continue;
            }
            $points = (float) ($movement['points'] ?? 0);
            if ((string) ($movement['movement_type'] ?? '') === 'purchase') {
                $months[$month]['purchased'] += abs($points);
            } else {
                $months[$month]['consumed'] += abs($points);
            }
        }
        return array_values($months);
    }

    public static function dropdownName(string $table, int $id): string
    {
        global $DB;
        if ($id < 1 || !in_array($table, ['glpi_manufacturers', 'glpi_states', 'glpi_locations'], true) || !$DB->tableExists($table)) {
            return '';
        }
        foreach ($DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
            return (string) ($row['name'] ?? '');
        }
        return '';
    }
}
