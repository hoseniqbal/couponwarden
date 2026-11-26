<?php
/**
 * Bootstrap.
 *
 * @package CouponWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires everything together.
 */
class CouponWarden_Plugin {

	/**
	 * Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( get_option( 'couponwarden_db_version' ) !== COUPONWARDEN_DB_VERSION ) {
			CouponWarden_Log::install();
			CouponWarden_Features::install();
		}
		add_action( 'couponwarden_hourly', array( 'CouponWarden_Features', 'hourly' ) );
		add_action( 'couponwarden_daily', array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		CouponWarden_Features::init();
		if ( is_admin() ) {
			CouponWarden_Admin::init();
		}
	}

	/**
	 * Make sure the jobs are scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'couponwarden_hourly' ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'couponwarden_hourly' );
		}
		if ( ! wp_next_scheduled( 'couponwarden_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'couponwarden_daily' );
		}
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public static function daily() {
		CouponWarden_Log::purge();
		CouponWarden_Features::daily();
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		CouponWarden_Log::install();
		if ( class_exists( 'WooCommerce' ) ) {
			CouponWarden_Features::install();
			CouponWarden_Features::activate();
		}
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'couponwarden_hourly' );
		wp_clear_scheduled_hook( 'couponwarden_daily' );
	}
}
