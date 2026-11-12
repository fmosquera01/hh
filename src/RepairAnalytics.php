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
        $assetIds = [];
        $itemTypes = [];
        $byAsset = [];
        foreach ($assets as $asset) {
            $itemtype = (string)($asset['itemtype'] ?? '');
            $itemsId = (int)($asset['id'] ?? 0);
            $key = $itemtype . ':' . $itemsId;
            if ($itemtype === '' || $itemsId < 1) {
                continue;
            }
            $assetKeys[$key] = true;
            $assetIds[$itemsId] = $itemsId;
            $itemTypes[$itemtype] = $itemtype;
            $byAsset[$key] = [
                'repair_count' => 0,
                'completed_repairs' => 0,
                'parts_units' => 0.0,
                'parts_lines' => 0,
                'last_returned_at' => '',
            ];
        }

        if ($assetKeys === []) {
            return ['by_asset' => $byAsset, 'part_history' => []];
        }

        // Count only repair-ledger entries whose ticket still has the exact repair request origin.
        if ($DB->tableExists('glpi_plugin_hh_repair_records')) {
            foreach ($DB->request([
                'SELECT' => ['tickets_id', 'itemtype', 'items_id', 'requesttypes_id'],
                'FROM' => 'glpi_plugin_hh_repair_records',
                'WHERE' => [
                    'itemtype' => array_values($itemTypes),
                    'items_id' => array_values($assetIds),
                ],
            ]) as $record) {
                $key = (string)($record['itemtype'] ?? '') . ':' . (int)($record['items_id'] ?? 0);
                $ticketId = (int)($record['tickets_id'] ?? 0);
                $requestTypeId = (int)($record['requesttypes_id'] ?? 0);
                if (isset($byAsset[$key]) && RepairWorkflow::isRepairTicket($ticketId, $requestTypeId)) {
                    $byAsset[$key]['repair_count']++;
                }
            }
        }

        if (!$DB->tableExists('glpi_plugin_hh_quotes')
            || !$DB->tableExists('glpi_plugin_hh_quotedetails')) {
            return ['by_asset' => $byAsset, 'part_history' => []];
        }

        // Restrict the quote scan to the selected inventory, then resolve all return dates in batches.
        $candidateQuotes = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'itemtype', 'items_id', 'tickets_id', 'quote_date', 'returned_at'],
            'FROM' => 'glpi_plugin_hh_quotes',
            'WHERE' => [
                'status' => 'approved',
                'itemtype' => array_values($itemTypes),
                'items_id' => array_values($assetIds),
            ],
            'ORDER' => ['returned_at DESC', 'id DESC'],
        ]) as $quote) {
            $key = (string)($quote['itemtype'] ?? '') . ':' . (int)($quote['items_id'] ?? 0);
            if (isset($assetKeys[$key])) {
                $candidateQuotes[] = $quote;
            }
        }

        if ($candidateQuotes === []) {
            return ['by_asset' => $byAsset, 'part_history' => []];
        }

        $returnedDates = self::resolveReturnedAtBatch($candidateQuotes);
        $completedQuotes = [];
        foreach ($candidateQuotes as $quote) {
            $quoteId = (int)$quote['id'];
            $returnedAt = $returnedDates[$quoteId] ?? '';
            if ($returnedAt === '') {
                continue;
            }
            $key = (string)$quote['itemtype'] . ':' . (int)$quote['items_id'];
            $completedQuotes[$quoteId] = $quote;
            $byAsset[$key]['completed_repairs']++;
            if ($byAsset[$key]['last_returned_at'] === '' || $returnedAt > $byAsset[$key]['last_returned_at']) {
                $byAsset[$key]['last_returned_at'] = $returnedAt;
            }
        }

        $partHistory = [];
        if ($completedQuotes !== []) {
            foreach ($DB->request([
                'SELECT' => ['plugin_hh_quotes_id', 'code_snapshot', 'category_snapshot', 'description_snapshot', 'quantity', 'points_total'],
                'FROM' => 'glpi_plugin_hh_quotedetails',
                'WHERE' => ['plugin_hh_quotes_id' => array_keys($completedQuotes)],
                'ORDER' => ['id ASC'],
            ]) as $line) {
                if ((string)($line['category_snapshot'] ?? '') !== 'part') {
                    continue;
                }
                $quoteId = (int)$line['plugin_hh_quotes_id'];
                if (!isset($completedQuotes[$quoteId])) {
                    continue;
                }
                $quote = $completedQuotes[$quoteId];
                $key = (string)$quote['itemtype'] . ':' . (int)$quote['items_id'];
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
                    'returned_at' => $returnedDates[$quoteId] ?? '',
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
        $assetTickets = [];
        $assetIds = [];
        foreach ($assets as $asset) {
            $itemtype = (string)($asset['itemtype'] ?? '');
            $itemsId = (int)($asset['id'] ?? 0);
            if (in_array($itemtype, ['Computer', 'Phone'], true) && $itemsId > 0) {
                $assetTickets[$itemtype . ':' . $itemsId] = [];
                $assetIds[$itemsId] = $itemsId;
            }
        }

        $linksTable = 'glpi_items_tickets';
        $ticketsTable = 'glpi_tickets';
        $fieldsTable = 'glpi_plugin_fields_ticketreparacions';
        if ($assetTickets === [] || !$DB->tableExists($linksTable)
            || !$DB->tableExists($ticketsTable) || !$DB->tableExists($fieldsTable)) {
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

        // Load links once, then retain only tickets belonging to the filtered inventory.
        $ticketIds = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'itemtype', 'items_id'],
            'FROM' => $linksTable,
            'WHERE' => [
                'itemtype' => ['Computer', 'Phone'],
                'items_id' => array_values($assetIds),
            ],
        ]) as $link) {
            $key = (string)($link['itemtype'] ?? '') . ':' . (int)($link['items_id'] ?? 0);
            $ticketId = (int)($link['tickets_id'] ?? 0);
            if ($ticketId > 0 && isset($assetTickets[$key])) {
                $assetTickets[$key][] = $ticketId;
                $ticketIds[$ticketId] = $ticketId;
            }
        }
        if ($ticketIds === []) {
            return $result;
        }

        $ticketStatuses = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'status'],
            'FROM' => $ticketsTable,
            'WHERE' => ['id' => array_values($ticketIds)],
        ]) as $ticket) {
            $ticketStatuses[(int)$ticket['id']] = (int)($ticket['status'] ?? 0);
        }

        $fieldsByTicket = [];
        foreach ($DB->request([
            'SELECT' => array_merge(['items_id'], $requiredFields),
            'FROM' => $fieldsTable,
            'WHERE' => [
                'items_id' => array_values($ticketIds),
                'itemtype' => 'Ticket',
                'plugin_fields_containers_id' => 15,
            ],
        ]) as $fields) {
            $fieldsByTicket[(int)$fields['items_id']] = $fields;
        }

        foreach ($assetTickets as $assetKey => $linkedTickets) {
            rsort($linkedTickets);
            foreach ($linkedTickets as $ticketId) {
                $status = $ticketStatuses[$ticketId] ?? null;
                [$itemtype, $itemsId] = explode(':', $assetKey, 2);
                if ($status === null || in_array($status, [5, 6], true)
                    || !RepairWorkflow::isRepairTicket($ticketId)
                    || !RepairWorkflow::ensureRepairRecord($ticketId, $itemtype, (int)$itemsId)
                    || !isset($fieldsByTicket[$ticketId])) {
                    continue;
                }

                $fields = $fieldsByTicket[$ticketId];
                $guide = trim((string)($fields['nmerodeguafield'] ?? ''));
                $sentAt = trim((string)($fields['fechaenvioaproveedorfieldtwo'] ?? ''));
                $supplierReceivedAt = trim((string)($fields['fecharecepcionproveedorfieldtwo'] ?? ''));
                $returnedAt = trim((string)($fields['fecharecepcionguiafieldtwo'] ?? ''));
                $validDate = static fn(string $date): bool =>
                    $date !== '' && !str_starts_with($date, '0000-00-00');

                if ($guide === '' || !$validDate($sentAt) || !$validDate($supplierReceivedAt)
                    || $validDate($returnedAt)) {
                    continue;
                }

                $result[$assetKey] = [
                    'tickets_id' => $ticketId,
                    'guide_number' => $guide,
                    'sent_at' => $sentAt,
                    'supplier_received_at' => $supplierReceivedAt,
                ];
                break;
            }
        }

        return $result;
    }

    /**
     * Count completed, approved repairs by return month for the filtered inventory.
     *
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, int>
     */
    public static function getMonthlyCompletedRepairs(int $year, array $assets): array
    {
        global $DB;

        $months = array_fill(1, 12, 0);
        if ($assets === [] || !$DB->tableExists('glpi_plugin_hh_quotes')) {
            return array_values($months);
        }

        $assetKeys = [];
        $assetIds = [];
        $itemTypes = [];
        foreach ($assets as $asset) {
            $itemtype = (string)($asset['itemtype'] ?? '');
            $itemsId = (int)($asset['id'] ?? 0);
            if ($itemtype === '' || $itemsId < 1) {
                continue;
            }
            $assetKeys[$itemtype . ':' . $itemsId] = true;
            $assetIds[$itemsId] = $itemsId;
            $itemTypes[$itemtype] = $itemtype;
        }
        if ($assetKeys === []) {
            return array_values($months);
        }

        $candidateQuotes = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id', 'tickets_id', 'returned_at'],
            'FROM' => 'glpi_plugin_hh_quotes',
            'WHERE' => [
                'status' => 'approved',
                'itemtype' => array_values($itemTypes),
                'items_id' => array_values($assetIds),
            ],
        ]) as $quote) {
            $key = (string)($quote['itemtype'] ?? '') . ':' . (int)($quote['items_id'] ?? 0);
            if (isset($assetKeys[$key])) {
                $candidateQuotes[] = $quote;
            }
        }

        $returnedDates = self::resolveReturnedAtBatch($candidateQuotes);
        foreach ($candidateQuotes as $quote) {
            $returnedAt = $returnedDates[(int)$quote['id']] ?? '';
            if ($returnedAt === '' || !preg_match('/^(\\d{4})-(\\d{2})-\\d{2}/', $returnedAt, $matches)) {
                continue;
            }
            if ((int)$matches[1] !== $year) {
                continue;
            }
            $month = (int)$matches[2];
            if ($month >= 1 && $month <= 12) {
                $months[$month]++;
            }
        }

        return array_values($months);
    }

    /**
     * Resolve quote return dates in two queries at most, rather than one query per quote.
     *
     * @param array<int, array<string, mixed>> $quotes
     * @return array<int, string> Quote ID => confirmed return date
     */
    private static function resolveReturnedAtBatch(array $quotes): array
    {
        global $DB;

        $dates = [];
        $quoteTickets = [];
        $ticketIds = [];
        foreach ($quotes as $quote) {
            $quoteId = (int)($quote['id'] ?? 0);
            $ticketId = (int)($quote['tickets_id'] ?? 0);
            $itemtype = (string)($quote['itemtype'] ?? '');
            $itemsId = (int)($quote['items_id'] ?? 0);
            if ($quoteId < 1 || $ticketId < 1 || $itemtype === '' || $itemsId < 1
                || !RepairWorkflow::isRepairTicket($ticketId)
                || !RepairWorkflow::ensureRepairRecord($ticketId, $itemtype, $itemsId)) {
                continue;
            }

            // A cached quote date is not trusted until its linked ticket passes origin validation.
            $quoteTickets[$quoteId] = $ticketId;
            $returnedAt = trim((string)($quote['returned_at'] ?? ''));
            if ($returnedAt !== '' && !str_starts_with($returnedAt, '0000-00-00')) {
                $dates[$quoteId] = $returnedAt;
                continue;
            }
            $ticketIds[$ticketId] = $ticketId;
        }

        $table = 'glpi_plugin_fields_ticketreparacions';
        if ($ticketIds === [] || !$DB->tableExists($table)
            || !$DB->fieldExists($table, 'items_id')
            || !$DB->fieldExists($table, 'itemtype')
            || !$DB->fieldExists($table, 'plugin_fields_containers_id')) {
            return $dates;
        }

        $hasPreferred = $DB->fieldExists($table, 'fecharecepcionguiafieldtwo');
        $hasFallback = $DB->fieldExists($table, 'fecharecepcionguiafield');
        if (!$hasPreferred && !$hasFallback) {
            return $dates;
        }

        $select = ['items_id', 'plugin_fields_containers_id'];
        if ($hasPreferred) {
            $select[] = 'fecharecepcionguiafieldtwo';
        }
        if ($hasFallback) {
            $select[] = 'fecharecepcionguiafield';
        }

        $preferredDates = [];
        $fallbackDates = [];
        foreach ($DB->request([
            'SELECT' => $select,
            'FROM' => $table,
            'WHERE' => [
                'items_id' => array_values($ticketIds),
                'itemtype' => 'Ticket',
                'plugin_fields_containers_id' => [15, 1],
            ],
        ]) as $row) {
            $ticketId = (int)($row['items_id'] ?? 0);
            $containerId = (int)($row['plugin_fields_containers_id'] ?? 0);
            if ($ticketId < 1) {
                continue;
            }
            if ($containerId === 15 && $hasPreferred) {
                $date = trim((string)($row['fecharecepcionguiafieldtwo'] ?? ''));
                if ($date !== '' && !str_starts_with($date, '0000-00-00')) {
                    $preferredDates[$ticketId] = $date;
                }
            } elseif ($containerId === 1 && $hasFallback) {
                $date = trim((string)($row['fecharecepcionguiafield'] ?? ''));
                if ($date !== '' && !str_starts_with($date, '0000-00-00')) {
                    $fallbackDates[$ticketId] = $date;
                }
            }
        }

        foreach ($quoteTickets as $quoteId => $ticketId) {
            $date = $preferredDates[$ticketId] ?? $fallbackDates[$ticketId] ?? '';
            if ($date !== '') {
                $dates[$quoteId] = $date;
            }
        }

        return $dates;
    }

}
