<?php
defined( 'ABSPATH' ) || exit;

class WC_Kosatec_Logger {

    private static $table_name;

    private static function get_table() {
        global $wpdb;
        if ( ! self::$table_name ) {
            self::$table_name = $wpdb->prefix . 'kosatec_import_log';
        }
        return self::$table_name;
    }

    public static function log( string $type, string $message, array $context = [] ) {
        global $wpdb;
        $wpdb->insert(
            self::get_table(),
            [
                'type'       => sanitize_key( $type ),
                'message'    => sanitize_text_field( $message ),
                'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
    }

    public static function info( string $message, array $context = [] ) {
        self::log( 'info', $message, $context );
    }

    public static function error( string $message, array $context = [] ) {
        self::log( 'error', $message, $context );
    }

    public static function success( string $message, array $context = [] ) {
        self::log( 'success', $message, $context );
    }

    public static function get_logs( int $limit = 100, int $offset = 0, string $type = '' ) {
        global $wpdb;
        $table = self::get_table();

        $where = '';
        $params = [];
        if ( $type ) {
            $where = 'WHERE type = %s';
            $params[] = $type;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                ...$params
            )
        );
    }

    public static function get_count( string $type = '' ) {
        global $wpdb;
        $table = self::get_table();

        if ( $type ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE type = %s",
                $type
            ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    public static function clear_logs( int $days = 30 ) {
        global $wpdb;
        $table = self::get_table();
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }
}
