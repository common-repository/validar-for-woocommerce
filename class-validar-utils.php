<?php

class ValidarUtils
{
    private static function get_validar_host() {
        $custom = get_option('validar_custom_validar_host');

        if (empty($custom) || !$custom) {
            return 'https://app.validar.io';
        }

        return $custom;
    }

    public static function get_loader_url() {
        $custom = get_option('validar_custom_loader_url');

        if (empty($custom) || !$custom) {
            return 'https://app.validar.io/js/loaders/wp-validar.min.js';
        }

        return $custom;
    }

    static function send_request($endpoint, $data) {
        $url = self::get_validar_host().$endpoint;

        if (isset($_COOKIE['ra_customer_id'])) {
            $customer = sanitize_text_field(wp_unslash($_COOKIE['ra_customer_id']));

            if (is_object($data)) {
                $data->customer = $customer;
            } else {
                $data['customer'] = $customer;
            }
        }

        wp_remote_post($url, [
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => false,
            'cookies' => [],
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'api-key' => self::get_api_key()
            ],
            'body' => json_encode($data)
        ]);
    }

    private static function generate_uuid() {
        if (function_exists('com_create_guid') === true)
            return trim(com_create_guid(), '{}');

        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    static function url_encode($data) {
        return strtr(base64_encode($data), '+/=', '._-');
    }

    static function url_decode($data) {
        return base64_decode(strtr($data, '._-', '+/='));
    }

    public static function decode_array($base64, $assoc = false) {
        try {
            $decoded_base64 = self::url_decode($base64);
            return json_decode($decoded_base64, $assoc);
        } catch (Exception $e) {
            return null;
        }
    }

    public static function encode_array($data) {
        try {
            $json = json_encode($data);
            return self::url_encode($json);
        } catch (Exception $e) {
            return null;
        }
    }

    public static function get_api_key() {
        return get_option('validar_api_key');
    }

    public static function delete_api_key() {
        delete_option('validar_api_key');
    }

    public static function set_api_key($key) {
        update_option('validar_api_key', $key);
    }

    public static function is_connected() {
        $key = self::get_api_key();
        return !empty($key) && $key != null;
    }

    public static function get_authenticator_token() {
        return get_option('validar_authenticator_token');
    }

    public static function set_new_authenticator_token() {
        $token = self::generate_uuid();
        update_option('validar_authenticator_token', $token);
        return $token;
    }

    public static function get_connect_url($platform, $return, $cancel) {
        $user = wp_get_current_user();

        $query = join('&', [
            'platform='.urlencode($platform),
            'base='.urlencode(get_site_url()),
            'return='.urlencode($return),
            'return_cancel='.urlencode($cancel),
            'email='.urlencode($user->user_email),
            'display_name='.urlencode($user->display_name),
            'first_name='.urlencode($user->first_name),
            'last_name='.urlencode($user->last_name),
            'site_name='.urlencode(get_bloginfo('name')),
            'token='.self::set_new_authenticator_token()
        ]);

        return self::get_validar_host().'/wordpress/connect?'.$query;
    }

    public static function get_server_variable($field) {
        if (!empty($_SERVER[$field])) {
            return sanitize_text_field(
                wp_unslash($_SERVER[$field])
            );
        }
        return null;
    }

    public static function get_real_ip() {
        //check ip from share internet
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return self::get_server_variable('HTTP_CLIENT_IP');
        }

        //to check ip is pass from proxy
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return self::get_server_variable('HTTP_X_FORWARDED_FOR');
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return self::get_server_variable('REMOTE_ADDR');
        }

        return null;
    }

    public static function generate_secure_verification($key) {
        return hash('sha256', $key.'|'.self::get_authenticator_token());
    }
}