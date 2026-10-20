<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonGLPI;
use Computer;
use Phone;

/** Read-only native asset and warranty/contract view for GLPI 11. */
final class RepairTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Gestión de reparaciones', 'hh');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate || (!$item instanceof Computer && !$item instanceof Phone)) {
            return '';
        }
        return self::getTypeName();
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Computer && !$item instanceof Phone) {
            return true;
        }

        $itemType = $item instanceof Phone ? 'Phone' : 'Computer';
        $itemId = (int) $item->getID();
        $fields = $item->fields ?? [];
        $native = self::loadNativeAssetInfo($itemType, $itemId);
        $contracts = $native['contracts'];
        // La garantía propia del equipo se calcula aparte del contrato extendido.
        // La fecha de compra es el inicio preferente de la garantía nativa.
        $warrantyStart = self::validDate($native['buy_date'] ?? null)
            ? (string) $native['buy_date']
            : (string) ($native['warranty_date'] ?? '');
        $warrantyMonths = (int) ($native['warranty_duration'] ?? 0);
        $warrantyEnd = self::addMonths($warrantyStart, $warrantyMonths);
        $today = date('Y-m-d');

        $currentContracts = array_values(array_filter(
            $contracts,
            static fn(array $contract): bool => self::isContractCurrent($contract, $today)
        ));

        // El tipo de contrato vigente es la información principal de cobertura.
        // Si no hay contrato vigente, se muestra la garantía propia del equipo si aplica.
        if ($currentContracts !== []) {
            $types = array_values(array_unique(array_filter(array_map(
                static fn(array $contract): string => trim((string) ($contract['contract_type'] ?? '')),
                $currentContracts
            ))));
            $warrantyType = $types !== [] ? implode(', ', $types) : __('Contrato vigente', 'hh');
        } elseif ($warrantyEnd !== '' && $warrantyEnd >= $today) {
            $warrantyType = __('Garantía del equipo', 'hh');
        } else {
            $warrantyType = __('Sin garantía ni contrato vigente', 'hh');
        }


        echo "<div class='container-fluid hh-repairs'>";
        echo "<style>
            .hh-repairs .hh-hero{border:0;border-radius:.65rem;background:linear-gradient(120deg,#17365d,#245b83);color:#fff}
            .hh-repairs .hh-hero .text-muted{color:#d8e6f2!important}
            .hh-repairs .hh-hero{margin-bottom:.6rem!important}
            .hh-repairs .hh-hero .card-body{padding:.85rem 1rem!important}
            .hh-repairs .hh-hero h2{font-size:1.2rem;margin-bottom:.55rem!important}
            .hh-repairs .hh-asset-detail{height:100%;border:1px solid rgba(255,255,255,.22);border-radius:.35rem;padding:.35rem .5rem;background:rgba(255,255,255,.06);min-height:0}
            .hh-repairs .hh-detail-label{font-size:.68rem;line-height:1.15;color:#d8e6f2;margin-bottom:.1rem}
            .hh-repairs .hh-asset-detail strong{font-size:.82rem;line-height:1.2;display:block;overflow-wrap:anywhere;white-space:normal}
            .hh-repairs .hh-asset-grid{row-gap:.35rem!important}
            .hh-repairs .hh-empty{border:1px dashed var(--tblr-border-color,#dce1e7);border-radius:.5rem;padding:1rem;text-align:center}
            .hh-repairs .hh-repair-workspace{margin-bottom:.75rem!important}
            .hh-repairs .hh-repair-workspace>.card-header{padding:.5rem .8rem}
            .hh-repairs .hh-repair-workspace>.card-body{padding:.65rem .8rem}
            .hh-repairs .hh-repair-workspace .pt-3{padding-top:.5rem!important}
        </style>";

        echo "<div class='card hh-hero mb-2'><div class='card-body'>";
        echo "<h2 class='mb-2'>" . self::e(__('Gestión de reparaciones', 'hh')) . "</h2>";
        echo "<div class='row g-1 hh-asset-grid'>

        self::assetDetail(__('Número de activo fijo', 'hh'), (string) ($fields['otherserial'] ?? ''));
        self::assetDetail(__('Número de serie', 'hh'), (string) ($fields['serial'] ?? ''));
        self::assetDetail(__('Fabricante', 'hh'), (string) ($native['manufacturer'] ?? ''));
        self::assetDetail(__('Modelo', 'hh'), (string) ($native['model'] ?? ''));
        self::assetDetail(__('Estado', 'hh'), (string) ($native['status'] ?? ''));
        self::assetDetail(__('Fecha de compra', 'hh'), self::displayDate($native['buy_date'] ?? null));
        self::assetDetail(__('Valor de compra', 'hh'), self::displayMoney($native['purchase_value'] ?? null));
        self::assetDetail(__('Fin de garantía del equipo', 'hh'), self::displayDate($warrantyEnd));
        self::assetDetail(__('Tipo de contrato', 'hh'), $currentContracts !== [] ? (string) ($currentContracts[0]['contract_type'] ?? '—') : '—');
        self::assetDetail(__('Inicio de contrato', 'hh'), $currentContracts !== [] ? self::displayDate($currentContracts[0]['begin_date'] ?? null) : '—');
        self::assetDetail(__('Fin de contrato', 'hh'), $currentContracts !== [] ? self::displayDate($currentContracts[0]['contract_end_date'] ?? null) : '—');
        self::assetDetail(__('Proveedor del contrato', 'hh'), $currentContracts !== [] ? (string) ($currentContracts[0]['contract_supplier'] ?? '') : '—');
        echo "</div></div></div>";

        $quotesUrl = '/plugins/hh/front/quotes.php?embedded=1&itemtype=' . rawurlencode($itemType) . '&items_id=' . $itemId;
        $historyUrl = '/plugins/hh/front/history.php?embedded=1&itemtype=' . rawurlencode($itemType) . '&items_id=' . $itemId;
        
        echo "<div class='card hh-repair-workspace mb-2'><div class='card-header'><h3 class='card-title mb-0 fs-5'>" . self::e(__('Gestión de reparaciones', 'hh')) . "</h3></div><div class='card-body'>";
        echo "<ul class='nav nav-tabs' role='tablist'>";
        echo "<li class='nav-item'><button class='nav-link active' type='button' data-hh-tab='quotes'>Cotizaciones</button></li>";
        echo "<li class='nav-item'><button class='nav-link' type='button' data-hh-tab='history'>Historial</button></li>";
        echo "</ul>";
        echo "<div class='pt-3' data-hh-panel='quotes'><iframe title='Cotizaciones del activo' src='" . self::e($quotesUrl) . "' style='width:100%;height:500px;border:0' loading='lazy'></iframe></div>";
        echo "<div class='pt-3' data-hh-panel='history' hidden><iframe title='Historial del activo' src='" . self::e($historyUrl) . "' style='width:100%;height:650px;border:0' loading='lazy'></iframe></div>";
        echo "<script>(()=>{const root=document.currentScript.parentElement;const buttons=root.querySelectorAll('[data-hh-tab]');const panels=root.querySelectorAll('[data-hh-panel]');buttons.forEach(b=>b.addEventListener('click',()=>{buttons.forEach(x=>x.classList.toggle('active',x===b));panels.forEach(p=>p.hidden=p.dataset.hhPanel!==b.dataset.hhTab)}));})();</script>";
        echo "</div>";
        return true;
    }

    /** Read native GLPI fields through GLPI's DB request API (no direct SQL queries). */
    private static function loadNativeAssetInfo(string $itemType, int $itemId): array
    {
        global $DB;

        $tables = [
            'Phone' => [
                'asset' => 'glpi_phones',
                'model' => 'glpi_phonemodels',
                'model_field' => 'phonemodels_id',
            ],
            'Computer' => [
                'asset' => 'glpi_computers',
                'model' => 'glpi_computermodels',
                'model_field' => 'computermodels_id',
            ],
        ];

        if (!isset($tables[$itemType]) || $itemId < 1) {
            return ['contracts' => []];
        }

        $table = $tables[$itemType];
        $assetTable = $table['asset'];
        $info = [
            'buy_date' => null,
            'use_date' => null,
            'warranty_date' => null,
            'warranty_duration' => 0,
            'warranty_info' => null,
            'purchase_value' => null,
            'warranty_value' => null,
            'suppliers_id' => 0,
            'order_number' => null,
            'bill' => null,
            'manufacturer' => '',
            'model' => '',
            'status' => '',
            'brand' => '',
            'supplier' => '',
            'contracts' => [],
        ];

        // Avoid JOIN/FKEY query-builder edge cases across GLPI versions.
        // Every read uses GLPI's DB request API; no direct SQL query is executed.
        $assetRows = $DB->request([
            'SELECT' => '*',
            'FROM' => $assetTable,
            'WHERE' => [
                'id' => $itemId,
                'is_deleted' => 0,
            ],
            'LIMIT' => 1,
        ]);

        $asset = [];
        foreach ($assetRows as $row) {
            $asset = $row;
            break;
        }

        if ($asset === []) {
            return $info;
        }

        if ($itemType === 'Phone') {
            $info['brand'] = (string) ($asset['brand'] ?? '');
        }
        $info['manufacturer'] = self::lookupName('glpi_manufacturers', (int) ($asset['manufacturers_id'] ?? 0));
        $info['model'] = self::lookupName($table['model'], (int) ($asset[$table['model_field']] ?? 0));
        $info['status'] = self::lookupName('glpi_states', (int) ($asset['states_id'] ?? 0));

        // Purchase and native equipment warranty data.
        $infoRows = $DB->request([
            'SELECT' => '*',
            'FROM' => 'glpi_infocoms',
            'WHERE' => [
                'items_id' => $itemId,
                'itemtype' => $itemType,
            ],
            'LIMIT' => 1,
        ]);

        foreach ($infoRows as $row) {
            $info['buy_date'] = $row['buy_date'] ?? null;
            $info['use_date'] = $row['use_date'] ?? null;
            $info['warranty_date'] = $row['warranty_date'] ?? null;
            $info['warranty_duration'] = (int) ($row['warranty_duration'] ?? 0);
            $info['warranty_info'] = $row['warranty_info'] ?? null;
            $info['purchase_value'] = $row['value'] ?? null;
            $info['warranty_value'] = $row['warranty_value'] ?? null;
            $info['suppliers_id'] = (int) ($row['suppliers_id'] ?? 0);
            $info['order_number'] = $row['order_number'] ?? null;
            $info['bill'] = $row['bill'] ?? null;
            $info['supplier'] = self::lookupName('glpi_suppliers', (int) ($row['suppliers_id'] ?? 0));
            break;
        }

        // Retrieve all associated contracts. Resolve contract type separately to avoid joins.
        $linkRows = $DB->request([
            'SELECT' => ['contracts_id'],
            'FROM' => 'glpi_contracts_items',
            'WHERE' => [
                'itemtype' => $itemType,
                'items_id' => $itemId,
            ],
        ]);

        foreach ($linkRows as $link) {
            $contractId = (int) ($link['contracts_id'] ?? 0);
            if ($contractId < 1) {
                continue;
            }

            $contractRows = $DB->request([
                'SELECT' => '*',
                'FROM' => 'glpi_contracts',
                'WHERE' => [
                    'id' => $contractId,
                    'is_deleted' => 0,
                    'is_template' => 0,
                ],
                'LIMIT' => 1,
            ]);

            foreach ($contractRows as $contract) {
                $contract['contract_id'] = $contractId;
                $contract['contract_name'] = (string) ($contract['name'] ?? '');
                $contract['contract_number'] = (string) ($contract['num'] ?? '');
                $contract['contract_duration_months'] = (int) ($contract['duration'] ?? 0);
                $contract['contract_conditions'] = (string) ($contract['comment'] ?? '');
                $contract['contract_type'] = self::lookupName(
                    'glpi_contracttypes',
                    (int) ($contract['contracttypes_id'] ?? 0)
                );
                $contract['contract_supplier'] = self::lookupContractSupplier($contractId);
                $contract['contract_end_date'] = self::addMonths(
                    (string) ($contract['begin_date'] ?? ''),
                    (int) ($contract['duration'] ?? 0)
                );
                $info['contracts'][] = $contract;
                break;
            }
        }

        usort($info['contracts'], static function (array $a, array $b): int {
            return strcmp(
                (string) ($b['begin_date'] ?? ''),
                (string) ($a['begin_date'] ?? '')
            );
        });

        return $info;
    }

    /** Resolve a native GLPI catalogue name without SQL joins. */
    private static function lookupName(string $table, int $id): string
    {
        global $DB;

        if ($id < 1) {
            return '';
        }

        // Tables are selected only from fixed internal allowlists/call sites.
        $allowedTables = [
            'glpi_manufacturers',
            'glpi_phonemodels',
            'glpi_computermodels',
            'glpi_states',
            'glpi_suppliers',
            'glpi_contracttypes',
        ];
        if (!in_array($table, $allowedTables, true)) {
            return '';
        }

        $rows = $DB->request([
            'SELECT' => ['name'],
            'FROM' => $table,
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ]);

        foreach ($rows as $row) {
            return (string) ($row['name'] ?? '');
        }

        return '';
    }

    /** Resolve supplier linked to a contract through GLPI's native relation table. */
    private static function lookupContractSupplier(int $contractId): string
    {
        global $DB;

        if ($contractId < 1) {
            return '';
        }

        $links = $DB->request([
            'SELECT' => ['suppliers_id'],
            'FROM' => 'glpi_contracts_suppliers',
            'WHERE' => ['contracts_id' => $contractId],
        ]);

        $names = [];
        foreach ($links as $link) {
            $supplierId = (int) ($link['suppliers_id'] ?? 0);
            if ($supplierId > 0) {
                $name = self::lookupName('glpi_suppliers', $supplierId);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return implode(', ', array_values(array_unique($names)));
    }

    private static function isContractCurrent(array $contract, string $today): bool
    {
        $start = (string) ($contract['begin_date'] ?? '');
        $end = (string) ($contract['contract_end_date'] ?? '');
        $duration = (int) ($contract['contract_duration_months'] ?? 0);

        if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
            return false;
        }
        if ($duration > 0 && $end !== '' && $end !== '0000-00-00' && $end < $today) {
            return false;
        }
        return true;
    }

    private static function addMonths(string $start, int $months): string
    {
        if (!self::validDate($start) || $months <= 0) {
            return '';
        }
        try {
            return (new \DateTimeImmutable($start))->modify('+' . $months . ' months')->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function validDate($value): bool
    {
        return is_scalar($value) && trim((string) $value) !== ''
            && !str_starts_with((string) $value, '0000-00-00');
    }

    private static function displayDate($value): string
    {
        if (!self::validDate($value)) {
            return '—';
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format('Y-m-d');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    private static function displayMoney($value): string
    {
        return is_numeric($value) ? '$' . number_format((float) $value, 2, '.', ',') : '—';
    }

    private static function assetDetail(string $label, string $value): void
    {
        $display = trim($value) !== '' && $value !== '0' ? $value : '—';
        echo "<div class='col-6 col-md-4 col-xl-3'><div class='hh-asset-detail'>";
        echo "<div class='hh-detail-label'>" . self::e($label) . "</div>";
        echo "<strong>" . self::e($display) . "</strong></div></div>";
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
