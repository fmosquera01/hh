<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonGLPI;
use Computer;
use Html;
use Phone;

/**
 * Adds the repair-management tab to supported GLPI assets.
 *
 * The current asset is supplied by GLPI, so its type and ID do not need
 * to be searched or selected again by the user.
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

        if ($item instanceof Computer || $item instanceof Phone) {
            return self::getTypeName();
        }

        return '';
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

        echo "<div class='container-fluid'>";
        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'>"
            . Html::entities_deep(self::getTypeName())
            . "</h3></div>";
        echo "<div class='card-body'>";

        echo "<h4>" . Html::entities_deep(__('Activo vinculado', 'hh')) . "</h4>";
        echo "<dl class='row mb-3'>";
        self::renderDetail(__('Tipo de activo', 'hh'), $itemType);
        self::renderDetail(__('ID en GLPI', 'hh'), (string) $itemId);
        self::renderDetail(__('Nombre', 'hh'), $name !== '' ? $name : '—');
        self::renderDetail(__('Número de serie', 'hh'), $serial !== '' ? $serial : '—');
        self::renderDetail(__('Número de inventario', 'hh'), $assetTag !== '' ? $assetTag : '—');
        echo "</dl>";

        echo "<div class='alert alert-info' role='status'>"
            . Html::entities_deep(__('El activo queda identificado automáticamente desde su ficha. La lista y creación de cotizaciones se incorporarán en la siguiente etapa; todavía no se registran reparaciones desde esta pestaña.', 'hh'))
            . "</div>";

        $url = '/plugins/hh/front/index.php?itemtype=' . rawurlencode($itemType)
            . '&items_id=' . $itemId;
        echo "<a class='btn btn-primary' href='" . Html::entities_deep($url) . "'>"
            . Html::entities_deep(__('Abrir Gestión de reparaciones', 'hh'))
            . "</a>";

        echo "</div></div></div>";

        return true;
    }

    private static function renderDetail(string $label, string $value): void
    {
        echo "<dt class='col-sm-3'>" . Html::entities_deep($label) . "</dt>";
        echo "<dd class='col-sm-9'>" . Html::entities_deep($value) . "</dd>";
    }
}
