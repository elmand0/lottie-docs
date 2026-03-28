<?php
defined( 'ABSPATH' ) || exit;

class WC_Kosatec_API_Client {

    private $customer_id;
    private $edi_key;

    public function __construct() {
        $this->customer_id = get_option( 'wc_kosatec_customer_id', '' );
        $this->edi_key     = get_option( 'wc_kosatec_edi_key', '' );
    }

    public function is_configured(): bool {
        return ! empty( $this->customer_id ) && ! empty( $this->edi_key );
    }

    public function get_price_list_url( string $format = 'csv' ): string {
        $ext = in_array( $format, [ 'txt', 'csv', 'xml' ], true ) ? $format : 'csv';
        return sprintf(
            'https://data.kosatec.de/%s/%s/preisliste.%s',
            rawurlencode( $this->customer_id ),
            rawurlencode( $this->edi_key ),
            $ext
        );
    }

    public function fetch_price_list( string $format = 'csv' ): array {
        $url = $this->get_price_list_url( $format );

        $response = wp_remote_get( $url, [
            'timeout'   => 120,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            WC_Kosatec_Logger::error( 'Price list fetch failed', [ 'error' => $response->get_error_message() ] );
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            WC_Kosatec_Logger::error( 'Price list HTTP error', [ 'code' => $code ] );
            return [ 'success' => false, 'error' => "HTTP {$code}" ];
        }

        $body = wp_remote_retrieve_body( $response );

        if ( 'csv' === $format ) {
            return [ 'success' => true, 'data' => $this->parse_csv( $body ) ];
        }

        return [ 'success' => true, 'data' => $body ];
    }

    public function get_product_data_url(): string {
        return sprintf(
            'https://data.kosatec.de/%s/%s/artikeldaten.txt',
            rawurlencode( $this->customer_id ),
            rawurlencode( $this->edi_key )
        );
    }

    public function fetch_product_data(): array {
        $url = $this->get_product_data_url();

        $response = wp_remote_get( $url, [
            'timeout'   => 300,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            WC_Kosatec_Logger::error( 'Product data fetch failed', [ 'error' => $response->get_error_message() ] );
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            WC_Kosatec_Logger::error( 'Product data HTTP error', [ 'code' => $code ] );
            return [ 'success' => false, 'error' => "HTTP {$code}" ];
        }

        $body = wp_remote_retrieve_body( $response );
        return [ 'success' => true, 'data' => $this->parse_product_data( $body ) ];
    }

    public function get_realtime_info( string $sku ): array {
        $url = add_query_arg( [
            'out'  => 'xml',
            'cid'  => $this->customer_id,
            'pass' => $this->edi_key,
            'sku'  => $sku,
        ], 'https://www.kosatec.de/web/rta/' );

        $response = wp_remote_get( $url, [
            'timeout'   => 30,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $body = wp_remote_retrieve_body( $response );
        $xml  = @simplexml_load_string( $body );

        if ( ! $xml || ! isset( $xml->item ) ) {
            return [ 'success' => false, 'error' => 'Invalid XML response' ];
        }

        return [
            'success' => true,
            'data'    => [
                'sku'   => (string) $xml->item->sku,
                'stock' => (int) $xml->item->stock,
                'price' => (float) $xml->item->price,
                'eta'   => (string) ( $xml->item->eta ?? '' ),
            ],
        ];
    }

    public function add_order( array $order_data ): array {
        return $this->edi_request( 'addOrder', $order_data );
    }

    public function get_order_list( string $start_date = '', string $end_date = '' ): array {
        $data = [];
        if ( $start_date ) { $data['start_date'] = $start_date; }
        if ( $end_date ) { $data['end_date'] = $end_date; }
        return $this->edi_request( 'getOrderList', $data );
    }

    public function get_order_detail( string $order_id ): array {
        return $this->edi_request( 'getOrderDetail', [ 'order_id' => $order_id ] );
    }

    public function get_invoice_list( string $start_date = '', string $end_date = '' ): array {
        $data = [];
        if ( $start_date ) { $data['start_date'] = $start_date; }
        if ( $end_date ) { $data['end_date'] = $end_date; }
        return $this->edi_request( 'getInvoiceList', $data );
    }

    public function get_invoice_detail( string $invoice_id ): array {
        return $this->edi_request( 'getInvoiceDetail', [ 'invoice_id' => $invoice_id ] );
    }

    public function test_connection(): array {
        $url = $this->get_price_list_url( 'csv' );

        $response = wp_remote_head( $url, [
            'timeout'   => 15,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 === $code ) {
            return [ 'success' => true ];
        }

        return [ 'success' => false, 'error' => "HTTP {$code}" ];
    }

    private function edi_request( string $endpoint, array $extra_data = [] ): array {
        $url = "https://edi.kosatec.de/v1/{$endpoint}";

        $xml = new SimpleXMLElement( '<root/>' );
        $xml->addChild( 'cid', $this->customer_id );
        $xml->addChild( 'pass', $this->edi_key );

        $this->array_to_xml( $extra_data, $xml );

        $response = wp_remote_post( $url, [
            'timeout'   => 60,
            'sslverify' => true,
            'headers'   => [ 'Content-Type' => 'application/xml' ],
            'body'      => $xml->asXML(),
        ] );

        if ( is_wp_error( $response ) ) {
            WC_Kosatec_Logger::error( "EDI {$endpoint} failed", [ 'error' => $response->get_error_message() ] );
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $body     = wp_remote_retrieve_body( $response );
        $resp_xml = @simplexml_load_string( $body );

        if ( ! $resp_xml ) {
            return [ 'success' => false, 'error' => 'Invalid XML response' ];
        }

        $status = (string) ( $resp_xml->status ?? 'ERROR' );
        $code   = (int) ( $resp_xml->code ?? 0 );

        if ( 'OK' !== $status || 100 !== $code ) {
            $error_msg = $this->get_error_message( $code );
            WC_Kosatec_Logger::error( "EDI {$endpoint} error", [ 'code' => $code, 'message' => $error_msg ] );
            return [ 'success' => false, 'error' => $error_msg, 'code' => $code ];
        }

        return [ 'success' => true, 'data' => $this->xml_to_array( $resp_xml ) ];
    }

    private function parse_csv( string $body ): array {
        $lines    = explode( "\n", trim( $body ) );
        $products = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) { continue; }

            $fields = str_getcsv( $line, ';' );
            if ( count( $fields ) < 14 ) { continue; }

            $products[] = [
                'sku'          => $fields[0] ?? '',
                'mpn'          => $fields[1] ?? '',
                'name'         => $fields[2] ?? '',
                'manufacturer' => $fields[3] ?? '',
                'mfr_url'      => $fields[4] ?? '',
                'ean'          => $fields[5] ?? '',
                'price'        => (float) ( $fields[6] ?? 0 ),
                'retail_price' => (float) ( $fields[7] ?? 0 ),
                'availability' => $fields[8] ?? '',
                'stock'        => (int) ( $fields[9] ?? 0 ),
                'eta'          => $fields[10] ?? '',
                'created_date' => $fields[11] ?? '',
                'weight'       => (float) ( $fields[12] ?? 0 ),
                'eol'          => (int) ( $fields[13] ?? 0 ),
                'cat1'         => $fields[14] ?? '',
                'cat2'         => $fields[15] ?? '',
                'cat3'         => $fields[16] ?? '',
                'cat4'         => $fields[17] ?? '',
                'cat5'         => $fields[18] ?? '',
                'cat6'         => $fields[19] ?? '',
            ];
        }

        return $products;
    }

    private function parse_product_data( string $body ): array {
        $lines    = explode( "\n", trim( $body ) );
        $products = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) { continue; }

            $fields = explode( "\t", $line );
            if ( count( $fields ) < 20 ) { continue; }

            $products[] = [
                'sku'             => $fields[0] ?? '',
                'mpn'             => $fields[1] ?? '',
                'name'            => $fields[2] ?? '',
                'manufacturer'    => $fields[3] ?? '',
                'mfr_url'         => $fields[4] ?? '',
                'ean'             => $fields[5] ?? '',
                'price'           => (float) ( $fields[6] ?? 0 ),
                'retail_price'    => (float) ( $fields[7] ?? 0 ),
                'availability'    => $fields[8] ?? '',
                'stock'           => (int) ( $fields[9] ?? 0 ),
                'eta'             => $fields[10] ?? '',
                'created_date'    => $fields[11] ?? '',
                'weight'          => (float) ( $fields[12] ?? 0 ),
                'eol'             => (int) ( $fields[13] ?? 0 ),
                'cat1'            => $fields[14] ?? '',
                'cat2'            => $fields[15] ?? '',
                'cat3'            => $fields[16] ?? '',
                'cat4'            => $fields[17] ?? '',
                'cat5'            => $fields[18] ?? '',
                'cat6'            => $fields[19] ?? '',
                'title'           => $fields[20] ?? '',
                'short_desc'      => $fields[21] ?? '',
                'short_summary'   => $fields[22] ?? '',
                'long_summary'    => $fields[23] ?? '',
                'marketing_text'  => $fields[24] ?? '',
                'specs'           => $fields[25] ?? '',
                'pdf_url'         => $fields[26] ?? '',
                'manual_url'      => $fields[27] ?? '',
                'image_s'         => $fields[28] ?? '',
                'image_m'         => $fields[29] ?? '',
                'image_l'         => $fields[30] ?? '',
                'image_xl'        => $fields[31] ?? '',
            ];
        }

        return $products;
    }

    private function array_to_xml( array $data, SimpleXMLElement $xml ): void {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                if ( is_numeric( $key ) ) { $key = 'item'; }
                $child = $xml->addChild( $key );
                $this->array_to_xml( $value, $child );
            } else {
                $xml->addChild( (string) $key, htmlspecialchars( (string) $value, ENT_XML1, 'UTF-8' ) );
            }
        }
    }

    private function xml_to_array( SimpleXMLElement $xml ): array {
        $result = [];
        foreach ( $xml->children() as $key => $value ) {
            if ( $value->count() > 0 ) {
                if ( isset( $result[ $key ] ) ) {
                    if ( ! is_array( $result[ $key ] ) || ! isset( $result[ $key ][0] ) ) {
                        $result[ $key ] = [ $result[ $key ] ];
                    }
                    $result[ $key ][] = $this->xml_to_array( $value );
                } else {
                    $result[ $key ] = $this->xml_to_array( $value );
                }
            } else {
                if ( isset( $result[ $key ] ) ) {
                    if ( ! is_array( $result[ $key ] ) ) {
                        $result[ $key ] = [ $result[ $key ] ];
                    }
                    $result[ $key ][] = (string) $value;
                } else {
                    $result[ $key ] = (string) $value;
                }
            }
        }
        return $result;
    }

    private function get_error_message( int $code ): string {
        $errors = [
            100 => __( 'Transmission OK', 'wc-kosatec-import' ),
            101 => __( 'Service temporarily not available', 'wc-kosatec-import' ),
            102 => __( 'Wrong XML format', 'wc-kosatec-import' ),
            103 => __( 'Wrong login credentials', 'wc-kosatec-import' ),
            104 => __( 'Shipping address incomplete', 'wc-kosatec-import' ),
            105 => __( 'Product not found', 'wc-kosatec-import' ),
            106 => __( 'Passed purchase price not up to date', 'wc-kosatec-import' ),
            107 => __( 'Customer number is not activated for EDI orders', 'wc-kosatec-import' ),
            108 => __( 'Order not found', 'wc-kosatec-import' ),
            109 => __( 'Wrong country code', 'wc-kosatec-import' ),
            110 => __( 'No SSL connection', 'wc-kosatec-import' ),
            111 => __( 'Order reference already used', 'wc-kosatec-import' ),
            112 => __( 'Internal error', 'wc-kosatec-import' ),
            113 => __( 'No ESD order possible currently', 'wc-kosatec-import' ),
            114 => __( 'You are not able to order ESD. Please contact your Sales Manager.', 'wc-kosatec-import' ),
        ];

        return $errors[ $code ] ?? __( 'Unknown error', 'wc-kosatec-import' );
    }
}
