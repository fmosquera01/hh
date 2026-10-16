<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonGLPI;
use Computer;
use Html;
use Phone;

/**
 * Visual prototype of repair management inside a GLPI asset tab.
 * This stage is intentionally read-only: it does not persist quotes or items.
 */
final class RepairTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Gestión de reparaciones', 'hh');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate) {
            return '';
        }

        return ($item instanceof Computer || $item instanceof Phone)
            ? self::getTypeName()
            : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Computer) && !($item instanceof Phone)) {
            return true;
        }

        $itemType = $item::getType();
        $itemId = (int) $item->getID();
        $fields = $item->fields ?? [];
        $name = (string) ($fields['name'] ?? '');
        $serial = (string) ($fields['serial'] ?? '');
        $assetTag = (string) ($fields['otherserial'] ?? '');
        $manufacturer = (string) ($fields['manufacturers_id'] ?? '');
        $model = (string) ($fields['computermodels_id'] ?? $fields['phonemodels_id'] ?? '');
        $status = (string) ($fields['states_id'] ?? '');

        echo "<div class='container-fluid hh-repairs'>";
        echo "<style>
            .hh-repairs .hh-hero{border:0;border-radius:.65rem;background:linear-gradient(120deg,#17365d,#245b83);color:#fff}
            .hh-repairs .hh-hero .text-muted{color:#d8e6f2!important}
            .hh-repairs .hh-stat{height:100%;border:1px solid var(--tblr-border-color,#dce1e7);border-radius:.5rem;padding:1rem;background:var(--tblr-card-bg,#fff)}
            .hh-repairs .hh-stat .hh-stat-value{font-size:1.3rem;font-weight:700;margin-top:.25rem}
            .hh-repairs .hh-section{scroll-margin-top:1rem}
            .hh-repairs .hh-section-title{display:flex;align-items:center;gap:.5rem}
            .hh-repairs .hh-label{font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--tblr-secondary,#667382)}
            .hh-repairs .hh-empty{border:1px dashed var(--tblr-border-color,#dce1e7);border-radius:.5rem;padding:2rem 1rem;text-align:center}
            .hh-repairs .hh-empty-icon{font-size:2rem;color:#7a8795}
            .hh-repairs .hh-demo{font-size:.75rem}
            .hh-repairs .hh-sticky-nav{position:relative;z-index:1}
        </style>";

        echo "<div class='card hh-hero mb-3'><div class='card-body p-4'>";
        echo "<div class='d-flex flex-wrap justify-content-between align-items-start gap-3'>";
        echo "<div><div class='text-muted small mb-1'>SONGA IT · MÓDULO DE ACTIVOS</div>";
        echo "<h2 class='mb-1'>" . self::e(__('Gestión de reparaciones', 'hh')) . "</h2>";
        echo "<div class='text-muted'>" . self::e($name !== '' ? $name : __('Activo sin nombre', 'hh')) . " · " . self::e($itemType) . " #" . $itemId . "</div></div>";
        echo "<span class='badge bg-warning text-dark'>" . self::e(__('Prototipo visual', 'hh')) . "</span>";
        echo "</div><hr class='border-light opacity-25'>";
        echo "<div class='row g-3'>";
        echo "<div class='col-md-3'><div class='small text-muted'>" . self::e(__('Número de serie', 'hh')) . "</div><strong>" . self::e($serial !== '' ? $serial : '—') . "</strong></div>";
        echo "<div class='col-md-3'><div class='small text-muted'>" . self::e(__('N.º de inventario', 'hh')) . "</div><strong>" . self::e($assetTag !== '' ? $assetTag : '—') . "</strong></div>";
        echo "<div class='col-md-3'><div class='small text-muted'>" . self::e(__('Fabricante (ID GLPI)', 'hh')) . "</div><strong>" . self::e($manufacturer !== '' && $manufacturer !== '0' ? $manufacturer : '—') . "</strong></div>";
        echo "<div class='col-md-3'><div class='small text-muted'>" . self::e(__('Estado (ID GLPI)', 'hh')) . "</div><strong>" . self::e($status !== '' && $status !== '0' ? $status : '—') . "</strong></div>";
        echo "</div></div></div>";

        echo "<div class='alert alert-info d-flex align-items-start gap-2 mb-3' role='status'>";
        echo "<i class='ti ti-info-circle fs-2'></i><div><strong>" . self::e(__('Vista de diseño: no guarda información', 'hh')) . "</strong><br>";
        echo self::e(__('Los formularios, cotizaciones, repuestos y parámetros de ejemplo son únicamente visuales. En esta etapa no se crean ni modifican registros.', 'hh'));
        echo "</div></div>";

        echo "<div class='row g-3 mb-4'>";
        self::statCard(__('Cotizaciones'), '0', __('Registradas para este activo'));
        self::statCard(__('En aprobación'), '0', __('Pendientes de revisión'));
        self::statCard(__('Total cotizado'), '$0.00', __('Se calculará con el valor por punto vigente'));
        self::statCard(__('Última reparación'), '—', __('Sin historial cargado'));
        echo "</div>";

        echo "<div class='card mb-4 hh-sticky-nav'><div class='card-body py-2'>";
        echo "<nav class='nav nav-pills flex-wrap gap-2' aria-label='Secciones de gestión de reparaciones'>";
        $nav = [
            ['quotes', 'file-invoice', __('Cotizaciones')],
            ['new-quote', 'plus-circle', __('Nueva cotización')],
            ['catalog', 'list-details', __('Lista maestra de ítems')],
            ['rules', 'adjustments', __('Parámetros y evaluación')],
            ['history', 'history', __('Historial')],
        ];
        foreach ($nav as [$id, $icon, $label]) {
            echo "<a class='nav-link' href='#hh-" . self::e($id) . "'><i class='ti ti-" . self::e($icon) . " me-1'></i>" . self::e($label) . "</a>";
        }
        echo "</nav></div></div>";

        // Quote list
        echo "<section id='hh-quotes' class='hh-section card mb-4'><div class='card-header d-flex flex-wrap justify-content-between align-items-center gap-2'>";
        echo "<h3 class='card-title mb-0'><i class='ti ti-file-invoice me-2'></i>" . self::e(__('Cotizaciones del activo')) . "</h3>";
        echo "<a class='btn btn-primary' href='#hh-new-quote'><i class='ti ti-plus me-1'></i>" . self::e(__('Nueva cotización')) . "</a>";
        echo "</div><div class='card-body'>";
        echo "<div class='table-responsive'><table class='table table-vcenter table-hover'><thead><tr>";
        foreach ([__('Código'), __('Fecha'), __('Proveedor / taller'), __('Detalle'), __('Total'), __('Estado'), __('Acciones')] as $heading) {
            echo "<th>" . self::e($heading) . "</th>";
        }
        echo "</tr></thead><tbody><tr><td colspan='7'><div class='hh-empty'><div class='hh-empty-icon'><i class='ti ti-file-off'></i></div><h4 class='mt-2 mb-1'>" . self::e(__('Todavía no hay cotizaciones')) . "</h4><p class='text-muted mb-0'>" . self::e(__('Cuando conectemos el almacenamiento, aquí se listarán las cotizaciones asociadas a este activo.')) . "</p></div></td></tr></tbody></table></div>";
        echo "</div></section>";

        // New quote visual form
        echo "<section id='hh-new-quote' class='hh-section card mb-4'><div class='card-header'><h3 class='card-title mb-0'><i class='ti ti-file-plus me-2'></i>" . self::e(__('Nueva cotización de reparación')) . "</h3><span class='badge bg-secondary-lt'>" . self::e(__('Maqueta')) . "</span></div><div class='card-body'>";
        echo "<div class='row g-3'>";
        self::formField(__('Código de cotización'), '<input class="form-control" value="Se asignará automáticamente" disabled>');
        self::formField(__('Fecha de cotización'), '<input type="date" class="form-control" disabled>');
        self::formField(__('Proveedor / taller'), '<input class="form-control" placeholder="Nombre del proveedor" disabled>');
        self::formField(__('Número de cotización del proveedor'), '<input class="form-control" placeholder="Referencia externa" disabled>');
        self::formField(__('Tipo de reparación'), '<select class="form-select" disabled><option>Correctiva</option><option>Preventiva</option><option>Garantía</option></select>');
        self::formField(__('Prioridad'), '<select class="form-select" disabled><option>Normal</option><option>Alta</option><option>Crítica</option></select>');
        echo "<div class='col-12'><label class='form-label'>" . self::e(__('Diagnóstico / falla reportada')) . "</label><textarea class='form-control' rows='2' placeholder='Describe la falla reportada y el diagnóstico técnico' disabled></textarea></div>";
        echo "</div><hr><div class='d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2'><h4 class='mb-0'>" . self::e(__('Detalle de repuestos y trabajos')) . "</h4><button type='button' class='btn btn-outline-primary' disabled><i class='ti ti-plus me-1'></i>" . self::e(__('Agregar ítem')) . "</button></div>";
        echo "<div class='table-responsive'><table class='table table-bordered'><thead><tr><th>" . self::e(__('Código / ítem maestro')) . "</th><th>" . self::e(__('Descripción')) . "</th><th>" . self::e(__('Cant.')) . "</th><th>" . self::e(__('Puntos por unidad')) . "</th><th>" . self::e(__('Puntos total')) . "</th><th>" . self::e(__('Valor')) . "</th></tr></thead><tbody>";
        echo "<tr><td><select class='form-select' disabled><option>" . self::e(__('Seleccionar ítem…')) . "</option></select></td><td><input class='form-control' placeholder='Descripción del repuesto o trabajo' disabled></td><td><input class='form-control' value='1' disabled></td><td><input class='form-control' value='0.00' disabled></td><td>0.00</td><td>$0.00</td></tr>";
        echo "</tbody><tfoot><tr><th colspan='4' class='text-end'>" . self::e(__('Totales estimados')) . "</th><th>0.00</th><th>$0.00</th></tr></tfoot></table></div>";
        echo "<div class='row g-3 mt-1'><div class='col-lg-7'><label class='form-label'>" . self::e(__('Observaciones')) . "</label><textarea class='form-control' rows='2' placeholder='Notas internas, alcance, condiciones…' disabled></textarea></div><div class='col-lg-5'><div class='rounded border p-3'><div class='d-flex justify-content-between mb-2'><span>" . self::e(__('Valor por punto')) . "</span><strong>Por configurar</strong></div><div class='d-flex justify-content-between mb-2'><span>" . self::e(__('Subtotal')) . "</span><strong>$0.00</strong></div><div class='d-flex justify-content-between'><span>" . self::e(__('Total cotización')) . "</span><strong class='fs-2'>$0.00</strong></div></div></div></div>";
        echo "<div class='d-flex justify-content-end gap-2 mt-3'><button class='btn btn-outline-secondary' type='button' disabled>" . self::e(__('Cancelar')) . "</button><button class='btn btn-primary' type='button' disabled><i class='ti ti-device-floppy me-1'></i>" . self::e(__('Guardar borrador')) . "</button><button class='btn btn-success' type='button' disabled>" . self::e(__('Enviar a aprobación')) . "</button></div>";
        echo "</div></section>";

        // Master items catalog visual
        echo "<section id='hh-catalog' class='hh-section card mb-4'><div class='card-header d-flex flex-wrap justify-content-between align-items-center gap-2'><h3 class='card-title mb-0'><i class='ti ti-list-details me-2'></i>" . self::e(__('Lista maestra de ítems')) . "</h3><button class='btn btn-outline-primary' disabled><i class='ti ti-plus me-1'></i>" . self::e(__('Nuevo ítem maestro')) . "</button></div><div class='card-body'>";
        echo "<p class='text-muted'>" . self::e(__('Catálogo central para reutilizar repuestos y trabajos en diferentes cotizaciones. El catálogo real se cargará en una etapa posterior.')) . "</p>";
        echo "<div class='row g-3 mb-3'>";
        self::formField(__('Buscar'), '<input class="form-control" placeholder="Código o descripción" disabled>');
        self::formField(__('Categoría'), '<select class="form-select" disabled><option>Todas las categorías</option><option>Repuesto</option><option>Mano de obra</option><option>Servicio</option></select>');
        self::formField(__('Estado'), '<select class="form-select" disabled><option>Activos</option><option>Inactivos</option><option>Todos</option></select>');
        echo "</div><div class='table-responsive'><table class='table table-vcenter'><thead><tr><th>" . self::e(__('Código')) . "</th><th>" . self::e(__('Descripción del ítem')) . "</th><th>" . self::e(__('Categoría')) . "</th><th>" . self::e(__('Puntos')) . "</th><th>" . self::e(__('Estado')) . "</th><th>" . self::e(__('Acciones')) . "</th></tr></thead><tbody><tr><td colspan='6' class='text-center text-muted py-4'>" . self::e(__('El catálogo aún no contiene registros.')) . "</td></tr></tbody></table></div></div></section>";

        // Rules and approval workflow visual
        echo "<section id='hh-rules' class='hh-section card mb-4'><div class='card-header'><h3 class='card-title mb-0'><i class='ti ti-adjustments me-2'></i>" . self::e(__('Parámetros y evaluación de reparación')) . "</h3></div><div class='card-body'><div class='row g-3'>";
        echo "<div class='col-lg-6'><div class='card card-sm h-100'><div class='card-body'><h4>" . self::e(__('Valor del punto')) . "</h4><p class='text-muted'>" . self::e(__('Valor monetario vigente aplicado al cálculo de cada cotización. Cada cotización conservará una copia del valor usado.')) . "</p><label class='form-label'>" . self::e(__('Valor por punto (USD)')) . "</label><input class='form-control mb-2' placeholder='Ej. 1.00' disabled><label class='form-label'>" . self::e(__('Vigente desde')) . "</label><input type='date' class='form-control' disabled></div></div></div>";
        echo "<div class='col-lg-6'><div class='card card-sm h-100'><div class='card-body'><h4>" . self::e(__('Reglas de evaluación')) . "</h4><div class='d-flex justify-content-between border-bottom py-2'><span>" . self::e(__('Reparación repetida')) . "</span><strong>60 días</strong></div><div class='d-flex justify-content-between border-bottom py-2'><span>" . self::e(__('Umbral respecto al valor de compra')) . "</span><strong>40%</strong></div><p class='text-muted small mt-2'>" . self::e(__('En la fase funcional, estos indicadores se calcularán con el historial de reparaciones y el valor de compra del activo.')) . "</p></div></div></div>";
        echo "<div class='col-12'><div class='card card-sm'><div class='card-body'><h4>" . self::e(__('Flujo de aprobación propuesto')) . "</h4><div class='row g-2 text-center'>";
        foreach ([__('Borrador'), __('En revisión'), __('Aprobada / Rechazada'), __('Reparación'), __('Cerrada')] as $step) {
            echo "<div class='col'><div class='border rounded p-3 h-100'><i class='ti ti-circle-check fs-2 text-muted'></i><div class='mt-1'>" . self::e($step) . "</div></div></div>";
        }
        echo "</div></div></div></div></div></div></section>";

        // History visual
        echo "<section id='hh-history' class='hh-section card mb-4'><div class='card-header'><h3 class='card-title mb-0'><i class='ti ti-history me-2'></i>" . self::e(__('Historial de reparaciones y cambios')) . "</h3></div><div class='card-body'><div class='hh-empty'><div class='hh-empty-icon'><i class='ti ti-history'></i></div><h4 class='mt-2 mb-1'>" . self::e(__('Sin historial disponible')) . "</h4><p class='text-muted mb-0'>" . self::e(__('Aquí se mostrará la trazabilidad de cotizaciones, aprobaciones, cambios de estado, garantías y reparaciones anteriores.')) . "</p></div></div></section>";

        echo "<p class='text-muted small'>" . self::e(__('Activo contextualizado desde GLPI. Toda la experiencia de reparación se mantendrá dentro de esta pestaña.')) . "</p>";
        echo "</div>";
        return true;
    }

    private static function statCard(string $label, string $value, string $description): void
    {
        echo "<div class='col-6 col-xl-3'><div class='hh-stat'><div class='text-muted'>" . self::e($label) . "</div><div class='hh-stat-value'>" . self::e($value) . "</div><div class='small text-muted mt-1'>" . self::e($description) . "</div></div></div>";
    }

    private static function formField(string $label, string $control): void
    {
        echo "<div class='col-md-6 col-xl-4'><label class='form-label'>" . self::e($label) . "</label>" . $control . "</div>";
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
