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
        $warrantyStart = self::validDate($native['warranty_date'] ?? null)
            ? (string) $native['warranty_date']
            : (string) ($native['buy_date'] ?? '');
        $warrantyMonths = (int) ($native['warranty_duration'] ?? 0);
        $warrantyEnd = self::addMonths($warrantyStart, $warrantyMonths);
        $today = date('Y-m-d');

        $currentContracts = array_values(array_filter(
            $contracts,
            static fn(array $contract): bool => self::isContractCurrent($contract, $today)
        ));

        // El contrato vigente manda sobre la garantía propia del equipo.
        if ($currentContracts !== []) {
            $types = array_values(array_unique(array_filter(array_map(
                static fn(array $contract): string => trim((string) ($contract['contract_type'] ?? '')),
                $currentContracts
            ))));
            $warrantyType = $types !== [] ? implode(', ', $types) : __('Contrato vigente', 'hh');
            $serviceLevel = self::serviceLevelForContracts($currentContracts);
            $coverageSource = __('Contrato de servicio vigente; prevalece sobre la garantía propia del equipo', 'hh');
            $coverageStart = self::displayDate($currentContracts[0]['begin_date'] ?? null);
        } elseif ($warrantyEnd !== '' && $warrantyEnd >= $today) {
            $warrantyType = __('Garantía del equipo', 'hh');
            $serviceLevel = __('Aplican las condiciones de la garantía del fabricante', 'hh');
            $coverageSource = __('Garantía propia del equipo registrada en GLPI', 'hh');
            $coverageStart = self::displayDate($warrantyStart);
        } else {
            $warrantyType = __('Sin garantía ni contrato vigente', 'hh');
            $serviceLevel = __('No aplica un nivel de servicio de contrato', 'hh');
            $coverageSource = __('Sin cobertura vigente detectada', 'hh');
            $coverageStart = '—';
        }

        echo "<div class='container-fluid hh-repairs'>";
        echo "<style>
            .hh-repairs .hh-hero{border:0;border-radius:.65rem;background:linear-gradient(120deg,#17365d,#245b83);color:#fff}
            .hh-repairs .hh-hero .text-muted{color:#d8e6f2!important}
            .hh-repairs .hh-asset-detail{height:100%;border:1px solid rgba(255,255,255,.22);border-radius:.45rem;padding:.65rem .75rem;background:rgba(255,255,255,.06)}
            .hh-repairs .hh-detail-label{font-size:.72rem;color:#d8e6f2;margin-bottom:.15rem}
            .hh-repairs .hh-asset-detail strong{overflow-wrap:anywhere;white-space:normal}
            .hh-repairs .hh-empty{border:1px dashed var(--tblr-border-color,#dce1e7);border-radius:.5rem;padding:1.5rem 1rem;text-align:center}
        </style>";

        echo "<div class='card hh-hero mb-3'><div class='card-body p-4'>";
        echo "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3'>";
        echo "<div><div class='text-muted small mb-1'>SONGA IT · MÓDULO DE ACTIVOS</div>";
        echo "<h2 class='mb-1'>" . self::e(__('Gestión de reparaciones', 'hh')) . "</h2>";
        echo "<div class='text-muted'>" . self::e((string) ($fields['name'] ?? __('Activo sin nombre', 'hh')))
            . ' · ' . self::e($itemType) . ' #' . $itemId . "</div></div>";
        echo "<span class='badge bg-success-lt'>" . self::e(__('Consulta nativa activa', 'hh')) . "</span></div>";
        echo "<hr class='border-light opacity-25'><div class='row g-2'>";

        self::assetDetail(__('Número de serie', 'hh'), (string) ($fields['serial'] ?? ''));
        self::assetDetail(__('Número de activo fijo', 'hh'), (string) ($fields['otherserial'] ?? ''));
        self::assetDetail(__('Fabricante', 'hh'), (string) ($native['manufacturer'] ?? ''));
        self::assetDetail(__('Modelo', 'hh'), (string) ($native['model'] ?? ''));
        self::assetDetail(__('Marca', 'hh'), (string) ($native['brand'] ?? ''));
        self::assetDetail(__('Estado', 'hh'), (string) ($native['status'] ?? ''));
        self::assetDetail(__('Fecha de compra', 'hh'), self::displayDate($native['buy_date'] ?? null));
        self::assetDetail(__('Valor de compra', 'hh'), self::displayMoney($native['purchase_value'] ?? null));
        self::assetDetail(__('Tipo / información de garantía', 'hh'), $warrantyType);
        self::assetDetail(__('Inicio de contrato', 'hh'), $currentContracts !== [] ? self::displayDate($currentContracts[0]['begin_date'] ?? null) : '—');
        self::assetDetail(__('Fin de contrato', 'hh'), $currentContracts !== [] ? self::displayDate($currentContracts[0]['contract_end_date'] ?? null) : '—');
        self::assetDetail(__('Proveedor del equipo', 'hh'), (string) ($native['supplier'] ?? ''));
        self::assetDetail(__('Orden de compra', 'hh'), (string) ($native['order_number'] ?? ''));
        self::assetDetail(__('Factura', 'hh'), (string) ($native['bill'] ?? ''));
        self::assetDetail(__('Origen de cobertura', 'hh'), $coverageSource);
        self::assetDetail(__('Nivel de servicio', 'hh'), $serviceLevel);
        echo "</div></div></div>";

        echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title mb-0'>"
            . self::e(__('Contratos asociados al activo', 'hh')) . "</h3></div><div class='card-body'>";
        if ($contracts === []) {
            echo "<div class='hh-empty'><strong>" . self::e(__('No se encontraron contratos asociados', 'hh'))
                . "</strong><div class='text-muted mt-1'>" . self::e(__('Se evaluará la garantía propia del equipo si está registrada y vigente.', 'hh')) . "</div></div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-vcenter'><thead><tr>";
            foreach ([__('Contrato'), __('Tipo'), __('Inicio'), __('Vencimiento'), __('Estado'), __('Condiciones')] as $heading) {
                echo '<th>' . self::e($heading) . '</th>';
            }
            echo "</tr></thead><tbody>";
            foreach ($contracts as $contract) {
                $isCurrent = self::isContractCurrent($contract, $today);
                echo '<tr><td>' . self::e((string) ($contract['contract_name'] ?? '—')) . '</td>';
                echo '<td>' . self::e((string) ($contract['contract_type'] ?? '—')) . '</td>';
                echo '<td>' . self::e(self::displayDate($contract['begin_date'] ?? null)) . '</td>';
                echo '<td>' . self::e(self::displayDate($contract['contract_end_date'] ?? null)) . '</td>';
                echo '<td><span class="badge ' . ($isCurrent ? 'bg-success-lt' : 'bg-secondary-lt') . '">'
                    . self::e($isCurrent ? __('Vigente', 'hh') : __('No vigente', 'hh')) . '</span></td>';
                echo '<td>' . self::e((string) ($contract['contract_conditions'] ?? '')) . '</td></tr>';
            }
            echo "</tbody></table></div>";
        }
        echo "</div></div>";

        echo "<div class='alert alert-info' role='status'><strong>" . self::e(__('Reglas de cobertura', 'hh')) . "</strong><ul class='mb-0 mt-2'>";
        echo "<li>" . self::e(__('Platinum: cubre todo tipo de daño; tiempo objetivo de atención de 2 días.', 'hh')) . "</li>";
        echo "<li>" . self::e(__('Gold: no cubre la placa principal; tiempo objetivo de atención de 5 días.', 'hh')) . "</li>";
        echo "<li>" . self::e(__('Inveligent: cobertura mediante puntos.', 'hh')) . "</li>";
        echo "<li>" . self::e(__('Sin contrato vigente, se evalúa la garantía propia del equipo; si tampoco está vigente, el activo queda sin cobertura.', 'hh')) . "</li>";
        echo "</ul></div>";

        echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title mb-0'>"
            . self::e(__('Cotizaciones e historial de reparaciones', 'hh')) . "</h3></div><div class='card-body'>";
        echo "<div class='hh-empty'><strong>" . self::e(__('La lectura del activo ya está conectada', 'hh')) . "</strong><div class='text-muted mt-1'>"
            . self::e(__('Esta versión consulta los datos nativos de GLPI en modo lectura. El almacenamiento de cotizaciones, puntos e historial se implementará después.', 'hh'))
            . "</div></div></div></div>";
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

    private static function serviceLevelForContracts(array $contracts): string
    {
        $levels = [];
        foreach ($contracts as $contract) {
            $type = trim((string) ($contract['contract_type'] ?? ''));
            $normalized = strtolower($type);
            if ($normalized === 'platinum') {
                $levels[] = __('Platinum: cubre todo tipo de daño; objetivo de atención 2 días', 'hh');
            } elseif ($normalized === 'gold') {
                $levels[] = __('Gold: excluye la placa principal; objetivo de atención 5 días', 'hh');
            } elseif ($normalized === 'inveligent') {
                $levels[] = __('Inveligent: cobertura por puntos', 'hh');
            } elseif ($type !== '') {
                $levels[] = sprintf(__('Contrato %s: nivel de servicio por definir', 'hh'), $type);
            }
        }
        return $levels !== [] ? implode('; ', array_unique($levels)) : __('Consultar condiciones del contrato', 'hh');
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
