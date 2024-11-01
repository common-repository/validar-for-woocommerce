<?php

class ValidarBasePlatform {
    function __construct() {
    }

    function get_name() {
        return 'unknown';
    }

    function is_order_confirmation_page() {
        return false;
    }

    function remove_actions() {
    }

    function add_actions() {
    }

    public function enqueue_scripts() {
    }

    public static function get_base_path($url = true) {
        $path = dirname(__FILE__);
        return $url
            ? plugin_dir_url($path)
            : plugin_dir_path($path);
    }
}