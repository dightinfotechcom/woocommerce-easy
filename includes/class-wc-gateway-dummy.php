<?php

/**
 * WC_Gateway_Easymerchant class
 *
 * @author   Easymerchant <info@easymerchant.io>
 * @package  WooCommerce Easymerchant Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Easymerchant Gateway.
 *
 * @class    WC_Gateway_Easymerchant
 * @version  1.0.7
 */
class WC_Gateway_Dummy extends WC_Payment_Gateway
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
		$this->icon               = apply_filters('woocommerce_easymerchant_gateway_icon', '');
		$this->logo 			  = $this->get_option('logo_display');
		$this->has_fields         = true;
		$this->title 			  = 'Easy Merchant';
		$this->supports           = array(
			'subscriptions',
			'products',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'multiple_subscriptions',
			'pre-orders',
			'add_payment_method',
			'refunds',
			'default_credit_card_form'
		);

		$this->method_title       = _x('Easymerchant', 'Easymerchant payment method', 'woocommerce-easymerchant');
		$this->method_description = __('Easymerchant Gateway Options.', 'woocommerce-easymerchant');

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->enabled = $this->get_option('enabled');
		$this->testmode = 'yes' === $this->get_option('test_mode');
		if ($this->testmode == 'yes') {
			$this->api_key = $this->get_option('test_api_key');
			$this->secret_key = $this->get_option('test_secret_key');
			$this->api_base_url = 'https://stage-api.stage-easymerchant.io/';
		} else {
			$this->api_key = $this->get_option('api_key');
			$this->secret_key = $this->get_option('api_secret');
			$this->api_base_url = 'https://api.easymerchant.io/';
		}
		$this->capture = 'yes' === $this->get_option('capture', 'yes');
		$this->saved_cards = 'yes' === $this->get_option('saved_cards');
		// Actions.
		add_filter('woocommerce_credit_card_form_fields', array($this, 'add_cc_card_holder_name'), 10, 2);
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_scheduled_subscription_payment_dummy', array($this, 'process_subscription_payment'), 10, 2);
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
		if ($payment_id === 'easymerchant')
			return $cc_fields;
		$cc_card_holder_field = '<p class="form-row form-row-wide">
                 <label for="' . esc_attr($payment_id) . '-card-holder-name">' . __('Card Holder Name', 'woocommerce') . ' <span class="required">*</span></label>
                 <input id="' . esc_attr($payment_id) . '-card-holder-name" class="input-text wc-credit-card-form-card-holder-name" type="text" maxlength="20" autocomplete="off" placeholder="Enter your name" name="' . $payment_id . '-card-holder-name" />
             </p>';

		return $this->array_unshift_assoc($cc_fields, "card-holder-name-field", $cc_card_holder_field);
	}


	/**
	 * display html for save card checkbox
	 * @param  boolean $force_checked allow forced check
	 * @return html                 returns save card checkbox html
	 */
	public function save_payment_method_checkbox($force_checked = false)
	{
		$id = 'wc-' . $this->id . '-new-payment-method';
?>
		<fieldset <?php echo $force_checked ? 'style="display:none;"' : ''; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
			<p class="form-row woocommerce-SavedPaymentMethods-saveNew">
				<input id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($id); ?>" type="checkbox" value="true" style="width:auto;" <?php echo $force_checked ? 'checked' : ''; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?> />
				<label for="<?php echo esc_attr($id); ?>" style="display:inline;">
					<?php echo 'Save payment information to my account for future purchases.'; ?>
				</label>
			</p>
		</fieldset>
<?php
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
			'X-Api-Secret: ' . $this->secret_key
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
		session_start();
		$order = wc_get_order($order_id);
		$user_id = get_current_user_id();
		$im_cus_id = get_user_meta($user_id, '_customer_id', true);

		// Generate a random number between 1000 and 9999
		$randomNumber = rand(1000, 9999);
		// Concatenate the strings and the random number
		$username = $order->shipping_first_name . $order->shipping_last_name . $randomNumber;
		$customer_details = json_encode([
			"username" 			=> strtolower($username),
			"email" 			=> $order->billing_email,
			"name" 				=> $order->shipping_first_name . ' ' . $order->shipping_last_name,
			"address" 			=> $order->shipping_address_1,
			"city" 				=> $order->shipping_city,
			"state" 			=> $order->shipping_state,
			"zip" 				=> $order->shipping_postcode,
			"country" 			=> $order->billing_country,
		]);

		$response = wp_remote_post($this->api_base_url . 'api/v1/customers/', array(
			'method'    => 'POST',
			'headers'   => array(
				'X-Api-Key'      => $this->api_key,
				'X-Api-Secret'   => $this->secret_key,
				'Content-Type'   => 'application/json',
			),
			'body'               => $customer_details,
		));
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);


		$getCard = $_SESSION['cardDetails'];
		$card_number 	 = str_replace(' ', '', $getCard['easymerchant-card-number'] ?? '');
		$expiry 		 = $getCard['easymerchant-card-expiry'] ?? '';
		$cvc 			 = $getCard['easymerchant-card-cvc'] ?? '';
		$cardholder_name = $getCard['easymerchant-card-holder-name'] ?? '';

		// Extract expiry month and year
		list($exp_month, $exp_year) = explode(' / ', $expiry);


		$card_details 	= json_encode([
			'card_number' 			=> $card_number,
			'cardholder_name'		=> $cardholder_name,
			'exp_month'				=> $exp_month,
			'exp_year' 				=> $exp_year,
			'cvc'					=> $cvc,
			'customer' 			    => $response_data['customer_id'],
		]);

		$cardSaved = wp_remote_post($this->api_base_url . 'api/v1/cards/', array(
			'method'    => 'POST',
			'headers'   => array(
				'X-Api-Key'      => $this->api_key,
				'X-Api-Secret'   => $this->secret_key,
				'Content-Type'   => 'application/json',
			),
			'body'               => $card_details,
		));
		$card_body = wp_remote_retrieve_body($cardSaved);
		$card_data = json_decode($card_body, true);

		echo '<pre>';
		print_r($_POST['save_payment_method']);
		// print_r($_POST['wc-easymerchant-new-payment-method']);
		// print_r($force_customer);
		// print_r($response_data['customer_id']);
		echo '</pre>';
		die();
		//existing customer and save new card
		if ((($this->saved_cards == 'yes' && isset($_POST['wc-easymerchant-new-payment-method'])) || $force_customer) && $response_data['customer_id']) {
			$getCard = wp_remote_post($this->api_base_url . 'api/v1/cards/' . $response_data['customer_id'] . '/cards/', array(
				'method'    => 'GET',
				'headers'   => array(
					'X-Api-Key'      => $this->api_key,
					'X-Api-Secret'   => $this->secret_key,
					'Content-Type'   => 'application/json',
				),
			));
			$card_list = wp_remote_retrieve_body($getCard);
			$list_data = json_decode($card_list, true);
		}

		if ($order->get_total() > 0) {
			$body = json_encode([
				'payment_mode'      => 'auth_and_capture',
				'amount'            => $order->order_total,
				'name'              => $order->shipping_first_name . ' ' . $order->shipping_last_name,
				'email'             => $order->billing_email,
				'description'       => sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number()),
				'currency'		    => strtolower(get_woocommerce_currency()),
				'card_number' 		=> $card_number,
				'cardholder_name'	=> $cardholder_name,
				'exp_month'			=> $exp_month,
				'exp_year' 			=> $exp_year,
				'cvc'				=> $cvc,
			]);
			$charge = wp_remote_post($this->api_base_url . 'api/v1/charges', array(
				'method'    		=> 'POST',
				'headers'   		=> array(
					'X-Api-Key'     => $this->api_key,
					'X-Api-Secret'  => $this->secret_key,
					'Content-Type'  => 'application/json',
				),
				'body'				=> $body,
			));
			$createCharge 	= wp_remote_retrieve_body($charge);
			$chargeResponse = json_decode($createCharge, true);
		} else {
			//existing customer and existing card
			if ($_POST['ccard_id'] != '' && $im_cus_id) {
				$customer_id = $im_cus_id;
				$card_id = $_POST['ccard_id'];
				$source = (object) array(
					'customer' => $customer_id,
					'source'   => $card_id
				);
				// Store source to order meta.
				$this->save_source($order, $source);
			} else if ($im_cus_id) {
				//existing customer and save new card
				$body = json_encode([
					'card_number' 		=> $card_number,
					'cardholder_name'	=> $cardholder_name,
					'exp_month'			=> $exp_month,
					'exp_year' 			=> $exp_year,
					'cvc'				=> $cvc,
					'customer'			=> $response_data['customer_id'],
				]);
				$post['customer'] = $im_cus_id;
				$card = wp_remote_post($this->api_base_url . 'api/v1/card', array(
					'method'    => 'POST',
					'headers'   => array(
						'X-Api-Key'     => $this->api_key,
						'X-Api-Secret'  => $this->secret_key,
						'Content-Type'  => 'application/json',
					),
					'body'				=> $body,
				));
				$createCard = wp_remote_retrieve_body($card);
				$cardResponse = json_decode($createCard, true);

				if ($cardResponse && $cardResponse->status) {
					$source = (object) array(
						'customer' => $post['customer'],
						'source'   => $cardResponse->card_id
					);
					// Store source to order meta.
					$this->save_source($order, $source);
				} else {
					wc_add_notice(__('Payment error:', 'woocommerce-easymerchant') . ' ' . $cardResponse->message, 'error');
					return false;
				}
			} else {
				//new customer save customer and save card
				$customer = wp_remote_post($this->api_base_url . 'api/v1/customer', array(
					'method'    => 'POST',
					'headers'   => array(
						'X-Api-Key'     => $this->api_key,
						'X-Api-Secret'  => $this->secret_key,
						'Content-Type'  => 'application/json',
					),
					'body'				=> $customer_details,
				));
				$createCustomer = wp_remote_retrieve_body($customer);
				$customerResponse = json_decode($createCustomer, true);

				if ($customerResponse && $customerResponse->status) {
					$customer_id = $customerResponse->customer_id;
					$card_details['customer'] = $customer_id;

					$cards = wp_remote_post($this->api_base_url . 'api/v1/card', array(
						'method'    => 'POST',
						'headers'   => array(
							'X-Api-Key'     => $this->api_key,
							'X-Api-Secret'  => $this->secret_key,
							'Content-Type'  => 'application/json',
						),
						'body'				=> $card_details,
					));
					$createCards = wp_remote_retrieve_body($cards);
					$cardsResponse = json_decode($createCards, true);

					if ($cardsResponse && $cardsResponse->status) {
						$source = (object) array(
							'customer' => $customer_id,
							'source'   => $cardsResponse->card_id
						);
						// Store source to order meta.
						$this->save_source($order, $source);
					} else {
						wc_add_notice(__('Payment error:', 'woocommerce-easymerchant') . ' ' . $cardsResponse->message, 'error');
						return false;
					}
				} else {
					wc_add_notice(__('Payment error:', 'woocommerce-easymerchant') . ' ' . $customerResponse->message, 'error');
					return false;
				}
			}
			$order = new WC_Order($order_id);
			$order->payment_complete();
			$woocommerce->cart->empty_cart();
		}
		return array(
			'result' => 'success',
			'redirect' => $this->get_return_url($order)
		);
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

		$transaction_id = get_post_meta($order_id, '_transaction_id', true);
		$curl = $this->get_curl();
		$order_data = get_post_meta($order_id);

		$post = array(
			'charge_id' => $transaction_id,
			'amount' => $amount
		);

		if ($this->testmode) {
			$post['test_mode'] = true;
		}

		curl_setopt($curl, CURLOPT_URL, $this->api_base_url . 'api/v1/refund');
		curl_setopt($curl, CURLOPT_POST, 'true');
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post);

		$resp = json_decode(curl_exec($curl));


		if ($resp && $resp->status) {
			$order = new WC_Order($order_id);
			// create the note
			$order->add_order_note($resp->message . ' transaction_id ' . $resp->refund_id);
			return true;
		} else {
			return new WP_Error('simplify_refund_error', $resp->message());
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
				'description' =>  '<img src="' . esc_url(site_url('/wp-content/uploads/OIP.jpg')) . '" alt="Custom Logo" style="max-width: 40px; height: auto;"/>',
			),
			'title' => array(
				'title'       => __('Title', 'woocommerce'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
				'default'     => __('Credit card', 'woocommerce'),
				'desc_tip'    => true
			),
			'description' => array(
				'title'       => __('Description', 'woocommerce'),
				'type'        => 'text',
				'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
				'default'     => 'Pay with your credit card via Easy Merchant.',
				'desc_tip'    => true
			),
			'api_key' => array(
				'title' => __('API Key', 'woocommerce'),
				'description' => __('Get your API key from EasyMerchant.', 'woocommerce'),
				'type' => 'text',
				'default' => '',
				'desc_tip' => true,
			),
			'api_secret' => array(
				'title' => __('API Secret', 'woocommerce'),
				'description' => __('Get your API secret from EasyMerchant.', 'woocommerce'),
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
			'test_secret_key' => array(
				'title'       => __('Test Secret Key', 'woocommerce'),
				'type'        => 'text',
				'description' => __('Get your API keys from your EasyMerchant account.', 'woocommerce'),
				'default'     => '',
				'desc_tip'    => true,
			),
			'test_api_key' => array(
				'title'       => __('Test API Key', 'woocommerce'),
				'type'        => 'text',
				'description' => __('Get your API keys from your EasyMerchant account.', 'woocommerce'),
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
				'description' => __('If enabled, users will be able to pay with a saved card during checkout. Card details are saved on Easy Merchant servers, not on your store.', 'woocommerce'),
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
		$icon  = '<img src="' . WC_HTTPS::force_https_url(WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.png') . '" alt="Visa" />';
		$icon .= '<img src="' . WC_HTTPS::force_https_url(WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.png') . '" alt="MasterCard" />';
		$icon .= '<img src="' . WC_HTTPS::force_https_url(WC()->plugin_url() . '/assets/images/icons/credit-cards/amex.png') . '" alt="Amex" />';
		return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
	}



	/**
	 * Process subscription payment.
	 *
	 * @param  float     $amount
	 * @param  WC_Order  $order
	 * @return void
	 */
	// public function process_subscription_payment($amount, $order)
	// {
	// 	$payment_result = $this->get_option('result');

	// 	if ('success' === $payment_result) {
	// 		$order->payment_complete();
	// 	} else {
	// 		$order->update_status('failed', __('Subscription payment failed. To make a successful payment using Easymerchant Payments, please review the gateway settings.', 'woocommerce-easymerchant'));
	// 	}
	// }
}
