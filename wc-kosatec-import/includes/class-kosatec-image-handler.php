<?php
defined( 'ABSPATH' ) || exit;

class WC_Kosatec_Image_Handler {

    public static function process_images( int $product_id, string $image_urls ): array {
        if ( empty( $image_urls ) ) {
            return [];
        }

        $urls          = array_filter( array_map( 'trim', explode( ';', $image_urls ) ) );
        $attachment_ids = [];

        foreach ( $urls as $url ) {
            $url = esc_url_raw( $url );
            if ( empty( $url ) ) {
                continue;
            }

            $existing = self::find_existing_attachment( $url );
            if ( $existing ) {
                $attachment_ids[] = $existing;
                continue;
            }

            $attachment_id = self::download_image( $url, $product_id );
            if ( $attachment_id ) {
                $attachment_ids[] = $attachment_id;
            }
        }

        return $attachment_ids;
    }

    public static function set_product_images( int $product_id, array $attachment_ids ): void {
        if ( empty( $attachment_ids ) ) {
            return;
        }

        set_post_thumbnail( $product_id, $attachment_ids[0] );

        if ( count( $attachment_ids ) > 1 ) {
            $gallery = array_slice( $attachment_ids, 1 );
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
        }
    }

    private static function find_existing_attachment( string $url ): int {
        global $wpdb;
        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kosatec_source_url' AND meta_value = %s LIMIT 1",
            $url
        ) );
        return (int) $attachment_id;
    }

    private static function download_image( string $url, int $product_id ): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            WC_Kosatec_Logger::error( 'Image download failed', [
                'url'   => $url,
                'error' => $tmp->get_error_message(),
            ] );
            return 0;
        }

        $filename  = basename( wp_parse_url( $url, PHP_URL_PATH ) );
        $file_array = [
            'name'     => sanitize_file_name( $filename ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            WC_Kosatec_Logger::error( 'Image sideload failed', [
                'url'   => $url,
                'error' => $attachment_id->get_error_message(),
            ] );
            return 0;
        }

        update_post_meta( $attachment_id, '_kosatec_source_url', $url );

        return $attachment_id;
    }
}
