<?php
/**
 * Plugin Name: P4B Google Merchant Feed
 * Description: Generates per-language Google Merchant Center XML feeds (one per Polylang domain) as static files in uploads/gmc-feeds/. Run via WP-CLI: wp p4b-gmc generate [--lang=xx]
 */

const P4B_GMC_DIR = 'gmc-feeds';

function p4b_gmc_feed_url(string $lang): string {
    return p4b_catalog_feed_url(P4B_GMC_DIR, 'google-shopping-' . $lang . '.xml');
}

function p4b_gmc_availability(string $stock): string {
    switch ($stock) {
        case 'instock':
            return 'in_stock';
        case 'onbackorder':
            return 'backorder';
        default:
            return 'out_of_stock';
    }
}

function p4b_gmc_write_item(XMLWriter $x, array $item): void {
    $x->startElement('item');
    $x->writeElement('g:id', $item['id']);
    $x->writeElement('g:title', $item['title']);
    $x->writeElement('g:description', $item['description']);
    $x->writeElement('g:link', $item['link']);
    $x->writeElement('g:image_link', $item['image']);

    $availability = p4b_gmc_availability($item['stock']);
    $x->writeElement('g:availability', $availability);
    if ($availability === 'backorder') {
        $x->writeElement('g:availability_date', gmdate('Y-m-d', time() + P4B_CATALOG_BACKORDER_LEAD_DAYS * DAY_IN_SECONDS));
    }

    $x->writeElement('g:price', p4b_catalog_money($item['regular']));
    if ($item['sale'] !== null) {
        $x->writeElement('g:sale_price', p4b_catalog_money($item['sale']));
    }
    $x->writeElement('g:condition', 'new');

    if ($item['gtin']) {
        $x->writeElement('g:gtin', $item['gtin']);
    }
    if ($item['brand']) {
        $x->writeElement('g:brand', $item['brand']);
        $x->writeElement('g:mpn', $item['id']);
    }
    if (!$item['gtin'] && !$item['brand']) {
        $x->writeElement('g:identifier_exists', 'no');
    }
    if ($item['group_id']) {
        $x->writeElement('g:item_group_id', $item['group_id']);
    }
    if ($item['product_type']) {
        $x->writeElement('g:product_type', $item['product_type']);
    }
    if ($item['weight']) {
        $x->writeElement('g:shipping_weight', $item['weight']);
    }
    $x->endElement();
}

/** Generate the feed file for one language. Returns [written, skipped]. */
function p4b_gmc_generate_language(string $lang, string $country): array {
    [$x, $tmp, $final] = p4b_catalog_open_rss(
        p4b_catalog_feed_path(P4B_GMC_DIR, 'google-shopping-' . $lang . '.xml'),
        'Parts4Boats (' . strtoupper($lang) . ')',
        pll_home_url($lang)
    );
    $result = p4b_catalog_each_item($lang, $country, function (array $item) use ($x) {
        p4b_gmc_write_item($x, $item);
    });
    p4b_catalog_close_rss($x, $tmp, $final);
    return $result;
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('p4b-gmc', function ($args, $assoc_args) {
        if (!function_exists('wc_get_product') || !function_exists('pll_home_url')) {
            WP_CLI::error('WooCommerce and Polylang must be active.');
        }
        $languages = P4B_CATALOG_LANG_COUNTRY;
        if (!empty($assoc_args['lang'])) {
            $lang = $assoc_args['lang'];
            if (!isset($languages[$lang])) {
                WP_CLI::error("Unknown language: $lang");
            }
            $languages = [$lang => $languages[$lang]];
        }
        foreach ($languages as $lang => $country) {
            [$written, $skipped] = p4b_gmc_generate_language($lang, $country);
            WP_CLI::log(sprintf('%s: %d items, %d skipped -> %s', $lang, $written, $skipped, p4b_gmc_feed_url($lang)));
        }
        if (!empty($GLOBALS['p4b_catalog_duplicate_ids'])) {
            WP_CLI::warning('Duplicate ids skipped: ' . implode(', ', $GLOBALS['p4b_catalog_duplicate_ids']));
        }
        WP_CLI::success('Google feeds generated.');
    });
}
