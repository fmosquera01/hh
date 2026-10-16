<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonGLPI;

final class Dashboard extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('HH - Gestión de reparaciones', 'hh');
    }

    public static function getMenuName($nb = 0): string
    {
        return self::getTypeName();
    }

    public static function getMenuContent(): array
    {
        $url = '/plugins/hh/front/index.php';

        return [
            'title' => self::getMenuName(),
            'page'  => $url,
            'icon'  => 'ti ti-tool',
        ];
    }
}
