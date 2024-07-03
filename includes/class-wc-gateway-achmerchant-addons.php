<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Instantmerchant_Addons class.
 *
 * @extends WC_Gateway_ACHMerchant
 */
class WC_Gateway_ACHmerchant_Addons extends WC_Gateway_ACHMerchant {
    public function __construct() {
		parent::__construct();

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_achmerchant', array( $this, 'scheduled_subscription_payment' ), 10, 2 );

			
		}

		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_pre_order_release_payment' ) );
		}
	}

	/**
	 * Is $order_id a subscription?
	 * @param  int  $order_id
	 * @return boolean
	 */
	protected function is_subscription( $order_id ) {
		return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
	}

	/**
	 * Is $order_id a pre-order?
	 * @param  int  $order_id
	 * @return boolean
	 */
	protected function is_pre_order( $order_id ) {
		return ( class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Order::order_contains_pre_order( $order_id ) );
	}

	/**
	 * Process the payment based on type.
	 * @param  int $order_id
	 * @return array
	 */
	public function process_payment( $order_id, $retry = true, $force_customer = false ) {
		if ( $this->is_subscription( $order_id ) ) {
			// Regular payment with force customer enabled
			return parent::process_payment( $order_id, true, true );

		} elseif ( $this->is_pre_order( $order_id ) ) {
			return $this->process_pre_order( $order_id, $retry, $force_customer );

		} else {
			return parent::process_payment( $order_id, $retry, $force_customer );
		}
	}

	/**
	 * Updates other subscription sources.
	 */
	protected function save_source( $order, $source ) {
		parent::save_source( $order, $source );

		// Also store it on the subscriptions being purchased or paid for in the order
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order->id ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order->id );
		} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order->id ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order->id );
		} else {
			$subscriptions = array();
		}

		foreach( $subscriptions as $subscription ) {
			update_post_meta( $subscription->id, '_customer_id', $source->customer );
			update_post_meta( $subscription->id, '_card_id', $source->source );
		}
	}

	/**
	 * process_subscription_payment function.
	 * @param mixed $order
	 * @param int $amount (default: 0)
	 * @param string $stripe_token (default: '')
	 * @param  bool initial_payment
	 */
	public function process_subscription_payment( $amount = 0 ,$order = ''  ) {
		global $woocommerce;
		if ( $amount * 100 < 50 ) {
			return new WP_Error( 'stripe_error', __( 'Sorry, the minimum allowed order total is 0.50 to use this payment method.', 'woocommerce-gateway-stripe' ) );
		}

		if ( $order ) {
			$img_customer =get_post_meta( $order->id, '_customer_id', true );
			$img_source = get_post_meta( $order->id, '_card_id', true );
		}
		$url = 'http://stage-api.easymerchant-api.test/';
		$charge_card = array(
			'customer'=>$img_customer,
			'description' => sprintf( __( '%s - Order #%s', 'woocommerce' ), esc_html( get_bloginfo( 'name', 'display' ) ), $order->get_order_number() ),
			'amount'=>$amount,
			'currency'=> strtolower(get_woocommerce_currency()),
			'card_id'=>$img_source
			);

		$curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_AUTOREFERER, true);
        curl_setopt($curl, CURLOPT_VERBOSE, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_URL, $url . 'api/v1/ach/charge');
        curl_setopt($curl, CURLOPT_POST, 'true');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $charge_card);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'X-Api-Key: '.$this->get_option('api_key'),
            'X-Api-Secret: '.$this->get_option('api_secret')
        ));

        $resp = json_decode(curl_exec($curl));
        
         if($resp && $resp->status)
            {
                $order = new WC_Order( $order->id );
                update_post_meta( $order->id, '_transaction_id', $resp->charge_id, true );
                
                // create the note
                if(!$this->capture){
                     if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
                        $order->reduce_order_stock();
                    }

                    $order->update_status( 'on-hold', sprintf( __( 'InstantMerchant charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-gateway-instantmerchant' ), $resp->charge_id ) );
                    
                }else{
                  
                    $order->add_order_note( $resp->message.' Transaction ID '. $resp->charge_id);
                    $order->payment_complete();
                    $order->reduce_order_stock();
                }
                return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url( $order )
                );
            }
            else
            {
               return false;
            }
	}



}