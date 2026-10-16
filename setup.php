<?php
declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Hh\Dashboard;
use GlpiPlugin\Hh\RepairTab;

require_once __DIR__ . '/src/Dashboard.php';
require_once __DIR__ . '/src/RepairTab.php';

define('PLUGIN_HH_VERSION', '0.2.0');
define('PLUGIN_HH_MIN_GLPI', '11.0.0');
define('PLUGIN_HH_MAX_GLPI', '11.99.99');

function plugin_init_hh(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['hh'] = [
        'plugins' => Dashboard::class,
    ];

    // Add a native tab to supported asset forms in GLPI.
    Plugin::registerClass(RepairTab::class, [
        'addtabon' => [
            'Computer',
            'Phone',
        ],
    ]);
}

function plugin_version_hh(): array
{
    return [
        'name'         => 'Gestión de reparaciones',
        'version'      => PLUGIN_HH_VERSION,
        'author'       => 'Songa IT',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/fmosquera01/hh',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_HH_MIN_GLPI,
                'max' => PLUGIN_HH_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_hh_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_HH_MIN_GLPI, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_HH_MAX_GLPI, '<=');
}

function plugin_hh_check_config($verbose = false): bool
{
    return true;
}
