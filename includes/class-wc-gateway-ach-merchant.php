<?php

/**
 * WC_Gateway_ACH_Easymerchant class
 *
 * @author   Easymerchant <info@easymerchant.io>
 * @package  WooCommerce ACH Easymerchant Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Easymerchant Gateway.
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
        $this->method_title = 'ACH EasyMerchant';
        $this->title = 'ACH EasyMerchant';
        $this->method_description = 'ACH EasyMerchant Gateway Options';
        $this->supports = array(
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
                'default'     => __('Bank Payment', 'woocommerce-easymerchant'),
                'desc_tip'    => true
            ),
            'description' => array(
                'title'       => __('Description', 'woocommerce-easymerchant'),
                'type'        => 'text',
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-easymerchant'),
                'default'     => 'Pay your bill via ACH Merchant.',
                'desc_tip'    => true
            ),
            'api_key' => array(
                'title' => __('API Key', 'woocommerce-easymerchant'),
                'description' => __('Get your API key from AchMerchant.', 'woocommerce-easymerchant'),
                'type' => 'text',
                'default' => '',
                'desc_tip' => true,
            ),
            'api_secret' => array(
                'title' => __('API Secret', 'woocommerce-easymerchant'),
                'description' => __('Get your API secret from AchMerchant.', 'woocommerce-easymerchant'),
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
            'test_secret_key' => array(
                'title'       => __('Test Secret Key', 'woocommerce-easymerchant'),
                'type'        => 'text',
                'description' => __('Get your API keys from your AchMerchant account.', 'woocommerce-easymerchant'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'test_api_key' => array(
                'title'       => __('Test API Key', 'woocommerce-easymerchant'),
                'type'        => 'text',
                'description' => __('Get your API keys from your AchMerchant account.', 'woocommerce-easymerchant'),
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
        $im_cus_id = get_user_meta($user_id, '_customer_id', true);
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
        // Retrieve ACH details from Session
        $getAch = $_SESSION['achDetails'];

        $account_number = $getAch['ach-account-number'] ?? '';
        $routing_number = $getAch['ach-routing-number'] ?? '';
        $account_type = $getAch['ach-account-type'] ?? '';

        $card_details = json_encode([
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
                'account_type'      => 'saving',
            ]);
            $achCharge = wp_remote_post($this->api_base_url . 'api/v1/ach/charge', array(
                'method'    => 'POST',
                'headers'   => array(
                    'X-Api-Key'      => $this->api_key,
                    'X-Api-Secret'   => $this->secret_key,
                    'Content-Type'   => 'application/json',
                ),
                'body'               => $body,
            ));
            $createAchCharge     = wp_remote_retrieve_body($achCharge);
            $achchargeResponse = json_decode($createAchCharge, true);
        } else {
            //existing customer and existing card
            if ($_POST['achaccount'] != '' && $im_cus_id) {
                $customer = $im_cus_id;
                $card_id = $_POST['achaccount'];
                $source = (object) array(
                    'customer' => $customer,
                    'source'   => $card_id
                );
                // Store source to order meta.
                $this->save_source($order, $source);
            } else if ($im_cus_id) {
                //existing customer and save new card

                curl_setopt($curl, CURLOPT_URL, $this->api_base_url . 'api/v1/ach/charge');

                $body = json_encode([
                    'account_number'     => $account_number,
                    'routing_number'    => $routing_number,
                    'customer'            => $response_data['customer_id'],
                ]);
                $post['customer'] = $im_cus_id;
                $card = wp_remote_post($this->api_base_url . 'api/v1/card', array(
                    'method'    => 'POST',
                    'headers'   => array(
                        'X-Api-Key'     => $this->api_key,
                        'X-Api-Secret'  => $this->secret_key,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'                => $body,
                ));
                $createCard = wp_remote_retrieve_body($card);
                $cardResponse = json_decode($createCard, true);


                if ($resp && $resp->status) {
                    $source = (object) array(
                        'customer' => $customer_id,
                        'source'   => $resp->card_id
                    );
                    // Store source to order meta.
                    $this->save_source($order, $source);
                } else {
                    wc_add_notice(__('Payment error:', 'woothemes') . ' ' . $resp->message, 'error');
                    return false;
                }
            } else {
                //new customer save customer and save card
                curl_setopt($curl, CURLOPT_URL, $this->api_base_url . 'api/v1/customer');
                curl_setopt($curl, CURLOPT_POST, 'true');
                curl_setopt($curl, CURLOPT_POSTFIELDS, $customer_details);

                $resp = json_decode(curl_exec($curl));

                if ($resp && $resp->status) {
                    $customer_id = $resp->customer_id;
                    $card_details['customer'] = $customer_id;
                    curl_setopt($curl, CURLOPT_URL, $this->api_base_url . 'api/v1/ach/charge');
                    curl_setopt($curl, CURLOPT_POST, 'true');
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $card_details);

                    $resp = json_decode(curl_exec($curl));

                    if ($resp && $resp->status) {
                        $source = (object) array(
                            'customer' => $customer_id,
                            'source'   => $resp->card_id
                        );
                        // Store source to order meta.
                        $this->save_source($order, $source);
                    } else {
                        wc_add_notice(__('Payment error:', 'woothemes') . ' ' . $resp->message, 'error');
                        return false;
                    }
                } else {
                    wc_add_notice(__('Payment error:', 'woothemes') . ' ' . $resp->message, 'error');
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
}
