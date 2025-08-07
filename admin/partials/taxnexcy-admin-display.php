<?php
/**
 * Display the cached Fluent Forms entry on the order screen.
 *
 * @var WC_Order $order
 */

if ( ! isset( $order ) || ! $order instanceof WC_Order ) {
    return;
}
?>
<div class="order_data_column">
    <h4><?php esc_html_e( 'Fluent Form Entry', 'taxnexcy' ); ?></h4>
    <?php echo $order->get_meta( '_ff_entry_html', true ); ?>
</div>
