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

    <div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0;">
        <p style="margin: 0;">
            <?php
            /* translators: %d: nombre de produits */
            printf( esc_html__( '%d produit(s) trouvé(s)', 'bihr-woocommerce-importer' ), intval( $total ) );
            ?>
        </p>
        
        <?php if ( ! empty( $products ) ) : ?>
            <div>
                <button type="button" id="refresh-selected-stocks" class="button button-primary" disabled>
                    <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                    <?php esc_html_e( 'Actualiser les stocks sélectionnés', 'bihr-woocommerce-importer' ); ?>
                    (<span id="selected-count">0</span>)
                </button>
                <button type="button" id="refresh-all-stocks" class="button">
                    <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                    <?php esc_html_e( 'Actualiser tous les stocks', 'bihr-woocommerce-importer' ); ?>
                </button>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Zone de notification -->
    <div id="stock-refresh-notification" style="display: none; background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; margin: 10px 0; border-radius: 4px;">
        <strong id="notification-message"></strong>
        <div id="notification-progress" style="margin-top: 8px;">
            <div style="background: #fff; height: 20px; border-radius: 3px; overflow: hidden;">
                <div id="progress-bar" style="background: #28a745; height: 100%; width: 0%; transition: width 0.3s;"></div>
            </div>
            <small id="progress-text" style="display: block; margin-top: 5px;"></small>
        </div>
    </div>

    <?php if ( empty( $products ) ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'Aucun produit trouvé.', 'bihr-woocommerce-importer' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped" id="imported-products-table">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="select-all-products" title="<?php esc_attr_e( 'Tout sélectionner', 'bihr-woocommerce-importer' ); ?>">
                    </th>
                    <th style="width: 80px;"><?php esc_html_e( 'Image', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 30%;"><?php esc_html_e( 'Nom du produit', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 150px;"><?php esc_html_e( 'Code BIHR', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 100px;"><?php esc_html_e( 'Prix', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 150px;"><?php esc_html_e( 'Stock', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 100px;"><?php esc_html_e( 'Statut', 'bihr-woocommerce-importer' ); ?></th>
                    <th style="width: 180px;"><?php esc_html_e( 'Actions', 'bihr-woocommerce-importer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $products as $product ) : 
                    $product_code = $product->get_sku();
                    // Si pas de SKU, chercher dans les meta données BIHR
                    if ( empty( $product_code ) ) {
                        $product_code = get_post_meta( $product->get_id(), '_bihr_product_code', true );
                    }
                    $stock_quantity = $product->get_stock_quantity();
                    $stock_status = $product->get_stock_status();
                    ?>
                    <tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
                        <td>
                            <input type="checkbox" class="select-product" value="<?php echo esc_attr( $product->get_id() ); ?>" data-product-code="<?php echo esc_attr( $product_code ); ?>">
                        </td>
                        <td style="padding: 8px; text-align: center;">
                            <?php echo wp_kses_post( $product->get_image( 'thumbnail' ) ); ?>
                        </td>
                        <td style="padding: 8px;">
                            <strong>
                                <a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>">
                                    <?php echo esc_html( $product->get_name() ); ?>
                                </a>
                            </strong>
                        </td>
                        <td>
                            <code style="background: #f0f0f1; padding: 3px 6px; border-radius: 3px; font-size: 12px;">
                                <?php echo esc_html( $product_code ? $product_code : __( 'N/A', 'bihr-woocommerce-importer' ) ); ?>
                            </code>
                        </td>
                        <td>
                            <?php echo wp_kses_post( $product->get_price_html() ); ?>
                        </td>
                        <td class="stock-cell" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-product-code="<?php echo esc_attr( $product_code ); ?>">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div>
                                    <div class="stock-value">
                                        <?php
                                        if ( $stock_quantity !== null ) {
                                            echo '<strong>' . esc_html( $stock_quantity ) . '</strong>';
                                        } else {
                                            echo '<strong>' . esc_html__( 'N/A', 'bihr-woocommerce-importer' ) . '</strong>';
                                        }
                                        ?>
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
                                </div>
                                <button type="button" 
                                        class="refresh-stock button button-small" 
                                        title="<?php esc_attr_e( 'Rafraîchir le stock', 'bihr-woocommerce-importer' ); ?>"
                                        style="padding: 4px 8px;">
                                    <span class="dashicons dashicons-update" style="font-size: 16px;"></span>
                                </button>
                            </div>
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
#imported-products-table img {
    max-width: 60px;
    height: auto;
    display: block;
    margin: 0 auto;
}
</style>

<script>
jQuery(document).ready(function($) {
    console.log('=== BIHR Imported Products Page ===');
    console.log('bihrProgressData:', typeof bihrProgressData !== 'undefined' ? bihrProgressData : 'NON DÉFINI');
    console.log('ajaxurl:', typeof ajaxurl !== 'undefined' ? ajaxurl : 'NON DÉFINI');
    
    // Utiliser la bonne URL AJAX
    var ajaxUrl = typeof bihrProgressData !== 'undefined' && bihrProgressData.ajaxurl 
        ? bihrProgressData.ajaxurl 
        : (typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo admin_url( 'admin-ajax.php' ); ?>');
    
    console.log('URL AJAX utilisée:', ajaxUrl);
    
    var nonce = typeof bihrProgressData !== 'undefined' && bihrProgressData.nonce 
        ? bihrProgressData.nonce 
        : '';
    
    console.log('Nonce:', nonce ? 'PRÉSENT' : 'MANQUANT');
    
    if (!nonce) {
        console.error('⚠️ ERREUR: Nonce manquant! Le script bihr-progress.js n\'est peut-être pas chargé.');
    }
    
    // Compteur de produits sélectionnés
    function updateSelectedCount() {
        var count = $('.select-product:checked').length;
        $('#selected-count').text(count);
        $('#refresh-selected-stocks').prop('disabled', count === 0);
    }
    
    // Sélection individuelle
    $('.select-product').on('change', updateSelectedCount);
    
    // Tout sélectionner / Tout désélectionner
    $('#select-all-products').on('change', function() {
        $('.select-product').prop('checked', $(this).is(':checked'));
        updateSelectedCount();
    });
    
    // Actualiser les stocks sélectionnés
    $('#refresh-selected-stocks').on('click', function() {
        var selectedProducts = [];
        $('.select-product:checked').each(function() {
            selectedProducts.push({
                id: $(this).val(),
                code: $(this).data('product-code')
            });
        });
        
        if (selectedProducts.length === 0) {
            alert('Veuillez sélectionner au moins un produit.');
            return;
        }
        
        refreshMultipleStocks(selectedProducts);
    });
    
    // Actualiser tous les stocks
    $('#refresh-all-stocks').on('click', function() {
        if (!confirm('Voulez-vous vraiment actualiser les stocks de tous les produits de cette page ?')) {
            return;
        }
        
        var allProducts = [];
        $('.select-product').each(function() {
            allProducts.push({
                id: $(this).val(),
                code: $(this).data('product-code')
            });
        });
        
        refreshMultipleStocks(allProducts);
    });
    
    // Fonction pour actualiser plusieurs stocks
    function refreshMultipleStocks(products) {
        var $notification = $('#stock-refresh-notification');
        var $message = $('#notification-message');
        var $progressBar = $('#progress-bar');
        var $progressText = $('#progress-text');
        
        var total = products.length;
        var processed = 0;
        var succeeded = 0;
        var failed = 0;
        
        $message.text('Actualisation en cours...');
        $progressText.text('0 / ' + total + ' produits traités');
        $progressBar.css('width', '0%');
        $notification.show();
        
        // Désactiver les boutons pendant le traitement
        $('#refresh-selected-stocks, #refresh-all-stocks').prop('disabled', true);
        
        function processNext(index) {
            if (index >= products.length) {
                // Terminé
                $notification.css('background', '#d4edda');
                $message.text('✓ Actualisation terminée : ' + succeeded + ' réussis, ' + failed + ' échoués');
                setTimeout(function() {
                    $notification.fadeOut();
                    $('#refresh-selected-stocks, #refresh-all-stocks').prop('disabled', false);
                    updateSelectedCount();
                }, 3000);
                return;
            }
            
            var product = products[index];
            var $cell = $('.stock-cell[data-product-id="' + product.id + '"]');
            var $button = $cell.find('.refresh-stock');
            
            // Animation sur le bouton
            $button.find('.dashicons').addClass('spin');
            $cell.css('background-color', '#f0f8ff');
            
            console.log('Envoi requête AJAX pour produit:', product.id, product.code);
            
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bihr_refresh_stock',
                    product_code: product.code,
                    product_id: product.id,
                    nonce: nonce
                },
                beforeSend: function() {
                    console.log('Requête envoyée:', {
                        action: 'bihr_refresh_stock',
                        product_code: product.code,
                        product_id: product.id,
                        nonce: nonce ? 'PRÉSENT' : 'MANQUANT'
                    });
                },
                success: function(response) {
                    console.log('Réponse reçue:', response);
                    if (response.success) {
                        succeeded++;
                        var stockLevel = response.data.stock_level;
                        var $stockValue = $cell.find('.stock-value');
                        var stockHtml = '<strong style="color: green;">' + stockLevel + '</strong>';
                        
                        if (stockLevel === 0) {
                            stockHtml = '<strong style="color: red;">0</strong>';
                        } else if (stockLevel < 5) {
                            stockHtml = '<strong style="color: orange;">' + stockLevel + '</strong>';
                        }
                        
                        $stockValue.html(stockHtml);
                        
                        // Mise à jour du statut
                        if (response.data.updated) {
                            var $statusLabel = $cell.find('small');
                            if (stockLevel > 0) {
                                $statusLabel.removeClass('stock-status-outofstock stock-status-onbackorder')
                                           .addClass('stock-status-instock')
                                           .text('En stock');
                            } else {
                                $statusLabel.removeClass('stock-status-instock stock-status-onbackorder')
                                           .addClass('stock-status-outofstock')
                                           .text('Rupture');
                            }
                        }
                        
                        $cell.css('background-color', '#d4edda');
                    } else {
                        failed++;
                        $cell.css('background-color', '#f8d7da');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Erreur AJAX:', {xhr: xhr, status: status, error: error});
                    console.error('Response text:', xhr.responseText);
                    failed++;
                    $cell.css('background-color', '#f8d7da');
                },
                complete: function() {
                    $button.find('.dashicons').removeClass('spin');
                    setTimeout(function() {
                        $cell.css('background-color', '');
                    }, 2000);
                    
                    processed++;
                    var percent = Math.round((processed / total) * 100);
                    $progressBar.css('width', percent + '%');
                    $progressText.text(processed + ' / ' + total + ' produits traités');
                    
                    // Traiter le suivant
                    processNext(index + 1);
                }
            });
        }
        
        processNext(0);
    }
});
</script>
