<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonGLPI;
use Html;
use Session;

/**
 * Native GLPI profile rights for HH.
 * The seven capabilities are columns in GLPI's standard profile matrix.
 */
final class Profile extends \Profile
{
    public static $rightname = 'plugin_hh';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            return self::createTabEntry(__('Gestión de reparaciones HH', 'hh'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            $profile = new self();
            $profile->showFormHhRights((int) $item->getID());
        }
        return true;
    }

    private function showFormHhRights(int $profilesId): void
    {
        $targetProfile = new \Profile();
        $canEdit = Session::haveRight('profile', UPDATE);
        if (!$targetProfile->getFromDB($profilesId)) {
            return;
        }

        echo "<div class='spaced'>";
        if ($canEdit) {
            echo "<form method='post' action='" . htmlspecialchars(\Profile::getFormURL(), ENT_QUOTES, 'UTF-8') . "'>";
        }

        $rights = [[
            'itemtype' => self::class,
            'label'    => __('Gestión de reparaciones', 'hh'),
            'field'    => self::$rightname,
            'rights'   => [
                Rights::READ        => __('Consultar', 'hh'),
                Rights::CREATE      => __('Crear borradores', 'hh'),
                Rights::UPDATE      => __('Editar borradores', 'hh'),
                Rights::APPROVE     => __('Aprobar / rechazar', 'hh'),
                Rights::CATALOG     => __('Administrar catálogo', 'hh'),
                Rights::POINTS      => __('Administrar puntos', 'hh'),
                Rights::POINT_VALUE => __('Modificar valor del punto', 'hh'),
            ],
        ]];

        $targetProfile->displayRightsChoiceMatrix($rights, [
            'canedit' => $canEdit,
            'title'   => __('Derechos de Gestión de reparaciones HH', 'hh'),
        ]);

        if ($canEdit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $profilesId]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo '</div>';
            Html::closeForm();
        } else {
            echo "<div class='alert alert-info'>" .
                htmlspecialchars(__('Se requiere el permiso de actualización de perfiles para modificar estos derechos.', 'hh'), ENT_QUOTES, 'UTF-8') .
                '</div>';
        }
        echo '</div>';
    }
}
