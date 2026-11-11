<?php
declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Hh\Dashboard;
use GlpiPlugin\Hh\RepairTab;
use GlpiPlugin\Hh\RepairWorkflow;
use GlpiPlugin\Hh\Reports;
use GlpiPlugin\Hh\Profile as HhProfile;

require_once __DIR__ . '/src/Dashboard.php';
require_once __DIR__ . '/src/RepairTab.php';
require_once __DIR__ . '/src/Reports.php';
require_once __DIR__ . '/src/RepairAnalytics.php';
require_once __DIR__ . '/src/RepairWorkflow.php';
require_once __DIR__ . '/src/Rights.php';
require_once __DIR__ . '/src/Profile.php';

define('PLUGIN_HH_VERSION', '0.5.24');
define('PLUGIN_HH_MIN_GLPI', '11.0.0');
define('PLUGIN_HH_MAX_GLPI', '11.99.99');

function plugin_init_hh(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['hh'] = [
        'plugins' => Dashboard::class,
    ];

    // Ticket_Item is created after the ticket. This hook therefore sees the
    // real itemtype/items_id relation and changes only the selected asset.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['hh'] = [
        Ticket_Item::class => [RepairWorkflow::class, 'onTicketItemAdded'],
    ];

    Plugin::registerClass(HhProfile::class, ['addtabon' => Profile::class]);

    // GLPI 11 exposes native and Asset Definition classes through asset_types.
    $assetTypes = array_values(array_filter(
        $CFG_GLPI['asset_types'] ?? [],
        static fn($type): bool => is_string($type) && class_exists($type)
    ));
    Plugin::registerClass(RepairTab::class, [
        'addtabon' => array_values(array_unique($assetTypes)),
    ]);
}

function plugin_version_hh(): array
{
    return [
        'name'         => 'Gestión de activos y reparaciones',
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
