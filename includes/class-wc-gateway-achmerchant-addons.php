<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WC_Gateway_ACHmerchant_Addons class.
 *
 * @extends WC_Gateway_ACH_LyfePAY
 */
class WC_Gateway_ACHmerchant_Addons extends WC_Gateway_ACH_LyfePAY
{
	public function __construct()
	{
		parent::__construct();
		$this->supports = array(
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions'
		);

		if (class_exists('WC_Subscriptions_Order')) {
			add_action('woocommerce_scheduled_subscription_payment_ach-easymerchant', array($this, 'scheduled_subscription_payment'), 10, 3);
		}

		if (class_exists('WC_Pre_Orders_Order')) {
			add_action('wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array($this, 'process_pre_order_release_payment'));
		}
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
	}

	/**
	 * Is $order_id a subscription?
	 * @param  int  $order_id
	 * @return boolean
	 */
	protected function is_subscription($order_id)
	{
		return (function_exists('wcs_order_contains_subscription') && (wcs_order_contains_subscription($order_id) || wcs_is_subscription($order_id) || wcs_order_contains_renewal($order_id)));
	}

	/**
	 * Is $order_id a pre-order?
	 * @param  int  $order_id
	 * @return boolean
	 */
	protected function is_pre_order($order_id)
	{
		return (class_exists('WC_Pre_Orders_Order') && WC_Pre_Orders_Order::order_contains_pre_order($order_id));
	}

	/**
	 * Process the payment based on type.
	 * @param  int $order_id
	 * @return array
	 */
	public function process_payment($order_id, $retry = true, $force_customer = false)
	{
		if ($this->is_subscription($order_id)) {
			return $this->process_subscription_payment($order_id);
		} elseif ($this->is_pre_order($order_id)) {
			return $this->process_pre_order($order_id, $retry, $force_customer);
		} else {
			return parent::process_payment($order_id, $retry, $force_customer);
		}
	}

	/**
	 * Updates other subscription sources.
	 */
	protected function save_source($order, $source)
	{
		parent::save_source($order, $source);

		// Also store it on the subscriptions being purchased or paid for in the order
		if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order->id)) {
			$subscriptions = wcs_get_subscriptions_for_order($order->id);
		} elseif (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order->id)) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order($order->id);
		} else {
			$subscriptions = array();
		}

		foreach ($subscriptions as $subscription) {
			update_post_meta($subscription->id, '_customer_id', $source->customer);
			update_post_meta($subscription->id, '_card_id', $source->source);
		}
	}

	/**
	 * process_subscription_payment function.
	 * @param mixed $order
	 * @param int $amount (default: 0)
	 * @param  bool initial_payment
	 */
	public function process_subscription_payment($amount = 0, $order = '')
	{
		
		global $woocommerce;
		// if ($amount * 100 < 50) {
		// 	return new WP_Error('lyfepay_error', __('Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'woocommerce-easymerchant'));
		// }

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

		$paymentPayload = json_decode(stripslashes($_COOKIE['ACHPaymentPayload']), true);
		$customerPayload = json_decode(stripslashes($_COOKIE['customer_response']), true);

		$ach_details = json_encode([
			'description'    => sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number()),
			'account_number' => $paymentPayload['account_number'],
			'routing_number' => $paymentPayload['routing_number'],
			'account_type'   => $paymentPayload['account_type'],
			'currency'       => strtolower(get_woocommerce_currency()),
			'amount'         => $order->order_total,
			'customer'       => $customerPayload['customer_id'],
			'name'           => $order->get_billing_first_name() . " " . $order->get_billing_last_name()
		]);
		$chargeResponse = wp_remote_post($this->api_base_url . '/api/v1/ach/charge/', array(
			'method'    => 'POST',
			'headers'   => array(
				'X-Api-Key'      => $this->api_key,
				'X-Api-Secret'   => $this->secret_key,
				'User-Agent: ' . LYFE_APP_NAME,
				'Content-Type'   => 'application/json',
			),
			'body' => $ach_details,
		));

		$charge_response_body = wp_remote_retrieve_body($chargeResponse);
		$charge_response_data = json_decode($charge_response_body, true);

		if ($charge_response_data && $charge_response_data->status) {
			$order = new WC_Order($order->id);
			update_post_meta($order->id, '_charge_id', $charge_response_data->charge_id, true);

			// create the note
			if (!$this->capture) {
				if ($order->has_status(array('pending', 'failed'))) {
					$order->reduce_order_stock();
				}

				$order->update_status('on-hold', sprintf(__('lyfePAY charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-easymerchant'), $charge_response_data->charge_id));
			} else {
				$order->add_order_note($charge_response_data->message . ' Transaction ID ' . $charge_response_data->charge_id);
				$order->payment_complete();
				$order->update_status('on-hold');
				return array(
					'result' => 'success',
					'redirect' => $this->get_return_url($order)
				);
			}
		} else {
			return false;
		}
	}
}
