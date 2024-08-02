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
		// Actions.
		add_filter('woocommerce_credit_card_form_fields', array($this, 'add_cc_card_holder_name'), 10, 2);
		add_action('woocommerce_scheduled_subscription_payment_lyfepay', array($this, 'process_subscription_payment'), 10, 2);
		add_action('wp_ajax_get_client_token', 'get_client_token');
		add_action('wp_ajax_nopriv_get_client_token', 'get_client_token');
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
	 * Add Cardholder name fieeld to default credit card form
	 * @param array $cc_fields  default cc fields
	 * @param integer $payment_id paymentgateway id
	 */
	public function add_cc_card_holder_name($cc_fields, $payment_id)
	{
		if ($payment_id === 'lyfePAY')
			return $cc_fields;
		$cc_card_holder_field = '<p class="form-row form-row-wide">
                 <label for="' . esc_attr($payment_id) . '-card-holder-name">' . __('Card Holder Name', 'woocommerce') . ' <span class="required">*</span></label>
                 <input id="' . esc_attr($payment_id) . '-card-holder-name" class="input-text wc-credit-card-form-card-holder-name" type="text" maxlength="20" autocomplete="off" placeholder="Enter your name" name="' . $payment_id . '-card-holder-name" />
             </p>';

		return $this->array_unshift_assoc($cc_fields, "card-holder-name-field", $cc_card_holder_field);
	}


	public function get_curl($url = '')
	{
		if ($url) {
			$curl = curl_init($url);
		} else {
			$curl = curl_init();
		}
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_AUTOREFERER, 1);
		curl_setopt($curl, CURLOPT_VERBOSE, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			'X-Api-Key: ' . $this->api_key,
			'X-Api-Secret: ' . $this->secret_key,
			'User-Agent: ' . LYFE_APP_NAME,
		));
		return $curl;
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

		global $woocommerce;
		$order = wc_get_order($order_id);
		$amount_details = json_encode([
			"amount"           => $order->order_total,
		]);
		$response = wp_remote_post($this->api_base_url . '/api/v1/paymentintent/', array(
			'method'    => 'POST',
			'headers'   => array(
				'X-Api-Key'      => $this->api_key,
				'X-Api-Secret'   => $this->secret_key,
				'Content-Type'   => 'application/json',
			),
			'body'               => $amount_details,
		));
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		if ($response_data && $response_data['status'] == 1) {
			// Return response data
			return $response_data;
		} else {
			// Handle error
			return [
				'status' => 0,
				'message' => 'Payment intent creation failed.'
			];
		}
	}



	public function get_client_token()
	{
		check_ajax_referer('my_nonce_action', 'security');

		global $woocommerce, $post;
		$orderId = $post->ID;
		$order = wc_get_order($orderId);
		$order_id = $order->get_id();
		$gateway = new WC_Gateway_lyfePAY();
		$response_data = $gateway->process_payment($order_id);

		// Return the response as JSON
		wp_send_json($response_data);
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
		if (!$amount || $amount < 1) {
			return new WP_Error('lyfePAY_refund_error', 'There was a problem initiating a refund. This value must be greater than or equal to $1');
		}

		$transaction_id = get_post_meta($order_id, '_transaction_id', true);
		// $curl = $this->get_curl();
		$order_data = get_post_meta($order_id);

		$post = array(
			'charge_id' => $transaction_id,
			'amount' 	=> $amount
		);

		if ($this->testmode) {
			$post['test_mode'] = true;
		}

		$refundAmount = wp_remote_post($this->api_base_url . '/api/v1/refunds/', array(
			'method'    => 'POST',
			'headers'   => array(
				'X-Api-Key'      => $this->api_key,
				'X-Api-Secret'   => $this->secret_key,
				'Content-Type'   => 'application/json',
				'User-Agent: ' . LYFE_APP_NAME,
			),
			'body'               => $post,
		));

		$refund_body = wp_remote_retrieve_body($refundAmount);
		$refund_data = json_decode($refund_body, true);

		if ($refund_data['status']) {
			$order = new WC_Order($order_id);
			// create the note
			$order->add_order_note('Refunded $' . $amount . ' - Refund ID: ' . $refund_data['refund_id'] . ' - Reason: ' . $reason);
			return true;
		} else {
			return new WP_Error('lyfePAY_refund_error', $refund_data['refund_id']);
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
				'description' =>  '<img src="' . plugin_dir_url(__FILE__) . 'assets/images/lyfecycle-payments-logo.png" class="" alt="Logo" style="max-width: 30%; height: auto;filter:drop-shadow(2px 4px 6px black);"/>',
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



	/**
	 * Process subscription payment.
	 *
	 * @param  float     $amount
	 * @param  WC_Order  $order
	 * @return void
	 */
	public function process_subscription_payment($amount, $order)
	{
		$order_id = $order->get_id();
		$transaction_id = get_post_meta($order_id, '_transaction_id', true);
		$user_id = $order->get_user_id();
		$im_cus_id = get_user_meta($user_id, '_customer_id', true);
		$card_id = get_post_meta($order_id, '_card_id', true);

		if (!$im_cus_id || !$card_id) {
			$order->update_status('failed', __('lyfePAY Subscription Payment Failed: Missing customer or card details', 'woocommerce-easymerchant'));
			return;
		}

		$body = json_encode([
			'payment_mode' => 'auth_and_capture',
			'amount' => $amount,
			'description' => sprintf(__('Subscription Payment for Order #%s', 'woocommerce'), $order_id),
			'currency' => strtolower(get_woocommerce_currency()),
			'customer' => $im_cus_id,
			'card' => $card_id,
		]);

		$response = wp_remote_post($this->api_base_url . '/api/v1/charges', array(
			'method'    => 'POST',
			'headers'   => array(
				'X-Api-Key'      => $this->api_key,
				'X-Api-Secret'   => $this->secret_key,
				'Content-Type'   => 'application/json',
				'User-Agent: ' . LYFE_APP_NAME,
			),
			'body' => $body,
		));

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		if (isset($response_data['status']) && $response_data['status'] === 'success') {
			$order->payment_complete($response_data['transaction_id']);
			$order->add_order_note(sprintf(__('lyfePAY Subscription Payment Successful (Transaction ID: %s)', 'woocommerce-easymerchant'), $response_data['transaction_id']));
		} else {
			$order->update_status('failed', sprintf(__('lyfePAY Subscription Payment Failed: %s', 'woocommerce-easymerchant'), $response_data['message']));
		}
	}
}
