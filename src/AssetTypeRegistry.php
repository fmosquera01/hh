<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use CommonDBTM;
use Glpi\Asset\Asset;
use Glpi\Asset\AssetDefinitionManager;

/**
 * Resolves native GLPI assets and dynamically generated Asset Definition classes.
 * Follows the same discovery mechanism used by the Informe Técnico plugin.
 */
final class AssetTypeRegistry
{
    private const NATIVE_ASSETS = [
        'Computer',
        'Monitor',
        'NetworkEquipment',
        'Printer',
        'Phone',
        'Peripheral',
        'Software',
        'Cartridge',
        'Consumable',
        'Rack',
        'Enclosure',
        'PDU',
        'PassiveDCEquipment',
        'Unmanaged',
        'Cable',
    ];

    /** @var string[]|null */
    private static ?array $itemtypes = null;

    /** @return string[] */
    public static function getItemtypes(): array
    {
        if (self::$itemtypes !== null) {
            return self::$itemtypes;
        }

        $itemtypes = [];
        foreach (self::NATIVE_ASSETS as $itemtype) {
            if (class_exists($itemtype) && is_a($itemtype, CommonDBTM::class, true)) {
                $itemtypes[] = $itemtype;
            }
        }

        if (class_exists(AssetDefinitionManager::class)) {
            $assetManager = AssetDefinitionManager::getInstance();
            // Load active definitions and bootstrap their generated classes.
            $assetManager->bootDefinitions();

            foreach ($assetManager->getDefinitions(true) as $definition) {
                $itemtype = $definition->getAssetClassName();
                if (
                    is_string($itemtype)
                    && class_exists($itemtype)
                    && is_a($itemtype, Asset::class, true)
                    && is_a($itemtype, CommonDBTM::class, true)
                ) {
                    $itemtypes[] = $itemtype;
                }
            }
        }

        self::$itemtypes = array_values(array_unique($itemtypes));
        return self::$itemtypes;
    }

    public static function isSupported(string $itemtype): bool
    {
        return in_array($itemtype, self::getItemtypes(), true);
    }
}
