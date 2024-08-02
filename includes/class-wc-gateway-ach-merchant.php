<?php

/**
 * WC_Gateway_ACH_Easymerchant class
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
 * @class    WC_Gateway_ACH_Easymerchant
 * @version  1.0.7
 */

class WC_Gateway_ACH_Easymerchant extends WC_Payment_Gateway
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
            'default_credit_card_form'
        );
        $this->method_title = _x('lyfePAY ACH', 'lyfePAY ACH  Payment Method', 'woocommerce-easymerchant');
        $this->method_description = __('lyfePAY ACH Gateway Options', 'woocommerce-easymerchant');
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('test_mode');
        if ($this->testmode == 'yes') { // Arvind please check if this is correct and make all testmode code same
            $this->api_key = $this->get_option('test_api_key');
            $this->secret_key = $this->get_option('test_secret_key');
            $this->api_base_url = 'https://stage-api.stage-easymerchant.io';
        } else {
            $this->api_key = $this->get_option('api_key');
            $this->secret_key = $this->get_option('api_secret');
            $this->api_base_url = 'https://api.easymerchant.io';
        }
        $this->capture = 'yes' === $this->get_option('capture', 'yes');

        add_filter('woocommerce_account_form_fields', array($this, 'add_cc_account_holder_name'), 10, 2);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
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

        global $woocommerce;
        session_start();

        $order = wc_get_order($order_id);
        $user_id = get_current_user_id();
        // $im_cus_id = get_user_meta($user_id, '_customer_id', true);
        // Generate a random number between 1000 and 9999
        $randomNumber = rand(1000, 9999);
        $username = $order->shipping_first_name . $order->shipping_last_name . $randomNumber;
        $customer_details = json_encode([
            "username"          => strtolower($username),
            "email"             => $order->billing_email,
            "name"              => $order->shipping_first_name . ' ' . $order->shipping_last_name,
            "address"           => $order->shipping_address_1,
            "city"              => $order->shipping_city,
            "state"             => $order->shipping_state,
            "zip"               => $order->shipping_postcode,
            "country"           => $order->billing_country,
        ]);
        $response = wp_remote_post($this->api_base_url . '/api/v1/customers/', array(
            'method'    => 'POST',
            'headers'   => array(
                'X-Api-Key'      => $this->api_key,
                'X-Api-Secret'   => $this->secret_key,
                'User-Agent: ' . LYFE_APP_NAME,
                'Content-Type'   => 'application/json',
            ),
            'body'               => $customer_details,
        ));
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        // Retrieve ACH details from Session
        $getAch = $_SESSION['achDetails'];

        $account_number = $getAch['ach-account-number'] ?? '';
        $routing_number = $getAch['ach-routing-number'] ?? '';
        $account_type   = $getAch['ach-account-type'] ?? '';

        $ach_details = json_encode([
            'description'    => sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number()),
            'account_number' => $account_number,
            'routing_number' => $routing_number,
            'account_type'   => $account_type,
            'currency'       => strtolower(get_woocommerce_currency()),
            'amount'         => $order->order_total,
            'customer'       => $response_data['customer_id'],
        ]);

        if ($order->get_total() > 0) {
            $body = json_encode([
                'payment_mode'      => 'auth_and_capture',
                'amount'            => $order->order_total,
                'name'              => $order->shipping_first_name . ' ' . $order->shipping_last_name,
                'email'             => $order->billing_email,
                'description'       => sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number()),
                'currency'          => strtolower(get_woocommerce_currency()),
                'account_number'    => $account_number,
                'routing_number'    => $routing_number,
                'account_type'      => $account_type,
            ]);
            $achCharge = wp_remote_post($this->api_base_url . '/api/v1/ach/charge', array(
                'method'    => 'POST',
                'headers'   => array(
                    'X-Api-Key'      => $this->api_key,
                    'X-Api-Secret'   => $this->secret_key,
                    'User-Agent: ' . LYFE_APP_NAME,
                    'Content-Type'   => 'application/json',
                ),
                'body'               => $body,
            ));
            $createAchCharge     = wp_remote_retrieve_body($achCharge);
            $achchargeResponse   = json_decode($createAchCharge, true);

            if (isset($achchargeResponse['status']) && !empty($achchargeResponse['status'])) {
                $order = new WC_Order($order_id);
                $order->payment_complete();
                $woocommerce->cart->empty_cart();
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if (!$amount || $amount < 1) {
            return new WP_Error('simplify_refund_error', 'There was a problem initiating a refund. This value must be greater than or equal to $1');
        }
        $transaction_id = get_post_meta($order_id, '_transaction_id', true);
        // $curl = $this->get_curl();
        // $order_data = get_post_meta($order_id);

        $post = array(
            'charge_id' => $transaction_id,
            'amount'     => $amount
        );

        if ($this->testmode) {
            $post['test_mode'] = true;
        }

        $refundAmount = wp_remote_post($this->api_base_url . '/api/v1/refunds/', array(
            'method'    => 'POST',
            'headers'   => array(
                'X-Api-Key'      => $this->api_key,
                'X-Api-Secret'   => $this->secret_key,
                'User-Agent: ' . LYFE_APP_NAME,
                'Content-Type'   => 'application/json',
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
            return new WP_Error('simplify_refund_error', $refund_data['refund_id']);
        }

        return false;
    }
}
