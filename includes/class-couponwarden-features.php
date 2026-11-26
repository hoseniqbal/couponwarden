<?php
/**
 * CouponWarden: real "new customers only" and "once per person" coupons, and a leak watch.
 *
 * @package CouponWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Features and screens.
 */
class CouponWarden_Features {

	const NEW_ONLY = '_couponwarden_new_only';
	const ONCE     = '_couponwarden_once';
	const SEEN     = '_couponwarden_seen';

	/* ------------------------------------------------------------------
	 * Frame contract
	 * ---------------------------------------------------------------- */

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'overview' => __( 'Overview', 'couponwarden' ),
			'coupons'  => __( 'Coupons', 'couponwarden' ),
			'settings' => __( 'Settings', 'couponwarden' ),
		);
	}

	/**
	 * Tagline.
	 *
	 * @return string
	 */
	public static function tagline() {
		return __( 'Your welcome discount goes to new customers, once.', 'couponwarden' );
	}

	/**
	 * Sections.
	 *
	 * @return array
	 */
	public static function sections() {
		return array( 'settings' => array( 'match_email', 'match_phone', 'match_address', 'msg_new', 'msg_once', 'leak_watch', 'leak_min', 'notify_to', 'retention_days', 'delete_data' ) );
	}

	/**
	 * Messages.
	 *
	 * @return array
	 */
	public static function messages() {
		return array();
	}

	/**
	 * Pro intro.
	 *
	 * @return string
	 */
	public static function pro_intro() {
		return __( 'Stop a leaked code by itself, make single-use codes in bulk and spot people who use a coupon and then ask for a refund.', 'couponwarden' );
	}

	/**
	 * Pro points.
	 *
	 * @return array
	 */
	public static function pro_points() {
		return array(
			__( 'Turn off a leaked coupon automatically', 'couponwarden' ),
			__( 'Create hundreds of single-use codes at once, with CSV', 'couponwarden' ),
			__( 'Coupon then refund report', 'couponwarden' ),
			__( 'Alerts on Slack, Discord, Telegram or any webhook', 'couponwarden' ),
		);
	}

	/**
	 * CSS on the coupon screen too.
	 *
	 * @param string $hook Hook.
	 * @return bool
	 */
	public static function needs_css( $hook ) {
		unset( $hook );
		return false;
	}

	/* ------------------------------------------------------------------
	 * Data
	 * ---------------------------------------------------------------- */

	/**
	 * Customers table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'couponwarden_ids';
	}

	/**
	 * Install the customers table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email_hash char(64) NOT NULL DEFAULT '',
			phone_hash char(64) NOT NULL DEFAULT '',
			addr_hash char(64) NOT NULL DEFAULT '',
			coupons varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY email_hash (email_hash),
			KEY phone_hash (phone_hash),
			KEY addr_hash (addr_hash)
		) {$charset};"
		);
	}

	/**
	 * Activation: remember past customers.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( function_exists( 'as_enqueue_async_action' ) && ! get_option( 'couponwarden_backfilled' ) ) {
			as_enqueue_async_action( 'couponwarden_backfill', array( 1 ), 'couponwarden' );
		}
	}

	/**
	 * Same person, different spelling: Gmail dots and +tags removed.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function norm_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return '';
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$plus   = strpos( $local, '+' );
		if ( false !== $plus ) {
			$local = substr( $local, 0, $plus );
		}
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return '' === $local ? '' : $local . '@' . $domain;
	}

	/**
	 * Phone: the last 10 digits.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	public static function norm_phone( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		return strlen( $digits ) >= 6 ? substr( $digits, -10 ) : '';
	}

	/**
	 * Address: street line and postcode without spaces or punctuation.
	 *
	 * @param string $line     Street line.
	 * @param string $postcode Postcode.
	 * @param string $country  Country.
	 * @return string
	 */
	public static function norm_address( $line, $postcode, $country ) {
		$line = preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $line ) ) );
		if ( strlen( $line ) < 4 ) {
			return '';
		}
		return strtoupper( (string) $country ) . '|' . preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $postcode ) ) . '|' . $line;
	}

	/**
	 * Keyed hash.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function hash( $value ) {
		return '' === $value ? '' : hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	/**
	 * Identity hashes for a set of details.
	 *
	 * @param string $email    Email.
	 * @param string $phone    Phone.
	 * @param string $line     Address line.
	 * @param string $postcode Postcode.
	 * @param string $country  Country.
	 * @return array
	 */
	public static function identity( $email, $phone, $line, $postcode, $country ) {
		return array(
			'email' => self::hash( self::norm_email( $email ) ),
			'phone' => self::hash( self::norm_phone( $phone ) ),
			'addr'  => self::hash( self::norm_address( $line, $postcode, $country ) ),
		);
	}

	/**
	 * Identity of an order (shipping address when there is one).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order_identity( $order ) {
		$line = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$post = $order->get_shipping_address_1() ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$ctry = $order->get_shipping_address_1() ? $order->get_shipping_country() : $order->get_billing_country();
		return self::identity( $order->get_billing_email(), $order->get_billing_phone(), $line, $post, $ctry );
	}

	/**
	 * Earlier orders that match this person, optionally only those that used a coupon.
	 *
	 * @param array  $id      Identity.
	 * @param string $coupon  Coupon code, or '' for any order.
	 * @param int    $exclude Order ID to ignore.
	 * @return int
	 */
	public static function matches( $id, $coupon = '', $exclude = 0 ) {
		global $wpdb;
		$or   = array();
		$args = array( self::table() );
		foreach ( array( 'email' => 'match_email', 'phone' => 'match_phone', 'addr' => 'match_address' ) as $key => $setting ) {
			if ( '' !== $id[ $key ] && CouponWarden_Settings::on( $setting ) ) {
				$or[]   = $key . '_hash = %s';
				$args[] = $id[ $key ];
			}
		}
		if ( ! $or ) {
			return 0;
		}
		$sql = 'SELECT COUNT(*) FROM %i WHERE (' . implode( ' OR ', $or ) . ') AND order_id <> %d';
		$args[] = (int) $exclude;
		if ( '' !== $coupon ) {
			$sql   .= ' AND CONCAT(",", coupons, ",") LIKE %s';
			$args[] = '%,' . $wpdb->esc_like( wc_format_coupon_code( $coupon ) ) . ',%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Remember a paid order.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $count Also count coupon use.
	 * @return void
	 */
	public static function remember( $order, $count = true ) {
		if ( ! $order instanceof WC_Order || $order->get_meta( self::SEEN ) ) {
			return;
		}
		global $wpdb;
		$id      = self::order_identity( $order );
		$coupons = array_map( 'wc_format_coupon_code', $order->get_coupon_codes() );
		$created = $order->get_date_created();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, write.
		$wpdb->replace(
			self::table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s', $created ? $created->getTimestamp() : time() ),
				'order_id'   => $order->get_id(),
				'email_hash' => $id['email'],
				'phone_hash' => $id['phone'],
				'addr_hash'  => $id['addr'],
				'coupons'    => substr( implode( ',', $coupons ), 0, 255 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		$order->update_meta_data( self::SEEN, 1 );
		$order->save_meta_data();
		if ( $count ) {
			foreach ( $coupons as $code ) {
				CouponWarden_Log::add(
					'used',
					array(
						'object_id' => $order->get_id(),
						'user_id'   => 0,
						'detail'    => $code,
					)
				);
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------- */

	/**
	 * Runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 20, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic_check' ), 30, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'store_api_check' ), 30, 2 );
		add_action( 'couponwarden_backfill', array( __CLASS__, 'backfill' ) );
	}

	/**
	 * Admin hooks.
	 *
	 * @return void
	 */
	public static function admin_hooks() {
		add_action( 'woocommerce_coupon_options_usage_restriction', array( __CLASS__, 'coupon_fields' ), 20, 2 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'coupon_save' ), 20, 2 );
		add_filter( 'manage_edit-shop_coupon_columns', array( __CLASS__, 'coupon_column' ) );
		add_action( 'manage_shop_coupon_posts_custom_column', array( __CLASS__, 'coupon_column_value' ), 10, 2 );
	}

	/**
	 * Paid orders become past customers.
	 *
	 * @param int      $id    Order ID.
	 * @param string   $from  Old status.
	 * @param string   $to    New status.
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function on_status( $id, $from, $to, $order ) {
		unset( $id, $from );
		if ( in_array( $to, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			self::remember( $order );
		}
	}

	/**
	 * Import past orders, 200 per run.
	 *
	 * @param int $page Page.
	 * @return void
	 */
	public static function backfill( $page = 1 ) {
		$page   = max( 1, (int) $page );
		$orders = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'status'  => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
				'limit'   => 200,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		foreach ( $orders as $order ) {
			self::remember( $order, false );
		}
		if ( 200 === count( $orders ) && $page < 250 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'couponwarden_backfill', array( $page + 1 ), 'couponwarden' );
		} else {
			update_option( 'couponwarden_backfilled', time(), false );
		}
	}

	/**
	 * Coupon rules.
	 *
	 * @param string $code Coupon code.
	 * @return array new_only, once (bools)
	 */
	public static function rules( $code ) {
		$coupon = new WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			return array(
				'new_only' => false,
				'once'     => false,
			);
		}
		return array(
			'new_only' => 'yes' === $coupon->get_meta( self::NEW_ONLY ),
			'once'     => 'yes' === $coupon->get_meta( self::ONCE ),
		);
	}

	/**
	 * Decide for one checkout. Returns '' or an error message.
	 *
	 * @param array    $id       Identity.
	 * @param string[] $coupons  Codes.
	 * @param int      $user_id  Customer user ID.
	 * @param int      $exclude  Order ID being placed.
	 * @param float[]  $amounts  Code => discount.
	 * @return string
	 */
	public static function decide( $id, $coupons, $user_id, $exclude, $amounts = array() ) {
		foreach ( $coupons as $code ) {
			$code  = wc_format_coupon_code( $code );
			$rules = self::rules( $code );
			$why   = '';
			if ( $rules['new_only'] && ( self::matches( $id, '', $exclude ) > 0 || ( $user_id && self::user_has_paid( $user_id, $exclude ) ) ) ) {
				$why = 'new_only';
			} elseif ( $rules['once'] && self::matches( $id, $code, $exclude ) > 0 ) {
				$why = 'once';
			}
			if ( '' !== $why ) {
				$seen = 'couponwarden_try_' . md5( $code . '|' . implode( '|', $id ) );
				if ( ! get_transient( $seen ) ) {
					set_transient( $seen, 1, 10 * MINUTE_IN_SECONDS );
					CouponWarden_Log::add(
						'blocked',
						array(
							'object_id' => 0,
							'user_id'   => $user_id,
							'field'     => $why,
							'amount'    => isset( $amounts[ $code ] ) ? (float) $amounts[ $code ] : 0,
							'detail'    => $code,
						)
					);
				}
				$msg = (string) CouponWarden_Settings::get( 'new_only' === $why ? 'msg_new' : 'msg_once' );
				return str_replace( '%s', strtoupper( $code ), $msg );
			}
		}
		return '';
	}

	/**
	 * Does a logged-in customer have a paid order (other than this one)?
	 *
	 * @param int $user_id User.
	 * @param int $exclude Order ID.
	 * @return bool
	 */
	private static function user_has_paid( $user_id, $exclude ) {
		$ids = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
				'limit'       => 2,
				'return'      => 'ids',
			)
		);
		return (bool) array_diff( $ids, array( (int) $exclude ) );
	}

	/**
	 * Classic checkout.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 * @return void
	 */
	public static function classic_check( $data, $errors ) {
		if ( ! WC()->cart || ! $errors instanceof WP_Error ) {
			return;
		}
		$coupons = WC()->cart->get_applied_coupons();
		if ( ! $coupons ) {
			return;
		}
		$ship    = ! empty( $data['ship_to_different_address'] ) && ! empty( $data['shipping_address_1'] );
		$id      = self::identity(
			isset( $data['billing_email'] ) ? $data['billing_email'] : '',
			isset( $data['billing_phone'] ) ? $data['billing_phone'] : '',
			$ship ? $data['shipping_address_1'] : ( isset( $data['billing_address_1'] ) ? $data['billing_address_1'] : '' ),
			$ship ? ( isset( $data['shipping_postcode'] ) ? $data['shipping_postcode'] : '' ) : ( isset( $data['billing_postcode'] ) ? $data['billing_postcode'] : '' ),
			$ship ? ( isset( $data['shipping_country'] ) ? $data['shipping_country'] : '' ) : ( isset( $data['billing_country'] ) ? $data['billing_country'] : '' )
		);
		$amounts = array();
		foreach ( $coupons as $code ) {
			$amounts[ $code ] = WC()->cart->get_coupon_discount_amount( $code, false );
		}
		$msg = self::decide( $id, $coupons, get_current_user_id(), 0, $amounts );
		if ( '' !== $msg ) {
			$errors->add( 'couponwarden', $msg );
		}
	}

	/**
	 * Block checkout.
	 *
	 * @param WC_Order        $order   Order being placed.
	 * @param WP_REST_Request $request Request.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the coupon is not allowed.
	 */
	public static function store_api_check( $order, $request ) {
		unset( $request );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$coupons = $order->get_coupon_codes();
		if ( ! $coupons ) {
			return;
		}
		$amounts = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$amounts[ wc_format_coupon_code( $item->get_code() ) ] = (float) $item->get_discount();
		}
		$msg = self::decide( self::order_identity( $order ), $coupons, (int) $order->get_customer_id(), $order->get_id(), $amounts );
		if ( '' !== $msg ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'couponwarden_coupon', esc_html( $msg ), 400 );
		}
	}

	/* ------------------------------------------------------------------
	 * Coupon screen
	 * ---------------------------------------------------------------- */

	/**
	 * Fields on the coupon's Usage restriction tab.
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon    Coupon.
	 * @return void
	 */
	public static function coupon_fields( $coupon_id, $coupon ) {
		unset( $coupon_id );
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => 'couponwarden_new_only',
				'label'       => __( 'New customers only', 'couponwarden' ),
				'description' => __( 'CouponWarden: refuse people who already ordered, matched by email (Gmail dots and +tags too), phone and address, guests included.', 'couponwarden' ),
				'value'       => $coupon->get_meta( self::NEW_ONLY ) ? $coupon->get_meta( self::NEW_ONLY ) : 'no',
				'cbvalue'     => 'yes',
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => 'couponwarden_once',
				'label'       => __( 'Once per person', 'couponwarden' ),
				'description' => __( 'CouponWarden: each person can use this coupon one time, even with a new account or as a guest.', 'couponwarden' ),
				'value'       => $coupon->get_meta( self::ONCE ) ? $coupon->get_meta( self::ONCE ) : 'no',
				'cbvalue'     => 'yes',
			)
		);
		echo '</div>';
	}

	/**
	 * Save the fields. WooCommerce checks the nonce and capability before this action.
	 *
	 * @param int       $post_id Coupon ID.
	 * @param WC_Coupon $coupon  Coupon.
	 * @return void
	 */
	public static function coupon_save( $post_id, $coupon ) {
		unset( $post_id );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the coupon form nonce.
		$coupon->update_meta_data( self::NEW_ONLY, isset( $_POST['couponwarden_new_only'] ) ? 'yes' : 'no' );
		$coupon->update_meta_data( self::ONCE, isset( $_POST['couponwarden_once'] ) ? 'yes' : 'no' );
		// phpcs:enable
		$coupon->save_meta_data();
	}

	/**
	 * Coupons list column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function coupon_column( $columns ) {
		$columns['couponwarden'] = __( 'Protection', 'couponwarden' );
		return $columns;
	}

	/**
	 * Column value.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Coupon ID.
	 * @return void
	 */
	public static function coupon_column_value( $column, $post_id ) {
		if ( 'couponwarden' !== $column ) {
			return;
		}
		$out = array();
		if ( 'yes' === get_post_meta( $post_id, self::NEW_ONLY, true ) ) {
			$out[] = __( 'New customers', 'couponwarden' );
		}
		if ( 'yes' === get_post_meta( $post_id, self::ONCE, true ) ) {
			$out[] = __( 'Once per person', 'couponwarden' );
		}
		echo esc_html( $out ? implode( ', ', $out ) : '' );
	}

	/* ------------------------------------------------------------------
	 * Leak watch
	 * ---------------------------------------------------------------- */

	/**
	 * Uses per coupon in a window.
	 *
	 * @param int $from Seconds ago (start).
	 * @param int $to   Seconds ago (end).
	 * @return array code => uses
	 */
	public static function uses( $from, $to = 0 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, report.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT detail, COUNT(*) AS n FROM %i WHERE type = %s AND created_at >= %s AND created_at < %s GROUP BY detail', CouponWarden_Log::table(), 'used', gmdate( 'Y-m-d H:i:s', time() - $from ), gmdate( 'Y-m-d H:i:s', time() - $to ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['detail'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Hourly: is any coupon suddenly used far more than usual?
	 *
	 * @return void
	 */
	public static function hourly() {
		if ( ! CouponWarden_Settings::on( 'leak_watch' ) ) {
			return;
		}
		$today  = self::uses( DAY_IN_SECONDS );
		$before = self::uses( 15 * DAY_IN_SECONDS, DAY_IN_SECONDS );
		$warned = get_option( 'couponwarden_leaks', array() );
		$warned = is_array( $warned ) ? $warned : array();
		foreach ( $today as $code => $n ) {
			$usual = isset( $before[ $code ] ) ? $before[ $code ] / 14 : 0;
			if ( $n < (int) CouponWarden_Settings::get( 'leak_min' ) || $n < 5 * max( 1, $usual ) ) {
				continue;
			}
			if ( isset( $warned[ $code ] ) && $warned[ $code ] > time() - DAY_IN_SECONDS ) {
				continue;
			}
			$warned[ $code ] = time();
			CouponWarden_Log::add(
				'leak',
				array(
					'user_id' => 0,
					'detail'  => $code,
					'amount'  => $n,
				)
			);
			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			self::mail(
				/* translators: 1: code, 2: site */
				sprintf( __( 'Coupon %1$s may have leaked on %2$s', 'couponwarden' ), strtoupper( $code ), $site ),
				sprintf(
					/* translators: 1: code, 2: uses, 3: usual */
					__( 'The coupon %1$s was used %2$s times in the last 24 hours. Normally it is used about %3$s times a day. It may have been posted on a coupon website. Consider turning it off or limiting it.', 'couponwarden' ),
					strtoupper( $code ),
					number_format_i18n( $n ),
					number_format_i18n( $usual, 1 )
				) . "\n\n" . admin_url( 'edit.php?post_type=shop_coupon' )
			);
		}
		update_option( 'couponwarden_leaks', $warned, false );
	}

	/**
	 * Daily.
	 *
	 * @return void
	 */
	public static function daily() {}

	/**
	 * Email the owner from the store's address.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return bool
	 */
	public static function mail( $subject, $body ) {
		$to      = CouponWarden_Settings::get( 'notify_to' );
		$to      = '' !== $to ? array_map( 'trim', explode( ',', $to ) ) : array( get_option( 'admin_email' ) );
		$headers = array();
		$from    = get_option( 'woocommerce_email_from_address' );
		if ( is_email( $from ) ) {
			$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = 'From: ' . str_replace( array( "\r", "\n", '<', '>' ), '', (string) get_option( 'woocommerce_email_from_name', $site ) ) . ' <' . $from . '>';
		}
		return wp_mail( $to, $subject, $body, $headers );
	}

	/* ------------------------------------------------------------------
	 * Screens
	 * ---------------------------------------------------------------- */

	/**
	 * Protected coupons.
	 *
	 * @return array
	 */
	public static function protected_coupons() {
		return get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'posts_per_page' => 200,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen.
					'relation' => 'OR',
					array(
						'key'   => self::NEW_ONLY,
						'value' => 'yes',
					),
					array(
						'key'   => self::ONCE,
						'value' => 'yes',
					),
				),
			)
		);
	}

	/**
	 * Past customers on record.
	 *
	 * @return int
	 */
	private static function known() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dashboard count.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Overview.
	 *
	 * @return void
	 */
	public static function render_overview() {
		$a     = 'CouponWarden_Admin';
		$count = count( self::protected_coupons() );
		if ( ! $count ) {
			$a::banner( 'is-learning', __( 'Pick the coupons to protect', 'couponwarden' ), __( 'Edit a coupon, open Usage restriction and tick "New customers only" or "Once per person".', 'couponwarden' ) );
		} else {
			/* translators: %s: number */
			$a::banner( 'is-on', __( 'Your coupons are protected', 'couponwarden' ), sprintf( _n( '%s coupon checks every order against your past customers, guests included.', '%s coupons check every order against your past customers, guests included.', $count, 'couponwarden' ), number_format_i18n( $count ) ) );
		}
		$a::figures(
			array(
				__( 'Attempts stopped, 7 days', 'couponwarden' ) => number_format_i18n( CouponWarden_Log::count( 'blocked', WEEK_IN_SECONDS ) ),
				__( 'Discount kept, 7 days', 'couponwarden' )    => wp_strip_all_tags( wc_price( CouponWarden_Log::sum( 'blocked', WEEK_IN_SECONDS ) ) ),
				__( 'Protected coupons', 'couponwarden' )        => number_format_i18n( $count ),
				__( 'Past orders on record', 'couponwarden' )    => number_format_i18n( self::known() ),
			)
		);
		$rows = array();
		foreach ( CouponWarden_Log::recent( array( 'types' => array( 'blocked', 'leak' ), 'limit' => 25 ) ) as $row ) {
			$rows[] = array(
				'when'      => $a::ago( $row['created_at'] ),
				'code'      => strtoupper( $row['detail'] ),
				'html_what' => 'leak' === $row['type']
					? $a::tag( 'is-would', __( 'Possible leak', 'couponwarden' ) )
					: $a::tag( 'is-blocked', 'once' === $row['field'] ? __( 'Already used by this person', 'couponwarden' ) : __( 'Not a new customer', 'couponwarden' ) ),
				'amount'    => 'leak' === $row['type']
					/* translators: %s: uses */
					? sprintf( __( '%s uses in 24 h', 'couponwarden' ), number_format_i18n( (float) $row['amount'] ) )
					: wp_strip_all_tags( wc_price( (float) $row['amount'] ) ),
			);
		}
		$a::panel_open( __( 'Recently stopped', 'couponwarden' ), __( 'People are matched by scrambled fingerprints, never stored in plain text.', 'couponwarden' ) );
		if ( $rows ) {
			$a::table(
				array(
					'when'      => __( 'When', 'couponwarden' ),
					'code'      => __( 'Coupon', 'couponwarden' ),
					'html_what' => __( 'Why', 'couponwarden' ),
					'amount'    => __( 'Discount kept', 'couponwarden' ),
				),
				$rows
			);
		} else {
			$a::empty_state( __( 'Nothing stopped yet', 'couponwarden' ), __( 'When someone tries a protected coupon a second time, it shows up here.', 'couponwarden' ) );
		}
		$a::panel_close();
	}

	/**
	 * Coupons tab.
	 *
	 * @return void
	 */
	public static function render_coupons() {
		$a     = 'CouponWarden_Admin';
		$today = self::uses( DAY_IN_SECONDS );
		$week  = self::uses( WEEK_IN_SECONDS );
		$posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'posts_per_page' => 100,
			)
		);
		$rows = array();
		foreach ( $posts as $post ) {
			$code   = wc_format_coupon_code( $post->post_title );
			$flags  = array();
			if ( 'yes' === get_post_meta( $post->ID, self::NEW_ONLY, true ) ) {
				$flags[] = $a::tag( 'is-ok', __( 'New customers', 'couponwarden' ) );
			}
			if ( 'yes' === get_post_meta( $post->ID, self::ONCE, true ) ) {
				$flags[] = $a::tag( 'is-ok', __( 'Once per person', 'couponwarden' ) );
			}
			$rows[] = array(
				'html_code'  => '<a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '"><strong>' . esc_html( strtoupper( $code ) ) . '</strong></a>',
				'html_rules' => $flags ? implode( ' ', $flags ) : $a::tag( '', __( 'Not protected', 'couponwarden' ) ),
				'today'      => number_format_i18n( isset( $today[ $code ] ) ? $today[ $code ] : 0 ),
				'week'       => number_format_i18n( isset( $week[ $code ] ) ? $week[ $code ] : 0 ),
				'stopped'    => number_format_i18n( self::stopped_for( $code ) ),
			);
		}
		$a::panel_open( __( 'Coupons', 'couponwarden' ), __( 'Edit a coupon to change its protection.', 'couponwarden' ) );
		if ( $rows ) {
			$a::table(
				array(
					'html_code'  => __( 'Coupon', 'couponwarden' ),
					'html_rules' => __( 'Protection', 'couponwarden' ),
					'today'      => __( 'Uses, 24 h', 'couponwarden' ),
					'week'       => __( 'Uses, 7 days', 'couponwarden' ),
					'stopped'    => __( 'Stopped, 7 days', 'couponwarden' ),
				),
				$rows
			);
		} else {
			$a::empty_state( __( 'No coupons yet', 'couponwarden' ), __( 'Create a coupon in Marketing, Coupons.', 'couponwarden' ) );
		}
		$a::panel_close();
	}

	/**
	 * Stopped attempts for one code in the last week.
	 *
	 * @param string $code Code.
	 * @return int
	 */
	private static function stopped_for( $code ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin screen.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND detail = %s AND created_at >= %s', CouponWarden_Log::table(), 'blocked', $code, gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	public static function render_settings() {
		$a = 'CouponWarden_Admin';
		$a::form_open( 'settings' );
		$a::panel_open( __( 'How to recognise a person', 'couponwarden' ) );
		$a::row(
			__( 'Match on', 'couponwarden' ),
			__( 'Someone counts as the same person when any ticked detail matches an earlier paid order.', 'couponwarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'match_email', __( 'Email address (Gmail dots and +tags ignored)', 'couponwarden' ) );
				$a::switch_field( 'match_phone', __( 'Phone number', 'couponwarden' ) );
				$a::switch_field( 'match_address', __( 'Delivery address and postcode', 'couponwarden' ) );
			}
		);
		$a::panel_close();
		$a::panel_open( __( 'Messages', 'couponwarden' ) );
		$a::row(
			__( 'Not a new customer', 'couponwarden' ),
			/* translators: %s stays as it is: it is replaced by the coupon code. */
			__( 'Use %s for the coupon code.', 'couponwarden' ),
			static function () use ( $a ) {
				$a::text( 'msg_new', __( 'Message', 'couponwarden' ) );
			}
		);
		$a::row(
			__( 'Already used', 'couponwarden' ),
			/* translators: %s stays as it is: it is replaced by the coupon code. */
			__( 'Use %s for the coupon code.', 'couponwarden' ),
			static function () use ( $a ) {
				$a::text( 'msg_once', __( 'Message', 'couponwarden' ) );
			}
		);
		$a::panel_close();
		$a::panel_open( __( 'Leak watch', 'couponwarden' ) );
		$a::row(
			__( 'Sudden jumps', 'couponwarden' ),
			__( 'A code posted on a coupon website is used far more than usual.', 'couponwarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'leak_watch', __( 'Email me when a coupon may have leaked', 'couponwarden' ) );
				/* translators: %1$s: number */
				$a::sentence( __( 'Only when it is used at least %1$s times in 24 hours and five times more than usual.', 'couponwarden' ), array( 'leak_min' => __( 'Uses', 'couponwarden' ) ) );
				$a::text( 'notify_to', __( 'Email addresses', 'couponwarden' ), get_option( 'admin_email' ) );
			}
		);
		$a::panel_close();
		$a::panel_open( __( 'Data', 'couponwarden' ) );
		$a::row(
			__( 'History', 'couponwarden' ),
			__( 'Past customers are kept as scrambled fingerprints for as long as the plugin is active.', 'couponwarden' ),
			static function () use ( $a ) {
				/* translators: %1$s: days */
				$a::sentence( __( 'Keep the activity log for %1$s days.', 'couponwarden' ), array( 'retention_days' => __( 'Days', 'couponwarden' ) ) );
				$a::switch_field( 'delete_data', __( 'Delete settings and data when the plugin is deleted', 'couponwarden' ) );
			}
		);
		$a::panel_close();
		$a::form_close();
	}
}
