<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Dummy Payments Blocks integration
 *
 * @since 1.0.3
 */
final class WC_Gateway_Easymerchant_Blocks_Support extends AbstractPaymentMethodType
{

	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_Dummy
	 */
	private $gateway;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'easymerchant';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize()
	{
		$this->settings = get_option('woocommerce_dummy_settings', []);
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[$this->name];
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active()
	{
		return $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles()
	{
		$script_path       = '/assets/js/frontend/blocks.js';
		$script_asset_path = WC_Easymerchant_Payments::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
		$script_asset      = file_exists($script_asset_path)
			? require($script_asset_path)
			: array(
				'dependencies' => array(),
				'version'      => '1.2.0'
			);
		$script_url        = WC_Easymerchant_Payments::plugin_url() . $script_path;

		wp_register_script(
			'wc-dummy-payments-blocks',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		if (function_exists('wp_set_script_translations')) {
			wp_set_script_translations('wc-dummy-payments-blocks', 'woocommerce-gateway-dummy', WC_Easymerchant_Payments::plugin_abspath() . 'languages/');
		}

		return ['wc-dummy-payments-blocks'];
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
	 * Checkout payment form fields
	 * @return html checkout payment form fields
	 */
	public function payment_fields()
	{
		$curl = $this->get_curl();
		$user_id = get_current_user_id();
		$im_cus_id = get_user_meta($user_id, '_customer_id', true);

		$cards = [];
		if ($im_cus_id) {
			$params = http_build_query(array('customer' => $im_cus_id));
			curl_setopt($curl, CURLOPT_POST, false);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
			curl_setopt($curl, CURLOPT_URL, $this->api_base_url . "api/v1/card?$params");
			curl_setopt($curl, CURLOPT_HTTPHEADER, array(
				'X-Api-Key: ' . $this->api_key,
				'X-Api-Secret: ' . $this->secret_key
			));

			$resp = json_decode(curl_exec($curl));
			if ($resp && isset($resp->Cards)) {
				$count_data = count($resp->Cards);
				for ($i = 0; $i < $count_data; $i++) {
					$cards[] = $resp->Cards[$i];
				}
			}
		}

?>
		<div class="img-payment-fields">
			<?php
			$display = '';
			$display1 = '';

			// if ($this->saved_cards == 'yes' && is_user_logged_in() && !empty($im_cus_id)) {
			$display = 'style="display:none;"';
			?>
			<input type="radio" id="exist_card" name="emwc_card" value="exist">
			<label for="exist_card"><?php _e('Use saved cards', 'woocommerce-easymerchant'); ?></label>
			<input type="radio" id="new_card" name="emwc_card" value="new" checked>
			<label for="new_card"><?php _e('Use a new payment method', 'woocommerce-easymerchant'); ?></label>
			<?php
			// }

			echo '<div ' . $display . ' id="img-payment-data">';
			$this->credit_card_form(array('fields_have_names' => false));
			if ($this->saved_cards == 'yes' && is_user_logged_in()) {
				$this->save_payment_method_checkbox();
			}
			echo '</div>';

			// if ($this->saved_cards == 'yes' && is_user_logged_in() && $im_cus_id) {
			$display1 = 'style="display:none;"';

			echo '<div ' . $display1 . ' id="img-payment-data1">
                        <fieldset>
                        <p class="form-row form-row-wide">
                        <label for="-ccard-number">' . __('Card Number', 'woocommerce') . ' <span class="required">*</span></label>
                        <div id="-ccard-number" class="input-text">
                            <select name="ccard_id" style="padding: 5px;">
                            <option value="">Select your option</option>';
			foreach ($cards as $card) {
				echo '<option value="' . $card->card_id . '">' . $card->card_brand_name . ' ending in ' . $card->cc_last_4 . ' (expires ' . $card->cc_valid_thru . ')' . '</option>';
			}
			echo '</select>
                            </div>
                    </p></fieldset>
                </div>';
			// }
			?>
		</div>
<?php
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data()
	{
		return [
			'title'       => $this->get_setting('title'),
			'description' => $this->get_setting('description'),
			'supports'    => array_filter($this->gateway->supports, [$this->gateway, 'supports'])
		];
	}
}
