<?php
/**
 * Plugin Name: Kosatec Product Import for WooCommerce
 * Plugin URI: https://github.com/elmand0/lottie-docs
 * Description: Import products from Kosatec E-Services into WooCommerce with automatic sync, variant grouping, EDI ordering, and real-time stock updates.
 * Version: 1.0.0
 * Author: Elmand0
 * Author URI: https://github.com/elmand0
 * Text Domain: wc-kosatec-import
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_KOSATEC_VERSION', '1.0.0' );
define( 'WC_KOSATEC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_KOSATEC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_KOSATEC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

final class WC_Kosatec_Import {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->check_dependencies();
        $this->includes();
        $this->init_hooks();
    }

    private function check_dependencies() {
        add_action( 'admin_init', function () {
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', function () {
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e( 'Kosatec Import requires WooCommerce to be installed and active.', 'wc-kosatec-import' );
                    echo '</p></div>';
                } );
                deactivate_plugins( WC_KOSATEC_PLUGIN_BASENAME );
            }
        } );
    }

    private function includes() {
        require_once WC_KOSATEC_PLUGIN_DIR . 'includes/class-kosatec-logger.php';
        require_once WC_KOSATEC_PLUGIN_DIR . 'includes/class-kosatec-api-client.php';
        require_once WC_KOSATEC_PLUGIN_DIR . 'includes/class-kosatec-product-importer.php';
        require_once WC_KOSATEC_PLUGIN_DIR . 'includes/class-kosatec-variant-grouper.php';
        require_once WC_KOSATEC_PLUGIN_DIR . 'includes/class-kosatec-cron.php';
        require_once WC_KOSATEC_PLUGIN_DIR . 'includes/class-kosatec-edi-orders.php';
        require_once WC_KOSATEC_PLUGIN_DIR . 'includes/class-kosatec-image-handler.php';
        require_once WC_KOSATEC_PLUGIN_DIR . 'includes/class-kosatec-wpml.php';

        if ( is_admin() ) {
            require_once WC_KOSATEC_PLUGIN_DIR . 'admin/class-kosatec-admin.php';
        }
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_filter( 'plugin_action_links_' . WC_KOSATEC_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );

        WC_Kosatec_Cron::init();
    }

    public function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'kosatec_import_log';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        WC_Kosatec_Cron::schedule_events();

        update_option( 'wc_kosatec_version', WC_KOSATEC_VERSION );
    }

    public function deactivate() {
        WC_Kosatec_Cron::clear_events();
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'wc-kosatec-import', false, dirname( WC_KOSATEC_PLUGIN_BASENAME ) . '/languages' );
    }

    public function plugin_action_links( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=kosatec-settings' ) ) . '">'
            . esc_html__( 'Settings', 'wc-kosatec-import' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

add_action( 'plugins_loaded', [ 'WC_Kosatec_Import', 'instance' ] );
