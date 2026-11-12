<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonDBTM;
use Session;

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

    /**
     * Resolve GLPI's native subtype/model fields for an asset class.
     * Example: Phone => phonetypes_id / phonemodels_id.
     */
    private static function getSubtypeModelMetadata(string $itemtype): array
    {
        global $DB;
        $shortName = (new \ReflectionClass($itemtype))->getShortName();
        $prefix = strtolower($shortName);
        $table = $itemtype::getTable();

        $typeField = $prefix . 'types_id';
        $modelField = $prefix . 'models_id';
        $typeTable = 'glpi_' . $prefix . 'types';
        $modelTable = 'glpi_' . $prefix . 'models';

        return [
            'type_field' => $DB->fieldExists($table, $typeField) ? $typeField : null,
            'model_field' => $DB->fieldExists($table, $modelField) ? $modelField : null,
            'type_table' => $DB->tableExists($typeTable) ? $typeTable : null,
            'model_table' => $DB->tableExists($modelTable) ? $modelTable : null,
        ];
    }

    public static function getInventory(?string $search = null, ?string $filterType = null): array
    {
        global $DB;
        $assets = [];
        // Raw DB queries must preserve GLPI's active entity scope.
        $activeEntities = Session::getActiveEntities();
        foreach (self::getAssetTypes() as $itemtype) {
            // Reports must never bypass the user's native GLPI asset permissions.
            if (!property_exists($itemtype, 'rightname') || !Session::haveRight($itemtype::$rightname, READ)) {
                continue;
            }
            if ($filterType !== null && $filterType !== '' && $filterType !== $itemtype) {
                continue;
            }
            try {
                $table = $itemtype::getTable();
                if (!$DB->tableExists($table)) {
                    continue;
                }
                $metadata = self::getSubtypeModelMetadata($itemtype);
                $isCustom = is_a($itemtype, 'Glpi\\Asset\\Asset', true);
                $fields = ['id', 'name'];
                foreach (['serial', 'otherserial', 'manufacturers_id', 'states_id', 'date_creation', 'is_deleted', 'is_template'] as $field) {
                    if ($DB->fieldExists($table, $field)) {
                        $fields[] = $field;
                    }
                }
                foreach (['type_field', 'model_field'] as $metadataField) {
                    if ($metadata[$metadataField] !== null) {
                        $fields[] = $metadata[$metadataField];
                    }
                }
                $where = [];
                if ($DB->fieldExists($table, 'is_deleted')) {
                    $where['is_deleted'] = 0;
                }
                if ($DB->fieldExists($table, 'is_template')) {
                    $where['is_template'] = 0;
                }
                // Apply the active entity list because direct DBAL reads do not add GLPI entity criteria.
                if ($DB->fieldExists($table, 'entities_id')) {
                    if ($activeEntities === []) {
                        continue;
                    }
                    $where['entities_id'] = $activeEntities;
                }
                // Custom asset classes share a table; filter by their definition ID.
                if ($isCustom && $DB->fieldExists($table, 'assets_assetdefinitions_id')) {
                    $definition = $itemtype::getDefinition();
                    $definitionId = (int) ($definition->fields['id'] ?? 0);
                    if ($definitionId > 0) {
                        $where['assets_assetdefinitions_id'] = $definitionId;
                    }
                }
                $params = ['SELECT' => array_values(array_unique($fields)), 'FROM' => $table, 'ORDER' => ['name ASC']];
                if ($where !== []) {
                    $params['WHERE'] = $where;
                }
                foreach ($DB->request($params) as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id < 1) {
                        continue;
                    }
                    $typeId = $metadata['type_field'] !== null ? (int) ($row[$metadata['type_field']] ?? 0) : 0;
                    $modelId = $metadata['model_field'] !== null ? (int) ($row[$metadata['model_field']] ?? 0) : 0;
                    $assets[$itemtype . ':' . $id] = [
                        'itemtype' => $itemtype,
                        'type_name' => $itemtype::getTypeName(1),
                        'subtype_id' => $typeId,
                        'subtype_name' => $metadata['type_table'] !== null ? self::dropdownName($metadata['type_table'], $typeId) : '',
                        'model_id' => $modelId,
                        'model_name' => $metadata['model_table'] !== null ? self::dropdownName($metadata['model_table'], $modelId) : '',
                        'id' => $id,
                        'name' => (string) ($row['name'] ?? ''),
                        'serial' => (string) ($row['serial'] ?? ''),
                        'otherserial' => (string) ($row['otherserial'] ?? ''),
                        'status_id' => (int) ($row['states_id'] ?? 0),
                        'status_name' => self::dropdownName('glpi_states', (int) ($row['states_id'] ?? 0)),
                        'manufacturer' => self::dropdownName('glpi_manufacturers', (int) ($row['manufacturers_id'] ?? 0)),
                        'purchase_date' => '',
                        'purchase_value' => null,
                        'warranty_end' => '',
                        'approved_quote_count' => 0,
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
                    $asset['subtype_name'], $asset['model_name'],
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
        $itemTypes = [];
        $itemIds = [];
        $assetKeys = [];
        foreach ($assets as $asset) {
            $itemtype = (string)($asset['itemtype'] ?? '');
            $itemsId = (int)($asset['id'] ?? 0);
            if ($itemtype === '' || $itemsId < 1) {
                continue;
            }
            $itemTypes[$itemtype] = $itemtype;
            $itemIds[$itemsId] = $itemsId;
            $assetKeys[$itemtype . ':' . $itemsId] = true;
        }

        $infocomRows = [];
        if ($itemTypes !== [] && $itemIds !== [] && $DB->tableExists('glpi_infocoms')) {
            foreach ($DB->request([
                'SELECT' => ['itemtype', 'items_id', 'buy_date', 'value', 'warranty_date', 'warranty_duration'],
                'FROM' => 'glpi_infocoms',
                'WHERE' => [
                    'itemtype' => array_values($itemTypes),
                    'items_id' => array_values($itemIds),
                ],
            ]) as $info) {
                $key = (string)$info['itemtype'] . ':' . (int)$info['items_id'];
                if (isset($assetKeys[$key])) {
                    $infocomRows[$key] = $info;
                }
            }
        }
        $repairRows = [];
        if ($itemTypes !== [] && $itemIds !== [] && $DB->tableExists('glpi_plugin_hh_quotes')) {
            foreach ($DB->request([
                'SELECT' => ['itemtype', 'items_id', 'tickets_id', 'status', 'total_points'],
                'FROM' => 'glpi_plugin_hh_quotes',
                'WHERE' => [
                    'itemtype' => array_values($itemTypes),
                    'items_id' => array_values($itemIds),
                ],
            ]) as $quote) {
                $key = (string) ($quote['itemtype'] ?? '') . ':' . (int) ($quote['items_id'] ?? 0);
                if (!isset($assetKeys[$key])) {
                    continue;
                }
                if (!isset($repairRows[$key])) {
                    $repairRows[$key] = ['count' => 0, 'points' => 0.0];
                }
                $ticketId = (int)($quote['tickets_id'] ?? 0);
                if ((string) ($quote['status'] ?? '') === 'approved'
                    && $ticketId > 0
                    && RepairWorkflow::isRepairTicket($ticketId)
                    && RepairWorkflow::ensureRepairRecord($ticketId, (string)$quote['itemtype'], (int)$quote['items_id'])) {
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
                $asset['approved_quote_count'] = $repairRows[$key]['count'];
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
        static $cache = [];

        if (
            $id < 1
            || !preg_match('/^glpi_[a-z0-9_]+$/', $table)
        ) {
            return '';
        }

        $key = $table . ':' . $id;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'name')) {
            $cache[$key] = '';
            return '';
        }

        foreach ($DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
            $cache[$key] = (string) ($row['name'] ?? '');
            return $cache[$key];
        }

        $cache[$key] = '';
        return '';
    }
}
