<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e( 'Paramètres de Synchronisation des Commandes', 'bihr-woocommerce-importer' ); ?></h1>

    <?php if ( isset( $success_message ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( $success_message ); ?></p>
        </div>
    <?php endif; ?>

    <div class="bihr-section">
        <h2><?php esc_html_e( 'Configuration de la Synchronisation Automatique', 'bihr-woocommerce-importer' ); ?></h2>
        <p>
            <?php esc_html_e( 'Lorsqu\'une commande est créée dans WooCommerce, le plugin peut automatiquement la transmettre à l\'API BIHR pour traitement.', 'bihr-woocommerce-importer' ); ?>
        </p>

        <form method="post" action="">
            <?php wp_nonce_field( 'bihrwi_order_settings_action', 'bihrwi_order_settings_nonce' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bihrwi_auto_sync_orders">
                            <?php esc_html_e( 'Synchronisation automatique', 'bihr-woocommerce-importer' ); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   id="bihrwi_auto_sync_orders" 
                                   name="bihrwi_auto_sync_orders" 
                                   value="1" 
                                   <?php checked( $auto_sync_orders, 1 ); ?>>
                            <?php esc_html_e( 'Activer la synchronisation automatique des commandes vers BIHR', 'bihr-woocommerce-importer' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Si activé, les commandes WooCommerce seront automatiquement envoyées à l\'API BIHR lors de leur création.', 'bihr-woocommerce-importer' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="bihrwi_auto_checkout">
                            <?php esc_html_e( 'Validation automatique', 'bihr-woocommerce-importer' ); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   id="bihrwi_auto_checkout" 
                                   name="bihrwi_auto_checkout" 
                                   value="1" 
                                   <?php checked( $auto_checkout, 1 ); ?>>
                            <?php esc_html_e( 'Activer la validation automatique des commandes (IsAutomaticCheckoutActivated)', 'bihr-woocommerce-importer' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Si activé, les commandes seront automatiquement validées côté BIHR sans intervention manuelle.', 'bihr-woocommerce-importer' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="bihrwi_weekly_free_shipping">
                            <?php esc_html_e( 'Livraison gratuite hebdomadaire', 'bihr-woocommerce-importer' ); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   id="bihrwi_weekly_free_shipping" 
                                   name="bihrwi_weekly_free_shipping" 
                                   value="1" 
                                   <?php checked( $weekly_free_shipping, 1 ); ?>>
                            <?php esc_html_e( 'Activer la livraison gratuite hebdomadaire (IsWeeklyFreeShippingActivated)', 'bihr-woocommerce-importer' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Si activé, bénéficiez de la livraison gratuite hebdomadaire selon les conditions BIHR.', 'bihr-woocommerce-importer' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="bihrwi_delivery_mode">
                            <?php esc_html_e( 'Mode de livraison', 'bihr-woocommerce-importer' ); ?>
                        </label>
                    </th>
                    <td>
                        <select id="bihrwi_delivery_mode" name="bihrwi_delivery_mode">
                            <option value="Default" <?php selected( $delivery_mode, 'Default' ); ?>>
                                <?php esc_html_e( 'Par défaut (Default)', 'bihr-woocommerce-importer' ); ?>
                            </option>
                            <option value="Express" <?php selected( $delivery_mode, 'Express' ); ?>>
                                <?php esc_html_e( 'Express', 'bihr-woocommerce-importer' ); ?>
                            </option>
                            <option value="Standard" <?php selected( $delivery_mode, 'Standard' ); ?>>
                                <?php esc_html_e( 'Standard', 'bihr-woocommerce-importer' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Sélectionnez le mode de livraison par défaut pour toutes les commandes.', 'bihr-woocommerce-importer' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="bihrwi_save_order_settings" class="button button-primary">
                    <?php esc_html_e( 'Enregistrer les paramètres', 'bihr-woocommerce-importer' ); ?>
                </button>
            </p>
        </form>
    </div>

    <div class="bihr-section">
        <h2><?php esc_html_e( 'Informations de Synchronisation', 'bihr-woocommerce-importer' ); ?></h2>
        
        <h3><?php esc_html_e( 'Comment ça fonctionne ?', 'bihr-woocommerce-importer' ); ?></h3>
        <ol>
            <li><?php esc_html_e( 'Un client passe une commande sur votre boutique WooCommerce', 'bihr-woocommerce-importer' ); ?></li>
            <li><?php esc_html_e( 'Le plugin vérifie si la commande contient des produits BIHR (avec un code produit BIHR)', 'bihr-woocommerce-importer' ); ?></li>
            <li><?php esc_html_e( 'La commande est automatiquement envoyée à l\'API BIHR avec les informations de livraison', 'bihr-woocommerce-importer' ); ?></li>
            <li><?php esc_html_e( 'Une note est ajoutée à la commande WooCommerce avec l\'ID de commande BIHR', 'bihr-woocommerce-importer' ); ?></li>
            <li><?php esc_html_e( 'Les logs détaillés sont disponibles dans la page "Logs"', 'bihr-woocommerce-importer' ); ?></li>
        </ol>

        <h3><?php esc_html_e( 'Format de la commande envoyée à BIHR', 'bihr-woocommerce-importer' ); ?></h3>
        <pre style="background: #f0f0f1; padding: 15px; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;">
{
  "Order": {
    "CustomerReference": "WC Order #123 - John Doe",
    "Lines": [
      {
        "ProductId": "TPCI07495",
        "Quantity": 2,
        "ReferenceType": "Not used anymore",
        "CustomerReference": "Nom du produit",
        "ReservedQuantity": 0
      }
    ],
    "IsAutomaticCheckoutActivated": true,
    "IsWeeklyFreeShippingActivated": true,
    "DeliveryMode": "Default"
  },
  "DropShippingAddress": {
    "FirstName": "John",
    "LastName": "Doe",
    "Line1": "123 rue Example",
    "Line2": "Appartement 4B",
    "ZipCode": "75001",
    "Town": "Paris",
    "Country": "FR",
    "Phone": "+33123456789"
  }
}</pre>

        <h3><?php esc_html_e( 'Données stockées dans WooCommerce', 'bihr-woocommerce-importer' ); ?></h3>
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Meta Key', 'bihr-woocommerce-importer' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'bihr-woocommerce-importer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>_bihr_sync_ticket_id</code></td>
                    <td><?php esc_html_e( 'Ticket ID unique pour tracer toutes les étapes', 'bihr-woocommerce-importer' ); ?></td>
                </tr>
                <tr>
                    <td><code>_bihr_order_synced</code></td>
                    <td><?php esc_html_e( 'Indique si la commande a été synchronisée avec succès', 'bihr-woocommerce-importer' ); ?></td>
                </tr>
                <tr>
                    <td><code>_bihr_order_id</code></td>
                    <td><?php esc_html_e( 'ID de la commande côté BIHR', 'bihr-woocommerce-importer' ); ?></td>
                </tr>
                <tr>
                    <td><code>_bihr_sync_date</code></td>
                    <td><?php esc_html_e( 'Date et heure de synchronisation', 'bihr-woocommerce-importer' ); ?></td>
                </tr>
                <tr>
                    <td><code>_bihr_order_sync_failed</code></td>
                    <td><?php esc_html_e( 'Indique si la synchronisation a échoué', 'bihr-woocommerce-importer' ); ?></td>
                </tr>
                <tr>
                    <td><code>_bihr_sync_error</code></td>
                    <td><?php esc_html_e( 'Message d\'erreur en cas d\'échec', 'bihr-woocommerce-importer' ); ?></td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin-top: 20px;"><?php esc_html_e( 'Notes importantes', 'bihr-woocommerce-importer' ); ?></h3>
        <ul>
            <li><?php esc_html_e( 'Seuls les produits avec un code produit BIHR (meta _bihr_product_code) seront synchronisés', 'bihr-woocommerce-importer' ); ?></li>
            <li><?php esc_html_e( 'Les commandes sont envoyées au moment de la création (hook: woocommerce_checkout_order_processed)', 'bihr-woocommerce-importer' ); ?></li>
            <li><?php esc_html_e( 'Les numéros de téléphone français sont automatiquement formatés au format international (+33)', 'bihr-woocommerce-importer' ); ?></li>
            <li><?php esc_html_e( 'Les adresses de livraison sont utilisées en priorité, sinon les adresses de facturation', 'bihr-woocommerce-importer' ); ?></li>
            <li><?php esc_html_e( 'Toutes les opérations sont loguées dans la page "Logs"', 'bihr-woocommerce-importer' ); ?></li>
        </ul>
    </div>

    <?php
    // Afficher les dernières commandes synchronisées
    $recent_orders = get_posts( array(
        'post_type'      => 'shop_order',
        'posts_per_page' => 10,
        'meta_key'       => '_bihr_order_synced',
        'meta_value'     => '1',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    if ( ! empty( $recent_orders ) ) :
    ?>
        <div class="bihr-section">
            <h2><?php esc_html_e( 'Dernières Commandes Synchronisées', 'bihr-woocommerce-importer' ); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Ticket ID', 'bihr-woocommerce-importer' ); ?></th>
                        <th><?php esc_html_e( 'Commande WC', 'bihr-woocommerce-importer' ); ?></th>
                        <th><?php esc_html_e( 'Client', 'bihr-woocommerce-importer' ); ?></th>
                        <th><?php esc_html_e( 'ID Commande BIHR', 'bihr-woocommerce-importer' ); ?></th>
                        <th><?php esc_html_e( 'Date de Sync', 'bihr-woocommerce-importer' ); ?></th>
                        <th><?php esc_html_e( 'Statut', 'bihr-woocommerce-importer' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $recent_orders as $post ) :
                        $order         = wc_get_order( $post->ID );
                        $ticket_id     = get_post_meta( $post->ID, '_bihr_sync_ticket_id', true );
                        $bihr_order_id = get_post_meta( $post->ID, '_bihr_order_id', true );
                        $sync_date     = get_post_meta( $post->ID, '_bihr_sync_date', true );
                        $sync_failed   = get_post_meta( $post->ID, '_bihr_order_sync_failed', true );
                    ?>
                        <tr>
                            <td><code><?php echo esc_html( $ticket_id ?: 'N/A' ); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
                                    #<?php echo esc_html( $order->get_order_number() ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?></td>
                            <td><?php echo esc_html( $bihr_order_id ?: 'N/A' ); ?></td>
                            <td><?php echo esc_html( $sync_date ?: 'N/A' ); ?></td>
                            <td>
                                <?php if ( $sync_failed ) : ?>
                                    <span style="color: red;">❌ <?php esc_html_e( 'Échec', 'bihr-woocommerce-importer' ); ?></span>
                                <?php else : ?>
                                    <span style="color: green;">✅ <?php esc_html_e( 'Synchronisé', 'bihr-woocommerce-importer' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
