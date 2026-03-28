<?php
defined( 'ABSPATH' ) || exit;

class WC_Kosatec_Product_Importer {

    private $api;
    private $stats = [
        'created'  => 0,
        'updated'  => 0,
        'skipped'  => 0,
        'errors'   => 0,
        'variants' => 0,
    ];

    public function __construct() {
        $this->api = new WC_Kosatec_API_Client();
    }

    public function run_full_import( array $options = [] ): array {
        $defaults = [
            'import_images'       => true,
            'update_existing'     => true,
            'skip_eol'            => (bool) get_option( 'wc_kosatec_skip_eol', false ),
            'category_filter'     => get_option( 'wc_kosatec_category_filter', '' ),
            'manufacturer_filter' => get_option( 'wc_kosatec_manufacturer_filter', '' ),
            'enable_variants'     => (bool) get_option( 'wc_kosatec_enable_variants', true ),
            'batch_size'          => (int) get_option( 'wc_kosatec_batch_size', 50 ),
        ];
        $options = wp_parse_args( $options, $defaults );

        WC_Kosatec_Logger::info( 'Full import started', $options );

        $result = $this->api->fetch_product_data();
        if ( ! $result['success'] ) {
            WC_Kosatec_Logger::error( 'Import aborted: failed to fetch product data', [ 'error' => $result['error'] ] );
            return [ 'success' => false, 'error' => $result['error'] ];
        }

        $products = $result['data'];
        WC_Kosatec_Logger::info( sprintf( 'Fetched %d products from Kosatec', count( $products ) ) );

        $products = $this->apply_filters( $products, $options );
        WC_Kosatec_Logger::info( sprintf( '%d products after filtering', count( $products ) ) );

        if ( $options['enable_variants'] ) {
            $groups = WC_Kosatec_Variant_Grouper::group_products( $products );
        } else {
            $groups = array_map( function ( $p ) {
                return [ 'type' => 'simple', 'products' => [ $p ], 'attributes' => [] ];
            }, $products );
        }

        foreach ( $groups as $group ) {
            try {
                if ( 'variable' === $group['type'] && count( $group['products'] ) > 1 ) {
                    $this->import_variable_product( $group, $options );
                } else {
                    foreach ( $group['products'] as $product_data ) {
                        $this->import_simple_product( $product_data, $options );
                    }
                }
            } catch ( \Exception $e ) {
                $this->stats['errors']++;
                WC_Kosatec_Logger::error( 'Import exception', [
                    'message' => $e->getMessage(),
                    'sku'     => $group['products'][0]['sku'] ?? 'unknown',
                ] );
            }
        }

        WC_Kosatec_Logger::success( 'Full import completed', $this->stats );
        update_option( 'wc_kosatec_last_full_import', current_time( 'mysql' ) );

        return [ 'success' => true, 'stats' => $this->stats ];
    }

    public function run_price_stock_update(): array {
        WC_Kosatec_Logger::info( 'Price/stock update started' );

        $result = $this->api->fetch_price_list( 'csv' );
        if ( ! $result['success'] ) {
            WC_Kosatec_Logger::error( 'Price update failed: cannot fetch price list' );
            return [ 'success' => false, 'error' => $result['error'] ];
        }

        $updated = 0;
        $skipped = 0;

        foreach ( $result['data'] as $item ) {
            $product_id = $this->find_product_by_kosatec_sku( $item['sku'] );
            if ( ! $product_id ) { $skipped++; continue; }

            $product = wc_get_product( $product_id );
            if ( ! $product ) { $skipped++; continue; }

            $changed = false;

            $sell_price = $this->calculate_sell_price( $item['price'] );
            if ( (float) $product->get_regular_price() !== $sell_price ) {
                $product->set_regular_price( $sell_price );
                $changed = true;
            }

            $new_stock = $item['stock'];
            if ( $product->get_stock_quantity() !== $new_stock ) {
                $product->set_stock_quantity( $new_stock );
                $product->set_manage_stock( true );
                $product->set_stock_status( $new_stock > 0 ? 'instock' : 'outofstock' );
                $changed = true;
            }

            update_post_meta( $product_id, '_kosatec_availability', sanitize_text_field( $item['availability'] ) );
            if ( ! empty( $item['eta'] ) ) {
                update_post_meta( $product_id, '_kosatec_eta', sanitize_text_field( $item['eta'] ) );
            }

            if ( $changed ) { $product->save(); $updated++; } else { $skipped++; }
        }

        $stats = [ 'updated' => $updated, 'skipped' => $skipped ];
        WC_Kosatec_Logger::success( 'Price/stock update completed', $stats );
        update_option( 'wc_kosatec_last_price_update', current_time( 'mysql' ) );

        return [ 'success' => true, 'stats' => $stats ];
    }

    private function import_simple_product( array $data, array $options ): void {
        $existing_id = $this->find_product_by_kosatec_sku( $data['sku'] );

        if ( $existing_id && ! $options['update_existing'] ) {
            $this->stats['skipped']++;
            return;
        }

        if ( $existing_id ) {
            $product = wc_get_product( $existing_id );
            if ( ! $product ) { $product = new WC_Product_Simple(); }
        } else {
            if ( ! empty( $data['ean'] ) ) {
                $ean_id = $this->find_product_by_ean( $data['ean'] );
                if ( $ean_id ) { $this->stats['skipped']++; return; }
            }
            $product = new WC_Product_Simple();
        }

        $this->set_product_data( $product, $data );
        $product->save();

        $product_id = $product->get_id();
        $this->save_kosatec_meta( $product_id, $data );
        $this->assign_categories( $product_id, $data );

        if ( $options['import_images'] && ! empty( $data['image_xl'] ) ) {
            $image_source = $data['image_xl'];
            if ( empty( $image_source ) ) { $image_source = $data['image_l'] ?? ''; }
            $attachment_ids = WC_Kosatec_Image_Handler::process_images( $product_id, $image_source );
            WC_Kosatec_Image_Handler::set_product_images( $product_id, $attachment_ids );
        }

        if ( $existing_id ) { $this->stats['updated']++; } else { $this->stats['created']++; }
    }

    private function import_variable_product( array $group, array $options ): void {
        $products   = $group['products'];
        $attributes = $group['attributes'];
        $first      = $products[0];

        $parent_sku = 'KOSATEC-VAR-' . md5( $first['manufacturer'] . $first['mpn'] );
        $parent_id  = $this->find_product_by_kosatec_sku( $parent_sku );

        if ( $parent_id ) {
            $parent = wc_get_product( $parent_id );
            if ( ! $parent || ! $parent->is_type( 'variable' ) ) { $parent = new WC_Product_Variable(); }
        } else {
            $parent = new WC_Product_Variable();
        }

        $parent_name = $first['title'] ?: $first['name'];
        $parent->set_name( sanitize_text_field( $parent_name ) );
        $parent->set_sku( $parent_sku );
        $parent->set_status( 'publish' );
        $parent->set_catalog_visibility( 'visible' );

        if ( ! empty( $first['short_desc'] ) ) { $parent->set_short_description( wp_kses_post( $first['short_desc'] ) ); }
        if ( ! empty( $first['long_summary'] ) ) { $parent->set_description( wp_kses_post( $first['long_summary'] ) ); }
        if ( $first['weight'] > 0 ) { $parent->set_weight( $first['weight'] ); }

        $wc_attributes = [];
        $attr_label_map = [
            'color'   => __( 'Color', 'wc-kosatec-import' ),
            'storage' => __( 'Storage', 'wc-kosatec-import' ),
            'size'    => __( 'Size', 'wc-kosatec-import' ),
        ];

        foreach ( $attributes as $attr_key ) {
            $values = [];
            foreach ( $products as $p ) {
                $val = $p['_variant_attrs'][ $attr_key ] ?? '';
                if ( $val ) { $values[] = $val; }
            }
            $values = array_unique( $values );

            $wc_attr = new WC_Product_Attribute();
            $wc_attr->set_name( $attr_label_map[ $attr_key ] ?? ucfirst( $attr_key ) );
            $wc_attr->set_options( $values );
            $wc_attr->set_visible( true );
            $wc_attr->set_variation( true );
            $wc_attributes[] = $wc_attr;
        }

        $parent->set_attributes( $wc_attributes );
        $parent->save();
        $parent_id = $parent->get_id();

        $this->assign_categories( $parent_id, $first );

        if ( $options['import_images'] && ! empty( $first['image_xl'] ) ) {
            $attachment_ids = WC_Kosatec_Image_Handler::process_images( $parent_id, $first['image_xl'] );
            WC_Kosatec_Image_Handler::set_product_images( $parent_id, $attachment_ids );
        }

        foreach ( $products as $variant_data ) {
            $this->create_or_update_variation( $parent_id, $variant_data, $attributes, $attr_label_map, $options );
            $this->stats['variants']++;
        }

        WC_Product_Variable::sync( $parent_id );
        $this->stats['created']++;
    }

    private function create_or_update_variation( int $parent_id, array $data, array $attributes, array $attr_label_map, array $options ): void {
        $existing_id = $this->find_product_by_kosatec_sku( $data['sku'] );

        if ( $existing_id ) {
            $variation = wc_get_product( $existing_id );
            if ( ! $variation || ! $variation->is_type( 'variation' ) ) { $variation = new WC_Product_Variation(); }
        } else {
            $variation = new WC_Product_Variation();
        }

        $variation->set_parent_id( $parent_id );
        $variation->set_sku( $data['sku'] );
        $variation->set_status( 'publish' );

        $sell_price = $this->calculate_sell_price( $data['price'] );
        $variation->set_regular_price( $sell_price );

        $variation->set_manage_stock( true );
        $variation->set_stock_quantity( $data['stock'] );
        $variation->set_stock_status( $data['stock'] > 0 ? 'instock' : 'outofstock' );

        if ( $data['weight'] > 0 ) { $variation->set_weight( $data['weight'] ); }

        if ( ! empty( $data['ean'] ) && method_exists( $variation, 'set_global_unique_id' ) ) {
            $variation->set_global_unique_id( $data['ean'] );
        }

        $var_attrs = [];
        foreach ( $attributes as $attr_key ) {
            $label = $attr_label_map[ $attr_key ] ?? ucfirst( $attr_key );
            $value = $data['_variant_attrs'][ $attr_key ] ?? '';
            $var_attrs[ sanitize_title( $label ) ] = sanitize_text_field( $value );
        }
        $variation->set_attributes( $var_attrs );

        $variation->save();
        $this->save_kosatec_meta( $variation->get_id(), $data );

        if ( $options['import_images'] && ! empty( $data['image_xl'] ) ) {
            $first_url = explode( ';', $data['image_xl'] )[0];
            $att_ids   = WC_Kosatec_Image_Handler::process_images( $variation->get_id(), $first_url );
            if ( ! empty( $att_ids ) ) {
                $variation->set_image_id( $att_ids[0] );
                $variation->save();
            }
        }
    }

    private function set_product_data( WC_Product $product, array $data ): void {
        $name = ! empty( $data['title'] ) ? $data['title'] : $data['name'];
        $product->set_name( sanitize_text_field( $name ) );
        $product->set_sku( $data['sku'] );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );

        $sell_price = $this->calculate_sell_price( $data['price'] );
        $product->set_regular_price( $sell_price );

        $product->set_manage_stock( true );
        $product->set_stock_quantity( $data['stock'] );
        $product->set_stock_status( $data['stock'] > 0 ? 'instock' : 'outofstock' );

        if ( $data['weight'] > 0 ) { $product->set_weight( $data['weight'] ); }

        if ( ! empty( $data['long_summary'] ) ) {
            $product->set_description( wp_kses_post( $data['long_summary'] ) );
        } elseif ( ! empty( $data['specs'] ) ) {
            $product->set_description( wp_kses_post( $data['specs'] ) );
        }

        if ( ! empty( $data['short_desc'] ) ) {
            $product->set_short_description( wp_kses_post( $data['short_desc'] ) );
        } elseif ( ! empty( $data['short_summary'] ) ) {
            $product->set_short_description( wp_kses_post( $data['short_summary'] ) );
        }

        if ( ! empty( $data['ean'] ) && method_exists( $product, 'set_global_unique_id' ) ) {
            $product->set_global_unique_id( $data['ean'] );
        }
    }

    private function save_kosatec_meta( int $product_id, array $data ): void {
        $meta_map = [
            '_kosatec_sku'            => $data['sku'],
            '_kosatec_mpn'            => $data['mpn'],
            '_kosatec_ean'            => $data['ean'] ?? '',
            '_kosatec_manufacturer'   => $data['manufacturer'],
            '_kosatec_mfr_url'        => $data['mfr_url'] ?? '',
            '_kosatec_availability'   => $data['availability'] ?? '',
            '_kosatec_eta'            => $data['eta'] ?? '',
            '_kosatec_eol'            => $data['eol'] ?? 0,
            '_kosatec_purchase_price' => $data['price'] ?? 0,
            '_kosatec_pdf_url'        => $data['pdf_url'] ?? '',
            '_kosatec_manual_url'     => $data['manual_url'] ?? '',
            '_kosatec_last_sync'      => current_time( 'mysql' ),
        ];

        foreach ( $meta_map as $key => $value ) {
            update_post_meta( $product_id, $key, sanitize_text_field( (string) $value ) );
        }
    }

    private function assign_categories( int $product_id, array $data ): void {
        $cat_names = array_filter( [
            $data['cat1'] ?? '', $data['cat2'] ?? '', $data['cat3'] ?? '',
            $data['cat4'] ?? '', $data['cat5'] ?? '', $data['cat6'] ?? '',
        ] );

        if ( empty( $cat_names ) ) { return; }

        $cat_ids   = [];
        $parent_id = 0;

        foreach ( $cat_names as $cat_name ) {
            $cat_name = sanitize_text_field( $cat_name );
            $term     = get_term_by( 'name', $cat_name, 'product_cat' );

            if ( ! $term ) {
                $inserted = wp_insert_term( $cat_name, 'product_cat', [ 'parent' => $parent_id ] );
                if ( is_wp_error( $inserted ) ) { break; }
                $cat_ids[] = $inserted['term_id'];
                $parent_id = $inserted['term_id'];
            } else {
                $cat_ids[] = $term->term_id;
                $parent_id = $term->term_id;
            }
        }

        if ( ! empty( $cat_ids ) ) {
            wp_set_object_terms( $product_id, $cat_ids, 'product_cat' );
        }

        if ( ! empty( $data['manufacturer'] ) ) {
            wp_set_object_terms( $product_id, sanitize_text_field( $data['manufacturer'] ), 'product_tag', true );
        }
    }

    public function find_product_by_kosatec_sku( string $sku ): int {
        global $wpdb;
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kosatec_sku' AND meta_value = %s LIMIT 1",
            $sku
        ) );

        if ( $product_id ) { return (int) $product_id; }

        $product_id = wc_get_product_id_by_sku( $sku );
        return $product_id ? (int) $product_id : 0;
    }

    private function find_product_by_ean( string $ean ): int {
        global $wpdb;
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kosatec_ean' AND meta_value = %s LIMIT 1",
            $ean
        ) );
        return (int) $product_id;
    }

    private function calculate_sell_price( float $purchase_price ): float {
        $markup_type  = get_option( 'wc_kosatec_markup_type', 'percentage' );
        $markup_value = (float) get_option( 'wc_kosatec_markup_value', 20 );

        if ( 'fixed' === $markup_type ) {
            return round( $purchase_price + $markup_value, 2 );
        }

        return round( $purchase_price * ( 1 + $markup_value / 100 ), 2 );
    }

    private function apply_filters( array $products, array $options ): array {
        if ( $options['skip_eol'] ) {
            $products = array_filter( $products, function ( $p ) {
                return empty( $p['eol'] ) || 0 === (int) $p['eol'];
            } );
        }

        if ( ! empty( $options['category_filter'] ) ) {
            $allowed = array_map( 'trim', explode( ',', strtolower( $options['category_filter'] ) ) );
            $products = array_filter( $products, function ( $p ) use ( $allowed ) {
                $cat = strtolower( $p['cat1'] ?? '' );
                return in_array( $cat, $allowed, true );
            } );
        }

        if ( ! empty( $options['manufacturer_filter'] ) ) {
            $allowed = array_map( 'trim', explode( ',', strtolower( $options['manufacturer_filter'] ) ) );
            $products = array_filter( $products, function ( $p ) use ( $allowed ) {
                $mfr = strtolower( $p['manufacturer'] ?? '' );
                return in_array( $mfr, $allowed, true );
            } );
        }

        return array_values( $products );
    }
}
