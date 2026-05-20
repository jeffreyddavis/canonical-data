<?php

/**
 * Scrape Millennium Dealer Portal tire products into a variant matrix.
 *
 * Source:
 * https://dealer.millenniumelastomers.com/collections/tires/products.json
 *
 * Usage:
 * php scripts/scrape_millennium_tires.php
 */

$sourceUrl = 'https://dealer.millenniumelastomers.com/collections/tires/products.json';
$root = dirname(__DIR__);
$csvPath = $root . DIRECTORY_SEPARATOR . 'millennium_tire_variants.csv';
$jsonPath = $root . DIRECTORY_SEPARATOR . 'millennium_tire_variants.json';

$payload = millennium_fetch_json($sourceUrl);

if (!isset($payload['products']) || !is_array($payload['products'])) {
    fwrite(STDERR, "Unexpected Shopify response.\n");
    exit(1);
}

$rows = [];

foreach ($payload['products'] as $product) {
    $details = millennium_extract_product_details($product);
    $variants = $product['variants'] ?? [];

    foreach ($variants as $variant) {
        $compound = trim((string) ($variant['option1'] ?? ''));
        $treadTreatment = trim((string) ($variant['option2'] ?? ''));
        $variantSku = trim((string) ($variant['sku'] ?? ''));
        $price = millennium_normalize_shopify_price($variant['price'] ?? null);

        $rows[] = [
            'product_id' => $product['id'] ?? null,
            'product_handle' => $product['handle'] ?? '',
            'product_title' => $product['title'] ?? '',
            'product_url' => 'https://dealer.millenniumelastomers.com/products/' . ($product['handle'] ?? ''),
            'size' => $details['size'],
            'outside_diameter' => $details['outside_diameter'],
            'tread_width' => $details['tread_width'],
            'inside_diameter' => $details['inside_diameter'],
            'manufacturer_part_numbers' => implode(' | ', $details['manufacturer_part_numbers']),
            'millennium_part_number' => $details['millennium_part_number'],
            'other_part_numbers' => implode(' | ', $details['other_part_numbers']),
            'variant_id' => $variant['id'] ?? null,
            'variant_title' => $variant['title'] ?? '',
            'variant_sku' => $variantSku,
            'compound' => $compound,
            'tread_treatment' => $treadTreatment,
            'price' => $price,
            'available' => !empty($variant['available']) ? 'Y' : 'N',
        ];
    }
}

usort($rows, function ($a, $b) {
    return [$a['millennium_part_number'], $a['compound'], $a['tread_treatment']]
        <=> [$b['millennium_part_number'], $b['compound'], $b['tread_treatment']];
});

millennium_write_csv($csvPath, $rows);
file_put_contents(
    $jsonPath,
    json_encode([
        'source_url' => $sourceUrl,
        'scraped_at_utc' => gmdate('c'),
        'product_count' => count($payload['products']),
        'variant_count' => count($rows),
        'rows' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Products: " . count($payload['products']) . PHP_EOL;
echo "Variants: " . count($rows) . PHP_EOL;
echo "CSV: $csvPath" . PHP_EOL;
echo "JSON: $jsonPath" . PHP_EOL;

function millennium_fetch_json($url) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'header' => implode("\r\n", [
                'Accept: application/json',
                'User-Agent: canonical-data-millennium-tire-scraper/1.0',
            ]),
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException("Unable to fetch $url");
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON from $url");
    }

    return $data;
}

function millennium_extract_product_details($product) {
    $body = html_entity_decode(strip_tags((string) ($product['body_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = preg_replace('/\s+/', ' ', $body);
    $body = trim($body);

    $size = millennium_extract_after_label($body, 'Size:', [
        'Manufacturer Part Numbers:',
        'Millennium Part Number:',
        'Other Part Numbers:',
    ]);
    $manufacturerParts = millennium_extract_after_label($body, 'Manufacturer Part Numbers:', [
        'Millennium Part Number:',
        'Other Part Numbers:',
    ]);
    $millenniumPart = millennium_extract_after_label($body, 'Millennium Part Number:', [
        'Other Part Numbers:',
    ]);
    $otherParts = millennium_extract_after_label($body, 'Other Part Numbers:', []);

    $sizeParts = millennium_parse_tire_size($size);

    return [
        'size' => $size,
        'outside_diameter' => $sizeParts['outside_diameter'],
        'tread_width' => $sizeParts['tread_width'],
        'inside_diameter' => $sizeParts['inside_diameter'],
        'manufacturer_part_numbers' => millennium_split_part_numbers($manufacturerParts),
        'millennium_part_number' => trim($millenniumPart),
        'other_part_numbers' => millennium_split_part_numbers($otherParts),
    ];
}

function millennium_extract_after_label($text, $label, $nextLabels) {
    $start = stripos($text, $label);
    if ($start === false) {
        return '';
    }

    $start += strlen($label);
    $end = strlen($text);

    foreach ($nextLabels as $nextLabel) {
        $pos = stripos($text, $nextLabel, $start);
        if ($pos !== false && $pos < $end) {
            $end = $pos;
        }
    }

    return trim(substr($text, $start, $end - $start), " \t\n\r\0\x0B:-");
}

function millennium_split_part_numbers($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/\s*,\s*|\s*\|\s*/', $value);
    $parts = array_map(fn($part) => trim($part), $parts);

    return array_values(array_unique(array_filter($parts)));
}

function millennium_parse_tire_size($size) {
    $clean = preg_replace('/\([^)]*\)/', '', (string) $size);
    $clean = preg_replace('/\s+/', ' ', trim($clean));

    $dimension = '(\d+(?:\.\d+)?(?:\s+\d+\/\d+|\s+1\/2)?)';
    $pattern = '/^' . $dimension . '\s*x\s*' . $dimension . '\s*x\s*' . $dimension . '/i';

    if (!preg_match($pattern, $clean, $matches)) {
        return [
            'outside_diameter' => '',
            'tread_width' => '',
            'inside_diameter' => '',
        ];
    }

    return [
        'outside_diameter' => millennium_dimension_to_decimal($matches[1]),
        'tread_width' => millennium_dimension_to_decimal($matches[2]),
        'inside_diameter' => millennium_dimension_to_decimal($matches[3]),
    ];
}

function millennium_dimension_to_decimal($value) {
    $value = trim((string) $value);

    if (preg_match('/^(\d+(?:\.\d+)?)\s+(\d+)\/(\d+)$/', $value, $matches)) {
        return number_format((float) $matches[1] + ((float) $matches[2] / (float) $matches[3]), 4, '.', '');
    }

    if (is_numeric($value)) {
        return number_format((float) $value, 4, '.', '');
    }

    return '';
}

function millennium_normalize_shopify_price($value) {
    if ($value === null || $value === '') {
        return '';
    }

    if (is_string($value) && strpos($value, '.') !== false) {
        return number_format((float) $value, 2, '.', '');
    }

    if (is_numeric($value)) {
        $number = (float) $value;
        if ($number >= 1000) {
            return number_format($number / 100, 2, '.', '');
        }

        return number_format($number, 2, '.', '');
    }

    return '';
}

function millennium_write_csv($path, $rows) {
    $handle = fopen($path, 'w');
    if (!$handle) {
        throw new RuntimeException("Unable to write $path");
    }

    if (empty($rows)) {
        fclose($handle);
        return;
    }

    fputcsv($handle, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);
}
