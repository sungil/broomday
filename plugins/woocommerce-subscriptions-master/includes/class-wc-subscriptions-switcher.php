<?php
/**
 * A class to make it possible to switch between different subscriptions (i.e. upgrade/downgrade a subscription)
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Switcher
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.4
 */
class WC_Subscriptions_Switcher {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.4
	 */
	public static function init() {

		// Attach hooks which depend on WooCommerce constants
		add_action( 'woocommerce_loaded', __CLASS__ . '::attach_dependant_hooks' );

		// Check if the current request is for switching a subscription and if so, start he switching process
		add_filter( 'template_redirect', __CLASS__ . '::subscription_switch_handler', 100 );

		// Pass in the filter switch to the group items
		add_filter( 'woocommerce_grouped_product_list_link', __CLASS__ . '::add_switch_query_arg_grouped', 12 );
		add_filter( 'post_type_link', __CLASS__ . '::add_switch_query_arg_post_link', 12, 2 );

		// Add the settings to control whether Switching is enabled and how it will behave
		add_filter( 'woocommerce_subscription_settings', __CLASS__ . '::add_settings' );

		// Add the "Switch" button to the View Subscription table
		add_filter( 'woocommerce_order_item_meta_end', __CLASS__ . '::print_switch_link', 10, 3 );

		// We need to create subscriptions on checkout and want to do it after almost all other extensions have added their products/items/fees
		add_action( 'woocommerce_checkout_order_processed', __CLASS__ . '::process_checkout', 50, 2 );

		// When creating an order, add meta if it's for switching a subscription
		add_action( 'woocommerce_checkout_update_order_meta', __CLASS__ . '::add_order_meta', 10, 2 );

		// Add a renewal orders section to the Related Orders meta box
		add_action( 'woocommerce_subscriptions_related_orders_meta_box_rows', __CLASS__ . '::switch_order_meta_box_rows', 10 );

		// Don't allow switching to the same product
		add_filter( 'woocommerce_add_to_cart_validation', __CLASS__ . '::validate_switch_request', 10, 4 );

		// Record subscription switching in the cart
		add_action( 'woocommerce_add_cart_item_data', __CLASS__ . '::set_switch_details_in_cart', 10, 3 );

		// Make sure the 'switch_subscription' cart item data persists
		add_action( 'woocommerce_get_cart_item_from_session', __CLASS__ . '::get_cart_from_session', 10, 3 );

		// Set totals for subscription switch orders (needs to be hooked just before WC_Subscriptions_Cart::calculate_subscription_totals())
		add_filter( 'woocommerce_before_calculate_totals', __CLASS__ . '::calculate_prorated_totals', 99, 1 );

		// Don't display free trials when switching a subscription, because no free trials are provided
		add_filter( 'woocommerce_subscriptions_product_price_string_inclusions', __CLASS__ . '::customise_product_string_inclusions', 12, 2 );

		// Don't carry switch meta data to renewal orders
		add_filter( 'wcs_renewal_order_meta_query', __CLASS__ . '::remove_renewal_order_meta_query', 10 );

		// Don't carry switch meta data to renewal orders
		add_filter( 'woocommerce_subscriptions_recurring_cart_key', __CLASS__ . '::get_recurring_cart_key', 10, 2 );

		// Make sure the first renewal date takes into account any prorated length of time for upgrades/downgrades
		add_filter( 'wcs_recurring_cart_next_payment_date', __CLASS__ . '::recurring_cart_next_payment_date', 100, 2 );

		// Make sure the new end date starts from the end of the time that has already paid for
		add_filter( 'wcs_recurring_cart_end_date', __CLASS__ . '::recurring_cart_end_date', 100, 3 );

		// Make sure the switch process persists when having to choose product addons
		add_action( 'addons_add_to_cart_url', __CLASS__ . '::addons_add_to_cart_url', 10 );

		// Make sure the switch process persists when having to choose product addons
		add_action( 'woocommerce_hidden_order_itemmeta', __CLASS__ . '::hidden_order_itemmeta', 10 );

		// Add/remove the print switch link filters when printing HTML/plain subscription emails
		add_action( 'woocommerce_email_before_subscription_table', __CLASS__ . '::remove_print_switch_link' );
		add_filter( 'woocommerce_email_order_items_table', __CLASS__ . '::add_print_switch_link' );

		// Make sure sign-up fees paid on switch orders are accounted for in an items sign-up fee
		add_filter( 'woocommerce_subscription_items_sign_up_fee', __CLASS__ . '::subscription_items_sign_up_fee', 10, 4 );

		// Display/indicate whether a cart switch item is a upgrade/downgrade/crossgrade
		add_filter( 'woocommerce_cart_item_subtotal', __CLASS__ . '::add_cart_item_switch_direction', 10, 3 );

		// Check if the order was to record a switch request and maybe call a "switch completed" action.
		add_action( 'woocommerce_subscriptions_switch_completed', __CLASS__ . '::maybe_add_switched_callback', 10, 1 );

		// Revoke download permissions from old switch item
		add_action( 'woocommerce_subscriptions_switched_item', __CLASS__ . '::remove_download_permissions_after_switch', 10, 3 );

		// Process subscription switch changes on completed switch orders status
		add_action( 'woocommerce_order_status_changed', __CLASS__ . '::process_subscription_switches', 10, 3 );

		// Check if we need to force payment on this switch, just after calculating the prorated totals in @see self::calculate_prorated_totals()
		add_filter( 'woocommerce_subscriptions_calculated_total', __CLASS__ . '::set_force_payment_flag_in_cart', 10, 1 );

		// Require payment when switching from a $0 / period subscription to a non-zero subscription to process automatic payments
		add_filter( 'woocommerce_cart_needs_payment', __CLASS__ . '::cart_needs_payment' , 50, 2 );

		// Require payment when switching from a $0 / period subscription to a non-zero subscription to process automatic payments
		add_filter( 'woocommerce_subscriptions_switch_completed', __CLASS__ . '::maybe_set_payment_method_after_switch' , 10, 1 );

		// Do not reduce product stock when the order item is simply to record a switch
		add_filter( 'woocommerce_order_item_quantity', __CLASS__ . '::maybe_do_not_reduce_stock', 10, 3 );

		// Mock a free trial on the cart item to make sure the switch total doesn't include any recurring amount
		add_filter( 'woocommerce_before_calculate_totals', __CLASS__ . '::maybe_set_free_trial', 100, 1 );
		add_action( 'woocommerce_subscription_cart_before_grouping', __CLASS__ . '::maybe_unset_free_trial' );
		add_action( 'woocommerce_subscription_cart_after_grouping', __CLASS__ . '::maybe_set_free_trial' );
		add_action( 'wcs_recurring_cart_start_date', __CLASS__ . '::maybe_unset_free_trial', 0, 1 );
		add_action( 'wcs_recurring_cart_end_date', __CLASS__ . '::maybe_set_free_trial', 100, 1 );
		add_filter( 'woocommerce_subscriptions_calculated_total', __CLASS__ . '::maybe_unset_free_trial', 10000, 1 );
		add_action( 'woocommerce_cart_totals_before_shipping', __CLASS__ . '::maybe_set_free_trial' );
		add_action( 'woocommerce_cart_totals_after_shipping', __CLASS__ . '::maybe_unset_free_trial' );
		add_action( 'woocommerce_review_order_before_shipping', __CLASS__ . '::maybe_set_free_trial' );
		add_action( 'woocommerce_review_order_after_shipping', __CLASS__ . '::maybe_unset_free_trial' );

		// Grant download permissions after the switch is complete.
		add_action( 'woocommerce_grant_product_download_permissions', __CLASS__ . '::delay_granting_download_permissions', 9, 1 );
		add_action( 'woocommerce_subscriptions_switch_completed', __CLASS__ . '::grant_download_permissions', 9, 1 );
	}

	/**
	 * Attach WooCommerce version dependent hooks
	 *
	 * @since 2.2.0
	 */
	public static function attach_dependant_hooks() {

		if ( WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {

			// For order items created as part of a switch, keep a record of the prorated amounts
			add_action( 'woocommerce_add_order_item_meta', __CLASS__ . '::add_order_item_meta', 10, 3 );

			// For subscription items created as part of a switch, keep a record of the relationship between the items
			add_action( 'woocommerce_add_subscription_item_meta', __CLASS__ . '::set_subscription_item_meta', 50, 3 );

		} else {

			// For order items created as part of a switch, keep a record of the prorated amounts
			add_action( 'woocommerce_checkout_create_order_line_item', __CLASS__ . '::add_line_item_meta', 10, 4 );
		}
	}

	/**
	 * Handles the subscription upgrade/downgrade process.
	 *
	 * @since 1.4
	 */
	public static function subscription_switch_handler() {
		global $post;

		// If the current user doesn't own the subscription, remove the query arg from the URL
		if ( isset( $_GET['switch-subscription'] ) && isset( $_GET['item'] ) ) {

			$subscription = wcs_get_subscription( $_GET['switch-subscription'] );
			$line_item    = wcs_get_order_item( $_GET['item'], $subscription );

			// Visiting a switch link for someone elses subscription or if the switch link doesn't contain a valid nonce
			if ( ! is_object( $subscription ) || empty( $_GET['_wcsnonce'] ) || ! wp_verify_nonce( $_GET['_wcsnonce'], 'wcs_switch_request' ) || empty( $line_item ) || ! self::can_item_be_switched_by_user( $line_item, $subscription )  ) {

				wp_redirect( remove_query_arg( array( 'switch-subscription', 'auto-switch', 'item', '_wcsnonce' ) ) );
				exit();

			} else {

				if ( isset( $_GET['auto-switch'] ) ) {
					$switch_message = __( 'You have a subscription to this product. Choosing a new subscription will replace your existing subscription.', 'woocommerce-subscriptions' );
				} else {
					$switch_message = __( 'Choose a new subscription.', 'woocommerce-subscriptions' );
				}

				wc_add_notice( $switch_message, 'notice' );

			}
		} elseif ( ( is_cart() || is_checkout() ) && ! is_order_received_page() && false !== ( $switch_items = self::cart_contains_switches() ) ) {

			$removed_item_count = 0;

			foreach ( $switch_items as $cart_item_key => $switch_item ) {

				$subscription = wcs_get_subscription( $switch_item['subscription_id'] );
				$line_item    = wcs_get_order_item( $switch_item['item_id'], $subscription );

				if ( ! is_object( $subscription ) || empty( $line_item ) || ! self::can_item_be_switched_by_user( $line_item, $subscription ) ) {
					WC()->cart->remove_cart_item( $cart_item_key );
					$removed_item_count++;
				}
			}

			if ( $removed_item_count > 0 ) {
				wc_add_notice( _n( 'Your cart contained an invalid subscription switch request. It has been removed.', 'Your cart contained invalid subscription switch requests. They have been removed.', 	$removed_item_count, 'woocommerce-subscriptions' ), 'error' );

				wp_redirect( wc_get_cart_url() );
				exit();
			}
		} elseif ( is_product() && $product = wc_get_product( $post ) ) { // Automatically initiate the switch process for limited variable subscriptions

			$limited_switchable_products = array();

			if ( $product->is_type( 'grouped' ) ) { // If we're on a grouped product's page, we need to check if this grouped product has children which are limited and may need to be switched

				$child_ids = $product->get_children();

				foreach ( $child_ids as $child_id ) {
					$product = wc_get_product( $child_id );

					if ( 'no' != wcs_get_product_limitation( $product ) && wcs_is_product_switchable_type( $product ) ) {
						$limited_switchable_products[] = $product;
					}
				}
			} elseif ( 'no' != wcs_get_product_limitation( $product ) && wcs_is_product_switchable_type( $product ) ) {
				// If we're on a limited variation or single product within a group which is switchable
				// we only need to look for if the customer is subscribed to this product
				$limited_switchable_products[] = $product;
			}

			// If we have limited switchable products, check if the customer is already subscribed and needs to be switched
			if ( ! empty( $limited_switchable_products ) ) {

				$subscriptions = wcs_get_users_subscriptions();

				foreach ( $subscriptions as $subscription ) {
					foreach ( $limited_switchable_products as $product ) {

						if ( ! $subscription->has_product( $product->get_id() ) ) {
							continue;
						}

						$limitation = wcs_get_product_limitation( $product );

						if ( 'any' == $limitation || $subscription->has_status( $limitation ) ) {

							$subscribed_notice = __( 'You have already subscribed to this product and it is limited to one per customer. You can not purchase the product again.', 'woocommerce-subscriptions' );

							// Don't initiate auto-switching when the subscription requires payment
							if ( $subscription->needs_payment() ) {

								$last_order = $subscription->get_last_order( 'all' );

								if ( $last_order->needs_payment() ) {
									// translators: 1$: is the "You have already subscribed to this product" notice, 2$-4$: opening/closing link tags, 3$: an order number
									$subscribed_notice = sprintf( __( '%1$s Complete payment on %2$sOrder %3$s%4$s to be able to change your subscription.', 'woocommerce-subscriptions' ), $subscribed_notice, sprintf( '<a href="%s">', $last_order->get_checkout_payment_url() ), $last_order->get_order_number(), '</a>' );
								}

								wc_add_notice( $subscribed_notice, 'notice' );
								break;

							} else {

								// Get the matching item
								foreach ( $subscription->get_items() as $line_item_id => $line_item ) {
									if ( $line_item['product_id'] == $product->get_id() || $line_item['variation_id'] == $product->get_id() ) {
										$item_id = $line_item_id;
										$item    = $line_item;
										break;
									}
								}

								if ( self::can_item_be_switched_by_user( $item, $subscription ) ) {
									wp_redirect( add_query_arg( 'auto-switch', 'true', self::get_switch_url( $item_id, $item, $subscription ) ) );
									exit;
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * When switching between grouped products, the Switch Subscription will take people to the grouped product's page. From there if they
	 * click through to the individual products, they lose the switch.
	 *
	 * WooCommerce added a filter so we're able to modify the permalinks, passing through the switch parameter to the individual products'
	 * pages.
	 *
	 * @param string $permalink The permalink of the product belonging to that group
	 */
	public static function add_switch_query_arg_grouped( $permalink ) {

		if ( isset( $_GET['switch-subscription'] ) ) {
			$permalink = self::add_switch_query_args( $_GET['switch-subscription'], $_GET['item'], $permalink );
		}

		return $permalink;
	}

	/**
	 * Slightly more awkward implementation for WooCommerce versions that do not have the woocommerce_grouped_product_list_link filter.
	 *
	 * @param string  $permalink The permalink of the product belonging to the group
	 * @param WP_Post $post      The WP_Post object
	 *
	 * @return string modified string with the query arg present
	 */
	public static function add_switch_query_arg_post_link( $permalink, $post ) {
		if ( ! isset( $_GET['switch-subscription'] ) || ! is_main_query() || ! is_product() || 'product' !== $post->post_type ) {
			return $permalink;
		}

		$product = wc_get_product( $post );
		$type    = wcs_get_objects_property( $product, 'type' );

		switch ( $type ) {
			case 'variable-subscription':
			case 'subscription':
				return self::add_switch_query_args( $_GET['switch-subscription'], $_GET['item'], $permalink );

			case 'grouped':
				// Check to see if the group contains a subscription.
				$children = $product->get_children();
				foreach ( $children as $child ) {
					$child_product = wc_get_product( $child );
					if ( 'subscription' === wcs_get_objects_property( $child_product, 'type' ) ) {
						return self::add_switch_query_args( $_GET['switch-subscription'], $_GET['item'], $permalink );
					}
				}

				// break omitted intentionally to fall through to default.

			default:
				return $permalink;
		}
	}

	/**
	 * Add Switch settings to the Subscription's settings page.
	 *
	 * @since 1.4
	 */
	public static function add_settings( $settings ) {

		array_splice( $settings, 12, 0, array(

			array(
				'name'     => __( 'Switching', 'woocommerce-subscriptions' ),
				'type'     => 'title',
				// translators: placeholders are opening and closing link tags
				'desc'     => sprintf( __( 'Allow subscribers to switch (upgrade or downgrade) between different subscriptions. %sLearn more%s.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( 'http://docs.woocommerce.com/document/subscriptions/switching-guide/' ) . '">', '</a>' ),
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_switch_settings',
			),

			array(
				'name'    => __( 'Allow Switching', 'woocommerce-subscriptions' ),
				'desc'    => __( 'Allow subscribers to switch between subscriptions combined in a grouped product, different variations of a Variable subscription or don\'t allow switching.', 'woocommerce-subscriptions' ),
				'tip'     => '',
				'id'      => WC_Subscriptions_Admin::$option_prefix . '_allow_switching',
				'css'     => 'min-width:150px;',
				'default' => 'no',
				'type'    => 'select',
				'options' => array(
					'no'               => _x( 'Never', 'when to allow a setting', 'woocommerce-subscriptions' ),
					'variable'         => _x( 'Between Subscription Variations', 'when to allow switching', 'woocommerce-subscriptions' ),
					'grouped'          => _x( 'Between Grouped Subscriptions', 'when to allow switching', 'woocommerce-subscriptions' ),
					'variable_grouped' => _x( 'Between Both Variations & Grouped Subscriptions', 'when to allow switching', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),

			array(
				'name'    => __( 'Prorate Recurring Payment', 'woocommerce-subscriptions' ),
				'desc'    => __( 'When switching to a subscription with a different recurring payment or billing period, should the price paid for the existing billing period be prorated when switching to the new subscription?', 'woocommerce-subscriptions' ),
				'tip'     => '',
				'id'      => WC_Subscriptions_Admin::$option_prefix . '_apportion_recurring_price',
				'css'     => 'min-width:150px;',
				'default' => 'no',
				'type'    => 'select',
				'options' => array(
					'no'              => _x( 'Never', 'when to allow a setting', 'woocommerce-subscriptions' ),
					'virtual-upgrade' => _x( 'For Upgrades of Virtual Subscription Products Only', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'yes-upgrade'     => _x( 'For Upgrades of All Subscription Products', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'virtual'         => _x( 'For Upgrades & Downgrades of Virtual Subscription Products Only', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
					'yes'             => _x( 'For Upgrades & Downgrades of All Subscription Products', 'when to prorate recurring fee when switching', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),

			array(
				'name'    => __( 'Prorate Sign up Fee', 'woocommerce-subscriptions' ),
				'desc'    => __( 'When switching to a subscription with a sign up fee, you can require the customer pay only the gap between the existing subscription\'s sign up fee and the new subscription\'s sign up fee (if any).', 'woocommerce-subscriptions' ),
				'tip'     => '',
				'id'      => WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee',
				'css'     => 'min-width:150px;',
				'default' => 'no',
				'type'    => 'select',
				'options' => array(
					'no'                 => _x( 'Never (do not charge a sign up fee)', 'when to prorate signup fee when switching', 'woocommerce-subscriptions' ),
					'full'               => _x( 'Never (charge the full sign up fee)', 'when to prorate signup fee when switching', 'woocommerce-subscriptions' ),
					'yes'                => _x( 'Always', 'when to prorate signup fee when switching','woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),

			array(
				'name'    => __( 'Prorate Subscription Length', 'woocommerce-subscriptions' ),
				'desc'    => __( 'When switching to a subscription with a length, you can take into account the payments already completed by the customer when determining how many payments the subscriber needs to make for the new subscription.', 'woocommerce-subscriptions' ),
				'tip'     => '',
				'id'      => WC_Subscriptions_Admin::$option_prefix . '_apportion_length',
				'css'     => 'min-width:150px;',
				'default' => 'no',
				'type'    => 'select',
				'options' => array(
					'no'                 => _x( 'Never', 'when to allow a setting', 'woocommerce-subscriptions' ),
					'virtual'            => _x( 'For Virtual Subscription Products Only', 'when to prorate first payment / subscription length', 'woocommerce-subscriptions' ),
					'yes'                => _x( 'For All Subscription Products', 'when to prorate first payment / subscription length', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),

			array(
				'name'     => __( 'Switch Button Text', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Customise the text displayed on the button next to the subscription on the subscriber\'s account page. The default is "Switch Subscription", but you may wish to change this to "Upgrade" or "Change Subscription".', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_switch_button_text',
				'css'      => 'min-width:150px;',
				'default'  => __( 'Upgrade or Downgrade', 'woocommerce-subscriptions' ),
				'type'     => 'text',
				'desc_tip' => true,
			),

			array( 'type' => 'sectionend', 'id' => WC_Subscriptions_Admin::$option_prefix . '_switch_settings' ),
		) );

		return $settings;
	}

	/**
	 * Adds an Upgrade/Downgrade link on the View Subscription page for each item that can be switched.
	 *
	 * @param int $item_id The order item ID of a subscription line item
	 * @param array $item An order line item
	 * @param object $subscription A WC_Subscription object
	 * @since 1.4
	 */
	public static function print_switch_link( $item_id, $item, $subscription ) {

		if ( wcs_is_order( $subscription ) || 'shop_subscription' !== $subscription->get_type() || ! self::can_item_be_switched_by_user( $item, $subscription ) ) {
			return;
		}

		$switch_url  = esc_url( self::get_switch_url( $item_id, $item, $subscription ) );
		$switch_text = get_option( WC_Subscriptions_Admin::$option_prefix . '_switch_button_text', __( 'Upgrade or Downgrade', 'woocommerce-subscriptions' ) );
		$switch_link = sprintf( '<a href="%s" class="wcs-switch-link button">Actualizar o bajar de categoría</a>', $switch_url, $switch_text );

		echo wp_kses( apply_filters( 'woocommerce_subscriptions_switch_link', $switch_link, $item_id, $item, $subscription ), array( 'a' => array( 'href' => array(), 'title' => array(), 'class' => array() ) ) );
	}

	/**
	 * The link for switching a subscription - the product page for variable subscriptions, or grouped product page for grouped subscriptions.
	 *
	 * @param WC_Subscription $subscription An instance of WC_Subscription
	 * @param array $item An order item on the subscription
	 * @since 2.0
	 */
	public static function get_switch_url( $item_id, $item, $subscription ) {

		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		$product = wc_get_product( $item['product_id'] );
		$parent_products       = WC_Subscriptions_Product::get_visible_grouped_parent_product_ids( $product );
		$additional_query_args = array();

		// Grouped product
		if ( ! empty( $parent_products ) ) {
			$switch_url = get_permalink( reset( $parent_products ) );
		} else {
			$switch_url = get_permalink( $product->get_id() );

			if ( ! empty( $_GET ) && is_product() ) {
				$product_variations    = $product->get_variation_attributes();
				$additional_query_args = array_intersect_key( $_GET, $product_variations );
			}
		}

		$switch_url = self::add_switch_query_args( $subscription->get_id(), $item_id, $switch_url, $additional_query_args );

		return apply_filters( 'woocommerce_subscriptions_switch_url', $switch_url, $item_id, $item, $subscription );
	}

	/**
	 * Add the switch parameters to a URL for a given subscription and item.
	 *
	 * @param int $subscription_id A subscription's post ID
	 * @param int $item_id The order item ID of a subscription line item
	 * @param string $permalink The permalink of the product
	 * @param array $additional_query_args (optional) Additional query args to add to the switch URL
	 * @since 2.0
	 */
	protected static function add_switch_query_args( $subscription_id, $item_id, $permalink, $additional_query_args = array() ) {

		// manually add a nonce because we can't use wp_nonce_url() (it would escape the URL)
		$query_args = array_merge( $additional_query_args, array( 'switch-subscription' => absint( $subscription_id ), 'item' => absint( $item_id ), '_wcsnonce' => wp_create_nonce( 'wcs_switch_request' ) ) );
		$permalink  = add_query_arg( $query_args, $permalink );

		return apply_filters( 'woocommerce_subscriptions_add_switch_query_args', $permalink, $subscription_id, $item_id );
	}

	/**
	 * Check if a given item on a subscription can be switched.
	 *
	 * For an item to be switchable, switching must be enabled, and the item must be for a variable subscription or
	 * part of a grouped product (at the time the check is made, not at the time the subscription was purchased)
	 *
	 * The subscription must also be active and use manual renewals or use a payment method which supports cancellation.
	 *
	 * @param array $item An order item on the subscription
	 * @param WC_Subscription $subscription An instance of WC_Subscription
	 * @since 2.0
	 */
	public static function can_item_be_switched( $item, $subscription = null ) {

		$product_id = wcs_get_canonical_product_id( $item );

		if ( 'line_item' == $item['type'] && wcs_is_product_switchable_type( $product_id ) ) {
			$is_product_switchable = true;
		} else {
			$is_product_switchable = false;
		}

		if ( $subscription->has_status( 'active' ) && 0 !== $subscription->get_date( 'last_order_date_created' ) ) {
			$is_subscription_switchable = true;
		} else {
			$is_subscription_switchable = false;
		}

		if ( $subscription->payment_method_supports( 'subscription_amount_changes' ) && $subscription->payment_method_supports( 'subscription_date_changes' ) ) {
			$can_subscription_be_updated = true;
		} else {
			$can_subscription_be_updated = false;
		}

		if ( $is_product_switchable && $is_subscription_switchable && $can_subscription_be_updated ) {
			$item_can_be_switch = true;
		} else {
			$item_can_be_switch = false;
		}

		return apply_filters( 'woocommerce_subscriptions_can_item_be_switched', $item_can_be_switch, $item, $subscription );
	}

	/**
	 * Check if a given item on a subscription can be switched by a given user.
	 *
	 * @param array $item An order item on the subscription
	 * @param WC_Subscription $subscription An instance of WC_Subscription
	 * @param int $user_id (optional) The ID of a user. Defaults to currently logged in user.
	 * @since 2.0
	 */
	public static function can_item_be_switched_by_user( $item, $subscription, $user_id = 0 ) {

		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		$item_can_be_switched = false;

		if ( user_can( $user_id, 'switch_shop_subscription', $subscription->get_id() ) && self::can_item_be_switched( $item, $subscription ) ) {
			$item_can_be_switched = true;
		}

		return apply_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user', $item_can_be_switched, $item, $subscription );
	}

	/**
	 * If the order being generated is for switching a subscription, keep a record of some of the switch
	 * routines meta against the order.
	 *
	 * @param int $order_id The ID of a WC_Order object
	 * @param array $posted The data posted on checkout
	 * @since 1.4
	 */
	public static function add_order_meta( $order_id, $posted ) {

		$order = wc_get_order( $order_id );

		// delete all the existing subscription switch links before adding new ones
		WCS_Related_Order_Store::instance()->delete_relations( $order, 'switch' );

		$switches = self::cart_contains_switches();

		if ( false !== $switches ) {

			foreach ( $switches as $switch_details ) {
				WCS_Related_Order_Store::instance()->add_relation( $order, wcs_get_subscription( $switch_details['subscription_id'] ), 'switch' );
			}
		}
	}

	/**
	 * To prorate sign-up fee and recurring amounts correctly when the customer switches a subscription multiple times, keep a record of the
	 * amount for each on the order item.
	 *
	 * @param int $order_item_id The ID of a WC_Order_Item object.
	 * @param array $cart_item The cart item's data.
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @since 2.0
	 */
	public static function add_order_item_meta( $order_item_id, $cart_item, $cart_item_key ) {

		if ( false === WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			_deprecated_function( __METHOD__, '2.2.0 and WooCommerce 3.0.0', __CLASS__ . '::add_order_line_item_meta( $order_item, $cart_item_key, $cart_item )' );
		}

		if ( isset( $cart_item['subscription_switch'] ) ) {
			if ( $switches = self::cart_contains_switches() ) {
				foreach ( $switches as $switch_item_key => $switch_details ) {
					if ( $cart_item_key == $switch_item_key ) {
						wc_add_order_item_meta( $order_item_id, '_switched_subscription_sign_up_fee_prorated', wcs_get_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee_prorated', 'single', 0 ), true );
						wc_add_order_item_meta( $order_item_id, '_switched_subscription_price_prorated', wcs_get_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_price_prorated', 'single', 0 ), true );
					}
				}
			}

			// Store the order line item id so it can be retrieved when we're processing the switch on checkout
			foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

				// If this cart item belongs to this recurring cart
				if ( in_array( $cart_item_key, array_keys( $recurring_cart->cart_contents ) ) ) {
					WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ]['subscription_switch']['order_line_item_id'] = $order_item_id;
				}
			}
		}
	}

	/**
	 * Store switch related data on the line item on the subscription and switch order.
	 *
	 * For subscriptions: items on a new billing schedule are left to be added as new subscriptions, but we also want
	 * to keep a record of them being a switch, so we do that here.
	 *
	 * For orders: to prorate sign-up fee and recurring amounts correctly when the customer switches a subscription
	 * multiple times, keep a record of the amount for each on the order item.
	 *
	 * Attached to WC 3.0+ hooks and uses WC 3.0 methods.
	 *
	 * @param WC_Order_Item_Product $order_item
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @param array $cart_item The cart item's data.
	 * @param WC_Order $order The order or subscription object to which the line item relates
	 * @since 2.2.0
	 */
	public static function add_line_item_meta( $order_item, $cart_item_key, $cart_item, $order ) {
		if ( isset( $cart_item['subscription_switch'] ) ) {
			if ( $switches = self::cart_contains_switches() ) {
				foreach ( $switches as $switch_item_key => $switch_details ) {
					if ( $cart_item_key == $switch_item_key ) {

						if ( wcs_is_subscription( $order ) ) {
							$order_item->add_meta_data( '_switched_subscription_item_id', $switch_details['item_id'] );
						} else {
							$sign_up_fee_prorated = WC()->cart->cart_contents[ $cart_item_key ]['data']->get_meta( 'subscription_sign_up_fee_prorated', true );
							$price_prorated       = WC()->cart->cart_contents[ $cart_item_key ]['data']->get_meta( 'subscription_price_prorated', true );

							$order_item->add_meta_data( '_switched_subscription_sign_up_fee_prorated', empty( $sign_up_fee_prorated ) ? 0 : $sign_up_fee_prorated );
							$order_item->add_meta_data( '_switched_subscription_price_prorated', empty( $price_prorated ) ? 0 : $price_prorated );
						}
					}
				}
			}
		}
	}

	/**
	 * Subscription items on a new billing schedule are left to be added as new subscriptions, but we also
	 * want to keep a record of them being a switch, so we do that here.
	 *
	 * @param int $order_item_id The ID of a WC_Order_Item object.
	 * @param array $cart_item The cart item's data.
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @since 2.0
	 */
	public static function set_subscription_item_meta( $item_id, $cart_item, $cart_item_key ) {

		if ( ! WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			_deprecated_function( __METHOD__, '2.2.0', __CLASS__ . '::add_subscription_line_item_meta( $order_item, $cart_item_key, $cart_item )' );
		}

		if ( isset( $cart_item['subscription_switch'] ) ) {
			if ( $switches = self::cart_contains_switches() ) {
				foreach ( $switches as $switch_item_key => $switch_details ) {
					if ( $cart_item_key == $switch_item_key ) {
						wc_add_order_item_meta( $item_id, '_switched_subscription_item_id', $switch_details['item_id'], true );
						wc_add_order_item_meta( $switch_details['item_id'], '_switched_subscription_new_item_id', $item_id, true );
					}
				}
			}
		}
	}

	/**
	 * Handle any subscription switch items on checkout (and before WC_Subscriptions_Checkout::process_checkout())
	 *
	 * If the item is on the same billing schedule as the old subscription (and the next payment date is the same) or the
	 * item is the only item on the subscription, the subscription item will be updated (and a note left on the order).
	 * If the item is on a new billing schedule and there are other items on the existing subscription, the old item will
	 * be removed and the new item will be added to a new subscription by @see WC_Subscriptions_Checkout::process_checkout()
	 *
	 * @param int $order_id The post_id of a shop_order post/WC_Order object
	 * @param array $posted_data The data posted on checkout
	 * @since 2.0
	 */
	public static function process_checkout( $order_id, $posted_data ) {
		global $wpdb;

		if ( ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return;
		}

		$order             = wc_get_order( $order_id );
		$order_items       = $order->get_items();
		$switch_order_data = array();

		try {

			foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

				foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {

					if ( ! isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
						continue;
					}

					$subscription  = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );
					$existing_item = wcs_get_order_item( $cart_item['subscription_switch']['item_id'], $subscription );

					// If we haven't calculated a first payment date, fall back to the recurring cart's next payment date
					if ( 0 == $cart_item['subscription_switch']['first_payment_timestamp'] ) {
						$cart_item['subscription_switch']['first_payment_timestamp'] = wcs_date_to_time( $recurring_cart->next_payment_date );
					}

					$is_different_billing_schedule = self::has_different_billing_schedule( $cart_item, $subscription );
					$is_different_payment_date     = self::has_different_payment_date( $cart_item, $subscription );
					$is_different_length           = self::has_different_length( $recurring_cart, $subscription );
					$is_single_item_subscription   = self::is_single_item_subscription( $subscription );

					$switched_item_data = array( 'remove_line_item' => $cart_item['subscription_switch']['item_id'] );

					// If the item is on the same schedule, we can just add it to the new subscription and remove the old item
					if ( $is_single_item_subscription || ( false === $is_different_billing_schedule && false === $is_different_payment_date && false === $is_different_length ) ) {

						// Add the new item
						if ( WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
							$item_id = WC_Subscriptions_Checkout::add_cart_item( $subscription, $cart_item, $cart_item_key );
							wcs_update_order_item_type( $item_id, 'line_item_pending_switch', $subscription->get_id() );
						} else {
							$item = new WC_Order_Item_Pending_Switch;
							$item->legacy_values        = $cart_item; // @deprecated For legacy actions.
							$item->legacy_cart_item_key = $cart_item_key; // @deprecated For legacy actions.
							$item->set_props( array(
								'quantity'     => $cart_item['quantity'],
								'variation'    => $cart_item['variation'],
								'subtotal'     => $cart_item['line_subtotal'],
								'total'        => $cart_item['line_total'],
								'subtotal_tax' => $cart_item['line_subtotal_tax'],
								'total_tax'    => $cart_item['line_tax'],
								'taxes'        => $cart_item['line_tax_data'],
							) );

							if ( ! empty( $cart_item['data'] ) ) {
								$product = $cart_item['data'];
								$item->set_props( array(
									'name'         => $product->get_name(),
									'tax_class'    => $product->get_tax_class(),
									'product_id'   => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
									'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
								) );
							}

							if ( WC_Subscriptions_Product::get_trial_length( wcs_get_canonical_product_id( $cart_item ) ) > 0 ) {
								$item->add_meta_data( '_has_trial', 'true' );
							}

							do_action( 'woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $cart_item, $subscription );

							$subscription->add_item( $item );

							// The subscription is not saved automatically, we need to call 'save' becaused we added an item
							$subscription->save();
							$item_id = $item->get_id();
						}

						$switched_item_data['add_line_item'] = $item_id;

						// Remove the item from the cart so that WC_Subscriptions_Checkout doesn't add it to a subscription
						if ( 1 == count( WC()->cart->recurring_carts[ $recurring_cart_key ]->get_cart() ) ) {
							// If this is the only item in the cart, clear out recurring carts so WC_Subscriptions_Checkout doesn't try to create an empty subscription
							unset( WC()->cart->recurring_carts[ $recurring_cart_key ] );
						} else {
							unset( WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ] );
						}
					}

					$switch_order_data[ $subscription->get_id() ]['switches'][ $cart_item['subscription_switch']['order_line_item_id'] ] = $switched_item_data;

					// If the old subscription has just one item, we can safely update its billing schedule
					if ( $is_single_item_subscription ) {

						if ( $is_different_billing_schedule ) {
							$switch_order_data[ $subscription->get_id() ]['billing_schedule']['_billing_period']   = WC_Subscriptions_Product::get_period( $cart_item['data'] );
							$switch_order_data[ $subscription->get_id() ]['billing_schedule']['_billing_interval'] = absint( WC_Subscriptions_Product::get_interval( $cart_item['data'] ) );
						}

						$updated_dates = array();

						if ( '1' == WC_Subscriptions_Product::get_length( $cart_item['data'] ) || ( 0 != $recurring_cart->end_date && gmdate( 'Y-m-d H:i:s', $cart_item['subscription_switch']['first_payment_timestamp'] ) >= $recurring_cart->end_date ) ) {
							// Delete the next payment date.
							$updated_dates['next_payment'] = 0;
						} else if ( $is_different_payment_date ) {
							$updated_dates['next_payment'] = gmdate( 'Y-m-d H:i:s', $cart_item['subscription_switch']['first_payment_timestamp'] );
						}

						if ( $is_different_length ) {
							$updated_dates['end'] = $recurring_cart->end_date;
						}

						if ( ! empty( $updated_dates ) ) {
							$subscription->validate_date_updates( $updated_dates );
							$switch_order_data[ $subscription->get_id() ]['dates']['update'] = $updated_dates;
						}
					}

					// Add the shipping
					// Keep a record of the current shipping line items so we can flip any new shipping items to a _pending_switch shipping item.
					$current_shipping_line_items = array_keys( $subscription->get_shipping_methods() );
					$new_shipping_line_items     = array();

					// Keep a record of the subscription shipping total. Adding shipping methods will cause a new shipping total to be set, we'll need to set it back after.
					$subscription_shipping_total = $subscription->get_total_shipping();

					WC_Subscriptions_Checkout::add_shipping( $subscription, $recurring_cart );

					if ( ! WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
						// We must save the subscription, we need the Shipping method saved
						// otherwise the ID is bogus (new:1) and we need it.
						$subscription->save();
					}

					// Set all new shipping methods to shipping_pending_switch line items
					foreach ( $subscription->get_shipping_methods() as $shipping_line_item_id => $shipping_meta ) {

						if ( ! in_array( $shipping_line_item_id, $current_shipping_line_items ) ) {
							wcs_update_order_item_type( $shipping_line_item_id, 'shipping_pending_switch', $subscription->get_id() );
							$new_shipping_line_items[] = $shipping_line_item_id;
						}
					}

					if ( WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
						$subscription->set_total( $subscription_shipping_total, 'shipping' );
					} else {
						$subscription->set_shipping_total( $subscription_shipping_total );
					}

					$switch_order_data[ $subscription->get_id() ]['shipping_line_items'] = $new_shipping_line_items;
				}
			}

			foreach ( $switch_order_data as $subscription_id => $switch_data ) {

				// Cancel all the switch orders linked to the switched subscription(s) which haven't been completed yet - excluding this one.
				$switch_orders = wcs_get_switch_orders_for_subscription( $subscription_id );

				foreach ( $switch_orders as $switch_order_id => $switch_order ) {
					if ( wcs_get_objects_property( $order, 'id' ) !== $switch_order_id && in_array( $switch_order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed', 'on-hold' ), $switch_order ) ) ) {
						$switch_order->update_status( 'cancelled', sprintf( __( 'Switch order cancelled due to a new switch order being created #%s.', 'woocommerce-subscriptions' ), $order->get_order_number() ) );
					}
				}
			}

			wcs_set_objects_property( $order, 'subscription_switch_data', $switch_order_data );

		} catch ( Exception $e ) {
			// There was an error updating the subscription, roll back and delete pending order for switch
			$wpdb->query( 'ROLLBACK' );
			wp_delete_post( $order_id, true );
			throw $e;
		}
	}

	/**
	 * Update shipping method on the subscription if the order changed anything
	 *
	 * @param  WC_Order $order The new order
	 * @param  WC_Subscription $subscription The original subscription
	 * @param  WC_Cart $recurring_cart A recurring cart
	 */
	public static function update_shipping_methods( $subscription, $recurring_cart ) {

		// First, archive all the shipping methods
		foreach ( $subscription->get_shipping_methods() as $shipping_method_id => $shipping_method ) {
			wcs_update_order_item_type( $shipping_method_id, 'shipping_switched', $subscription->get_id() );
		}

		// Then zero the order_shipping total so we have a clean slate to add to
		$subscription->set_total_shipping( 0 );

		WC_Subscriptions_Checkout::add_shipping( $subscription, $recurring_cart );

		// Now update subscription object order_shipping to reflect updated values so it doesn't stay 0
		$subscription->order_shipping = get_post_meta( $subscription->get_id(), '_order_shipping', true );
	}

	/**
	 * Updates address on the subscription if one of them is changed.
	 *
	 * @param  WC_Order $order The new order
	 * @param  WC_Subscription $subscription The original subscription
	 */
	public static function maybe_update_subscription_address( $order, $subscription ) {

		if ( method_exists( $subscription, 'get_address' ) ) {

			$order_billing         = $order->get_address( 'billing' );
			$order_shipping        = $order->get_address();
			$subscription_billing  = $subscription->get_address( 'billing' );
			$subscription_shipping = $subscription->get_address();

		} else {

			$order_billing         = wcs_get_order_address( $order, 'billing' );
			$order_shipping        = wcs_get_order_address( $order );
			$subscription_billing  = wcs_get_order_address( $subscription, 'billing' );
			$subscription_shipping = wcs_get_order_address( $subscription );

		}

		$subscription->set_address( array_diff_assoc( $order_billing, $subscription_billing ), 'billing' );
		$subscription->set_address( array_diff_assoc( $order_shipping, $subscription_shipping ), 'shipping' );

	}

	/**
	 * If the subscription purchased in an order has since been switched, include a link to the order placed to switch the subscription
	 * in the "Related Orders" meta box (displayed on the Edit Order screen).
	 *
	 * @param WC_Order $order The current order.
	 * @since 1.4
	 */
	public static function switch_order_meta_box_rows( $post ) {

		$subscriptions          = array();
		$switched_subscriptions = array();
		$orders                 = array();

		// On the subscription page, just show related orders
		if ( wcs_is_subscription( $post->ID ) ) {

			// Select the orders which switched item/s from this subscription
			$orders = wcs_get_switch_orders_for_subscription( $post->ID );

			foreach ( $orders as $order_id => $order ) {
				wcs_set_objects_property( $order, 'relationship', __( 'Switch Order', 'woocommerce-subscriptions' ), 'set_prop_only' );
			}

			// Select the subscriptions which had item/s switched to this subscription by its parent order
			if ( ! empty( $post->post_parent ) ) {
				$switched_subscriptions = wcs_get_subscriptions_for_switch_order( $post->post_parent );
			}

		// On the Edit Order screen, show any subscriptions with items switched by this order
		} else {
			$switched_subscriptions = wcs_get_subscriptions_for_switch_order( $post->ID );
		}

		if ( is_array( $switched_subscriptions ) ) {
			foreach ( $switched_subscriptions as $subscription_id => $subscription ) {
				wcs_set_objects_property( $subscription, 'relationship', __( 'Switched Subscription', 'woocommerce-subscriptions' ), 'set_prop_only' );
				$orders[ $subscription_id ] = $subscription;
			}
		}

		foreach ( $orders as $order ) {
			include( plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'includes/admin/meta-boxes/views/html-related-orders-row.php' );
		}

	}

	/**
	 * Check if the cart includes any items which are to switch an existing subscription's item.
	 *
	 * @return bool|array Returns all the items that are for a switching or false if none of the items in the cart are a switch request.
	 * @since 2.0
	 */
	public static function cart_contains_switches() {

		$subscription_switches = false;

		if ( is_admin() && ( ! defined( 'DOING_AJAX' ) || false == DOING_AJAX ) ) {
			return $subscription_switches;
		}

		if ( isset( WC()->cart ) ) {
			// We use WC()->cart->cart_contents instead of WC()->cart->get_cart() to prevent recursion caused when get_cart_from_session() too early is called ref: https://github.com/woocommerce/woocommerce/commit/1f3365f2066b1e9d7e84aca7b1d7e89a6989c213
			foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
				if ( isset( $cart_item['subscription_switch'] ) ) {
					if ( wcs_is_subscription( $cart_item['subscription_switch']['subscription_id'] ) ) {
						$subscription_switches[ $cart_item_key ] = $cart_item['subscription_switch'];
					} else {
						WC()->cart->remove_cart_item( $cart_item_key );
						wc_add_notice( __( 'Your cart contained an invalid subscription switch request. It has been removed.', 'woocommerce-subscriptions' ), 'error' );
					}
				}
			}
		}

		return $subscription_switches;
	}

	/**
	 * Check if the cart includes any items which are to switch an existing subscription's item.
	 *
	 * @param int|object Either a product ID (not variation ID) or product object
	 * @return bool True if the cart contains a switch fora  given product, or false if it does not.
	 * @since 2.0
	 */
	public static function cart_contains_switch_for_product( $product ) {

		$product_id         = ( is_object( $product ) ) ? $product->get_id() : $product;
		$switch_items       = self::cart_contains_switches();
		$switch_product_ids = array();

		if ( false !== $switch_items ) {

			// Check if there is a switch for this variation product
			foreach ( $switch_items as $switch_item_details ) {

				$switch_product  = wc_get_product( wcs_get_order_items_product_id( $switch_item_details['item_id'] ) );
				$parent_products = WC_Subscriptions_Product::get_parent_ids( $product );

				// If the switch is for a grouped product, we need to check the other products grouped with this one
				if ( $parent_products ) {
					foreach ( $parent_products as $parent_id ) {
						$switch_product_ids = array_unique( array_merge( $switch_product_ids, wc_get_product( $parent_id )->get_children() ) );
					}
				} elseif ( $switch_product->is_type( 'subscription_variation' ) ) {
					$switch_product_ids[] = $switch_product->get_parent_id();
				} else {
					$switch_product_ids[] = $switch_product->get_id();
				}
			}
		}

		return in_array( $product_id, $switch_product_ids );
	}

	/**
	 * When a product is added to the cart, check if it is being added to switch a subscription and if so,
	 * make sure it's valid (i.e. not the same subscription).
	 *
	 * @since 1.4
	 */
	public static function validate_switch_request( $is_valid, $product_id, $quantity, $variation_id = '' ) {

		$error_message = '';

		try {

			if ( ! isset( $_GET['switch-subscription'] ) ) {
				return $is_valid;
			}

			if ( empty( $_GET['_wcsnonce'] ) || ! wp_verify_nonce( $_GET['_wcsnonce'], 'wcs_switch_request' ) ) {
				return false;
			}

			$subscription = wcs_get_subscription( $_GET['switch-subscription'] );
			$item_id      = absint( $_GET['item'] );
			$item         = wcs_get_order_item( $item_id, $subscription );

			// Prevent switching to non-subscription product
			if ( ! WC_Subscriptions_Product::is_subscription( $product_id ) ) {
				throw new Exception( __( 'You can only switch to a subscription product.', 'woocommerce-subscriptions' ) );
			}

			// Check if the chosen variation's attributes are different to the existing subscription's attributes (to support switching between a "catch all" variation)
			if ( empty( $item ) ) {

				throw new Exception( __( 'We can not find your old subscription item.', 'woocommerce-subscriptions' ) );

			} else {

				$identical_attributes = true;

				foreach ( $_POST as $key => $value ) {
					if ( false !== strpos( $key, 'attribute_' ) && ! empty( $item[ str_replace( 'attribute_', '', $key ) ] ) && $item[ str_replace( 'attribute_', '', $key ) ] != $value ) {
						$identical_attributes = false;
						break;
					}
				}

				if ( $product_id == $item['product_id'] && ( empty( $variation_id ) || ( $variation_id == $item['variation_id'] && true == $identical_attributes ) ) && $quantity == $item['qty'] ) {
					$is_identical_product = true;
				} else {
					$is_identical_product = false;
				}

				$is_identical_product = apply_filters( 'woocommerce_subscriptions_switch_is_identical_product', $is_identical_product, $product_id, $quantity, $variation_id, $subscription, $item );

				if ( $is_identical_product ) {
					throw new Exception( __( 'You can not switch to the same subscription.', 'woocommerce-subscriptions' ) );
				}

				// Also remove any existing items in the cart for switching this item (but don't make the switch invalid)
				if ( $is_valid ) {

					$existing_switch_items = self::cart_contains_switches();

					if ( false !== $existing_switch_items ) {
						foreach ( $existing_switch_items as $cart_item_key => $switch_item ) {
							if ( $switch_item['item_id'] == $item_id ) {
								WC()->cart->remove_cart_item( $cart_item_key );
							}
						}
					}
				}
			}
		} catch ( Exception $e ) {
			$error_message = $e->getMessage();
		}

		$error_message = apply_filters( 'woocommerce_subscriptions_switch_error_message', $error_message, $product_id, $quantity, $variation_id, $subscription, $item );

		if ( ! empty( $error_message ) ) {
			wc_add_notice( $error_message, 'error' );
			$is_valid = false;
		}

		return apply_filters( 'woocommerce_subscriptions_is_switch_valid', $is_valid, $product_id, $quantity, $variation_id, $subscription, $item );
	}

	/**
	 * When a subscription switch is added to the cart, store a record of pertinent meta about the switch.
	 *
	 * @since 1.4
	 */
	public static function set_switch_details_in_cart( $cart_item_data, $product_id, $variation_id ) {

		try {
			if ( ! isset( $_GET['switch-subscription'] ) ) {
				return $cart_item_data;
			}

			$subscription = wcs_get_subscription( $_GET['switch-subscription'] );

			// Requesting a switch for someone elses subscription
			if ( ! current_user_can( 'switch_shop_subscription', $subscription->get_id() ) ) {
				wc_add_notice( __( 'You can not switch this subscription. It appears you do not own the subscription.', 'woocommerce-subscriptions' ), 'error' );
				WC()->cart->empty_cart( true );
				wp_redirect( get_permalink( $subscription['product_id'] ) );
				exit();
			}

			$item = wcs_get_order_item( absint( $_GET['item'] ), $subscription );

			// Else it's a valid switch
			$product         = wc_get_product( $item['product_id'] );
			$parent_products = WC_Subscriptions_Product::get_parent_ids( $product );
			$child_products  = array();

			if ( ! empty( $parent_products ) ) {
				foreach ( $parent_products as $parent_id ) {
					$child_products = array_unique( array_merge( $child_products, wc_get_product( $parent_id )->get_children() ) );
				}
			}

			if ( $product_id != $item['product_id'] && ! in_array( $item['product_id'], $child_products ) ) {
				return $cart_item_data;
			}

			$next_payment_timestamp = $subscription->get_time( 'next_payment' );

			// If there are no more payments due on the subscription, because we're in the last billing period, we need to use the subscription's expiration date, not next payment date
			if ( false == $next_payment_timestamp ) {
				$next_payment_timestamp = $subscription->get_time( 'end' );
			}

			$cart_item_data['subscription_switch'] = array(
				'subscription_id'         => $subscription->get_id(),
				'item_id'                 => absint( $_GET['item'] ),
				'next_payment_timestamp'  => $next_payment_timestamp,
				'upgraded_or_downgraded'  => '',
			);

			return $cart_item_data;

		} catch ( Exception $e ) {

			wc_add_notice( __( 'There was an error locating the switch details.', 'woocommerce-subscriptions' ), 'error' );
			WC()->cart->empty_cart( true );
			wp_redirect( get_permalink( wc_get_page_id( 'cart' ) ) );
			exit();
		}
	}

	/**
	 * Get the recurring amounts values from the session
	 *
	 * @since 1.4
	 */
	public static function get_cart_from_session( $cart_item_data, $cart_item, $key ) {
		if ( isset( $cart_item['subscription_switch'] ) ) {
			$cart_item_data['subscription_switch'] = $cart_item['subscription_switch'];
		}

		return $cart_item_data;
	}

	/**
	 * Make sure the sign-up fee on a subscription line item takes into account sign-up fees paid for switching.
	 *
	 * @param WC_Subscription $subscription
	 * @param string $tax_inclusive_or_exclusive Defaults to the value tax setting stored on the subscription.
	 * @return array $cart_item Details of an item in WC_Cart for a switch
	 * @since 2.0
	 */
	public static function subscription_items_sign_up_fee( $sign_up_fee, $line_item, $subscription, $tax_inclusive_or_exclusive = '' ) {

		// This item has never been switched, no need to add anything
		if ( ! isset( $line_item['switched_subscription_item_id'] ) ) {
			return $sign_up_fee;
		}

		// First add any sign-up fees for previously switched items
		$switched_line_items = $subscription->get_items( 'line_item_switched' );

		// Default tax inclusive or exclusive to the value set on the subscription. This is for backwards compatibility
		if ( empty( $tax_inclusive_or_exclusive ) ) {
			$tax_inclusive_or_exclusive = ( $subscription->get_prices_include_tax() ) ? 'inclusive_of_tax' : 'exclusive_of_tax';
		}

		foreach ( $switched_line_items as $switched_line_item_id => $switched_line_item ) {
			if ( $line_item['switched_subscription_item_id'] == $switched_line_item_id ) {
				$sign_up_fee += $subscription->get_items_sign_up_fee( $switched_line_item, $tax_inclusive_or_exclusive ); // Recursion: get the sign up fee for this item's old item and the sign up fee for that item's old item and the sign up fee for that item's old item and the sign up fee for that item's old item ...
				break; // Each item can only be switched once
			}
		}

		// Now add any sign-up fees paid in switch orders
		foreach ( wcs_get_switch_orders_for_subscription( $subscription->get_id() ) as $order ) {
			foreach ( $order->get_items() as $order_item_id => $order_item ) {
				if ( wcs_get_canonical_product_id( $line_item ) == wcs_get_canonical_product_id( $order_item ) ) {

					// We only want to add the amount of the line total which was for a prorated sign-up fee, not the amount for a prorated recurring amount
					if ( isset( $order_item['switched_subscription_sign_up_fee_prorated'] ) ) {
						if ( $order_item['switched_subscription_sign_up_fee_prorated'] > 0 ) {
							$sign_up_proportion = $order_item['switched_subscription_sign_up_fee_prorated'] / ( $order_item['switched_subscription_price_prorated'] + $order_item['switched_subscription_sign_up_fee_prorated'] );
						} else {
							$sign_up_proportion = 0;
						}
					} else {
						$sign_up_proportion = 1;
					}

					$order_total = $order_item['line_total'];

					if ( 'inclusive_of_tax' == $tax_inclusive_or_exclusive && wcs_get_objects_property( $order, 'prices_include_tax' ) ) {
						$order_total += $order_item['line_tax'];
					}

					$sign_up_fee += round( $order_total * $sign_up_proportion, 2 );
				}
			}
		}

		return $sign_up_fee;
	}

	/**
	 * Set the subscription prices to be used in calculating totals by @see WC_Subscriptions_Cart::calculate_subscription_totals()
	 *
	 * @since 2.0
	 */
	public static function calculate_prorated_totals( $cart ) {

		if ( false === self::cart_contains_switches() ) {
			return;
		}

		// Maybe charge an initial amount to account for upgrading from a cheaper subscription
		$apportion_recurring_price = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_recurring_price', 'no' );
		$apportion_sign_up_fee     = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee', 'no' );
		$apportion_length          = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_length', 'no' );

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

			if ( ! isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
				continue;
			}

			$subscription       = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );
			$existing_item      = wcs_get_order_item( $cart_item['subscription_switch']['item_id'], $subscription );

			if ( empty( $existing_item ) ) {
				WC()->cart->remove_cart_item( $cart_item_key );
				continue;
			}

			$item_data          = $cart_item['data'];
			$product_id         = wcs_get_canonical_product_id( $cart_item );
			$product            = wc_get_product( $product_id );
			$is_virtual_product = $product->is_virtual();

			// Set when the first payment and end date for the new subscription should occur
			WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $cart_item['subscription_switch']['next_payment_timestamp'];
			WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['end_timestamp'] = $end_timestamp = wcs_date_to_time( WC_Subscriptions_Product::get_expiration_date( $product_id, $subscription->get_date( 'last_order_date_created' ) ) );

			// Add any extra sign up fees required to switch to the new subscription
			if ( 'yes' == $apportion_sign_up_fee ) {

				// With WC 3.0, make sure we get a fresh copy of the product's meta to avoid prorating an already prorated sign-up fee
				if ( is_callable( array( $product, 'read_meta_data' ) ) ) {
					$product->read_meta_data( true );
				}

				// Because product add-ons etc. don't apply to sign-up fees, it's safe to use the product's sign-up fee value rather than the cart item's
				$sign_up_fee_due  = WC_Subscriptions_Product::get_sign_up_fee( $product );
				$sign_up_fee_paid = $subscription->get_items_sign_up_fee( $existing_item, get_option( 'woocommerce_prices_include_tax' ) === 'yes' ? 'inclusive_of_tax' : 'exclusive_of_tax' );

				// Make sure total prorated sign-up fee is prorated across total amount of sign-up fee so that customer doesn't get extra discounts
				if ( $cart_item['quantity'] > $existing_item['qty'] ) {
					$sign_up_fee_paid = ( $sign_up_fee_paid * $existing_item['qty'] ) / $cart_item['quantity'];
				}

				wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee', max( $sign_up_fee_due - $sign_up_fee_paid, 0 ), 'set_prop_only' );
				wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee_prorated', WC_Subscriptions_Product::get_sign_up_fee( WC()->cart->cart_contents[ $cart_item_key ]['data'] ), 'set_prop_only' );

			} elseif ( 'no' == $apportion_sign_up_fee ) { // $0 the initial sign-up fee

				wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee', 0, 'set_prop_only' );

			}

			// Get the current subscription's last payment date
			$last_order_time_created = $subscription->get_time( 'last_order_date_created' );
			$days_since_last_payment = floor( ( gmdate( 'U' ) - $last_order_time_created ) / ( 60 * 60 * 24 ) );

			// Get the current subscription's next payment date
			$next_payment_timestamp  = $cart_item['subscription_switch']['next_payment_timestamp'];
			$days_until_next_payment = ceil( ( $next_payment_timestamp - gmdate( 'U' ) ) / ( 60 * 60 * 24 ) );

			// If the subscription contains a synced product and the next payment is actually the first payment, determine the days in the "old" cycle from the subscription object
			if ( WC_Subscriptions_Synchroniser::subscription_contains_synced_product( $subscription->get_id() ) && WC_Subscriptions_Synchroniser::calculate_first_payment_date( $product, 'timestamp', $subscription->get_date( 'date_created' ) ) == $next_payment_timestamp ) {
				$days_in_old_cycle = wcs_get_days_in_cycle( $subscription->get_billing_period(), $subscription->get_billing_interval() );
			} else {
				// Find the number of days between the two
				$days_in_old_cycle = $days_until_next_payment + $days_since_last_payment;
			}

			// Find the actual recurring amount charged for the old subscription (we need to use the '_recurring_line_total' meta here rather than '_subscription_recurring_amount' because we want the recurring amount to include extra from extensions, like Product Add-ons etc.)
			$old_recurring_total = $existing_item['line_total'];

			// Use previous parent or renewal order's actual line item total instead of what is due, to guard against not yet paid amounts in multi-switching
			$last_order = $subscription->get_last_order( 'all' );
			$last_order_items = $last_order->get_items();
			foreach ( $last_order_items as $last_order_item ) {
				if ( wcs_get_canonical_product_id( $last_order_item ) == $product_id ) {
					$old_recurring_total = $last_order_item['line_total'];
					break;
				}
			}

			if ( $subscription->get_prices_include_tax() ) {
				$old_recurring_total += $existing_item['line_tax'];
			}

			// Find the $price per day for the old subscription's recurring total
			$old_price_per_day = $days_in_old_cycle > 0 ? $old_recurring_total / $days_in_old_cycle : $old_recurring_total;

			// Find the price per day for the new subscription's recurring total based on billing schedule
			$days_in_new_cycle = wcs_get_days_in_cycle( WC_Subscriptions_Product::get_period( $item_data ), WC_Subscriptions_Product::get_interval( $item_data ) );

			// Whether the days in new cycle match the days in old,ignoring any rounding.
			$days_in_new_and_old_cycle_match = ceil( $days_in_new_cycle ) == $days_in_old_cycle || floor( $days_in_new_cycle ) == $days_in_old_cycle;

			// Whether the new item uses the same billing interval & cycle as the old subscription,
			$matching_billing_cycle = WC_Subscriptions_Product::get_period( $item_data ) == $subscription->get_billing_period() && WC_Subscriptions_Product::get_interval( $item_data ) == $subscription->get_billing_interval();
			$switch_during_trial    = $subscription->get_time( 'trial_end' ) > gmdate( 'U' );

			// Set the days in each cycle to match if they are equal (ignoring any rounding discrepancy) or if the subscription is switched during a trial and has a matching billing cycle.
			if ( $days_in_new_and_old_cycle_match || ( $matching_billing_cycle && $switch_during_trial ) ) {
				$days_in_new_cycle = $days_in_old_cycle;
			}

			// We need to use the cart items price to ensure we include extras added by extensions like Product Add-ons, but we don't want the sign-up fee accounted for in the price, so make sure WC_Subscriptions_Cart::set_subscription_prices_for_calculation() isn't adding that.
			remove_filter( 'woocommerce_product_get_price', 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation', 100 );
			$new_price_per_day = ( WC_Subscriptions_Product::get_price( $item_data ) * $cart_item['quantity'] ) / $days_in_new_cycle;
			add_filter( 'woocommerce_product_get_price', 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation', 100, 2 );

			if ( $old_price_per_day < $new_price_per_day ) {

				WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['upgraded_or_downgraded'] = 'upgraded';

			} elseif ( $old_price_per_day > $new_price_per_day && $new_price_per_day >= 0 ) {

				WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['upgraded_or_downgraded'] = 'downgraded';

			}

			// Now lets see if we should add a prorated amount to the sign-up fee (for upgrades) or extend the next payment date (for downgrades)
			if ( in_array( $apportion_recurring_price, array( 'yes', 'yes-upgrade' ) ) || ( in_array( $apportion_recurring_price, array( 'virtual', 'virtual-upgrade' ) ) && $is_virtual_product ) ) {

				// If the customer is upgrading, we may need to add a gap payment to the sign-up fee or to reduce the pre-paid period (or both)
				if ( $old_price_per_day < $new_price_per_day ) {

					// The new subscription may be more expensive, but it's also on a shorter billing cycle, so reduce the next pre-paid term
					if ( $days_in_old_cycle > $days_in_new_cycle ) {

						// Find out how many days at the new price per day the customer would receive for the total amount already paid
						// (e.g. if the customer paid $10 / month previously, and was switching to a $5 / week subscription, she has pre-paid 14 days at the new price)
						$pre_paid_days = self::calculate_pre_paid_days( $old_recurring_total, $new_price_per_day );

						// If the total amount the customer has paid entitles her to more days at the new price than she has received, there is no gap payment, just shorten the pre-paid term the appropriate number of days
						if ( $days_since_last_payment < $pre_paid_days ) {

							WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $last_order_time_created + ( $pre_paid_days * 60 * 60 * 24 );

						// If the total amount the customer has paid entitles her to the same or less days at the new price then start the new subscription from today
						} else {

							WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = 0;

						}
					} else {

						// If we've already calculated the prorated price recalculate the amounts but reset the values so we don't double the amounts
						if ( 0 < wcs_get_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_price_prorated', 'single', 0 ) ) {
							$prorated_signup_fee = wcs_get_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee_prorated', 'single' );
							wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee', $prorated_signup_fee, 'set_prop_only' );
						}

						$extra_to_pay = $days_until_next_payment * ( $new_price_per_day - $old_price_per_day );

						// when calculating a subscription with one length (no more next payment date and the end date may have been pushed back) we need to pay for those extra days at the new price per day between the old next payment date and new end date
						if ( 1 == WC_Subscriptions_Product::get_length( $item_data ) ) {
							$days_to_new_end = floor( ( $end_timestamp - $next_payment_timestamp ) / ( 60 * 60 * 24 ) );

							if ( $days_to_new_end > 0 ) {
								$extra_to_pay += $days_to_new_end * $new_price_per_day;
							}
						}

						// We need to find the per item extra to pay so we can set it as the sign-up fee (WC will then multiply it by the quantity)
						$extra_to_pay = $extra_to_pay / $cart_item['quantity'];

						// Keep a record of the two separate amounts so we store these and calculate future switch amounts correctly
						$existing_sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee( WC()->cart->cart_contents[ $cart_item_key ]['data'] );
						wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee_prorated', $existing_sign_up_fee, 'set_prop_only' );
						wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_price_prorated', round( $extra_to_pay, wc_get_price_decimals() ), 'set_prop_only' );
						wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_sign_up_fee', round( $existing_sign_up_fee + $extra_to_pay, wc_get_price_decimals() ), 'set_prop_only' );
					}

				// If the customer is downgrading, set the next payment date and maybe extend it if downgrades are prorated
				} elseif ( $old_price_per_day > $new_price_per_day && $new_price_per_day > 0 ) {

					$old_total_paid = $old_price_per_day * $days_until_next_payment;

					// if downgrades are apportioned, extend the next payment date for n more days
					if ( in_array( $apportion_recurring_price, array( 'virtual', 'yes' ) ) ) {

						// Find how many more days at the new lower price it takes to exceed the amount already paid
						$days_to_add = self::calculate_pre_paid_days( $old_total_paid, $new_price_per_day );

						$days_to_add -= $days_until_next_payment;
					} else {
						$days_to_add = 0;
					}

					WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $next_payment_timestamp + ( $days_to_add * 60 * 60 * 24 );

				} // The old price per day == the new price per day, no need to change anything

				if ( WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] != $cart_item['subscription_switch']['next_payment_timestamp'] ) {
					WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['recurring_payment_prorated'] = true;
				}
			}

			if ( 'yes' == $apportion_length || ( 'virtual' == $apportion_length && $is_virtual_product ) ) {

				$base_length        = WC_Subscriptions_Product::get_length( $product_id );
				$completed_payments = $subscription->get_completed_payment_count();
				$length_remaining   = $base_length - $completed_payments;

				// Default to the base length if more payments have already been made than this subscription requires
				if ( $length_remaining <= 0 ) {
					$length_remaining = $base_length;
				}

				wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_length', $length_remaining, 'set_prop_only' );
			}
		}
	}

	/**
	* Calculate the number of days that have already been paid
	*
	* @param int $old_total_paid The amount paid previously, such as the old recurring total
	* @param int $new_price_per_day The amount per day price for the new subscription
	* @return int $pre_paid_days The number of days paid for already
	*/
	private static function calculate_pre_paid_days( $old_total_paid, $new_price_per_day ) {
		$pre_paid_days = 0;
		if ( 0 != $new_price_per_day ) {
			$pre_paid_days = ceil( $old_total_paid / $new_price_per_day );
		}
		return $pre_paid_days;
	}

	/**
	 * Make sure when displaying the first payment date for a switched subscription, the date takes into
	 * account the switch (i.e. prepaid days and possibly a downgrade).
	 *
	 * @since 2.0
	 */
	public static function recurring_cart_next_payment_date( $first_renewal_date, $cart ) {

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) && 0 != $cart_item['subscription_switch']['first_payment_timestamp'] ) {
				$first_renewal_date = ( '1' != WC_Subscriptions_Product::get_length( $cart_item['data'] ) ) ? gmdate( 'Y-m-d H:i:s', $cart_item['subscription_switch']['first_payment_timestamp'] ) : 0;
			}
		}

		return $first_renewal_date;
	}

	/**
	 * Make sure the end date of the switched subscription starts after already paid term
	 *
	 * @since 2.0
	 */
	public static function recurring_cart_end_date( $end_date, $cart, $product ) {

		if ( 0 !== $end_date ) {
			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

				if ( isset( $cart_item['subscription_switch']['subscription_id'] ) && isset( $cart_item['data'] ) && $product == $cart_item['data'] ) {
					$next_payment_time = isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) ? $cart_item['subscription_switch']['first_payment_timestamp'] : 0;
					$end_timestamp     = WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['end_timestamp'];

					// if the subscription is length 1 and prorated, we want to use the prorated the next payment date as the end date
					if ( 1 == WC_Subscriptions_Product::get_length( $cart_item['data'] ) && 0 !== $next_payment_time && isset( $cart_item['subscription_switch']['recurring_payment_prorated'] ) ) {
						$end_date = gmdate( 'Y-m-d H:i:s', $next_payment_time );

					// if the subscription is more than 1 (and not 0) and we have a next payment date (prorated or not) we want to calculate the new end date from that
					} elseif ( 0 !== $next_payment_time && WC_Subscriptions_Product::get_length( $cart_item['data'] ) > 1 ) {
						// remove trial period on the switched subscription when calculating the new end date
						$trial_length = wcs_get_objects_property( $cart_item['data'], 'subscription_trial_length' );
						wcs_set_objects_property( $cart_item['data'], 'subscription_trial_length', 0, 'set_prop_only' );

						$end_date = WC_Subscriptions_Product::get_expiration_date( $cart_item['data'], gmdate( 'Y-m-d H:i:s', $next_payment_time ) );

						// add back the trial length if it has been spoofed
						wcs_set_objects_property( $cart_item['data'], 'subscription_trial_length', $trial_length, 'set_prop_only' );

					// elseif fallback to using the end date set on the cart item
					} elseif ( ! empty( $end_timestamp ) ) {
						$end_date = gmdate( 'Y-m-d H:i:s', $end_timestamp );
					}

					break;
				}
			}
		}
		return $end_date;
	}

	/**
	 * Make sure that a switch items cart key is based on it's first renewal date, not the date calculated for the product.
	 *
	 * @since 2.0
	 */
	public static function get_recurring_cart_key( $cart_key, $cart_item ) {

		if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) ) {
			remove_filter( 'woocommerce_subscriptions_recurring_cart_key', __METHOD__, 10 );
			$cart_key = WC_Subscriptions_Cart::get_recurring_cart_key( $cart_item, $cart_item['subscription_switch']['first_payment_timestamp'] );
			add_filter( 'woocommerce_subscriptions_recurring_cart_key', __METHOD__, 10, 2 );
		}

		// Append switch data to the recurring cart key so switch items are separated from other subscriptions in the cart. Switch items are processed through the checkout separately so should have separate recurring carts.
		if ( isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
			$cart_key .= '_switch_' . $cart_item['subscription_switch']['subscription_id'];
		}

		return $cart_key;
	}

	/**
	 * If the current request is to switch subscriptions, don't show a product's free trial period (because there is no
	 * free trial for subscription switches) and also if the length is being prorateed, don't display the length until
	 * checkout.
	 *
	 * @since 1.4
	 */
	public static function customise_product_string_inclusions( $inclusions, $product ) {

		if ( isset( $_GET['switch-subscription'] ) || self::cart_contains_switch_for_product( $product ) ) {

			$inclusions['trial_length'] = false;

			$apportion_length      = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_length', 'no' );
			$apportion_sign_up_fee = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee', 'no' );

			if ( 'yes' == $apportion_length || ( 'virtual' == $apportion_length && $product->is_virtual() ) ) {
				$inclusions['subscription_length'] = false;
			}

			if ( 'no' === $apportion_sign_up_fee ) {
				$inclusions['sign_up_fee'] = false;
			}
		}

		return $inclusions;
	}

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to switch the subscription, then mark it as purchasable.
	 *
	 * @since 1.4.4
	 * @return bool
	 * @deprecated 2.1
	 */
	public static function is_purchasable( $is_purchasable, $product ) {
		_deprecated_function( __METHOD__, '2.1', 'WCS_Limiter::is_purchasable_switch' );
		return WCS_Limiter::is_purchasable_switch( $is_purchasable, $product );
	}

	/**
	 * Do not carry over switch related meta data to renewal orders.
	 *
	 * @since 1.5.4
	 */
	public static function remove_renewal_order_meta_query( $order_meta_query ) {

		$order_meta_query .= " AND `meta_key` NOT IN ('_subscription_switch')";

		return $order_meta_query;
	}

	/**
	 * Make the switch process persist even if the subscription product has Product Addons that need to be set.
	 *
	 * @since 1.5.6
	 */
	public static function addons_add_to_cart_url( $add_to_cart_url ) {

		if ( isset( $_GET['switch-subscription'] ) && false === strpos( $add_to_cart_url, 'switch-subscription' ) ) {
			$add_to_cart_url = self::add_switch_query_args( $_GET['switch-subscription'], $_GET['item'], $add_to_cart_url );
		}

		return $add_to_cart_url;
	}

	/**
	 * Completes subscription switches on completed order status changes.
	 *
	 * Commits all the changes calculated and saved by @see WC_Subscriptions_Switcher::process_checkout(), updating subscription
	 * line items, schedule, dates and totals to reflect the changes made in this switch order.
	 *
	 * @param int $order_id The post_id of a shop_order post/WC_Order object
	 * @param array $order_old_status The old order status
	 * @param array $order_new_status The new order status
	 * @since 2.1
	 */
	public static function process_subscription_switches( $order_id, $order_old_status, $order_new_status ) {
		global $wpdb;

		$order            = wc_get_order( $order_id );
		$switch_processed = wcs_get_objects_property( $order, 'completed_subscription_switch' );

		if ( ! wcs_order_contains_switch( $order ) || 'true' == $switch_processed ) {
			return;
		}

		$order_completed = in_array( $order_new_status, array( apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order_id, $order ), 'processing', 'completed' ) );

		if ( $order_completed ) {
			try {
				// Start transaction if available
				$wpdb->query( 'START TRANSACTION' );

				self::complete_subscription_switches( $order );

				wcs_set_objects_property( $order, 'completed_subscription_switch', 'true' );

				$wpdb->query( 'COMMIT' );

			} catch ( Exception $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}

			do_action( 'woocommerce_subscriptions_switch_completed', $order );
		}
	}

	/**
	 * Checks if a product can be switched based on it's type and the types which can be switched
	 *
	 * @since 1.5.21
	 */
	public static function is_product_of_switchable_type( $product ) {

		_deprecated_function( __METHOD__, '2.0.7', 'wcs_is_product_switchable_type' );

		$allow_switching = false;
		$switch_setting  = get_option( WC_Subscriptions_Admin::$option_prefix . '_allow_switching', 'no' );

		// does the current switch setting allow switching for variable or variable_grouped
		if ( 'variable_grouped' == $switch_setting || ( $product->is_type( array( 'variable-subscription', 'subscription_variation' ) ) && 'variable' == $switch_setting ) || ( 'grouped' == $switch_setting && ( $product->is_type( 'grouped' ) || wcs_get_objects_property( $product, 'parent_id' ) ) ) ) {
			$allow_switching = true;
		}

		return $allow_switching;
	}

	/**
	 * Check if a given subscription item was for upgrading/downgrading an existing item.
	 *
	 * @since 2.0
	 */
	protected static function is_item_switched( $item ) {
		return isset( $item['switched'] );
	}

	/**
	 * Do not display switch related order item meta keys unless Subscriptions is in debug mode.
	 *
	 * @since 2.0
	 */
	public static function hidden_order_itemmeta( $hidden_meta_keys ) {

		if ( apply_filters( 'woocommerce_subscriptions_hide_switch_itemmeta', ! defined( 'WCS_DEBUG' ) || true !== WCS_DEBUG ) ) {
			$hidden_meta_keys = array_merge( $hidden_meta_keys, array(
				'_switched_subscription_item_id',
				'_switched_subscription_new_item_id',
				'_switched_subscription_sign_up_fee_prorated',
				'_switched_subscription_price_prorated',
				)
			);
		}

		return $hidden_meta_keys;
	}

	/**
	 * Stop the switch link from printing on email templates
	 *
	 * @since 2.0
	 */
	public static function remove_print_switch_link() {
		remove_filter( 'woocommerce_order_item_meta_end', __CLASS__ . '::print_switch_link', 10 );
	}

	/**
	 * Add the print switch link filter back after the subscription items table has been created in email template
	 *
	 * @since 2.0
	 */
	public static function add_print_switch_link( $table_content ) {
		add_filter( 'woocommerce_order_item_meta_end', __CLASS__ . '::print_switch_link', 10, 3 );
		return $table_content;
	}

	/**
	 * Add the cart item upgrade/downgrade/crossgrade direction for display
	 *
	 * @since 2.0
	 */
	public static function add_cart_item_switch_direction( $product_subtotal, $cart_item, $cart_item_key ) {

		if ( ! empty( $cart_item['subscription_switch'] ) ) {

			switch ( $cart_item['subscription_switch']['upgraded_or_downgraded'] ) {
				case 'downgraded' :
					$direction = _x( 'Downgrade', 'a switch order', 'woocommerce-subscriptions' );
					break;
				case 'upgraded' :
					$direction = _x( 'Upgrade', 'a switch order', 'woocommerce-subscriptions' );
					break;
				default :
					$direction = _x( 'Crossgrade', 'a switch order', 'woocommerce-subscriptions' );
				break;
			}

			// translators: %1: product subtotal, %2: HTML span tag, %3: direction (upgrade, downgrade, crossgrade), %4: closing HTML span tag
			$product_subtotal = sprintf( _x( '%1$s %2$s(%3$s)%4$s', 'product subtotal string', 'woocommerce-subscriptions' ), $product_subtotal, '<span class="subscription-switch-direction">', $direction, '</span>' );

		}

		return $product_subtotal;
	}

	/**
	 * Creates a 2.0 updated version of the "subscriptions_switched" callback for developers to hook onto.
	 *
	 * The subscription passed to the new `woocommerce_subscriptions_switched_item` callback is strictly the subscription
	 * to which the `$new_order_item` belongs to; this may be a new or the original subscription.
	 *
	 * @since 2.0.5
	 * @param WC_Order $order
	 */
	public static function maybe_add_switched_callback( $order ) {
		if ( wcs_order_contains_switch( $order ) ) {

			$subscriptions = wcs_get_subscriptions_for_order( $order );

			foreach ( $subscriptions as $subscription ) {
				foreach ( $subscription->get_items() as $new_order_item ) {
					if ( isset( $new_order_item['switched_subscription_item_id'] ) ) {
						$product_id = wcs_get_canonical_product_id( $new_order_item );
						// we need to check if the switch order contains the line item that has just been switched so that we don't call the hook on items that were previously switched in another order
						foreach ( $order->get_items() as $order_item ) {
							if ( wcs_get_canonical_product_id( $order_item ) == $product_id ) {
								do_action( 'woocommerce_subscriptions_switched_item', $subscription, $new_order_item, WC_Subscriptions_Order::get_item_by_id( $new_order_item['switched_subscription_item_id'] ) );
								break;
							}
						}
					}
				}
			}
		}
	}

	/**
	* Revoke download permissions granted on the old switch item.
	*
	* @since 2.0.9
	* @param WC_Subscription $subscription
	* @param array $new_item
	* @param array $old_item
	*/
	public static function remove_download_permissions_after_switch( $subscription, $new_item, $old_item ) {

		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		$product_id = wcs_get_canonical_product_id( $old_item );
		WCS_Download_Handler::revoke_downloadable_file_permission( $product_id, $subscription->get_id(), $subscription->get_user_id() );

	}

	/**
	 * Completes subscription switches for switch order.
	 *
	 * Performs all the changes calculated and saved by @see WC_Subscriptions_Switcher::process_checkout(), updating subscription
	 * line items, schedule, dates and totals to reflect the changes made in this switch order.
	 *
	 * @param WC_Order $order
	 * @since 2.1
	 */
	public static function complete_subscription_switches( $order ) {

		// Get the switch meta
		$switch_order_data = wcs_get_objects_property( $order, 'subscription_switch_data' );

		// if we don't have an switch data, there is nothing to do here. Switch orders created prior to v2.1 won't have any data to process.
		if ( empty( $switch_order_data ) || ! is_array( $switch_order_data ) ) {
			return;
		}

		foreach ( $switch_order_data as $subscription_id => $switch_data ) {

			$subscription = wcs_get_subscription( $subscription_id );

			if ( ! $subscription instanceof WC_Subscription ) {
				continue;
			}

			if ( ! empty( $switch_data['switches'] ) && is_array( $switch_data['switches'] ) ) {

				// If the switch data is in the old format
				if ( ! array_key_exists( 'remove_line_item', reset( $switch_data['switches'] ) ) ) {
					self::switch_line_items_pre_2_1_2( $switch_data['switches'], $order, $subscription );
				} else {
					foreach ( $switch_data['switches'] as $order_item_id => $switched_item_data ) {

						// If we are adding a line item to an existing subscription
						if ( isset( $switched_item_data['add_line_item'] ) ) {
							wcs_update_order_item_type( $switched_item_data['add_line_item'], 'line_item', $subscription->get_id() );
							do_action( 'woocommerce_subscription_item_switched', $order, $subscription, $switched_item_data['add_line_item'], $switched_item_data['remove_line_item'] );
						}

						// remove the existing subscription item
						$old_subscription_item = wcs_get_order_item( $switched_item_data['remove_line_item'], $subscription );
						$switch_order_item     = wcs_get_order_item( $order_item_id, $order );

						if ( empty( $old_subscription_item ) ) {
							throw new Exception( __( 'The original subscription item being switched cannot be found.', 'woocommerce-subscriptions' ) );
						} elseif ( empty( $switch_order_item ) ) {
							throw new Exception( __( 'The item on the switch order cannot be found.', 'woocommerce-subscriptions' ) );
						} else {
							// We don't want to include switch item meta in order item name
							add_filter( 'woocommerce_subscriptions_hide_switch_itemmeta', '__return_true' );
							$old_item_name = wcs_get_order_item_name( $old_subscription_item, array( 'attributes' => true ) );
							$new_item_name = wcs_get_order_item_name( $switch_order_item, array( 'attributes' => true ) );
							remove_filter( 'woocommerce_subscriptions_hide_switch_itemmeta', '__return_true' );

							wcs_update_order_item_type( $switched_item_data['remove_line_item'], 'line_item_switched', $subscription->get_id() );

							// translators: 1$: old item, 2$: new item when switching
							$add_note = sprintf( _x( 'Customer switched from: %1$s to %2$s.', 'used in order notes', 'woocommerce-subscriptions' ), $old_item_name, $new_item_name );
						}
					}
				}
			}

			if ( ! empty( $add_note ) ) {
				$subscription->add_order_note( $add_note );
			}

			if ( ! empty( $switch_data['billing_schedule'] ) ) {

				// Update the billing schedule
				if ( ! empty( $switch_data['billing_schedule']['_billing_period'] ) ) {
					$subscription->set_billing_period( $switch_data['billing_schedule']['_billing_period'] );
				}

				if ( ! empty( $switch_data['billing_schedule']['_billing_interval'] ) ) {
					$subscription->set_billing_interval( $switch_data['billing_schedule']['_billing_interval'] );
				}
			}

			// Update subscription dates
			if ( ! empty( $switch_data['dates'] ) ) {

				if ( ! empty( $switch_data['dates']['delete'] ) ) {
					foreach ( $switch_data['dates']['delete'] as $date ) {
						$subscription->delete_date( $date );
					}
				}

				if ( ! empty( $switch_data['dates']['update'] ) ) {
					$subscription->update_dates( $switch_order_data[ $subscription->get_id() ]['dates']['update'] );
				}
			}

			// If the shipping data is in the old format
			if ( ! empty( $switch_data['shipping_methods'] ) ) {
				self::switch_shipping_line_items_pre_2_1_2( $subscription, $switch_data['shipping_methods'] );
			} else if ( ! empty( $switch_data['shipping_line_items'] ) && is_array( $switch_data['shipping_line_items'] ) ) {

				// Archive the old subscription shipping methods
				foreach ( $subscription->get_shipping_methods() as $shipping_line_item_id => $item ) {
					wcs_update_order_item_type( $shipping_line_item_id, 'shipping_switched', $subscription->get_id() );
				}

				// Flip the switched shipping line items "on"
				foreach ( $switch_data['shipping_line_items'] as $shipping_line_item_id ) {
					wcs_update_order_item_type( $shipping_line_item_id, 'shipping', $subscription->get_id() );
				}
			}

			// Update the subscription address
			self::maybe_update_subscription_address( $order, $subscription );

			// Save every change
			$subscription->save();

			// We just changed above the type of some items related to this subscription, so we need to reload it to get the newest items
			wcs_get_subscription( $subscription->get_id() )->calculate_totals();
		}
	}

	/**
	 * If we are switching a $0 / period subscription to a non-zero $ / period subscription, and the existing
	 * subscription is using manual renewals but manual renewals are not forced on the site, we need to set a
	 * flag to force WooCommerce to require payment so that we can switch the subscription to automatic renewals
	 * because it was probably only set to manual because it was $0.
	 *
	 * We need to determine this here instead of on the 'woocommerce_cart_needs_payment' because when payment is being
	 * processed, we will have changed the associated subscription data already, so we can't check that subscription's
	 * values anymore. We determine it here, then ue the 'force_payment' flag on 'woocommerce_cart_needs_payment' to
	 * require payment.
	 *
	 * @param int $total
	 * @since 2.0.16
	 */
	public static function set_force_payment_flag_in_cart( $total ) {

		if ( $total > 0 || 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' ) || false === self::cart_contains_switches() ) {
			return $total;
		}

		$old_recurring_total = 0;
		$new_recurring_total = 0;
		$has_future_payments = false;

		// Check that the new subscriptions are not for $0 recurring and there is a future payment required
		foreach ( WC()->cart->recurring_carts as $cart ) {

			$new_recurring_total += $cart->total;

			if ( $cart->next_payment_date > 0 ) {
				$has_future_payments = true;
			}
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

			if ( ! isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
				continue;
			}

			$subscription = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );

			$is_manual_subscription = $subscription->is_manual();

			// Check for $0 / period to a non-zero $ / period and manual subscription
			$switch_from_zero_manual_subscription = $is_manual_subscription && 0 == $subscription->get_total();

			// Force payment gateway selection for new subscriptions if the old subscription was automatic or manual renewals aren't accepted
			$force_automatic_payments = ! $is_manual_subscription || 'no' === get_option( WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals', 'no' );

			if ( $new_recurring_total > 0 && true === $has_future_payments && ( $switch_from_zero_manual_subscription || ( $force_automatic_payments && self::cart_contains_subscription_creating_switch() ) ) ) {
				WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['force_payment'] = true;
			}
		}

		return $total;
	}

	/**
	 * Require payment when switching from a $0 / period subscription to a non-zero subscription to process
	 * automatic payments for future renewals, as indicated by the 'force_payment' flag on the switch, set in
	 * @see self::set_force_payment_flag_in_cart().
	 *
	 * @param bool $needs_payment
	 * @param object $cart
	 * @since 2.0.16
	 */
	public static function cart_needs_payment( $needs_payment, $cart ) {

		if ( false === $needs_payment && 0 == $cart->total && false !== ( $switch_items = self::cart_contains_switches() ) ) {

			foreach ( $switch_items as $switch_item ) {
				if ( isset( $switch_item['force_payment'] ) && true === $switch_item['force_payment'] ) {
					$needs_payment = true;
					break;
				}
			}
		}

		return $needs_payment;
	}

	/**
	 * Once payment is processed on a switch from a $0 / period subscription to a non-zero $ / period subscription, if
	 * payment was completed with a payment method which supports automatic payments, update the payment on the subscription
	 * and the manual renewals flag so that future renewals are processed automatically.
	 *
	 * @param WC_Order $order
	 * @since 2.1
	 */
	public static function maybe_set_payment_method_after_switch( $order ) {

		// Only set manual subscriptions to automatic if automatic payments are enabled
		if ( 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' ) ) {
			return;
		}

		foreach ( wcs_get_subscriptions_for_switch_order( $order ) as $subscription ) {

			if ( false === $subscription->is_manual() ) {
				continue;
			}

			if ( $subscription->get_payment_method() !== wcs_get_objects_property( $order, 'payment_method' ) ) {

				// Set the new payment method on the subscription
				$available_gateways   = WC()->payment_gateways->get_available_payment_gateways();
				$order_payment_method = wcs_get_objects_property( $order, 'payment_method' );
				$payment_method       = '' != $order_payment_method && isset( $available_gateways[ $order_payment_method ] ) ? $available_gateways[ $order_payment_method ] : false;

				if ( $payment_method && $payment_method->supports( 'subscriptions' ) ) {
					$subscription->set_payment_method( $payment_method );
					$subscription->set_requires_manual_renewal( false );
					$subscription->save();
				}
			}
		}
	}

	/**
	 * Delay granting download permissions to the subscription until the switch is processed.
	 *
	 * @param int $order_id The order the download permissions are being granted for.
	 * @since 2.2.13
	 */
	public static function delay_granting_download_permissions( $order_id ) {
		if ( wcs_order_contains_switch( $order_id ) ) {
			remove_action( 'woocommerce_grant_product_download_permissions', 'WCS_Download_Handler::save_downloadable_product_permissions' );
		}
	}

	/**
	 * Grant the download permissions to the subscription after the switch is processed.
	 *
	 * @param WC_Order The switch order.
	 * @since 2.2.13
	 */
	public static function grant_download_permissions( $order ) {
		WCS_Download_Handler::save_downloadable_product_permissions( wcs_get_objects_property( $order, 'id' ) );

		// reattach the hook detached in @see self::delay_granting_download_permissions()
		add_action( 'woocommerce_grant_product_download_permissions', 'WCS_Download_Handler::save_downloadable_product_permissions' );
	}

	/** Deprecated Methods **/

	/**
	 * Automatically set a switch order's status to complete (even if the items require shipping because
	 * the order is simply a record of the switch and not indicative of an item needing to be shipped)
	 *
	 * @since 1.5
	 */
	public static function subscription_switch_autocomplete( $new_order_status, $order_id ) {
		_deprecated_function( __METHOD__, '2.1.3', 'WC_Subscriptions_Order::maybe_autocomplete_order' );
		return WC_Subscriptions_Order::maybe_autocomplete_order( $new_order_status, $order_id );
	}

	/**
	 * Once payment is processed on a switch from a $0 / period subscription to a non-zero $ / period subscription, if
	 * payment was completed with a payment method which supports automatic payments, update the payment on the subscription
	 * and the manual renewals flag so that future renewals are processed automatically.
	 *
	 * @param array $payment_processing_result
	 * @param int $order_id
	 * @since 2.0.16
	 * @deprecated 2.1
	 */
	public static function maybe_set_payment_method( $payment_processing_result, $order_id ) {

		_deprecated_function( __METHOD__, '2.1', __CLASS__ . '::maybe_set_payment_method_after_switch( $order )' );

		if ( wcs_order_contains_switch( $order_id ) && false != get_post_meta( $order_id, '_paid_date', true ) ) {

			$order = wc_get_order( $order_id );
			self::maybe_set_payment_method_after_switch( $order );
		}

		return $payment_processing_result;
	}

	/**
	 * Override the order item quantity used to reduce stock levels when the order item is to record a switch and where no
	 * prorated amount is being charged.
	 *
	 * @param int $quantity the original order item quantity used to reduce stock
	 * @param WC_Order $order
	 * @param array $order_item
	 *
	 * @return int
	 */
	public static function maybe_do_not_reduce_stock( $quantity, $order, $order_item ) {

		if ( isset( $order_item['switched_subscription_price_prorated'] ) && 0 == $order_item['line_total'] ) {
			$quantity = 0;
		}

		return $quantity;
	}

	/**
	 * Make sure switch cart item price doesn't include any recurring amount by setting a free trial.
	 *
	 * @since 2.0.18
	 */
	public static function maybe_set_free_trial( $total = '' ) {

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) && 0 != $cart_item['subscription_switch']['first_payment_timestamp'] ) {
				wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_trial_length', 1, 'set_prop_only' );
			}
		}

		return $total;
	}

	/**
	 * Remove mock free trials from switch cart items.
	 *
	 * @since 2.0.18
	 */
	public static function maybe_unset_free_trial( $total = '' ) {

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) && 0 != $cart_item['subscription_switch']['first_payment_timestamp'] ) {
				wcs_set_objects_property( WC()->cart->cart_contents[ $cart_item_key ]['data'], 'subscription_trial_length', 0, 'set_prop_only' );
			}
		}
		return $total;
	}

	/**
	 * Switch subscription line items provided line item data in the 2.1 switch order meta format.
	 *
	 * @param array $switches an array of switch items and its meta
	 * @param WC_Order $order the switch order
	 * @param WC_Subscription $subscription the subscription being switched
	 * @since 2.1.2
	 */
	protected static function switch_line_items_pre_2_1_2( $switches, $order, $subscription ) {

		foreach ( $switches as $order_item_id => $switch_item_data ) {

			$order_item = wcs_get_order_item( $order_item_id, $order );

			// if we are simply adding this product to an existing subscription
			if ( isset( $switch_item_data['add_order_item_data'] ) ) {
				$product              = WC_Subscriptions::get_product( wcs_get_canonical_product_id( $order_item ) );
				$line_tax_data        = wc_get_order_item_meta( $order_item_id, '_line_tax_data', true );
				$variation_attributes = ( method_exists( $product, 'get_variation_attributes' ) ) ? $product->get_variation_attributes() : array();

				$item_id = $subscription->add_product( $product, $order_item['qty'], array(
					'variation' => $variation_attributes,
					'totals'    => $switch_item_data['add_order_item_data']['totals'],
				) );

				foreach ( $switch_item_data['add_order_item_data']['meta'] as $key => $value ) {
					if ( ! array_key_exists( 'attribute_' . $key, $variation_attributes ) ) {
						wc_add_order_item_meta( $item_id, $key, reset( $value ), true );
					}
				}

				do_action( 'woocommerce_subscription_item_switched', $order, $subscription, $order_item_id, $switch_item_data['subscription_item_id'] );
			}

			// remove the existing subscription item
			$old_order_item = wcs_get_order_item( $switch_item_data['subscription_item_id'], $subscription );

			if ( empty( $old_order_item ) ) {
				throw new Exception( __( 'The original subscription item being switched cannot be found.', 'woocommerce-subscriptions' ) );
			} else {
				// We don't want to include switch item meta in order item name
				add_filter( 'woocommerce_subscriptions_hide_switch_itemmeta', '__return_true' );
				$new_order_item_name         = wcs_get_order_item_name( $order_item, array( 'attributes' => true ) );
				$old_subscription_item_name  = wcs_get_order_item_name( $old_order_item, array( 'attributes' => true ) );
				remove_filter( 'woocommerce_subscriptions_hide_switch_itemmeta', '__return_true' );

				wcs_update_order_item_type( $switch_item_data['subscription_item_id'], 'line_item_switched', $subscription->get_id() );

				// translators: 1$: old item, 2$: new item when switching
				$subscription->add_order_note( sprintf( _x( 'Customer switched from: %1$s to %2$s.', 'used in order notes', 'woocommerce-subscriptions' ), $old_subscription_item_name, $new_order_item_name ) );
			}
		}
	}

	/**
	 * Switch subscription shipping line items provided shipping line item data in the 2.1 switch order meta format.
	 *
	 * @param WC_Subscription $subscription the subscription being switched
	 * @param array $shipping_methods an array of shipping line items and meta
	 * @since 2.1.2
	 */
	protected static function switch_shipping_line_items_pre_2_1_2( $subscription, $shipping_methods ) {
		// Archive the old subscription shipping methods
		foreach ( $subscription->get_shipping_methods() as $shipping_line_item_id => $item ) {
			wcs_update_order_item_type( $shipping_line_item_id, 'shipping_switched', $subscription->get_id() );
		}

		// Add the new shipping line item
		foreach ( $shipping_methods as $shipping_line_item ) {
			$item_id = wc_add_order_item( $subscription->get_id(), array(
				'order_item_name' => $shipping_line_item['name'],
				'order_item_type' => 'shipping',
			) );

			if ( ! $item_id || empty( $shipping_line_item['method_id'] ) || empty( $shipping_line_item['cost'] ) || empty( $shipping_line_item['taxes'] ) ) {
				throw new Exception( __( 'Failed to update the subscription shipping method.', 'woocommerce-subscriptions' ) );
			}

			// Add shipping order item meta
			wc_add_order_item_meta( $item_id, 'method_id', $shipping_line_item['method_id'] );
			wc_add_order_item_meta( $item_id, 'cost', wc_format_decimal( $shipping_line_item['cost'] ) );

			$taxes = array_map( 'wc_format_decimal', maybe_unserialize( $shipping_line_item['taxes'] ) );
			wc_add_order_item_meta( $item_id, 'taxes', $taxes );

			// Add custom shipping order item meta added by third-party plugins
			foreach ( $shipping_line_item['item_meta'] as $key => $value ) {
				wc_add_order_item_meta( $item_id, $key, $value );
			}
		}
	}

	/**
	 * Check if a cart item has a different billing schedule (period and interval) to the subscription being switched.
	 *
	 * Used to determine if a new subscription should be created as the result of a switch request.
	 * @see self::cart_contains_subscription_creating_switch() and self::process_checkout().
	 *
	 * @param array $cart_item
	 * @param WC_Subscription $subscription
	 * @since 2.2.19
	 */
	protected static function has_different_billing_schedule( $cart_item, $subscription ) {
		return WC_Subscriptions_Product::get_period( $cart_item['data'] ) != $subscription->get_billing_period() || WC_Subscriptions_Product::get_interval( $cart_item['data'] ) != $subscription->get_billing_interval();
	}

	/**
	 * Check if a cart item contains a different payment timestamp to the subscription being switched.
	 *
	 * Used to determine if a new subscription should be created as the result of a switch request.
	 * @see self::cart_contains_subscription_creating_switch() and self::process_checkout().
	 *
	 * @param array $cart_item
	 * @param WC_Subscription $subscription
	 * @since 2.2.19
	 */
	protected static function has_different_payment_date( $cart_item, $subscription ) {

		// If there are no more payments due on the subscription, because we're in the last billing period, we need to use the subscription's expiration date, not next payment date
		if ( 0 === ( $next_payment_timestamp = $subscription->get_time( 'next_payment' ) ) ) {
			$next_payment_timestamp = $subscription->get_time( 'end' );
		}

		if ( 0 !== $cart_item['subscription_switch']['first_payment_timestamp'] && $next_payment_timestamp !== $cart_item['subscription_switch']['first_payment_timestamp'] ) {
			$is_different_payment_date = true;
		} elseif ( 0 !== $cart_item['subscription_switch']['first_payment_timestamp'] && 0 === $subscription->get_time( 'next_payment' ) ) { // if the subscription doesn't have a next payment but the switched item does
			$is_different_payment_date = true;
		} else {
			$is_different_payment_date = false;
		}

		return $is_different_payment_date;
	}

	/**
	 * Determine if a recurring cart has a different length (end date) to a subscription.
	 *
	 * Used to determine if a new subscription should be created as the result of a switch request.
	 * @see self::cart_contains_subscription_creating_switch() and self::process_checkout().
	 *
	 * @param WC_Cart $recurring_cart
	 * @param WC_Subscription $subscription
	 * @return bool
	 * @since 2.2.19
	 */
	protected static function has_different_length( $recurring_cart, $subscription ) {
		$recurring_cart_end_date = gmdate( 'Y-m-d', wcs_date_to_time( $recurring_cart->end_date ) );
		$subscription_end_date   = gmdate( 'Y-m-d', $subscription->get_time( 'end' ) );

		return $recurring_cart_end_date !== $subscription_end_date;
	}

	/**
	 * Checks if a subscription has a single line item.
	 *
	 * Used to determine if a new subscription should be created as the result of a switch request.
	 * @see self::cart_contains_subscription_creating_switch() and self::process_checkout().
	 *
	 * @param WC_Subscription $subscription
	 * @return bool
	 * @since 2.2.19
	 */
	protected static function is_single_item_subscription( $subscription ) {
		// WC_Abstract_Order::get_item_count() uses quantities, not just line item rows
		return 1 === count( $subscription->get_items() );
	}

	/**
	 * Check if the cart contains a subscription switch which will result in a new subscription being created.
	 *
	 * New subscriptions will be created when:
	 *  - The current subscription has more than 1 line item @see self::is_single_item_subscription() and
	 *  - the recurring cart has a different length @see self::has_different_length() or
	 *  - the switched cart item has a different payment date @see self::has_different_payment_date() or
	 *  - the switched cart item has a different billing schedule @see self::has_different_billing_schedule()
	 *
	 * @return bool
	 * @since 2.2.19
	 */
	public static function cart_contains_subscription_creating_switch() {
		$cart_contains_subscription_creating_switch = false;

		foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

			foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {

				if ( ! isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
					continue;
				}

				$subscription = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );

				if (
					! self::is_single_item_subscription( $subscription ) && (
					self::has_different_length( $recurring_cart, $subscription ) ||
					self::has_different_payment_date( $cart_item, $subscription ) ||
					self::has_different_billing_schedule( $cart_item, $subscription ) )
				) {
					$cart_contains_subscription_creating_switch = true;
					break 2;
				}
			}
		}

		return $cart_contains_subscription_creating_switch;
	}

	/**
	 * Don't allow switched subscriptions to be cancelled.
	 *
	 * @param bool $subscription_can_be_changed
	 * @param array $subscription A subscription of the form created by @see WC_Subscriptions_Manager::get_subscription()
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function can_subscription_be_cancelled( $subscription_can_be_changed, $subscription ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( 'switched' == $subscription['status'] ) {
			$subscription_can_be_changed = false;
		}

		return $subscription_can_be_changed;
	}

	/**
	 * Adds a "Switch" button to the "My Subscriptions" table for those subscriptions can be upgraded/downgraded.
	 *
	 * @param array $all_actions The $subscription_key => $actions array with all actions that will be displayed for a subscription on the "My Subscriptions" table
	 * @param array $subscriptions All of a given users subscriptions that will be displayed on the "My Subscriptions" table
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function add_switch_button( $all_actions, $subscriptions ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::print_switch_button( $subscription, $item )' );

		$user_id = get_current_user_id();

		foreach ( $all_actions as $subscription_key => $actions ) {

			if ( WC_Subscriptions_Manager::can_subscription_be_changed_to( 'new-subscription', $subscription_key, $user_id ) ) {
				$all_actions[ $subscription_key ] = array(
					'switch' => array(
						'url'  => self::get_switch_link( $subscription_key ),
						'name' => get_option( WC_Subscriptions_Admin::$option_prefix . '_switch_button_text', __( 'Upgrade or Downgrade', 'woocommerce-subscriptions' ) ),
					),
				) + $all_actions[ $subscription_key ];
			}
		}

		return $all_actions;
	}

	/**
	 * The link for switching a subscription - the product page for variable subscriptions, or grouped product page for grouped subscriptions.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function get_switch_link( $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::get_switch_url( $item_id, $item, $subscription )' );
	}

	/**
	 * Add a 'new-subscription' handler to the WC_Subscriptions_Manager::can_subscription_be_changed_to() function.
	 *
	 * For the subscription to be switchable, switching must be enabled, and the subscription must:
	 * - be active or on-hold
	 * - be a variable subscription or part of a grouped product (at the time the check is made, not at the time the subscription was purchased)
	 * - be using manual renewals or use a payment method which supports cancellation
	 *
	 * @param bool $subscription_can_be_changed Flag of whether the subscription can be changed to
	 * @param string $new_status_or_meta The status or meta data you want to change the subscription to. Can be 'active', 'on-hold', 'cancelled', 'expired', 'trash', 'deleted', 'failed', 'AMQPChannel to the 'woocommerce_can_subscription_be_changed_to' filter.
	 * @param object $args Set of values used in @see WC_Subscriptions_Manager::can_subscription_be_changed_to() for determining if a subscription can be changed
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function can_subscription_be_changed_to( $subscription_can_be_changed, $new_status_or_meta, $args ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::can_item_be_switched( $item, $subscription )' );
		return false;
	}

	/**
	 * Check if the cart includes a request to switch a subscription.
	 *
	 * @return bool Returns true if any item in the cart is a subscription switch request, otherwise, false.
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function cart_contains_subscription_switch() {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::cart_contains_switches()' );

		$cart_contains_subscription_switch = self::cart_contains_switches();

		// For backward compatiblity, only send the first switch item, not all of them
		if ( false !== $cart_contains_subscription_switch ) {
			$cart_contains_subscription_switch = array_pop( $cart_contains_subscription_switch );
		}

		return $cart_contains_subscription_switch;
	}

	/**
	 * Previously, we used a trial period to make sure totals are calculated correctly (i.e. order total does not include any recurring
	 * amounts) but we didn't want switched subscriptions to actually have a trial period, so reset the value on the order after checkout.
	 *
	 * This is all redundant now that trial period isn't stored on a subscription item. The first payment date will be used instead.
	 *
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function fix_order_item_meta( $item_id, $values ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Add the next payment date to the end of the subscription to clarify when the new rate will be charged
	 *
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function customise_subscription_price_string( $subscription_string ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Never display the trial period for a subscription switch (we're only setting it to calculate correct totals)
	 *
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function customise_cart_subscription_string_details( $subscription_details ) {
		_deprecated_function( __METHOD__, '2.0' );
		return $subscription_details;
	}

	/**
	 * Make sure when calculating the first payment date for a switched subscription, the date takes into
	 * account the switch (i.e. prepaid days and possibly a downgrade).
	 *
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function calculate_first_payment_date( $next_payment_date, $order, $product_id, $type ) {
		_deprecated_function( __METHOD__, '2.0' );
		return self::get_first_payment_date( $next_payment_date, WC_Subscriptions_Manager::get_subscription_key( wcs_get_objects_property( $order, 'id' ), $product_id ), $order->get_user_id(), $type );
	}

	/**
	 * Make sure anything requesting the first payment date for a switched subscription receives a date which
	 * takes into account the switch (i.e. prepaid days and possibly a downgrade).
	 *
	 * This is necessary as the self::calculate_first_payment_date() is not called when the subscription is active
	 * (which it isn't until the first payment is completed and the subscription is activated).
	 *
	 * @deprecated 2.0
	 */
	public static function get_first_payment_date( $next_payment_date, $subscription_key, $user_id, $type ) {
		_deprecated_function( __METHOD__, '2.0' );

		$subscription = wcs_get_subscription_from_key( $subscription_key );

		if ( $subscription->has_status( 'active' ) && $subscription->get_parent_id() && wcs_order_contains_switch( $subscription->get_parent_id() ) && 1 >= $subscription->get_completed_payment_count() ) {

			$first_payment_timestamp = get_post_meta( $subscription->get_parent_id(), '_switched_subscription_first_payment_timestamp', true );

			if ( 0 != $first_payment_timestamp ) {
				$next_payment_date = ( 'mysql' == $type ) ? gmdate( 'Y-m-d H:i:s', $first_payment_timestamp ) : $first_payment_timestamp;
			}
		}

		return $next_payment_date;
	}

	/**
	 * Add an i18n'ified string for the "switched" subscription status.
	 *
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function add_switched_status_string( $status_string ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( 'switched' === strtolower( $status_string ) ) {
			$status_string = _x( 'Switched', 'Subscription status', 'woocommerce-subscriptions' );
		}

		return $status_string;
	}

	/**
	 * Set the subscription prices to be used in calculating totals by @see WC_Subscriptions_Cart::calculate_subscription_totals()
	 *
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function maybe_set_apporitioned_totals( $total ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::calculate_prorated_totals()' );
		return $total;
	}

	/**
	 * If the subscription purchased in an order has since been switched, include a link to the order placed to switch the subscription
	 * in the "Related Orders" meta box (displayed on the Edit Order screen).
	 *
	 * @param WC_Order $order The current order.
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function switch_order_meta_box_section( $order ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * After payment is completed on an order for switching a subscription, complete the switch.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function maybe_complete_switch( $order_id ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Check if a given order was created to switch a subscription.
	 *
	 * @param WC_Order $order An order to check.
	 * @return bool Returns true if the order switched a subscription, otherwise, false.
	 * @since 1.4
	 */
	public static function order_contains_subscription_switch( $order_id ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_order_contains_switch( $order_id )' );
		return wcs_order_contains_switch( $order_id );
	}

	/**
	 * Store the order line item id so it can be retrieved when we're processing the switch on checkout
	 *
	 * @param int $order_id
	 * @param array $checkout_posted_data
	 * @since 2.2.0
	 */
	public static function set_switch_order_item_id( $order_id, $posted_checkout_data ) {
		_deprecated_function( __METHOD__, '2.2.1', 'WCS_Cart_Switch::set_cart_item_order_item_id()' );

		$order = wc_get_order( $order_id );

		foreach ( $order->get_items( 'line_item' ) as $order_item_id => $order_item ) {

			$cart_item_key = $order_item->get_meta( '_switched_cart_item_key' );

			if ( ! empty( $cart_item_key ) ) {
				foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

					// If this cart item belongs to this recurring cart
					if ( in_array( $cart_item_key, array_keys( $recurring_cart->cart_contents ) ) && isset( WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ]['subscription_switch'] ) ) {
						WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ]['subscription_switch']['order_line_item_id'] = $order_item_id;
						wc_add_order_item_meta( WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ]['subscription_switch']['item_id'], '_switched_subscription_new_item_id', $order_item_id, true );
					}
				}
			}
		}
	}

	/**
	 * Filter the WC_Subscription::get_related_orders() method to include switch orders.
	 *
	 * @since 2.0
	 * @deprecated
	 *
	 * @param array           $related_orders
	 * @param WC_Subscription $subscription
	 * @param string          $return_fields
	 * @param string          $order_type
	 *
	 * @return array
	 */
	public static function add_related_orders( $related_orders, $subscription, $return_fields, $order_type ) {
		wcs_deprecated_function( __METHOD__, '2.3.0', 'wcs_get_switch_orders_for_subscription()' );
		if ( in_array( $order_type, array( 'all', 'switch' ) ) ) {

			$switch_orders = wcs_get_switch_orders_for_subscription( $subscription->get_id() );

			if ( 'all' == $return_fields ) {
				$related_orders += $switch_orders;
			} else {
				foreach ( $switch_orders as $order_id => $order ) {
					$related_orders[ $order_id ] = $order_id;
				}
			}

			// This will change the ordering to be by ID instead of the default of date
			krsort( $related_orders );
		}

		return $related_orders;
	}
}
WC_Subscriptions_Switcher::init();
