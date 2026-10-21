<?php
/**
 * Plugin Name: P4B Meta Pixel Hygiene
 * Description: Keeps crawlers out of the Meta Conversions API events sent by Facebook for WooCommerce, stops crawler-triggered ?add-to-cart= GETs, and prevents duplicate browser Purchase events when a thank-you page is re-executed from the browser cache.
 *
 * Background (Sept 2026 investigation):
 *  - Facebook for WooCommerce sends a server-side (CAPI) PageView / ViewCategory / ViewContent /
 *    Search / AddToCart event on every page render. Its built-in crawler filter only knows Meta's
 *    own crawlers and the word "crawler", so SemrushBot, MJ12bot, PetalBot, GPTBot, curl, empty
 *    user agents etc. produced ~1,000 server PageViews per hour against ~30 real browser PageViews.
 *  - Category pages contain crawlable "?add-to-cart=ID" links; a GET on them triggers
 *    woocommerce_add_to_cart and therefore a server-side AddToCart event. Bots ignoring robots.txt
 *    caused bursts of 15-35 AddToCart events per hour with zero checkouts.
 *  - The plugin's "stale page" branch fires browser events WITHOUT an eventID when the page is
 *    executed more than 30 minutes after it was generated (tab restore, back navigation from disk
 *    cache, reopening the confirmation). On the thank-you page that re-fires Purchase in a way Meta
 *    cannot deduplicate. This site has no full-page cache, so keeping the eventID is always safe.
 *
 * Remove this file to restore the plugin's default behaviour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class P4B_Meta_Pixel_Hygiene {

	/** Must mirror $cache_threshold in WC_Facebookcommerce_Pixel::build_event(). */
	const CACHE_THRESHOLD = 1800;

	/** Lower-case substrings that identify automated clients. Matched against the User-Agent. */
	const UA_PATTERNS = array(
		// generic
		'bot', 'crawl', 'spider', 'slurp', 'scraper', 'fetch', 'headless', 'phantomjs', 'selenium',
		'puppeteer', 'playwright', 'validator', 'monitor', 'preview', 'lighthouse', 'pagespeed',
		// http libraries / cli clients
		'curl/', 'wget/', 'python', 'go-http', 'java/', 'okhttp', 'httpclient', 'libwww', 'lwp-',
		'scrapy', 'axios/', 'postmanruntime', 'insomnia', 'http_request', 'wordpress/',
		// SEO / index / AI crawlers that do not carry "bot" in the UA
		'semrush', 'ahrefs', 'mj12', 'petal', 'baidu', 'yandex', 'duckduck', 'dataforseo', 'sitebulb',
		'screaming frog', 'perplexity', 'chatgpt', 'oai-search', 'claude', 'anthropic', 'bytespider',
		'meta-external', 'facebookexternalhit', 'whatsapp', 'skypeuripreview', 'ia_archiver', 'archive.org',
		'censys', 'shodan', 'zgrab', 'masscan', 'nmap',
	);

	public static function init(): void {
		// 1. Keep automated clients out of the Conversions API.
		add_filter( 'wc_facebook_is_crawler_request', array( __CLASS__, 'filter_is_crawler_request' ), 10, 2 );

		// 2. Ignore ?add-to-cart= GETs from automated clients (runs before WC_Form_Handler at priority 20).
		add_action( 'wp_loaded', array( __CLASS__, 'drop_automated_add_to_cart' ), 15 );

		// 3. Never let the pixel drop eventIDs on "stale" pages; suppress Purchase re-fires on the thank-you page.
		add_action( 'wp_footer', array( __CLASS__, 'print_stale_page_guard' ), 1 );
	}

	/**
	 * Whether the current HTTP request looks automated (crawler, script, empty UA).
	 */
	public static function is_automated_request(): bool {
		static $result = null;
		if ( null !== $result ) {
			return $result;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( trim( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === $ua ) {
			return $result = true;
		}

		foreach ( self::UA_PATTERNS as $pattern ) {
			if ( false !== strpos( $ua, $pattern ) ) {
				return $result = true;
			}
		}

		// Every real browser sends Accept-Language; scripted clients and most scrapers do not.
		if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return $result = true;
		}

		return $result = false;
	}

	/**
	 * Whether the current request is a GET on a ?add-to-cart= URL.
	 */
	private static function is_get_add_to_cart_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		return 'GET' === $method && isset( $_GET['add-to-cart'] );
	}

	/**
	 * Filter for Facebook for WooCommerce: treat automated requests as crawlers so no CAPI event is sent.
	 *
	 * A GET add-to-cart request from a client that carries no cookies at all is also excluded: no
	 * real shopper reaches that URL without having visited a page first, but non-JS clients do.
	 */
	public static function filter_is_crawler_request( $is_crawler, $user_agent ): bool {
		if ( $is_crawler ) {
			return true;
		}
		if ( self::is_automated_request() ) {
			return true;
		}
		if ( self::is_get_add_to_cart_request() && empty( $_COOKIE ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Remove the add-to-cart request var so WC_Form_Handler::add_to_cart_action() does nothing.
	 */
	public static function drop_automated_add_to_cart(): void {
		if ( ! self::is_get_add_to_cart_request() || ! self::is_automated_request() ) {
			return;
		}
		unset( $_REQUEST['add-to-cart'], $_GET['add-to-cart'] );
	}

	/**
	 * Printed at wp_footer priority 1, i.e. before the plugin's inline event script (priority 20).
	 */
	public static function print_stale_page_guard(): void {
		if ( is_admin() ) {
			return;
		}

		$purchase_event_id = '';
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$order_id = absint( get_query_var( 'order-received' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			if ( $order ) {
				$purchase_event_id = (string) $order->get_meta( '_meta_event_id' );
			}
		}
		?>
<script id="p4b-meta-pixel-hygiene">
(function () {
	var generated = window.__wc_fb_page_generated;
	if (!generated) { return; }
	var now = Math.floor(Date.now() / 1000);
	var stale = (now - generated) > <?php echo (int) self::CACHE_THRESHOLD; ?>;
<?php if ( '' !== $purchase_event_id ) : ?>
	if (stale) {
		// Thank-you page re-executed from browser cache: the Purchase was already sent, do not fire it again.
		window.wcFacebookPixelFiredEvents = window.wcFacebookPixelFiredEvents || {};
		window.wcFacebookPixelFiredEvents[<?php echo wp_json_encode( $purchase_event_id ); ?>] = true;
	}
<?php endif; ?>
	// No full-page cache on this site, so eventIDs are unique per render: keep them so Meta can deduplicate.
	window.__wc_fb_page_generated = now;
})();
</script>
		<?php
	}
}

P4B_Meta_Pixel_Hygiene::init();
