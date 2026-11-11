<?php
declare(strict_types=1);

use GlpiPlugin\Hh\Profile as HhProfile;
use GlpiPlugin\Hh\Rights;

function plugin_hh_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_HH_VERSION);
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    $tables = [
        'glpi_plugin_hh_pointvalues' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_pointvalues` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `value_per_point` decimal(12,4) NOT NULL,
            `currency` char(3) NOT NULL DEFAULT 'USD',
            `valid_from` timestamp NOT NULL,
            `valid_until` timestamp DEFAULT NULL,
            `reason` varchar(255) DEFAULT NULL,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hh_pointvalues_validity` (`valid_from`, `valid_until`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_pointmovements' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_pointmovements` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `movement_type` varchar(20) NOT NULL,
            `points` decimal(12,2) NOT NULL,
            `movement_date` date NOT NULL,
            `supplier_quote_number` varchar(100) DEFAULT NULL,
            `plugin_hh_quotes_id` int unsigned DEFAULT NULL,
            `itemtype` varchar(100) DEFAULT NULL,
            `items_id` int unsigned DEFAULT NULL,
            `description` varchar(255) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_quote_movement` (`plugin_hh_quotes_id`, `movement_type`),
            KEY `idx_hh_pointmovements_date` (`movement_date`),
            KEY `idx_hh_pointmovements_type` (`movement_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_items' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_items` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `code` varchar(40) NOT NULL,
            `name` varchar(180) NOT NULL,
            `description` text DEFAULT NULL,
            `category` varchar(30) NOT NULL DEFAULT 'part',
            `points_each` decimal(12,2) NOT NULL DEFAULT 0,
            `warranty_days` int unsigned NOT NULL DEFAULT 60,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_code` (`code`),
            KEY `idx_hh_items_category_active` (`category`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_itemtypes' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_itemtypes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_hh_items_id` int unsigned NOT NULL,
            `itemtype` varchar(100) NOT NULL,
            `models_id` int unsigned DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_item_type_model` (`plugin_hh_items_id`, `itemtype`, `models_id`),
            KEY `idx_hh_itemtypes_type_model` (`itemtype`, `models_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_quotes' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_quotes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(40) NOT NULL,
            `itemtype` varchar(100) DEFAULT NULL,
            `items_id` int unsigned DEFAULT NULL,
            `quote_date` date DEFAULT NULL,
            `suppliers_id` int unsigned DEFAULT NULL,
            `supplier_name_snapshot` varchar(180) DEFAULT NULL,
            `supplier_quote_number` varchar(100) DEFAULT NULL,
            `tickets_id` int unsigned DEFAULT NULL,
            `guide_number` varchar(100) DEFAULT NULL,
            `sent_to_supplier_at` timestamp DEFAULT NULL,
            `supplier_received_at` timestamp DEFAULT NULL,
            `returned_at` timestamp DEFAULT NULL,
            `repair_type` varchar(30) NOT NULL DEFAULT 'corrective',
            `priority` varchar(20) NOT NULL DEFAULT 'normal',
            `diagnosis` text DEFAULT NULL,
            `observations` text DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'draft',
            `total_points` decimal(12,2) NOT NULL DEFAULT 0,
            `point_value_snapshot` decimal(12,4) NOT NULL,
            `currency` char(3) NOT NULL DEFAULT 'USD',
            `total_amount_snapshot` decimal(14,2) NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity_name` (`name`),
            KEY `idx_hh_quotes_item` (`itemtype`, `items_id`),
            KEY `idx_hh_quotes_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_quotedetails' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_quotedetails` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_hh_quotes_id` int unsigned NOT NULL,
            `plugin_hh_items_id` int unsigned DEFAULT NULL,
            `code_snapshot` varchar(40) DEFAULT NULL,
            `category_snapshot` varchar(30) DEFAULT NULL,
            `source_itemtype` varchar(100) DEFAULT NULL,
            `source_items_id` int unsigned DEFAULT NULL,
            `is_warranty` tinyint NOT NULL DEFAULT 0,
            `warranty_days_snapshot` int unsigned NOT NULL DEFAULT 60,
            `description_snapshot` varchar(255) NOT NULL,
            `quantity` decimal(10,2) NOT NULL DEFAULT 1,
            `points_each` decimal(12,2) NOT NULL DEFAULT 0,
            `points_total` decimal(12,2) NOT NULL DEFAULT 0,
            `point_value_snapshot` decimal(12,4) NOT NULL,
            `amount_snapshot` decimal(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_hh_quotedetails_quote` (`plugin_hh_quotes_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_general_quotes' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_general_quotes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int unsigned NOT NULL,
            `quote_number` varchar(100) DEFAULT NULL,
            `suppliers_id` int unsigned DEFAULT NULL,
            `supplier_name_snapshot` varchar(180) NOT NULL,
            `quote_date` date NOT NULL,
            `valid_until` date DEFAULT NULL,
            `currency` char(3) NOT NULL DEFAULT 'USD',
            `quote_mode` varchar(20) NOT NULL DEFAULT 'global',
            `description` varchar(255) NOT NULL,
            `subtotal` decimal(14,2) NOT NULL DEFAULT 0,
            `discount` decimal(14,2) NOT NULL DEFAULT 0,
            `tax` decimal(14,2) NOT NULL DEFAULT 0,
            `transport` decimal(14,2) NOT NULL DEFAULT 0,
            `total_amount` decimal(14,2) NOT NULL DEFAULT 0,
            `observations` text DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'draft',
            `selection_reason` text DEFAULT NULL,
            `attachment_path` varchar(255) DEFAULT NULL,
            `attachment_name` varchar(255) DEFAULT NULL,
            `approved_by` int unsigned DEFAULT NULL,
            `approved_at` datetime DEFAULT NULL,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hh_general_quote_asset` (`itemtype`, `items_id`),
            KEY `idx_hh_general_quote_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_general_quotelines' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_general_quotelines` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `general_quotes_id` int unsigned NOT NULL,
            `description` varchar(255) NOT NULL,
            `quantity` decimal(10,2) NOT NULL DEFAULT 1,
            `unit_price` decimal(14,2) DEFAULT NULL,
            `line_total` decimal(14,2) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_hh_general_quotelines_quote` (`general_quotes_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_general_quotehistory' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_general_quotehistory` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `general_quotes_id` int unsigned NOT NULL,
            `old_status` varchar(20) DEFAULT NULL,
            `new_status` varchar(20) NOT NULL,
            `users_id` int unsigned NOT NULL,
            `comment` text DEFAULT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hh_general_quotehistory_quote` (`general_quotes_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_workflow_config' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_workflow_config` (
            `id` int unsigned NOT NULL,
            `itilcategories_id` int unsigned NOT NULL DEFAULT 0,
            `requesttypes_id` int unsigned NOT NULL DEFAULT 0,
            `enabled` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_asset_history' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_asset_history` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int unsigned NOT NULL,
            `tickets_id` int unsigned DEFAULT NULL,
            `old_states_id` int unsigned NOT NULL DEFAULT 0,
            `new_states_id` int unsigned NOT NULL DEFAULT 0,
            `action` varchar(40) NOT NULL,
            `notes` text DEFAULT NULL,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hh_asset_history_asset` (`itemtype`, `items_id`),
            KEY `idx_hh_asset_history_ticket` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_quotehistory' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_quotehistory` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_hh_quotes_id` int unsigned NOT NULL,
            `old_status` varchar(20) DEFAULT NULL,
            `new_status` varchar(20) NOT NULL,
            `users_id` int unsigned NOT NULL,
            `comment` text DEFAULT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hh_quotehistory_quote` (`plugin_hh_quotes_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
    ];

    foreach ($tables as $table => $sql) {
        if (!$DB->tableExists($table)) {
            $migration->addPostQuery($sql);
        }
    }

    // Add request origin while preserving the legacy category column and all settings.
    if ($DB->tableExists('glpi_plugin_hh_workflow_config')) {
        $migration->addPostQuery("ALTER TABLE `glpi_plugin_hh_workflow_config` ADD COLUMN IF NOT EXISTS `requesttypes_id` int unsigned NOT NULL DEFAULT 0");
    }

    // Upgrade tables created by earlier HH versions without dropping existing data.
    // MariaDB supports ADD COLUMN IF NOT EXISTS, which makes this migration repeatable.
    $upgrades = [
        'glpi_plugin_hh_quotes' => [
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `quote_date` date DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `suppliers_id` int unsigned DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `supplier_name_snapshot` varchar(180) DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `supplier_quote_number` varchar(100) DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `tickets_id` int unsigned DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `guide_number` varchar(100) DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `sent_to_supplier_at` timestamp DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `supplier_received_at` timestamp DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `returned_at` timestamp DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `repair_type` varchar(30) NOT NULL DEFAULT 'corrective'",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `priority` varchar(20) NOT NULL DEFAULT 'normal'",
            "ALTER TABLE `glpi_plugin_hh_quotes` ADD COLUMN IF NOT EXISTS `observations` text DEFAULT NULL",
        ],
        'glpi_plugin_hh_items' => [
            "ALTER TABLE `glpi_plugin_hh_items` ADD COLUMN IF NOT EXISTS `warranty_days` int unsigned NOT NULL DEFAULT 60",
        ],
        'glpi_plugin_hh_quotedetails' => [
            "ALTER TABLE `glpi_plugin_hh_quotedetails` ADD COLUMN IF NOT EXISTS `warranty_days_snapshot` int unsigned NOT NULL DEFAULT 60",
            "ALTER TABLE `glpi_plugin_hh_quotedetails` ADD COLUMN IF NOT EXISTS `plugin_hh_items_id` int unsigned DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotedetails` ADD COLUMN IF NOT EXISTS `code_snapshot` varchar(40) DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotedetails` ADD COLUMN IF NOT EXISTS `category_snapshot` varchar(30) DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotedetails` ADD COLUMN IF NOT EXISTS `source_itemtype` varchar(100) DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotedetails` ADD COLUMN IF NOT EXISTS `source_items_id` int unsigned DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_hh_quotedetails` ADD COLUMN IF NOT EXISTS `is_warranty` tinyint NOT NULL DEFAULT 0",
        ],
    ];

    foreach ($upgrades as $table => $queries) {
        if ($DB->tableExists($table)) {
            foreach ($queries as $query) {
                $migration->addPostQuery($query);
            }
        }
    }

    // Convert legacy DATETIME columns to GLPI-preferred TIMESTAMP without losing stored values.
    // These fields represent operational dates and are expected to remain within TIMESTAMP's supported range.
    if ($DB->tableExists('glpi_plugin_hh_pointvalues')) {
        $migration->addPostQuery("ALTER TABLE `glpi_plugin_hh_pointvalues` MODIFY COLUMN `valid_from` timestamp NOT NULL");
        $migration->addPostQuery("ALTER TABLE `glpi_plugin_hh_pointvalues` MODIFY COLUMN `valid_until` timestamp NULL DEFAULT NULL");
    }
    if ($DB->tableExists('glpi_plugin_hh_quotes')) {
        $migration->addPostQuery("ALTER TABLE `glpi_plugin_hh_quotes` MODIFY COLUMN `sent_to_supplier_at` timestamp NULL DEFAULT NULL");
        $migration->addPostQuery("ALTER TABLE `glpi_plugin_hh_quotes` MODIFY COLUMN `supplier_received_at` timestamp NULL DEFAULT NULL");
        $migration->addPostQuery("ALTER TABLE `glpi_plugin_hh_quotes` MODIFY COLUMN `returned_at` timestamp NULL DEFAULT NULL");
    }

    // Preserve legacy diagnosis text in the single observations field, then remove the duplicate column.
    // The field check makes the migration safe to rerun on subsequent plugin upgrades.
    if ($DB->tableExists('glpi_plugin_hh_quotes') && $DB->fieldExists('glpi_plugin_hh_quotes', 'diagnosis')) {
        $migration->addPostQuery("UPDATE `glpi_plugin_hh_quotes` SET `observations` = `diagnosis` WHERE (`observations` IS NULL OR `observations` = '') AND `diagnosis` IS NOT NULL AND `diagnosis` <> ''");
        $migration->addPostQuery("ALTER TABLE `glpi_plugin_hh_quotes` DROP COLUMN `diagnosis`");
    }

    $migration->executeMigration();

    // Create defaults only on first install; upgrades must preserve the admin's settings.
    if ($DB->tableExists('glpi_plugin_hh_workflow_config')) {
        $hasConfig = false;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_hh_workflow_config',
            'WHERE' => ['id' => 1],
            'LIMIT' => 1,
        ]) as $unused) {
            $hasConfig = true;
        }
        if (!$hasConfig) {
            $DB->insert('glpi_plugin_hh_workflow_config', [
                'id' => 1,
                'itilcategories_id' => 0,
                'requesttypes_id' => 0,
                'enabled' => 0,
            ]);
        }
    }

    // Register the native GLPI profile right for every existing profile.
    // Keep existing bitmasks unchanged; never grant HH access automatically.
    $rightName = 'plugin_hh';
    $profiles = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => Profile::getTable(),
    ]);

    foreach ($profiles as $profile) {
        $profilesId = (int) $profile['id'];
        $existingRights = ProfileRight::getProfileRights($profilesId, [$rightName]);

        if (!array_key_exists($rightName, $existingRights)) {
            $DB->insert(
                ProfileRight::getTable(),
                [
                    'profiles_id' => $profilesId,
                    'name'        => $rightName,
                ]
            );
        }
    }

    ProfileRight::cleanAllPossibleRights();

    return true;
}

function plugin_hh_upgrade($previousVersion): bool
{
    // Re-run idempotent schema migrations when GLPI upgrades this plugin.
    return plugin_hh_install();
}

function plugin_hh_uninstall(): bool
{
    global $DB;

    // Remove HH-specific profile permissions.
    ProfileRight::deleteProfileRights(['plugin_hh']);

    // Drop dependent/detail tables first, then their parent tables.
    // Uninstalling HH intentionally resets all plugin-owned data.
    // GLPI core tables and asset/ticket records are never dropped.
    $tables = [
        'glpi_plugin_hh_general_quotelines',
        'glpi_plugin_hh_general_quotehistory',
        'glpi_plugin_hh_general_quotes',
        'glpi_plugin_hh_pointmovements',
        'glpi_plugin_hh_quotedetails',
        'glpi_plugin_hh_asset_history',
        'glpi_plugin_hh_workflow_config',
        'glpi_plugin_hh_quotehistory',
        'glpi_plugin_hh_quotes',
        'glpi_plugin_hh_itemtypes',
        'glpi_plugin_hh_items',
        'glpi_plugin_hh_pointvalues',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            if (!$DB->doQuery("DROP TABLE `{$table}`")) {
                return false;
            }
        }
    }

    return true;
}
