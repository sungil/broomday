<?php
/**
 * WooCommerce Subscriptions Cart Functions
 *
 * Functions for cart specific things, based on wc-cart-functions.php but overloaded
 * for use with recurring carts.
 *
 * @author 		Prospress
 * @category 	Core
 * @package 	WooCommerce Subscriptions/Functions
 * @version     2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Display a recurring cart's subtotal
 *
 * @access public
 * @return string
 */
function wcs_cart_totals_subtotal_html( $cart ) {
	echo wp_kses_post( wcs_cart_price_string( $cart->get_cart_subtotal(), $cart ) );
}

/**
 * Get recurring shipping methods.
 *
 * @access public
 */
function wcs_cart_totals_shipping_html() {

	$initial_packages = WC()->shipping->get_packages();

	$show_package_details = count( WC()->cart->recurring_carts ) > 1;
	$show_package_name    = true;

	// Create new subscriptions for each subscription product in the cart (that is not a renewal)
	foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

		// Create shipping packages for each subscription item
		if ( WC_Subscriptions_Cart::cart_contains_subscriptions_needing_shipping( $recurring_cart ) && 0 !== $recurring_cart->next_payment_date ) {

			// This will get a package with the 'recurring_cart_key' set to 'none' (because WC_Subscriptions_Cart::display_recurring_totals() set WC_Subscriptions_Cart::$calculation_type to 'recurring_total', but WC_Subscriptions_Cart::$recurring_cart_key has not been set), which ensures that it's a unique package, which we need in order to get all the available packages, not just the package for the recurring cart calculation we completed previously where WC_Subscriptions_Cart::filter_package_rates() removed all unchosen rates and which WC then cached
			$packages = $recurring_cart->get_shipping_packages();

			foreach ( $packages as $i => $base_package ) {

				$product_names = array();
				$base_package['recurring_cart_key'] = $recurring_cart_key;

				$package = WC_Subscriptions_Cart::get_calculated_shipping_for_package( $base_package );
				$index   = sprintf( '%1$s_%2$d', $recurring_cart_key, $i );

				if ( $show_package_details ) {
					foreach ( $package['contents'] as $item_id => $values ) {
						$product_names[] = $values['data']->get_title() . ' &times;' . $values['quantity'];
					}
					$package_details = implode( ', ', $product_names );
				} else {
					$package_details = '';
				}

				$chosen_initial_method   = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';

				if ( isset( WC()->session->chosen_shipping_methods[ $recurring_cart_key . '_' . $i ] ) ) {
					$chosen_recurring_method = WC()->session->chosen_shipping_methods[ $recurring_cart_key . '_' . $i ];
				} elseif ( in_array( $chosen_initial_method, $package['rates'] ) ) {
					$chosen_recurring_method = $chosen_initial_method;
				} else {
					$chosen_recurring_method = empty( $package['rates'] ) ? '' : current( $package['rates'] )->id;
				}

				$shipping_selection_displayed = false;

				if ( ( 1 === count( $package['rates'] ) ) || ( isset( $package['rates'][ $chosen_initial_method ] ) && isset( $initial_packages[ $i ] ) && $package['rates'] == $initial_packages[ $i ]['rates'] && apply_filters( 'wcs_cart_totals_shipping_html_price_only', true, $package, $recurring_cart ) ) ) {
					$shipping_method = ( 1 === count( $package['rates'] ) ) ? current( $package['rates'] ) : $package['rates'][ $chosen_initial_method ];
					// packages match, display shipping amounts only
					?>
					<tr class="shipping recurring-total <?php echo esc_attr( $recurring_cart_key ); ?>">
						<th><?php echo esc_html( sprintf( __( 'Shipping via %s', 'woocommerce-subscriptions' ), $shipping_method->label ) ); ?></th>
						<td data-title="<?php echo esc_attr( sprintf( __( 'Shipping via %s', 'woocommerce-subscriptions' ), $shipping_method->label ) ); ?>">
							<?php echo wp_kses_post( wcs_cart_totals_shipping_method_price_label( $shipping_method, $recurring_cart ) ); ?>
							<?php if ( 1 === count( $package['rates'] ) ) : ?>
								<?php wcs_cart_print_shipping_input( $index, $shipping_method ); ?>
								<?php do_action( 'woocommerce_after_shipping_rate', $shipping_method, $index ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $show_package_details ) ) : ?>
								<?php echo '<p class="woocommerce-shipping-contents"><small>' . esc_html( $package_details ) . '</small></p>'; ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php
				} else {
					// Display the options
					$product_names = array();

					$shipping_selection_displayed = true;

					if ( $show_package_name ) {
						$package_name = apply_filters( 'woocommerce_shipping_package_name', sprintf( _n( 'Shipping', 'Shipping %d', ( $i + 1 ), 'woocommerce-subscriptions' ), ( $i + 1 ) ), $i, $package );
					} else {
						$package_name = '';
					}

					wc_get_template( 'cart/cart-recurring-shipping.php', array(
							'package'              => $package,
							'available_methods'    => $package['rates'],
							'show_package_details' => $show_package_details,
							'package_details'      => $package_details,
							'package_name'         => $package_name,
							'index'                => $index,
							'chosen_method'        => $chosen_recurring_method,
							'recurring_cart_key'   => $recurring_cart_key,
							'recurring_cart'       => $recurring_cart,
						),
						'',
						plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/'
					);
					$show_package_name = false;
				}
				do_action( 'woocommerce_subscriptions_after_recurring_shipping_rates', $index, $base_package, $recurring_cart, $chosen_recurring_method, $shipping_selection_displayed );
			}
		}
	}
}

/**
 * Display a recurring shipping method's input element, either as a hidden element if there is only one shipping method,
 * or a radio select box when there is more than one available method.
 *
 * @param  string $shipping_method_index
 * @param  object $shipping_method
 * @param  string $chosen_method
 * @param  string $input_type
 * @return null
 */
function wcs_cart_print_shipping_input( $shipping_method_index, $shipping_method, $chosen_method = '', $input_type = 'hidden' ) {

	if ( 'radio' == $input_type ) {
		$checked = checked( $shipping_method->id, $chosen_method, false );
	} else {
		// Make sure we only output safe input types
		$input_type = 'hidden';
		$checked    = '';
	}

	printf( '<input type="%1$s" name="shipping_method[%2$s]" data-index="%2$s" id="shipping_method_%2$s_%3$s" value="%4$s" class="shipping_method shipping_method_%2$s" %5$s />',
	esc_attr( $input_type ), esc_attr( $shipping_method_index ), esc_attr( sanitize_title( $shipping_method->id ) ), esc_attr( $shipping_method->id ), esc_attr( $checked ) );
}

/**
 * Display a recurring shipping methods price & name as a label
 *
 * @param  object $method
 * @return string
 */
function wcs_cart_totals_shipping_method( $method, $cart ) {

	$label = ( method_exists( $method, 'get_label' ) ) ? $method->get_label() : $method->label; // WC < 2.5 compatibility (WC_Shipping_Rate::get_label() was introduced with WC 2.5)
	$label .= ': ' . wcs_cart_totals_shipping_method_price_label( $method, $cart );

	return apply_filters( 'wcs_cart_totals_shipping_method', $label, $method, $cart );
}

/**
 * Display a recurring shipping methods price
 *
 * @param  object $method
 * @return string
 */
function wcs_cart_totals_shipping_method_price_label( $method, $cart ) {

	$price_label = '';

	if ( $method->cost != 0 ) {

		if ( WC()->cart->tax_display_cart == 'excl' ) {
			$price_label .= wcs_cart_price_string( $method->cost, $cart );
			if ( $method->get_shipping_tax() > 0 && $cart->prices_include_tax ) {
				$price_label .= ' <small>' . WC()->countries->ex_tax_or_vat() . '</small>';
			}
		} else {
			$price_label .= wcs_cart_price_string( $method->cost + $method->get_shipping_tax(), $cart );
			if ( $method->get_shipping_tax() > 0 && ! $cart->prices_include_tax ) {
				$price_label .= ' <small>' . WC()->countries->inc_tax_or_vat() . '</small>';
			}
		}
	} elseif ( ! empty( $cart->recurring_cart_key ) ) {
		$price_label .= _x( 'Free', 'shipping method price', 'woocommerce-subscriptions' );
	}

	return $price_label;
}

/**
 * Display recurring taxes total
 *
 * @access public
 * @return void
 */
function wcs_cart_totals_taxes_total_html( $cart ) {
	$value = apply_filters( 'woocommerce_cart_totals_taxes_total_html', $cart->get_taxes_total() );
	echo wp_kses_post( apply_filters( 'wcs_cart_totals_taxes_total_html', wcs_cart_price_string( $value, $cart ), $cart ) );
}

/**
 * Display the remove link for a coupon.
 *
 *  @access public
 *
 * @param WC_Coupon $coupon
 */
function wcs_cart_coupon_remove_link_html( $coupon ) {
	$html = '<a href="' . esc_url( add_query_arg( 'remove_coupon', urlencode( wcs_get_coupon_property( $coupon, 'code' ) ), defined( 'WOOCOMMERCE_CHECKOUT' ) ? wc_get_checkout_url() : wc_get_cart_url() ) ) . '" class="woocommerce-remove-coupon" data-coupon="' . esc_attr( wcs_get_coupon_property( $coupon, 'code' ) ) . '">' . __( '[Remove]', 'woocommerce-subscriptions' ) . '</a>';
	echo wp_kses( $html, array_replace_recursive( wp_kses_allowed_html( 'post' ), array( 'a' => array( 'data-coupon' => true ) ) ) );
}

/**
 * Display a recurring coupon's value.
 *
 * @see wc_cart_totals_coupon_html()
 *
 * @access public
 *
 * @param string|WC_Coupon $coupon
 * @param WC_Cart          $cart
 */
function wcs_cart_totals_coupon_html( $coupon, $cart ) {
	if ( is_string( $coupon ) ) {
		$coupon = new WC_Coupon( $coupon );
	}

	$value  = array();

	if ( $amount = $cart->get_coupon_discount_amount( wcs_get_coupon_property( $coupon, 'code' ), $cart->display_cart_ex_tax ) ) {
		$discount_html = '-' . wc_price( $amount );
	} else {
		$discount_html = '';
	}

	$value[] = apply_filters( 'woocommerce_coupon_discount_amount_html', $discount_html, $coupon );

	if ( wcs_get_coupon_property( $coupon, 'enable_free_shipping' ) ) {
		$value[] = __( 'Free shipping coupon', 'woocommerce-subscriptions' );
	}

	// get rid of empty array elements
	$value = implode( ', ', array_filter( $value ) );

	// Apply filters.
	$html = apply_filters( 'wcs_cart_totals_coupon_html', $value, $coupon, $cart );
	$html = apply_filters( 'woocommerce_cart_totals_coupon_html', $html, $coupon, $discount_html );

	echo wp_kses( $html, array_replace_recursive( wp_kses_allowed_html( 'post' ), array( 'a' => array( 'data-coupon' => true ) ) ) );
}

/**
 * Get recurring total html including inc tax if needed
 *
 * @access public
 * @return void
 */
function wcs_cart_totals_order_total_html( $cart ) {
	$value = '<strong>' . $cart->get_total() . '</strong> ';

	// If prices are tax inclusive, show taxes here
	if ( wc_tax_enabled() && $cart->tax_display_cart == 'incl' ) {
		$tax_string_array = array();

		if ( get_option( 'woocommerce_tax_total_display' ) == 'itemized' ) {
			foreach ( $cart->get_tax_totals() as $code => $tax ) {
				$tax_string_array[] = sprintf( '%s %s', $tax->formatted_amount, $tax->label );
			}
		} else {
			$tax_string_array[] = sprintf( '%s %s', wc_price( $cart->get_taxes_total( true, true ) ), WC()->countries->tax_or_vat() );
		}

		if ( ! empty( $tax_string_array ) ) {
			// translators: placeholder is price string, denotes tax included in cart/order total
			$value .= '<small class="includes_tax">' . sprintf( _x( '(Includes %s)', 'includes tax', 'woocommerce-subscriptions' ), implode( ', ', $tax_string_array ) ) . '</small>';
		}
	}

	// Apply WooCommerce core filter
	$value = apply_filters( 'woocommerce_cart_totals_order_total_html', $value );

	echo wp_kses_post( apply_filters( 'wcs_cart_totals_order_total_html', wcs_cart_price_string( $value, $cart ), $cart ) );

}

/**
 * Return a formatted price string for a given cart object
 *
 * @access public
 * @return string
 */
function wcs_cart_price_string( $recurring_amount, $cart ) {

	 //return wcs_price_string( apply_filters( 'woocommerce_cart_subscription_string_details', array(
	//	'recurring_amount'      => $recurring_amount,

		// Schedule details
	//	'subscription_interval' => wcs_cart_pluck( $cart, 'subscription_period_interval' ),
	//	'subscription_period'   => wcs_cart_pluck( $cart, 'subscription_period', '' ),
	//	'subscription_length'   => wcs_cart_pluck( $cart, 'subscription_length' ),
	//) ) );
	
}

/**
 * Return a given piece of meta data from the cart
 *
 * The data can exist on the cart object, a cart item, or product data on a cart item.
 * The first piece of data with a matching key (in that order) will be returned if it
 * is found, otherwise, the value specified with $default, will be returned.
 *
 * @access public
 * @return string
 */
function wcs_cart_pluck( $cart, $field, $default = 0 ) {

	$value = $default;

	if ( isset( $cart->$field ) ) {
		$value = $cart->$field;
	} else {
		foreach ( $cart->get_cart() as $cart_item ) {

			if ( isset( $cart_item[ $field ] ) ) {
				$value = $cart_item[ $field ];
			} else {
				$value = WC_Subscriptions_Product::get_meta_data( $cart_item['data'], $field, $default );
			}
		}
	}

	return $value;
}

/**
 * Append the first renewal payment date to a string (which is the order total HTML string by default)
 *
 * @access public
 * @return string
 */
function wcs_add_cart_first_renewal_payment_date( $order_total_html, $cart ) {

	if ( 0 !== $cart->next_payment_date ) {
	
		global $woocommerce;
		$items = $woocommerce->cart->get_cart();
	
		
	//   echo "<pre>"; print_r($items); echo "</pre>";
		$date = '';
		$activeschem = '';
		foreach($items as $item){
				// echo "<pre>"; print_r($item); echo "</pre>";
				$date .= $item['date'];
				$activeschem .= $item['wcsatt_data']['active_subscription_scheme'];
		
		}
		
		$schem = array();
		$labels = array();
		foreach($items as $item => $values) { 
				$_product =  wc_get_product( $values['data']->get_id()); 
				$rr = get_post_meta($_product->id, '_wcsatt_schemes', true) ;
				
				$sch = maybe_unserialize($rr);
				
				foreach($sch as $schs){
						
						$printerval = $schs['subscription_period_interval'];
						$prperiod = $schs['subscription_period'];
						$prlabel = $schs['subscription_label'];
						$prlenght = "";
						if($schs['subscription_length'] != 0){
						$prlenght = "_".$schs['subscription_length'];
						}
						
					$schem[$prlabel] = $printerval."_".$prperiod.$prlenght;  
					$labels[$printerval."_".$prperiod] = 	$prlabel;
				}
		} 
		//echo $activeschem."<br/>";
		//echo "<pre>"; print_r($schem); echo "</pre>";
		list($interval, $period) = explode('_',$activeschem);       
		switch($period){
			case 'month':
				$threedays_ago = date('Y-m-d H:i:s', strtotime("+$interval month -3days", strtotime($date)));
				$one_ago = date('Y-m-d H:i:s', strtotime("+$interval month", strtotime($date)));
				break;
			case 'week':
				$threedays_ago = date('Y-m-d H:i:s', strtotime("+$interval week -3days", strtotime($date)));
				$one_ago = date('Y-m-d H:i:s', strtotime("+$interval week", strtotime($date)));
				break;
			case 'day':
				$threedays_ago = date('Y-m-d H:i:s', strtotime("+$interval days", strtotime($date)));
				$one_ago = date('Y-m-d H:i:s', strtotime("+$interval days", strtotime($date)));
				break;
		}
	    
    echo  "<span class='mysub' style='text-align: right; float: right; margin-top: -14px !important;margin-bottom:0;'>".$labels[$interval.'_'.$period]."</span>";

		global $wp_locale;
		$order_month = date('m', strtotime($threedays_ago));
		$nextorder_month = date('m', strtotime($one_ago));
	
		$date =  date('d, Y', strtotime($threedays_ago));
		$one_ago =  date('d, Y', strtotime($one_ago));
		
		
		$order_month = $wp_locale->get_month( $order_month);
	
		$nextorder_month = $wp_locale->get_month($nextorder_month);
		
		$first_renewal_date = $order_month." ".$date;
		$plusone_ago = $nextorder_month." ".$one_ago;
		

	   // $first_renewal_date = date_i18n($date);
		// $plusone_ago = date_i18n($one_ago);

		// translators: placeholder is a date
		//$order_total_html  .= '<div class="first-payment-date"><small>' .sprintf( __( 'Primera renovacion: %s', 'woocommerce-subscriptions' ), $first_renewal_date ) .  '</small></div>';
		$order_total_html  .= '<div class="next-order-date"><small>' . sprintf( __( 'Próxima Limpieza: %s', 'woocommerce-subscriptions' ), $plusone_ago ) .  '</small></div>';
	}

	return $order_total_html;
}
add_filter( 'wcs_cart_totals_order_total_html', 'wcs_add_cart_first_renewal_payment_date', 10, 2 );

/**
 * Return the cart item name for specific cart item
 *
 * @access public
 * @return string
 */
function wcs_get_cart_item_name( $cart_item, $include = array() ) {

	$include = wp_parse_args( $include, array(
		'attributes' => false,
	) );

	$cart_item_name = $cart_item['data']->get_title();

	if ( $include['attributes'] ) {

		$attributes_string = WC()->cart->get_item_data( $cart_item, true );
		$attributes_string = implode( ', ', array_filter( explode( "\n", $attributes_string ) ) );

		if ( ! empty( $attributes_string ) ) {
			$cart_item_name = sprintf( '%s (%s)', $cart_item_name, $attributes_string );
		}
	}

	return $cart_item_name;
}
