<?php
// TEMP: Vision importer for Phase 1 – Part 2

function hillen_import_vision_parts() {
    global $wpdb;

    $parts_table   = $wpdb->prefix . 'canonical_parts';
    $sources_table = $wpdb->prefix . 'canonical_part_sources';

    $json_path = WP_PLUGIN_DIR . '/parts-visualizer/parts.json';
    $raw = file_get_contents($json_path);

    if ($raw === false) {
        error_log('Vision import failed: parts.json not found');
        return;
    }

    $vision_parts = json_decode($raw, true);

    if (!is_array($vision_parts)) {
        error_log('Vision import failed: invalid JSON');
        return;
    }

    // Counters for summary
    $summary = [
        'total_rows_seen'        => 0,
        'canonical_parts_added'  => 0,
        'vision_sources_added'   => 0,
        'vision_sources_skipped' => 0,
        'rows_skipped_invalid'   => 0,
        'lastDuplicatePart' => '',
    ];

    $lastDuplicatePart = [];

    foreach ($vision_parts as $index => $part) {
        $summary['total_rows_seen']++;

        // Required identity
        if (empty($part['number']) || empty($part['name'])) {
            echo '<pre style="padding:20px;background:#fff;border:1px solid #ccc;">Invalid Row Skipped: ';
            print_r($part);
            echo '</pre>';

            $summary['rows_skipped_invalid']++;
            continue;
        }

        $vision_part_number = trim($part['number']);

        // Build idempotent Vision record key
        $vision_record_key = sprintf(
            'vision:%s|%s|%s|%s',
            $vision_part_number,
            trim($part['models'] ?? ''),
            trim($part['category'] ?? ''),
            trim($part['subcategory'] ?? '')
        );

        // Check if this Vision occurrence already exists
        $existing_source_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $sources_table
                 WHERE source = 'vision'
                   AND source_record_id = %s",
                $vision_record_key
            )
        );



        $rows_table = $wpdb->prefix . 'canonical_part_source_rows';

        $sku_norm = hillen_normalize_sku($vision_part_number);

        $data = [
            'name'         => trim($part['name']) ?: null,
            'price'        => trim($part['price']) ?: null,
            'weight'       => null,
            'category1'    => trim($part['make'] ?? '') ?: null,
            'category2'    => trim($part['category'] ?? '') ?: null,
            'category3'    => trim($part['subcategory'] ?? '') ?: null,
            'model'        => trim($part['models'] ?? '') ?: null,
            'serial_start' => isset($part['serial_start']) ? (int)$part['serial_start'] : null,
            'serial_end'   => isset($part['serial_end']) ? (int)$part['serial_end'] : null,
        ];

        $row_hash = hillen_row_hash($data);

        $result = $wpdb->insert(
            $rows_table,
            [
                'canonical_part_id' => $existing_source_id,
                'source'            => 'vision',
                'sku'               => $vision_part_number,
                'sku_norm'          => $sku_norm,
                'source_record_id'  => $vision_record_key,
                'name'              => $data['name'],
                'price'             => $data['price'],
                'weight'            => null,
                'category1'         => $data['category1'],
                'category2'         => $data['category2'],
                'category3'         => $data['category3'],
                'model'             => $data['model'],
                'serial_start'      => $data['serial_start'],
                'serial_end'        => $data['serial_end'],
                'vision_key'        => $vision_record_key,
                'row_hash'          => $row_hash,
                'raw_json'          => json_encode($part),
            ]
        );

        if ($result === false) {
            echo '<pre>INSERT ERROR:</pre>';
            echo '<pre>' . esc_html($wpdb->last_error) . '</pre>';
            echo '<pre>QUERY:</pre>';
            echo '<pre>' . esc_html($wpdb->last_query) . '</pre>';
            exit;
        }


        if ($existing_source_id) {
            $summary['lastDuplicatePart'] = $part;
            $summary['vision_sources_skipped']++;
            continue;
        }

        // Find or create canonical part (by part number only)
        $canonical_part_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $parts_table WHERE vision_part_number = %s",
                $vision_part_number
            )
        );

        if (!$canonical_part_id) {
            $wpdb->insert(
                $parts_table,
                [
                    'vision_part_number' => $vision_part_number,
                    'vision_name'        => $part['name'],
                ],
                ['%s', '%s']
            );

            $canonical_part_id = $wpdb->insert_id;
            $summary['canonical_parts_added']++;
        }

        // Record Vision source occurrence
        $wpdb->insert(
            $sources_table,
            [
                'canonical_part_id' => $canonical_part_id,
                'source'            => 'vision',
                'source_record_id'  => $vision_record_key,
                'notes'             => $part['diagram'] ?? null,
            ],
            ['%d', '%s', '%s', '%s']
        );

        $summary['vision_sources_added']++;
    }

    return $summary;
}

add_action('admin_init', 'hillen_maybe_run_vision_import');
add_action('admin_init', 'hillen_maybe_run_vision_import_woo');
add_action('admin_init', 'hillen_maybe_run_punchout_import');
add_action('admin_init', 'hillen_maybe_run_millennium_wheel_import');
add_action('admin_init', 'hillen_maybe_run_filter_backfill');
add_action('admin_init', 'hillen_maybe_run_pdf_category_import');
add_action('admin_init', 'hillen_export_pdf_category_dataset');
add_action('admin_init', 'hillen_export_master_dataset_with_filters');
add_action('admin_init', 'hillen_export_master_dataset');
add_action('admin_init', 'hillen_rebuild_caches');

function hillen_maybe_run_vision_import() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_run_vision_import'])) {
        return;
    }

    // Run importer
    $summary = hillen_import_vision_parts();

    // Output results
    echo '<pre style="padding:20px;background:#fff;border:1px solid #ccc;">';
    echo "Vision Import Summary\n";
    echo "----------------------\n";

    if (is_array($summary)) {
        foreach ($summary as $key => $value) {
            echo sprintf("%s: %s\n", $key, $value);
        }
        echo "Last Duplicate part: ";
        print_r($summary['lastDuplicatePart']);
    } else {
        echo "Importer did not return a summary.\n";
    }

    echo '</pre>';

    exit;
}

function hillen_maybe_run_vision_import_woo() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_run_woo_import'])) {
        return;
    }

    // Run importer
    $summary = hillen_import_woo_products_from_csv(WP_PLUGIN_DIR . '/parts-visualizer/woo.csv');

    // Output results
    echo '<pre style="padding:20px;background:#fff;border:1px solid #ccc;">';
    echo "Vision Woo Import Summary\n";
    echo "----------------------\n";

    if (is_array($summary)) {
        foreach ($summary as $key => $value) {
            echo sprintf("%s: %s\n", $key, $value);
        }
    } else {
        echo "Importer did not return a summary.\n";
    }

    echo '</pre>';

    exit;
}


function hillen_maybe_run_punchout_import() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_run_punchout_import'])) {
        return;
    }

    // Run importer
    $summary = hillen_import_punchout_parts();

    // Output results
    echo '<pre style="padding:20px;background:#fff;border:1px solid #ccc;">';
    echo "Vision Punchout Import Summary\n";
    echo "----------------------\n";

    if (is_array($summary)) {
        foreach ($summary as $key => $value) {
            echo sprintf("%s: %s\n", $key, $value);
        }
    } else {
        echo "Importer did not return a summary.\n";
    }

    echo '</pre>';

    exit;
}

function hillen_maybe_run_millennium_wheel_import() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_run_millennium_wheel_import'])) {
        return;
    }

    $summary = hillen_import_millennium_wheels(
        WP_PLUGIN_DIR . '/canonical-data/MILLENNIUM - WHEELS - PRICES - 09-11-2025(1).xlsx'
    );

    echo '<pre style="padding:20px;background:#fff;border:1px solid #ccc;">';
    echo "Millennium Wheel Import Summary\n";
    echo "-------------------------------\n";

    if (is_array($summary)) {
        foreach ($summary as $key => $value) {
            echo sprintf("%s: %s\n", $key, is_scalar($value) ? $value : json_encode($value));
        }
    } else {
        echo "Importer did not return a summary.\n";
    }

    echo '</pre>';
    exit;
}

function hillen_maybe_run_filter_backfill() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_run_filter_backfill'])) {
        return;
    }

    $summary = hillen_backfill_product_filters_from_export(
        WP_PLUGIN_DIR . '/canonical-data/master_parts_export(51).csv',
        WP_PLUGIN_DIR . '/canonical-data/MILLENNIUM - WHEELS - PRICES - 09-11-2025(1).xlsx'
    );

    echo '<pre style="padding:20px;background:#fff;border:1px solid #ccc;">';
    echo "Product Filter Backfill Summary\n";
    echo "-------------------------------\n";

    if (is_array($summary)) {
        foreach ($summary as $key => $value) {
            echo sprintf("%s: %s\n", $key, is_scalar($value) ? $value : json_encode($value));
        }
    } else {
        echo "Importer did not return a summary.\n";
    }

    echo '</pre>';
    exit;
}



function hillen_import_woo_products_from_csv($csv_path) {
    global $wpdb;

    $parts_table   = $wpdb->prefix . 'canonical_parts';
    $sources_table = $wpdb->prefix . 'canonical_part_sources';
    $rows_table    = $wpdb->prefix . 'canonical_part_source_rows';

    if (!file_exists($csv_path)) {
        return ['error' => 'Woo CSV not found'];
    }

    $handle = fopen($csv_path, 'r');
    if (!$handle) {
        return ['error' => 'Unable to open Woo CSV'];
    }

    $headers = fgetcsv($handle);
    $headers = array_map(function ($h) {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        return trim($h);
    }, $headers);

    $col = array_flip($headers);

    $summary = [
        'rows_seen'                 => 0,
        'canonical_created_from_woo'=> 0,
        'woo_sources_added'         => 0,
        'raw_rows_inserted'         => 0,
        'rows_skipped_invalid'      => 0,
    ];

    set_time_limit(0);
    $wpdb->show_errors();

    while (($row = fgetcsv($handle)) !== false) {
        $summary['rows_seen']++;

        $sku_raw = trim($row[$col['SKU']] ?? '');
        $id      = trim($row[$col['ID']] ?? '');

        if ($sku_raw === '' || $id === '') {
            $summary['rows_skipped_invalid']++;
            continue;
        }

        $sku_norm = hillen_normalize_sku($sku_raw);

        // Lookup canonical by normalized SKU
        $canonical_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $parts_table WHERE sku_norm = %s",
                $sku_norm
            )
        );

        if (!$canonical_id) {
            $name_raw = trim($row[$col['Name']] ?? '') ?: $sku_raw;

            $wpdb->insert(
                $parts_table,
                [
                    'vision_part_number' => $sku_raw,
                    'sku_norm'           => $sku_norm,
                    'vision_name'        => $name_raw,
                ],
                ['%s','%s','%s']
            );

            $canonical_id = $wpdb->insert_id;
            $summary['canonical_created_from_woo']++;
        }

        // Deterministic Woo record key
        $woo_record_key = 'woo:' . $id;

        // Attach source if not exists
        $exists_source = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $sources_table
                 WHERE source='woo'
                 AND source_record_id=%s",
                $woo_record_key
            )
        );

        if (!$exists_source) {
            $wpdb->insert(
                $sources_table,
                [
                    'canonical_part_id' => $canonical_id,
                    'source'            => 'woo',
                    'source_record_id'  => $woo_record_key,
                    'notes'             => null,
                ],
                ['%d','%s','%s','%s']
            );

            $summary['woo_sources_added']++;
        }

        // Prepare raw row fields
        $name = trim($row[$col['Name']] ?? '') ?: null;

        $price = isset($col['Regular price']) && trim($row[$col['Regular price']]) !== ''
            ? number_format((float)$row[$col['Regular price']], 2, '.', '')
            : null;

        $weight = isset($col['Weight (lbs)']) && trim($row[$col['Weight (lbs)']]) !== ''
            ? (float)$row[$col['Weight (lbs)']]
            : null;

        $serial_start = isset($col['Meta: Start Serial']) && trim($row[$col['Meta: Start Serial']]) !== ''
            ? (int)$row[$col['Meta: Start Serial']]
            : null;

        $serial_end = isset($col['Meta: End Serial']) && trim($row[$col['Meta: End Serial']]) !== ''
            ? (int)$row[$col['Meta: End Serial']]
            : null;

        // Prepare raw row fields
        $category = trim($row[$col['Categories']] ?? '') ?: null;            

        $row_data = [
            'name' => $name,
            'price' => $price,
            'weight' => $weight,
            'serial_start' => $serial_start,
            'serial_end' => $serial_end,
        ];

        $row_hash = hillen_row_hash($row_data);

        // Idempotent raw insert
        $exists_raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $rows_table
                 WHERE source='woo'
                 AND source_record_id=%s",
                $woo_record_key
            )
        );

        if (!$exists_raw) {
            $result = $wpdb->insert(
                $rows_table,
                [
                    'canonical_part_id' => $canonical_id,
                    'source'            => 'woo',
                    'sku'               => $sku_raw,
                    'sku_norm'          => $sku_norm,
                    'source_record_id'  => $woo_record_key,
                    'name'              => $name,
                    'price'             => $price,
                    'weight'            => $weight,
                    'category1'         => $category,
                    'category2'         => null,
                    'category3'         => null,
                    'model'             => null,
                    'serial_start'      => $serial_start,
                    'serial_end'        => $serial_end,
                    'vision_key'        => null,
                    'row_hash'          => $row_hash,
                    'raw_json'          => json_encode($row),
                ]
            );

            if ($result === false) {
                echo '<pre>Woo INSERT ERROR:</pre>';
                echo '<pre>' . esc_html($wpdb->last_error) . '</pre>';
                exit;
            }

            $summary['raw_rows_inserted']++;
        }
    }

    fclose($handle);

    return $summary;
}


function hillen_import_punchout_parts() {

    if (get_transient('hillen_punch_import_running')) {
        return ['error' => 'Import already running'];
    }


    set_transient('hillen_punch_import_running', 1, 60 * 30);

    global $wpdb;

    $parts_table   = $wpdb->prefix . 'canonical_parts';
    $sources_table = $wpdb->prefix . 'canonical_part_sources';
    $rows_table    = $wpdb->prefix . 'canonical_part_source_rows';

    $csv_path = WP_PLUGIN_DIR . '/parts-visualizer/punchout.csv';

    if (!file_exists($csv_path)) {
        return ['error' => 'PunchOut CSV not found'];
    }

    $handle = fopen($csv_path, 'r');
    if (!$handle) {
        return ['error' => 'Unable to open PunchOut CSV'];
    }

    $headers = fgetcsv($handle);
    $headers = array_map(function ($h) {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        return trim($h);
    }, $headers);

    $idx = array_flip($headers);

    $stats = [
        'rows_seen' => 0,
        'canonical_created_from_punchout' => 0,
        'punchout_sources_added' => 0,
        'raw_rows_inserted' => 0,
        'rows_skipped_invalid' => 0,
    ];

    set_time_limit(0);
    $wpdb->show_errors();

    while (($row = fgetcsv($handle)) !== false) {
        $stats['rows_seen']++;

        $sku_raw = trim($row[$idx['Name']] ?? '');
        if ($sku_raw === '') {
            $stats['rows_skipped_invalid']++;
            continue;
        }

        // Normalize SKU
        $sku_norm = hillen_normalize_sku($sku_raw);

        // Lookup canonical
        $canonical_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $parts_table WHERE sku_norm = %s",
                $sku_norm
            )
        );

        if (!$canonical_id) {
            $name_raw = trim($row[$idx['Description']] ?? $sku_raw);

            $wpdb->insert(
                $parts_table,
                [
                    'vision_part_number' => $sku_raw,
                    'sku_norm'           => $sku_norm,
                    'vision_name'        => $name_raw ?: $sku_raw,
                ],
                ['%s','%s','%s']
            );

            $canonical_id = $wpdb->insert_id;
            $stats['canonical_created_from_punchout']++;
        }

        // Generate deterministic source_record_id
        $row_hash = sha1(json_encode($row));
        $punch_record_key = 'punch:' . $row_hash;

        // Insert source mapping (optional skip if exists)
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $sources_table
                 WHERE source = 'punchout'
                 AND source_record_id = %s",
                $punch_record_key
            )
        );

        if (!$exists) {
            $wpdb->insert(
                $sources_table,
                [
                    'canonical_part_id' => $canonical_id,
                    'source'            => 'punchout',
                    'source_record_id'  => $punch_record_key,
                    'notes'             => null,
                ],
                ['%d','%s','%s','%s']
            );

            $stats['punchout_sources_added']++;
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $rows_table
                WHERE source = 'punchout'
                AND source_record_id = %s",
                $punch_record_key
            )
        );

        if ($exists) {
            continue;
        }


        // Clean data
        $name   = trim($row[$idx['Description']] ?? '') ?: null;
        $price  = isset($idx['Regular price']) && trim($row[$idx['Regular price']]) !== ''
                    ? number_format((float)$row[$idx['Regular price']], 2, '.', '')
                    : null;
        $weight = isset($idx['Weight (lbs)']) && trim($row[$idx['Weight (lbs)']]) !== ''
                    ? (float)$row[$idx['Weight (lbs)']]
                    : null;

        $category   = trim($row[$idx['Categories']] ?? '') ?: null;
        $subcategory   = trim($row[$idx['Subcategory']] ?? '') ?: null;

        $row_data = [
            'name' => $name,
            'price' => $price,
            'weight' => $weight,
            'category' => $category
        ];

        $computed_hash = hillen_row_hash($row_data);

        $result = $wpdb->insert(
            $rows_table,
            [
                'canonical_part_id' => $canonical_id,
                'source'            => 'punchout',
                'sku'               => $sku_raw,
                'sku_norm'          => $sku_norm,
                'source_record_id'  => $punch_record_key,
                'name'              => $name,
                'price'             => $price,
                'weight'            => $weight,
                'category1'         => $category,
                'category2'         => $subcategory,
                'category3'         => null,
                'model'             => null,
                'serial_start'      => null,
                'serial_end'        => null,
                'vision_key'        => null,
                'row_hash'          => $computed_hash,
                'raw_json'          => json_encode($row),
            ]
        );

        if ($result === false) {
            echo '<pre>INSERT ERROR:</pre>';
            echo '<pre>' . esc_html($wpdb->last_error) . '</pre>';
            delete_transient('hillen_punch_import_running');

            exit;
        }

        $stats['raw_rows_inserted']++;
    }

    fclose($handle);
    delete_transient('hillen_punch_import_running');

    return $stats;
}

function hillen_import_millennium_wheels($xlsx_path) {
    global $wpdb;

    if (function_exists('hillen_canonical_data_activate')) {
        hillen_canonical_data_activate();
    }

    if (!file_exists($xlsx_path)) {
        return ['error' => 'Millennium wheel XLSX not found'];
    }

    $rows = hillen_read_xlsx_first_sheet($xlsx_path);
    if (empty($rows)) {
        return ['error' => 'Millennium wheel XLSX has no readable rows'];
    }

    $headers = array_map('hillen_normalize_header', array_shift($rows));
    $idx = array_flip($headers);

    $required = [
        'product',
        'size',
        'mfr_part',
        'millennium_part',
        'compound',
        'wheel_type',
        'bearing',
        'price',
        'pm_price',
        'manufacturer',
        'free_freight_qty',
    ];

    foreach ($required as $header) {
        if (!isset($idx[$header])) {
            return ['error' => 'Missing required Millennium column: ' . $header];
        }
    }

    $parts_table        = $wpdb->prefix . 'canonical_parts';
    $sources_table      = $wpdb->prefix . 'canonical_part_sources';
    $rows_table         = $wpdb->prefix . 'canonical_part_source_rows';
    $wheel_specs_table  = $wpdb->prefix . 'canonical_wheel_specs';
    $wheel_compat_table = $wpdb->prefix . 'canonical_wheel_tire_compatibility';

    $summary = [
        'rows_seen' => 0,
        'canonical_created_from_millennium' => 0,
        'millennium_sources_added' => 0,
        'source_rows_inserted' => 0,
        'wheel_specs_upserted' => 0,
        'compatibility_keys_upserted' => 0,
        'rows_skipped_invalid' => 0,
    ];

    set_time_limit(0);
    $wpdb->show_errors();

    foreach ($rows as $row) {
        $summary['rows_seen']++;

        $product = hillen_cell($row, $idx['product']);
        $size = hillen_cell($row, $idx['size']);
        $mfr_part = hillen_cell($row, $idx['mfr_part']);
        $millennium_part = hillen_cell($row, $idx['millennium_part']);
        $compound = hillen_cell($row, $idx['compound']);
        $wheel_type = hillen_cell($row, $idx['wheel_type']);
        $bearing = hillen_cell($row, $idx['bearing']);
        $manufacturer = hillen_normalize_manufacturer(hillen_cell($row, $idx['manufacturer']));
        $price = hillen_decimal_or_null(hillen_cell($row, $idx['price']));
        $pm_price = hillen_decimal_or_null(hillen_cell($row, $idx['pm_price']));
        $free_freight_qty = hillen_decimal_or_null(hillen_cell($row, $idx['free_freight_qty']), 4);

        if ($product === '' || $size === '' || $mfr_part === '' || $millennium_part === '' || $compound === '') {
            $summary['rows_skipped_invalid']++;
            continue;
        }

        $size_parts = hillen_parse_wheel_size($size);
        $compatibility_key = hillen_wheel_compatibility_key($size_parts);
        $sku_norm = hillen_normalize_sku($mfr_part);

        $canonical_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $parts_table WHERE sku_norm = %s OR vision_part_number = %s LIMIT 1",
                $sku_norm,
                $mfr_part
            )
        );

        if (!$canonical_id) {
            $wpdb->insert(
                $parts_table,
                [
                    'vision_part_number' => $mfr_part,
                    'sku_norm'           => $sku_norm,
                    'vision_name'        => hillen_millennium_wheel_name($manufacturer, $size, $compound, $wheel_type, $bearing),
                ],
                ['%s', '%s', '%s']
            );

            $canonical_id = $wpdb->insert_id;
            $summary['canonical_created_from_millennium']++;
        }

        $raw_assoc = [
            'PRODUCT' => $product,
            'SIZE' => $size,
            'MFR PART #' => $mfr_part,
            'MILLENNIUM PART #' => $millennium_part,
            'COMPOUND' => $compound,
            'WHEEL TYPE' => $wheel_type,
            'BEARING #' => $bearing,
            'PRICE' => $price,
            'PM PRICE' => $pm_price,
            'MANUFACTURER' => $manufacturer,
            'FREE FREIGHT QTY' => $free_freight_qty,
        ];

        $source_record_id = 'millennium:' . sha1(implode('|', [
            $mfr_part,
            $millennium_part,
            $size,
            $compound,
            $wheel_type,
            $bearing,
            $manufacturer,
            number_format((float) $price, 2, '.', ''),
            number_format((float) $pm_price, 2, '.', ''),
        ]));

        $source_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $sources_table WHERE source = 'millennium' AND source_record_id = %s",
                $source_record_id
            )
        );

        if (!$source_exists) {
            $wpdb->insert(
                $sources_table,
                [
                    'canonical_part_id' => $canonical_id,
                    'source' => 'millennium',
                    'source_record_id' => $source_record_id,
                    'notes' => 'Millennium wheel part: ' . $millennium_part,
                ],
                ['%d', '%s', '%s', '%s']
            );

            $summary['millennium_sources_added']++;
        }

        $row_data = [
            'name' => hillen_millennium_wheel_name($manufacturer, $size, $compound, $wheel_type, $bearing),
            'price' => $pm_price,
            'category1' => $manufacturer,
            'category2' => $product,
            'category3' => $compound,
            'category4' => $wheel_type,
            'category5' => $bearing,
            'model' => $size,
        ];

        $row_hash = hillen_row_hash($row_data);

        $raw_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $rows_table WHERE source = 'millennium' AND source_record_id = %s",
                $source_record_id
            )
        );

        if (!$raw_exists) {
            $wpdb->insert(
                $rows_table,
                [
                    'canonical_part_id' => $canonical_id,
                    'source' => 'millennium',
                    'sku' => $mfr_part,
                    'sku_norm' => $sku_norm,
                    'source_record_id' => $source_record_id,
                    'name' => $row_data['name'],
                    'price' => $pm_price,
                    'weight' => null,
                    'category1' => $manufacturer,
                    'category2' => $product,
                    'category3' => $compound,
                    'category4' => $wheel_type,
                    'category5' => $bearing ?: null,
                    'model' => $size,
                    'serial_start' => null,
                    'serial_end' => null,
                    'vision_key' => null,
                    'row_hash' => $row_hash,
                    'raw_json' => json_encode($raw_assoc),
                ]
            );

            $summary['source_rows_inserted']++;
        }

        $wpdb->replace(
            $wheel_specs_table,
            [
                'canonical_part_id' => $canonical_id,
                'source_record_id' => $source_record_id,
                'mfr_part_number' => $mfr_part,
                'millennium_part_number' => $millennium_part,
                'product' => $product,
                'size_label' => $size,
                'nominal_diameter' => $size_parts['diameter'],
                'nominal_width' => $size_parts['width'],
                'bore_diameter' => $size_parts['bore'],
                'compound' => $compound,
                'wheel_type' => $wheel_type,
                'bearing_number' => $bearing ?: null,
                'manufacturer' => $manufacturer,
                'price' => $price,
                'pm_price' => $pm_price,
                'free_freight_qty' => $free_freight_qty,
                'compatibility_key' => $compatibility_key,
                'raw_json' => json_encode($raw_assoc),
            ]
        );
        $summary['wheel_specs_upserted']++;

        $compat_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $wheel_compat_table WHERE compatibility_key = %s",
                $compatibility_key
            )
        );

        if (!$compat_exists) {
            $wpdb->insert(
                $wheel_compat_table,
                [
                    'compatibility_key' => $compatibility_key,
                    'size_label' => $size,
                    'nominal_diameter' => $size_parts['diameter'],
                    'nominal_width' => $size_parts['width'],
                    'bore_diameter' => $size_parts['bore'],
                    'source' => 'millennium',
                    'notes' => 'Attach tire data by matching wheel diameter, width, and bore where applicable.',
                ],
                ['%s', '%s', '%f', '%f', '%f', '%s', '%s']
            );
            $summary['compatibility_keys_upserted']++;
        }
    }

    return $summary;
}

function hillen_backfill_product_filters_from_export($csv_path, $xlsx_path = null) {
    global $wpdb;

    if (function_exists('hillen_ensure_filter_tables')) {
        hillen_ensure_filter_tables();
    }

    if (!file_exists($csv_path)) {
        return ['error' => 'Master export CSV not found'];
    }

    $handle = fopen($csv_path, 'r');
    if (!$handle) {
        return ['error' => 'Unable to open master export CSV'];
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return ['error' => 'Master export CSV is empty'];
    }

    $headers = array_map(function ($header) {
        return trim(preg_replace('/^\xEF\xBB\xBF/', '', $header));
    }, $headers);

    $idx = array_flip($headers);
    $required = ['SKU/Part Number', 'Description', 'Category 1', 'Category 4', 'Category 5'];
    foreach ($required as $header) {
        if (!isset($idx[$header])) {
            fclose($handle);
            return ['error' => 'Missing required export column: ' . $header];
        }
    }

    $part_filters_table = $wpdb->prefix . 'canonical_part_filters';
    $parts_table = $wpdb->prefix . 'canonical_parts';
    $millennium_lookup = $xlsx_path && file_exists($xlsx_path)
        ? hillen_build_millennium_filter_lookup($xlsx_path)
        : [];

    $wpdb->query("TRUNCATE TABLE $part_filters_table");

    $summary = [
        'rows_seen' => 0,
        'wheel_rows_detected' => 0,
        'tire_rows_detected' => 0,
        'rows_with_millennium_enrichment' => 0,
        'filter_values_inserted' => 0,
        'rows_without_filters' => 0,
    ];

    set_time_limit(0);
    $wpdb->show_errors();

    while (($row = fgetcsv($handle)) !== false) {
        $summary['rows_seen']++;
        $record = [];
        foreach ($headers as $i => $header) {
            $record[$header] = trim((string) ($row[$i] ?? ''));
        }

        $sku = $record['SKU/Part Number'];
        if ($sku === '') {
            continue;
        }

        $sku_norm = hillen_normalize_sku($sku);
        $product_type = hillen_detect_filter_product_type($record);
        $millennium = hillen_lookup_millennium_filter_row($sku, $millennium_lookup);

        if (!$product_type && $millennium) {
            $product_type = 'wheel';
        }

        if (!$product_type) {
            continue;
        }

        if ($product_type === 'wheel') {
            $summary['wheel_rows_detected']++;
        } elseif ($product_type === 'tire') {
            $summary['tire_rows_detected']++;
        }

        if ($millennium) {
            $summary['rows_with_millennium_enrichment']++;
        }

        $canonical_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $parts_table WHERE sku_norm = %s OR vision_part_number = %s LIMIT 1",
                $sku_norm,
                $sku
            )
        );

        $filters = hillen_extract_product_filters($record, $product_type, $millennium);

        if (empty($filters)) {
            $summary['rows_without_filters']++;
            continue;
        }

        foreach ($filters as $filter) {
            $inserted = hillen_insert_part_filter(
                $part_filters_table,
                $canonical_id ? (int) $canonical_id : null,
                $sku,
                $sku_norm,
                $product_type,
                $filter['key'],
                $filter['value'],
                $filter['numeric'] ?? null,
                $filter['source'] ?? 'parsed',
                $filter['confidence'] ?? 'parsed',
                $filter['raw'] ?? null
            );

            if ($inserted) {
                $summary['filter_values_inserted']++;
            }
        }
    }

    fclose($handle);

    return $summary;
}

function hillen_build_millennium_filter_lookup($xlsx_path) {
    $rows = hillen_read_xlsx_first_sheet($xlsx_path);
    if (empty($rows)) {
        return [];
    }

    $headers = array_map('hillen_normalize_header', array_shift($rows));
    $idx = array_flip($headers);
    $lookup = [];

    foreach ($rows as $row) {
        $mfr_part = hillen_cell($row, $idx['mfr_part'] ?? -1);
        $compound = hillen_cell($row, $idx['compound'] ?? -1);
        if ($mfr_part === '' || $compound === '') {
            continue;
        }

        $size = hillen_cell($row, $idx['size'] ?? -1);
        $wheel_type = hillen_cell($row, $idx['wheel_type'] ?? -1);
        $bearing = hillen_cell($row, $idx['bearing'] ?? -1);
        $size_parts = hillen_parse_wheel_size($size);

        $is_assembled = stripos($wheel_type, 'assembled') !== false;
        $entry = [
            'mfr_part_number' => $mfr_part,
            'millennium_part_number' => hillen_cell($row, $idx['millennium_part'] ?? -1),
            'compound' => $compound,
            'manufacturer' => hillen_normalize_manufacturer(hillen_cell($row, $idx['manufacturer'] ?? -1)),
            'size' => $size,
            'outside_diameter' => $size_parts['diameter'],
            'tread_width' => $size_parts['width'],
            'inside_diameter' => $size_parts['bore'],
            'wheel_type' => $wheel_type,
            'bearing_part_number' => $bearing,
            'has_bearings' => $is_assembled && $bearing !== '' ? 'Yes' : 'No',
            'preassembled' => $is_assembled ? 'Yes' : 'No',
            'assembly_state' => $is_assembled ? 'assembled' : 'wheel_only',
        ];

        foreach (hillen_millennium_sku_lookup_keys($mfr_part, $compound, $entry['assembly_state']) as $key) {
            $lookup[$key] = $entry;
        }
    }

    return $lookup;
}

function hillen_millennium_sku_lookup_keys($mfr_part, $compound, $assembly_state = null) {
    $compound = trim((string) $compound);
    $base_keys = [
        hillen_normalize_sku($mfr_part . '-' . $compound),
        hillen_normalize_sku($mfr_part . $compound),
    ];

    if (!$assembly_state) {
        return array_unique(array_filter($base_keys));
    }

    return array_map(
        fn($key) => $key . '|' . $assembly_state,
        array_unique(array_filter($base_keys))
    );
}

function hillen_lookup_millennium_filter_row($sku, $lookup) {
    $compound = hillen_extract_compound($sku);
    $base_sku = hillen_base_wheel_sku_without_assembly($sku);
    $assembly_state = hillen_sku_is_assembled_wheel($sku) ? 'assembled' : 'wheel_only';

    $candidates = [];
    if ($compound && $base_sku) {
        foreach (hillen_millennium_sku_lookup_keys($base_sku, $compound, $assembly_state) as $key) {
            $candidates[] = $key;
        }
    }

    $sku_norm = hillen_normalize_sku($sku);
    $candidates[] = $sku_norm . '|' . $assembly_state;
    $candidates[] = $sku_norm;

    foreach (array_unique(array_filter($candidates)) as $candidate) {
        if (isset($lookup[$candidate])) {
            return $lookup[$candidate];
        }
    }

    return null;
}

function hillen_base_wheel_sku_without_assembly($sku) {
    $sku = trim((string) $sku);
    $sku = preg_replace('/(?:-)?(?:FALCONIUM|HYLOAD|HL3|HL4|XD59)$/i', '', $sku);
    $sku = preg_replace('/A$/i', '', $sku);

    return trim($sku, '- ');
}

function hillen_sku_is_assembled_wheel($sku) {
    $base = trim((string) $sku);
    $base = preg_replace('/(?:-)?(?:FALCONIUM|HYLOAD|HL3|HL4|XD59)$/i', '', $base);

    return (bool) preg_match('/A$/i', $base);
}

function hillen_detect_filter_product_type($record) {
    $description = strtoupper($record['Description'] ?? '');
    $category4 = strtoupper($record['Category 4'] ?? '');
    $category5 = strtoupper($record['Category 5'] ?? '');
    $sku = strtoupper($record['SKU/Part Number'] ?? '');

    $wheelish = $category4 === 'WHEELS'
        || preg_match('/(^|[^A-Z])WHEEL([^A-Z]|$)/', $description)
        || preg_match('/(FALCONIUM|HYLOAD|HL3|HL4|XD59)/', $description . ' ' . $sku);

    $tireish = $category4 === 'TIRES'
        || preg_match('/(^|[^A-Z])TIRE([^A-Z]|$)/', $description)
        || preg_match('/(^|[^A-Z])TYRE([^A-Z]|$)/', $description);

    if ($wheelish && !$tireish) {
        return 'wheel';
    }

    if ($tireish && !$wheelish) {
        return 'tire';
    }

    if ($category5 === 'WHEELS AND TIRES') {
        if (preg_match('/(^|[^A-Z])WHEEL([^A-Z]|$)/', $description)) {
            return 'wheel';
        }

        if (preg_match('/(^|[^A-Z])TIRE([^A-Z]|$)/', $description)) {
            return 'tire';
        }
    }

    return null;
}

function hillen_extract_product_filters($record, $product_type, $millennium = null) {
    $filters = [];
    $sku = $record['SKU/Part Number'] ?? '';
    $description = $record['Description'] ?? '';
    $manufacturer = hillen_normalize_manufacturer($record['Category 1'] ?? '');

    hillen_add_filter($filters, 'manufacturer_part_number', $millennium['mfr_part_number'] ?? $sku, null, 'export', 'exact', $sku);
    hillen_add_filter($filters, 'manufacturer', $millennium['manufacturer'] ?? $manufacturer, null, $millennium ? 'millennium' : 'export', 'exact', $manufacturer);

    $compound = $millennium['compound'] ?? hillen_extract_compound($sku . ' ' . $description);
    hillen_add_filter($filters, 'compound', $compound, null, $millennium ? 'millennium' : 'parsed', $compound ? 'parsed' : 'missing', $description);

    $size = $millennium ? [
        'display' => $millennium['size'],
        'outside_diameter' => $millennium['outside_diameter'],
        'tread_width' => $millennium['tread_width'],
        'inside_diameter' => $millennium['inside_diameter'],
    ] : hillen_extract_size_filters($description);

    if ($size) {
        hillen_add_filter($filters, 'size', $size['display'], null, $millennium ? 'millennium' : 'parsed', 'parsed', $description);
        hillen_add_filter($filters, 'outside_diameter', $size['outside_diameter'], $size['outside_diameter'], $millennium ? 'millennium' : 'parsed', 'parsed', $description);
        hillen_add_filter($filters, 'tread_width', $size['tread_width'], $size['tread_width'], $millennium ? 'millennium' : 'parsed', 'parsed', $description);
        hillen_add_filter($filters, 'inside_diameter', $size['inside_diameter'], $size['inside_diameter'], $millennium ? 'millennium' : 'parsed', 'parsed', $description);
    }

    if ($product_type === 'wheel') {
        $bearing = $millennium['bearing_part_number'] ?? hillen_extract_bearing_part_number($description);
        $assembled_by_sku = hillen_sku_is_assembled_wheel($sku);
        $has_bearings = $assembled_by_sku || ($millennium && ($millennium['has_bearings'] ?? 'No') === 'Yes') || (bool) $bearing;

        hillen_add_filter($filters, 'bearing_part_number', $bearing, null, $millennium ? 'millennium' : 'parsed', 'parsed', $description);
        hillen_add_filter($filters, 'has_bearings', $has_bearings ? 'Yes' : 'No', null, $millennium ? 'millennium' : 'parsed', 'parsed', $description);
        hillen_add_filter($filters, 'preassembled', $assembled_by_sku ? 'Yes' : 'No', null, $assembled_by_sku ? 'sku_rule' : 'parsed', 'parsed', $sku);

        $threshold = 1500;
        $wheel_price = hillen_filter_price_for_breakpoint($record);
        hillen_add_filter($filters, 'free_shipping_threshold', (string) $threshold, $threshold, 'business_rule', 'exact', 'Millennium free freight at $1500');
        if ($wheel_price > 0) {
            $breakpoint = (int) ceil($threshold / $wheel_price);
            hillen_add_filter($filters, 'free_shipping_qty_breakpoint', (string) $breakpoint, $breakpoint, 'calculated', 'calculated', 'ceil(1500 / wheel price)');
        }
    }

    if ($product_type === 'tire') {
        $tread_treatment = hillen_extract_tread_treatment($description);
        hillen_add_filter($filters, 'tread_treatment', $tread_treatment, null, 'parsed', $tread_treatment ? 'parsed' : 'missing', $description);
    }

    return $filters;
}

function hillen_filter_price_for_breakpoint($record) {
    foreach (['List Price', 'Coded Price', 'Price (Cost)'] as $field) {
        $value = preg_replace('/[^0-9.]/', '', (string) ($record[$field] ?? ''));
        if ($value !== '' && is_numeric($value) && (float) $value > 0) {
            return (float) $value;
        }
    }

    return 0.0;
}

function hillen_add_filter(&$filters, $key, $value, $numeric = null, $source = 'parsed', $confidence = 'parsed', $raw = null) {
    $value = trim((string) $value);
    if ($value === '') {
        return;
    }

    $filters[] = [
        'key' => $key,
        'value' => $value,
        'numeric' => $numeric !== null && $numeric !== '' ? number_format((float) $numeric, 4, '.', '') : null,
        'source' => $source,
        'confidence' => $confidence,
        'raw' => $raw,
    ];
}

function hillen_insert_part_filter($table, $canonical_id, $sku, $sku_norm, $product_type, $key, $value, $numeric, $source, $confidence, $raw) {
    global $wpdb;

    $value_norm = hillen_normalize_filter_value($value);

    return $wpdb->replace(
        $table,
        [
            'canonical_part_id' => $canonical_id,
            'sku' => $sku,
            'sku_norm' => $sku_norm,
            'product_type' => $product_type,
            'filter_key' => $key,
            'filter_value' => $value,
            'filter_value_norm' => $value_norm,
            'numeric_value' => $numeric,
            'source' => $source,
            'confidence' => $confidence,
            'raw_value' => $raw,
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s']
    );
}

function hillen_normalize_filter_value($value) {
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/\s+/', ' ', $value);
    return preg_replace('/[^A-Z0-9.]+/', '_', $value);
}

function hillen_extract_compound($text) {
    $text = strtoupper((string) $text);
    $compounds = [
        'FALCONIUM' => 'Falconium',
        'HYLOAD' => 'Hyload',
        'XD59 SOFT' => 'XD59 Soft',
        'XD59' => 'XD59',
        'HLS' => 'HLS',
        'HL3' => 'HL3',
        'HL4' => 'HL4',
        'SPS' => 'SPS',
        'PRM SOFT' => 'PRM Soft',
        'LMAX' => 'LMAX',
        'XL' => 'XL',
        'POLY' => 'Poly',
    ];

    foreach ($compounds as $needle => $label) {
        if (preg_match('/(?<![A-Z0-9])' . preg_quote($needle, '/') . '(?![A-Z0-9])/', $text)) {
            return $label;
        }
    }

    foreach ($compounds as $needle => $label) {
        if (preg_match('/' . preg_quote($needle, '/') . '$/', $text)) {
            return $label;
        }
    }

    return null;
}

function hillen_extract_tread_treatment($description) {
    $text = strtoupper((string) $description);

    $map = [
        'XTREME DIAMOND TREAD' => 'Xtreme Diamond Tread',
        'EXTREME DIAMOND TREAD' => 'Xtreme Diamond Tread',
        'DIAMOND TREAD' => 'Diamond Tread',
        'CROSS GROOVE' => 'Cross Groove',
        'GROOVE' => 'Cross Groove',
        ' CG' => 'Cross Groove',
        'SENSOR SIPING' => 'Sensor Siping',
        'SIPING' => 'Sensor Siping',
        'SIPE' => 'Sensor Siping',
        'SMOOTH FLAT' => 'Smooth',
        'SMOOTH' => 'Smooth',
        ' SF' => 'Smooth',
        ' GR' => 'Cross Groove',
    ];

    foreach ($map as $needle => $label) {
        if (strpos($text, $needle) !== false) {
            return $label;
        }
    }

    return null;
}

function hillen_extract_bearing_part_number($description) {
    $text = strtoupper((string) $description);

    if (preg_match('/\(([A-Z0-9]+(?:\s*X\s*\d+)?)\)/', $text, $matches)) {
        $candidate = trim($matches[1]);
        if (preg_match('/\d/', $candidate) && !preg_match('/^\d+(?:\.\d+)?$/', $candidate)) {
            return preg_replace('/\s+/', '', $candidate);
        }
    }

    if (preg_match('/BEARING\s+([A-Z0-9\-]+)/', $text, $matches)) {
        return $matches[1];
    }

    return null;
}

function hillen_extract_size_filters($description) {
    $text = strtoupper((string) $description);
    $dimension = '(?:\d+(?:\.\d+)?(?:\s+(?:\d+\/\d+|12))?|\d+\s*\/\s*\d+)';
    $pattern = '/(?<![A-Z0-9])(' . $dimension . ')\s*X\s*(' . $dimension . ')\s*(?:X|\()?\s*(' . $dimension . ')?\)?/i';

    if (!preg_match($pattern, $text, $matches)) {
        return null;
    }

    $outside = hillen_dimension_to_decimal($matches[1] ?? null);
    $width = hillen_dimension_to_decimal($matches[2] ?? null);
    $inside = hillen_dimension_to_decimal($matches[3] ?? null);

    if (!$outside || !$width) {
        return null;
    }

    if ((float) $outside > 100 || (float) $width > 100 || ($inside && (float) $inside > 100)) {
        return null;
    }

    $parts = array_filter([$outside, $width, $inside]);

    return [
        'display' => count($parts) === 3
            ? sprintf('%s x %s (%s)', $outside, $width, $inside)
            : sprintf('%s x %s', $outside, $width),
        'outside_diameter' => $outside,
        'tread_width' => $width,
        'inside_diameter' => $inside,
    ];
}

function hillen_dimension_to_decimal($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/\s+/', ' ', $value);

    if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $value, $matches)) {
        return number_format((float) $matches[1] + ((float) $matches[2] / (float) $matches[3]), 4, '.', '');
    }

    if (preg_match('/^(\d+)\s+12$/', $value, $matches)) {
        return number_format((float) $matches[1] + 0.5, 4, '.', '');
    }

    if (preg_match('/^(\d+)\/(\d+)$/', $value, $matches)) {
        return number_format((float) $matches[1] / (float) $matches[2], 4, '.', '');
    }

    if (is_numeric($value)) {
        return number_format((float) $value, 4, '.', '');
    }

    return null;
}

function hillen_pdf_category_import_path() {
    $base_dir = defined('HILLEN_CANONICAL_DATA_PLUGIN_DIR')
        ? HILLEN_CANONICAL_DATA_PLUGIN_DIR
        : dirname(__DIR__) . '/';

    $upload_dir = wp_upload_dir();
    $candidates = [
        trailingslashit($upload_dir['basedir']) . 'canonical-data/pdf-category-import.ndjson',
        $base_dir . 'pdf-category-import.ndjson',
        trailingslashit($upload_dir['basedir']) . 'canonical-data/pdf-category-import.json',
        $base_dir . 'pdf-category-import.json',
    ];

    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0];
}

function hillen_pdf_category_match_hash($canonical_part_id, $category1, $category2, $category3, $source_pdf, $page, $inferred) {
    return sha1(implode('|', [
        (int) $canonical_part_id,
        strtolower(trim((string) $category1)),
        strtolower(trim((string) $category2)),
        strtolower(trim((string) $category3)),
        strtolower(trim((string) $source_pdf)),
        (string) ((int) $page),
        (string) ((int) $inferred),
    ]));
}

function hillen_pdf_category_priority($match) {
    $priority = !empty($match['inferred']) ? 200 : 20;

    if (!empty($match['category3'])) {
        $priority -= 5;
    }

    if (!empty($match['inferred']) && isset($match['inference_confidence'])) {
        $priority -= (int) round(((float) $match['inference_confidence']) * 25);
    }

    return max(1, $priority);
}

function hillen_import_pdf_category_part($part, $tables, $batch, &$summary) {
    global $wpdb;

    $summary['parts_seen']++;

    $sku = trim((string) ($part['sku'] ?? ''));
    $sku_norm = hillen_normalize_sku($part['sku_norm'] ?? $sku);
    $category1 = hillen_clean_category_text($part['category1'] ?? '');
    $matches = $part['matches'] ?? [];

    if ($sku === '' || $sku_norm === '' || !is_array($matches)) {
        $summary['matches_skipped']++;
        return;
    }

    $canonical_part_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$tables['parts']} WHERE sku_norm = %s OR vision_part_number = %s LIMIT 1",
            $sku_norm,
            $sku
        )
    );

    if (!$canonical_part_id) {
        $canonical_part_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT canonical_part_id FROM {$tables['rows']} WHERE sku_norm = %s ORDER BY canonical_part_id IS NULL, id LIMIT 1",
                $sku_norm
            )
        );
    }

    if (!$canonical_part_id) {
        $summary['parts_unmatched']++;
        return;
    }

    $summary['parts_matched']++;
    $best_match = null;
    $best_priority = 999999;
    $source_row_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
             FROM {$tables['rows']}
             WHERE canonical_part_id = %d
               AND (sku_norm = %s OR sku = %s)
             ORDER BY (category1 = %s) DESC, id
             LIMIT 1",
            (int) $canonical_part_id,
            $sku_norm,
            $sku,
            $category1
        )
    );

    foreach ($matches as $match) {
        $summary['matches_seen']++;

        $category2 = hillen_clean_category_text($match['category2'] ?? '');
        $category3 = hillen_clean_category_text($match['category3'] ?? '');

        if ($category2 === '') {
            $summary['matches_skipped']++;
            continue;
        }

        $source_pdf = trim((string) ($match['source_pdf'] ?? ''));
        $page = isset($match['page']) && $match['page'] !== null ? (int) $match['page'] : null;
        $inferred = !empty($match['inferred']) ? 1 : 0;
        $priority = hillen_pdf_category_priority($match);
        $match_hash = hillen_pdf_category_match_hash(
            $canonical_part_id,
            $category1,
            $category2,
            $category3,
            $source_pdf,
            $page,
            $inferred
        );

        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$tables['pdf']} WHERE match_hash = %s", $match_hash)
        );

        $row = [
            'canonical_part_id' => (int) $canonical_part_id,
            'source_row_id' => $source_row_id ? (int) $source_row_id : null,
            'sku' => $sku,
            'sku_norm' => $sku_norm,
            'category1' => $category1 ?: null,
            'category2' => $category2,
            'category3' => $category3 ?: null,
            'source_pdf' => $source_pdf ?: null,
            'page' => $page,
            'inferred' => $inferred,
            'inference_method' => trim((string) ($match['inference_method'] ?? '')) ?: null,
            'inference_confidence' => isset($match['inference_confidence']) ? (float) $match['inference_confidence'] : null,
            'priority' => $priority,
            'match_hash' => $match_hash,
            'import_batch' => $batch,
            'raw_json' => wp_json_encode($match),
        ];

        if ($existing_id) {
            $wpdb->update($tables['pdf'], $row, ['id' => (int) $existing_id]);
            $summary['category_rows_updated']++;
        } else {
            $wpdb->insert($tables['pdf'], $row);
            $summary['category_rows_inserted']++;
        }

        if ($priority < $best_priority) {
            $best_priority = $priority;
            $best_match = $row;
        }
    }

    if (!$best_match) {
        return;
    }

    $where_sql = "canonical_part_id = %d AND (category2 IS NULL OR category2 = '')";
    $where_args = [(int) $canonical_part_id];

    if ($category1 !== '') {
        $where_sql .= " AND (category1 = %s OR category1 IS NULL OR category1 = '')";
        $where_args[] = $category1;
    }

    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$tables['rows']}
             SET category1 = COALESCE(NULLIF(category1, ''), %s),
                 category2 = %s,
                 category3 = %s
             WHERE $where_sql",
            array_merge(
                [
                    $best_match['category1'],
                    $best_match['category2'],
                    $best_match['category3'],
                ],
                $where_args
            )
        )
    );

    if ($updated !== false) {
        $summary['source_rows_updated'] += (int) $updated;
    }
}

function hillen_import_pdf_categories($json_path = null, $offset = 0, $limit = 500) {
    global $wpdb;

    if (function_exists('hillen_ensure_pdf_category_table')) {
        hillen_ensure_pdf_category_table();
    }

    $offset = max(0, (int) $offset);
    $limit = max(1, min(5000, (int) $limit));
    $json_path = $json_path ?: hillen_pdf_category_import_path();
    $stream_path = preg_replace('/\.json$/', '.ndjson', $json_path);
    $import_path = file_exists($stream_path) ? $stream_path : $json_path;
    $is_stream = substr($import_path, -7) === '.ndjson';
    $tables = [
        'parts' => $wpdb->prefix . 'canonical_parts',
        'rows' => $wpdb->prefix . 'canonical_part_source_rows',
        'pdf' => $wpdb->prefix . 'canonical_part_pdf_categories',
    ];
    $batch = 'pdf_categories_' . gmdate('Ymd_His');

    $summary = [
        'file' => $import_path,
        'format' => $is_stream ? 'ndjson' : 'json',
        'parts_seen' => 0,
        'parts_matched' => 0,
        'parts_unmatched' => 0,
        'category_rows_inserted' => 0,
        'category_rows_updated' => 0,
        'source_rows_updated' => 0,
        'matches_seen' => 0,
        'matches_skipped' => 0,
        'batch' => $batch,
        'offset' => $offset,
        'limit' => $limit,
        'next_offset' => null,
        'has_more' => false,
    ];

    if ($is_stream) {
        $handle = fopen($import_path, 'r');
        if (!$handle) {
            return ['error' => 'Import file not found: ' . $import_path];
        }

        $line_number = 0;
        $processed = 0;

        while (($line = fgets($handle)) !== false) {
            if ($line_number < $offset) {
                $line_number++;
                continue;
            }

            if ($processed >= $limit) {
                $summary['has_more'] = true;
                $summary['next_offset'] = $line_number;
                break;
            }

            $line = trim($line);
            $line_number++;

            if ($line === '') {
                continue;
            }

            $part = json_decode($line, true);
            if (!is_array($part)) {
                $summary['matches_skipped']++;
                continue;
            }

            hillen_import_pdf_category_part($part, $tables, $batch, $summary);
            $processed++;
        }

        fclose($handle);

        if (!$summary['has_more']) {
            $summary['next_offset'] = null;
        }

        return $summary;
    }

    $raw = file_get_contents($import_path);
    if ($raw === false) {
        return ['error' => 'Import file not found: ' . $import_path];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['parts']) || !is_array($payload['parts'])) {
        return ['error' => 'Invalid category import JSON'];
    }

    $parts = array_slice($payload['parts'], $offset, $limit);
    foreach ($parts as $part) {
        hillen_import_pdf_category_part($part, $tables, $batch, $summary);
    }

    if ($offset + $limit < count($payload['parts'])) {
        $summary['has_more'] = true;
        $summary['next_offset'] = $offset + $limit;
    }

    return $summary;
}

function hillen_maybe_run_pdf_category_import() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_run_pdf_category_import'])) {
        return;
    }

    @set_time_limit(0);
    ini_set('memory_limit', '1024M');

    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 250;
    $summary = hillen_import_pdf_categories(null, $offset, $limit);

    echo '<pre style="padding:20px;background:#fff;border:1px solid #ccc;">';
    echo "PDF Category Import Summary\n";
    echo "---------------------------\n";
    print_r($summary);

    if (!empty($summary['has_more']) && isset($summary['next_offset'])) {
        $next_url = admin_url('?' . http_build_query([
            'hillen_run_pdf_category_import' => 1,
            'offset' => $summary['next_offset'],
            'limit' => $summary['limit'],
        ]));
        echo "\nNext batch:\n" . esc_url($next_url) . "\n";
        echo "\nOpen that URL to continue.\n";
    } else {
        echo "\nImport appears complete. Rebuild caches next with ?hillen_rebuild_cache=1\n";
    }

    echo '</pre>';
    exit;
}

function hillen_export_pdf_category_dataset() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_export_pdf_categories'])) {
        return;
    }

    @set_time_limit(0);
    ignore_user_abort(true);
    ini_set('memory_limit', '512M');

    global $wpdb;

    $pdf_table = $wpdb->prefix . 'canonical_part_pdf_categories';
    $parts_table = $wpdb->prefix . 'canonical_parts';

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=pdf_category_export.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'SKU/Part Number',
        'Category 1',
        'Category 2',
        'Category 3',
        'Source PDF',
        'Page',
        'Inferred',
        'Inference Method',
        'Inference Confidence',
        'Priority',
        'Import Batch',
    ]);

    $limit = 2000;
    $last_id = 0;

    do {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    c.id,
                    p.vision_part_number,
                    c.category1,
                    c.category2,
                    c.category3,
                    c.source_pdf,
                    c.page,
                    c.inferred,
                    c.inference_method,
                    c.inference_confidence,
                    c.priority,
                    c.import_batch
                FROM $pdf_table c
                INNER JOIN $parts_table p
                    ON p.id = c.canonical_part_id
                WHERE c.id > %d
                ORDER BY c.id
                LIMIT %d
                ",
                $last_id,
                $limit
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $last_id = (int) $row['id'];
            fputcsv($output, [
                $row['vision_part_number'],
                hillen_clean_category_text($row['category1'] ?? ''),
                hillen_clean_category_text($row['category2'] ?? ''),
                hillen_clean_category_text($row['category3'] ?? ''),
                $row['source_pdf'],
                $row['page'],
                $row['inferred'] ? 'Y' : 'N',
                $row['inference_method'],
                $row['inference_confidence'],
                $row['priority'],
                $row['import_batch'],
            ]);
        }

        fflush($output);
        flush();
    } while (count($rows) === $limit);

    fclose($output);
    exit;
}

function hillen_read_xlsx_first_sheet($xlsx_path) {
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($xlsx_path) !== true) {
        return [];
    }

    $shared_strings = [];
    $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($shared_xml !== false) {
        $shared = simplexml_load_string($shared_xml);
        foreach ($shared->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string) $si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
            }
            $shared_strings[] = $text;
        }
    }

    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheet_xml === false) {
        return [];
    }

    $sheet = simplexml_load_string($sheet_xml);
    $rows = [];

    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $index = hillen_xlsx_col_index($ref);
            $type = (string) $cell['t'];
            $value = isset($cell->v) ? (string) $cell->v : '';

            if ($type === 's' && $value !== '') {
                $value = $shared_strings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $value = (string) $cell->is->t;
            }

            $values[$index] = trim($value);
        }

        if (!empty($values)) {
            ksort($values);
            $rows[] = $values;
        }
    }

    return $rows;
}

function hillen_xlsx_col_index($cell_ref) {
    $letters = preg_replace('/[^A-Z]/i', '', $cell_ref);
    $index = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord(strtoupper($letters[$i])) - 64);
    }

    return $index - 1;
}

function hillen_normalize_header($header) {
    $header = strtolower(trim((string) $header));
    $header = str_replace('#', '', $header);
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    $header = trim($header, '_');

    $aliases = [
        'mfr_part' => 'mfr_part',
        'millennium_part' => 'millennium_part',
        'bearing' => 'bearing',
        'pm_price' => 'pm_price',
        'free_freight_qty' => 'free_freight_qty',
    ];

    return $aliases[$header] ?? $header;
}

function hillen_cell($row, $index) {
    return trim((string) ($row[$index] ?? ''));
}

function hillen_decimal_or_null($value, $scale = 2) {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    return number_format((float) $value, $scale, '.', '');
}

function hillen_parse_wheel_size($size) {
    $result = [
        'diameter' => null,
        'width' => null,
        'bore' => null,
    ];

    if (preg_match('/^\s*([0-9.]+)\s*x\s*([0-9.]+)(?:\s*\(([0-9.]+)\))?/i', $size, $matches)) {
        $result['diameter'] = number_format((float) $matches[1], 4, '.', '');
        $result['width'] = number_format((float) $matches[2], 4, '.', '');
        $result['bore'] = isset($matches[3]) ? number_format((float) $matches[3], 4, '.', '') : null;
    }

    return $result;
}

function hillen_wheel_compatibility_key($size_parts) {
    return implode('|', [
        $size_parts['diameter'] ?? '0.0000',
        $size_parts['width'] ?? '0.0000',
        $size_parts['bore'] ?? '0.0000',
    ]);
}

function hillen_normalize_manufacturer($manufacturer) {
    $manufacturer = trim((string) $manufacturer);
    $manufacturer = preg_replace('/\s*\/\s*/', ' / ', $manufacturer);
    $manufacturer = preg_replace('/\s+/', ' ', $manufacturer);

    return $manufacturer;
}

function hillen_millennium_wheel_name($manufacturer, $size, $compound, $wheel_type, $bearing) {
    $parts = array_filter([
        $manufacturer,
        $size,
        $compound,
        $wheel_type,
        $bearing ? 'Bearing ' . $bearing : null,
    ]);

    return implode(' ', $parts);
}


function hillen_normalize_sku($sku) {
    $sku = strtoupper(trim($sku));
    $sku = str_replace(['-', '_', ' '], '', $sku);
    return $sku;
}

function hillen_row_hash($data) {
    $normalized = [
        strtolower(trim($data['name'] ?? '')),
        number_format((float)($data['price'] ?? 0), 2, '.', ''),
        strtolower(trim($data['category1'] ?? '')),
        strtolower(trim($data['category2'] ?? '')),
        strtolower(trim($data['category3'] ?? '')),
        strtolower(trim($data['model'] ?? '')),
        (int)($data['serial_start'] ?? 0),
        (int)($data['serial_end'] ?? 0),
    ];

    return sha1(implode('|', $normalized));
}

function getManufacturerPrefixMap() {
    return [
        'taylor dunn' => 'TD',
        'taylor-dunn' => 'TD',
        'motrec' => 'MO',
        'ezgo' => 'EZ',
        'hyster yale' => 'HY',
        'raymond' => 'RA',
        'cub car' => 'CC',
        'tennant' => 'TN',
        'crown' => 'CR',
        'big joe' => 'BJ',
        'toyota' => 'TY',
        'clark' => 'CL',
        'unicarriers' => 'UN',
        'genie' => 'GN',
        'cushman' => 'CU',
        'jungheinrich' => 'JU',
        'tug' => 'TU',
        'prime mover' => 'PM',
        'pack mule' => 'PA',
        'jlg' => 'JL',
        'polaris' => 'PO',
        'cascade' => 'CA',
        'honda' => 'HO'
    ];
}

function transformPartNumber($number, $prefix) {
    $raw = trim((string)$number);
    if ($raw === '' || !preg_match('/\d/', $raw)) {
        return $raw;
    }

    $compact = preg_replace('/[-\x{2010}-\x{2015}\x{2212}]/u', '', $raw);

    $out = $prefix;

    for ($i = 0; $i < strlen($compact); $i++) {
        $ch = $compact[$i];

        if (ctype_digit($ch)) {
            $d = intval($ch);
            $out .= ($d === 0 || $d === 9) ? $ch : strval($d + 1);
        } else {
            $out .= $ch;
        }
    }

    return $out;
}


function calculatePrices($visionPrice, $wooPrice, $punchoutPrice, $millenniumPrice, $sku, $prefix) {

    // Determine base price by hierarchy
    $basePrice = null;
    $source = null;

    if ($visionPrice !== null) {
        $basePrice = $visionPrice;
        $source = 'vision';
    } elseif ($wooPrice !== null) {
        $basePrice = $wooPrice;
        $source = 'woo';
    } elseif ($millenniumPrice !== null) {
        $basePrice = $millenniumPrice;
        $source = 'millennium';
    } else {
        $basePrice = $punchoutPrice;
        $source = 'punchout';
    }

    if ($basePrice === null) {
        return [null, null, null];
    }

    // Woo special handling
    if ($source === 'woo') {

        $compact = preg_replace('/[-\u2010-\u2015\u2212]/u', '', $sku);

        $isCoded = false;

        $isCoded = isAlreadyCoded($sku);

        if ($isCoded) {
            $coded = $basePrice;
            $cost = round($coded / 1.6, 2);
            $list = round($cost * 1.5, 2);
        } else {
            $list = $basePrice;
            $cost = round($list / 1.5, 2);
            $coded = round($cost * 1.6, 2);
        }

    } else {
        // Vision, PunchOut, or Millennium
        // Logic hillen gave was incorrect, these are actually the list price. 
        //$cost = $basePrice;
        //$list = round($cost * 1.5, 2);
        //$coded = round($cost * 1.6, 2);

        $list = $basePrice;
        $cost = round($list / 1.5, 2);
        $coded = round($cost * 1.6, 2);

    }

    return [$cost, $list, $coded];
}

function hillen_rebuild_caches() {

    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_rebuild_cache'])) {
        return;
    }

    set_time_limit(0);
    ini_set('memory_limit', '512M');

    global $wpdb;

    $rollup_cache_table = $wpdb->prefix . 'canonical_rollup_cache';
    $category_cache_table = $wpdb->prefix . 'canonical_category_cache';
    $rollup_view = $wpdb->prefix . 'canonical_part_source_rollup';
    $source_rows_table = $wpdb->prefix . 'canonical_part_source_rows';
    $pdf_categories_table = $wpdb->prefix . 'canonical_part_pdf_categories';

    if (function_exists('hillen_ensure_pdf_category_table')) {
        hillen_ensure_pdf_category_table();
    }

    error_log("Rebuild started");

    // Clear existing cache
    $wpdb->query("TRUNCATE TABLE $rollup_cache_table");
    $wpdb->query("TRUNCATE TABLE $category_cache_table");

    // Rebuild rollup cache
    $wpdb->query("
        INSERT INTO $rollup_cache_table
        SELECT
            canonical_part_id,
            MAX(representative_name),
            MAX(representative_weight),

            MAX(CASE WHEN source = 'vision' THEN representative_price END),
            MAX(CASE WHEN source = 'woo' THEN representative_price END),
            MAX(CASE WHEN source = 'punchout' THEN representative_price END),
            MAX(CASE WHEN source = 'millennium' THEN representative_price END),

            MIN(min_serial_start),
            MAX(max_serial_end),

            MAX(CASE WHEN source = 'woo' THEN 1 ELSE 0 END)

        FROM $rollup_view
        GROUP BY canonical_part_id
    ");

    error_log("Rollup cache rebuilt");

    // Rebuild category cache
    $wpdb->query("
        INSERT INTO $category_cache_table
        SELECT
            base.canonical_part_id,
            GROUP_CONCAT(DISTINCT base.category1 ORDER BY base.category1 SEPARATOR ' | '),
            GROUP_CONCAT(DISTINCT base.category2 ORDER BY base.category2 SEPARATOR ' | '),
            GROUP_CONCAT(DISTINCT base.category3 ORDER BY base.category3 SEPARATOR ' | '),
            GROUP_CONCAT(DISTINCT base.category4 ORDER BY base.category4 SEPARATOR ' | '),
            GROUP_CONCAT(DISTINCT base.category5 ORDER BY base.category5 SEPARATOR ' | '),            
            GROUP_CONCAT(DISTINCT base.model ORDER BY base.model SEPARATOR ', ')
        FROM (
            SELECT
                canonical_part_id,
                CONVERT(category1 USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category1,
                CONVERT(category2 USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category2,
                CONVERT(category3 USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category3,
                CONVERT(category4 USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category4,
                CONVERT(category5 USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category5,
                CONVERT(model USING utf8mb4) COLLATE utf8mb4_unicode_ci AS model
            FROM $source_rows_table
            UNION ALL
            SELECT
                canonical_part_id,
                CONVERT(category1 USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category1,
                CONVERT(category2 USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category2,
                CONVERT(category3 USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category3,
                CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS category4,
                CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS category5,
                CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS model
            FROM $pdf_categories_table
        ) base
        WHERE base.canonical_part_id IS NOT NULL
        GROUP BY base.canonical_part_id
    ");

    error_log("Category cache rebuilt");

    echo "Cache rebuild complete.";
    exit;
}


function normalizeSku($sku) {
    $sku = trim((string)$sku);

    // Pattern: ** (11-111-11)
    if (preg_match('/^\*+\s*\(([A-Za-z0-9\-]+)\)$/', $sku, $m)) {
        return $m[1];
    }

    return $sku;
}
function cleanDescription($desc, $sku) {

    if (!$desc) return $desc;
    if (!$sku) return $desc;

    $desc = trim($desc);

    // Normalize SKU for matching logic
    $base = preg_replace('/[^A-Za-z0-9]/', '', $sku);

    if ($base === '') return $desc;

    // Build flexible pattern:
    // Allow optional dash between transitions
    $pattern = '';

    $chars = str_split($base);

    foreach ($chars as $ch) {
        $pattern .= preg_quote($ch, '/');
        $pattern .= '[-\x{2010}-\x{2015}\x{2212}]?'; // allow optional dash
    }

    // Remove trailing optional dash allowance
    $pattern = rtrim($pattern, '[-\x{2010}-\x{2015}\x{2212}]?');

    // Allow optional leading apostrophe
    $pattern = "'?" . $pattern;

    // 1️⃣ Remove full parenthetical containing variant
    $desc = preg_replace(
        '/\(\s*' . $pattern . '\s*\)/iu',
        '',
        $desc
    );

    // 2️⃣ Remove standalone variant
    $desc = preg_replace(
        '/\b' . $pattern . '\b/iu',
        '',
        $desc
    );

    // Cleanup
    $desc = preg_replace('/\(\s*\)/', '', $desc);
    $desc = preg_replace('/\s{2,}/', ' ', $desc);
    $desc = trim($desc, " -,");
    $desc = trim($desc);

    return $desc;
}

function buildSkuLoosePattern(string $sku): ?string {
    $sku = trim($sku);
    if ($sku === '') return null;

    // Excel sometimes gives leading apostrophe
    $sku = ltrim($sku, "'");

    // Keep only alnum for the "core" sequence
    $compact = preg_replace('/[^A-Za-z0-9]/', '', $sku);
    if ($compact === '' || !preg_match('/\d/', $compact)) {
        return null; // no digits => not a real part number
    }

    // Allow separators between characters: spaces, slash, any dash (ASCII + Unicode)
    $sep = '[\s\/\-\x{2010}-\x{2015}\x{2212}]*';

    $chars = preg_split('//u', $compact, -1, PREG_SPLIT_NO_EMPTY);
    $escaped = array_map(fn($ch) => preg_quote($ch, '/'), $chars);

    // Boundaries: don't match inside a bigger alnum token
    $pattern = '(?<![A-Za-z0-9])' . implode($sep, $escaped) . '(?![A-Za-z0-9])';

    return $pattern;
}

function buildSkuVariants(string $sku): array {

    $sku = trim($sku);

    // Remove leading apostrophe or dot
    $sku = ltrim($sku, "'. ");

    // Remove slash suffix like " / C"
    $skuNoSlash = preg_replace('/\s*\/\s*[A-Za-z0-9]+$/', '', $sku);

    // Remove trailing pure word suffix (Falconium, Hyload)
    $skuNoWordSuffix = preg_replace('/([0-9A-Za-z\-]*?[0-9A-Za-z]+)[A-Za-z]+$/', '$1', $skuNoSlash);

    return array_unique(array_filter([
        $skuNoSlash,
        $skuNoWordSuffix
    ]));
}
function buildSkuCandidates(string $sku): array {

    $sku = trim($sku);
    $sku = ltrim($sku, "'. ");

    // Remove slash suffix
    $sku = preg_replace('/\s*\/\s*[A-Za-z0-9]+$/', '', $sku);

    $candidates = [];

    // Always include full SKU
    $candidates[] = $sku;

    // Split original into parts
    $originalParts = explode('-', $sku);

    if (count($originalParts) > 0) {

        $last = end($originalParts);

        // Extract numeric + optional single letter subcode (e.g. 71, 71A)
        if (preg_match('/^([0-9]+[A-Za-z]?)/', $last, $m)) {

            $parts = $originalParts;
            $parts[count($parts) - 1] = $m[1];

            $baseWithSubcode = implode('-', $parts);

            // IMPORTANT: push this immediately after full SKU
            $candidates[] = $baseWithSubcode;
        }
    }

    // Now progressive truncation using ORIGINAL parts only
    for ($i = count($originalParts) - 1; $i > 0; $i--) {
        $candidates[] = implode('-', array_slice($originalParts, 0, $i));
    }

    $candidates = array_unique(array_filter($candidates));

    // Sort longest first
    usort($candidates, fn($a, $b) => strlen($b) - strlen($a));

    return $candidates;
}
function stripSkuFromDescription(?string $desc, ?string $sku): string {

    $desc = trim((string)$desc);
    if ($desc === '' || !$sku) return $desc;

    // Clean SKU for matching
    $skuClean = ltrim(trim($sku), "'. ");

    $candidates = buildSkuCandidates($sku);

    // Split by dash and progressively shorten
    $parts = explode('-', $skuClean);
    for ($i = count($parts) - 1; $i > 0; $i--) {
        $candidates[] = implode('-', array_slice($parts, 0, $i));
    }

    // Remove duplicates and sort longest first
    $candidates = array_unique(array_filter($candidates));
    usort($candidates, fn($a, $b) => strlen($b) - strlen($a));

    foreach ($candidates as $candidate) {

        $escaped = preg_quote($candidate, '/');

        // Remove parenthetical version first
        $desc = preg_replace('/\s*\(\s*' . $escaped . '\s*\)\s*/i', ' ', $desc);

        // Remove standalone version
        $desc = preg_replace('/(?<![A-Za-z0-9])' . $escaped . '(?![A-Za-z0-9])/i', ' ', $desc);
    }

    // Cleanup
    $desc = preg_replace('/\(\s*[-,;:]*\s*\)/', ' ', $desc);
    $desc = preg_replace('/\s{2,}/', ' ', $desc);
    $desc = preg_replace('/[-–—]\s*$/u', '', $desc);
    $desc = trim($desc);

    $desc = str_replace("eel -71", "eel", $desc); // fix a special case. 

    return $desc;
}

function normalizeLoose($sku) {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', $sku));
}

function removeAllPartNumbers($desc) {

    if (!$desc) return $desc;

    // Remove patterns like 12345-12345-12A
    $desc = preg_replace('/\b\d{3,}-\d{3,}-[A-Za-z0-9]+\b/u', ' ', $desc);

    // Remove patterns like 12345-12345
    $desc = preg_replace('/\b\d{3,}-\d{3,}\b/u', ' ', $desc);

    // Remove patterns like 00407-G6
    $desc = preg_replace('/\b\d{3,}-[A-Z]\d+\b/u', ' ', $desc);

    // Remove slash numbers (/003)
    $desc = preg_replace('/\/\d{3,}/u', ' ', $desc);

    // Remove fragments like -71A
    $desc = preg_replace('/\-\d+[A-Za-z]?/u', ' ', $desc);

    // Cleanup
    $desc = preg_replace('/\s{2,}/', ' ', $desc);
    $desc = trim($desc, " -,/");

    // remove empty parenthesis
    $desc = preg_replace('/\(\s*\)/', ' ', $desc);

    // remove stray punctuation
    $desc = preg_replace('/,\s*\)/', ')', $desc);
    $desc = preg_replace('/,\s*,/', ',', $desc);

    return $desc;
}

function normalizeEncoding($text) {

    if (!$text) return $text;

    // Step 1: Repair common mis-encoding (Windows-1252 interpreted as UTF-8)
    $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');

    // Step 2: Normalize known bad sequences (keep your mappings, cleaned up)
    $replace = [

        // corrupted quotes
        'â€™' => "'",
        'â€œ' => '"',
        'â€�' => '"',

        // corrupted dashes
        'â€“' => '-',
        'â€”' => '-',

        // corrupted inch / foot
        'â€³' => '"',
        'â€²' => "'",

        // correct unicode versions
        '″' => '"',
        '′' => "'",

        // stray encoding artifacts
        'Â' => '',
        'Ã˜' => '',

    ];

    $text = str_replace(array_keys($replace), array_values($replace), $text);

    // Step 3: Convert any remaining unicode → ASCII
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

    // Step 4: Uppercase (your system standard)
    $text = strtoupper($text);

    // Step 5: Remove anything unexpected
    $text = preg_replace('/[^A-Z0-9\s\-\(\)\.,]/', '', $text);

    // Step 6: Normalize spacing
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
}

function hillen_clean_category_text($text) {
    if ($text === null) {
        return '';
    }

    $text = trim((string) $text);
    if ($text === '' || $text === '.') {
        return '';
    }

    if (preg_match('/Ãƒ|Ã¢|Ã‚/', $text)) {
        $repaired = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        if ($repaired !== false) {
            $text = $repaired;
        }
    }

    $replace = [
        'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢' => "'",
        'ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œ' => '"',
        'ÃƒÂ¢Ã¢â€šÂ¬Ã¯Â¿Â½' => '"',
        'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“' => '-',
        'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â' => '-',
        'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â³' => '"',
        'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â²' => "'",
        'Ã¢â‚¬Â³' => '"',
        'Ã¢â‚¬Â²' => "'",
        'Ã¢â‚¬â€œ' => '-',
        'Ã¢â‚¬â€' => '-',
        'Ãƒâ€š' => '',
        'ÃƒÆ’Ã‹Å“' => '',
    ];

    $text = str_replace(array_keys($replace), array_values($replace), $text);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }

    $text = strtoupper($text);
    $text = preg_replace('/[^A-Z0-9\s\-\(\)\.,&\/\|]/', ' ', $text);
    $text = preg_replace('/\s*\|\s*/', ' | ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text, " \t\n\r\0\x0B-.,|/");

    if ($text === '.' || $text === '') {
        return '';
    }

    if (strpos($text, '|') !== false) {
        $parts = [];
        foreach (explode('|', $text) as $part) {
            $part = trim($part, " \t\n\r\0\x0B-.,|/");
            if ($part !== '' && strpos($part, '(') === false && preg_match('/\)$/', $part)) {
                continue;
            }
            if ($part !== '' && $part !== '.') {
                $parts[hillen_unsmoosh_category_segment($part)] = true;
            }
        }

        return implode(' | ', array_keys($parts));
    }

    if (strpos($text, '(') === false && preg_match('/\)$/', $text)) {
        return '';
    }

    return hillen_unsmoosh_category_segment($text);
}

function hillen_unsmoosh_category_segment($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/,([A-Z0-9])/', ', $1', $text);

    $phrases = [
        'ACANDHEATER' => 'AC AND HEATER',
        'BATTERYCHARGERS' => 'BATTERY CHARGERS',
        'BLOWERWAND' => 'BLOWER WAND',
        'BRAKEPEDAL' => 'BRAKE PEDAL',
        'BRUSHDRIVE' => 'BRUSH DRIVE',
        'BRUSHHEAD' => 'BRUSH HEAD',
        'CABCOVER' => 'CAB COVER',
        'CIRCUITBOARD' => 'CIRCUIT BOARD',
        'CYLINDRICALBRUSH' => 'CYLINDRICAL BRUSH',
        'CYLINDRICALSCRUBHEAD' => 'CYLINDRICAL SCRUB HEAD',
        'DIRECTIONALPEDAL' => 'DIRECTIONAL PEDAL',
        'DISKSCRUBHEAD' => 'DISK SCRUB HEAD',
        'DOCUMENTATIONGROUP' => 'DOCUMENTATION GROUP',
        'EC-H20SOLUTION' => 'EC-H20 SOLUTION',
        'ELECTRICALGROUP' => 'ELECTRICAL GROUP',
        'ENGINEALTERNATOR' => 'ENGINE ALTERNATOR',
        'ENGINEANDMOUNT' => 'ENGINE AND MOUNT',
        'ENGINECOVER' => 'ENGINE COVER',
        'ENGINEFUEL' => 'ENGINE FUEL',
        'ENGINERADIATOR' => 'ENGINE RADIATOR',
        'EXTENDEDSCRUB' => 'EXTENDED SCRUB',
        'FASTGROUP' => 'FAST GROUP',
        'FRONTWHEELGEARBOX' => 'FRONT WHEEL GEARBOX',
        'HEPAFILTEREXHAUST' => 'HEPA FILTER EXHAUST',
        'HEPAFILTERBOX' => 'HEPA FILTER BOX',
        'HOPPERCHAIN' => 'HOPPER CHAIN',
        'HOPPERCOVER' => 'HOPPER COVER',
        'HOPPERCYLINDER' => 'HOPPER CYLINDER',
        'HOPPERFILTER' => 'HOPPER FILTER',
        'HOPPERLIFT' => 'HOPPER LIFT',
        'HYDRAULICCONTROL' => 'HYDRAULIC CONTROL',
        'HYDRAULICGEAR' => 'HYDRAULIC GEAR',
        'HYDRAULICHOSE' => 'HYDRAULIC HOSE',
        'HYDRAULICPISTON' => 'HYDRAULIC PISTON',
        'HYDRAULICPUMP' => 'HYDRAULIC PUMP',
        'HYDRAULICRESERVOIR' => 'HYDRAULIC RESERVOIR',
        'INSTRUMENTPANEL' => 'INSTRUMENT PANEL',
        'MAINBRUSH' => 'MAIN BRUSH',
        'MAINFRAME' => 'MAIN FRAME',
        'MITSUBISHIENGINE' => 'MITSUBISHI ENGINE',
        'OPERATORSTATION' => 'OPERATOR STATION',
        'PRESSUREWASHER' => 'PRESSURE WASHER',
        'REARCOVER' => 'REAR COVER',
        'REARSQUEEGEE' => 'REAR SQUEEGEE',
        'RECOVERYTANK' => 'RECOVERY TANK',
        'SIDEBRUSH' => 'SIDE BRUSH',
        'SOLUTIONPUMP' => 'SOLUTION PUMP',
        'SOLUTIONTANK' => 'SOLUTION TANK',
        'SPRAYNOZZLE' => 'SPRAY NOZZLE',
        'SQUEEGEELIFT' => 'SQUEEGEE LIFT',
        'SQUEEGEELINKAGE' => 'SQUEEGEE LINKAGE',
        'SCRUBHEAD' => 'SCRUB HEAD',
        'TELEMETRYGROUP' => 'TELEMETRY GROUP',
        'VACUUMFAN' => 'VACUUM FAN',
        'VACUUMWAND' => 'VACUUM WAND',
        'WHEELANDBRAKE' => 'WHEEL AND BRAKE',
        'WETSIDEBRUSH' => 'WET SIDE BRUSH',
    ];

    uksort($phrases, function ($a, $b) {
        return strlen($b) <=> strlen($a);
    });

    foreach ($phrases as $from => $to) {
        $text = str_replace($from, $to, $text);
    }

    $text = preg_replace('/([A-Z0-9])GROUP\b/', '$1 GROUP', $text);
    $text = preg_replace('/([A-Z0-9])BREAKDOWN\b/', '$1 BREAKDOWN', $text);
    $text = preg_replace('/([A-Z0-9])KIT\b/', '$1 KIT', $text);
    $text = preg_replace('/([A-Z0-9])MOTOR\b/', '$1 MOTOR', $text);
    $text = preg_replace('/([A-Z0-9])COVER\b/', '$1 COVER', $text);
    $text = preg_replace('/([A-Z0-9])LIFT\b/', '$1 LIFT', $text);
    $text = preg_replace('/([A-Z0-9])DRIVE\b/', '$1 DRIVE', $text);
    $text = preg_replace('/([A-Z0-9])HEAD\b/', '$1 HEAD', $text);
    $text = preg_replace('/([A-Z0-9])TANK\b/', '$1 TANK', $text);
    $text = preg_replace('/([A-Z0-9])PUMP\b/', '$1 PUMP', $text);
    $text = preg_replace('/([A-Z0-9])BOX\b/', '$1 BOX', $text);
    $text = preg_replace('/([A-Z0-9])DOOR\b/', '$1 DOOR', $text);
    $text = preg_replace('/([A-Z0-9])FILTER\b/', '$1 FILTER', $text);
    $text = preg_replace('/([A-Z0-9])SQUEEGEE\b/', '$1 SQUEEGEE', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = preg_replace('/\s+,/', ',', $text);

    return trim($text);
}

function hillen_text_matches_any($text, $patterns) {
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }

    return false;
}

function hillen_infer_category4_5($description, $category1, $category2, $category3) {
    $description = hillen_clean_category_text($description);
    $category1 = hillen_clean_category_text($category1);
    $category2 = hillen_clean_category_text($category2);
    $category3 = hillen_clean_category_text($category3);
    $text = trim("$description $category3 $category2 $category1");

    $rules = [
        ['Screws', 'Fasteners', ['/(\b|-)SCREW(S)?\b/', '/\bCAP SCREW\b/', '/\bMACHINE SCREW\b/', '/\bSETSCREW\b/']],
        ['Bolts', 'Fasteners', ['/\bBOLT(S)?\b/', '/\bU-BOLT(S)?\b/', '/\bSHOULDER BOLT\b/']],
        ['Nuts', 'Fasteners', ['/\bNUT(S)?\b/', '/\bLOCKNUT(S)?\b/', '/\bJAM NUT\b/']],
        ['Washers', 'Fasteners', ['/\bWASHER(S)?\b/', '/\bLOCKWASHER(S)?\b/']],
        ['Rivets', 'Fasteners', ['/\bRIVET(S)?\b/']],
        ['Pins', 'Fasteners', ['/\bPIN(S)?\b/', '/\bCOTTER\b/', '/\bCLEVIS\b/', '/\bDOWEL\b/']],
        ['Clips and Retainers', 'Fasteners', ['/\bCLIP(S)?\b/', '/\bRETAINER(S)?\b/', '/\bRING,? RETAIN/', '/\bSNAP RING\b/', '/\bE-CLIP\b/']],
        ['Fittings', 'Hydraulics', ['/\bFITTING(S)?\b/', '/\bADAPTER(S)?\b/', '/\bELBOW(S)?\b/', '/\bTEE(S)?\b/', '/\bCOUPLER(S)?\b/', '/\bNIPPLE(S)?\b/']],
        ['Hoses', 'Hydraulics', ['/\bHOSE(S)?\b/', '/\bHYDRAULIC LINE(S)?\b/', '/\bTUBE ASSY\b/', '/\bTUBING\b/']],
        ['Cylinders', 'Hydraulics', ['/\bCYLINDER(S)?\b/', '/\bRAM\b/']],
        ['Valves', 'Hydraulics', ['/\bVALVE(S)?\b/', '/\bMANIFOLD(S)?\b/', '/\bSOLENOID VALVE\b/']],
        ['Pumps', 'Hydraulics', ['/\bPUMP(S)?\b/', '/\bMOTOR PUMP\b/']],
        ['Seals and O-Rings', 'Sealing', ['/\bSEAL(S)?\b/', '/\bO[- ]?RING(S)?\b/', '/\bGASKET(S)?\b/', '/\bPACKING\b/']],
        ['Bearings', 'Motion', ['/\bBEARING(S)?\b/', '/\bBUSHING(S)?\b/', '/\bBUSH\b/']],
        ['Rollers', 'Motion', ['/\bROLLER(S)?\b/', '/\bCASTER(S)?\b/']],
        ['Wheels', 'Wheels and Tires', ['/\bWHEEL(S)?\b/', '/\bRIM(S)?\b/', '/\bCASTOR WHEEL\b/']],
        ['Tires', 'Wheels and Tires', ['/\bTIRE(S)?\b/', '/\bTYRE(S)?\b/', '/\bPNEUMATIC\b/']],
        ['Brakes', 'Braking', ['/\bBRAKE(S)?\b/', '/\bSHOE(S)?\b/', '/\bCALIPER(S)?\b/', '/\bDRUM(S)?\b/', '/\bROTOR(S)?\b/']],
        ['Cables', 'Electrical', ['/\bCABLE(S)?\b/', '/\bWIRE(S)?\b/', '/\bHARNESS(ES)?\b/', '/\bLEAD(S)?\b/']],
        ['Switches', 'Electrical', ['/\bSWITCH(ES)?\b/', '/\bCONTACTOR(S)?\b/', '/\bRELAY(S)?\b/']],
        ['Lights', 'Electrical', ['/\bLIGHT(S)?\b/', '/\bLAMP(S)?\b/', '/\bLED\b/', '/\bHEADLIGHT(S)?\b/', '/\bTAIL LIGHT(S)?\b/']],
        ['Batteries and Chargers', 'Electrical', ['/\bBATTER(Y|IES)\b/', '/\bCHARGER(S)?\b/', '/\bPLUG(S)?\b/', '/\bRECEPTACLE(S)?\b/']],
        ['Motors and Controllers', 'Electrical', ['/\bMOTOR(S)?\b/', '/\bCONTROLLER(S)?\b/', '/\bCONTROL BOX\b/']],
        ['Filters', 'Maintenance', ['/\bFILTER(S)?\b/', '/\bSTRAINER(S)?\b/']],
        ['Belts and Pulleys', 'Powertrain', ['/\bBELT(S)?\b/', '/\bPULLEY(S)?\b/']],
        ['Chains and Sprockets', 'Powertrain', ['/\bCHAIN(S)?\b/', '/\bSPROCKET(S)?\b/']],
        ['Gears and Shafts', 'Powertrain', ['/\bGEAR(S)?\b/', '/\bSHAFT(S)?\b/', '/\bAXLE(S)?\b/', '/\bDIFFERENTIAL\b/']],
        ['Engine Parts', 'Powertrain', ['/\bENGINE\b/', '/\bCARBURETOR(S)?\b/', '/\bMUFFLER(S)?\b/', '/\bEXHAUST\b/', '/\bSTARTER\b/', '/\bALTERNATOR\b/', '/\bSPARK PLUG(S)?\b/']],
        ['Seats', 'Body', ['/\bSEAT(S)?\b/', '/\bBACKREST(S)?\b/', '/\bARMREST(S)?\b/']],
        ['Body Panels and Covers', 'Body', ['/\bCOVER(S)?\b/', '/\bPANEL(S)?\b/', '/\bHOOD(S)?\b/', '/\bFENDER(S)?\b/', '/\bGUARD(S)?\b/', '/\bBUMPER(S)?\b/']],
        ['Handles and Levers', 'Controls', ['/\bHANDLE(S)?\b/', '/\bLEVER(S)?\b/', '/\bPEDAL(S)?\b/', '/\bKNOB(S)?\b/', '/\bGRIP(S)?\b/']],
        ['Labels and Decals', 'Identification', ['/\bLABEL(S)?\b/', '/\bDECAL(S)?\b/', '/\bPLATE,? NAME\b/', '/\bNAMEPLATE(S)?\b/', '/\bWARNING\b/']],
        ['Keys and Locks', 'Security', ['/\bKEY(S)?\b/', '/\bLOCK(S)?\b/', '/\bLATCH(ES)?\b/']],
        ['Springs', 'Motion', ['/\bSPRING(S)?\b/']],
        ['Mounts and Brackets', 'Structure', ['/\bBRACKET(S)?\b/', '/\bMOUNT(S)?\b/', '/\bSUPPORT(S)?\b/']],
        ['Tanks and Caps', 'Fluids', ['/\bTANK(S)?\b/', '/\bCAP(S)?\b/', '/\bFUEL TANK\b/']],
        ['Brushes and Squeegees', 'Cleaning System', ['/\bBRUSH(ES)?\b/', '/\bSQUEEGEE(S)?\b/', '/\bPAD DRIVER(S)?\b/']],
    ];

    foreach ($rules as $rule) {
        if (hillen_text_matches_any($text, $rule[2])) {
            return [$rule[0], $rule[1]];
        }
    }

    if (preg_match('/\bHARDWARE\b/', $text)) {
        return ['Hardware', 'Fasteners'];
    }
    if (preg_match('/\bELECTRICAL\b/', $text)) {
        return ['Electrical Components', 'Electrical'];
    }
    if (preg_match('/\bHYDRAULIC(S)?\b/', $text)) {
        return ['Hydraulic Components', 'Hydraulics'];
    }
    if (preg_match('/\bSTEERING\b/', $text)) {
        return ['Steering Components', 'Steering'];
    }
    if (preg_match('/\bFRAME|BODY\b/', $text)) {
        return ['Body and Frame Components', 'Body'];
    }

    return ['Other Parts', 'General Parts'];
}

function cleanCodedPart($coded) {
    if (!$coded) return $coded;

    return preg_replace('/[^A-Za-z0-9]/', '', $coded);
}


function isAlreadyCoded($sku) {

    $sku = trim($sku);

    // Remove dashes
    $compact = preg_replace('/[-\x{2010}-\x{2015}\x{2212}]/u', '', $sku);

    $prefixMap = getManufacturerPrefixMap();

    foreach ($prefixMap as $manufacturer => $prefix) {

        if (!$prefix) continue;

        // Must start with exact known prefix
        if (stripos($compact, $prefix) === 0) {
            return true;
        }
    }

    return false;
}

function hillen_export_master_dataset() {

    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_export_master'])) {
        return;
    }

    set_time_limit(0);
    ignore_user_abort(true);
    ini_set('memory_limit', '512M');

    // Kill all output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }

    global $wpdb;

    error_log("Export started");

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=master_parts_export.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'SKU/Part Number',
        'Coded Part Number',
        'Description',
        'Weight (lbs)',
        'HS Code',
        'UNSPSC Code',
        'Shipping Dimensions',
        'Category 5',
        'Category 4',
        'Category 3',
        'Category 2',
        'Category 1',
        'Price (Cost)',
        'List Price',
        'Coded Price',
        'Models',
        'Serial Start',
        'Serial End',
        'CURRENTLY ON WEBSITE Y/N'
    ]);

    $oemLookup = [];

    $parts_table = $wpdb->prefix . 'canonical_parts';
    $rollup_cache_table = $wpdb->prefix . 'canonical_rollup_cache';
    $category_cache_table = $wpdb->prefix . 'canonical_category_cache';
    $classification_table = $wpdb->prefix . 'canonical_part_classification';
    $wheel_specs_table = $wpdb->prefix . 'canonical_wheel_specs';

    $parts = $wpdb->get_results("
        SELECT vision_part_number
        FROM $parts_table
    ", ARRAY_A);

    foreach ($parts as $p) {

        $sku = trim($p['vision_part_number']);
        $norm = normalizeLoose($sku);

        if ($norm) {
            $oemLookup[$norm] = $sku;
        }
    }    

    $millenniumPartLookup = [];
    $millenniumRows = $wpdb->get_results("
        SELECT canonical_part_id, MIN(millennium_part_number) AS millennium_part_number
        FROM $wheel_specs_table
        GROUP BY canonical_part_id
    ", ARRAY_A);

    foreach ($millenniumRows as $wheelRow) {
        $millenniumPartLookup[(int) $wheelRow['canonical_part_id']] = $wheelRow['millennium_part_number'];
    }

    $prefixMap = getManufacturerPrefixMap();

    $baseQuery = "
        SELECT 
            p.id AS canonical_part_id,
            p.vision_part_number,
            r.representative_name,
            r.representative_weight,
            r.vision_price,
            r.woo_price,
            r.punchout_price,
            r.millennium_price,
            r.min_serial_start,
            r.max_serial_end,
            r.on_website,
            c.category1,
            c.category2,
            c.category3,
            c.category4,
            c.category5,
            c.models,
            cl.unspsc,
            cl.hs_code
        FROM $parts_table p
        LEFT JOIN $rollup_cache_table r 
            ON p.id = r.canonical_part_id
        LEFT JOIN $category_cache_table c 
            ON p.id = c.canonical_part_id
        LEFT JOIN $classification_table cl
            ON p.id = cl.canonical_part_id
    ";

    $limit = 1000;
    $offset = 0;
    $totalWritten = 0;

    do {

        $rows = $wpdb->get_results(
            $baseQuery . " LIMIT $limit OFFSET $offset",
            ARRAY_A
        );

        foreach ($rows as $row) {

            $cat1List = explode('|', $row['category1'] ?? '');
            foreach ($cat1List as $cat1) {

                $cat1 = trim($cat1);

                $sku = normalizeSku($row['vision_part_number']);
                $categoryKey = strtolower($cat1);
                $categoryKey = str_replace(['-', '_'], ' ', $categoryKey);
                $categoryKey = preg_replace('/\s+/', ' ', $categoryKey);

                $prefix = $prefixMap[$categoryKey] ?? null;

                $isCoded = isAlreadyCoded($sku);

                if ($isCoded) {
                    //$codedPart = $sku;
                    //$exportSku = 'Find OEM Match';

                    $codedPart = $sku;

                    $codedNorm = normalizeLoose($sku);

                    if (isset($oemLookup[$codedNorm])) {

                        // OEM exists, so this coded row is duplicate
                        continue;

                    } else {

                        // No OEM found, treat coded as real SKU
                        $exportSku = $sku;
                        $codedPart = null;

                    }

                } else {
                    $codedPart = $prefix 
                        ? cleanCodedPart(transformPartNumber($sku, $prefix)) 
                        : null;

                    $exportSku = $sku;
                }

                if (isset($millenniumPartLookup[(int) $row['canonical_part_id']])) {
                    $codedPart = $millenniumPartLookup[(int) $row['canonical_part_id']];
                }




                $descClean = $row['representative_name'] ?? '';
                $descClean = normalizeEncoding($descClean);
                $descClean = stripSkuFromDescription($descClean, $exportSku !== 'Find OEM Match' ? $exportSku : $sku);
                $descClean = removeAllPartNumbers($descClean);    

                $descClean = strtoupper($descClean);
                $descClean = normalizeEncoding($descClean);
                $descClean = normalizeEncoding($descClean);

                $cat1 = hillen_clean_category_text($cat1);
                $cat2 = hillen_clean_category_text($row['category2'] ?? '');
                $cat3 = hillen_clean_category_text($row['category3'] ?? '');
                $cat4 = hillen_clean_category_text($row['category4'] ?? '');
                $cat5 = hillen_clean_category_text($row['category5'] ?? '');

                if ($cat4 === '' || $cat5 === '') {
                    list($inferredCat4, $inferredCat5) = hillen_infer_category4_5(
                        $descClean,
                        $cat1,
                        $cat2,
                        $cat3
                    );

                    if ($cat4 === '') {
                        $cat4 = hillen_clean_category_text($inferredCat4);
                    }

                    if ($cat5 === '') {
                        $cat5 = hillen_clean_category_text($inferredCat5);
                    }
                }
                        

                list($cost, $list, $coded) = calculatePrices(
                    $row['vision_price'],
                    $row['woo_price'],
                    $row['punchout_price'],
                    $row['millennium_price'],
                    $sku,
                    $prefix
                );

                fputcsv($output, [
                    $exportSku,
                    $codedPart,
                    $descClean,
                    $row['representative_weight'],
                    $row['unspsc'],
                    $row['hs_code'],
                    null,
                    $cat5,
                    $cat4,                
                    $cat3,
                    $cat2,
                    $cat1,
                    $cost,
                    $list,
                    $coded,
                    $row['models'],
                    $row['min_serial_start'],
                    $row['max_serial_end'],
                    $row['on_website'] ? 'Y' : 'N'
                ]);

                $totalWritten++;
            }
        }

        $offset += $limit;

        fflush($output);
        flush();

        error_log("Written so far: " . $totalWritten);

    } while (count($rows) === $limit);

    fclose($output);

    error_log("Export finished. Total rows: " . $totalWritten);

    exit;
}

function hillen_export_master_dataset_with_filters() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['hillen_export_master_with_filters'])) {
        return;
    }

    $source_path = WP_PLUGIN_DIR . '/canonical-data/master_parts_export(51).csv';
    if (!file_exists($source_path)) {
        echo 'Master export CSV not found.';
        exit;
    }

    global $wpdb;

    $filters_table = $wpdb->prefix . 'canonical_part_filters';
    $filter_rows = $wpdb->get_results("
        SELECT sku_norm, product_type, filter_key, filter_value
        FROM $filters_table
    ", ARRAY_A);

    $filter_lookup = [];
    foreach ($filter_rows as $filter) {
        $sku_norm = $filter['sku_norm'];
        $key = $filter['filter_key'];
        $filter_lookup[$sku_norm]['product_type'] = $filter['product_type'];
        $filter_lookup[$sku_norm][$key][] = $filter['filter_value'];
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=master_parts_export_with_filters.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $input = fopen($source_path, 'r');
    $output = fopen('php://output', 'w');
    $headers = fgetcsv($input);

    $filter_headers = [
        'Filter Product Type',
        'Filter Compound',
        'Filter Tread Treatment',
        'Filter Size',
        'Filter Outside Diameter',
        'Filter Tread Width',
        'Filter Inside Diameter',
        'Filter Manufacturer Part Number',
        'Filter Manufacturer',
        'Filter Bearing Part Number',
        'Filter Has Bearings',
        'Filter Preassembled',
        'Filter Free Shipping Threshold',
        'Filter Free Shipping Qty Breakpoint',
    ];

    fputcsv($output, array_merge($headers, $filter_headers));
    $idx = array_flip($headers);

    while (($row = fgetcsv($input)) !== false) {
        $sku = $row[$idx['SKU/Part Number']] ?? '';
        $filters = $filter_lookup[hillen_normalize_sku($sku)] ?? [];

        $extra = [
            $filters['product_type'] ?? '',
            hillen_join_filter_export_values($filters['compound'] ?? []),
            hillen_join_filter_export_values($filters['tread_treatment'] ?? []),
            hillen_join_filter_export_values($filters['size'] ?? []),
            hillen_join_filter_export_values($filters['outside_diameter'] ?? []),
            hillen_join_filter_export_values($filters['tread_width'] ?? []),
            hillen_join_filter_export_values($filters['inside_diameter'] ?? []),
            hillen_join_filter_export_values($filters['manufacturer_part_number'] ?? []),
            hillen_join_filter_export_values($filters['manufacturer'] ?? []),
            hillen_join_filter_export_values($filters['bearing_part_number'] ?? []),
            hillen_join_filter_export_values($filters['has_bearings'] ?? []),
            hillen_join_filter_export_values($filters['preassembled'] ?? []),
            hillen_join_filter_export_values($filters['free_shipping_threshold'] ?? []),
            hillen_join_filter_export_values($filters['free_shipping_qty_breakpoint'] ?? []),
        ];

        fputcsv($output, array_merge($row, $extra));
    }

    fclose($input);
    fclose($output);
    exit;
}

function hillen_join_filter_export_values($values) {
    $values = array_unique(array_filter(array_map('trim', (array) $values)));
    return implode(' | ', $values);
}

function classifyPartWithAI($part) {

    $apiKey = getenv('OPENAI_API_KEY');

    if (!$apiKey) {
        throw new Exception('OPENAI_API_KEY is not configured.');
    }

    $url = "https://api.openai.com/v1/responses";

    $promptText = "
Classify this industrial part.

Return JSON with:
- unspsc (8-digit)
- hs_code (6-digit)
- confidence (low, medium, high)

Rules:
- Use valid codes
- Never return empty values
- Prefer broader category if unsure
- Return only the JSON. Do not explain.

Examples:

HEX BOLT → {\"unspsc\":\"31161600\",\"hs_code\":\"731815\",\"confidence\":\"high\"}

Part:

Description: {$part['vision_name']}
Category: {$part['category2']}
Manufacturer: {$part['category1']}
";

    $payload = [
        "model" => "gpt-5",
        "max_output_tokens" => 150,
        "reasoning" => [
            "effort" => "minimal"
        ],
        "input" => [
            [
                "role" => "user",
                "content" => [
                    [
                        "type" => "input_text",
                        "text" => $promptText
                    ]
                ]
            ]
        ],
        "text" => [
            "verbosity" => "low",
            "format" => [
                "type" => "json_schema",
                "name" => "classification",
                "schema" => [
                    "type" => "object",
                    "properties" => [
                        "unspsc" => ["type" => "string"],
                        "hs_code" => ["type" => "string"],
                        "confidence" => [
                            "type" => "string",
                            "enum" => ["low", "medium", "high"]
                        ]
                    ],
                    "required" => ["unspsc", "hs_code", "confidence"],
                    "additionalProperties" => false
                ]
            ]
        ]
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);


    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception("OpenAI request failed: " . curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($response, true);

    // Extract structured JSON safely
    $json = $data['output'][0]['content'][0]['text'] ?? $data['output'][1]['content'][0]['text'] ?? '{}';

    return json_decode($json, true);
}

function hillen_run_ai_classification_batch() {

    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['hillen_run_ai_classification'])) return;

    set_time_limit(0);

    global $wpdb;

    $rows = $wpdb->get_results("
        SELECT 
            *
        FROM wp_canonical_parts p
        LEFT JOIN wp_canonical_rollup_cache r 
            ON p.id = r.canonical_part_id
        LEFT JOIN wp_canonical_category_cache c 
            ON p.id = c.canonical_part_id
        LEFT JOIN wp_canonical_part_classification cl
            ON cl.canonical_part_id = p.id
        WHERE cl.unspsc IS NULL
    ", ARRAY_A);

    foreach ($rows as $row) {

        $result = classifyPartWithAI(
            $row
        );


        $wpdb->query($wpdb->prepare("
            UPDATE wp_canonical_part_classification
                SET unspsc = %s,
                hs_code = %s,
                confidence = %s,
                status = 'pending'
            WHERE canonical_part_id = %d

        ",
            $result['unspsc'] ?? null,
            $result['hs_code'] ?? null,
            $result['confidence'] ?? 'low',
            $row['canonical_part_id']
        ));

        // Small delay to avoid rate limits
        usleep(200000); // 0.2 sec
    }

    echo "Batch complete";
    exit;
}

add_action('admin_init', 'hillen_run_ai_classification_batch');
