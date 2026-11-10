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
            'SELECT' => ['id', 'name', 'itemtype', 'items_id', 'quote_date', 'returned_at', 'total_points'],
            'FROM' => 'glpi_plugin_hh_quotes',
            'WHERE' => ['status' => 'approved'],
            'ORDER' => ['returned_at DESC', 'id DESC'],
        ]) as $quote) {
            $key = (string)($quote['itemtype'] ?? '') . ':' . (int)($quote['items_id'] ?? 0);
            $returnedAt = trim((string)($quote['returned_at'] ?? ''));
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
                    'returned_at' => (string)$quote['returned_at'],
                    'code' => (string)($line['code_snapshot'] ?? ''),
                    'description' => (string)($line['description_snapshot'] ?? ''),
                    'quantity' => $quantity,
                    'points' => (float)($line['points_total'] ?? 0),
                ];
            }
        }

        return ['by_asset' => $byAsset, 'part_history' => $partHistory];
    }
}
