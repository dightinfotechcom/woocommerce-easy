<?php

/**
 * WC_Gateway_ACH_LyfePAY class
 *
 * @author   lyfePAY ACH <info@easymerchant.io>
 * @package  WooCommerce lyfePAY ACH Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * lyfePAY ACH.
 *
 * @class    WC_Gateway_ACH_LyfePAY
 * @version  1.0.7
 */

class WC_Gateway_ACH_LyfePAY extends WC_Payment_Gateway
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
    public $id = 'ach-easymerchant';

    public function __construct()
    {
        $this->id = 'ach-easymerchant';
        $this->has_fields = true;
        $this->title = 'lyfePAY ACH';
        $this->supports = array(
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
        );
        $this->method_title = _x('lyfePAY ACH', 'lyfePAY ACH  Payment Method', 'woocommerce-easymerchant');
        $this->method_description = __('lyfePAY ACH Gateway Options', 'woocommerce-easymerchant');
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
    }


    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-easymerchant'),
                'type' => 'checkbox',
                'label' => __('Enable', 'woocommerce-easymerchant'),
                'default' => 'no'
            ),
            'title' => array(
                'title'       => __('Title', 'woocommerce-easymerchant'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-easymerchant'),
                'default'     => __('lyfePAY ACH', 'woocommerce-easymerchant'),
                'desc_tip'    => true
            ),
            'description' => array(
                'title'       => __('Description', 'woocommerce-easymerchant'),
                'type'        => 'text',
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-easymerchant'),
                'default'     => 'Pay your bill via lyfePAY ACH.',
                'desc_tip'    => true
            ),
            'api_key' => array(
                'title' => __('API Key', 'woocommerce-easymerchant'),
                'description' => __('Get your API key from ACH lyfePAY.', 'woocommerce-easymerchant'),
                'type' => 'text',
                'default' => '',
                'desc_tip' => true,
            ),
            'api_secret' => array(
                'title' => __('API Secret', 'woocommerce-easymerchant'),
                'description' => __('Get your API secret from ACH lyfePAY.', 'woocommerce-easymerchant'),
                'type' => 'text',
                'default' => '',
                'desc_tip' => true,
            ),
            'test_mode' => array(
                'title' => __('Test Mode', 'woocommerce-easymerchant'),
                'label' => __('Enable Test Mode', 'woocommerce-easymerchant'),
                'type' => 'checkbox',
                'default'     => 'yes',
                'desc_tip'    => true
            ),
            'test_api_key' => array(
                'title'       => __('Test API Key', 'woocommerce-easymerchant'),
                'type'        => 'text',
                'description' => __('Get your API keys from your ACH lyfePAY Account.', 'woocommerce-easymerchant'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'test_secret_key' => array(
                'title'       => __('Test Secret Key', 'woocommerce-easymerchant'),
                'type'        => 'text',
                'description' => __('Get your API keys from your ACH lyfePAY Account.', 'woocommerce-easymerchant'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'capture' => array(
                'title'       => __('Capture', 'woocommerce-easymerchant'),
                'label'       => __('Capture charge immediately', 'woocommerce-easymerchant'),
                'type'        => 'checkbox',
                'description' => __('Whether or not to immediately capture the charge. When unchecked, the charge issues an authorization and will need to be captured later. Uncaptured charges expire in 7 days.', 'woocommerce-easymerchant'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),

        );
    }

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

    // Here the payment is getting process starts from here 
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
            setcookie('ACHPaymentPayload', '', time() - 3600, "/");
            setcookie('customer_response', '', time() - 3600, "/");
            if (isset($charge_response_data['charge_id'])) {
                update_post_meta($order_id, '_charge_id', $charge_response_data['charge_id']);
                $order->update_status('processing'); 
                $order->payment_complete();
                $woocommerce->cart->empty_cart();
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }
            
        }
        catch (\Throwable $th) {
			print_r($th);
			throw $th;
		}
    }

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

}
