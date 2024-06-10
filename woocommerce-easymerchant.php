<?php

/**
 * Plugin Name: WooCommerce Easymerchant
 * Plugin URI: https://easymerchant.io/
 * Description: Adds the Easymerchant gateway to your WooCommerce website.
 * Version: 1.0.9
 *
 * Author: Easymerchant
 * Author URI: https://easymerchant.io/
 *
 * Text Domain: woocommerce-easymerchant
 * Domain Path: /i18n/languages/
 *
 * Requires at least: 4.2
 * Tested up to: 4.9
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * function to check if woocommerce is installed and activated
 * @return string Returns error message if woocommerce not installed and activated
 */

function img_dependency_error_woo()
{
?>
	<div class="notice notice-error is-dismissible">
		<p>
			<?php _e('Easy Merchant requires Woocommerce plugin installed and activated!', 'woocommerce-easymerchant'); ?>
		</p>
	</div>
<?php
}

/**
 * function to check if php curl is installed and enabled
 * @return string Returns error message if php curl not installed and enabled
 */
function img_dependency_error_curl()
{
?>
	<div class="notice notice-error is-dismissible">
		<p>
			<?php _e('Easy Merchant requires PHP CURL installed on this server!', 'woocommerce-easymerchant'); ?>
		</p>
	</div>
<?php
}

function do_ssl_check()
{
	if ('yes' != $this->stripe_sandbox && "no" == get_option('woocommerce_force_ssl_checkout') && "yes" == $this->enabled) {
		echo "<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>";
	}
}
/**
 * WC Easymerchant gateway plugin class.
 *
 * @class WC_Easymerchant_Payments
 */
class WC_Dummy_Payments
{

	/**
	 * @var Singleton The reference the *Singleton* instance of this class
	 */
	private static $instance;

	/**
	 * @var Reference to logging class.
	 */
	private static $log;

	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @return Singleton The *Singleton* instance.
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private clone method to prevent cloning of the instance of the
	 * *Singleton* instance.
	 *
	 * @return void
	 */
	private function __clone()
	{
	}

	/**
	 * Private unserialize method to prevent unserializing of the *Singleton*
	 * instance.
	 *
	 * @return void
	 */
	public function __wakeup()
	{
	}

	/**
	 * Flag to indicate whether or not we need to load code for / support subscriptions.
	 *
	 * @var bool
	 */
	private $subscription_support_enabled = false;

	/**
	 * Flag to indicate whether or not we need to load support for pre-orders.
	 *
	 * @since 3.0.3
	 *
	 * @var bool
	 */
	private $pre_order_enabled = false;

	/**
	 * Notices (array)
	 * @var array
	 */
	public $notices = array();

	/**
	 * Protected constructor to prevent creating a new instance of the
	 * Singleton* via the `new` operator from outside of this class.
	 */
	protected function __construct()
	{
		add_action('admin_notices', array($this, 'admin_notices'), 15);
		add_action('plugins_loaded', array($this, 'init_easy_merchant'));
		add_action('rest_api_init', function () {
			register_rest_route('easymerchant/v1', '/cards', array(
				'methods' => 'GET',
				'callback' => 'fetch_user_cards',
			));
		});
		// Register custom REST API endpoint to get current user ID and customer ID
		add_action('rest_api_init', function () {
			register_rest_route('wooeasy/wp-json/wp/v2', '/users', array(
				'methods' => 'GET',
				'callback' => 'get_user_meta_data',
				'permission_callback' => function () {
					return is_user_logged_in(); // Ensure the user is authenticated
				}
			));
		});
	}

	function init_easy_merchant()
	{
		/**
		 * Check if WooCommerce is active
		 **/
		if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
			add_action('admin_notices', 'img_dependency_error_woo');
			return;
		}
		/**
		 * Check if CURL is enabled
		 **/
		if (!function_exists('curl_version')) {
			add_action('admin_notices', 'img_dependency_error_curl');
			return;
		}
		// Init the gateway itself
		$this->init_gateways();
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'img_woocommerce_addon_settings_link'));
		add_action('woocommerce_order_status_on-hold_to_processing', array($this, 'capture_payment'));
		add_action('woocommerce_order_status_on-hold_to_completed', array($this, 'capture_payment'));
	}
	/**
	 * Allow this class and other classes to add slug keyed notices (to avoid duplication)
	 */
	public function add_admin_notice($slug, $class, $message)
	{
		$this->notices[$slug] = array(
			'class' => $class,
			'message' => $message
		);
	}
	/**
	 * Display any notices we've collected thus far (e.g. for connection, disconnection)
	 */
	public function admin_notices()
	{
		foreach ((array) $this->notices as $notice_key => $notice) {
			echo "<div class='" . esc_attr($notice['class']) . "'><p>";
			echo wp_kses($notice['message'], array('a' => array('href' => array())));
			echo "</p></div>";
		}
	}
	public function init_gateways()
	{
		if (class_exists('WC_Subscriptions_Order') && function_exists('wcs_create_renewal_order')) {
			$this->subscription_support_enabled = true;
		}

		if (class_exists('WC_Pre_Orders_Order')) {
			$this->pre_order_enabled = true;
		}

		if (class_exists('WC_Payment_Gateway')) {
			include_once('includes/class-wc-gateway-dummy.php');
		}
		add_filter('woocommerce_payment_gateways', array($this, 'add_gateways'));

		$load_addons = ($this->subscription_support_enabled
			||
			$this->pre_order_enabled
		);

		if ($load_addons) {
			require_once 'includes/class-wc-gateway-instantmerchant-addons.php';
		}
	}
	/**
	 * Plugin bootstrapping.
	 */
	public static function init()
	{
		//Easymerchant gateway class.
		add_action('plugins_loaded', array(__CLASS__, 'includes'), 0);
		// Make theEasymerchant gateway available to WC.
		add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));
		// Registers WooCommerce Blocks integration.
		add_action('woocommerce_blocks_loaded', array(__CLASS__, 'woocommerce_gateway_easymerchant_woocommerce_block_support'));
	}

	/**
	 * Add the Easymerchant gateway to the list of available gateways.
	 *
	 * @param array
	 */
	public static function add_gateway($gateways)
	{
		$options = get_option('woocommerce_dummy_settings', array());
		if (isset($options['hide_for_non_admin_users'])) {
			$hide_for_non_admin_users = $options['hide_for_non_admin_users'];
		} else {
			$hide_for_non_admin_users = 'no';
		}

		if (('yes' === $hide_for_non_admin_users && current_user_can('manage_options')) || 'no' === $hide_for_non_admin_users) {
			$gateways[] = 'WC_Gateway_Dummy';
		}
		return $gateways;
	}
	/**
	 * Add the gateways to WooCommerce
	 * @since 1.0.0
	 */
	public function add_gateways($methods)
	{
		if ($this->subscription_support_enabled || $this->pre_order_enabled) {
			$methods[] = 'WC_Gateway_Easymerchant_Addons';
		} else {
			$methods[] = 'WC_Gateway_Dummy';
		}
		return $methods;
	}
	public function get_curl2()
	{
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($curl, CURLOPT_AUTOREFERER, true);
		curl_setopt($curl, CURLOPT_VERBOSE, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		return $curl;
	}

	/**
	 * Capture payment when the order is changed from on-hold to complete or processing
	 *
	 * @param  int $order_id
	 */
	public function capture_payment($order_id)
	{
		$order = wc_get_order($order_id);
		if ('easymerchant' === $order->payment_method) {
			$charge = get_post_meta($order_id, '_transaction_id', true);
			if ($charge) {
				$curl = $this->get_curl2();
				$url = 'http://stage-api.easymerchant-api.test/';
				$options = get_option('woocommerce_easymerchant_settings');
				curl_setopt($curl, CURLOPT_URL, $url . 'api/v1/capture');
				curl_setopt($curl, CURLOPT_POST, 'true');
				curl_setopt($curl, CURLOPT_POSTFIELDS, array('charge_id' => $charge));
				curl_setopt(
					$curl,
					CURLOPT_HTTPHEADER,
					array(
						'X-Api-Key: ' . $options['api_key'],
						'X-Api-Secret: ' . $options['api_secret']
					)
				);
				$resp = json_decode(curl_exec($curl));
				if (is_wp_error($resp)) {
					$order->add_order_note(__('Unable to capture charge!', 'woocommerce-easymerchant') . ' ' . $resp->get_error_message());
				} else {
					$order->add_order_note(sprintf(__('EasyMerchant charge complete (Charge ID: %s)', 'woocommerce-easymerchant'), $resp->charge_id));
					// Store other data such as fees
					update_post_meta($order->id, '_transaction_id', $resp->charge_id);
				}
			}
		}
	}

	/*Plugin Settings Link*/
	function img_woocommerce_addon_settings_link($links)
	{
		$settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=easymerchant">' . __('Easymerchant Settings', 'woocommerce-easymerchant') . '</a>';
		array_push($links, $settings_link);
		return $links;
	}

	/**
	 * Plugin includes.
	 */
	public static function includes()
	{
		// Make the WC_Gateway_Easymerchant class available.
		if (class_exists('WC_Payment_Gateway')) {
			require_once 'includes/class-wc-gateway-dummy.php';
		}
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url()
	{
		return untrailingslashit(plugins_url('/', __FILE__));
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_abspath()
	{
		return trailingslashit(plugin_dir_path(__FILE__));
	}

	/**
	 * Callback function to get user ID and customer ID
	 */
	function get_user_meta_data()
	{
		$user_id = get_current_user_id(); // Get the current user ID
		if (!$user_id) {
			return new WP_Error('no_user', __('No user is logged in', 'text-domain'), array('status' => 401));
		}
		$customer_id = get_user_meta($user_id, '_customer_id', true); // Get the customer ID from user meta
		// Return the data as a JSON response
		return rest_ensure_response(array(
			'user_id' => $user_id,
			'customer_id' => $customer_id,
		));
	}

	function fetch_user_cards()
	{
		$user_id = get_current_user_id();
		$im_cus_id = get_user_meta($user_id, '_customer_id', true);
		$mode = get_option('test_mode');
		if ($mode) {
			$api_base_url = "https://stage-api.stage-easymerchant.io";
			$api_key = get_option('test_api_key');
			$secret_key = get_option('test_secret_key');
		} else {
			$api_base_url = "https://api.easymerchant.io";
			$api_key = get_option('api_key');
			$secret_key = get_option('api_secret');
		}

		$cards = [];

		if ($im_cus_id) {
			$curl = curl_init();
			$params = http_build_query(array('customer' => $im_cus_id));
			curl_setopt($curl, CURLOPT_POST, false);
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
			curl_setopt($curl, CURLOPT_URL, $api_base_url . "api/v1/card?$params");
			curl_setopt($curl, CURLOPT_HTTPHEADER, array(
				'X-Api-Key: ' . $api_key,
				'X-Api-Secret: ' . $secret_key
			));

			$response = curl_exec($curl);
			if (curl_errno($curl)) {
				$error_msg = curl_error($curl);
				curl_close($curl);
				return new WP_Error('curl_error', __('Error fetching cards: ' . $error_msg, 'your-text-domain'));
			}
			curl_close($curl);

			$resp = json_decode($response, true);
			if ($resp && isset($resp['Cards'])) {
				$cards = $resp['Cards'];
			}
		}

		return rest_ensure_response(['cards' => $cards]);
	}

	/**
	 * Registers WooCommerce Blocks integration.
	 */
	public static function woocommerce_gateway_easymerchant_woocommerce_block_support()
	{
		if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
			require_once 'includes/blocks/class-wc-dummy-payments-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
					$payment_method_registry->register(new WC_Gateway_Dummy_Blocks_Support());
				}
			);
		}
	}
}

WC_Dummy_Payments::init();
