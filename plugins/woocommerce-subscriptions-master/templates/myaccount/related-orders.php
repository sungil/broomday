<?php
/**
 * Related Orders table on the View Subscription page
 *
 * @author   Prospress
 * @category WooCommerce Subscriptions/Templates
 * @version  2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<header>
	<h2><?php esc_html_e( 'Pedidos Relacionados', 'woocommerce' ); ?></h2>
</header>

<table class="shop_table shop_table_responsive my_account_orders">

	<thead>
		<tr>
			<th class="order-number"><span class="nobr"><?php esc_html_e( 'Orden', 'woocommerce-subscriptions' ); ?></span></th>
			<th class="order-date"><span class="nobr"><?php esc_html_e( '
Fecha', 'woocommerce-subscriptions' ); ?></span></th>
			<th class="order-status"><span class="nobr"><?php esc_html_e( 'Estado', 'woocommerce-subscriptions' ); ?></span></th>
			<th class="order-total"><span class="nobr"><?php echo esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions' ); ?></span></th>
			<!--<th class="order-booking"><span class="nobr"><?php echo esc_html_x( '
Fecha / hora de la próxima reserva', 'table heading', 'woocommerce-subscriptions' ); ?></span></th>-->
			<th class="order-actions">&nbsp;</th>
		</tr>
	</thead>

	<tbody>
		<?php  foreach ( $subscription_orders as $subscription_order ) {
			$order      = wc_get_order( $subscription_order );
			//echo "<pre>";print_r($order);die;
			$item_count = $order->get_item_count();
			$order_date = wcs_get_datetime_utc_string( wcs_get_objects_property( $order, 'date_created' ) );

			?><tr class="order">
				<td class="order-number" data-title="<?php esc_attr_e( 'Order Number', 'woocommerce-subscriptions' ); ?>">
					<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
						<?php echo sprintf( esc_html_x( '#%s', 'hash before order number', 'woocommerce-subscriptions' ), esc_html( $order->get_order_number() ) ); ?>
					</a>
				</td>
				<td class="order-date" data-title="<?php esc_attr_e( 'Date', 'woocommerce-subscriptions' ); ?>">
					<time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', wcs_date_to_time( $order_date ) ) ); ?>" title="<?php echo esc_attr( wcs_date_to_time( $order_date ) ); ?>"><?php echo wp_kses_post( date_i18n( get_option( 'date_format' ), wcs_date_to_time( $order_date ) ) ); ?></time>
				</td>
				<td class="order-status" data-title="<?php esc_attr_e( 'Status', 'woocommerce-subscriptions' ); ?>" style="white-space:nowrap;">
					<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
				</td>
				<td class="order-total" data-title="<?php echo esc_attr_x( 'Total', 'Used in data attribute. Escaped', 'woocommerce-subscriptions' ); ?>">
					<?php
					// translators: $1: formatted order total for the order, $2: number of items bought
					echo wp_kses_post( sprintf( _n( '%1$s for %2$d item', '%1$s for %2$d items', $item_count, 'woocommerce-subscriptions' ), $order->get_formatted_order_total(), $item_count ) );
					?>
				</td>
			<!--<td class="order-booking" data-title="<?php echo esc_attr_x( 'Next Booking Date/Time', 'Used in data attribute. Escaped', 'woocommerce-subscriptions' ); ?>">
				<?php
				// translators: $1: formatted order total for the order, $2: number of items bought
				$date_type="last_order_date_created"; echo wp_kses_post(date('F j, Y', strtotime($subscription_order->schedule_next_payment )));
				?>
			</td>-->
				<td class="order-actions">
					<?php $actions = array();

					if ( in_array( $order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed' ), $order ) ) && wcs_get_objects_property( $order, 'id' ) == $subscription->get_last_order( 'ids', 'any' ) ) {
						$actions['pay'] = array(
							'url'  => $order->get_checkout_payment_url(),
							'name' => esc_html_x( 'Pay', 'pay for a subscription', 'woocommerce-subscriptions' ),
						);
					}

					if ( in_array( $order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order ) ) ) {
						$redirect = wc_get_page_permalink( 'myaccount' );

						if ( wcs_is_view_subscription_page() ) {
							$redirect = $subscription->get_view_order_url();
						}

						$actions['cancel'] = array(
							'url'  => $order->get_cancel_order_url( $redirect ),
							'name' => esc_html_x( 'Cancel', 'an action on a subscription', 'woocommerce-subscriptions' ),
						);
					}

					$actions['view'] = array(
						'url'  => $order->get_view_order_url(),
						'name' => esc_html_x( 'View', 'view a subscription', 'woocommerce-subscriptions' ),
					);

					$actions = apply_filters( 'woocommerce_my_account_my_orders_actions', $actions, $order );

					if ( $actions ) {
						foreach ( $actions as $key => $action ) {
							echo wp_kses_post( '<a href="' . esc_url( $action['url'] ) . '" class="button ' . sanitize_html_class( $key ) . '">' . esc_html( $action['name'] ) . '</a>' );
						}
					}
					?>
				</td>
			</tr>
		<?php } ?>
	</tbody>
</table>

<?php do_action( 'woocommerce_subscription_details_after_subscription_related_orders_table', $subscription ); ?>
