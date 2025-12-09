<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gestion de la synchronisation automatique des commandes WooCommerce vers l'API BIHR
 */
class BihrWI_Order_Sync {

    protected $logger;
    protected $api_client;

    public function __construct( BihrWI_Logger $logger, BihrWI_API_Client $api_client ) {
        $this->logger     = $logger;
        $this->api_client = $api_client;

        // Hook sur la création de commande WooCommerce
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'sync_order_to_bihr' ), 10, 3 );
        
        // Hook sur le changement de statut de commande (optionnel)
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 10, 4 );
    }

    /**
     * Synchronise une commande WooCommerce vers l'API BIHR
     * 
     * @param int $order_id ID de la commande WooCommerce
     * @param array $posted_data Données du formulaire de checkout
     * @param WC_Order $order Objet commande WooCommerce
     */
    public function sync_order_to_bihr( $order_id, $posted_data, $order ) {
        // Vérifier si la synchronisation automatique est activée
        if ( ! get_option( 'bihrwi_auto_sync_orders', 1 ) ) {
            $this->logger->log( "Synchronisation automatique désactivée - Commande #{$order_id} ignorée" );
            return;
        }

        try {
            $this->logger->log( "=== SYNCHRONISATION COMMANDE #{$order_id} VERS BIHR ===" );

            // Vérifier si la commande n'a pas déjà été synchronisée
            if ( get_post_meta( $order_id, '_bihr_order_synced', true ) ) {
                $this->logger->log( "Commande #{$order_id} déjà synchronisée avec BIHR" );
                return;
            }

            // Construire les données de la commande
            $order_data = $this->build_order_data( $order );

            if ( ! $order_data ) {
                $this->logger->log( "Impossible de construire les données pour la commande #{$order_id}" );
                return;
            }

            // Envoyer la commande à l'API BIHR
            $result = $this->send_order_to_bihr( $order_data );

            if ( $result && isset( $result['success'] ) && $result['success'] ) {
                // Marquer la commande comme synchronisée
                update_post_meta( $order_id, '_bihr_order_synced', true );
                update_post_meta( $order_id, '_bihr_order_id', $result['order_id'] ?? '' );
                update_post_meta( $order_id, '_bihr_sync_date', current_time( 'mysql' ) );

                // Ajouter une note à la commande
                $order->add_order_note( 
                    sprintf( 
                        __( 'Commande synchronisée avec BIHR (ID BIHR: %s)', 'bihr-woocommerce-importer' ),
                        $result['order_id'] ?? 'N/A'
                    )
                );

                $this->logger->log( "Commande #{$order_id} synchronisée avec succès vers BIHR" );
            } else {
                $error_message = $result['message'] ?? 'Erreur inconnue';
                
                // Marquer comme échec
                update_post_meta( $order_id, '_bihr_order_sync_failed', true );
                update_post_meta( $order_id, '_bihr_sync_error', $error_message );

                // Ajouter une note d'erreur
                $order->add_order_note( 
                    sprintf( 
                        __( 'Échec de la synchronisation avec BIHR : %s', 'bihr-woocommerce-importer' ),
                        $error_message
                    )
                );

                $this->logger->log( "Échec synchronisation commande #{$order_id} : {$error_message}" );
            }

        } catch ( Exception $e ) {
            $this->logger->log( "Erreur synchronisation commande #{$order_id} : " . $e->getMessage() );
            update_post_meta( $order_id, '_bihr_order_sync_failed', true );
            update_post_meta( $order_id, '_bihr_sync_error', $e->getMessage() );
        }
    }

    /**
     * Construit les données de commande au format BIHR API
     * 
     * @param WC_Order $order Commande WooCommerce
     * @return array|false Données formatées ou false en cas d'erreur
     */
    protected function build_order_data( $order ) {
        // Récupération des produits de la commande
        $lines = array();
        
        foreach ( $order->get_items() as $item_id => $item ) {
            $product    = $item->get_product();
            $product_id = $product->get_id();
            
            // Récupérer le code produit BIHR
            $bihr_code = get_post_meta( $product_id, '_bihr_product_code', true );
            
            if ( empty( $bihr_code ) ) {
                $this->logger->log( "Produit #{$product_id} ({$product->get_name()}) n'a pas de code BIHR - ignoré" );
                continue;
            }

            $lines[] = array(
                'ProductId'         => $bihr_code,
                'Quantity'          => $item->get_quantity(),
                'ReferenceType'     => 'Not used anymore',
                'CustomerReference' => $product->get_name(),
                'ReservedQuantity'  => 0,
            );
        }

        // Si aucun produit BIHR, ne pas envoyer la commande
        if ( empty( $lines ) ) {
            $this->logger->log( "Aucun produit BIHR trouvé dans la commande - synchronisation annulée" );
            return false;
        }

        // Construction de la référence client
        $customer_reference = sprintf(
            'WC Order #%d - %s',
            $order->get_id(),
            $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
        );

        // Récupération des options de configuration
        $auto_checkout      = get_option( 'bihrwi_auto_checkout', true );
        $weekly_free_ship   = get_option( 'bihrwi_weekly_free_shipping', true );
        $delivery_mode      = get_option( 'bihrwi_delivery_mode', 'Default' );

        // Construction des données de commande
        $order_data = array(
            'Order' => array(
                'CustomerReference'              => $customer_reference,
                'Lines'                          => $lines,
                'IsAutomaticCheckoutActivated'   => (bool) $auto_checkout,
                'IsWeeklyFreeShippingActivated'  => (bool) $weekly_free_ship,
                'DeliveryMode'                   => $delivery_mode,
            ),
        );

        // Ajout de l'adresse de livraison (DropShipping)
        $shipping_address = $this->build_shipping_address( $order );
        if ( $shipping_address ) {
            $order_data['DropShippingAddress'] = $shipping_address;
        }

        return $order_data;
    }

    /**
     * Construit l'adresse de livraison au format BIHR
     * 
     * @param WC_Order $order Commande WooCommerce
     * @return array Adresse formatée
     */
    protected function build_shipping_address( $order ) {
        // Utiliser l'adresse de livraison si disponible, sinon l'adresse de facturation
        $first_name = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
        $last_name  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
        $address_1  = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $address_2  = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        $postcode   = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $city       = $order->get_shipping_city() ?: $order->get_billing_city();
        $country    = $order->get_shipping_country() ?: $order->get_billing_country();
        $phone      = $order->get_billing_phone();

        // Formatage du numéro de téléphone (ajouter +33 si nécessaire)
        if ( ! empty( $phone ) && $country === 'FR' ) {
            $phone = $this->format_french_phone( $phone );
        }

        return array(
            'FirstName' => $first_name,
            'LastName'  => $last_name,
            'Line1'     => $address_1,
            'Line2'     => $address_2 ?: '',
            'ZipCode'   => $postcode,
            'Town'      => $city,
            'Country'   => $country,
            'Phone'     => $phone,
        );
    }

    /**
     * Formate un numéro de téléphone français au format international
     * 
     * @param string $phone Numéro de téléphone
     * @return string Numéro formaté
     */
    protected function format_french_phone( $phone ) {
        // Nettoyer le numéro
        $phone = preg_replace( '/[^0-9+]/', '', $phone );

        // Si déjà au format international, retourner tel quel
        if ( strpos( $phone, '+' ) === 0 ) {
            return $phone;
        }

        // Si commence par 0, remplacer par +33
        if ( strpos( $phone, '0' ) === 0 ) {
            $phone = '+33' . substr( $phone, 1 );
        }

        return $phone;
    }

    /**
     * Envoie la commande à l'API BIHR
     * 
     * @param array $order_data Données de la commande
     * @return array Résultat de l'API
     */
    protected function send_order_to_bihr( $order_data ) {
        $this->logger->log( 'Envoi commande vers API BIHR : ' . wp_json_encode( $order_data, JSON_PRETTY_PRINT ) );

        // Récupération du token d'accès
        $token = $this->api_client->get_access_token();

        if ( ! $token ) {
            return array(
                'success' => false,
                'message' => 'Token d\'accès BIHR manquant ou expiré',
            );
        }

        // Appel à l'API
        $response = wp_remote_post(
            'https://api.mybihr.com/api/v2.1/Order/Creation',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $order_data ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'Erreur API BIHR : ' . $response->get_error_message() );
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        $this->logger->log( "Réponse API BIHR (status {$status_code}) : " . $body );

        if ( $status_code >= 200 && $status_code < 300 ) {
            return array(
                'success'  => true,
                'order_id' => $data['OrderId'] ?? $data['orderId'] ?? '',
                'data'     => $data,
            );
        } else {
            return array(
                'success' => false,
                'message' => $data['message'] ?? $data['Message'] ?? 'Erreur API inconnue',
                'data'    => $data,
            );
        }
    }

    /**
     * Gère les changements de statut de commande
     * 
     * @param int $order_id ID de la commande
     * @param string $old_status Ancien statut
     * @param string $new_status Nouveau statut
     * @param WC_Order $order Objet commande
     */
    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
        // Si la commande passe en "traitement" et n'est pas encore synchronisée
        if ( $new_status === 'processing' && ! get_post_meta( $order_id, '_bihr_order_synced', true ) ) {
            $this->logger->log( "Commande #{$order_id} passée en traitement - tentative de synchronisation" );
            $this->sync_order_to_bihr( $order_id, array(), $order );
        }

        // Si la commande passe en "annulé" et était synchronisée
        if ( $new_status === 'cancelled' && get_post_meta( $order_id, '_bihr_order_synced', true ) ) {
            $this->logger->log( "Commande #{$order_id} annulée - notification BIHR requise (à implémenter)" );
            // TODO: Implémenter l'annulation côté BIHR si l'API le permet
        }
    }

    /**
     * Réessaye la synchronisation d'une commande échouée
     * 
     * @param int $order_id ID de la commande
     * @return bool Succès ou échec
     */
    public function retry_order_sync( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return false;
        }

        // Réinitialiser les metas d'échec
        delete_post_meta( $order_id, '_bihr_order_sync_failed' );
        delete_post_meta( $order_id, '_bihr_sync_error' );

        // Réessayer la synchronisation
        $this->sync_order_to_bihr( $order_id, array(), $order );

        return ! get_post_meta( $order_id, '_bihr_order_sync_failed', true );
    }

    /**
     * Vérifie si une commande a été synchronisée avec BIHR
     * 
     * @param int $order_id ID de la commande
     * @return bool True si synchronisée
     */
    public function is_order_synced( $order_id ) {
        return (bool) get_post_meta( $order_id, '_bihr_order_synced', true );
    }

    /**
     * Récupère l'ID de commande BIHR
     * 
     * @param int $order_id ID de la commande WooCommerce
     * @return string ID de commande BIHR ou chaîne vide
     */
    public function get_bihr_order_id( $order_id ) {
        return get_post_meta( $order_id, '_bihr_order_id', true );
    }
}
