<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Variables fournies par BihrWI_Admin::render_products_page()
 *
 * @var array $products
 * @var int   $current_page
 * @var int   $per_page
 * @var int   $total
 * @var int   $total_pages
 */

$status_data = get_option( 'bihrwi_prices_generation', array() );
?>

<div class="wrap">
    <h1>Bihr Import – Produits Bihr</h1>

    <?php
    /* =======================
     *  NOTIFICATIONS (GET)
     * ======================= */

    // Fusion catalogues
    if ( isset( $_GET['bihrwi_merge_success'] ) ) : ?>
        <div class="notice notice-success"><p>
            Fusion des catalogues terminée. <?php echo intval( $_GET['bihrwi_merge_count'] ); ?> produits fusionnés.
        </p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['bihrwi_merge_error'] ) ) : ?>
        <div class="notice notice-error"><p>
            Erreur lors de la fusion des catalogues :
            <?php echo esc_html( wp_unslash( $_GET['bihrwi_msg'] ) ); ?>
        </p></div>
    <?php endif; ?>

    <!-- Import produit -->
    <?php if ( isset( $_GET['bihrwi_import_success'] ) ) : ?>
        <div class="notice notice-success"><p>
            Produit importé dans WooCommerce (ID : <?php echo intval( $_GET['imported_id'] ); ?>).
        </p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['bihrwi_import_error'] ) ) : ?>
        <div class="notice notice-error"><p>
            Erreur lors de l’import du produit :
            <?php echo esc_html( wp_unslash( $_GET['bihrwi_msg'] ) ); ?>
        </p></div>
    <?php endif; ?>

    <!-- Statut vérification manuelle du catalog Prices -->
    <?php if ( isset( $_GET['bihrwi_check_status'] ) ) : ?>
        <?php if ( $_GET['bihrwi_check_status'] === 'processing' ) : ?>
            <div class="notice notice-warning"><p>
                Le fichier Prices est toujours en cours de génération (PROCESSING).
            </p></div>
        <?php elseif ( $_GET['bihrwi_check_status'] === 'done' ) : ?>
            <div class="notice notice-success"><p>
                Le fichier Prices est prêt et a été téléchargé.
            </p></div>
        <?php elseif ( $_GET['bihrwi_check_status'] === 'error' ) : ?>
            <div class="notice notice-error"><p>
                Erreur lors de la génération du fichier Prices :
                <?php echo esc_html( wp_unslash( $_GET['bihrwi_msg'] ) ); ?>
            </p></div>
        <?php elseif ( $_GET['bihrwi_check_status'] === 'downloadfail' ) : ?>
            <div class="notice notice-error"><p>
                Le fichier Prices est marqué comme prêt, mais le téléchargement a échoué.
            </p></div>
        <?php elseif ( $_GET['bihrwi_check_status'] === 'exception' ) : ?>
            <div class="notice notice-error"><p>
                Erreur inattendue lors de la vérification du catalog Prices :
                <?php echo esc_html( wp_unslash( $_GET['bihrwi_msg'] ) ); ?>
            </p></div>
        <?php elseif ( $_GET['bihrwi_check_status'] === 'noticket' ) : ?>
            <div class="notice notice-error"><p>
                Aucun TicketID en cours. Lance d’abord la génération du catalog Prices.
            </p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( isset( $_GET['bihrwi_prices_started'] ) ) : ?>
        <div class="notice notice-success"><p>
            Génération du catalog Prices démarrée. Le statut sera vérifié automatiquement par WP-Cron.
        </p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['bihrwi_prices_error'] ) ) : ?>
        <div class="notice notice-error"><p>
            Erreur lors du démarrage du catalog Prices :
            <?php echo esc_html( wp_unslash( $_GET['bihrwi_msg'] ) ); ?>
        </p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['bihrwi_reset_success'] ) ) : ?>
        <div class="notice notice-success"><p>
            Toutes les données ont été effacées avec succès.
        </p></div>
    <?php endif; ?>


    <!-- =========================================================
         1. FUSION DES CATALOGUES CSV
    ========================================================== -->

    <h2>1. Fusion des catalogues CSV</h2>

    <p>
        Place tous les fichiers CSV Bihr (<code>references</code>, <code>prices</code>,
        <code>images</code>, <code>inventory</code>, <code>attributes</code>) dans
        <code>wp-content/uploads/bihr-import/</code>, puis clique sur le bouton ci-dessous.
    </p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'bihrwi_merge_catalogs_action', 'bihrwi_merge_catalogs_nonce' ); ?>
        <input type="hidden" name="action" value="bihrwi_merge_catalogs" />
        <?php submit_button( 'Fusionner les catalogues', 'primary' ); ?>
    </form>

    <!-- Bouton pour effacer les données -->
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;" onsubmit="return confirm('Êtes-vous sûr de vouloir effacer toutes les données de la table wp_bihr_products ?');">
        <?php wp_nonce_field( 'bihrwi_reset_data_action', 'bihrwi_reset_data_nonce' ); ?>
        <input type="hidden" name="action" value="bihrwi_reset_data" />
        <?php submit_button( 'Effacer les données', 'delete', '', false ); ?>
    </form>

    <hr />

    <!-- =========================================================
         2. CATALOG PRICES (ASYNC)
    ========================================================== -->
    <h2>2. Catalog Prices (gestion asynchrone)</h2>

    <p>
        Le catalog <strong>Prices</strong> est spécifique à ton compte et peut prendre 30 à 60 minutes
        pour être généré lors de la première demande de la journée. Pour éviter les timeouts,
        la génération est surveillée en tâche de fond via WP-Cron.
    </p>

    <!-- Bouton pour démarrer la génération -->
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'bihrwi_start_prices_action', 'bihrwi_start_prices_nonce' ); ?>
        <input type="hidden" name="action" value="bihrwi_start_prices_generation" />
        <?php submit_button( 'Lancer la génération du catalog Prices', 'secondary' ); ?>
    </form>

    <!-- Bouton pour vérifier immédiatement -->
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
        <?php wp_nonce_field( 'bihrwi_check_prices_now_action', 'bihrwi_check_prices_now_nonce' ); ?>
        <input type="hidden" name="action" value="bihrwi_check_prices_now" />
        <?php submit_button( 'Vérifier maintenant si le catalog Prices est prêt', 'secondary' ); ?>
    </form>

    <p>
        <?php if ( ! empty( $status_data['ticket_id'] ) ) : ?>
            <strong>TicketID actuel :</strong> <?php echo esc_html( $status_data['ticket_id'] ); ?><br />
            <?php if ( ! empty( $status_data['started_at'] ) ) : ?>
                <em>Démarré le : <?php echo esc_html( $status_data['started_at'] ); ?></em><br />
            <?php endif; ?>
            <?php if ( ! empty( $status_data['last_status'] ) ) : ?>
                <strong>Dernier statut :</strong> <?php echo esc_html( $status_data['last_status'] ); ?><br />
            <?php endif; ?>
            <?php if ( ! empty( $status_data['last_checked'] ) ) : ?>
                <em>Dernière vérification cron :</em> <?php echo esc_html( $status_data['last_checked'] ); ?><br />
            <?php endif; ?>
            Le plugin vérifie automatiquement le statut toutes les 5 minutes via WP-Cron
            et télécharge le fichier dès qu’il est prêt.
        <?php else : ?>
            Aucune génération de catalog Prices en cours actuellement.
        <?php endif; ?>
    </p>

    <hr />

    <!-- =========================================================
         3. PREVIEW TABLE wp_bihr_products
    ========================================================== -->

    <h2>3. Prévisualisation des produits Bihr (table wp_bihr_products)</h2>

    <p>
        <strong>Total :</strong> <?php echo intval( $total ); ?> produits enregistrés.
    </p>

    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th style="width:120px;">Code produit</th>
                <th>Nom</th>
                <th>Description</th>
                <th style="width:80px;">Image</th>
                <th style="width:80px;">Stock</th>
                <th style="width:120px;">Prix HT (dealer)</th>
                <th style="width:160px;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( ! empty( $products ) ) : ?>
            <?php foreach ( $products as $row ) : ?>
                <tr>
                    <td><?php echo intval( $row->id ); ?></td>
                    <td><?php echo esc_html( $row->product_code ); ?></td>
                    <td><?php echo esc_html( $row->name ); ?></td>
                    <td>
                        <?php
                        $desc = $row->description;
                        if ( $desc ) {
                            // On tronque un peu pour l'affichage
                            $desc = wp_trim_words( $desc, 30, '…' );
                            echo nl2br( esc_html( $desc ) );
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if ( ! empty( $row->image_url ) ) {
                            $img_url = $row->image_url;
                            // Si ce n’est pas une URL absolue, on ajoute le préfixe https://api.mybihr.com
                            if ( ! preg_match( '#^https?://#i', $img_url ) ) {
                                $img_url = rtrim( BIHRWI_IMAGE_BASE_URL, '/' ) . '/' . ltrim( $img_url, '/' );
                            }
                            ?>
                            <img src="<?php echo esc_url( $img_url ); ?>" style="max-width:60px;height:auto;" />
                            <?php
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if ( $row->stock_level !== null ) {
                            echo intval( $row->stock_level );
                            if ( ! empty( $row->stock_description ) ) {
                                echo '<br /><small>' . esc_html( $row->stock_description ) . '</small>';
                            }
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if ( $row->dealer_price_ht !== null ) {
                            $price = (float) $row->dealer_price_ht;
                            echo esc_html( number_format( $price, 2, ',', ' ' ) ) . ' €';
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </td>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( 'bihrwi_import_product_action', 'bihrwi_import_product_nonce' ); ?>
                            <input type="hidden" name="action" value="bihrwi_import_product" />
                            <input type="hidden" name="bihrwi_product_id" value="<?php echo intval( $row->id ); ?>" />
                            <?php submit_button( 'Importer dans WooCommerce', 'secondary small', '', false ); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr><td colspan="8">Aucun produit trouvé dans la table <code>wp_bihr_products</code>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                $base_url = remove_query_arg( array( 'paged' ), $_SERVER['REQUEST_URI'] );
                $base_url = esc_url( $base_url );

                if ( $current_page > 1 ) {
                    $prev_url = add_query_arg( 'paged', $current_page - 1, $base_url );
                    echo '<a class="button" href="' . esc_url( $prev_url ) . '">&laquo; Page précédente</a> ';
                }

                if ( $current_page < $total_pages ) {
                    $next_url = add_query_arg( 'paged', $current_page + 1, $base_url );
                    echo '<a class="button" href="' . esc_url( $next_url ) . '">Page suivante &raquo;</a>';
                }
                ?>
                <span style="margin-left:10px;">
                    Page <?php echo intval( $current_page ); ?> / <?php echo intval( $total_pages ); ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

</div>
