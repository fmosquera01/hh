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
        $warrantyStart = self::validDate($native['warranty_date'] ?? null)
            ? (string) $native['warranty_date'] : (string) ($native['buy_date'] ?? '');
        $warrantyMonths = (int) ($native['warranty_duration'] ?? 0);
        $warrantyEnd = self::addMonths($warrantyStart, $warrantyMonths);
        $today = date('Y-m-d');

        $currentContracts = array_values(array_filter(
            $contracts,
            static fn(array $contract): bool => self::isContractCurrent($contract, $today)
        ));

        if ($currentContracts !== []) {
            $types = array_values(array_unique(array_filter(array_map(
                static fn(array $contract): string => trim((string) ($contract['contract_type'] ?? '')),
                $currentContracts
            ))));
            $warrantyType = $types !== [] ? implode(', ', $types) : __('Contrato vigente', 'hh');
            $serviceLevel = self::serviceLevelForContracts($currentContracts);
            $coverageSource = __('Contrato de servicio vigente', 'hh');
            $coverageStart = self::displayDate($currentContracts[0]['begin_date'] ?? null);
        } elseif ($warrantyEnd !== '' && $warrantyEnd >= $today) {
            $warrantyType = __('Garantía del equipo', 'hh');
            $serviceLevel = __('Aplican las condiciones de la garantía del fabricante', 'hh');
            $coverageSource = __('Garantía nativa registrada en GLPI', 'hh');
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
        self::assetDetail(__('N.º de inventario', 'hh'), (string) ($fields['otherserial'] ?? ''));
        self::assetDetail(__('Fabricante', 'hh'), (string) ($native['manufacturer'] ?? ''));
        self::assetDetail(__('Modelo', 'hh'), (string) ($native['model'] ?? ''));
        self::assetDetail(__('Marca', 'hh'), (string) ($native['brand'] ?? ''));
        self::assetDetail(__('Estado', 'hh'), (string) ($native['status'] ?? ''));
        self::assetDetail(__('Fecha de compra', 'hh'), self::displayDate($native['buy_date'] ?? null));
        self::assetDetail(__('Valor de compra', 'hh'), self::displayMoney($native['purchase_value'] ?? null));
        self::assetDetail(__('Tipo / información de garantía', 'hh'), $warrantyType);
        self::assetDetail(__('Cobertura aplicada desde', 'hh'), $coverageStart);
        self::assetDetail(__('Fin de garantía propia del equipo', 'hh'), self::displayDate($warrantyEnd));
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

    /** Read native GLPI fields. Table names are allowlisted by item type. */
    private static function loadNativeAssetInfo(string $itemType, int $itemId): array
    {
        global $DB;

        $tables = [
            'Phone' => ['asset' => 'glpi_phones', 'model' => 'glpi_phonemodels', 'model_field' => 'phonemodels_id'],
            'Computer' => ['asset' => 'glpi_computers', 'model' => 'glpi_computermodels', 'model_field' => 'computermodels_id'],
        ];
        if (!isset($tables[$itemType]) || $itemId < 1) {
            return ['contracts' => []];
        }

        $table = $tables[$itemType];
        $type = $DB->escape($itemType);
        $sql = "SELECT i.buy_date, i.use_date, i.warranty_date, i.warranty_duration,
                       i.warranty_info, i.value AS purchase_value, i.warranty_value,
                       i.suppliers_id, i.order_number, i.bill,
                       m.name AS manufacturer, mo.name AS model, s.name AS status,
                       a.brand, sup.name AS supplier
                FROM {$table['asset']} a
                LEFT JOIN glpi_infocoms i ON i.items_id = a.id AND i.itemtype = '{$type}'
                LEFT JOIN glpi_manufacturers m ON m.id = a.manufacturers_id
                LEFT JOIN {$table['model']} mo ON mo.id = a.{$table['model_field']}
                LEFT JOIN glpi_states s ON s.id = a.states_id
                LEFT JOIN glpi_suppliers sup ON sup.id = i.suppliers_id
                WHERE a.id = " . (int) $itemId . " AND a.is_deleted = 0
                LIMIT 1";

        $info = [
            'buy_date' => null, 'use_date' => null, 'warranty_date' => null,
            'warranty_duration' => 0, 'warranty_info' => null, 'purchase_value' => null,
            'warranty_value' => null, 'suppliers_id' => 0, 'order_number' => null,
            'bill' => null, 'manufacturer' => '', 'model' => '', 'status' => '',
            'brand' => '', 'supplier' => '', 'contracts' => [],
        ];
        $result = $DB->query($sql);
        if ($result) {
            $row = $DB->fetch_assoc($result);
            if (is_array($row)) {
                $info = array_merge($info, $row);
            }
        }

        $contractSql = "SELECT c.id AS contract_id, c.name AS contract_name, c.num AS contract_number,
                               ct.name AS contract_type, c.begin_date, c.duration AS contract_duration_months,
                               CASE WHEN c.begin_date IS NULL OR c.duration <= 0 THEN NULL
                                    ELSE DATE_ADD(c.begin_date, INTERVAL c.duration MONTH) END AS contract_end_date,
                               c.comment AS contract_conditions
                        FROM glpi_contracts_items ci
                        INNER JOIN glpi_contracts c ON c.id = ci.contracts_id
                        LEFT JOIN glpi_contracttypes ct ON ct.id = c.contracttypes_id
                        WHERE ci.itemtype = '{$type}'
                          AND ci.items_id = " . (int) $itemId . "
                          AND c.is_deleted = 0
                          AND c.is_template = 0
                        ORDER BY c.begin_date DESC, c.id DESC";
        $contractResult = $DB->query($contractSql);
        if ($contractResult) {
            while ($contract = $DB->fetch_assoc($contractResult)) {
                $info['contracts'][] = $contract;
            }
        }

        return $info;
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
            $normalized = mb_strtolower($type);
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
