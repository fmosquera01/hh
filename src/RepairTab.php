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
        $approvedPoints = self::loadApprovedRepairPoints($itemType, $itemId);
        $repairTracking = self::loadLatestRepairTracking($itemType, $itemId);
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
            .hh-repairs{font-size:12px;line-height:1.22;padding-left:.2rem;padding-right:.2rem}
            .hh-repairs .hh-hero{border:0;border-radius:.55rem;background:linear-gradient(120deg,#17365d,#245b83);color:#fff}
            .hh-repairs .hh-hero .text-muted{color:#d8e6f2!important}
            .hh-repairs .hh-hero .card-body{padding:.45rem .6rem!important}
            .hh-repairs .hh-hero h2{font-size:1rem;line-height:1.15;margin-bottom:.25rem!important}
            .hh-repairs .hh-asset-detail{height:100%;min-height:0;border:1px solid rgba(255,255,255,.22);border-radius:.3rem;padding:.22rem .35rem;background:rgba(255,255,255,.06)}
            .hh-repairs .hh-detail-label{font-size:.61rem;line-height:1.1;color:#d8e6f2;margin-bottom:.04rem}
            .hh-repairs .hh-asset-detail strong{font-size:.72rem;line-height:1.12;overflow-wrap:anywhere;white-space:normal}
            .hh-repairs .row.g-2{--tblr-gutter-x:.25rem;--tblr-gutter-y:.25rem}
            .hh-repairs .hh-empty{border:1px dashed var(--tblr-border-color,#dce1e7);border-radius:.5rem;padding:1rem;text-align:center}
            .hh-repairs>.card{margin-bottom:.6rem!important}
            .hh-repairs>.card>.card-header{padding:.55rem .8rem}
            .hh-repairs>.card>.card-header .card-title{font-size:.95rem}
            .hh-repairs>.card>.card-body{padding:.65rem .8rem}
            .hh-repairs .nav-tabs .nav-link{padding:.35rem .6rem;font-size:.82rem}
            .hh-repairs [data-hh-panel]{padding-top:.45rem!important}
            .hh-repairs iframe{display:block}
        </style>";

        echo "<div class='card hh-hero mb-2'><div class='card-body p-3'>";
        
        echo "<h2 class='mb-1'>" . self::e(__('Gestión de reparaciones', 'hh')) . "</h2>";
      
        
        echo "<div class='row g-2'>";

        self::assetDetail(__('Número de activo fijo', 'hh'), (string) ($fields['otherserial'] ?? ''));
        self::assetDetail(__('Número de serie', 'hh'), (string) ($fields['serial'] ?? ''));
        self::assetDetail(__('Modelo', 'hh'), (string) ($native['model'] ?? ''));
		self::assetDetail(__('Puntos usados en reparaciones aprobadas', 'hh'), number_format($approvedPoints, 2, '.', ','));
        self::assetDetail(__('Fecha de última reparación', 'hh'), self::displayDate($repairTracking['returned_at'] ?? null));
        self::assetDetail(__('Última guía de salida', 'hh'), (string)($repairTracking['guide_number'] ?? '—') ?: '—');
        self::assetDetail(__('Fecha de envío al proveedor', 'hh'), self::displayDate($repairTracking['sent_at'] ?? null));
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
        
        echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title mb-0'>" . self::e(__('Gestión de reparaciones', 'hh')) . "</h3></div><div class='card-body'>";
        $tabPrefix = 'hh-repair-' . strtolower($itemType) . '-' . $itemId;
        $quotesTabId = $tabPrefix . '-quotes';
        $historyTabId = $tabPrefix . '-history';
        echo "<style>
            .hh-repair-tabs .hh-tab-radio{position:absolute;opacity:0;pointer-events:none}
            .hh-repair-tabs .hh-tab-label{display:inline-block;margin:0;padding:.4rem .7rem;border:1px solid var(--tblr-border-color,#dce1e7);background:var(--tblr-bg-surface-secondary,#f8fafc);color:var(--tblr-body-color,#334155);cursor:pointer;font-size:.82rem}
            .hh-repair-tabs .hh-tab-label:first-of-type{border-radius:.35rem 0 0 .35rem}
            .hh-repair-tabs .hh-tab-label:last-of-type{border-radius:0 .35rem .35rem 0;margin-left:-1px}
            .hh-repair-tabs .hh-tab-radio:checked + .hh-tab-label{background:var(--tblr-bg-surface,#fff);color:var(--tblr-primary,#206bc4);border-bottom-color:var(--tblr-bg-surface,#fff);font-weight:600;position:relative;z-index:1}
            .hh-repair-tabs .hh-tab-panel{display:none;padding-top:.6rem}
            .hh-repair-tabs input.hh-tab-radio:nth-of-type(1):checked ~ .hh-tab-panels [data-hh-panel='quotes'],
            .hh-repair-tabs input.hh-tab-radio:nth-of-type(2):checked ~ .hh-tab-panels [data-hh-panel='history']{display:block}
            .hh-repair-tabs iframe{display:block;width:100%;border:0}
        </style>";
        echo "<div class='hh-repair-tabs'>";
        echo "<input class='hh-tab-radio' type='radio' name='" . self::e($tabPrefix) . "' id='" . self::e($quotesTabId) . "' checked>";
        echo "<label class='hh-tab-label' for='" . self::e($quotesTabId) . "'>Cotizaciones</label>";
        echo "<input class='hh-tab-radio' type='radio' name='" . self::e($tabPrefix) . "' id='" . self::e($historyTabId) . "'>";
        echo "<label class='hh-tab-label' for='" . self::e($historyTabId) . "'>Historial</label>";
        echo "<div class='hh-tab-panels'>";
        echo "<div class='hh-tab-panel' data-hh-panel='quotes'><iframe title='Cotizaciones del activo' src='" . self::e($quotesUrl) . "' style='height:850px' loading='lazy'></iframe></div>";
        echo "<div class='hh-tab-panel' data-hh-panel='history'><iframe title='Historial de reparaciones de este activo' src='" . self::e($historyUrl) . "' style='height:650px' loading='lazy'></iframe></div>";
        echo "</div></div>";
        return true;
    }

    /** Sum points from approved repair quotes for this asset only. */
    private static function loadApprovedRepairPoints(string $itemType, int $itemId): float
    {
        global $DB;
        $total = 0.0;
        foreach ($DB->request(['SELECT' => ['total_points'], 'FROM' => 'glpi_plugin_hh_quotes', 'WHERE' => ['itemtype' => $itemType, 'items_id' => $itemId, 'status' => 'approved']]) as $row) {
            $total += (float)($row['total_points'] ?? 0);
        }
        return $total;
    }

    /** Latest guide, supplier-send date and confirmed return for this asset from its linked ticket Fields. */
    private static function loadLatestRepairTracking(string $itemType, int $itemId): array
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['tickets_id'],
            'FROM' => 'glpi_items_tickets',
            'WHERE' => ['itemtype' => $itemType, 'items_id' => $itemId],
            'ORDER' => ['tickets_id DESC'],
        ]) as $link) {
            $ticketId = (int) ($link['tickets_id'] ?? 0);
            if ($ticketId < 1) {
                continue;
            }
            foreach ($DB->request([
                'SELECT' => ['nmerodeguafield', 'fechaenvioaproveedorfield', 'fecharecepcionproveedorfield', 'fecharecepcionguiafield'],
                'FROM' => 'glpi_plugin_fields_ticketreparacions',
                'WHERE' => [
                    'items_id' => $ticketId,
                    'itemtype' => 'Ticket',
                    'plugin_fields_containers_id' => 1,
                ],
            ]) as $fields) {
                $guide = trim((string) ($fields['nmerodeguafield'] ?? ''));
                $sent = $fields['fechaenvioaproveedorfield'] ?? null;
                $received = $fields['fecharecepcionproveedorfield'] ?? null;
                $returned = $fields['fecharecepcionguiafield'] ?? null;
                if ($guide !== '' || self::validDate($sent) || self::validDate($received) || self::validDate($returned)) {
                    return [
                        'guide_number' => $guide !== '' ? $guide : null,
                        'sent_at' => self::validDate($sent) ? $sent : null,
                        'returned_at' => self::validDate($returned) ? $returned : null,
                    ];
                }
            }
        }
        return ['returned_at' => null, 'guide_number' => null, 'sent_at' => null];
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
        echo "<div class='col-6 col-md-4 col-xl-2'><div class='hh-asset-detail'>";
        echo "<div class='hh-detail-label'>" . self::e($label) . "</div>";
        echo "<strong>" . self::e($display) . "</strong></div></div>";
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}