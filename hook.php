<?php
declare(strict_types=1);

use DBConnection;
use Migration;

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
            `valid_from` datetime NOT NULL,
            `valid_until` datetime DEFAULT NULL,
            `reason` varchar(255) DEFAULT NULL,
            `users_id` int unsigned NOT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hh_pointvalues_validity` (`valid_from`, `valid_until`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_hh_quotes' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_hh_quotes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(40) NOT NULL,
            `itemtype` varchar(100) DEFAULT NULL,
            `items_id` int unsigned DEFAULT NULL,
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
            `description_snapshot` varchar(255) NOT NULL,
            `quantity` decimal(10,2) NOT NULL DEFAULT 1,
            `points_each` decimal(12,2) NOT NULL DEFAULT 0,
            `points_total` decimal(12,2) NOT NULL DEFAULT 0,
            `point_value_snapshot` decimal(12,4) NOT NULL,
            `amount_snapshot` decimal(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_hh_quotedetails_quote` (`plugin_hh_quotes_id`)
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

    $migration->executeMigration();

    return true;
}

function plugin_hh_uninstall(): bool
{
    // Preserve business data on uninstall. Removal of HH tables must be a separate,
    // explicit and backed-up maintenance operation to prevent accidental data loss.
    return true;
}
