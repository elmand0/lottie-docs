<?php
defined( 'ABSPATH' ) || exit;

class WC_Kosatec_Variant_Grouper {

    private static $color_keywords = [
        'black', 'white', 'red', 'blue', 'green', 'yellow', 'silver', 'gold', 'grey', 'gray',
        'pink', 'purple', 'orange', 'brown', 'schwarz', 'weiss', 'rot', 'blau', 'gruen',
        'gelb', 'silber', 'grau', 'rosa', 'lila', 'braun', 'midnight', 'space gray',
        'space grey', 'starlight', 'graphite', 'sierra blue', 'alpine green', 'deep purple',
        'coral', 'mint', 'cream', 'phantom', 'titanium', 'natural', 'desert',
    ];

    private static $storage_pattern = '/\b(\d+)\s*(GB|TB|MB|gb|tb|mb)\b/';
    private static $ram_pattern     = '/\b(\d+)\s*(GB|MB)\s*(RAM|DDR\d?)\b/i';
    private static $size_keywords   = [
        '11"', '12"', '13"', '14"', '15"', '16"', '17"',
        '11-inch', '12-inch', '13-inch', '14-inch', '15-inch', '16-inch', '17-inch',
        '24"', '27"', '32"', '34"', '40"', '43"', '49"', '55"', '65"', '75"',
    ];

    public static function group_products( array $products ): array {
        $groups = [];

        foreach ( $products as $product ) {
            $key = self::generate_group_key( $product );
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = [ 'key' => $key, 'products' => [] ];
            }
            $groups[ $key ]['products'][] = $product;
        }

        $result = [];
        foreach ( $groups as $group ) {
            if ( count( $group['products'] ) <= 1 ) {
                $result[] = [ 'type' => 'simple', 'products' => $group['products'], 'attributes' => [] ];
            } else {
                $attrs = self::detect_varying_attributes( $group['products'] );
                if ( empty( $attrs ) ) {
                    $result[] = [ 'type' => 'simple', 'products' => $group['products'], 'attributes' => [] ];
                } else {
                    $result[] = [ 'type' => 'variable', 'products' => $group['products'], 'attributes' => $attrs ];
                }
            }
        }

        return $result;
    }

    private static function generate_group_key( array $product ): string {
        $manufacturer = strtolower( trim( $product['manufacturer'] ?? '' ) );
        $mpn          = strtolower( trim( $product['mpn'] ?? '' ) );
        $name         = strtolower( trim( $product['name'] ?? '' ) );

        $base_mpn = preg_replace( '/[-\/]\w{1,4}$/', '', $mpn );
        $base_name = self::strip_variant_info( $name );

        return md5( $manufacturer . '|' . $base_mpn . '|' . $base_name );
    }

    private static function strip_variant_info( string $name ): string {
        $name = strtolower( $name );

        foreach ( self::$color_keywords as $color ) {
            $name = str_ireplace( $color, '', $name );
        }

        $name = preg_replace( self::$storage_pattern, '', $name );

        foreach ( self::$size_keywords as $size ) {
            $name = str_ireplace( $size, '', $name );
        }

        return trim( preg_replace( '/\s+/', ' ', $name ) );
    }

    private static function detect_varying_attributes( array $products ): array {
        $attributes = [];

        $extracted = [];
        foreach ( $products as $product ) {
            $name = $product['name'] ?? '';
            $vals = [
                'color'   => self::extract_color( $name ),
                'storage' => self::extract_storage( $name ),
                'size'    => self::extract_size( $name ),
            ];
            $extracted[] = $vals;
        }

        foreach ( [ 'color', 'storage', 'size' ] as $attr ) {
            $values = array_column( $extracted, $attr );
            $unique = array_unique( array_filter( $values ) );
            if ( count( $unique ) > 1 ) {
                $attributes[] = $attr;
            }
        }

        foreach ( $products as $i => &$product ) {
            $product['_variant_attrs'] = [];
            foreach ( $attributes as $attr ) {
                $product['_variant_attrs'][ $attr ] = $extracted[ $i ][ $attr ] ?? '';
            }
        }

        return $attributes;
    }

    private static function extract_color( string $name ): string {
        $name_lower = strtolower( $name );
        foreach ( self::$color_keywords as $color ) {
            if ( stripos( $name_lower, strtolower( $color ) ) !== false ) {
                return ucfirst( $color );
            }
        }
        return '';
    }

    private static function extract_storage( string $name ): string {
        if ( preg_match( self::$storage_pattern, $name, $matches ) ) {
            return $matches[1] . strtoupper( $matches[2] );
        }
        return '';
    }

    private static function extract_size( string $name ): string {
        foreach ( self::$size_keywords as $size ) {
            if ( stripos( $name, $size ) !== false ) {
                return $size;
            }
        }
        return '';
    }
}
