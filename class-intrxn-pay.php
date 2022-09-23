<?php
/**
 * Plugin Name: Crypto payment checkout by intrXn
 * Plugin URI:  http://www.intrxn.com/
 * Description: Accept payment in various cryptocurrencies | super easy to install | lowest fees.
 * Author:      intrxn.com
 * Author URI:  mailto:contact@intrxn.com?subject=intrxn.com Pay Checkout for WooCommerce
 * Version:     1.0
 *
 * WP requires at least: 4.4
 * WC requires at least: 4.5
 * WC tested up to: 5.1
 * 
 */


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

add_filter( 'http_request_timeout', function( $timeout ) { return 60; });

define('INTRXN_PLUGIN_VERSION', '1.0');
if ( !defined( 'INTRXN_PLUGIN_FILE' ) ) {
	define( 'INTRXN_PLUGIN_FILE', __FILE__);
}

/**
 * Plugin activation 
 */
function cp_intrxn_plugin_activation()
{	
    $intrxn_plugin_version = get_option('intrxn_plugin_version');
    if (!$intrxn_plugin_version) {
        add_option('intrxn_plugin_version', INTRXN_PLUGIN_VERSION);
    } else {
        update_option('intrxn_plugin_version', INTRXN_PLUGIN_VERSION);
    }
}

register_activation_hook(__FILE__, 'cp_intrxn_plugin_activation');

add_action( 'admin_init', 'cp_intrxn_admin_initialization');
function cp_intrxn_admin_initialization(){
	
	//Requirements Check
	if ( !defined( 'WC_VERSION' ) ) {
		if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			if ( isset( $_GET['activate'] ) ) {
				unset( $_GET['activate'] );
			}
		}
	}
}
//Add settings link
add_filter( "plugin_action_links", 'cp_intrxn_add_context_link', 10, 2 );
function cp_intrxn_add_context_link($links, $file ){
		
	if($file == plugin_basename(INTRXN_PLUGIN_FILE) && function_exists('admin_url') ) {
		if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
			$settings_link = '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=intrxn_pay').'">'.esc_html__('Settings', 'intrxn-pay').'</a>';
			array_unshift( $links, $settings_link );
		}
	
	}
	return $links;
}

add_action('plugins_loaded', 'cp_load_intrxn_payment_gateway', 0);

// Register webhook to recive payment status
add_action('rest_api_init', function () {
    register_rest_route('intrxn-pay/v1', '/webhook', array(
        'methods' => 'POST',
        'callback' => 'cp_intrxn_process_webhook',
        'permission_callback' => '__return_true',
    ));
});


ob_start();

/**
 * notice message when WooCommerce is not active
 */
function cp_intrxn_notice_to_activate_woocommerce()
{
    echo '<div id="message" class="error notice is-dismissible"><p><strong>Crypto payment checkout by intrXn: </strong>' .
    esc_attr(__('WooCommerce must be installed & active to make this plugin working properly.', 'intrxn-pay')) .
        '</p></div>';
}

/**
 * Init payment gateway
 */
function cp_load_intrxn_payment_gateway()
{

    /**
     * Loads translation
     */
    load_plugin_textdomain('intrxn-pay', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'cp_intrxn_notice_to_activate_woocommerce');
        return;
    }

    include_once dirname(__FILE__) . '/includes/class-intrxn-helper.php';
    include_once dirname(__FILE__) . '/includes/class-intrxn-payment-api.php';

    if (!class_exists('Intrxn_pay')) {

        /**
         * Crypto Payment Gateway
         *
         * @class Intrxn_pay
         */
        class Intrxn_pay extends WC_Payment_Gateway
        {

            public $id = 'intrxn_pay';

            /**
             * Woocommerce order
             *
             * @var object $wc_order
             */
            protected $wc_order;

            /**
             * Main function
             */
            public function __construct()
            {
                $plugin_dir = plugin_dir_url(__FILE__);
                $this->form_fields = $this->get_intrxn_form_fields();
                $this->method_title = __('Pay with crypto via intrXn', 'intrxn-pay');
                $this->method_description = __('Easy crypto payment by intrxn.com.', 'intrxn-pay');
                $this->icon = apply_filters('woocommerce_gateway_icon', '' . $plugin_dir . '/assets/logo.png', $this->id);

                $this->supports = array('products', 'refunds');

                $this->init_settings();

                // action to save intrxn pay backend configuration
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                // action to show payment page
                add_action('woocommerce_receipt_' . $this->id, array(&$this, 'payment_state'));
                // action to show success page
                add_action('woocommerce_thankyou_' . $this->id, array(&$this, 'success_state'));

                if (isset(WC()->session->intrxn_success_state)) {
                    unset(WC()->session->intrxn_success_state);
                }
                if (isset(WC()->session->intrxn_payment_state)) {
                    unset(WC()->session->intrxn_payment_state);
                }
                if (isset(WC()->session->intrxn_display_error)) {
                    $_POST['intrxn_error'] = '1';
                    unset(WC()->session->intrxn_display_error);
                }
            }

            /**
             * Get payment method title
             *
             * @return string
             */
            public function get_title()
            {
                return $this->method_title;
            }

            public function get_description() 
            {
                return $this->settings['description'];
            }

            /**
             * set intrxn backend configuration fields
             */
            public function get_intrxn_form_fields()
            {

                $form_fields = array(
					
                    'enabled' => array(
                        'title' => __('Enabled', 'intrxn-pay'),
                        'type' => 'checkbox',
                        'default' => '',
                    ),
					'environment' => array(
                        'title' => __('Environment', 'intrxn-pay'),
                        'type' => 'select',
                        'description' => __('Select <b>Test</b> for testing the plugin, <b>Production</b> when you are ready to go live.'),
                        'options' => array(
                            'production' => 'Production',
                            'test' => 'Test',
                        ),
                        'default' => 'test',
						'class' => 'environment-select'
                    ),
                    'live_publishable_key' => array(
                        'title' => __('API Key', 'intrxn-pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'live_secret_key' => array(
                        'title' => __('API Secret Key', 'intrxn-pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    
                    'capture_status' => array(
                        'title' => __('Order Status when Payment Success', 'intrxn-pay'),
                        'type' => 'select',
                        'description' => __('When payment is captured and this server received the Webhook from intrxn.com Pay server, the status of orders that you would like to update to.'),
                        'options' => array(
                            'processing' => 'Processing',
                            'completed' => 'Completed',
                        ),
                        'default' => 'processing',
                    ),
                    'description' => array(
                        'title' => __('Description', 'intrxn-pay'),
                        'type' => 'text',
                        'default' => __('PLEASE HAVE YOUR WALLET OF CHOICE READY FOR PAYMENT. <br/>You will be redirected to intrxn\'s website to complete the payment.'),
                    ),
                    
                );

                return $form_fields;
            }

            public function admin_options() {
                ?>
                <h2>Crypto payment checkout by intrXn</h2>
                <p><strong>Easy crypto payment by intrxn.com.</strong></p>
                <p>Please visit your account at <a href="https://intrxn.com" target="_blank">intrXn.com</a> to get your API keys to fill in the form below. You will also need to add a webhook in Merchant Dashboard so that payment status are synchronized back to WooCommerce.
                Please refer to <a href="https://intrxn.com/api-docs/" target="_blank">this FAQ page</a> for the detail setup guide.</p>
                <table class="form-table">
                <?php $this->generate_settings_html(); ?>
                </table>
                <script type="text/javascript">
                	//Add secret visibility toggles.
                    jQuery( function( $ ) {
						
						$("form#mainform table.form-table tbody tr:nth-child(2)").after("<tr><th>Webhook URL</th><td><?php echo get_rest_url(null, 'intrxn-pay/v1/webhook'); ?><p>Copy this URL to create a new webhook in <strong>Merchant Dashboard</strong>.</p></td></tr>");
						
                        $( '#woocommerce_intrxn_pay_live_publishable_key, #woocommerce_intrxn_pay_live_secret_key' ).after(
                            '<button class="wc-intrxn-pay-toggle-secret" style="height: 30px; margin-left: 2px; cursor: pointer"><span class="dashicons dashicons-visibility"></span></button>'
                        );
                        $( '.wc-intrxn-pay-toggle-secret' ).on( 'click', function( event ) {
                            event.preventDefault();
                            var $dashicon = $( this ).closest( 'button' ).find( '.dashicons' );
                            var $input = $( this ).closest( 'tr' ).find( '.input-text' );
                            var inputType = $input.attr( 'type' );
                            if ( 'text' == inputType ) {
                                $input.attr( 'type', 'password' );
                                $dashicon.removeClass( 'dashicons-hidden' );
                                $dashicon.addClass( 'dashicons-visibility' );
                            } else {
                                $input.attr( 'type', 'text' );
                                $dashicon.removeClass( 'dashicons-visibility' );
                                $dashicon.addClass( 'dashicons-hidden' );
                            }
                        } );
                    });
                </script>
                <?php
            }

            /**
             * Process the payment
             *
             * @param int $order_id order id.
             * @return array
             */
            public function process_payment($order_id)
            {   
                $order = wc_get_order($order_id);
                $payment_url = $order->get_checkout_payment_url(true);
				
                                
				$amount = $order->get_total();
				$currency = $order->get_currency();
				$customer_name = $order->get_billing_first_name() . " " . $order->get_billing_last_name();

				$return_url = $order->get_checkout_order_received_url();
				$cancel_url = $payment_url;
				
				$api_key = $this->settings['live_publishable_key'];
				$secret_key = $this->settings['live_secret_key'];
				$environment = $this->settings['environment'];

				$result = intrxn_Intrxn_payment_Api::request_payment($order_id, $currency, $amount, $customer_name, $return_url, $cancel_url, $api_key, $environment);
				
				if (isset($result['error'])) {
					wc_add_notice('intrxn.com Pay Error: ' . ($result['error']['message'] ?? print_r($result, true)), 'error');
					return array(
						'result' => 'failure',
						'messages' => 'failure'
					);
				}

				$payment_id = $result['success']['result']['refID'];
				$order->add_meta_data('intrxn_pay_paymentId', $payment_id, true);
				$order->save_meta_data();

				WC()->cart->empty_cart();

				$payment_url = $payment_id = $result['success']['result']['redirectUrl'];
                return array(
                    'result' => 'success',
                    'redirect' => $payment_url
                );
            }

            /**
             * Calls from hook "woocommerce_receipt_{gateway_id}"
             *
             * @param int $order_id order id.
             */
            public function payment_state($order_id)
            {
                $payment_id = intrxn_Crypto_Helper::get_request_value('id');
                $error_payment = intrxn_Crypto_Helper::get_request_value('error');

                if (!empty($payment_id)) {
                    $this->intrxn_process_approved_payment($order_id, $payment_id);
                } elseif (!empty($error_payment)) {
                    $this->intrxn_process_error_payment($order_id, 'wc-failed', 'payment failed');
                }

                if (!isset(WC()->session->intrxn_payment_state)) {
                    //$this->intrxn_render_payment_button($order_id);
                    //WC()->session->set('intrxn_payment_state', true);
                }
            }



            /**
             * Get base url
             *
             * @param string $wp_request wp request
             * @return string
             */
            private function intrxn_get_home_url($wp_request)
            {
                if (false !== strpos(home_url($wp_request), '/?')) {
                    $home_url = home_url($wp_request) . '&';
                } else {
                    $home_url = home_url($wp_request) . '/?';
                }
                return $home_url;
            }

            /**
             * check payment status with payment id
             *
             * @param int $order_id order id.
             * @param string $payment_id payment id.
             */
            private function intrxn_process_approved_payment($order_id, $payment_id)
            {

                // check payment status with payment_id
                // TODO: Review the usage of this function [Thomas, 20201027]

                $this->intrxn_show_success_page($order_id);
            }

            /**
             * cancel the order
             *
             * @param int $order_id order id.
             */
            private function intrxn_cancel_order($order_id)
            {
                $this->intrxn_process_error_payment($order_id, 'wc-cancelled', 'cancelled by user');
            }

            /**
             * set order status, reduce stock, empty cart and show success page.
             *
             * @param int     $order_id order id.
             */
            private function intrxn_show_success_page($order_id)
            {
                $order = wc_get_order($order_id);
                wc_reduce_stock_levels($order_id);
                WC()->cart->empty_cart();
                wp_safe_redirect($this->get_return_url($order));
                exit();
            }

            /**
             * Error payment action
             *
             * @param int          $order_id order id.
             * @param string       $payment_status payment status.
             * @param string|array $error_message error identifier.
             */
            private function intrxn_process_error_payment($order_id, $payment_status, $error_message = 'payment error')
            {
                global $woocommerce;

                $order = wc_get_order($order_id);

                // Cancel the order.
                $order->update_status($error_message);
                $order->update_status($payment_status, 'order_note');

                // To display failure messages from woocommerce session.
                if (isset($error_message)) {
                    $woocommerce->session->errors = $error_message;
                    wc_add_notice($error_message, 'error');
                    WC()->session->set('intrxn_display_error', true);
                }

                wp_safe_redirect(wc_get_checkout_url());
                exit();
            }

            /**
             * Calls from hook "woocommerce_thankyou_{gateway_id}"
             */
            public function success_state($order_id)
            {
                // 1.1.0 update: Update metadata here so we can process refund from woocommerce
                $payment_id = intrxn_Crypto_Helper::get_request_value('id');
                if (!isset($payment_id)) {
                    $order = wc_get_order($order_id);
                    $order->add_meta_data('intrxn_pay_paymentId', $payment_id, true);
                    $order->save_meta_data();
                }

                if (!isset(WC()->session->intrxn_success_state)) {
                    WC()->session->set('intrxn_success_state', true);
                }
            }

            /**
             * get customer parameters by order
             *
             * @return array
             */
            private function intrxn_get_customer_parameters()
            {
                $customer['first_name'] = $this->wc_order->get_billing_first_name();
                $customer['last_name'] = $this->wc_order->get_billing_last_name();
                $customer['email'] = $this->wc_order->get_billing_email();
                $customer['phone'] = $this->wc_order->get_billing_phone();

                return $customer;
            }

            /**
             * get billing parameters by order
             *
             * @return array
             */
            private function intrxn_get_billing_parameters()
            {
                $billing['address'] = $this->wc_order->get_billing_address_1();
                $billing_address_2 = trim($this->wc_order->get_billing_address_2());
                if (!empty($billing_address_2)) {
                    $billing['address'] .= ', ' . $billing_address_2;
                }
                $billing['city'] = $this->wc_order->get_billing_city();
                $billing['postcode'] = $this->wc_order->get_billing_postcode();
                $billing['country'] = $this->wc_order->get_billing_country();

                return $billing;
            }

            /**
             * get payment parameters by order
             *
             * @param int $order_id order id.
             * @return array
             */
            private function get_intrxn_payment_parameters($order_id)
            {
                $this->wc_order = wc_get_order($order_id);
                $currency = get_woocommerce_currency();

                $payment_parameters['publishable_key'] = $this->settings['live_publishable_key'];
                $payment_parameters['order_id'] = $order_id;
                $payment_parameters['amount']   = $this->get_order_total();
                $payment_parameters['currency'] = $currency;
                $payment_parameters['customer'] = $this->intrxn_get_customer_parameters();
                $payment_parameters['billing'] = $this->intrxn_get_billing_parameters();
                $payment_parameters['description'] = "WooCommerce order ID: $order_id";
                $payment_parameters['first_name'] = $this->wc_order->get_billing_first_name();
                $payment_parameters['last_name'] = $this->wc_order->get_billing_last_name();

                return $payment_parameters;
            }

            

            /**
             * get number of decimals from a number
             *
             * @param f number to evaluate
             * @return int number of decimals
             * @since 1.1.0
             */
            private function get_decimal_count($f)
            {
                $num = 0;
                while (true) {
                    if ((string) $f === (string) round($f)) {
                        break;
                    }
                    if (is_infinite($f)) {
                        break;
                    }

                    $f *= 10;
                    $num++;
                }
                return $num;
            }
        }
    }

    /**
     * Add Crypto Pay to WooCommerce
     *
     * @access public
     * @param array $gateways gateways.
     * @return array
     */
    function intrxn_add_to_gateways($gateways)
    {
        $gateways[] = 'intrxn_pay';
        return $gateways;
    }
    add_filter('woocommerce_payment_gateways', 'intrxn_add_to_gateways');

    /**
     * Handle a custom 'intrxn_pay_paymentId' query var to get orders with the 'intrxn_pay_paymentId' meta.
     * @param array $query - Args for WP_Query.
     * @param array $query_vars - Query vars from WC_Order_Query.
     * @return array modified $query
     */
    function intrxn_handle_custom_query_var( $query, $query_vars ) {
        if ( ! empty( $query_vars['intrxn_pay_paymentId'] ) ) {
            $query['meta_query'][] = array(
                'key' => 'intrxn_pay_paymentId',
                'value' => esc_attr( $query_vars['intrxn_pay_paymentId'] ),
            );
        }

        return $query;
    }
    add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'intrxn_handle_custom_query_var', 10, 2 );
}

/**
 * Process webhook
 *
 * @param array $request Options for the function.
 * @return  {status: true} to mark the API server cron to stop sending status for any order
 * @since 1.2.0
 */
function cp_intrxn_process_webhook(WP_REST_Request $request)
{

    $json = $request->get_json_params();
		
	if(isset($json['paymentStatus']) && isset($json['orderId'])){
		$order_id = (int)$json['orderId'];
		$order = wc_get_order($order_id);
        if (!is_null($order)) {
			
			$payment_id = $order->get_meta( 'intrxn_pay_paymentId' );
			if($payment_id != $json['paymentId']){return rest_ensure_response(array('status'=>true));}
			
			if($json['paymentStatus']=='SUCCESS'){
				$payment_gateway_id = 'intrxn_pay';
				
				// Get an instance of the WC_Payment_Gateways object
				$payment_gateways = WC_Payment_Gateways::instance();
			
				// Get the desired WC_Payment_Gateway object
				$gateways = $payment_gateways->payment_gateways();
				$payment_gateway = $gateways[$payment_gateway_id];
	
				if ($payment_gateway->settings['capture_status'] == 'completed') {
					$order->update_status('completed');
				} else {
					$order->update_status('processing');
				}
				
				//reduce product stock
				$order->reduce_order_stock();
				
				return rest_ensure_response(array('status'=>true));
			}elseif($json['paymentStatus']=='FAILED'){
				$order->update_status('failed');
				return rest_ensure_response(array('status'=>true));
			}else{
				//if any other status in future	
			}
		}
	}
	
    return rest_ensure_response(array('status'=>false));
}