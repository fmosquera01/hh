<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

final class RepairAlerts
{
    public static function getPolicy(): array
    {
        global $DB;
        $p = ['repair_repeat_days' => 60, 'repair_cost_percent' => 40.0, 'renewal_years' => 5];
        if (!$DB->tableExists('glpi_plugin_hh_general_quote_config')) return $p;
        foreach ($DB->request(['SELECT' => ['repair_repeat_days','repair_cost_percent','renewal_years'], 'FROM' => 'glpi_plugin_hh_general_quote_config', 'WHERE' => ['id' => 1], 'LIMIT' => 1]) as $r) {
            $p['repair_repeat_days'] = max(1, min(365, (int)($r['repair_repeat_days'] ?? 60)));
            $p['repair_cost_percent'] = max(1, min(100, (float)($r['repair_cost_percent'] ?? 40)));
            $p['renewal_years'] = max(1, min(20, (int)($r['renewal_years'] ?? 5)));
        }
        return $p;
    }

    public static function getPurchaseValue(string $itemtype, int $itemsId): ?float
    {
        global $DB;
        if ($itemtype === '' || $itemsId < 1 || !$DB->tableExists('glpi_infocoms')) return null;
        foreach ($DB->request(['SELECT' => ['value'], 'FROM' => 'glpi_infocoms', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId], 'LIMIT' => 1]) as $r) {
            $v = $r['value'] ?? null;
            return is_numeric($v) && (float)$v > 0 ? (float)$v : null;
        }
        return null;
    }

    public static function getLastReturnDate(string $itemtype, int $itemsId): ?string
    {
        global $DB;
        if ($itemtype === '' || $itemsId < 1) return null;
        $latest = null;
        foreach ([
            ['glpi_plugin_hh_quotes', ['approved']],
            ['glpi_plugin_hh_general_quotes', ['approved', 'selected']],
        ] as [$table, $statuses]) {
            if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'returned_at')) continue;
            foreach ($DB->request(['SELECT' => ['returned_at'], 'FROM' => $table, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId, 'status' => $statuses]]) as $r) {
                $date = self::validDate((string)($r['returned_at'] ?? ''));
                if ($date !== null && ($latest === null || $date > $latest)) $latest = $date;
            }
        }
        $links = 'glpi_items_tickets';
        $fields = 'glpi_plugin_fields_ticketreparacions';
        if ($DB->tableExists($links) && $DB->tableExists($fields)
            && $DB->fieldExists($fields, 'fecharecepcionguiafieldtwo')
            && $DB->fieldExists($fields, 'items_id') && $DB->fieldExists($fields, 'itemtype')
            && $DB->fieldExists($fields, 'plugin_fields_containers_id')) {
            $ids = [];
            foreach ($DB->request(['SELECT' => ['tickets_id'], 'FROM' => $links, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId]]) as $r) {
                $id = (int)($r['tickets_id'] ?? 0); if ($id > 0) $ids[$id] = $id;
            }
            if ($ids !== []) foreach ($DB->request(['SELECT' => ['fecharecepcionguiafieldtwo'], 'FROM' => $fields, 'WHERE' => ['items_id' => array_values($ids), 'itemtype' => 'Ticket', 'plugin_fields_containers_id' => 15]]) as $r) {
                $date = self::validDate((string)($r['fecharecepcionguiafieldtwo'] ?? ''));
                if ($date !== null && ($latest === null || $date > $latest)) $latest = $date;
            }
        }
        return $latest;
    }

    public static function getRepeatRepairWarning(string $itemtype, int $itemsId): ?array
    {
        $date = self::getLastReturnDate($itemtype, $itemsId);
        if ($date === null) return null;
        try {
            $returned = new \DateTimeImmutable($date);
            $today = new \DateTimeImmutable('today');
            if ($returned > $today) return null;
            $days = (int)$returned->diff($today)->format('%a');
            $window = self::getPolicy()['repair_repeat_days'];
            return $days <= $window ? ['returned_at' => $date, 'days' => $days, 'window' => $window] : null;
        } catch (\Throwable $e) { return null; }
    }

    private static function validDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) return null;
        try { return (new \DateTimeImmutable($value))->format('Y-m-d'); } catch (\Throwable $e) { return null; }
    }
}
