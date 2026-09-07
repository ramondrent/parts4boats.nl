<?php
/**
 * Plugin Name: Rank Math + Polylang sitemap fix
 * Description: Serve correct per-domain sitemaps with Polylang's domain-per-language setup. (1) Disables Rank Math's sitemap cache, which is not domain-aware: the first domain to generate a sitemap would have its XML cached and served on every other domain. (2) Localizes home_url() while a sitemap renders: Rank Math serves sitemaps on parse_query (priority 1), before Polylang's home_url filter activates (it waits for template_redirect), so secondary-domain sitemap URLs were built on the default-language domain and every entry was dropped as "external".
 * Author: Parts4Boats
 *
 * Temporary site-level copy of a fix that belongs in the theme's
 * App\Filters\RankMathPolylangFilters class. Safe to remove once the theme
 * ships the same fix (double registration is harmless: the caching filter is
 * boolean and the home_url rewrite is idempotent).
 */

declare(strict_types=1);

add_filter('rank_math/sitemap/enable_caching', '__return_false');

add_action('parse_query', static function (WP_Query $query): void {
    if (! $query->is_main_query()
        || (empty($query->query_vars['sitemap']) && empty($query->query_vars['xsl']))
        || ! function_exists('PLL')
        || ! function_exists('pll_default_language')) {
        return;
    }

    $slug = pll_current_language('slug');

    if (! is_string($slug) || $slug === '') {
        $linksModel = PLL()->links_model ?? null;

        if (is_object($linksModel) && is_callable([$linksModel, 'get_language_from_url'])) {
            $slug = $linksModel->get_language_from_url();
        }
    }

    if (! is_string($slug) || $slug === '') {
        $slug = pll_default_language();
    }

    if (! is_string($slug) || $slug === '') {
        return;
    }

    $language = PLL()->model->get_language($slug);

    if (! $language instanceof PLL_Language) {
        return;
    }

    $defaultHome = untrailingslashit((string) (PLL()->links_model->home ?? home_url()));
    $languageHome = untrailingslashit((string) $language->get_home_url());

    if ($defaultHome === '' || $languageHome === '' || $languageHome === $defaultHome) {
        return;
    }

    add_filter('home_url', static function (string $url) use ($defaultHome, $languageHome): string {
        return str_replace($defaultHome, $languageHome, $url);
    });
}, 0);
