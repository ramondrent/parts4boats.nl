<?php
/**
 * Plugin Name: S4T Umami Analytics
 * Description: Umami tracking + session replay (analytics.shop4trac.com) with WooCommerce event wiring, frontend only, excludes staff.
 */

const S4T_UMAMI_HOST       = 'https://analytics.shop4trac.com';
const S4T_UMAMI_WEBSITE_ID = '3c9f2fa7-06f5-4977-bc96-a240e6761dab';

function s4t_umami_enabled(): bool {
    return !is_admin() && !current_user_can('edit_posts');
}

add_action('wp_head', function () {
    if (!s4t_umami_enabled()) {
        return;
    }
    printf(
        '<script defer src="%1$s/script.js" data-website-id="%2$s"></script>' . "\n" .
        '<script defer src="%1$s/recorder.js" data-website-id="%2$s"></script>' . "\n",
        S4T_UMAMI_HOST,
        S4T_UMAMI_WEBSITE_ID
    );
});

/**
 * WooCommerce funnel events: view_product -> add_to_cart -> begin_checkout -> purchase.
 * Event data always carries language + currency so conversions can be segmented per country.
 */
add_action('wp_footer', function () {
    if (!s4t_umami_enabled() || !class_exists('WooCommerce')) {
        return;
    }

    $ctx = [
        'language' => get_locale(),
        'currency' => get_woocommerce_currency(),
    ];

    $product = null;
    if (is_product()) {
        $p = wc_get_product(get_queried_object_id());
        if ($p) {
            $product = [
                'product_id' => (string) $p->get_id(),
                'name'       => $p->get_name(),
                'price'      => (float) wc_get_price_to_display($p),
            ];
        }
    }

    $is_checkout = is_checkout() && !is_wc_endpoint_url('order-received');
    $cart_total  = ($is_checkout && WC()->cart) ? (float) WC()->cart->get_total('edit') : 0;
    ?>
    <script>
    (function () {
        var ctx = <?php echo wp_json_encode($ctx); ?>;
        function track(name, data) {
            if (typeof umami === 'undefined') return;
            umami.track(name, Object.assign({}, ctx, data || {}));
        }

        <?php if ($product) : ?>
        window.addEventListener('load', function () {
            track('view_product', <?php echo wp_json_encode($product); ?>);
        });
        <?php endif; ?>

        <?php if ($is_checkout) : ?>
        window.addEventListener('load', function () {
            track('begin_checkout', { value: <?php echo wp_json_encode($cart_total); ?> });
        });
        <?php endif; ?>

        if (window.jQuery) {
            // AJAX add-to-cart (shop/category loops)
            jQuery(document.body).on('added_to_cart', function (e, fragments, hash, button) {
                var data = {};
                if (button && button.length) {
                    data.product_id = String(button.data('product_id') || '');
                    data.name = button.attr('aria-label') || undefined;
                }
                track('add_to_cart', data);
            });
            jQuery(document.body).on('removed_from_cart', function () {
                track('remove_from_cart');
            });
        }

        // Non-AJAX add-to-cart (single product page form submit)
        var cartForm = document.querySelector('form.cart');
        if (cartForm) {
            cartForm.addEventListener('submit', function () {
                track('add_to_cart', <?php echo wp_json_encode($product ?: new stdClass()); ?>);
            });
        }
    })();
    </script>
    <?php
});

/**
 * Purchase event on the order-received page, with `revenue` + `currency` so
 * Umami's revenue report picks it up. Deduped client-side per order so a
 * page refresh does not double-count.
 */
add_action('woocommerce_thankyou', function ($order_id) {
    if (!s4t_umami_enabled()) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    $data = [
        'order_id' => (string) $order->get_order_number(),
        'revenue'  => (float) $order->get_total(),
        'currency' => $order->get_currency(),
        'payment'  => (string) $order->get_payment_method(),
        'items'    => (int) $order->get_item_count(),
        'language' => get_locale(),
    ];
    ?>
    <script>
    window.addEventListener('load', function () {
        var key = 's4t_umami_purchase_<?php echo (int) $order_id; ?>';
        try {
            if (localStorage.getItem(key)) return;
        } catch (e) {}
        if (typeof umami === 'undefined') return;
        umami.track('purchase', <?php echo wp_json_encode($data); ?>);
        try { localStorage.setItem(key, '1'); } catch (e) {}
    });
    </script>
    <?php
});
