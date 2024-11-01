<?php

/*
 * Plugin Name: Validar for WooCommerce
 * Plugin URI: https://validar.io/
 * Description: Validar helps you validate shipping addresses.
 * Version: 1.0.1
 * Author: validar
 * Text Domain: validar
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (!class_exists('Validar')) {

    include(dirname(__FILE__) . '/class-validar-utils.php');
    include(dirname(__FILE__) . '/platforms/class-base-platform.php');
    include(dirname(__FILE__) . '/platforms/class-woocommerce.php');

    define('VALIDAR_VERSION', '1.0.1');
    define('VALIDAR_TEXT_DOMAIN', 'validar');
    define('VALIDAR_TARGET_PLATFORM', 'WooCommerce');

    class Validar
    {
        private $platform = null;

        /**
         * Constructor for the plugin.
         *
         * @access        public
         */
        public function __construct()
        {
            add_action('init', [$this, 'load_plugin_textdomain']);

            if (ValidarWooCommerce::is_ready()) {
                $this->platform = new ValidarWooCommerce();
            } else {
                add_action('admin_init', [$this, 'validar_nag_ignore']);
                add_action('admin_notices', [$this, 'plugin_missing_notice']);
                return;
            }

            // Add the plugin page Settings and Docs links
            add_filter('plugin_action_links_' . plugin_basename(__FILE__),
                [$this, 'validar_plugin_links']);

            // standard wordpress actions
            add_action('init', [$this, 'check_version']);
            add_action('init', [$this, 'finish_validar_connection']);
            add_action('init', [$this, 'create_unique_discount_code']);
            add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

            add_action('admin_notices', [$this, 'admin_notices']);
            add_action('admin_menu', [$this, 'admin_page']);
            add_action('wp_footer', [$this, 'add_footer_scripts']);

            // ajax handlers
            add_action('wp_ajax_validar_connection_status', [$this, 'ajax_is_connected']);

            // Validar connection actions
            add_action('admin_post_validar_disconnect',
                [$this, 'disconnect_validar']);

            add_action('admin_post_validar_connect',
                [$this, 'connect_validar']);

            add_action('admin_post_validar_confirm_disconnect',
                [$this, 'confirm_disconnect_validar']);

            add_filter('allowed_redirect_hosts',  [$this, 'allowed_redirect_hosts']);

            // setup the actions for this provider;
            $this->platform->add_actions();
        }

        function allowed_redirect_hosts($hosts) {
            $full_hosts = [
                'validar.io',
                'app.validar.io',
                'localhost'
            ];
            return array_merge($hosts, $full_hosts);
        }

        function load_plugin_textdomain() {
            $location = basename(dirname(__FILE__)).'/languages/';
            load_plugin_textdomain(VALIDAR_TEXT_DOMAIN, FALSE, $location);
        }

        function enqueue_scripts() {
            $this->platform->enqueue_scripts();
        }

        function admin_enqueue_scripts() {
            // add our style
            wp_register_style(
                'validar_styles',
                plugins_url('/css/styles.css', __FILE__),
                false,
                VALIDAR_VERSION,
                'all'
            );

            wp_enqueue_style('validar_styles');

            // Add script
            wp_register_script(
                'validar_script',
                plugins_url('/js/admin.js', __FILE__),
                VALIDAR_VERSION,
                'all');

            wp_localize_script(
                'validar_script',
                '___validar',
                ['ajax' => admin_url('admin-ajax.php')]
            );

            wp_enqueue_script('validar_script');
        }

        public function ajax_is_connected() {
            wp_send_json_success(ValidarUtils::is_connected());
        }

        /**
         * Set up admin notices
         *
         * @access        public
         * @return        void
         */
        public function admin_notices()
        {
            $screen = get_current_screen();

            // If the Merchant ID field is empty
            if (get_option('validar_api_key') || $screen->base == 'toplevel_page_validar') {
                return;
            }

            ?>
            <div class="updated">
                <p>
                    <?php esc_html_e('Please connect website to Validar', VALIDAR_TEXT_DOMAIN); ?>
                    <a href="<?php echo esc_html(admin_url('admin.php?page=validar')) ?>">
                        <?php esc_html_e('here', VALIDAR_TEXT_DOMAIN); ?>
                    </a>
                </p>
            </div>
            <?php
        }

        /**
         * Initialize the Validar menu
         *
         * @access        public
         * @return        void
         */
        public function admin_page()
        {
            add_menu_page(
                'Validar',
                'Validar',
                'manage_options',
                'validar',
                [$this, 'admin_options'],
                plugins_url('images/validar.png', __FILE__),
                58);

            add_submenu_page(
                null,
                'Disconnect Validar',
                'Disconnect Validar',
                'manage_options',
                'validar-confirm-disconnect',
                [$this, 'confirm_disconnect_validar']
            );

            add_action('admin_init', [$this, 'register_settings']);
        }

        /**
         * Register settings for Validar
         *
         * @access        public
         * @return        void
         */
        public function register_settings()
        {
            register_setting('validar-settings-group',
                'validar_api_key');

            register_setting('validar-settings-group',
                'validar_custom_loader_url');

            register_setting('validar-settings-group',
                'validar_custom_validar_host');
        }

        /**
         * Add options to the Validar menu
         *
         * @access        public
         * @return        void
         */
        public function admin_options()
        {
            ?>
            <div class="wrap">
                <form method="post" action="<?php echo esc_html(admin_url('admin-post.php')); ?>" target="_blank">
                    <?php if (ValidarUtils::is_connected()) { ?>
                        <h2>Validar</h2>
                        <p>
                            <?php esc_html_e('Validar has been successfully connected.', VALIDAR_TEXT_DOMAIN) ?>
                        </p>
                        <p>
                            <a href="https://app.validar.io/account" target="_blank">
                                Address Analytics
                            </a>
                        </p>
                        <h3 class="validar-disconnect">Disconnect this site</h3>
                        <p>
                            <a href="<?php echo esc_html(admin_url('admin.php?page=validar-confirm-disconnect')) ?>" class="button">
                                <?php esc_html_e('Disconnect from Validar', VALIDAR_TEXT_DOMAIN) ?>
                            </a>
                        </p>

                    <?php } else { ?>
                        <h2>Welcome to Validar!</h2>
                        <p>
                        Stop failed deliveries in just seconds with no coding required!
                        </p>
                        <p>
                            Don’t waste any more money on returned packages because of bad shipping addresses. Avoid redelivery fees, angry customers and wasted time!
                        </p>
                        <p>
                            <input type="hidden" name="action" value="validar_connect">
                            <?php submit_button(__('Connect to Validar', VALIDAR_TEXT_DOMAIN), 'primary', 'submit', false); ?>
                        </p>
                        <p>
                            If you're having issues connecting, don't hesitate to <a href="https://validar.io">contact us</a>
                        </p>
                        <script>
                        window.ValidarAdmin.monitorAccountConnection();
                        </script>
                    <?php } ?>
                </form>
            </div>
            <?php
        }

        function connect_validar()
        {
            // redirect to the connection url
            $url = ValidarUtils::get_connect_url(
                $this->platform->get_name(),
                admin_url('admin.php?page=validar'),
                admin_url('admin.php?page=validar')
            );
            wp_safe_redirect($url);
            exit();
        }

        function disconnect_validar()
        {
            ValidarUtils::delete_api_key();
            wp_safe_redirect(admin_url('admin.php?page=validar'));
            exit();
        }

        function confirm_disconnect_validar()
        {
            ?>
            <div class="wrap">
                <h2>Validar</h2>
                <form method="post" action="<?php echo esc_html(admin_url('admin-post.php')); ?>">
                    <p>
                        <?php esc_html_e('Disconnecting will stop new addresses from being validated', VALIDAR_TEXT_DOMAIN) ?>
                        <input type="hidden" name="action" value="validar_disconnect">
                    <p>
                        <?php submit_button(__('I understand, disconnect my store', VALIDAR_TEXT_DOMAIN), 'primary', 'submit', false); ?>
                        &nbsp;
                        <a href="<?php echo esc_html(admin_url('admin.php?page=validar')) ?>" class="button">
                            <?php esc_html_e('Cancel', VALIDAR_TEXT_DOMAIN) ?>
                        </a>
                    </p>
                </form>
            </div>
            <?php
        }

        function finish_validar_connection()
        {
            // Ignoring wpecs warning because this URL is called from Validar via the backend, i.e. the user
            // session is not valid

            // phpcs:ignore
            if (empty($_GET['connect_validar']) || empty($_GET['api_key']) || empty($_GET['token'])) {
                return;
            }

            // phpcs:ignore
            $token = sanitize_text_field(wp_unslash($_GET['token']));

            // phpcs:ignore
            $api_key = sanitize_text_field(wp_unslash($_GET['api_key']));

            if ($token != ValidarUtils::get_authenticator_token()) {
                return;
            }

            ValidarUtils::set_api_key($api_key);

            // phpcs:ignore
            if (isset($_GET['redirect_settings'])) {
                wp_safe_redirect(admin_url('admin.php?page=validar'));
                die();
            }

            die('Connected!');
        }

        function create_unique_discount_code() {
            // phpcs:ignore
            if (empty($_GET['generate_unique_discount']) || empty($_GET['token'])) {
                return;
            }

            // phpcs:ignore
            $token = sanitize_text_field(wp_unslash($_GET['token']));

            if ($token != ValidarUtils::get_authenticator_token()) {
                return;
            }

            $spec = json_decode(file_get_contents('php://input'), true);

            if ($spec == null) {
                wp_send_json([], 422);
                return;
            }

            $discount = $this->platform->create_unique_discount_code((object)$spec);
            wp_send_json(['discount' => $discount]);
        }

        function check_version()
        {
            // Called from Validar to check the current plugin version, nonce check not possible
            // phpcs:ignore
            if (isset($_GET['get_validar_version'])) {
                $res = [
                    'version' => VALIDAR_VERSION,
                    'platform' => $this->platform->get_name()
                ];
                echo json_encode($res);
                die();
            }
        }

        /**
         * Add scripts to the footer of th checkout or thank you page
         *
         * @access        public
         * @return        void
         */
        public function add_footer_scripts()
        {
            $api_key = ValidarUtils::get_api_key();

            if (!$api_key || empty($api_key) || !$this->platform->is_order_confirmation_page()) {
                return;
            }

            $cache_bust = round((time() / (60 * 10)));
            $url = ValidarUtils::get_loader_url().'?v='.$cache_bust.'&api_key='.$api_key;

            ?>
            <script type='text/javascript' src="<?= esc_html($url) ?>"></script>
            <?php
        }

        /**
         * Plugin page links
         *
         * @param array $links
         * @return array
         */
        function validar_plugin_links($links)
        {
            $links['settings'] = '<a href="' . esc_html(admin_url('admin.php?page=validar&settings-updated=true')) . '">' . __('Settings', 'Validar') . '</a>';
            return $links;
        }


        /**
         * Easy Digital Downloads plugin missing notice.
         *
         * @return string
         */
        public function plugin_missing_notice()
        {
            global $current_user;
            $user_id = $current_user->ID;
            if (!get_user_meta($user_id, 'validar_missing_plugin_nag')) {
                $message = sprintf(
                    __('Validar needs %s to be installed and active.', VALIDAR_TEXT_DOMAIN),
                    VALIDAR_TARGET_PLATFORM);

                $hide_message = __('Hide Notice', VALIDAR_TEXT_DOMAIN);
                $remove_nag_url = wp_nonce_url('?validar_missing_plugin_nag=0', 'remove_validar_nag');

                ?>
                    <div class="error">
                        <p>
                            <?php echo esc_html($message) ?>
                            <a href="<?php echo esc_html($remove_nag_url) ?>">
                                <?php echo esc_html($hide_message) ?>
                            </a>
                        </p>
                    </div> 
                <?php
            }

            return null;
        }

        /**
         *  Remove the nag if user chooses
         */
        function validar_nag_ignore()
        {
            global $current_user;
            $user_id = $current_user->ID;

            if (isset($_GET['validar_missing_plugin_nag'])) {
                check_admin_referer('remove_validar_nag');
                add_user_meta($user_id, 'validar_missing_plugin_nag', 'true', true);
            }
        }
    }

    function validar_plugins_loaded() {
        new Validar();
    }
    add_action('plugins_loaded', 'validar_plugins_loaded');

    function validar_plugin_activated($plugin) {
        $is_ready = ValidarWooCommerce::is_ready();

        if ($plugin == plugin_basename(__FILE__) && !ValidarUtils::is_connected() && $is_ready) {
            wp_safe_redirect(admin_url('admin.php?page=validar'));
            exit();
        }
    }
    add_action('activated_plugin', 'validar_plugin_activated');

} // End if class_exists check.
