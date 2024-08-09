<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WC_Gateway_Easymerchant_Addons class.
 *
 * @extends WC_Gateway_lyfePAY
 */
class WC_Gateway_Easymerchant_Addons extends WC_Gateway_lyfePAY
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
		
			add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [ $this, 'scheduled_subscription_payment' ], 10, 3);
			add_action( 'woocommerce_payment_complete', array( __CLASS__, 'trigger_renewal_payment_complete' ), 10 );
		

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

	public function process_subscription_payment($amount = 0, $renewal_order, $order = '')
	{
		global $woocommerce;
		if ($amount * 100 < 50) {
			return new WP_Error('easymerchant_error', __('Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'woocommerce-easymerchant'));
		}
		$order_id = $renewal_order->get_id();
		if ($order) {
			$card_id = get_post_meta($order->id, '_card_id', true);

			if (!empty($card_id)) {
				$url = $this->api_base_url . "/api/v1/card?card_id=$card_id";
				$curl = curl_init();
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
					return new WP_Error('easymerchant_error', 'Request Error: ' . curl_error($curl));
				}

				$resp = json_decode($response, true);
				curl_close($curl);

				if (json_last_error() !== JSON_ERROR_NONE) {
					return new WP_Error('easymerchant_error', 'JSON Decode Error: ' . json_last_error_msg());
				}

				if (isset($resp['Card']['customer_id'])) {
					$customer_id = $resp['Card']['customer_id'];
				} else {
					return new WP_Error('easymerchant_error', 'Customer ID not found in the response.');
				}
			} else {
				return new WP_Error('easymerchant_error', 'Card ID is empty. Cannot make the API request.');
			}
		}

	
		$charge_card = array(
			'customer'=>$customer_id,
			'description' => sprintf( __( '%s - Order #%s', 'woocommerce' ), esc_html( get_bloginfo( 'name', 'display' ) ), $order->get_order_number() ),
			'amount'=>$amount,
			'currency'=> strtolower(get_woocommerce_currency()),
			'card_id'=>$card_id
			);
		

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_URL, $this->api_base_url . '/api/v1/charges');
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $charge_card);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			'X-Api-Key: ' . $this->api_key,
			'X-Api-Secret: ' . $this->secret_key,
			'User-Agent: ' . LYFE_APP_NAME,
		));

		$resp = json_decode(curl_exec($curl));
        if($resp && $resp->status) {
			$order = new WC_Order( $order->id );
			$order->payment_complete();
			WC_Subscriptions_Manager::activate_subscriptions_for_order( $order );
			update_post_meta( $order->id, '_transaction_id', $resp->charge_id, true );
			
			// create the note
			if(!$this->capture){
					if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
					$order->reduce_order_stock();
				}

				$order->update_status( 'on-hold', sprintf( __( 'LyfePAY charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-easymerchant' ), $resp->charge_id ) );
				
			}else{
				
				$order->add_order_note( $resp->message.' Transaction ID '. $resp->charge_id);
				$order->payment_complete();
				$order->update_status('active');
				return array(
						'result' => 'success',
						'redirect' => $this->get_return_url( $order )
				);
			}
		}
		else
		{
			return false;
		}
	}

	/**
	 * scheduled_subscription_payment function.
	 *
	 * @param $amount_to_charge float The amount to charge.
	 * @param $renewal_order WC_Order A WC_Order object created to record the renewal payment.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		$this->process_subscription_payment( $amount_to_charge, $renewal_order, true, false );
	}
	
	public static function trigger_renewal_payment_complete($order_id) {
		if (wcs_order_contains_renewal($order_id)) {
			do_action('woocommerce_renewal_order_payment_complete', $order_id);
		}
	}
	
	// public function process_subscription_payments_on_order($subscription_id) {
	// 	$subscription = wcs_get_subscription($subscription_id);
	
	// 	if (!$subscription) {
	// 		return new WP_Error('invalid_subscription', __('Invalid subscription ID', 'woocommerce-easymerchant'));
	// 	}
	
	// 	// Retrieve the charge_id from the initial order or subscription meta
	// 	$original_order_id = $subscription->get_parent_id();
	// 	$charge_id = get_post_meta($original_order_id, '_charge_id', true);
	
	// 	if (!$charge_id) {
	// 		return new WP_Error('missing_charge_id', __('Charge ID not found for this subscription', 'woocommerce-easymerchant'));
	// 	}
	// 	if ($order) {
	// 		$img_source = get_post_meta($original_order_id, '_card_id', true);

	// 		if (!empty($img_source)) {
	// 			$url = $this->api_base_url . "/api/v1/card?card_id=$img_source";
	// 			$curl = curl_init();
	// 			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
	// 			curl_setopt($curl, CURLOPT_URL, $url);
	// 			curl_setopt($curl, CURLOPT_HTTPHEADER, array(
	// 				'X-Api-Key: ' . $this->api_key,
	// 				'X-Api-Secret: ' . $this->secret_key,
	// 				'User-Agent: ' . LYFE_APP_NAME,
	// 			));
	// 			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

	// 			$response = curl_exec($curl);
	// 			if (curl_errno($curl)) {
	// 				return new WP_Error('easymerchant_error', 'Request Error: ' . curl_error($curl));
	// 			}

	// 			$resp = json_decode($response, true);
	// 			curl_close($curl);

	// 			if (json_last_error() !== JSON_ERROR_NONE) {
	// 				return new WP_Error('easymerchant_error', 'JSON Decode Error: ' . json_last_error_msg());
	// 			}

	// 			if (isset($resp['Card']['customer_id'])) {
	// 				$customer_id = $resp['Card']['customer_id'];
	// 			} else {
	// 				return new WP_Error('easymerchant_error', 'Customer ID not found in the response.');
	// 			}
	// 		} else {
	// 			return new WP_Error('easymerchant_error', 'Card ID is empty. Cannot make the API request.');
	// 		}
	// 	}
	// 	// Calculate the renewal amount
	// 	$renewal_amount = $subscription->get_total();
	// 	$renew_body = json_encode([
	// 		'customer'=>$customer_id,
	// 		'description' => sprintf( __( '%s - Order #%s', 'woocommerce' ), esc_html( get_bloginfo( 'name', 'display' ) ), $order->get_order_number() ),
	// 		'amount'=>$renewal_amount,
	// 		'currency'=> strtolower(get_woocommerce_currency()),
	// 		'card_id'=>$img_source
	// 	]);
		
	// 	$response = wp_remote_post($this->api_base_url . '/api/v1/charges/', array(
	// 		'method'    => 'POST',
	// 		'headers'   => array(
	// 			'X-Api-Key'      => $this->api_key,
	// 			'X-Api-Secret'   => $this->secret_key,
	// 			'Content-Type'   => 'application/json',
	// 			'User-Agent: ' . LYFE_APP_NAME,
	// 		),
	// 		'body' => $renew_body
	// 	));
	
	// 	if (is_wp_error($response)) {
	// 		$subscription->add_order_note(
	// 			sprintf(__('Subscription renewal payment failed: %s', 'woocommerce-easymerchant'), $response->get_error_message())
	// 		);
	// 		return new WP_Error('renewal_failed', __('Subscription renewal failed due to HTTP error', 'woocommerce-easymerchant'));
	// 	}
	
	// 	$response_body = wp_remote_retrieve_body($response);
	// 	$response_data = json_decode($response_body, true);
	
	// 	if ($response_data && isset($response_data['status']) && $response_data['status'] == 1) {
	// 		// Renewal payment successful
	// 		$subscription->payment_complete();
	// 		$subscription->add_order_note(
	// 			sprintf(__('Subscription renewal payment successful. Amount: %s', 'woocommerce-easymerchant'), wc_price($renewal_amount))
	// 		);
	// 		return true;
	// 	} else {
	// 		// Renewal payment failed
	// 		$error_message = isset($response_data['message']) ? $response_data['message'] : __('Renewal payment failed', 'woocommerce-easymerchant');
	// 		$subscription->update_status('failed');
	// 		$subscription->add_order_note(
	// 			sprintf(__('Subscription renewal payment failed: %s', 'woocommerce-easymerchant'), $error_message)
	// 		);
	// 		return new WP_Error('renewal_failed', $error_message);
	// 	}
	// }
	
}
