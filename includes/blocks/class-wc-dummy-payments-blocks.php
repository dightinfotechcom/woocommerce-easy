<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * lyfePAY Payments Blocks integration
 *
 * @since 1.0.3
 */
final class WC_Gateway_Easymerchant_Blocks_Support extends AbstractPaymentMethodType
{

	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_lyfePAY
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
		// $this->settings = get_option('woocommerce_dummy_settings', []);
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
		$script_asset_path = WC_lyfePAY_Payments::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
		$script_asset      = file_exists($script_asset_path)
			? require($script_asset_path)
			: array(
				'dependencies' => array(),
				'version'      => '1.2.0'
			);
		$script_url        = WC_lyfePAY_Payments::plugin_url() . $script_path;

		wp_register_script(
			'wc-lyfePAY-payments-blocks',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		if (function_exists('wp_set_script_translations')) {
			wp_set_script_translations('wc-lyfePAY-payments-blocks', 'woocommerce-gateway-lyfePAY', WC_lyfePAY_Payments::plugin_abspath() . 'languages/');
		}

		return ['wc-lyfePAY-payments-blocks'];
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
