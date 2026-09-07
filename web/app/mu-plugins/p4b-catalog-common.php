<?php
/**
 * Plugin Name: P4B Catalog Common
 * Description: Shared product-feed helpers used by the Google Merchant and Meta catalog feed plugins. Loads first (alphabetical).
 */

// Target country per Polylang language; determines the VAT rate applied to feed prices.
// The .com (en) store has no single country; it defaults to NL pricing.
const P4B_CATALOG_LANG_COUNTRY = [
    'nl' => 'NL',
    'de' => 'DE',
    'en' => 'NL',
    'es' => 'ES',
    'fr' => 'FR',
    'it' => 'IT',
    'pl' => 'PL',
];

const P4B_CATALOG_CURRENCY = 'EUR';

// Default lead time used for backorder items (Google requires an availability date).
const P4B_CATALOG_BACKORDER_LEAD_DAYS = 30;

function p4b_catalog_feed_path(string $subdir, string $file): string {
    $dir = wp_upload_dir()['basedir'] . '/' . $subdir;
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir . '/' . $file;
}

function p4b_catalog_feed_url(string $subdir, string $file): string {
    return wp_upload_dir()['baseurl'] . '/' . $subdir . '/' . $file;
}

/**
 * Language-independent item id shared by all feeds AND the Meta pixel/CAPI
 * (via the wc_facebook_fb_retailer_id filter). SKUs are synced across Polylang
 * translations, so the same physical product has one id on every domain.
 * Fallback: the default-language post id, so translations still collapse to one id.
 */
function p4b_catalog_item_id($product): string {
    $sku = $product->get_sku('edit');
    if ($sku !== '' && $sku !== null) {
        return (string) $sku;
    }
    $id = $product->get_id();
    if (function_exists('pll_get_post')) {
        $default = pll_get_post($id, pll_default_language());
        if ($default) {
            $id = $default;
        }
    }
    return 'wc_' . $id;
}

/**
 * Price as displayed to a visitor from $country: strip the base (NL) VAT the
 * stored price includes, then apply the target country's rate. Mirrors
 * WooCommerce's own display math for prices-include-tax stores.
 */
function p4b_catalog_price_for_country($product, $raw_price, string $country): ?float {
    if ($raw_price === '' || $raw_price === null || !is_numeric($raw_price)) {
        return null;
    }
    $price = (float) $raw_price;
    if ($product->is_taxable() && wc_prices_include_tax()) {
        $base_rates = WC_Tax::get_base_tax_rates($product->get_tax_class());
        $excl       = $price - array_sum(WC_Tax::calc_tax($price, $base_rates, true));
        $rates      = WC_Tax::find_rates([
            'country'   => $country,
            'tax_class' => $product->get_tax_class(),
        ]);
        $price = $excl + array_sum(WC_Tax::calc_tax($excl, $rates, false));
    }
    return round($price, 2);
}

function p4b_catalog_money(float $amount): string {
    return number_format($amount, 2, '.', '') . ' ' . P4B_CATALOG_CURRENCY;
}

function p4b_catalog_clean_text(string $text, int $max): string {
    $text = strip_shortcodes($text);
    $text = wp_strip_all_tags($text, true);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    // Never emit line breaks: feeds are single-line records (CSV rows, XML text).
    $text = str_replace(["\r", "\n", "\xE2\x80\xA8", "\xE2\x80\xA9", "\xC2\x85"], ' ', $text);
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    return mb_substr($text, 0, $max);
}

/** Category path (e.g. "Electra > Startmotoren") in the product's own language. */
function p4b_catalog_product_type(int $product_id): string {
    $terms = get_the_terms($product_id, 'product_cat');
    if (!$terms || is_wp_error($terms)) {
        return '';
    }
    $best = null;
    $best_depth = -1;
    foreach ($terms as $term) {
        $depth = count(get_ancestors($term->term_id, 'product_cat'));
        if ($depth > $best_depth) {
            $best_depth = $depth;
            $best = $term;
        }
    }
    $parts = [];
    foreach (array_reverse(get_ancestors($best->term_id, 'product_cat')) as $ancestor_id) {
        $ancestor = get_term($ancestor_id, 'product_cat');
        if ($ancestor && !is_wp_error($ancestor)) {
            $parts[] = $ancestor->name;
        }
    }
    $parts[] = $best->name;
    return implode(' > ', $parts);
}

/** Published product ids in one Polylang language. */
function p4b_catalog_product_ids(string $lang): array {
    return get_posts([
        'post_type'        => 'product',
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'suppress_filters' => false,
        'lang'             => $lang,
    ]);
}

/**
 * Normalised feed data for one sellable item (simple product or variation).
 * Returns null when the item cannot be listed (no image or no price).
 */
function p4b_catalog_item_data($product, $parent, string $country): ?array {
    $for_data = $parent ?: $product; // brand, categories, description live on the parent

    $image_id = $product->get_image_id() ?: $for_data->get_image_id();
    $image    = $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
    if (!$image) {
        return null;
    }

    $regular = p4b_catalog_price_for_country($product, $product->get_regular_price(), $country);
    if ($regular === null || $regular <= 0) {
        return null;
    }
    $sale = $product->is_on_sale() ? p4b_catalog_price_for_country($product, $product->get_sale_price(), $country) : null;
    if ($sale !== null && ($sale <= 0 || $sale >= $regular)) {
        $sale = null;
    }

    $title = $for_data->get_name();
    if ($parent) {
        $summary = wc_get_formatted_variation($product, true, false);
        if ($summary) {
            $title .= ' - ' . $summary;
        }
    }
    $description = $for_data->get_short_description() ?: $for_data->get_description() ?: $title;

    $brand = $for_data->get_attribute('pa_merk');
    if ($brand) {
        $brand = trim(explode(',', $brand)[0]);
    }
    $gtin = method_exists($product, 'get_global_unique_id') ? (string) $product->get_global_unique_id() : '';

    $weight = $product->get_weight();

    return [
        'id'           => p4b_catalog_item_id($product),
        'title'        => p4b_catalog_clean_text($title, 150),
        'description'  => p4b_catalog_clean_text($description, 5000),
        'link'         => $product->get_permalink(),
        'image'        => $image,
        'stock'        => $product->get_stock_status(), // instock | onbackorder | outofstock
        'regular'      => $regular,
        'sale'         => $sale,
        'brand'        => $brand ?: '',
        'gtin'         => $gtin,
        'group_id'     => $parent ? p4b_catalog_item_id($parent) : null,
        'product_type' => p4b_catalog_product_type($for_data->get_id()),
        'weight'       => ($weight && (float) $weight > 0) ? wc_format_decimal($weight, 3) . ' ' . get_option('woocommerce_weight_unit', 'kg') : null,
    ];
}

/**
 * Iterate every listable item of a language, calling $cb(array $item) for each.
 * Returns [written, skipped].
 */
function p4b_catalog_each_item(string $lang, string $country, callable $cb): array {
    $written = 0;
    $skipped = 0;
    $count   = 0;
    $seen    = [];
    $GLOBALS['p4b_catalog_duplicate_ids'] = [];
    foreach (p4b_catalog_product_ids($lang) as $id) {
        $product = wc_get_product($id);
        if (!$product || $product->get_catalog_visibility() === 'hidden' || !$product->is_purchasable()) {
            $skipped++;
            continue;
        }
        $units = [];
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if ($variation && $variation->is_purchasable()) {
                    $units[] = [$variation, $product];
                } else {
                    $skipped++;
                }
            }
        } else {
            $units[] = [$product, null];
        }
        foreach ($units as [$unit, $parent]) {
            $item = p4b_catalog_item_data($unit, $parent, $country);
            if (!$item) {
                $skipped++;
            } elseif (isset($seen[$item['id']])) {
                // Feeds reject duplicate ids; keep the first, report the rest.
                $GLOBALS['p4b_catalog_duplicate_ids'][] = $item['id'] . ' (post ' . $unit->get_id() . ')';
                $skipped++;
            } else {
                $seen[$item['id']] = true;
                $cb($item);
                $written++;
            }
        }
        if (++$count % 500 === 0 && function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }
    return [$written, $skipped];
}

/** Open an RSS 2.0 / Google-namespace feed writer on a temp file; returns [XMLWriter, tmp, final]. */
function p4b_catalog_open_rss(string $final, string $title, string $link): array {
    $tmp = $final . '.tmp';
    $x = new XMLWriter();
    $x->openUri($tmp);
    $x->startDocument('1.0', 'UTF-8');
    $x->setIndent(true);
    $x->startElement('rss');
    $x->writeAttribute('version', '2.0');
    $x->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
    $x->startElement('channel');
    $x->writeElement('title', $title);
    $x->writeElement('link', $link);
    $x->writeElement('description', 'Product feed');
    return [$x, $tmp, $final];
}

function p4b_catalog_close_rss(XMLWriter $x, string $tmp, string $final): void {
    $x->endElement(); // channel
    $x->endElement(); // rss
    $x->endDocument();
    $x->flush();
    unset($x);
    rename($tmp, $final);
}
