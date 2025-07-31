<div class="wrap">
    <h1><?php esc_html_e( 'Form Product Mapping', 'taxnexcy' ); ?></h1>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'taxnexcy_save_mappings' ); ?>
        <input type="hidden" name="action" value="taxnexcy_save_mappings" />
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Form', 'taxnexcy' ); ?></th>
                    <th><?php esc_html_e( 'Product', 'taxnexcy' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $mappings ) ) : ?>
                    <?php foreach ( $mappings as $form_id => $product_id ) : ?>
                        <tr>
                            <td>
                                <select name="taxnexcy_forms[]">
                                    <option value=""><?php esc_html_e( 'Select form', 'taxnexcy' ); ?></option>
                                    <?php foreach ( $forms as $form ) : ?>
                                        <?php $fid = is_object( $form ) ? $form->id : $form['id']; ?>
                                        <option value="<?php echo esc_attr( $fid ); ?>" <?php selected( $form_id, $fid ); ?>>
                                            <?php echo esc_html( is_object( $form ) ? $form->title : $form['title'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="taxnexcy_products[]">
                                    <option value=""><?php esc_html_e( 'Select product', 'taxnexcy' ); ?></option>
                                    <?php foreach ( $products as $product ) : ?>
                                        <?php $pid = is_object( $product ) ? $product->get_id() : $product['ID']; ?>
                                        <option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $product_id, $pid ); ?>>
                                            <?php echo esc_html( is_object( $product ) ? $product->get_name() : $product['post_title'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr>
                    <td>
                        <select name="taxnexcy_forms[]">
                            <option value=""><?php esc_html_e( 'Select form', 'taxnexcy' ); ?></option>
                            <?php foreach ( $forms as $form ) : ?>
                                <?php $fid = is_object( $form ) ? $form->id : $form['id']; ?>
                                <option value="<?php echo esc_attr( $fid ); ?>">
                                    <?php echo esc_html( is_object( $form ) ? $form->title : $form['title'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="taxnexcy_products[]">
                            <option value=""><?php esc_html_e( 'Select product', 'taxnexcy' ); ?></option>
                            <?php foreach ( $products as $product ) : ?>
                                <?php $pid = is_object( $product ) ? $product->get_id() : $product['ID']; ?>
                                <option value="<?php echo esc_attr( $pid ); ?>">
                                    <?php echo esc_html( is_object( $product ) ? $product->get_name() : $product['post_title'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>
        <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Mappings', 'taxnexcy' ); ?></button></p>
    </form>
</div>
