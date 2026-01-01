<?php
// update_prices_full.php
// Run: php update_prices_full.php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

set_time_limit(0);
ini_set('memory_limit', '1024M');

// ---------------- CONFIG ----------------
$shop = "zorba-diamond.myshopify.com";
$access_token = "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";

$headers = [
    "Content-Type: application/json",
    "X-Shopify-Access-Token: $access_token"
];


$test_mode = false;                     // true = dry-run; set false to write
$per_page = 250;                       // Shopify REST max
$start_from_page = 12;                  // change to resume from specific page
$sleep_between_requests_us = 2000;   // 200ms between requests
$excel_file = __DIR__ . "/stone_carat_price.xlsx";
$csv_log_file = __DIR__ . "/price_updates.csv";
$zero_price_csv =  __DIR__ . "/products_with_zero_price_components.csv";

$zero_csv_handle = fopen($zero_price_csv, 'a');

$draft_csv_file = __DIR__ . "/products_forced_to_draft.csv";
$draft_csv_handle = fopen($draft_csv_file, 'a');

if ($draft_csv_handle && ftell($draft_csv_handle) === 0) {
    fputcsv($draft_csv_handle, [
        'product_id',
        'product_title',
        'product_url',
        'diamond_weight'
    ]);
}

/*
if ($zero_csv_handle && ftell($zero_csv_handle) == 0) {
    fputcsv($zero_csv_handle, [
        'product_id',
        'product_title',
        'product_url',
        'metal_price_total',
        'diamond_price_total',
        'labour_charge'
    ]);
}
*/
// --------------------------------------
/* ================= DIAMOND HELPERS ================= */

// Shape rule:
// blank OR round => round
// everything else => fancy
function normalize_diamond_shape_final($shape_raw) {
    $shape = normalize_value($shape_raw);
    if ($shape === '' || $shape === 'round') {
        return 'round';
    }
    return 'fancy';
}

// Weight rule:
// 0.03–0.039 => use 0.03 slab
// >1.49 => force draft
function normalize_diamond_weight_final($weight) {
    if ($weight >= 0.03 && $weight < 0.04) {
        return 0.03;
    }
    return $weight;
}

function set_product_status_draft($product_id, $shop, $headers) {
    shopify_rest_json(
        "https://$shop/admin/api/2024-01/products/$product_id.json",
        $headers,
        'PUT',
        [
            'product' => [
                'id' => $product_id,
                'status' => 'draft'
            ]
        ]
    );
}

// ---------------- UTILITIES --------------
function shopify_rest_request($url, $headers, $method = 'GET', $body = null, $return_headers = false) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($return_headers) curl_setopt($ch, CURLOPT_HEADER, true);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['body' => $resp, 'err' => $err, 'info' => $info];
}

function shopify_rest_json($url, $headers, $method = 'GET', $payload = null) {
    // $payload expected as PHP array (will be json-encoded)
    $body = $payload ? json_encode($payload) : null;
    $resp = shopify_rest_request($url, $headers, $method, $body);
    if ($resp['err']) return ['error' => $resp['err']];
    return ['http_info' => $resp['info'], 'data' => json_decode($resp['body'], true)];
}

function shopify_graphql($query, $variables = null, $headers, $shop) {
    // $query: GraphQL string
    // $variables: PHP array or null
    $payload = $variables === null ? json_encode(['query' => $query]) : json_encode(['query' => $query, 'variables' => $variables]);
    $url = "https://$shop/admin/api/2024-01/graphql.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    return json_decode($resp, true);
}
// -----------------------------------------

// ---------------- NORMALIZERS -------------
function normalize_value($value) {
    $v = strtolower(trim((string)$value));
    $v = str_replace(["\t","\n","\r"], '', $v);
    $v = preg_replace('/\s+/', '', $v);
    $v = str_replace(['–','—'], '-', $v);
    $v = preg_replace('/-+/', '-', $v);
    $v = trim($v, '-');
    return $v;
}
function normalize_clarity($value) {
    $v = normalize_value($value);
    if (preg_match('/vvs.*vs/', $v)) return 'vvs-vs';
    return $v;
}
// -----------------------------------------

// ---------------- RATE LOADERS ------------
function load_diamond_rates_from_excel($file) {
    $sheet = IOFactory::load($file)->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
    $rates = [];
    foreach ($rows as $i => $row) {
        if ($i == 1) continue; // header
        $rates[] = [
            'shape'   => strtolower(trim($row['B'] ?? '')),
            'quality' => strtolower(trim($row['C'] ?? '')),
            'color'   => strtolower(trim($row['D'] ?? '')),
            'from'    => floatval($row['E'] ?? 0),
            'to'      => floatval($row['F'] ?? 0),
            'rate'    => floatval($row['H'] ?? 0) // round off per carat
        ];
    }
    return $rates;
}

function fetch_metaobjects_by_type_all($type, $headers, $shop) {
    $query = <<<GQL
    {
      metaobjects(first: 250, type: "$type") {
        edges {
          node {
            id
            fields { key value }
          }
        }
      }
    }
    GQL;
    $res = shopify_graphql($query, null, $headers, $shop);
    $rates = [];
    if (!isset($res['data']['metaobjects']['edges'])) return $rates;
    foreach ($res['data']['metaobjects']['edges'] as $edge) {
        $fields = [];
        foreach ($edge['node']['fields'] as $f) {
            $fields[strtolower(trim($f['key']))] = strtolower(trim($f['value']));
        }
        if ($type === "metalrate") {
            $key = ($fields['metal_type'] ?? '') . "|" . ($fields['metal_tone'] ?? '') . "|" . ($fields['metal_karat'] ?? '');
            $rates[$key] = floatval($fields['metal_rate'] ?? 0);
        }
        if ($type === "colorstonerate") {
            $key = ($fields['color_stone_type'] ?? '') . "|" . ($fields['color_stone_shape'] ?? '') . "|" . ($fields['color_stone_color'] ?? '');
            $rates[$key] = floatval($fields['color_stone_rate'] ?? 0);
        }
    }
    return $rates;
}
// -----------------------------------------

// ------------- GRAPHQL METAOBJECT UPDATE (helper) -------------
function update_metaobject_field($gid, $key, $value_json_literal, $shop, $headers) {
    // value_json_literal must be a JSON literal (e.g. json_encode("123.45") or "null")
    $mutation = <<<GQL
    mutation {
      metaobjectUpdate(id: "$gid", metaobject: {
        fields: [
          { key: "$key", value: $value_json_literal }
        ]
      }) {
        metaobject { id }
        userErrors { field message }
      }
    }
    GQL;
    return shopify_graphql($mutation, null, $headers, $shop);
}
// ------------- GRAPHQL PRODUCT METAFIELDS -------------
function update_product_metafields_graphql($product_gid, $metafields_array, $shop, $headers) {
    $parts = [];
    foreach ($metafields_array as $m) {
        $val_json = json_encode($m['value']);
        $parts[] = sprintf('{
            ownerId: "%s",
            namespace: "%s",
            key: "%s",
            type: "%s",
            value: %s
        }', $product_gid, $m['namespace'], $m['key'], $m['type'], $val_json);
    }
    $mf_block = implode(",", $parts);
    $mutation = "mutation { metafieldsSet(metafields: [ $mf_block ]) { metafields { id } userErrors { field message } } }";
    return shopify_graphql($mutation, null, $GLOBALS['headers'], $GLOBALS['shop']);
}
// -----------------------------------------

// -------------- Variant Price (REST) ---------------
function update_variant_price_rest($variant_id, $price, $shop, $headers) {
    // Correct REST update: include id and price
    $url = "https://$shop/admin/api/2024-01/variants/$variant_id.json";
    $payload = [
        'variant' => [
            'id' => $variant_id,
            'price' => number_format($price, 2, '.', '')
        ]
    ];
    return shopify_rest_json($url, $headers, 'PUT', $payload);
}
// -----------------------------------------

// ------------- PROCESS PRODUCT -------------
function process_product($product, $headers, $shop, $metalRates, $colorStoneRates, $diamondRates, $test_mode, $csv_handle, $sleep_between_requests_us) {

    $product_id = $product['id'];
    $product_title = $product['title'] ?? '';
    $variants = $product['variants'] ?? [];
    $variant_id = $variants[0]['id'] ?? null;
    $product_gid = "gid://shopify/Product/{$product_id}";
	$product_url = "https://{$shop}/products/" . ($product['handle'] ?? '');
	$zero_price_csv =  __DIR__ . "/products_with_zero_price_components.csv";
	//echo $product_id.'<br>';

//$zero_csv_handle = fopen($zero_price_csv, 'a');
 //   echo "Processing product: {$product_title} (ID {$product_id})\n";
	
	$draft_csv_file = __DIR__ . "/products_forced_to_draft.csv";
$draft_csv_handle = fopen($draft_csv_file, 'a');



    // fetch product metafields
    $mf_resp = shopify_rest_json("https://$shop/admin/api/2024-01/products/{$product_id}/metafields.json", $headers);
    if (isset($mf_resp['error'])) {
        echo "Error fetching metafields for product {$product_id}: " . $mf_resp['error'] . "\n";
        return;
    }
    $metafields = $mf_resp['data']['metafields'] ?? [];

    $metal_meta = $diamond_meta = $stone_meta = null;
	$metal_total_existing = null;
$diamond_total_existing = null;
    $labour_charge = 0.0;
    foreach ($metafields as $mf) {
        if (($mf['namespace'] ?? '') !== 'custom') continue;
      /*  if (($mf['key'] ?? '') === 'metal_data') $metal_meta = $mf;
        if (($mf['key'] ?? '') === 'diamond_details_data') $diamond_meta = $mf;
        if (($mf['key'] ?? '') === 'color_stone_data') $stone_meta = $mf;
        if (($mf['key'] ?? '') === 'labour_charge') $labour_charge = floatval($mf['value'] ?? 0);
	   */
	   /* ==== Temporary Script STARTED ========== */
	    if ($mf['key'] === 'metal_data') $metal_meta = $mf;
    if ($mf['key'] === 'diamond_details_data') $diamond_meta = $mf;
    if ($mf['key'] === 'color_stone_data') $stone_meta = $mf;
		 if ($mf['key'] === 'labour_charge') {
        $labour_charge = floatval($mf['value'] ?? 0);
		}

		if ($mf['key'] === 'metal_price_total') {
			$metal_total_existing = floatval($mf['value'] ?? 0);
		}

		if ($mf['key'] === 'diamond_price_total') {
			$diamond_total_existing = floatval($mf['value'] ?? 0);
		}
		/* ==== Temporary Script ENDED ========== */
    }
	/* ==== Temporary Script STARTED ========== */
	/* if (
    ($metal_total_existing ?? 0) == 0 ||
    ($diamond_total_existing ?? 0) == 0 ||
    $labour_charge == 0
) {
    if ($zero_csv_handle) {
        fputcsv($zero_csv_handle, [
            $product_id,
            $product_title,
            $product_url,
            number_format($metal_total_existing ?? 0, 2, '.', ''),
            number_format($diamond_total_existing ?? 0, 2, '.', ''),
            number_format($labour_charge, 2, '.', '')
        ]);
    }
}
		if (
			($metal_total_existing ?? 0) > 0 &&
			($diamond_total_existing ?? 0) > 0 &&
			$labour_charge > 0
		) {
			echo "Skipping product {$product_id} — prices already set\n";
			return; // 🚀 SKIP FULL CALCULATION
		}
		*/
		/* ==== Temporary Script ENDED ========== */
    $metal_price = $diamond_price = $stone_price = 0.0;

    // === METAL (metaobject refs)
    if (!empty($metal_meta['value'])) {
        $refs = json_decode($metal_meta['value'], true);
        if (is_array($refs)) {
            foreach ($refs as $ref_gid) {
                $gql = "{ metaobject(id: \"$ref_gid\") { id fields { key value } } }";
                $res = shopify_graphql($gql, null, $headers, $shop);
                usleep($sleep_between_requests_us);
                if (!isset($res['data']['metaobject'])) continue;
                $fields_map = [];
                foreach ($res['data']['metaobject']['fields'] as $f) {
                    $fields_map[strtolower($f['key'])] = strtolower(trim($f['value']));
                }

                $lookup = ($fields_map['metal_type'] ?? '') . "|" . ($fields_map['metal_tone'] ?? '') . "|" . ($fields_map['metal_karat'] ?? '');
                $rate = $metalRates[$lookup] ?? 0;
                $wt = floatval($fields_map['metal_weight'] ?? 0);
                $metalPrice = $rate * $wt;
                $metal_price += $metalPrice;

                // update metaobject metal_rate & metal_price (for traceability)
                if ($rate > 0 && !$test_mode) {
                    $val_json = json_encode((string)$rate);
                    $price_json = json_encode((string)number_format($metalPrice,2,'.',''));
                    update_metaobject_field($ref_gid, 'metal_rate', $val_json, $shop, $headers);
                    usleep($sleep_between_requests_us);
                    update_metaobject_field($ref_gid, 'metal_price', $price_json, $shop, $headers);
                    usleep($sleep_between_requests_us);
                }
            }
        }
    }

    // === DIAMOND
    if (!empty($diamond_meta['value'])) {
        $refs = json_decode($diamond_meta['value'], true);
        if (is_array($refs)) {
            foreach ($refs as $ref_gid) {
                $gql = "{ metaobject(id: \"$ref_gid\") { id fields { key value } } }";
                $res = shopify_graphql($gql, null, $headers, $shop);
                usleep($sleep_between_requests_us);
                if (!isset($res['data']['metaobject'])) continue;
                $fields_map = [];
                foreach ($res['data']['metaobject']['fields'] as $f) {
                    $fields_map[strtolower($f['key'])] = trim($f['value']);
                }

                $shape_raw   = $fields_map['diamond_shape'] ?? '';
				$clarity_raw = $fields_map['diamond_clarity'] ?? '';
				$color_raw   = $fields_map['diamond_color'] ?? '';
				$raw_weight  = floatval($fields_map['diamond_total_weight'] ?? 0);

				/* ===== WEIGHT > 1.49 → FORCE DRAFT ===== */
			/*	if ($raw_weight > 1.49) {

					echo "⚠ Weight {$raw_weight} > 1.49 — forcing product {$product_id} to DRAFT\n";

					if (!$test_mode) {
						set_product_status_draft($product_id, $shop, $headers);
					}

					if ($draft_csv_handle) {
						fputcsv($draft_csv_handle, [
							$product_id,
							$product_title,
							$product_url,
							$raw_weight
						]);
					}

					return; // ❌ STOP processing this product completely
				}
				*/

				/* ===== NORMALIZATION ===== */
				$shape   = normalize_diamond_shape_final($shape_raw);
				$clarity = normalize_clarity($clarity_raw);
				$color   = normalize_value($color_raw);
				$weight  = normalize_diamond_weight_final($raw_weight);

                $matched_rate = 0;
                foreach ($diamondRates as $r) {
                    $r_shape = normalize_value($r['shape']);
                    $r_clarity = normalize_clarity($r['quality']);
                    $r_color = normalize_value($r['color']);
                    if (
                        $shape === $r_shape &&
                        $clarity === $r_clarity &&
                        $color === $r_color &&
                        $weight >= $r['from'] &&
                        $weight <= $r['to']
                    ) {
                        $matched_rate = $r['rate'];
                        break;
                    }
                }

                if ($matched_rate > 0 && $weight > 0) {
                    $diamondPrice = $matched_rate * $weight;
                    $diamond_price += $diamondPrice;

                    if (!$test_mode) {
                        $rate_json = json_encode((string)$matched_rate);
                        $price_json = json_encode((string)number_format($diamondPrice,2,'.',''));
                        update_metaobject_field($ref_gid, 'diamond_rate', $rate_json, $shop, $headers);
                        usleep($sleep_between_requests_us);
                        update_metaobject_field($ref_gid, 'diamond_price', $price_json, $shop, $headers);
                        usleep($sleep_between_requests_us);
                    }
                }
            }
        }
    }

    // === COLOR STONE
    if (!empty($stone_meta['value'])) {
        $refs = json_decode($stone_meta['value'], true);
        if (is_array($refs)) {
            foreach ($refs as $ref_gid) {
                $gql = "{ metaobject(id: \"$ref_gid\") { id fields { key value } } }";
                $res = shopify_graphql($gql, null, $headers, $shop);
                usleep($sleep_between_requests_us);
                if (!isset($res['data']['metaobject'])) continue;
                $fields_map = [];
                foreach ($res['data']['metaobject']['fields'] as $f) {
                    $fields_map[strtolower($f['key'])] = strtolower(trim($f['value']));
                }

                $pcs = floatval($fields_map['color_stone_pcs'] ?? 0);
                $stonewt = floatval($fields_map['color_stone_weight_per_pcs'] ?? 0);
                $rate = floatval($fields_map['color_stone_rate'] ?? 0);

                $stonePiecePrice = $pcs * $stonewt * $rate;
                $stone_price += $stonePiecePrice;

                if ($rate > 0 && !$test_mode) {
                    $rate_json = json_encode((string)$rate);
                    update_metaobject_field($ref_gid, 'color_stone_rate', $rate_json, $shop, $headers);
                    usleep($sleep_between_requests_us);
                }
            }
        }
    }

    // compute final
    $final_price = $metal_price + $diamond_price + $stone_price + floatval($labour_charge);

    echo "Calculated — Metal: {$metal_price}, Diamond: {$diamond_price}, Stone: {$stone_price}, Labour: {$labour_charge}, Final: {$final_price}\n";

    // --- Update product metafields
    $metafields_to_set = [
        ['namespace' => 'custom', 'key' => 'metal_price_total', 'type' => 'number_decimal', 'value' => number_format($metal_price, 2, '.', '')],
        ['namespace' => 'custom', 'key' => 'diamond_price_total', 'type' => 'number_decimal', 'value' => number_format($diamond_price, 2, '.', '')],
        ['namespace' => 'custom', 'key' => 'stone_price_total', 'type' => 'number_decimal', 'value' => number_format($stone_price, 2, '.', '')],
        ['namespace' => 'custom', 'key' => 'final_price_total', 'type' => 'number_decimal', 'value' => number_format($final_price, 2, '.', '')]
    ];

    if (!$test_mode) {
        $resp = update_product_metafields_graphql($product_gid, $metafields_to_set, $shop, $headers);
        //echo "Metafields update response:\n"; print_r($resp);
        usleep($sleep_between_requests_us);
    } else {
        echo "[DRY] Would set product metafields for product {$product_id}\n";
    }

    // --- Update main variant price (REST) for first variant only
    if ($variant_id) {
        $price_str = number_format($final_price, 2, '.', '');
        if (!$test_mode) {
            $resp_v = update_variant_price_rest($variant_id, $final_price, $shop, $headers);
            //echo "Variant update response:\n"; print_r($resp_v);
            // check common errors:
            if (!empty($resp_v['data']['errors'])) {
                //echo "Variant update errors:\n"; print_r($resp_v['data']['errors']);
            } else {
                echo "Updated variant {$variant_id} -> {$price_str}\n";
            }
            usleep($sleep_between_requests_us);
        } else {
            echo "[DRY] Would update variant {$variant_id} to {$price_str}\n";
        }
    } else {
        echo "No variant found for product {$product_id}\n";
    }

    // CSV log
 /*   if ($csv_handle) {
        fputcsv($csv_handle, [
            date('c'),
            $product_id,
            $product_title,
            number_format($metal_price,2,'.',''),
            number_format($diamond_price,2,'.',''),
            number_format($stone_price,2,'.',''),
            number_format(floatval($labour_charge),2,'.',''),
            number_format($final_price,2,'.','')
        ]);
    }
	*/

    echo "--------------------------------------------------------------\n\n";
}
// -----------------------------------------

// ------------------ MAIN PAGINATION LOOP --------------------
if (!file_exists($excel_file)) {
    echo "ERROR: Excel file not found at {$excel_file}\n";
    exit(1);
}

$diamondRates = load_diamond_rates_from_excel($excel_file);
$metalRates = fetch_metaobjects_by_type_all("metalrate", $headers, $shop);
$colorStoneRates = fetch_metaobjects_by_type_all("colorstonerate", $headers, $shop);

echo "Loaded diamond rates: " . count($diamondRates) . " rows\n";
echo "Loaded metal rates: " . count($metalRates) . " entries\n";
echo "Loaded color stone rates: " . count($colorStoneRates) . " entries\n";

$csv_handle = fopen($csv_log_file, $test_mode ? 'w' : 'a');
if (!$csv_handle) {
    echo "Warning: could not open CSV log file at {$csv_log_file}. Continuing without CSV logging.\n";
} else {
    if (ftell($csv_handle) == 0) {
        fputcsv($csv_handle, ['timestamp','product_id','title','metal_price','diamond_price','stone_price','labour_charge','final_price']);
    }
}

$next_url = "https://$shop/admin/api/2024-01/products.json?limit={$per_page}";
$total_products_processed = 0;
$page = 0;

while ($next_url) {
    $page++;

    // SKIP PAGES if needed
    if ($page < $start_from_page) {
        echo "Skipping page {$page}...\n";
        $resp = shopify_rest_request($next_url, $headers, 'GET', null, true);
        if ($resp['err']) { echo "Error fetching page {$page}: {$resp['err']}\n"; break; }
        $headerSize = $resp['info']['header_size'] ?? 0;
        $headers_str = substr($resp['body'], 0, $headerSize);
        if (preg_match('/<([^>]+)>;\s*rel="next"/i', $headers_str, $m)) {
            $next_url = $m[1];
        } else {
            $next_url = null;
        }
        usleep($sleep_between_requests_us);
        continue;
    }

    echo "Fetching page {$page} -> {$next_url}\n";
    $resp = shopify_rest_request($next_url, $headers, 'GET', null, true);
    if ($resp['err']) { echo "Error fetching products page: {$resp['err']}\n"; break; }

    $headerSize = $resp['info']['header_size'] ?? 0;
    $headers_str = substr($resp['body'], 0, $headerSize);
    $body = substr($resp['body'], $headerSize);
    $data = json_decode($body, true);
    $products = $data['products'] ?? [];

    foreach ($products as $prod) {
        process_product($prod, $headers, $shop, $metalRates, $colorStoneRates, $diamondRates, $test_mode, $csv_handle, $sleep_between_requests_us);
        $total_products_processed++;
    }

    if (preg_match('/<([^>]+)>;\s*rel="next"/i', $headers_str, $m)) {
        $next_url = $m[1];
    } else {
        $next_url = null;
    }

    usleep($sleep_between_requests_us);
}
if ($draft_csv_handle) {
    fclose($draft_csv_handle);
}
if ($zero_csv_handle) { fclose($draft_csv_handle);}
if ($csv_handle) fclose($csv_handle);

echo "Done. Total products processed: {$total_products_processed}\n";
if ($test_mode) echo "NOTE: test_mode == true — no writes were made.\n";
