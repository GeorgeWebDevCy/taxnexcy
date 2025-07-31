<div class="wrap">
    <h1><?php esc_html_e( 'Form Product Mapping', 'taxnexcy' ); ?></h1>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'taxnexcy_save_mappings' ); ?>
        <input type="hidden" name="action" value="taxnexcy_save_mappings" />
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Form ID', 'taxnexcy' ); ?></th>
                    <th><?php esc_html_e( 'Product ID', 'taxnexcy' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $mappings ) ) : ?>
                    <?php foreach ( $mappings as $form_id => $product_id ) : ?>
                        <tr>
                            <td><input type="number" name="taxnexcy_forms[]" value="<?php echo esc_attr( $form_id ); ?>" class="small-text" /></td>
                            <td><input type="number" name="taxnexcy_products[]" value="<?php echo esc_attr( $product_id ); ?>" class="small-text" /></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr>
                    <td><input type="number" name="taxnexcy_forms[]" class="small-text" /></td>
                    <td><input type="number" name="taxnexcy_products[]" class="small-text" /></td>
                </tr>
            </tbody>
        </table>
        <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Mappings', 'taxnexcy' ); ?></button></p>
    </form>
</div>
