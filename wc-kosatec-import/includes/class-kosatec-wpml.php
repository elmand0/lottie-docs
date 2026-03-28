<?php
defined( 'ABSPATH' ) || exit;

class WC_Kosatec_WPML {

    private static $default_language;

    public static function init() {
        if ( ! self::is_wpml_active() ) { return; }

        self::$default_language = apply_filters( 'wpml_default_language', null );

        add_action( 'kosatec_after_product_import', [ __CLASS__, 'register_product_for_translation' ], 10, 2 );
        add_action( 'wpml_after_duplicate_product', [ __CLASS__, 'copy_meta_to_translation' ], 10, 2 );
        add_action( 'kosatec_before_import', [ __CLASS__, 'switch_to_default_language' ] );
        add_action( 'kosatec_after_import', [ __CLASS__, 'restore_language' ] );
        add_filter( 'kosatec_find_product_id', [ __CLASS__, 'get_default_language_product' ], 10, 2 );
    }

    public static function is_wpml_active(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress' );
    }

    public static function is_wcml_active(): bool {
        return class_exists( 'woocommerce_wpml' ) || class_exists( 'WCML_WC_Strings' );
    }

    public static function get_default_language(): string {
        if ( ! self::$default_language ) {
            self::$default_language = apply_filters( 'wpml_default_language', 'en' );
        }
        return self::$default_language;
    }

    public static function get_active_languages(): array {
        if ( ! self::is_wpml_active() ) { return []; }
        return apply_filters( 'wpml_active_languages', [], 'skip_missing=0' );
    }

    public static function register_product_for_translation( int $product_id, array $data ) {
        if ( ! self::is_wpml_active() ) { return; }

        $default_lang = self::get_default_language();

        do_action( 'wpml_set_element_language_details', [
            'element_id'    => $product_id,
            'element_type'  => 'post_product',
            'trid'          => false,
            'language_code' => $default_lang,
        ] );

        if ( get_option( 'wc_kosatec_wpml_auto_duplicate', false ) ) {
            self::auto_duplicate_product( $product_id );
        }
    }

    public static function auto_duplicate_product( int $product_id ) {
        if ( ! self::is_wpml_active() ) { return; }

        $languages    = self::get_active_languages();
        $default_lang = self::get_default_language();

        foreach ( $languages as $lang_code => $lang_info ) {
            if ( $lang_code === $default_lang ) { continue; }

            $translated_id = apply_filters( 'wpml_object_id', $product_id, 'product', false, $lang_code );
            if ( $translated_id ) { continue; }

            do_action( 'wpml_make_post_duplicates', $product_id );
            break;
        }
    }

    public static function copy_meta_to_translation( int $original_id, int $translated_id ) {
        $meta_keys = [
            '_kosatec_sku', '_kosatec_mpn', '_kosatec_ean', '_kosatec_manufacturer',
            '_kosatec_mfr_url', '_kosatec_availability', '_kosatec_eta', '_kosatec_eol',
            '_kosatec_purchase_price', '_kosatec_pdf_url', '_kosatec_manual_url', '_kosatec_last_sync',
        ];

        foreach ( $meta_keys as $key ) {
            $value = get_post_meta( $original_id, $key, true );
            if ( '' !== $value ) { update_post_meta( $translated_id, $key, $value ); }
        }
    }

    public static function switch_to_default_language() {
        if ( self::is_wpml_active() ) { do_action( 'wpml_switch_language', self::get_default_language() ); }
    }

    public static function restore_language() {
        if ( self::is_wpml_active() ) { do_action( 'wpml_switch_language', null ); }
    }

    public static function get_default_language_product( int $product_id, string $sku ): int {
        if ( ! self::is_wpml_active() || ! $product_id ) { return $product_id; }

        $default_lang = self::get_default_language();
        $default_id   = apply_filters( 'wpml_object_id', $product_id, 'product', true, $default_lang );

        return $default_id ?: $product_id;
    }

    public static function get_translatable_fields(): array {
        return [
            'post_title'         => __( 'Product Name', 'wc-kosatec-import' ),
            'post_content'       => __( 'Description', 'wc-kosatec-import' ),
            'post_excerpt'       => __( 'Short Description', 'wc-kosatec-import' ),
            '_kosatec_specs'     => __( 'Specifications', 'wc-kosatec-import' ),
            '_kosatec_marketing' => __( 'Marketing Text', 'wc-kosatec-import' ),
        ];
    }

    public static function get_translation_status( int $product_id ): array {
        if ( ! self::is_wpml_active() ) { return []; }

        $languages = self::get_active_languages();
        $status    = [];

        foreach ( $languages as $lang_code => $lang_info ) {
            $translated_id = apply_filters( 'wpml_object_id', $product_id, 'product', false, $lang_code );
            $status[ $lang_code ] = [
                'name'       => $lang_info['native_name'] ?? $lang_code,
                'translated' => (bool) $translated_id,
                'product_id' => $translated_id ?: null,
            ];
        }

        return $status;
    }
}

add_action( 'init', [ 'WC_Kosatec_WPML', 'init' ], 5 );
