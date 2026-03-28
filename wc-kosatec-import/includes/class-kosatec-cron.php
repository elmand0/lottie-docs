<?php
defined( 'ABSPATH' ) || exit;

class WC_Kosatec_Cron {

    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_schedules' ] );
        add_action( 'kosatec_price_stock_update', [ __CLASS__, 'run_price_stock_update' ] );
        add_action( 'kosatec_full_product_import', [ __CLASS__, 'run_full_import' ] );
        add_action( 'kosatec_clean_logs', [ __CLASS__, 'clean_logs' ] );
    }

    public static function add_schedules( array $schedules ): array {
        $schedules['every_15_minutes'] = [
            'interval' => 900,
            'display'  => __( 'Every 15 Minutes', 'wc-kosatec-import' ),
        ];
        $schedules['every_30_minutes'] = [
            'interval' => 1800,
            'display'  => __( 'Every 30 Minutes', 'wc-kosatec-import' ),
        ];
        return $schedules;
    }

    public static function schedule_events() {
        $price_interval = get_option( 'wc_kosatec_price_sync_interval', 'hourly' );
        $full_interval  = get_option( 'wc_kosatec_full_sync_interval', 'daily' );

        if ( ! wp_next_scheduled( 'kosatec_price_stock_update' ) ) {
            wp_schedule_event( time(), $price_interval, 'kosatec_price_stock_update' );
        }

        if ( ! wp_next_scheduled( 'kosatec_full_product_import' ) ) {
            wp_schedule_event( time(), $full_interval, 'kosatec_full_product_import' );
        }

        if ( ! wp_next_scheduled( 'kosatec_clean_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'kosatec_clean_logs' );
        }
    }

    public static function clear_events() {
        wp_clear_scheduled_hook( 'kosatec_price_stock_update' );
        wp_clear_scheduled_hook( 'kosatec_full_product_import' );
        wp_clear_scheduled_hook( 'kosatec_clean_logs' );
    }

    public static function reschedule() {
        self::clear_events();
        self::schedule_events();
    }

    public static function run_price_stock_update() {
        $api = new WC_Kosatec_API_Client();
        if ( ! $api->is_configured() ) {
            return;
        }

        $importer = new WC_Kosatec_Product_Importer();
        $importer->run_price_stock_update();
    }

    public static function run_full_import() {
        $api = new WC_Kosatec_API_Client();
        if ( ! $api->is_configured() ) {
            return;
        }

        $importer = new WC_Kosatec_Product_Importer();
        $importer->run_full_import();
    }

    public static function clean_logs() {
        $days = (int) get_option( 'wc_kosatec_log_retention_days', 30 );
        WC_Kosatec_Logger::clear_logs( $days );
    }
}
