<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

/**
 * Read-only analysis of approved repairs whose return to Songa is recorded.
 * A quote approval alone is not treated as proof that a part was installed.
 */
final class RepairAnalytics
{
    /**
     * @param array<int, array<string, mixed>> $assets Inventory rows from Reports.
     * @return array{by_asset: array<string, array<string, mixed>>, part_history: array<int, array<string, mixed>>}
     */
    public static function getExecutedRepairSummary(array $assets): array
    {
        global $DB;

        $assetKeys = [];
        $byAsset = [];
        foreach ($assets as $asset) {
            $key = (string)($asset['itemtype'] ?? '') . ':' . (int)($asset['id'] ?? 0);
            $assetKeys[$key] = true;
            $byAsset[$key] = [
                'completed_repairs' => 0,
                'parts_units' => 0.0,
                'parts_lines' => 0,
                'last_returned_at' => '',
            ];
        }

        if ($assetKeys === [] || !$DB->tableExists('glpi_plugin_hh_quotes')
            || !$DB->tableExists('glpi_plugin_hh_quotedetails')) {
            return ['by_asset' => $byAsset, 'part_history' => []];
        }

        $quotes = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'itemtype', 'items_id', 'tickets_id', 'quote_date', 'returned_at', 'total_points'],
            'FROM' => 'glpi_plugin_hh_quotes',
            'WHERE' => ['status' => 'approved'],
            'ORDER' => ['returned_at DESC', 'id DESC'],
        ]) as $quote) {
            $key = (string)($quote['itemtype'] ?? '') . ':' . (int)($quote['items_id'] ?? 0);
            $returnedAt = self::resolveReturnedAt($quote);
            if (!isset($assetKeys[$key]) || $returnedAt === '' || str_starts_with($returnedAt, '0000-00-00')) {
                continue;
            }
            $quotes[] = $quote;
            $byAsset[$key]['completed_repairs']++;
            if ($byAsset[$key]['last_returned_at'] === '' || $returnedAt > $byAsset[$key]['last_returned_at']) {
                $byAsset[$key]['last_returned_at'] = $returnedAt;
            }
        }

        $partHistory = [];
        foreach ($quotes as $quote) {
            $key = (string)$quote['itemtype'] . ':' . (int)$quote['items_id'];
            foreach ($DB->request([
                'SELECT' => ['code_snapshot', 'category_snapshot', 'description_snapshot', 'quantity', 'points_total'],
                'FROM' => 'glpi_plugin_hh_quotedetails',
                'WHERE' => ['plugin_hh_quotes_id' => (int)$quote['id']],
                'ORDER' => ['id ASC'],
            ]) as $line) {
                if ((string)($line['category_snapshot'] ?? '') !== 'part') {
                    continue;
                }
                $quantity = (float)($line['quantity'] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }
                $byAsset[$key]['parts_units'] += $quantity;
                $byAsset[$key]['parts_lines']++;
                $partHistory[] = [
                    'itemtype' => (string)$quote['itemtype'],
                    'items_id' => (int)$quote['items_id'],
                    'quote_name' => (string)$quote['name'],
                    'quote_date' => (string)($quote['quote_date'] ?? ''),
                    'returned_at' => self::resolveReturnedAt($quote),
                    'code' => (string)($line['code_snapshot'] ?? ''),
                    'description' => (string)($line['description_snapshot'] ?? ''),
                    'quantity' => $quantity,
                    'points' => (float)($line['points_total'] ?? 0),
                ];
            }
        }

        return ['by_asset' => $byAsset, 'part_history' => $partHistory];
    }


    /**
     * Return one entry per asset currently at the external repair provider.
     * The guide, dispatch date and provider-received date must exist, while
     * the return-to-Songa date must still be empty.
     *
     * @param array<int, array<string, mixed>> $assets
     * @return array<string, array{tickets_id: int, guide_number: string, sent_at: string, supplier_received_at: string}>
     */
    public static function getActiveRepairAssets(array $assets): array
    {
        global $DB;

        $result = [];
        $linksTable = 'glpi_items_tickets';
        $ticketsTable = 'glpi_tickets';
        $fieldsTable = 'glpi_plugin_fields_ticketreparacions';

        if (!$DB->tableExists($linksTable) || !$DB->tableExists($ticketsTable)
            || !$DB->tableExists($fieldsTable)) {
            return $result;
        }

        $requiredFields = [
            'nmerodeguafield',
            'fechaenvioaproveedorfieldtwo',
            'fecharecepcionproveedorfieldtwo',
            'fecharecepcionguiafieldtwo',
        ];
        foreach ($requiredFields as $field) {
            if (!$DB->fieldExists($fieldsTable, $field)) {
                return $result;
            }
        }

        foreach ($assets as $asset) {
            $itemtype = (string)($asset['itemtype'] ?? '');
            $itemsId = (int)($asset['id'] ?? 0);
            if (!in_array($itemtype, ['Computer', 'Phone'], true) || $itemsId < 1) {
                continue;
            }

            $assetKey = $itemtype . ':' . $itemsId;
            foreach ($DB->request([
                'SELECT' => ['tickets_id'],
                'FROM' => $linksTable,
                'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId],
                'ORDER' => ['tickets_id DESC'],
            ]) as $link) {
                $ticketId = (int)($link['tickets_id'] ?? 0);
                if ($ticketId < 1) {
                    continue;
                }

                // Solved/closed tickets cannot represent a currently open repair.
                $ticketStatus = null;
                foreach ($DB->request([
                    'SELECT' => ['status'],
                    'FROM' => $ticketsTable,
                    'WHERE' => ['id' => $ticketId],
                    'LIMIT' => 1,
                ]) as $ticket) {
                    $ticketStatus = (int)($ticket['status'] ?? 0);
                    break;
                }
                if ($ticketStatus === null || in_array($ticketStatus, [5, 6], true)) {
                    continue;
                }

                foreach ($DB->request([
                    'SELECT' => $requiredFields,
                    'FROM' => $fieldsTable,
                    'WHERE' => [
                        'items_id' => $ticketId,
                        'itemtype' => 'Ticket',
                        'plugin_fields_containers_id' => 15,
                    ],
                    'LIMIT' => 1,
                ]) as $fields) {
                    $guide = trim((string)($fields['nmerodeguafield'] ?? ''));
                    $sentAt = trim((string)($fields['fechaenvioaproveedorfieldtwo'] ?? ''));
                    $supplierReceivedAt = trim((string)($fields['fecharecepcionproveedorfieldtwo'] ?? ''));
                    $returnedAt = trim((string)($fields['fecharecepcionguiafieldtwo'] ?? ''));

                    $validDate = static fn(string $date): bool =>
                        $date !== '' && !str_starts_with($date, '0000-00-00');

                    if ($guide === '' || !$validDate($sentAt) || !$validDate($supplierReceivedAt) || $validDate($returnedAt)) {
                        continue;
                    }

                    // One equipment is counted once even if it has multiple linked tickets.
                    $result[$assetKey] = [
                        'tickets_id' => $ticketId,
                        'guide_number' => $guide,
                        'sent_at' => $sentAt,
                        'supplier_received_at' => $supplierReceivedAt,
                    ];
                    break;
                }

                if (isset($result[$assetKey])) {
                    break;
                }
            }
        }

        return $result;
    }


    /** Use the linked GLPI ticket when the quote predates the return date. */
    private static function resolveReturnedAt(array $quote): string
    {
        global $DB;
        $value = trim((string)($quote['returned_at'] ?? ''));
        if ($value !== '' && !str_starts_with($value, '0000-00-00')) {
            return $value;
        }
        $ticketId = (int)($quote['tickets_id'] ?? 0);
        $table = 'glpi_plugin_fields_ticketreparacions';
        if ($ticketId < 1 || !$DB->tableExists($table)) {
            return '';
        }
        foreach ([
            ['field' => 'fecharecepcionguiafieldtwo', 'container' => 15],
            ['field' => 'fecharecepcionguiafield', 'container' => 1],
        ] as $definition) {
            $field = $definition['field'];
            if (!$DB->fieldExists($table, $field)) {
                continue;
            }
            foreach ($DB->request([
                'SELECT' => [$field],
                'FROM' => $table,
                'WHERE' => [
                    'items_id' => $ticketId,
                    'itemtype' => 'Ticket',
                    'plugin_fields_containers_id' => $definition['container'],
                ],
                'LIMIT' => 1,
            ]) as $row) {
                $date = trim((string)($row[$field] ?? ''));
                if ($date !== '' && !str_starts_with($date, '0000-00-00')) {
                    return $date;
                }
            }
        }
        return '';
    }
}
