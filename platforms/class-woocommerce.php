<?php

class ValidarWooCommerce extends ValidarBasePlatform {

    function get_name() {
        return 'woocommerce';
    }

    function is_order_confirmation_page() {
        // we use the woocommerce_thankyou action
        return false;
    }

    public function add_actions() {
        add_action('woocommerce_thankyou', [$this, 'add_thank_you_script']);
        add_action('wp_ajax_validar_update_order_sipping_address', [$this, 'update_order_sipping_address']);
        add_action('wp_ajax_nopriv_validar_update_order_sipping_address', [$this, 'update_order_sipping_address']);
    }

    public  function remove_actions() {
        remove_action('woocommerce_thankyou', [$this, 'add_thank_you_script']);
        remove_action('wp_ajax_validar_update_order_sipping_address', [$this, 'update_order_sipping_address']);
        remove_action('wp_ajax_nopriv_validar_update_order_sipping_address', [$this, 'update_order_sipping_address']);
    }

    public static function is_ready() {
        return class_exists('WooCommerce') && function_exists('WC');
    }

    public function enqueue_scripts() {
    }

    protected function is_valid_validar_order($order) {
        if (!$order->has_shipping_address()) {
            return false;
        }

        $now = (new WC_DateTime())->getTimestamp();
        $created_at = $order->get_date_created()->getTimestamp();

        // If the order was placed over 48 hours ago then don't process
        $hours_delta = ($now - $created_at) / 60 / 60;
        return $hours_delta < 48;
    }

    public function add_thank_you_script($order_id) {
        try {
            $api_key = ValidarUtils::get_api_key();

            if (!$api_key || empty($api_key)) {
                return;
            }

            $cache_bust = round((time() / (60 * 10)));
            $url = ValidarUtils::get_loader_url().'?v='.$cache_bust.'&api_key='.$api_key;

            $order = wc_get_order($order_id);

            if ($order == false || !self::is_valid_validar_order($order)) {
                return;
            }

            $order_status_url = '';

            if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
                $status_host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
                $status_url = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
                $order_status_url = "http://$status_host$status_url";
            }

            // Get the states for this country
            $states = WC()->countries->get_states($order->get_shipping_country());

            ?>
            <script type='text/javascript'>
                window.__validarAjax = {
                    url: '<?php echo esc_url_raw(admin_url('admin-ajax.php')); ?>',
                    nonce: '<?php echo esc_attr(wp_create_nonce('validar_update_address')); ?>'
                };
                window.__validarOrder = {
                    orderId: '<?php echo esc_attr($order_id) ?>',
                    apiKey: '<?php echo esc_attr($api_key) ?>',
                    token: '<?php echo esc_attr(ValidarUtils::generate_secure_verification($order_id)) ?>',
                    createdAt: '<?php echo esc_attr($order->get_date_created()) ?>',
                    orderStatusUrl: '<?php echo esc_url_raw($order_status_url) ?>',
                    address: {
                        address1: '<?php echo esc_html($order->get_shipping_address_1()) ?>',
                        address2: '<?php echo esc_html($order->get_shipping_address_2()) ?>',
                        city: '<?php echo esc_html($order->get_shipping_city()) ?>',
                        province: '<?php echo esc_html($states[$order->get_shipping_state()]) ?>',
                        zip: '<?php echo esc_html($order->get_shipping_postcode()) ?>',
                        country: '<?php echo esc_html($order->get_shipping_country()) ?>',
                    }
                };
            </script>
            <script type='text/javascript' src="<?= esc_html($url) ?>"></script>
            <?php
        } catch (Exception $e) {
            // Don't kill the thank you page!
            return;
        }
    }

    public function update_order_sipping_address() {
        // Security check
        $nonce = isset($_POST['nonce'])
            ? sanitize_text_field(wp_unslash($_POST['nonce']))
            : null;

        if ($nonce == null || !wp_verify_nonce($nonce, 'validar_update_address')) {
            wp_send_json_error(new WP_Error('001', 'Invalid', 'Invalid nonce'));
        }

        $request = (object) $_REQUEST;
        $order = wc_get_order($request->order_id);

        // Make sure the order and key are valid
        if (
            $order == false ||
            $order->get_order_key() != $request->key ||
            !self::is_valid_validar_order($order)
        ) {
            wp_send_json([], 401);
            die();
        }

        // Update the address
        $order->set_shipping_address_1($request->address1);
        $order->set_shipping_address_2($request->address2);
        $order->set_shipping_city($request->city);
        $order->set_shipping_postcode($request->zip);

        /*
        We don't currently let customers update their state as it might impact
        shipping prices

        // Find the state (if any)
        $states = WC()->countries->get_states($order->get_shipping_country());
        $state = array_search($request->province, $states);

        if ($state != false) {
            $order->set_shipping_state($state);
        }
        */

        // Save the order
        $order->save();

        wp_send_json([], 201);
        die();
    }
}
