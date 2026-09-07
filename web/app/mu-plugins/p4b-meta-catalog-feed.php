<?php
/**
 * Plugin Name: P4B Meta Catalog Feed
 * Description: Replaces Facebook-for-WooCommerce product sync with static catalog feeds (primary NL feed + language/country override feeds) in uploads/meta-feeds/, and aligns pixel/CAPI content_ids with the feed ids. Run via WP-CLI: wp p4b-meta generate
 */

const P4B_META_DIR = 'meta-feeds';

// Locale used as the "override" value of each language feed.
// Meta accepted de_DE / it_IT / pl_PL but rejected en_GB / es_ES / fr_FR; those use the
// country-agnostic xx_XX form instead (verified in Commerce Manager, 2026-09-01).
const P4B_META_LANG_LOCALE = [
    'de' => 'de_DE',
    'en' => 'en_XX',
    'es' => 'es_XX',
    'fr' => 'fr_XX',
    'it' => 'it_IT',
    'pl' => 'pl_PL',
];

/* ---------------------------------------------------------------------------
 * 1. Neutralise the official plugin's product sync, keep its pixel + CAPI.
 * ------------------------------------------------------------------------- */

// Equivalent to setting every product to "Do not sync".
add_filter('wc_facebook_should_sync_product', '__return_false', 100);

add_filter('wc_facebook_is_product_sync_enabled', '__return_false', 100);

// Never let the plugin delete catalog items: with retailer ids now equal to feed
// SKUs, a plugin-initiated DELETE (e.g. "Do not sync" via the admin UI, or a product
// being trashed) would remove the feed-owned item. Returning null skips the request.
add_filter('wc_facebook_sync_background_item_delete_request', '__return_null', 100);

// Pixel + Conversions API content_ids must equal the catalog item id (SKU),
// not the per-translation post id the plugin uses by default.
add_filter('wc_facebook_fb_retailer_id', function ($retailer_id, $product) {
    if ($product instanceof WC_Product && function_exists('p4b_catalog_item_id')) {
        return p4b_catalog_item_id($product);
    }
    return $retailer_id;
}, 10, 2);

/* ---------------------------------------------------------------------------
 * 1b. Fix server-side Purchase timing.
 * The plugin sends its Conversions API Purchase on woocommerce_new_order, which
 * HPOS fires before the order line items are persisted, so the server event has
 * no content_ids; Meta then dedupes the (complete) browser event against it.
 * Re-hook the plugin's own method to run once items exist, on the Blocks and
 * classic checkout hooks. Both run inside the customer's own checkout request,
 * so client IP / user agent / fbp cookies are the shopper's. Deliberately NOT
 * hooked on woocommerce_payment_complete: that fires inside Mollie/Stripe
 * webhook requests or Action Scheduler runs, where the plugin would attach the
 * payment provider's user agent (or none) to the Purchase.
 * The tracker's once-per-context guard (order meta) prevents duplicates.
 * ------------------------------------------------------------------------- */
add_action('init', function () {
    global $wp_filter;
    if (empty($wp_filter['woocommerce_new_order']) || !class_exists('WC_Facebookcommerce_EventsTracker')) {
        return;
    }
    $tracker = null;
    foreach ($wp_filter['woocommerce_new_order']->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $cb) {
            $fn = $cb['function'];
            if (is_array($fn) && ($fn[0] ?? null) instanceof WC_Facebookcommerce_EventsTracker && ($fn[1] ?? '') === 'inject_purchase_event') {
                $tracker = $fn[0];
                remove_action('woocommerce_new_order', $fn, $priority);
            }
        }
    }
    if (!$tracker) {
        return;
    }
    $fire = function ($order) use ($tracker) {
        $order_id = $order instanceof WC_Order ? $order->get_id() : (int) $order;
        if ($order_id) {
            $tracker->inject_purchase_event($order_id);
        }
    };
    add_action('woocommerce_store_api_checkout_order_processed', $fire, 20);
    add_action('woocommerce_checkout_order_processed', $fire, 20);
}, 20);

/* ---------------------------------------------------------------------------
 * 2. Feed generation.
 * ------------------------------------------------------------------------- */

function p4b_meta_availability(string $stock): string {
    switch ($stock) {
        case 'instock':
            return 'in stock';
        case 'onbackorder':
            return 'available for order';
        default:
            return 'out of stock';
    }
}

/** CSV row with every field quoted (ids like "SKU-95036+0,5" contain commas). */
function p4b_meta_csv_row($fh, array $fields): void {
    $quoted = array_map(function ($v) {
        return '"' . str_replace('"', '""', (string) $v) . '"';
    }, $fields);
    fwrite($fh, implode(',', $quoted) . "\n");
}

function p4b_meta_feed_url(string $file): string {
    return p4b_catalog_feed_url(P4B_META_DIR, $file);
}

/** Primary feed: full item data in the default language (NL) with NL pricing. */
function p4b_meta_generate_primary(): array {
    $lang    = pll_default_language();
    $country = P4B_CATALOG_LANG_COUNTRY[$lang];
    [$x, $tmp, $final] = p4b_catalog_open_rss(
        p4b_catalog_feed_path(P4B_META_DIR, 'meta-catalog.xml'),
        'Parts4Boats',
        pll_home_url($lang)
    );
    $result = p4b_catalog_each_item($lang, $country, function (array $item) use ($x) {
        $x->startElement('item');
        $x->writeElement('g:id', $item['id']);
        $x->writeElement('g:title', $item['title']);
        $x->writeElement('g:description', $item['description']);
        $x->writeElement('g:availability', p4b_meta_availability($item['stock']));
        $x->writeElement('g:condition', 'new');
        $x->writeElement('g:price', p4b_catalog_money($item['regular']));
        if ($item['sale'] !== null) {
            $x->writeElement('g:sale_price', p4b_catalog_money($item['sale']));
        }
        $x->writeElement('g:link', $item['link']);
        $x->writeElement('g:image_link', $item['image']);
        if ($item['brand']) {
            $x->writeElement('g:brand', $item['brand']);
            $x->writeElement('g:mpn', $item['id']);
        }
        if ($item['gtin']) {
            $x->writeElement('g:gtin', $item['gtin']);
        }
        if ($item['group_id']) {
            $x->writeElement('g:item_group_id', $item['group_id']);
        }
        if ($item['product_type']) {
            $x->writeElement('g:product_type', $item['product_type']);
        }
        $x->endElement();
    });
    p4b_catalog_close_rss($x, $tmp, $final);
    return $result;
}

/**
 * Supplementary feeds for one language, written as CSV (Meta's documented
 * format for localized feeds):
 *  - language feed: id, override (locale), title, description, link
 *  - country feed:  id, override (country), price, sale_price, availability, link
 * Price/availability may only be overridden per country, texts only per language.
 */
function p4b_meta_generate_overrides(string $lang, string $country): array {
    $locale = P4B_META_LANG_LOCALE[$lang];
    $default_country = P4B_CATALOG_LANG_COUNTRY[pll_default_language()];
    $write_country = $country !== $default_country; // en shares NL pricing: no country feed

    $lang_final = p4b_catalog_feed_path(P4B_META_DIR, 'meta-language-' . $lang . '.csv');
    $lang_fh    = fopen($lang_final . '.tmp', 'w');
    p4b_meta_csv_row($lang_fh, ['id', 'override', 'title', 'description', 'link']);

    $country_fh = null;
    if ($write_country) {
        $country_final = p4b_catalog_feed_path(P4B_META_DIR, 'meta-country-' . $country . '.csv');
        $country_fh    = fopen($country_final . '.tmp', 'w');
        p4b_meta_csv_row($country_fh, ['id', 'override', 'price', 'sale_price', 'availability', 'link']);
    }

    $result = p4b_catalog_each_item($lang, $country, function (array $item) use ($lang_fh, $country_fh, $locale, $country) {
        p4b_meta_csv_row($lang_fh, [$item['id'], $locale, $item['title'], $item['description'], $item['link']]);
        if ($country_fh) {
            p4b_meta_csv_row($country_fh, [
                $item['id'],
                $country,
                p4b_catalog_money($item['regular']),
                $item['sale'] !== null ? p4b_catalog_money($item['sale']) : '',
                p4b_meta_availability($item['stock']),
                $item['link'],
            ]);
        }
    });

    fclose($lang_fh);
    rename($lang_final . '.tmp', $lang_final);
    if ($country_fh) {
        fclose($country_fh);
        rename($country_final . '.tmp', $country_final);
    }
    return $result;
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('p4b-meta', function ($args, $assoc_args) {
        if (!function_exists('wc_get_product') || !function_exists('pll_home_url')) {
            WP_CLI::error('WooCommerce and Polylang must be active.');
        }
        [$written, $skipped] = p4b_meta_generate_primary();
        WP_CLI::log(sprintf('primary (%s): %d items, %d skipped -> %s', pll_default_language(), $written, $skipped, p4b_meta_feed_url('meta-catalog.xml')));

        foreach (P4B_META_LANG_LOCALE as $lang => $locale) {
            $country = P4B_CATALOG_LANG_COUNTRY[$lang];
            [$written, $skipped] = p4b_meta_generate_overrides($lang, $country);
            WP_CLI::log(sprintf('%s: %d items -> %s', $lang, $written, p4b_meta_feed_url('meta-language-' . $lang . '.csv')));
            if ($country !== P4B_CATALOG_LANG_COUNTRY[pll_default_language()]) {
                WP_CLI::log(sprintf('   country %s -> %s', $country, p4b_meta_feed_url('meta-country-' . $country . '.csv')));
            }
        }
        if (!empty($GLOBALS['p4b_catalog_duplicate_ids'])) {
            WP_CLI::warning('Duplicate ids skipped: ' . implode(', ', $GLOBALS['p4b_catalog_duplicate_ids']));
        }
        WP_CLI::success('Meta feeds generated.');
    });
}
