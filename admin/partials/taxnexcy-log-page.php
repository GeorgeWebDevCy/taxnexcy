<div class="wrap">
    <h1>Taxnexcy Log</h1>
    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
        <?php wp_nonce_field( 'taxnexcy_clear_log' ); ?>
        <input type="hidden" name="action" value="taxnexcy_clear_log" />
        <p><input type="submit" class="button" value="Clear Log" /></p>
    </form>
    <pre style="background:#fff;border:1px solid #ccc;padding:10px;max-height:400px;overflow:auto;">
<?php echo esc_html( implode( "\n", $logs ) ); ?>
    </pre>
</div>
