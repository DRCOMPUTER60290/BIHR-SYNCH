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
        // Génération d'un Ticket ID unique pour tracer toutes les étapes
        $ticket_id = 'WC' . $order_id . '-' . time() . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
        
        $this->logger->log( "┌─────────────────────────────────────────────────────────────" );
        $this->logger->log( "│ 🎫 TICKET: {$ticket_id}" );
        $this->logger->log( "│ 📦 COMMANDE WC: #{$order_id}" );
        $this->logger->log( "│ 👤 CLIENT: {$order->get_billing_first_name()} {$order->get_billing_last_name()}" );
        $this->logger->log( "│ 💶 MONTANT: {$order->get_total()} {$order->get_currency()}" );
        $this->logger->log( "└─────────────────────────────────────────────────────────────" );
        
        // Stocker le ticket ID dans les métadonnées
        update_post_meta( $order_id, '_bihr_sync_ticket_id', $ticket_id );
        
        // Vérifier si la synchronisation automatique est activée
        if ( ! get_option( 'bihrwi_auto_sync_orders', 1 ) ) {
            $this->logger->log( "[{$ticket_id}] ❌ ÉTAPE 1/6 : Synchronisation automatique désactivée - Commande ignorée" );
            return;
        }
        
        $this->logger->log( "[{$ticket_id}] ✅ ÉTAPE 1/6 : Synchronisation automatique activée" );

        try {
            // Vérifier si la commande n'a pas déjà été synchronisée
            if ( get_post_meta( $order_id, '_bihr_order_synced', true ) ) {
                $existing_bihr_id = get_post_meta( $order_id, '_bihr_order_id', true );
                $this->logger->log( "[{$ticket_id}] ⚠️ ÉTAPE 2/6 : Commande déjà synchronisée (BIHR ID: {$existing_bihr_id})" );
                return;
            }
            
            $this->logger->log( "[{$ticket_id}] ✅ ÉTAPE 2/6 : Vérification doublons OK - Nouvelle synchronisation" );

            // Construire les données de la commande
            $this->logger->log( "[{$ticket_id}] 🔄 ÉTAPE 3/6 : Construction des données de commande..." );
            $order_data = $this->build_order_data( $order, $ticket_id );

            if ( ! $order_data ) {
                $this->logger->log( "[{$ticket_id}] ❌ ÉTAPE 3/6 : Impossible de construire les données (aucun produit BIHR trouvé)" );
                update_post_meta( $order_id, '_bihr_order_sync_failed', true );
                update_post_meta( $order_id, '_bihr_sync_error', 'Aucun produit BIHR dans la commande' );
                return;
            }
            
            $product_count = count( $order_data['Order']['Lines'] );
            $this->logger->log( "[{$ticket_id}] ✅ ÉTAPE 3/6 : Données construites - {$product_count} produit(s) BIHR trouvé(s)" );

            // Log des produits
            foreach ( $order_data['Order']['Lines'] as $index => $line ) {
                $this->logger->log( "[{$ticket_id}]    → Produit " . ($index + 1) . ": {$line['ProductId']} x{$line['Quantity']} - {$line['CustomerReference']}" );
            }
            
            // Log de l'adresse
            $address = $order_data['DropShippingAddress'];
            $this->logger->log( "[{$ticket_id}]    → Livraison: {$address['FirstName']} {$address['LastName']}, {$address['Line1']}, {$address['ZipCode']} {$address['Town']}, {$address['Country']}" );

            // Envoyer la commande à l'API BIHR
            $this->logger->log( "[{$ticket_id}] 🚀 ÉTAPE 4/6 : Envoi vers l'API BIHR..." );
            $result = $this->send_order_to_bihr( $order_data, $ticket_id );

            if ( $result && isset( $result['success'] ) && $result['success'] ) {
                $bihr_ticket_id = $result['bihr_ticket_id'] ?? '';
                $result_code = $result['result_code'] ?? '';
                
                $this->logger->log( "[{$ticket_id}] ✅ ÉTAPE 4/6 : API BIHR - Demande de création acceptée" );
                
                if ( $bihr_ticket_id ) {
                    $this->logger->log( "[{$ticket_id}]    → BIHR Ticket ID: {$bihr_ticket_id}" );
                    
                    // Vérifier le statut de génération (workflow asynchrone)
                    $this->logger->log( "[{$ticket_id}] 🔄 Vérification du statut de génération..." );
                    $status_result = $this->check_order_generation_status( $bihr_ticket_id, $ticket_id );
                    
                    if ( $status_result && isset( $status_result['order_url'] ) ) {
                        $this->logger->log( "[{$ticket_id}]    → URL de la commande: {$status_result['order_url']}" );
                        update_post_meta( $order_id, '_bihr_order_url', $status_result['order_url'] );
                    }
                    
                    if ( $status_result && isset( $status_result['request_status'] ) ) {
                        $this->logger->log( "[{$ticket_id}]    → Statut: {$status_result['request_status']}" );
                    }
                }
                
                if ( $bihr_ticket_id ) {
                    $this->logger->log( "[{$ticket_id}]    → BIHR Ticket ID: {$bihr_ticket_id}" );
                }
                
                $this->logger->log( "[{$ticket_id}] 💾 ÉTAPE 5/6 : Enregistrement des métadonnées WooCommerce..." );
                
                // Marquer la commande comme synchronisée
                update_post_meta( $order_id, '_bihr_order_synced', true );
                update_post_meta( $order_id, '_bihr_order_id', $bihr_order_id );
                update_post_meta( $order_id, '_bihr_sync_date', current_time( 'mysql' ) );
                
                // Enregistrer le Ticket ID BIHR si disponible
                if ( $bihr_ticket_id ) {
                    update_post_meta( $order_id, '_bihr_api_ticket_id', $bihr_ticket_id );
                }

                // Construire la note de commande
                $note_parts = array(
                    '✅ Commande synchronisée avec BIHR',
                    'Ticket WC: ' . $ticket_id,
                );
                
                if ( $bihr_order_id && $bihr_order_id !== 'N/A' ) {
                    $note_parts[] = 'BIHR Order ID: ' . $bihr_order_id;
                }
                
                if ( $bihr_ticket_id ) {
                    $note_parts[] = 'BIHR Ticket ID: ' . $bihr_ticket_id;
                }
                
                if ( isset( $result['result_code'] ) && $result['result_code'] ) {
                    $note_parts[] = 'Résultat: ' . $result['result_code'];
                }
                
                $order->add_order_note( implode( "\n", $note_parts ) );
                
                $this->logger->log( "[{$ticket_id}] ✅ ÉTAPE 5/6 : Métadonnées enregistrées" );
                $this->logger->log( "[{$ticket_id}] 📝 ÉTAPE 6/6 : Note ajoutée à la commande WC" );
                
                $success_msg = "🎉 SYNCHRONISATION RÉUSSIE - Commande #{$order_id}";
                if ( $bihr_order_id && $bihr_order_id !== 'N/A' ) {
                    $success_msg .= " → BIHR ID: {$bihr_order_id}";
                }
                if ( $bihr_ticket_id ) {
                    $success_msg .= " (Ticket: {$bihr_ticket_id})";
                }
                
                $this->logger->log( "[{$ticket_id}] {$success_msg}" );
                $this->logger->log( "─────────────────────────────────────────────────────────────" );
                
            } else {
                $error_message = $result['message'] ?? 'Erreur inconnue';
                $http_code = $result['http_code'] ?? 'N/A';
                
                $this->logger->log( "[{$ticket_id}] ❌ ÉTAPE 4/6 : API BIHR - Échec (HTTP {$http_code})" );
                $this->logger->log( "[{$ticket_id}] ❌ Erreur: {$error_message}" );
                
                // Marquer comme échec
                update_post_meta( $order_id, '_bihr_order_sync_failed', true );
                update_post_meta( $order_id, '_bihr_sync_error', $error_message );

                // Ajouter une note d'erreur
                $order->add_order_note( 
                    sprintf( 
                        __( '❌ Échec synchronisation BIHR%sTicket: %s%sErreur: %s', 'bihr-woocommerce-importer' ),
                        "\n",
                        $ticket_id,
                        "\n",
                        $error_message
                    )
                );

                $this->logger->log( "[{$ticket_id}] 💾 ÉTAPE 5/6 : Échec enregistré dans les métadonnées" );
                $this->logger->log( "[{$ticket_id}] 📝 ÉTAPE 6/6 : Note d'erreur ajoutée à la commande WC" );
                $this->logger->log( "[{$ticket_id}] ⛔ SYNCHRONISATION ÉCHOUÉE" );
                $this->logger->log( "─────────────────────────────────────────────────────────────" );
            }

        } catch ( Exception $e ) {
            $this->logger->log( "[{$ticket_id}] 💥 EXCEPTION CRITIQUE : " . $e->getMessage() );
            $this->logger->log( "[{$ticket_id}] 📍 Fichier: " . $e->getFile() . " (ligne " . $e->getLine() . ")" );
            $this->logger->log( "[{$ticket_id}] 📊 Stack trace: " . $e->getTraceAsString() );
            
            update_post_meta( $order_id, '_bihr_order_sync_failed', true );
            update_post_meta( $order_id, '_bihr_sync_error', $e->getMessage() );
            
            $order->add_order_note( 
                sprintf( 
                    __( '💥 Exception lors de la synchronisation BIHR%sTicket: %s%sErreur: %s', 'bihr-woocommerce-importer' ),
                    "\n",
                    $ticket_id,
                    "\n",
                    $e->getMessage()
                )
            );
            
            $this->logger->log( "─────────────────────────────────────────────────────────────" );
        }
    }

    /**
     * Construit les données de commande au format BIHR API
     * 
     * @param WC_Order $order Commande WooCommerce
     * @param string $ticket_id Identifiant unique de suivi
     * @return array|false Données formatées ou false en cas d'erreur
     */
    protected function build_order_data( $order, $ticket_id = '' ) {
        // Récupération des produits de la commande
        $lines = array();
        
        $this->logger->log( "[{$ticket_id}]    🔍 Analyse des produits de la commande..." );
        
        foreach ( $order->get_items() as $item_id => $item ) {
            $product    = $item->get_product();
            $product_id = $product->get_id();
            
            // Récupérer le code produit BIHR
            $bihr_code = get_post_meta( $product_id, '_bihr_product_code', true );
            
            if ( empty( $bihr_code ) ) {
                $this->logger->log( "[{$ticket_id}]    ⚠️ Produit WC #{$product_id} ({$product->get_name()}) - Pas de code BIHR (ignoré)" );
                continue;
            }

            $this->logger->log( "[{$ticket_id}]    ✅ Produit WC #{$product_id} - Code BIHR: {$bihr_code} x{$item->get_quantity()}" );

            $lines[] = array(
                'ProductId'         => $bihr_code,
                'Quantity'          => $item->get_quantity(),
                'CustomerReference' => $product->get_name(),
            );
        }

        // Si aucun produit BIHR, ne pas envoyer la commande
        if ( empty( $lines ) ) {
            $this->logger->log( "[{$ticket_id}]    ❌ Aucun produit BIHR trouvé - Synchronisation annulée" );
            return false;
        }

        $this->logger->log( "[{$ticket_id}]    📊 Total: " . count( $lines ) . " produit(s) BIHR à synchroniser" );

        // Construction de la référence client
        $customer_reference = sprintf(
            'WC Order #%d - %s',
            $order->get_id(),
            $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
        );
        
        $this->logger->log( "[{$ticket_id}]    📝 Référence client: {$customer_reference}" );

        // Récupération de l'option de validation automatique
        $auto_checkout = get_option( 'bihrwi_auto_checkout', true );
        
        $this->logger->log( "[{$ticket_id}]    ⚙️ Option: Checkout automatique=" . ( $auto_checkout ? 'activé' : 'désactivé' ) );

        // Construction des données de commande selon nouvelle API doc
        $order_data = array(
            'Order' => array(
                'CustomerReference'              => $customer_reference,
                'Lines'                          => $lines,
                'IsAutomaticCheckoutActivated'   => (bool) $auto_checkout,
            ),
        );

        // Ajout de l'adresse de livraison (DropShipping)
        $shipping_address = $this->build_shipping_address( $order, $ticket_id );
        if ( $shipping_address ) {
            $order_data['DropShippingAddress'] = $shipping_address;
        }

        return $order_data;
    }

    /**
     * Construit l'adresse de livraison au format BIHR
     * 
     * @param WC_Order $order Commande WooCommerce
     * @param string $ticket_id Identifiant unique de suivi
     * @return array Adresse formatée
     */
    protected function build_shipping_address( $order, $ticket_id = '' ) {
        // Utiliser l'adresse de livraison si disponible, sinon l'adresse de facturation
        $first_name = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
        $last_name  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
        $address_1  = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $address_2  = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        $postcode   = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $city       = $order->get_shipping_city() ?: $order->get_billing_city();
        $country    = $order->get_shipping_country() ?: $order->get_billing_country();
        $phone      = $order->get_billing_phone();

        $this->logger->log( "[{$ticket_id}]    📍 Adresse: {$first_name} {$last_name}, {$address_1}, {$postcode} {$city}, {$country}" );

        // Formatage du numéro de téléphone (ajouter +33 si nécessaire)
        $original_phone = $phone;
        if ( ! empty( $phone ) && $country === 'FR' ) {
            $phone = $this->format_french_phone( $phone );
            if ( $phone !== $original_phone ) {
                $this->logger->log( "[{$ticket_id}]    📞 Téléphone formaté: {$original_phone} → {$phone}" );
            } else {
                $this->logger->log( "[{$ticket_id}]    📞 Téléphone: {$phone}" );
            }
        } else {
            $this->logger->log( "[{$ticket_id}]    📞 Téléphone: {$phone}" );
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
     * Vérifie le statut de génération d'une commande (workflow asynchrone)
     * 
     * @param string $bihr_ticket_id TicketId retourné par l'API
     * @param string $ticket_id Identifiant de suivi interne
     * @return array|false Résultat du statut ou false
     */
    protected function check_order_generation_status( $bihr_ticket_id, $ticket_id = '' ) {
        if ( empty( $bihr_ticket_id ) ) {
            return false;
        }

        $this->logger->log( "[{$ticket_id}]    🔍 Vérification du statut avec TicketId: {$bihr_ticket_id}" );
        
        // Attendre 2 secondes pour laisser l'API traiter la demande
        sleep( 2 );
        
        $result = $this->api_client->get_order_generation_status( $bihr_ticket_id );
        
        if ( ! $result ) {
            $this->logger->log( "[{$ticket_id}]    ⚠️ Impossible de récupérer le statut" );
            return false;
        }
        
        $request_status = $result['request_status'] ?? '';
        $order_url = $result['order_url'] ?? '';
        
        if ( $request_status === 'Running' ) {
            $this->logger->log( "[{$ticket_id}]    ⏳ Création en cours (Running)..." );
        } elseif ( $request_status === 'Cart' ) {
            $this->logger->log( "[{$ticket_id}]    🛒 Panier créé avec succès" );
        } elseif ( $request_status === 'Order' ) {
            $this->logger->log( "[{$ticket_id}]    📦 Commande créée avec succès" );
        } elseif ( ! empty( $request_status ) ) {
            // Message d'erreur ou problème métier
            $this->logger->log( "[{$ticket_id}]    ⚠️ Statut: {$request_status}" );
        }
        
        return $result;
    }

    /**
     * Envoie la commande à l'API BIHR
     * 
     * @param array $order_data Données de la commande
     * @param string $ticket_id Identifiant unique de suivi
     * @return array Résultat de l'API
     */
    protected function send_order_to_bihr( $order_data, $ticket_id = '' ) {
        $this->logger->log( "[{$ticket_id}]    📤 Préparation de la requête HTTP POST..." );
        $this->logger->log( "[{$ticket_id}]    🔗 URL: https://api.bihr.net/api/v2.1/Order/Creation" );
        
        // Log du JSON (formaté pour lisibilité)
        $json_data = wp_json_encode( $order_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $this->logger->log( "[{$ticket_id}]    📋 Payload JSON:" );
        foreach ( explode( "\n", $json_data ) as $line ) {
            $this->logger->log( "[{$ticket_id}]       " . $line );
        }

        // Récupération du token d'accès
        $this->logger->log( "[{$ticket_id}]    🔑 Récupération du token d'accès OAuth..." );
        
        try {
            $token = $this->api_client->get_token();
            $this->logger->log( "[{$ticket_id}]    ✅ Token OAuth récupéré: " . substr( $token, 0, 20 ) . "..." );
        } catch ( Exception $e ) {
            $this->logger->log( "[{$ticket_id}]    ❌ Échec: " . $e->getMessage() );
            return array(
                'success'   => false,
                'message'   => 'Erreur d\'authentification: ' . $e->getMessage(),
                'http_code' => 'N/A',
            );
        }

        // Appel à l'API
        $api_url = 'https://api.bihr.net/api/v2.1/Order/Creation';
        $start_time = microtime( true );
        $this->logger->log( "[{$ticket_id}]    ⏱️ Envoi de la requête HTTP... (timeout: 30s)" );
        $this->logger->log( "[{$ticket_id}]    🔗 URL: {$api_url}" );
        
        $response = wp_remote_post(
            $api_url,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body'    => wp_json_encode( $order_data ),
                'timeout' => 30,
            )
        );
        
        $elapsed = round( ( microtime( true ) - $start_time ) * 1000, 2 );

        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            $this->logger->log( "[{$ticket_id}]    ❌ Erreur HTTP ({$elapsed}ms): {$error_msg}" );
            return array(
                'success'   => false,
                'message'   => $error_msg,
                'http_code' => 'ERROR',
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        $this->logger->log( "[{$ticket_id}]    📨 Réponse reçue ({$elapsed}ms) - HTTP {$status_code}" );
        
        // Si le body est trop long (ex: page HTML d'erreur), on le tronque dans les logs
        if ( strlen( $body ) > 500 ) {
            $this->logger->log( "[{$ticket_id}]    📄 Body (tronqué): " . substr( $body, 0, 500 ) . '...' );
        } else {
            $this->logger->log( "[{$ticket_id}]    📄 Body: " . $body );
        }
        
        // Tentative de décodage JSON
        $data = json_decode( $body, true );
        
        // Si ce n'est pas du JSON valide et que c'est une erreur HTTP
        if ( json_last_error() !== JSON_ERROR_NONE && $status_code >= 400 ) {
            $json_error = json_last_error_msg();
            $this->logger->log( "[{$ticket_id}]    ⚠️ La réponse n'est pas du JSON valide: {$json_error}" );
            
            // Vérifier si c'est une page HTML d'erreur
            if ( strpos( $body, '<html' ) !== false || strpos( $body, '<!DOCTYPE' ) !== false ) {
                $this->logger->log( "[{$ticket_id}]    ❌ L'API a retourné une page HTML au lieu de JSON" );
                return array(
                    'success'   => false,
                    'message'   => "L'API BIHR a retourné une erreur HTTP {$status_code} (page HTML)",
                    'http_code' => $status_code,
                );
            }
            
            return array(
                'success'   => false,
                'message'   => "Réponse invalide de l'API BIHR (HTTP {$status_code})",
                'http_code' => $status_code,
            );
        }

        if ( $status_code >= 200 && $status_code < 300 ) {
            // Nouveau workflow asynchrone : récupération du TicketId et ResultCode
            $bihr_ticket_id = $data['TicketId'] ?? $data['ticketId'] ?? '';
            $result_code = $data['ResultCode'] ?? '';
            
            if ( $bihr_ticket_id ) {
                $this->logger->log( "[{$ticket_id}]    ✅ Demande acceptée - BIHR Ticket ID: {$bihr_ticket_id}" );
            } else {
                $this->logger->log( "[{$ticket_id}]    ✅ Demande acceptée" );
            }
            
            // Log du ResultCode
            if ( $result_code ) {
                $this->logger->log( "[{$ticket_id}]    📋 ResultCode: {$result_code}" );
                
                if ( strpos( $result_code, 'Cart creation requested' ) !== false ) {
                    $this->logger->log( "[{$ticket_id}]    🛒 Un panier sera créé (validation manuelle requise sur mybihr.com)" );
                } elseif ( strpos( $result_code, 'Order creation requested' ) !== false ) {
                    $this->logger->log( "[{$ticket_id}]    📦 Une commande sera créée automatiquement" );
                }
            }
            
            return array(
                'success'        => true,
                'bihr_ticket_id' => $bihr_ticket_id,
                'result_code'    => $result_code,
                'data'           => $data,
                'http_code'      => $status_code,
            );
        } else {
            $error_msg = $data['message'] ?? $data['Message'] ?? $data['error'] ?? 'Erreur API inconnue';
            $this->logger->log( "[{$ticket_id}]    ❌ Échec - Message: {$error_msg}" );
            
            // Log des détails supplémentaires si disponibles
            if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
                $this->logger->log( "[{$ticket_id}]    📋 Détails des erreurs:" );
                foreach ( $data['errors'] as $field => $errors ) {
                    if ( is_array( $errors ) ) {
                        foreach ( $errors as $error ) {
                            $this->logger->log( "[{$ticket_id}]       - {$field}: {$error}" );
                        }
                    }
                }
            }
            
            return array(
                'success'   => false,
                'message'   => $error_msg,
                'data'      => $data,
                'http_code' => $status_code,
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
        $ticket_id = get_post_meta( $order_id, '_bihr_sync_ticket_id', true ) ?: 'RETRY-' . $order_id . '-' . time();
        
        $this->logger->log( "[{$ticket_id}] 🔄 Changement de statut détecté: {$old_status} → {$new_status}" );
        
        // Si la commande passe en "traitement" ou "terminée" et n'est pas encore synchronisée
        if ( in_array( $new_status, array( 'processing', 'completed' ) ) && ! get_post_meta( $order_id, '_bihr_order_synced', true ) ) {
            $this->logger->log( "[{$ticket_id}] ⚡ Commande non synchronisée - Lancement de la synchronisation automatique..." );
            $this->sync_order_to_bihr( $order_id, array(), $order );
        } elseif ( in_array( $new_status, array( 'processing', 'completed' ) ) && get_post_meta( $order_id, '_bihr_order_synced', true ) ) {
            $bihr_order_id = get_post_meta( $order_id, '_bihr_order_id', true );
            $bihr_ticket_id = get_post_meta( $order_id, '_bihr_api_ticket_id', true );
            $this->logger->log( "[{$ticket_id}] ✅ Commande déjà synchronisée (BIHR Order ID: {$bihr_order_id}, BIHR Ticket: {$bihr_ticket_id})" );
        }

        // Si la commande passe en "annulé" et était synchronisée
        if ( $new_status === 'cancelled' && get_post_meta( $order_id, '_bihr_order_synced', true ) ) {
            $bihr_order_id = get_post_meta( $order_id, '_bihr_order_id', true );
            $this->logger->log( "[{$ticket_id}] ⚠️ Commande WC #{$order_id} annulée (BIHR ID: {$bihr_order_id})" );
            $this->logger->log( "[{$ticket_id}] 📌 Note: L'annulation côté BIHR doit être faite manuellement" );
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
        
        $ticket_id = 'RETRY-' . $order_id . '-' . time();
        
        $this->logger->log( "┌─────────────────────────────────────────────────────────────" );
        $this->logger->log( "│ 🔄 NOUVELLE TENTATIVE DE SYNCHRONISATION" );
        $this->logger->log( "│ 🎫 TICKET: {$ticket_id}" );
        $this->logger->log( "│ 📦 COMMANDE WC: #{$order_id}" );
        $this->logger->log( "└─────────────────────────────────────────────────────────────" );

        // Réinitialiser les metas d'échec
        delete_post_meta( $order_id, '_bihr_order_sync_failed' );
        delete_post_meta( $order_id, '_bihr_sync_error' );
        delete_post_meta( $order_id, '_bihr_order_synced' );

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
