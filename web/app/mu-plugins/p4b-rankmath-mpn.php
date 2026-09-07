<?php
/**
 * Plugin Name: P4B Rank Math MPN
 * Description: Enables Rank Math Pro's own GTIN/MPN product field (stored in _rank_math_gtin_code) so the value is output as "mpn" in the Product schema and editable under Product data → Inventory. The schema property type is set to "mpn" in Rank Math → General Settings → WooCommerce. OEM numbers were bulk-imported from the meta descriptions on 2026-09-02; the full list per product lives in _p4b_oem_numbers.
 */

add_filter('rank_math/woocommerce/add_gtin_field', '__return_true');
