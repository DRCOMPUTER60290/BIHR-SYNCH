<?php
/**
 * Vue de gestion des produits déjà importés
 *
 * @package BihrWoocommerceImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Récupération de la page courante
$paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$per_page = 50;

// Récupération des filtres
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$stock_filter = isset( $_GET['stock_status'] ) ? sanitize_text_field( wp_unslash( $_GET['stock_status'] ) ) : '';

// Arguments pour la requête WooCommerce
$args = array(
    'status'   => 'publish',
    'limit'    => $per_page,
    'page'     => $paged,
    'orderby'  => 'date',
    'order'    => 'DESC',
    'paginate' => true,
);

// Filtre par recherche
if ( ! empty( $search ) ) {
    $args['s'] = $search;
}

// Filtre par stock
if ( ! empty( $stock_filter ) ) {
    $args['stock_status'] = $stock_filter;
}

// Récupération des produits
$results = wc_get_products( $args );
$products = $results->products;
$total = $results->total;
$total_pages = $results->max_num_pages;
?>

<div class="wrap">
    <h1><?php echo esc_html__( 'Produits Importés de BIHR', 'bihr-woocommerce-importer' ); ?></h1>
    
    <div class="bihrwi-filters" style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ccc; border-radius: 4px;">
        <form method="get" action="">
            <input type="hidden" name="page" value="bihrwi_imported_products">
            
            <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                <div>
                    <label for="search-input"><?php esc_html_e( 'Recherche', 'bihr-woocommerce-importer' ); ?></label>
                    <input type="text" 
                           id="search-input" 
                           name="s" 
                           value="<?php echo esc_attr( $search ); ?>" 
                           placeholder="<?php esc_attr_e( 'Nom ou code produit...', 'bihr-woocommerce-importer' ); ?>"
                           style="width: 250px;">
                </div>
                
                <div>
                    <label for="stock-filter"><?php esc_html_e( 'Stock', 'bihr-woocommerce-importer' ); ?></label>
                    <select id="stock-filter" name="stock_status">
                        <option value=""><?php esc_html_e( 'Tous', 'bihr-woocommerce-importer' ); ?></option>
                        <option value="instock" <?php selected( $stock_filter, 'instock' ); ?>><?php esc_html_e( 'En stock', 'bihr-woocommerce-importer' ); ?></option>
                        <option value="outofstock" <?php selected( $stock_filter, 'outofstock' ); ?>><?php esc_html_e( 'Rupture', 'bihr-woocommerce-importer' ); ?></option>
                        <option value="onbackorder" <?php selected( $stock_filter, 'onbackorder' ); ?>><?php esc_html_e( 'Sur commande', 'bihr-woocommerce-importer' ); ?></option>
                    </select>
                </div>
                
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Filtrer', 'bihr-woocommerce-importer' ); ?>
                </button>
                
                <a href="?page=bihrwi_imported_products" class="button">
                    <?php esc_html_e( 'Réinitialiser', 'bihr-woocommerce-importer' ); ?>
                </a>
            </div>
        </form>
    </div>

    <p>
        <?php
        /* translators: %d: nombre de produits */
        printf( esc_html__( '%d produit(s) trouvé(s)', 'bihr-woocommerce-importer' ), intval( $total ) );
        ?>
    </p>

    <?php if ( empty( $products ) ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'Aucun produit trouvé.', 'bihr-woocommerce-importer' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 60px;"><?php esc_html_e( 'Image', 'bihr-woocommerce-importer' ); ?></th>
                    <th><?php esc_html_e( 'Nom du produit', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 120px;"><?php esc_html_e( 'Code BIHR', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 100px;"><?php esc_html_e( 'Prix', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 120px;"><?php esc_html_e( 'Stock', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 100px;"><?php esc_html_e( 'Statut', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 150px;"><?php esc_html_e( 'Actions', 'bihr-woocommerce-importer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $products as $product ) : 
                    $product_code = $product->get_sku();
                    $stock_quantity = $product->get_stock_quantity();
                    $stock_status = $product->get_stock_status();
                    ?>
                    <tr>
                        <td>
                            <?php echo wp_kses_post( $product->get_image( 'thumbnail' ) ); ?>
                        </td>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>">
                                    <?php echo esc_html( $product->get_name() ); ?>
                                </a>
                            </strong>
                        </td>
                        <td>
                            <code><?php echo esc_html( $product_code ); ?></code>
                        </td>
                        <td>
                            <?php echo wp_kses_post( $product->get_price_html() ); ?>
                        </td>
                        <td class="stock-cell" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-product-code="<?php echo esc_attr( $product_code ); ?>">
                            <div class="stock-display" style="display: flex; align-items: center; gap: 8px;">
                                <span class="stock-value">
                                    <?php
                                    if ( $stock_quantity !== null ) {
                                        echo esc_html( $stock_quantity );
                                    } else {
                                        echo esc_html__( 'N/A', 'bihr-woocommerce-importer' );
                                    }
                                    ?>
                                </span>
                                <button type="button" 
                                        class="refresh-stock button-link" 
                                        title="<?php esc_attr_e( 'Rafraîchir le stock', 'bihr-woocommerce-importer' ); ?>"
                                        style="padding: 2px; line-height: 1;">
                                    <span class="dashicons dashicons-update"></span>
                                </button>
                            </div>
                            <small class="stock-status-<?php echo esc_attr( $stock_status ); ?>">
                                <?php
                                switch ( $stock_status ) {
                                    case 'instock':
                                        esc_html_e( 'En stock', 'bihr-woocommerce-importer' );
                                        break;
                                    case 'outofstock':
                                        esc_html_e( 'Rupture', 'bihr-woocommerce-importer' );
                                        break;
                                    case 'onbackorder':
                                        esc_html_e( 'Sur commande', 'bihr-woocommerce-importer' );
                                        break;
                                }
                                ?>
                            </small>
                        </td>
                        <td>
                            <?php
                            $status = $product->get_status();
                            $status_labels = array(
                                'publish' => __( 'Publié', 'bihr-woocommerce-importer' ),
                                'draft'   => __( 'Brouillon', 'bihr-woocommerce-importer' ),
                                'pending' => __( 'En attente', 'bihr-woocommerce-importer' ),
                                'private' => __( 'Privé', 'bihr-woocommerce-importer' ),
                            );
                            echo '<span class="status-' . esc_attr( $status ) . '">';
                            echo esc_html( $status_labels[ $status ] ?? $status );
                            echo '</span>';
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'Modifier', 'bihr-woocommerce-importer' ); ?>
                            </a>
                            <a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>" class="button button-small" target="_blank">
                                <?php esc_html_e( 'Voir', 'bihr-woocommerce-importer' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $page_links = paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'prev_text' => __( '&laquo;', 'bihr-woocommerce-importer' ),
                        'next_text' => __( '&raquo;', 'bihr-woocommerce-importer' ),
                        'total'     => $total_pages,
                        'current'   => $paged,
                    ) );

                    if ( $page_links ) {
                        echo '<span class="pagination-links">' . wp_kses_post( $page_links ) . '</span>';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.stock-status-instock {
    color: #46b450;
}
.stock-status-outofstock {
    color: #dc3232;
}
.stock-status-onbackorder {
    color: #ffb900;
}
</style>
