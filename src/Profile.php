<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonDBTM;
use CommonGLPI;
use Profile as GlpiProfile;
use ProfileRight;

final class Profile extends CommonDBTM
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return __('Gestión de reparaciones HH', 'hh');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && (int)$item->getID() > 0) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && (int)$item->getID() > 0) {
            self::showForProfile((int)$item->getID());
        }
        return true;
    }

    public static function getAllRights($all = false): array
    {
        return [[
            'itemtype' => self::class,
            'label' => self::getTypeName(),
            'field' => 'plugin_hh',
        ]];
    }

    public static function showForProfile(int $profilesId): void
    {
        $profile = new GlpiProfile();
        if (!$profile->getFromDB($profilesId)) {
            return;
        }

        $rights = ProfileRight::getProfileRights($profilesId, ['plugin_hh']);
        $mask = (int)($rights['plugin_hh'] ?? 0);
        $canEdit = Session::haveRight('profile', UPDATE);
        $options = [
            Rights::READ => 'Consultar Gestión de reparaciones, cotizaciones e historial',
            Rights::CREATE => 'Crear casos/cotizaciones en borrador',
            Rights::UPDATE => 'Modificar cotizaciones en borrador',
            Rights::APPROVE => 'Aprobar o rechazar cotizaciones',
            Rights::CATALOG => 'Administrar catálogo de repuestos y servicios',
            Rights::POINTS => 'Registrar compras y consumos de puntos',
            Rights::POINT_VALUE => 'Modificar el valor monetario del punto',
        ];

        echo "<div class='card m-3'><div class='card-header'><h3 class='card-title'>Permisos de Gestión de reparaciones HH</h3></div><div class='card-body'>";
        echo "<p class='text-muted'>Asigne únicamente las funciones que correspondan a este perfil. Los permisos se validan también en el servidor.</p>";
        echo "<form method='post' action='/plugins/hh/front/profile.php'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES, 'UTF-8') . "'>";
        echo "<input type='hidden' name='profiles_id' value='{$profilesId}'>";
        foreach ($options as $bit => $label) {
            $checked = ($mask & $bit) === $bit ? ' checked' : '';
            $disabled = $canEdit ? '' : ' disabled';
            echo "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' name='rights[]' value='{$bit}' id='hh-right-{$bit}'{$checked}{$disabled}><label class='form-check-label' for='hh-right-{$bit}'>" . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</label></div>";
        }
        if ($canEdit) {
            echo "<button class='btn btn-primary mt-3' type='submit' name='save_hh_rights' value='1'>Guardar permisos</button>";
        } else {
            echo "<div class='alert alert-info mt-3 mb-0'>Se requiere el permiso de actualización de perfiles para modificar estos derechos.</div>";
        }
        echo "</form></div></div>";
    }
}
