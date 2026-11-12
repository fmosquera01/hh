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
        if ($itemtype === '' || $itemsId < 1
            || !$DB->tableExists('glpi_plugin_hh_repair_records')
            || !$DB->tableExists('glpi_plugin_fields_ticketreparacions')) {
            return null;
        }

        $requiredFields = [
            'fecharecepcionguiafieldtwo',
            'items_id',
            'itemtype',
            'plugin_fields_containers_id',
        ];
        foreach ($requiredFields as $field) {
            if (!$DB->fieldExists('glpi_plugin_fields_ticketreparacions', $field)) {
                return null;
            }
        }

        $latest = null;
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'requesttypes_id', 'status', 'returned_at'],
            'FROM' => 'glpi_plugin_hh_repair_records',
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
                'status' => ['approved', 'completed'],
            ],
            'ORDER' => ['tickets_id DESC'],
        ]) as $record) {
            $ticketId = (int) ($record['tickets_id'] ?? 0);
            $requestTypeId = (int) ($record['requesttypes_id'] ?? 0);
            if ($ticketId < 1 || $requestTypeId < 1) {
                continue;
            }

            // Revalidate the origin in GLPI in case the ticket was edited later.
            if (!RepairWorkflow::isRepairTicket($ticketId, $requestTypeId)
                || !RepairWorkflow::ensureRepairRecord($ticketId, $itemtype, $itemsId)) {
                continue;
            }

            $dateValue = trim((string) ($record['returned_at'] ?? ''));
            if ($dateValue === '') {
                foreach ($DB->request([
                    'SELECT' => ['fecharecepcionguiafieldtwo'],
                    'FROM' => 'glpi_plugin_fields_ticketreparacions',
                    'WHERE' => [
                        'items_id' => $ticketId,
                        'itemtype' => 'Ticket',
                        'plugin_fields_containers_id' => 15,
                    ],
                    'LIMIT' => 1,
                ]) as $fields) {
                    $dateValue = trim((string) ($fields['fecharecepcionguiafieldtwo'] ?? ''));
                }
            }

            $date = self::validDate($dateValue);
            if ($date !== null && ($latest === null || $date > $latest)) {
                $latest = $date;
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
