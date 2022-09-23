<?php
/**
 * Crypto Payment API
 *
 * The Class for Process Crypto Payment Gateways
 * Copyright (c) 2018 - 2021, Foris Limited ("intrxn.com")
 *
 * @class      intrxn_Intrxn_payment_Api
 * @package    Crypto/Classes
 * @located at /includes/
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * The Class for Processing Crypto Payment API
 */
class intrxn_Intrxn_payment_Api
{
    /**
     * payment api url
     *
     * @var string $intrxn_api_payment_url
     */
    protected static $intrxn_api_payment_url_production = 'https://businessbackend.intrxn.com/v1/payment';
	protected static $intrxn_api_payment_url_test = 'https://devbackend.intrxn.com/v1/payment';
    protected static $intrxn_api_refund_url = '';

    /**
     * Get http response
     *
     * @param string $url url.
     * @param string $api_key API key.
     * @param string $method method.
     * @param string $data data.
     * @return array
     */
    private static function get_http_response($url, $api_key, $method = 'get', $data = '')
    {
		$site_url = get_site_url();
        if ('get' === $method) {
            $response = wp_remote_get($url,
                array(
                    'headers' => array(
                        'X-intrXn-key' => $api_key,
						'X-intrXn-Timestamp' => time(),
						'Content-Type' => "application/json",
						'Access-Control-Allow-Origin' => $site_url
                    ),
                )
            );
        } else {
            $response = wp_remote_post($url,
                array(
                    'headers' => array(
                        'X-intrXn-key' => $api_key,
						'X-intrXn-Timestamp' => time(),
						'Content-Type' => "application/json",
						'Access-Control-Allow-Origin' => $site_url
                    ),
                    'body' => json_encode($data),
                )
            );
        }
		
        $result = array();

        // if wordpress error
        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            $result['request'] = $data;
            return $result;
        }

        $response = wp_remote_retrieve_body($response);
        $response_json = json_decode($response, true);
				
        // if outgoing request get back a normal response, but containing an error field in JSON body
        if ($response_json['error']) {
            $result['error'] = $response_json['error'];
            $result['error']['message'] = $result['error']['param'] . ' ' . $result['error']['code'];
            $result['request'] = $data;
            return $result;
        }

        // if everything normal
        $result['success'] = $response_json;
        return $result;
    }

    /**
     * create a payment
     * 
     * @param string $order_id
     * @param string $currency currency
     * @param string $amount amount
     * @param string $customer_name customer name
     * @param string $api_key API key
	 * @param string $environment production|test
     * @since 1.3.0
     */
    public static function request_payment($order_id, $currency, $amount, $customer_name, $return_url, $cancel_url, $api_key, $environment) 
    {		
		$intrxn_api_payment_url = ($environment == 'production' ? self::$intrxn_api_payment_url_production : self::$intrxn_api_payment_url_test);
        $data = array(
            'orderId' => $order_id,
            'currency' => $currency,
            'amount' => $amount,
            'redirectUrl' => $return_url,
            'cancelUrl' => $cancel_url
        );		
		
        return self::get_http_response($intrxn_api_payment_url, $api_key, 'post', $data);
    }

    /**
     * retrieve a payment by payment unique id
     *
     * @param string $payment_id payment id.
     * @param string $api_key API key.
	 * @param string $environment production|test
     * @return array
     */
    public static function retrieve_payment($payment_id, $api_key, $environment)
    {
		
		$intrxn_api_payment_url = ($environment == 'production' ? self::$intrxn_api_payment_url_production : self::$intrxn_api_payment_url_test);
        $intrxn_api_payment_url = $intrxn_api_payment_url . $payment_id;
        return self::get_http_response($intrxn_api_payment_url, $api_key);
    }

}
