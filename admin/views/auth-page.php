<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div class="wrap">
    <h1>Authentification Bihr</h1>

    <?php if ( isset( $_GET['bihrwi_auth_success'] ) ) : ?>
        <div class="notice notice-success"><p>✓ Authentification Bihr réussie. Le token a été récupéré.</p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['bihrwi_auth_error'] ) ) : ?>
        <div class="notice notice-error">
            <p><strong>✗ Échec de l'authentification Bihr.</strong></p>
            <?php if ( ! empty( $_GET['bihrwi_msg'] ) ) : ?>
                <p><strong>Détail :</strong> <?php echo esc_html( wp_unslash( $_GET['bihrwi_msg'] ) ); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['bihrwi_openai_success'] ) ) : ?>
        <div class="notice notice-success">
            <p><strong>✓ Clé OpenAI valide et opérationnelle !</strong></p>
            <p>L'enrichissement automatique des descriptions par IA est activé.</p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['bihrwi_openai_error'] ) ) : ?>
        <div class="notice notice-warning">
            <p><strong>⚠ Problème avec la clé OpenAI</strong></p>
            <?php if ( ! empty( $_GET['bihrwi_openai_msg'] ) ) : ?>
                <p><strong>Détail :</strong> <?php echo esc_html( wp_unslash( $_GET['bihrwi_openai_msg'] ) ); ?></p>
            <?php endif; ?>
            <p><em>L'import des produits fonctionnera sans enrichissement IA.</em></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'bihrwi_authenticate_action', 'bihrwi_authenticate_nonce' ); ?>
        <input type="hidden" name="action" value="bihrwi_authenticate" />

        <table class="form-table">
            <tr>
                <th scope="row"><label for="bihrwi_username">Username Bihr</label></th>
                <td><input name="bihrwi_username" id="bihrwi_username" type="text" class="regular-text" value="<?php echo esc_attr( $username ); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="bihrwi_password">Password Bihr</label></th>
                <td><input name="bihrwi_password" id="bihrwi_password" type="password" class="regular-text" value="<?php echo esc_attr( $password ); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="bihrwi_openai_key">Clé API OpenAI (optionnel)</label></th>
                <td>
                    <input name="bihrwi_openai_key" id="bihrwi_openai_key" type="password" class="regular-text" value="<?php echo esc_attr( $openai_key ); ?>">
                    <p class="description">
                        Si renseignée, l'IA générera automatiquement des descriptions courtes et longues lors de l'import des produits.
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button( 'Sauvegarder & Tester l\'authentification' ); ?>
    </form>

    <h2>Token actuel</h2>
    <?php if ( ! empty( $last_token ) ) : ?>
        <p><strong>Token :</strong></p>
        <textarea readonly style="width:100%;height:120px;"><?php echo esc_textarea( $last_token ); ?></textarea>
        <p><em>Le token est valable environ 30 minutes.</em></p>
    <?php else : ?>
        <p>Aucun token en cache. Cliquez sur le bouton ci-dessus pour tester l'authentification et récupérer un token.</p>
    <?php endif; ?>
</div>
