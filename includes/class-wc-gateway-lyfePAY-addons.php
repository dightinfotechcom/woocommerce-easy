<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WC_Gateway_lyfePAY_Addons class.
 *
 * @extends WC_Gateway_lyfePAY
 */
class WC_Gateway_lyfePAY_Addons extends WC_Gateway_lyfePAY
{

	/**
	 * Constructor
	 */
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
			add_action('woocommerce_scheduled_subscription_payment_lyfePAY', array($this, 'scheduled_subscription_payment'), 10, 2);
		}

		if (class_exists('WC_Pre_Orders_Order')) {
			add_action('wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array($this, 'process_pre_order_release_payment'));
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
	 * @param string $stripe_token (default: '')
	 * @param  bool initial_payment
	 */
	// public function process_subscription_payment($amount = 0, $order = '')
	// {
	// 	//echo $amount;
	// 	global $woocommerce;
	// 	if ($amount * 100 < 50) {
	// 		return new WP_Error('stripe_error', __('Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'woocommerce-gateway-stripe'));
	// 	}

	// 	if ($order) {
	// 		$img_source = get_post_meta($order->id, '_card_id', true);

	// 		// Check if $img_source is not empty before making the request
	// 		if (!empty($img_source)) {
	// 			$url = $this->api_base_url . "/api/v1/card?card_id=$img_source";

	// 			$curl = curl_init();
	// 			$options = get_option('woocommerce_easymerchant_settings');
	// 			curl_setopt($curl, CURLOPT_POST, false);
	// 			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
	// 			curl_setopt($curl, CURLOPT_URL, $url);
	// 			curl_setopt($curl, CURLOPT_HTTPHEADER, array(
	// 				'X-Api-Key: ' . $this->api_key, // Replace with your actual API Key
	// 				'X-Api-Secret: ' . $this->secret_key, // Replace with your actual API Secret
	// 				'User-Agent: ' . LYFE_APP_NAME,
	// 			));
	// 			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

	// 			$resp = json_decode(curl_exec($curl), true);

	// 			if ($resp === null) {
	// 				// Handle JSON parsing error if needed
	// 				echo 'Error parsing JSON response: ' . curl_error($curl);
	// 			}

	// 			curl_close($curl);

	// 			if (isset($resp['Card']['customer_id'])) {
	// 				$customer_id = $resp['Card']['customer_id'];
	// 			} else {
	// 				echo "Customer ID not found in the response.";
	// 			}
	// 		} else {
	// 			echo "img_source is empty. Cannot make the API request.";
	// 		}
	// 	}

	// 	$url = 'https://stage-api.stage-easymerchant.io/';
	// 	$charge_card = array(
	// 		'customer' => $customer_id,
	// 		'description' => sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number()),
	// 		'amount' => $amount,
	// 		'currency' => strtolower(get_woocommerce_currency()),
	// 		'card_id' => $img_source
	// 	);
	// 	$curl = curl_init();
	// 	$options = get_option('woocommerce_easymerchant_settings');
	// 	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	// 	curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
	// 	curl_setopt($curl, CURLOPT_AUTOREFERER, true);
	// 	curl_setopt($curl, CURLOPT_VERBOSE, false);
	// 	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	// 	curl_setopt($curl, CURLOPT_URL, $url . 'api/v1/charges');
	// 	curl_setopt($curl, CURLOPT_POST, 'true');
	// 	curl_setopt($curl, CURLOPT_POSTFIELDS, $charge_card);
	// 	curl_setopt($curl, CURLOPT_HTTPHEADER, array(
	// 		'X-Api-Key: ' . $options['test_api_key'], // Replace with your actual API Key
	// 		'X-Api-Secret: ' . $options['test_secret_key'], // Replace with your actual API Secret
	// 		'User-Agent: ' . LYFE_APP_NAME,
	// 	));

	// 	$resp = json_decode(curl_exec($curl));
	// 	if ($resp && $resp->status) {
	// 		$order = new WC_Order($order->id);
	// 		update_post_meta($order->id, '_transaction_id', $resp->charge_id, true);

	// 		// create the note
	// 		if (!$this->capture) {
	// 			if ($order->has_status(array('pending', 'failed'))) {
	// 			}

	// 			$order->update_status('on-hold', sprintf(__('EasyMerchant charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-easymerchant'), $resp->charge_id));
	// 		} else {

	// 			$order->add_order_note($resp->message . ' Transaction ID ' . $resp->charge_id);
	// 			$order->payment_complete();
	// 		}
	// 		return array(
	// 			'result' => 'success',
	// 			'redirect' => $this->get_return_url($order)
	// 		);
	// 	} else {
	// 		return false;
	// 	}
	// }
	public function process_subscription_payment($amount = 0, $order = '')
	{
		global $woocommerce;
		if ($amount * 100 < 50) {
			return new WP_Error('lyfePAY_error', __('Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'woocommerce-lyfePAY'));
		}

		if ($order) {
			$img_source = get_post_meta($order->id, '_card_id', true);
			if (!empty($img_source)) {
				$url = $this->api_base_url . "/api/v1/card?card_id=$img_source";

				$curl = curl_init();
				$options = get_option('woocommerce_lyfePAY_settings');
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
				curl_setopt($curl, CURLOPT_URL, $url);
				curl_setopt($curl, CURLOPT_HTTPHEADER, array(
					'X-Api-Key: ' . $this->api_key,
					'X-Api-Secret: ' . $this->secret_key,
					'User-Agent: ' . LYFE_APP_NAME,
				));
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

				$response = curl_exec($curl);
				if (curl_errno($curl)) {
					return new WP_Error('lyfePAY_error', 'Request Error: ' . curl_error($curl));
				}

				$resp = json_decode($response, true);
				curl_close($curl);

				if (json_last_error() !== JSON_ERROR_NONE) {
					return new WP_Error('lyfePAY_error', 'JSON Decode Error: ' . json_last_error_msg());
				}

				if (isset($resp['Card']['customer_id'])) {
					$customer_id = $resp['Card']['customer_id'];
				} else {
					return new WP_Error('lyfePAY_error', 'Customer ID not found in the response.');
				}
			} else {
				return new WP_Error('lyfePAY_error', 'Card ID is empty. Cannot make the API request.');
			}
		}

		$url = 'https://stage-api.stage-easymerchant.io/';
		$charge_card = array(
			'customer' => $customer_id,
			'description' => sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number()),
			'amount' => $amount,
			'currency' => strtolower(get_woocommerce_currency()),
			'card_id' => $img_source
		);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_URL, $url . 'api/v1/charges');
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($charge_card));
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			'X-Api-Key: ' . $options['test_api_key'],
			'X-Api-Secret: ' . $options['test_secret_key'],
			'User-Agent: ' . LYFE_APP_NAME,
		));

		$response = curl_exec($curl);
		if (curl_errno($curl)) {
			return new WP_Error('lyfePAY_error', 'Request Error: ' . curl_error($curl));
		}

		$resp = json_decode($response);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return new WP_Error('lyfePAY_error', 'JSON Decode Error: ' . json_last_error_msg());
		}

		curl_close($curl);

		if ($resp && isset($resp->status) && $resp->status) {
			$order = new WC_Order($order->id);
			update_post_meta($order->id, '_transaction_id', $resp->charge_id, true);

			if (!$this->capture) {
				$order->update_status('on-hold', sprintf(__('lyfePAY charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-lyfePAY'), $resp->charge_id));
			} else {
				$order->add_order_note($resp->message . ' Transaction ID ' . $resp->charge_id);
				$order->payment_complete();
			}

			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url($order)
			);
		} else {
			return new WP_Error('lyfePAY_error', 'Payment processing failed.');
		}
	}

	/**
	 * scheduled_subscription_payment function.
	 *
	 * @param $amount_to_charge float The amount to charge.
	 * @param $renewal_order WC_Order A WC_Order object created to record the renewal payment.
	 */
	public function scheduled_subscription_payment($amount_to_charge, $renewal_order)
	{
		$response = $this->process_subscription_payment($amount_to_charge, $renewal_order);
		if (is_wp_error($response)) {
			$renewal_order->update_status('failed', sprintf(__('lyfePAY Transaction Failed (%s)', 'woocommerce-lyfePAY'), $response->get_error_message()));
		}
	}
}
