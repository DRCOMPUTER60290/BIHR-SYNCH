<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1>Logs Bihr</h1>

    <?php if ( isset( $_GET['bihrwi_cleared'] ) ) : ?>
        <div class="notice notice-success"><p>Les logs ont été effacés.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:15px;">
        <?php wp_nonce_field( 'bihrwi_clear_logs_action', 'bihrwi_clear_logs_nonce' ); ?>
        <input type="hidden" name="action" value="bihrwi_clear_logs" />
        <?php submit_button( 'Effacer les logs', 'delete' ); ?>
    </form>

    <h2>Contenu du fichier de logs</h2>
    <textarea readonly style="width:100%;height:500px;"><?php echo esc_textarea( $log_contents ); ?></textarea>
</div>
