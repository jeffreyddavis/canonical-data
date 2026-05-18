<?php
// Developed and tested on Vision demo site due to admin access issues on production

/**
 * Plugin Name: Hillen Canonical Data
 * Description: Canonical master data tables for parts consolidation.
 * Version: 0.1.0
 * Author: Jeff Davis
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HILLEN_CANONICAL_DATA_PLUGIN_DIR')) {
    define('HILLEN_CANONICAL_DATA_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

// Load importer functions
require_once HILLEN_CANONICAL_DATA_PLUGIN_DIR . 'importer/importer.php';

register_activation_hook(__FILE__, 'hillen_canonical_data_activate');

function hillen_canonical_data_activate() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $parts_table          = $wpdb->prefix . 'canonical_parts';
    $sources_table        = $wpdb->prefix . 'canonical_part_sources';
    $rows_table           = $wpdb->prefix . 'canonical_part_source_rows';
    $rollup_cache_table   = $wpdb->prefix . 'canonical_rollup_cache';
    $category_cache_table = $wpdb->prefix . 'canonical_category_cache';
    $classification_table = $wpdb->prefix . 'canonical_part_classification';
    $wheel_specs_table    = $wpdb->prefix . 'canonical_wheel_specs';
    $wheel_compat_table   = $wpdb->prefix . 'canonical_wheel_tire_compatibility';
    $filter_defs_table    = $wpdb->prefix . 'canonical_filter_definitions';
    $part_filters_table   = $wpdb->prefix . 'canonical_part_filters';
    $pdf_categories_table = $wpdb->prefix . 'canonical_part_pdf_categories';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql_parts = "
        CREATE TABLE $parts_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vision_part_number VARCHAR(191) NOT NULL,
            sku_norm VARCHAR(191) NULL,
            vision_name TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY vision_part_number (vision_part_number),
            KEY sku_norm (sku_norm)
        ) $charset_collate;
    ";

    $sql_sources = "
        CREATE TABLE $sources_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_part_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(50) NOT NULL COMMENT 'vision|woo|punchout|millennium',
            source_record_id VARCHAR(191) NOT NULL,
            notes TEXT NULL,
            PRIMARY KEY (id),
            KEY canonical_part_id (canonical_part_id),
            KEY source (source),
            UNIQUE KEY source_record_id (source_record_id)
        ) $charset_collate;
    ";

    $sql_rows = "
        CREATE TABLE $rows_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_part_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(50) NOT NULL,
            sku VARCHAR(191) NULL,
            sku_norm VARCHAR(191) NULL,
            source_record_id VARCHAR(191) NOT NULL,
            name TEXT NULL,
            price DECIMAL(12,2) NULL,
            weight DECIMAL(12,4) NULL,
            category1 VARCHAR(191) NULL,
            category2 VARCHAR(191) NULL,
            category3 VARCHAR(191) NULL,
            category4 VARCHAR(191) NULL,
            category5 VARCHAR(191) NULL,
            model VARCHAR(191) NULL,
            serial_start BIGINT NULL,
            serial_end BIGINT NULL,
            vision_key VARCHAR(191) NULL,
            row_hash CHAR(40) NOT NULL,
            raw_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY canonical_part_id (canonical_part_id),
            KEY source (source),
            KEY sku_norm (sku_norm),
            KEY source_record_id (source_record_id)
        ) $charset_collate;
    ";

    $sql_rollup_cache = "
        CREATE TABLE $rollup_cache_table (
            canonical_part_id BIGINT UNSIGNED NOT NULL,
            representative_name TEXT NULL,
            representative_weight DECIMAL(12,4) NULL,
            vision_price DECIMAL(12,2) NULL,
            woo_price DECIMAL(12,2) NULL,
            punchout_price DECIMAL(12,2) NULL,
            millennium_price DECIMAL(12,2) NULL,
            min_serial_start BIGINT NULL,
            max_serial_end BIGINT NULL,
            on_website TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (canonical_part_id)
        ) $charset_collate;
    ";

    $sql_category_cache = "
        CREATE TABLE $category_cache_table (
            canonical_part_id BIGINT UNSIGNED NOT NULL,
            category1 TEXT NULL,
            category2 TEXT NULL,
            category3 TEXT NULL,
            category4 TEXT NULL,
            category5 TEXT NULL,
            models TEXT NULL,
            PRIMARY KEY (canonical_part_id)
        ) $charset_collate;
    ";

    $sql_classification = "
        CREATE TABLE $classification_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_part_id BIGINT UNSIGNED NOT NULL,
            unspsc VARCHAR(32) NULL,
            hs_code VARCHAR(32) NULL,
            confidence VARCHAR(20) NULL,
            status VARCHAR(20) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY canonical_part_id (canonical_part_id)
        ) $charset_collate;
    ";

    $sql_wheel_specs = "
        CREATE TABLE $wheel_specs_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_part_id BIGINT UNSIGNED NOT NULL,
            source_record_id VARCHAR(191) NOT NULL,
            mfr_part_number VARCHAR(191) NOT NULL,
            millennium_part_number VARCHAR(191) NOT NULL,
            product VARCHAR(50) NOT NULL,
            size_label VARCHAR(191) NOT NULL,
            nominal_diameter DECIMAL(10,4) NULL,
            nominal_width DECIMAL(10,4) NULL,
            bore_diameter DECIMAL(10,4) NULL,
            compound VARCHAR(100) NOT NULL,
            wheel_type VARCHAR(191) NOT NULL,
            bearing_number VARCHAR(100) NULL,
            manufacturer VARCHAR(191) NOT NULL,
            price DECIMAL(12,2) NULL,
            pm_price DECIMAL(12,2) NULL,
            free_freight_qty DECIMAL(12,4) NULL,
            compatibility_key VARCHAR(191) NOT NULL,
            raw_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY canonical_part_id (canonical_part_id),
            KEY mfr_part_number (mfr_part_number),
            KEY millennium_part_number (millennium_part_number),
            KEY compatibility_key (compatibility_key),
            UNIQUE KEY source_record_id (source_record_id)
        ) $charset_collate;
    ";

    $sql_wheel_compat = "
        CREATE TABLE $wheel_compat_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            compatibility_key VARCHAR(191) NOT NULL,
            size_label VARCHAR(191) NOT NULL,
            nominal_diameter DECIMAL(10,4) NULL,
            nominal_width DECIMAL(10,4) NULL,
            bore_diameter DECIMAL(10,4) NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'millennium',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY compatibility_key (compatibility_key)
        ) $charset_collate;
    ";

    $sql_filter_defs = "
        CREATE TABLE $filter_defs_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_type VARCHAR(50) NOT NULL,
            filter_key VARCHAR(100) NOT NULL,
            label VARCHAR(191) NOT NULL,
            value_type VARCHAR(30) NOT NULL DEFAULT 'text',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_filter (product_type, filter_key)
        ) $charset_collate;
    ";

    $sql_part_filters = "
        CREATE TABLE $part_filters_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_part_id BIGINT UNSIGNED NULL,
            sku VARCHAR(191) NOT NULL,
            sku_norm VARCHAR(191) NOT NULL,
            product_type VARCHAR(50) NOT NULL,
            filter_key VARCHAR(100) NOT NULL,
            filter_value VARCHAR(191) NOT NULL,
            filter_value_norm VARCHAR(191) NOT NULL,
            numeric_value DECIMAL(12,4) NULL,
            source VARCHAR(50) NOT NULL,
            confidence VARCHAR(20) NOT NULL DEFAULT 'parsed',
            raw_value TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY canonical_part_id (canonical_part_id),
            KEY sku_norm (sku_norm),
            KEY product_type (product_type),
            KEY filter_key (filter_key),
            KEY filter_value_norm (filter_value_norm),
            UNIQUE KEY part_filter_value (sku_norm, product_type, filter_key, filter_value_norm)
        ) $charset_collate;
    ";

    $sql_pdf_categories = hillen_pdf_category_table_sql($pdf_categories_table, $charset_collate);

    dbDelta($sql_parts);
    dbDelta($sql_sources);
    dbDelta($sql_rows);
    dbDelta($sql_rollup_cache);
    dbDelta($sql_category_cache);
    dbDelta($sql_classification);
    dbDelta($sql_wheel_specs);
    dbDelta($sql_wheel_compat);
    dbDelta($sql_filter_defs);
    dbDelta($sql_part_filters);
    dbDelta($sql_pdf_categories);

    hillen_seed_filter_definitions($filter_defs_table);

    hillen_create_rollup_view_if_missing($rows_table);
}

function hillen_create_rollup_view_if_missing($rows_table) {
    global $wpdb;

    $rollup_view = $wpdb->prefix . 'canonical_part_source_rollup';
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SHOW FULL TABLES LIKE %s",
            $rollup_view
        ),
        ARRAY_N
    );

    if ($existing) {
        return;
    }

    $wpdb->query("
        CREATE VIEW $rollup_view AS
        SELECT
            canonical_part_id,
            source,
            MAX(name) AS representative_name,
            MAX(weight) AS representative_weight,
            MAX(price) AS representative_price,
            MIN(serial_start) AS min_serial_start,
            MAX(serial_end) AS max_serial_end
        FROM $rows_table
        GROUP BY canonical_part_id, source
    ");
}

function hillen_ensure_filter_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $filter_defs_table = $wpdb->prefix . 'canonical_filter_definitions';
    $part_filters_table = $wpdb->prefix . 'canonical_part_filters';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("
        CREATE TABLE $filter_defs_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_type VARCHAR(50) NOT NULL,
            filter_key VARCHAR(100) NOT NULL,
            label VARCHAR(191) NOT NULL,
            value_type VARCHAR(30) NOT NULL DEFAULT 'text',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_filter (product_type, filter_key)
        ) $charset_collate;
    ");

    dbDelta("
        CREATE TABLE $part_filters_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_part_id BIGINT UNSIGNED NULL,
            sku VARCHAR(191) NOT NULL,
            sku_norm VARCHAR(191) NOT NULL,
            product_type VARCHAR(50) NOT NULL,
            filter_key VARCHAR(100) NOT NULL,
            filter_value VARCHAR(191) NOT NULL,
            filter_value_norm VARCHAR(191) NOT NULL,
            numeric_value DECIMAL(12,4) NULL,
            source VARCHAR(50) NOT NULL,
            confidence VARCHAR(20) NOT NULL DEFAULT 'parsed',
            raw_value TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY canonical_part_id (canonical_part_id),
            KEY sku_norm (sku_norm),
            KEY product_type (product_type),
            KEY filter_key (filter_key),
            KEY filter_value_norm (filter_value_norm),
            UNIQUE KEY part_filter_value (sku_norm, product_type, filter_key, filter_value_norm)
        ) $charset_collate;
    ");

    hillen_seed_filter_definitions($filter_defs_table);
}

function hillen_pdf_category_table_sql($table, $charset_collate) {
    return "
        CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_part_id BIGINT UNSIGNED NOT NULL,
            source_row_id BIGINT UNSIGNED NULL,
            sku VARCHAR(191) NOT NULL,
            sku_norm VARCHAR(191) NOT NULL,
            category1 VARCHAR(191) NULL,
            category2 VARCHAR(191) NOT NULL,
            category3 VARCHAR(191) NULL,
            source_pdf VARCHAR(255) NULL,
            page INT NULL,
            inferred TINYINT(1) NOT NULL DEFAULT 0,
            inference_method VARCHAR(80) NULL,
            inference_confidence DECIMAL(6,4) NULL,
            priority INT NOT NULL DEFAULT 100,
            match_hash CHAR(40) NOT NULL,
            import_batch VARCHAR(80) NULL,
            raw_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_match_hash (match_hash),
            KEY canonical_part_id (canonical_part_id),
            KEY source_row_id (source_row_id),
            KEY sku_norm (sku_norm),
            KEY category1 (category1),
            KEY inferred (inferred),
            KEY import_batch (import_batch)
        ) $charset_collate;
    ";
}

function hillen_ensure_pdf_category_table() {
    global $wpdb;

    $table = $wpdb->prefix . 'canonical_part_pdf_categories';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta(hillen_pdf_category_table_sql($table, $charset_collate));
}

function hillen_seed_filter_definitions($filter_defs_table) {
    global $wpdb;

    $definitions = [
        ['wheel', 'compound', 'Compound', 'text', 10],
        ['wheel', 'outside_diameter', 'Outside Diameter', 'number', 20],
        ['wheel', 'tread_width', 'Tread Width', 'number', 30],
        ['wheel', 'inside_diameter', 'Inside Diameter', 'number', 40],
        ['wheel', 'size', 'Size', 'text', 50],
        ['wheel', 'manufacturer_part_number', 'Manufacturer Part Number', 'text', 60],
        ['wheel', 'manufacturer', 'Manufacturer', 'text', 70],
        ['wheel', 'bearing_part_number', 'Bearing Part Number', 'text', 80],
        ['wheel', 'has_bearings', 'Has Bearings', 'boolean', 90],
        ['wheel', 'preassembled', 'Preassembled', 'boolean', 100],
        ['wheel', 'free_shipping_threshold', 'Free Shipping Threshold', 'number', 110],
        ['wheel', 'free_shipping_qty_breakpoint', 'Free Shipping Quantity Breakpoint', 'number', 120],
        ['tire', 'compound', 'Compound', 'text', 10],
        ['tire', 'tread_treatment', 'Tread Treatment', 'text', 20],
        ['tire', 'outside_diameter', 'Outside Diameter', 'number', 30],
        ['tire', 'tread_width', 'Tread Width', 'number', 40],
        ['tire', 'inside_diameter', 'Inside Diameter', 'number', 50],
        ['tire', 'size', 'Size', 'text', 60],
        ['tire', 'manufacturer_part_number', 'Manufacturer Part Number', 'text', 70],
        ['tire', 'manufacturer', 'Manufacturer', 'text', 80],
    ];

    foreach ($definitions as $definition) {
        $wpdb->replace(
            $filter_defs_table,
            [
                'product_type' => $definition[0],
                'filter_key' => $definition[1],
                'label' => $definition[2],
                'value_type' => $definition[3],
                'sort_order' => $definition[4],
            ],
            ['%s', '%s', '%s', '%s', '%d']
        );
    }
}
