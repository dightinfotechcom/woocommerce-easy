<?php

/**
 * WC_Gateway_WC_Gateway_lyfePAY class
 *
 * @author   WC_Gateway_lyfePAY <info@WC_Gateway_lyfePAY.io>
 * @package  WooCommerce WC_Gateway_lyfePAY Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}
session_start();
/**
 * WC_Gateway_lyfePAY Gateway.
 *
 * @class    WC_Gateway_WC_Gateway_lyfePAY
 * @version  1.0.7
 */
class WC_Gateway_lyfePAY extends WC_Payment_Gateway
{

	/**
	 * Payment gateway instructions.
	 * @var string
	 *
	 */
	protected $instructions;

	/**
	 * Whether the gateway is visible for non-admin users.
	 * @var boolean
	 *
	 */
	protected $hide_for_non_admin_users;

	/**
	 * Unique id for the gateway.
	 * @var string
	 *
	 */
	public $id = 'easymerchant';

	/**
	 * Constructor for the gateway.
	 */
	public function __construct()
	{
		$this->id 				  = 'easymerchant';
		$this->icon               =  apply_filters('woocommerce_lyfepay_icon', plugin_dir_path('/assets/images/lyfecycle-payments-logo.png', __FILE__));
		$this->logo 			  = $this->get_option('logo_display');
		$this->has_fields         = true;
		$this->title 			  = 'lyfePAY';
		$this->supports           = array(
			'subscriptions',
			'products',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
			'pre-orders',
			'add_payment_method',
			'refunds',
			'default_credit_card_form'
		);
		$this->method_title       = _x('lyfePAY ', 'lyfePAY  payment method', 'woocommerce-easymerchant');
		$this->method_description = __('lyfePAY  Gateway Options.', 'woocommerce-easymerchant');

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->enabled = $this->get_option('enabled');
		$this->testmode = 'yes' === $this->get_option('test_mode');
		if ($this->testmode == 'yes') {
			$this->api_key = $this->get_option('test_api_key');
			$this->secret_key = $this->get_option('test_secret_key');
			$this->api_base_url = 'https://stage-api.stage-easymerchant.io';
		} else {
			$this->api_key = $this->get_option('api_key');
			$this->secret_key = $this->get_option('api_secret');
			$this->api_base_url = 'https://api.easymerchant.io';
		}
		$this->capture = 'yes' === $this->get_option('capture', 'yes');
		$this->saved_cards = 'yes' === $this->get_option('saved_cards');
	}

	/**
	 * Check if this gateway is enabled
	 */
	public function is_available()
	{

		if ('yes' === $this->enabled) {
			if (!$this->secret_key || !$this->api_key) {
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * Asscoaitive Array push at first index
	 * @param  array &$arr source array
	 * @param  string $key  new key
	 * @param  string $val  new value
	 * @return array       new array
	 */
	public function array_unshift_assoc(&$arr, $key, $val)
	{
		$arr = array_reverse($arr, true);
		$arr[$key] = $val;
		return array_reverse($arr, true);
	}
	
	

	/**
	 * Process the payment
	 *
	 * @param int  $order_id Reference.
	 * @param bool $retry Should we retry on fail.
	 * @param bool $force_customer Force user creation.
	 *
	 * @return array|void
	 */

	public function process_payment($order_id, $retry = true, $force_customer = false)
	{
		sleep(5);
		global $woocommerce;
		$order = wc_get_order($order_id);
		
		try {
			if (!$order) {
				error_log("Order not found: " . $order_id);
				return [
					'status' => 0,
					'message' => 'Order not found.'
				];
			}

			$amount_details = json_encode([
				"amount" => $order->order_total,
			]);

			$response = wp_remote_post($this->api_base_url . '/api/v1/paymentintent/', array(
				'method'    => 'POST',
				'headers'   => array(
					'X-Api-Key'      => $this->api_key,
					'X-Api-Secret'   => $this->secret_key,
					'Content-Type'   => 'application/json',
				),
				'body' => $amount_details,
			));

			if (is_wp_error($response)) {
				error_log("HTTP request failed: " . $response->get_error_message());
				return [
					'status' => 0,
					'message' => 'Payment intent creation failed due to HTTP error.'
				];
			}

			$response_body = wp_remote_retrieve_body($response);
			$response_data = json_decode($response_body, true);

			if ($response_data && isset($response_data['status']) && $response_data['status'] == 1) {
				setcookie('payment_response', json_encode($response_data), time() + 3600, "/");
				error_log("Payment response set in cookie: " . print_r($response_data, true));
			} else {
				$error_data = [
					'status' => 0,
					'message' => 'Payment intent creation failed.'
				];
				setcookie('payment_response', json_encode($error_data), time() + 3600, "/");
				error_log("Payment intent creation failed: " . print_r($response_data, true));
				return $error_data;
			}

			$customers_details = json_encode([
				"username"   => $order->get_billing_email(),
				"email"      => $order->get_billing_email(),
				"name"       => $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
				"address"    => $order->get_billing_address_1(),
				"city"       => $order->get_billing_city(),
				"state"      => $order->get_billing_state(),
				"zip"        => $order->get_billing_postcode(),
				"country"    => $order->get_billing_country()
			]);

			$customerResponse = wp_remote_post($this->api_base_url . '/api/v1/customers/', array(
				'method'    => 'POST',
				'headers'   => array(
					'X-Api-Key'      => $this->api_key,
					'X-Api-Secret'   => $this->secret_key,
					'User-Agent: ' . LYFE_APP_NAME,
					'Content-Type'   => 'application/json',
				),
				'body' => $customers_details,
			));

			$customer_response_body = wp_remote_retrieve_body($customerResponse);
			$customer_response_data = json_decode($customer_response_body, true);
			
			if ($customer_response_data && isset($customer_response_data['status']) && $customer_response_data['status'] == 1) {
				setcookie('customer_response', json_encode($customer_response_data), time() + 3600, "/");
				error_log("Customer response set in cookie: " . print_r($customer_response_data, true));
			} else {
				$billing_email = $order->get_billing_email();
				$getCustomerResponse = wp_remote_get($this->api_base_url . '/api/v1/customers', array(
					'headers' => array(
						'X-Api-Key'      => $this->api_key,
						'X-Api-Secret'   => $this->secret_key,
						'User-Agent: ' . LYFE_APP_NAME,
						'Content-Type'   => 'application/json',
					)
				));
				$get_customer_response_body = wp_remote_retrieve_body($getCustomerResponse);
				$get_customer_response_data = json_decode($get_customer_response_body, true);
				
				if ($get_customer_response_data && isset($get_customer_response_data['status']) && $get_customer_response_data['status'] == true) {
					$customers = $get_customer_response_data['customer'];
					foreach ($customers as $customer) {
						if ($customer['email'] === $billing_email) {
							$customer_id = $customer['user_id'];
							setcookie('customer_response', json_encode(['customer_id' => $customer_id]), time() + 3600, "/");
							error_log("Customer ID set in cookie: " . $customer_id);
						}
					}
				}
			}
			$paymentPayload = json_decode(stripslashes($_COOKIE['paymentPayload']), true);
			$customerPayload = json_decode(stripslashes($_COOKIE['customer_response']), true);
			
			$charge_details = json_encode([
				"payment_mode"	=> "auth_and_capture",
				"card_number"	=> str_replace(' ', '', $paymentPayload['card_number']),
				"exp_month"		=> $paymentPayload['exp_month'],
				"exp_year"		=> $paymentPayload['exp_year'],
				"cvc"			=> $paymentPayload['cvc'],
				"cardholder_name"=> $paymentPayload['cardholder_name'],
				"currency"		=> $paymentPayload['currency'],
				"name"			=> $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
				"email"			=> $order->get_billing_email(),
				"amount"		=> $order->order_total,
				"description"	=> "Payment through Woocommerce lyfePAY",
				"customer_id"	=> $customerPayload['customer_id'],
			]);
			$chargeResponse = wp_remote_post($this->api_base_url . '/api/v1/charges/', array(
				'method'    => 'POST',
				'headers'   => array(
					'X-Api-Key'      => $this->api_key,
					'X-Api-Secret'   => $this->secret_key,
					'Content-Type'   => 'application/json',
				),
				'body' => $charge_details,
			));
			$charge_response_body = wp_remote_retrieve_body($chargeResponse);
			$charge_response_data = json_decode($charge_response_body, true);
			
			setcookie('paymentPayload', '', time() - 3600, "/");
			setcookie('payment_response', '', time() - 3600, "/");
			setcookie('customer_response', '', time() - 3600, "/");
			if (isset($charge_response_data['charge_id'])) {
                update_post_meta($order_id, '_charge_id', $charge_response_data['charge_id']);
                $order->update_status('completed');  // Set order status to completed
                $order->payment_complete();
                $woocommerce->cart->empty_cart();
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            } else {
                $order->update_status('processing');  // Set order status to processing
                return array(
                    'result' => 'failure',
                    'message' => 'Payment failed.'
                );
            }
		} catch (\Throwable $th) {
			print_r($th);
			throw $th;
		}
	}
	/**
	 * Save source to order.
	 *
	 * @param WC_Order $order For to which the source applies.
	 * @param stdClass $source Source information.
	 */
	protected function save_source($order, $source)
	{
		// Store source in the order.
		if ($source->customer) {
			update_post_meta($order->id, '_customer_id', $source->customer);
		}
		if ($source->source) {
			update_post_meta($order->id, '_card_id', $source->source);
		}
	}

	/**
	 * Refund a charge
	 * @param  int $order_id
	 * @param  float $amount
	 * @return bool
	 */
	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$order = wc_get_order( $order_id );

		if ( ! $this->can_refund_order( $order ) ) {
			return new WP_Error( 'error', __( 'Refund failed.', 'woocommerce' ) );
		}

		if (!$amount || $amount < 1) {
			return new WP_Error('lyfePAY_refund_error', 'There was a problem initiating a refund. This value must be greater than or equal to $1');
		}
		
		$charge_id 		= get_post_meta($order_id, '_charge_id', true);

		$refund_details = json_encode([
			'charge_id' => $charge_id,
			'amount'    => $amount,
			'reason'    => $reason,
		]);

		$refundAmount = wp_remote_post($this->api_base_url . '/api/v1/refunds/', array(
			'method'    => 'POST',
			'headers'   => array(
				'X-Api-Key'      => $this->api_key,
				'X-Api-Secret'   => $this->secret_key,
				'Content-Type'   => 'application/json',
				'User-Agent: ' . LYFE_APP_NAME,
			),
			'body'               => $refund_details
		));

		$refund_body = wp_remote_retrieve_body($refundAmount);
		$refund_data = json_decode($refund_body, true);

		if ($refund_data && isset($refund_data['status']) && $refund_data['status'] == 1) {
			// Refund successful
			$order->add_order_note(sprintf(__('Refunded %s via lyfePay. Reason: %s', 'woocommerce-easymerchant'), wc_price($amount), $reason));
			return true;
		} else {
			// Refund failed
			$error_message = isset($refund_data['message']) ? $refund_data['message'] : __('Refund failed', 'woocommerce-easymerchant');
			return new WP_Error('refund_failed', $error_message);
		}

		return false;
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields()
	{

		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'woocommerce'),
				'type' => 'checkbox',
				'label' => __('Enable', 'woocommerce'),
				'default' => 'no'
			),
			'logo_display' => array(
				'title'       => __('Brand Logo', 'woocommerce'),
				'type'        => 'image',
				'description' =>  '<img src="' . plugin_dir_url(__FILE__) . 'assets/images/lyfecycle-payments-logo.png" class="" alt="Logo" style="max-width: 8%; height: auto;"/>',
			),
			'title' => array(
				'title'       => __('Title', 'woocommerce'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
				'default'     => __('lyfePAY', 'woocommerce'),
				'desc_tip'    => true
			),
			'description' => array(
				'title'       => __('Description', 'woocommerce'),
				'type'        => 'text',
				'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
				'default'     => 'Pay with your credit card via lyfePAY.',
				'desc_tip'    => true
			),
			'api_key' => array(
				'title' => __('API Key', 'woocommerce'),
				'description' => __('Get your API key from lyfePAY.', 'woocommerce'),
				'type' => 'text',
				'default' => '',
				'desc_tip' => true,
			),
			'api_secret' => array(
				'title' => __('API Secret', 'woocommerce'),
				'description' => __('Get your API secret from lyfePAY.', 'woocommerce'),
				'type' => 'text',
				'default' => '',
				'desc_tip' => true,
			),
			'test_mode' => array(
				'title' => __('Test Mode', 'woocommerce'),
				'label' => __('Enable Test Mode', 'woocommerce'),
				'type' => 'checkbox',
				'default'     => 'yes',
				'desc_tip'    => true
			),
			'test_api_key' => array(
				'title'       => __('Test API Key', 'woocommerce'),
				'type'        => 'text',
				'description' => __('Get your API keys from your lyfePAY account.', 'woocommerce'),
				'default'     => '',
				'desc_tip'    => true,
			),
			'test_secret_key' => array(
				'title'       => __('Test Secret Key', 'woocommerce'),
				'type'        => 'text',
				'description' => __('Get your API keys from your lyfePAY account.', 'woocommerce'),
				'default'     => '',
				'desc_tip'    => true,
			),
			'capture' => array(
				'title'       => __('Capture', 'woocommerce'),
				'label'       => __('Capture charge immediately', 'woocommerce'),
				'type'        => 'checkbox',
				'description' => __('Whether or not to immediately capture the charge. When unchecked, the charge issues an authorization and will need to be captured later. Uncaptured charges expire in 7 days.', 'woocommerce'),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'saved_cards' => array(
				'title'       => __('Saved Cards', 'woocommerce'),
				'label'       => __('Enable Payment via Saved Cards', 'woocommerce'),
				'type'        => 'checkbox',
				'description' => __('If enabled, users will be able to pay with a saved card during checkout. Card details are saved on lyfePAY servers, not on your store.', 'woocommerce'),
				'default'     => 'no',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Get gateway icon.
	 *
	 * @access public
	 * @return string
	 */
	public function get_icon()
	{
		$icon  = '<img src="' . plugin_dir_url(__FILE__) . '/assets/images/icons/visa.png" alt="Visa" />';
		$icon .= '<img src="' . plugin_dir_url(__FILE__) . '/assets/images/icons/mastercard.png" alt="MasterCard" />';
		$icon .= '<img src="' . plugin_dir_url(__FILE__) . '/includes/assets/images/icons/amex.png" alt="Amex" />';
		return apply_filters('woocommerce_lyfepay_icon', $icon, $this->id);
	}

	
	
}
