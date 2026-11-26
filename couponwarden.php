<?php
/**
 * Plugin Name:       CouponWarden: New Customer and One Per Person Coupons for WooCommerce
 * Plugin URI:        https://github.com/hoseniqbal/couponwarden
 * Description:       Makes welcome coupons work for new customers only and once per person, guests included, by matching email, phone and address. Warns you when a coupon leaks.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Iqbal Hossen
 * Author URI:        https://profiles.wordpress.org/iqbal511138/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       couponwarden
 * WC requires at least: 7.6
 * WC tested up to:   11.3
 *
 * @package CouponWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COUPONWARDEN_VERSION', '1.0.0' );
define( 'COUPONWARDEN_DB_VERSION', '1' );
define( 'COUPONWARDEN_FILE', __FILE__ );
define( 'COUPONWARDEN_PATH', plugin_dir_path( __FILE__ ) );
define( 'COUPONWARDEN_URL', plugin_dir_url( __FILE__ ) );

require_once COUPONWARDEN_PATH . 'includes/class-couponwarden-settings.php';
require_once COUPONWARDEN_PATH . 'includes/class-couponwarden-log.php';
require_once COUPONWARDEN_PATH . 'includes/class-couponwarden-features.php';
require_once COUPONWARDEN_PATH . 'includes/class-couponwarden-admin.php';
require_once COUPONWARDEN_PATH . 'includes/class-couponwarden-plugin.php';

register_activation_hook( __FILE__, array( 'CouponWarden_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CouponWarden_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'CouponWarden_Plugin', 'init' ) );
