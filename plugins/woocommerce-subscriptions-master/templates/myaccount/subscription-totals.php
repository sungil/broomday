<?php
/**
 * Subscription totals table
 *
 * @author  Prospress
 * @package WooCommerce_Subscription/Templates
 * @since 2.2.19
 * @version 2.2.19
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<?php $allow_remove_items = wcs_can_items_be_removed( $subscription ); ?>
<h2><?php esc_html_e( '
Totales de Suscripción', 'woocommerce-subscriptions' ); ?></h2>
<table class="shop_table order_details">
	<thead>
		<tr>
			<?php if ( $allow_remove_items ) : ?>
			<th class="product-remove" style="width: 3em;">&nbsp;</th>
			<?php endif; ?>
			<th class="product-name"><?php echo esc_html_x( 'Producto', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			<th class="product-total"><?php echo esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		if ( sizeof( $subscription_items = $subscription->get_items() ) > 0 ) {
			
			$extra_service = "";
			foreach( $subscription->get_items('fee') as $item_id => $item ){
								
							
						
							 $extra_service .= $item->get_name().", ";
							 
							
						}
				

			foreach ( $subscription_items as $item_id => $item ) {
				
				
				$service_description = get_post_meta($subscription->get_id(), 'service_description', true );
				
				
				
				$_product  = apply_filters( 'woocommerce_subscriptions_order_item_product', $subscription->get_product_from_item( $item ), $item );
				if ( apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
					?>
					<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $subscription ) ); ?>">
						<?php  if ( $allow_remove_items ) : ?>
							<td class="remove_item">
								<?php if ( wcs_can_item_be_removed( $item, $subscription ) ) : ?>
									<?php $confirm_notice = apply_filters( 'woocommerce_subscriptions_order_item_remove_confirmation_text', __( '¿Está seguro de que desea eliminar este elemento de su suscripción?', 'woocommerce-subscriptions' ), $item, $_product, $subscription );?>
									<a href="<?php echo esc_url( WCS_Remove_Item::get_remove_url( $subscription->get_id(), $item_id ) );?>" class="remove" onclick="return confirm('<?php printf( esc_html( $confirm_notice ) ); ?>');">&times;</a>
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td class="product-name">
							<?php
							if ( $_product && ! $_product->is_visible() ) {
								
								echo esc_html( apply_filters( 'woocommerce_order_item_name', $item['name'], $item, false ) );
							} else {
								
								echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', sprintf( '<a href="%s">%s</a>', get_permalink( $item['product_id'] ), $item['name'] ), $item, false ) );
							}

							echo wp_kses_post( apply_filters( 'woocommerce_order_item_quantity_html', ' <strong class="product-quantity">' . sprintf( '&times; %s', $item['qty'] ) . '</strong>', $item ) );

							/**
							 * Allow other plugins to add additional product information here.
							 *
							 * @param int $item_id The subscription line item ID.
							 * @param WC_Order_Item|array $item The subscription line item.
							 * @param WC_Subscription $subscription The subscription.
							 * @param bool $plain_text Wether the item meta is being generated in a plain text context.
							 */
					
							do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $subscription, false );

							wcs_display_item_meta( $item, $subscription );

							wcs_display_item_downloads( $item, $subscription );

							/**
							 * Allow other plugins to add additional product information here.
							 *
							 * @param int $item_id The subscription line item ID.
							 * @param WC_Order_Item|array $item The subscription line item.
							 * @param WC_Subscription $subscription The subscription.
							 * @param bool $plain_text Wether the item meta is being generated in a plain text context.
							 */
							//do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $subscription, false );
							?>
						</td>
						<td class="product-total">
							<?php echo wp_kses_post( $subscription->get_formatted_line_subtotal( $item ) ); ?>
						</td>
					</tr>
					<?php
				}

				if ( $subscription->has_status( array( 'completed', 'processing' ) ) && ( $purchase_note = get_post_meta( $_product->id, '_purchase_note', true ) ) ) {
					?>
					<tr class="product-purchase-note">
						<td colspan="3"><?php echo wp_kses_post( wpautop( do_shortcode( $purchase_note ) ) ); ?></td>
					</tr>
					<?php
				}?>
		<?php if($extra_service != ''){?>
		<tr class="product-purchase-note">
						<td class="product-name"><strong>Servicios extra</strong></td>
						<td class="product-name">
							<?php echo $extra_service;?>
			
						</td>
					</tr>
		<?php }?>
		<?php if($service_description != ''){?>
		<tr class="product-purchase-note">
						<td class="product-name"><strong>Descripción del Servicio</strong></td>
						<td class="product-name">
							<?php echo $service_description;?>
			
						</td>
					</tr>
		<?php }?>
		   <tr class="product-purchase-note">
						<td class="product-name"><strong>Direcciónes</strong></td>
						<td class="product-name">
							<?php echo get_post_meta( $subscription->get_id(), '_billing_address_1', true ); ?>
			
						</td>
					</tr>
		 <tr class="product-purchase-note">
						<td class="product-name"><strong>No. de Casa, apto</strong></td>
						<td class="product-name">
							<?php echo get_post_meta( $subscription->get_id(), 'billing_comments', true ); ?>
			
						</td>
					</tr>
				
			<?php }
		}
		?>
	</tbody>
		<tfoot>
		<?php

		if ( $totals = $subscription->get_order_item_totals() ) {
			foreach ( $totals as $key => $total ) {
				?>
			<tr>
				<th scope="row" <?php echo ( $allow_remove_items ) ? 'colspan="2"' : ''; ?>><?php echo esc_html( $total['label'] ); ?></th>
				<td><?php echo wp_kses_post( $total['value'] ); ?></td>
			</tr>
				<?php
			}
		} ?>
	</tfoot>
</table>
