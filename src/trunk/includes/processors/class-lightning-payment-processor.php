<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LightningPaymentProcessor
 * @extends     PaymentProcessor
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class LightningPaymentProcessor extends AbstractPaymentProcessor
{
    public function process(\WC_Order $order, array $payment_data): array
    {
        $identifier = $this->gateway->get_option('network_identifier');

        if (empty($identifier)) {
            throw new PayCryptoMeException('Lightning Network identifier is not configured in the payment gateway settings.');
        }

        $payment_data['payment_address'] = $identifier;

        //TODO: ver como fazer o fluxo abaixo

        // Gere uma invoice por pedido no seu node ou provedor Lightning (LND, Core Lightning, LNbits, BTCPay, etc.). Cada invoice inclui um payment_request (BOLT11) e um payment_hash único.
        // Persista no DB a invoice criada: order_id, payment_request, payment_hash, amount_sats, expires_at, status, provider, raw_response.
        // Mostre ao cliente o payment_request / QR (Bolt11) na página de confirmação.

        return $payment_data;
    }
}

// ==================================================
/**
 * Create a Lightning invoice via BTCPay Server.
 *
 * @param float  $amount_btc Amount in BTC
 * @param string $order_id   WooCommerce Order ID
 * @param string $currency   Original currency (e.g., 'USD')
 * @return array|WP_Error Array with invoice data on success, WP_Error on failure
 */
// public function create_btcpay_invoice( $amount_btc, $order_id, $currency = 'BTC' ) {
//     $url      = $this->get_option( 'btcpay_url' );
//     $api_key  = $this->get_option( 'btcpay_api_key' );
//     $store_id = $this->get_option( 'btcpay_store_id' );
//     $expiry   = absint( $this->get_option( 'invoice_expiry', 3600 ) );

//     if ( empty( $url ) || empty( $api_key ) || empty( $store_id ) ) {
//         return new \WP_Error( 'btcpay_config', __( 'BTCPay Server not configured properly.', 'paycrypto-me-for-woocommerce' ) );
//     }

//     $endpoint = rtrim( $url, '/' ) . '/api/v1/stores/' . rawurlencode( $store_id ) . '/invoices';

//     // Convert BTC to satoshis for amount
//     $amount_sats = intval( round( $amount_btc * 100000000 ) );

//     $body = array(
//         'amount'   => strval( $amount_sats ),
//         'currency' => 'BTC',
//         'metadata' => array(
//             'orderId'     => $order_id,
//             'itemDesc'    => sprintf( __( 'Order #%s', 'paycrypto-me-for-woocommerce' ), $order_id ),
//             'buyerEmail'  => '', // Can be populated from order
//         ),
//         'checkout' => array(
//             'speedPolicy'       => 'MediumSpeed',
//             'paymentMethods'    => array( 'BTC-LightningNetwork' ),
//             'expirationMinutes' => intval( $expiry / 60 ),
//         ),
//     );

//     $args = array(
//         'method'  => 'POST',
//         'timeout' => 30,
//         'headers' => array(
//             'Authorization' => 'token ' . $api_key,
//             'Content-Type'  => 'application/json',
//             'Accept'        => 'application/json',
//         ),
//         'body'    => wp_json_encode( $body ),
//     );

//     $response = wp_remote_post( $endpoint, $args );

//     if ( is_wp_error( $response ) ) {
//         return $response;
//     }

//     $code = wp_remote_retrieve_response_code( $response );
//     $data = json_decode( wp_remote_retrieve_body( $response ), true );

//     if ( $code < 200 || $code >= 300 ) {
//         $error_msg = isset( $data['message'] ) ? $data['message'] : __( 'BTCPay API request failed.', 'paycrypto-me-for-woocommerce' );
//         return new \WP_Error( 'btcpay_api_error', $error_msg, array( 'status' => $code ) );
//     }

//     // Extract Lightning invoice (bolt11) from BTCPay response
//     // BTCPay structure: data['checkoutLink'], data['id'], data['amount'], etc.
//     $lightning_invoice = '';
//     if ( isset( $data['paymentMethods'] ) && is_array( $data['paymentMethods'] ) ) {
//         foreach ( $data['paymentMethods'] as $method ) {
//             if ( isset( $method['paymentMethod'] ) && $method['paymentMethod'] === 'BTC-LightningNetwork' ) {
//                 $lightning_invoice = isset( $method['destination'] ) ? $method['destination'] : '';
//                 break;
//             }
//         }
//     }

//     return array(
//         'invoice_id'        => $data['id'],
//         'payment_request'   => $lightning_invoice,
//         'amount_sats'       => $amount_sats,
//         'expiry'            => $expiry,
//         'checkout_link'     => isset( $data['checkoutLink'] ) ? $data['checkoutLink'] : '',
//         'status'            => isset( $data['status'] ) ? $data['status'] : 'New',
//         'raw_response'      => $data,
//     );
// }

/**
 * Create a Lightning invoice via lnd REST API.
 *
 * @param int    $amount_sats Amount in satoshis
 * @param string $memo        Invoice description
 * @param int    $expiry      Expiry in seconds
 * @return array|WP_Error Array with invoice data on success, WP_Error on failure
 */
// public function create_lnd_invoice( $amount_sats, $memo = '', $expiry = 3600 ) {
//     $url         = $this->get_option( 'lnd_rest_url' );
//     $macaroon    = $this->get_option( 'lnd_macaroon_hex' );
//     $certificate = $this->get_option( 'lnd_certificate' );
//     $verify_ssl  = $this->get_option( 'lnd_verify_ssl', 'yes' );

//     if ( empty( $url ) || empty( $macaroon ) ) {
//         return new \WP_Error( 'lnd_config', __( 'lnd REST not configured properly.', 'paycrypto-me-for-woocommerce' ) );
//     }

//     $endpoint = rtrim( $url, '/' ) . '/v1/invoices';

//     $body = array(
//         'value'  => strval( $amount_sats ),
//         'memo'   => $memo,
//         'expiry' => strval( $expiry ),
//     );

//     $args = array(
//         'method'  => 'POST',
//         'timeout' => 30,
//         'headers' => array(
//             'Grpc-Metadata-macaroon' => $macaroon,
//             'Content-Type'           => 'application/json',
//             'Accept'                 => 'application/json',
//         ),
//         'body'    => wp_json_encode( $body ),
//     );

//     // Handle SSL configuration
//     if ( ! empty( $certificate ) ) {
//         $temp_cert = tempnam( sys_get_temp_dir(), 'lnd_cert_' );
//         if ( $temp_cert && file_put_contents( $temp_cert, $certificate ) ) {
//             $args['sslcertificates'] = $temp_cert;
//         }
//     } else {
//         $args['sslverify'] = ( $verify_ssl === 'yes' );
//     }

//     $response = wp_remote_post( $endpoint, $args );

//     // Clean up temp certificate
//     if ( ! empty( $temp_cert ) && file_exists( $temp_cert ) ) {
//         unlink( $temp_cert );
//     }

//     if ( is_wp_error( $response ) ) {
//         return $response;
//     }

//     $code = wp_remote_retrieve_response_code( $response );
//     $data = json_decode( wp_remote_retrieve_body( $response ), true );

//     if ( $code < 200 || $code >= 300 ) {
//         $error_msg = isset( $data['message'] ) ? $data['message'] : __( 'lnd REST API request failed.', 'paycrypto-me-for-woocommerce' );
//         return new \WP_Error( 'lnd_api_error', $error_msg, array( 'status' => $code ) );
//     }

//     // lnd response structure: payment_request, r_hash, add_index
//     return array(
//         'invoice_id'      => isset( $data['r_hash'] ) ? $data['r_hash'] : '',
//         'payment_request' => isset( $data['payment_request'] ) ? $data['payment_request'] : '',
//         'amount_sats'     => $amount_sats,
//         'expiry'          => $expiry,
//         'add_index'       => isset( $data['add_index'] ) ? $data['add_index'] : '',
//         'raw_response'    => $data,
//     );
// }

/**
 * Get invoice status from BTCPay or lnd.
 *
 * @param string $invoice_id Invoice identifier
 * @param string $node_type  'btcpay' or 'lnd_rest'
 * @return array|WP_Error Status information or error
 */
// public function get_invoice_status( $invoice_id, $node_type = null ) {
//     if ( ! $node_type ) {
//         $node_type = $this->get_option( 'node_type', 'btcpay' );
//     }

//     if ( $node_type === 'btcpay' ) {
//         return $this->get_btcpay_invoice_status( $invoice_id );
//     } elseif ( $node_type === 'lnd_rest' ) {
//         return $this->get_lnd_invoice_status( $invoice_id );
//     }

//     return new \WP_Error( 'invalid_node_type', __( 'Invalid node type specified.', 'paycrypto-me-for-woocommerce' ) );
// }

/**
 * Get BTCPay invoice status.
 *
 * @param string $invoice_id BTCPay invoice ID
 * @return array|WP_Error
 */
// private function get_btcpay_invoice_status( $invoice_id ) {
//     $url      = $this->get_option( 'btcpay_url' );
//     $api_key  = $this->get_option( 'btcpay_api_key' );
//     $store_id = $this->get_option( 'btcpay_store_id' );

//     if ( empty( $url ) || empty( $api_key ) || empty( $store_id ) ) {
//         return new \WP_Error( 'btcpay_config', __( 'BTCPay Server not configured.', 'paycrypto-me-for-woocommerce' ) );
//     }

//     $endpoint = rtrim( $url, '/' ) . '/api/v1/stores/' . rawurlencode( $store_id ) . '/invoices/' . rawurlencode( $invoice_id );

//     $args = array(
//         'timeout' => 15,
//         'headers' => array(
//             'Authorization' => 'token ' . $api_key,
//             'Accept'        => 'application/json',
//         ),
//     );

//     $response = wp_remote_get( $endpoint, $args );

//     if ( is_wp_error( $response ) ) {
//         return $response;
//     }

//     $code = wp_remote_retrieve_response_code( $response );
//     $data = json_decode( wp_remote_retrieve_body( $response ), true );

//     if ( $code < 200 || $code >= 300 ) {
//         return new \WP_Error( 'btcpay_api_error', __( 'Failed to retrieve invoice status.', 'paycrypto-me-for-woocommerce' ) );
//     }

//     // BTCPay statuses: New, Processing, Expired, Invalid, Settled
//     $status = isset( $data['status'] ) ? $data['status'] : 'Unknown';
//     $paid = in_array( strtolower( $status ), array( 'settled', 'processing' ), true );

//     return array(
//         'paid'         => $paid,
//         'status'       => $status,
//         'amount_paid'  => isset( $data['amount'] ) ? $data['amount'] : 0,
//         'raw_response' => $data,
//     );
// }

/**
 * Get lnd invoice status.
 *
 * @param string $r_hash Invoice payment hash (r_hash)
 * @return array|WP_Error
 */
// private function get_lnd_invoice_status( $r_hash ) {
//     $url         = $this->get_option( 'lnd_rest_url' );
//     $macaroon    = $this->get_option( 'lnd_macaroon_hex' );
//     $certificate = $this->get_option( 'lnd_certificate' );
//     $verify_ssl  = $this->get_option( 'lnd_verify_ssl', 'yes' );

//     if ( empty( $url ) || empty( $macaroon ) ) {
//         return new \WP_Error( 'lnd_config', __( 'lnd REST not configured.', 'paycrypto-me-for-woocommerce' ) );
//     }

//     // lnd lookup endpoint: GET /v1/invoice/{r_hash}
//     $endpoint = rtrim( $url, '/' ) . '/v1/invoice/' . rawurlencode( $r_hash );

//     $args = array(
//         'timeout' => 15,
//         'headers' => array(
//             'Grpc-Metadata-macaroon' => $macaroon,
//             'Accept'                 => 'application/json',
//         ),
//     );

//     // Handle SSL
//     if ( ! empty( $certificate ) ) {
//         $temp_cert = tempnam( sys_get_temp_dir(), 'lnd_cert_' );
//         if ( $temp_cert && file_put_contents( $temp_cert, $certificate ) ) {
//             $args['sslcertificates'] = $temp_cert;
//         }
//     } else {
//         $args['sslverify'] = ( $verify_ssl === 'yes' );
//     }

//     $response = wp_remote_get( $endpoint, $args );

//     // Clean up temp cert
//     if ( ! empty( $temp_cert ) && file_exists( $temp_cert ) ) {
//         unlink( $temp_cert );
//     }

//     if ( is_wp_error( $response ) ) {
//         return $response;
//     }

//     $code = wp_remote_retrieve_response_code( $response );
//     $data = json_decode( wp_remote_retrieve_body( $response ), true );

//     if ( $code < 200 || $code >= 300 ) {
//         return new \WP_Error( 'lnd_api_error', __( 'Failed to retrieve invoice status.', 'paycrypto-me-for-woocommerce' ) );
//     }

//     // lnd 'state' values: OPEN, SETTLED, CANCELED, ACCEPTED
//     $state = isset( $data['state'] ) ? $data['state'] : 'UNKNOWN';
//     $paid  = ( strtoupper( $state ) === 'SETTLED' );

//     return array(
//         'paid'         => $paid,
//         'status'       => $state,
//         'amount_paid'  => isset( $data['amt_paid_sat'] ) ? $data['amt_paid_sat'] : 0,
//         'raw_response' => $data,
//     );
// }

/**
 * Decode a Lightning payment request (bolt11).
 * Uses lnd's payreq decode endpoint.
 *
 * @param string $payment_request bolt11 invoice string
 * @return array|WP_Error Decoded invoice data or error
 */
// public function decode_payment_request( $payment_request ) {
//     $node_type = $this->get_option( 'node_type', 'btcpay' );

//     // For BTCPay, we can try to use lnd decode if lnd credentials are available
//     // Or implement BTCPay's decode endpoint if it exists
//     // For simplicity, we'll use lnd's endpoint
//     $url         = $this->get_option( 'lnd_rest_url' );
//     $macaroon    = $this->get_option( 'lnd_macaroon_hex' );
//     $certificate = $this->get_option( 'lnd_certificate' );
//     $verify_ssl  = $this->get_option( 'lnd_verify_ssl', 'yes' );

//     if ( empty( $url ) || empty( $macaroon ) ) {
//         return new \WP_Error( 'lnd_config', __( 'Cannot decode payment request: lnd REST not configured.', 'paycrypto-me-for-woocommerce' ) );
//     }

//     // lnd decode endpoint: GET /v1/payreq/{pay_req}
//     $endpoint = rtrim( $url, '/' ) . '/v1/payreq/' . rawurlencode( $payment_request );

//     $args = array(
//         'timeout' => 15,
//         'headers' => array(
//             'Grpc-Metadata-macaroon' => $macaroon,
//             'Accept'                 => 'application/json',
//         ),
//     );

//     // Handle SSL
//     if ( ! empty( $certificate ) ) {
//         $temp_cert = tempnam( sys_get_temp_dir(), 'lnd_cert_' );
//         if ( $temp_cert && file_put_contents( $temp_cert, $certificate ) ) {
//             $args['sslcertificates'] = $temp_cert;
//         }
//     } else {
//         $args['sslverify'] = ( $verify_ssl === 'yes' );
//     }

//     $response = wp_remote_get( $endpoint, $args );

//     // Clean up
//     if ( ! empty( $temp_cert ) && file_exists( $temp_cert ) ) {
//         unlink( $temp_cert );
//     }

//     if ( is_wp_error( $response ) ) {
//         return $response;
//     }

//     $code = wp_remote_retrieve_response_code( $response );
//     $data = json_decode( wp_remote_retrieve_body( $response ), true );

//     if ( $code < 200 || $code >= 300 ) {
//         return new \WP_Error( 'decode_error', __( 'Failed to decode payment request.', 'paycrypto-me-for-woocommerce' ) );
//     }

//     return array(
//         'destination'   => isset( $data['destination'] ) ? $data['destination'] : '',
//         'payment_hash'  => isset( $data['payment_hash'] ) ? $data['payment_hash'] : '',
//         'num_satoshis'  => isset( $data['num_satoshis'] ) ? intval( $data['num_satoshis'] ) : 0,
//         'timestamp'     => isset( $data['timestamp'] ) ? intval( $data['timestamp'] ) : 0,
//         'expiry'        => isset( $data['expiry'] ) ? intval( $data['expiry'] ) : 0,
//         'description'   => isset( $data['description'] ) ? $data['description'] : '',
//         'raw_response'  => $data,
//     );
// }