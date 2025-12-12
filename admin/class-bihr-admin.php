<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BihrWI_Admin {

    protected $logger;
    protected $api_client;
    protected $product_sync;

    public function __construct() {
        $this->logger       = new BihrWI_Logger();
        $this->api_client   = new BihrWI_API_Client( $this->logger );
        $this->product_sync = new BihrWI_Product_Sync( $this->logger );

        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Handlers des formulaires
        add_action( 'admin_post_bihrwi_authenticate', array( $this, 'handle_authenticate' ) );
        add_action( 'admin_post_bihrwi_clear_logs', array( $this, 'handle_clear_logs' ) );
        add_action( 'admin_post_bihrwi_start_prices_generation', array( $this, 'handle_start_prices_generation' ) );
        add_action( 'admin_post_bihrwi_import_product', array( $this, 'handle_import_product' ) );
        add_action( 'admin_post_bihrwi_merge_catalogs', array( $this, 'handle_merge_catalogs' ) );
		add_action( 'admin_post_bihrwi_check_prices_now', array( $this, 'handle_check_prices_now' ) );
		add_action( 'admin_post_bihrwi_reset_data', array( $this, 'handle_reset_data' ) );
		add_action( 'admin_post_bihrwi_download_all_catalogs', array( $this, 'handle_download_all_catalogs' ) );

        // Handlers AJAX
        add_action( 'wp_ajax_bihrwi_download_all_catalogs_ajax', array( $this, 'ajax_download_all_catalogs' ) );
        add_action( 'wp_ajax_bihrwi_merge_catalogs_ajax', array( $this, 'ajax_merge_catalogs' ) );
        add_action( 'wp_ajax_bihrwi_import_single_product', array( $this, 'ajax_import_single_product' ) );
        add_action( 'wp_ajax_bihr_refresh_stock', array( $this, 'ajax_refresh_stock' ) );
        add_action( 'wp_ajax_bihrwi_import_vehicles', array( $this, 'ajax_import_vehicles' ) );
        add_action( 'wp_ajax_bihrwi_import_compatibility', array( $this, 'ajax_import_compatibility' ) );
        add_action( 'wp_ajax_bihrwi_import_all_compatibility', array( $this, 'ajax_import_all_compatibility' ) );

    }
	
	/**
	 * Charge les assets CSS et JS pour l'admin
	 */
	public function enqueue_admin_assets( $hook ) {
		// Charge uniquement sur les pages du plugin
		if ( strpos( $hook, 'bihrwi' ) === false && strpos( $hook, 'bihr' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'bihr-admin-css',
			BIHRWI_PLUGIN_URL . 'admin/css/bihr-admin.css',
			array(),
			BIHRWI_VERSION
		);

		wp_enqueue_script(
			'bihr-progress-js',
			BIHRWI_PLUGIN_URL . 'admin/js/bihr-progress.js',
			array( 'jquery' ),
			BIHRWI_VERSION,
			true
		);

		wp_localize_script(
			'bihr-progress-js',
			'bihrProgressData',
			array(
				'nonce' => wp_create_nonce( 'bihrwi_ajax_nonce' ),
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}
	
	public function handle_check_prices_now() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Permission denied.' );
    }

    check_admin_referer( 'bihrwi_check_prices_now_action', 'bihrwi_check_prices_now_nonce' );

    $redirect_url = add_query_arg( array( 'page' => 'bihrwi_products' ), admin_url( 'admin.php' ) );

    $status_data = get_option( 'bihrwi_prices_generation', array() );

    if ( empty( $status_data['ticket_id'] ) ) {
        $redirect_url = add_query_arg(
            array(
                'bihrwi_check_status' => 'noticket'
            ),
            $redirect_url
        );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    try {
        $logger = new BihrWI_Logger();
        $api    = new BihrWI_API_Client( $logger );

        $ticket_id = $status_data['ticket_id'];

        // On interroge directement l’API : GenerationStatus
        $status_response = $api->get_catalog_status( $ticket_id );
        $status          = strtoupper( $status_response['status'] ?? '' );

        // Mise à jour du dernier statut affichable
        $status_data['last_status']  = $status;
        $status_data['last_checked'] = current_time( 'mysql' );
        update_option( 'bihrwi_prices_generation', $status_data );

        if ( $status === 'PROCESSING' ) {
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_check_status' => 'processing',
                ),
                $redirect_url
            );
        }

        elseif ( $status === 'ERROR' ) {
            $error_msg = $status_response['error'] ?? 'Erreur inconnue.';
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_check_status' => 'error',
                    'bihrwi_msg'          => urlencode( $error_msg ),
                ),
                $redirect_url
            );
        }

        elseif ( $status === 'DONE' && ! empty( $status_response['downloadId'] ) ) {
            $download_id = $status_response['downloadId'];

            $file_path = $api->download_catalog_file( $download_id, 'prices' );

            if ( $file_path ) {
                // Fichier téléchargé avec succès
                $logger->log( "Catalogue Prices téléchargé: {$file_path}" );
                delete_option( 'bihrwi_prices_generation' );

                // Lancer automatiquement la fusion des catalogues
                $logger->log( "Démarrage automatique de la fusion des catalogues..." );
                
                try {
                    $product_sync = new BihrWI_Product_Sync( $logger );
                    $total_products = $product_sync->merge_catalogs_from_directory();
                    
                    if ( $total_products > 0 ) {
                        $logger->log( "Fusion automatique réussie: {$total_products} produits" );
                        
                        $redirect_url = add_query_arg(
                            array(
                                'bihrwi_check_status' => 'done_and_merged',
                                'bihrwi_file'         => urlencode( $file_path ),
                                'total_products'      => $total_products,
                            ),
                            $redirect_url
                        );
                    } else {
                        $logger->log( "Échec de la fusion automatique" );
                        
                        $redirect_url = add_query_arg(
                            array(
                                'bihrwi_check_status' => 'done_merge_failed',
                                'bihrwi_file'         => urlencode( $file_path ),
                            ),
                            $redirect_url
                        );
                    }
                } catch ( Exception $merge_error ) {
                    $logger->log( "Exception lors de la fusion: " . $merge_error->getMessage() );
                    
                    $redirect_url = add_query_arg(
                        array(
                            'bihrwi_check_status' => 'done',
                            'bihrwi_file'         => urlencode( $file_path ),
                            'merge_error'         => urlencode( $merge_error->getMessage() ),
                        ),
                        $redirect_url
                    );
                }
            } else {
                $redirect_url = add_query_arg(
                    array(
                        'bihrwi_check_status' => 'downloadfail',
                    ),
                    $redirect_url
                );
            }
        }

    } catch ( Exception $e ) {
        $redirect_url = add_query_arg(
            array(
                'bihrwi_check_status' => 'exception',
                'bihrwi_msg'          => urlencode( $e->getMessage() ),
            ),
            $redirect_url
        );
    }

    wp_safe_redirect( $redirect_url );
    exit;
}

	

    public function register_menus() {
        add_menu_page(
            __( 'Bihr Import', 'bihr-woocommerce-importer' ),
            __( 'Bihr Import', 'bihr-woocommerce-importer' ),
            'manage_woocommerce',
            'bihrwi_auth',
            array( $this, 'render_auth_page' ),
            'dashicons-cart',
            56
        );

        add_submenu_page(
            'bihrwi_auth',
            __( 'Authentification Bihr', 'bihr-woocommerce-importer' ),
            __( 'Authentification', 'bihr-woocommerce-importer' ),
            'manage_woocommerce',
            'bihrwi_auth',
            array( $this, 'render_auth_page' )
        );

        add_submenu_page(
            'bihrwi_auth',
            __( 'Logs Bihr', 'bihr-woocommerce-importer' ),
            __( 'Logs', 'bihr-woocommerce-importer' ),
            'manage_woocommerce',
            'bihrwi_logs',
            array( $this, 'render_logs_page' )
        );

        add_submenu_page(
            'bihrwi_auth',
            __( 'Produits Bihr', 'bihr-woocommerce-importer' ),
            __( 'Produits Bihr', 'bihr-woocommerce-importer' ),
            'manage_woocommerce',
            'bihrwi_products',
            array( $this, 'render_products_page' )
        );

        add_submenu_page(
            'bihrwi_auth',
            __( 'Paramètres Commandes', 'bihr-woocommerce-importer' ),
            __( 'Commandes', 'bihr-woocommerce-importer' ),
            'manage_woocommerce',
            'bihrwi_orders',
            array( $this, 'render_orders_settings_page' )
        );

        add_submenu_page(
            'bihrwi_auth',
            __( 'Gestion des marges', 'bihr-woocommerce-importer' ),
            __( 'Marges', 'bihr-woocommerce-importer' ),
            'manage_woocommerce',
            'bihrwi_margins',
            array( $this, 'render_margins_page' )
        );

        add_submenu_page(
            'bihrwi_auth',
            __( 'Produits Importés', 'bihr-woocommerce-importer' ),
            __( 'Produits Importés', 'bihr-woocommerce-importer' ),
            'manage_woocommerce',
            'bihrwi_imported_products',
            array( $this, 'render_imported_products_page' )
        );

        add_submenu_page(
            'bihrwi_auth',
            __( 'Compatibilité Véhicules', 'bihr-woocommerce-importer' ),
            __( 'Compatibilité', 'bihr-woocommerce-importer' ),
            'manage_woocommerce',
            'bihrwi_compatibility',
            array( $this, 'render_compatibility_page' )
        );
    }

    // === RENDER PAGES ===

    public function render_margins_page() {
        // Traitement du formulaire de sauvegarde
        if ( isset( $_POST['bihrwi_margins_nonce'] ) && wp_verify_nonce( $_POST['bihrwi_margins_nonce'], 'bihrwi_save_margins' ) ) {
            $this->save_margin_settings();
            wp_redirect( add_query_arg( 'margin_saved', '1', admin_url( 'admin.php?page=bihrwi_margins' ) ) );
            exit;
        }

        include BIHRWI_PLUGIN_DIR . 'admin/views/margin-page.php';
    }

    private function save_margin_settings() {
        $settings = array(
            'default_margin_type'  => sanitize_text_field( $_POST['default_margin_type'] ?? 'percentage' ),
            'default_margin_value' => floatval( $_POST['default_margin_value'] ?? 0 ),
            'category_margins'     => array(),
            'price_range_margins'  => array(),
            'priority'             => sanitize_text_field( $_POST['priority'] ?? 'specific' ),
        );

        // Marges par catégorie
        if ( isset( $_POST['category_margins'] ) && is_array( $_POST['category_margins'] ) ) {
            foreach ( $_POST['category_margins'] as $key => $data ) {
                $settings['category_margins'][ $key ] = array(
                    'enabled' => isset( $data['enabled'] ),
                    'type'    => sanitize_text_field( $data['type'] ?? 'percentage' ),
                    'value'   => floatval( $data['value'] ?? 0 ),
                );
            }
        }

        // Marges par tranche de prix
        if ( isset( $_POST['price_range_margins'] ) && is_array( $_POST['price_range_margins'] ) ) {
            foreach ( $_POST['price_range_margins'] as $data ) {
                $settings['price_range_margins'][] = array(
                    'enabled' => isset( $data['enabled'] ),
                    'min'     => floatval( $data['min'] ?? 0 ),
                    'max'     => floatval( $data['max'] ?? 999999 ),
                    'type'    => sanitize_text_field( $data['type'] ?? 'percentage' ),
                    'value'   => floatval( $data['value'] ?? 0 ),
                );
            }
        }

        update_option( 'bihrwi_margin_settings', $settings );
        $this->logger->log( 'Configuration des marges mise à jour' );
    }

    public function render_auth_page() {
        $username   = get_option( 'bihrwi_username', '' );
        $password   = get_option( 'bihrwi_password', '' );
        $openai_key = get_option( 'bihrwi_openai_key', '' );
        $last_token = get_transient( 'bihrwi_api_token' );

        include BIHRWI_PLUGIN_DIR . 'admin/views/auth-page.php';
    }

    public function render_logs_page() {
        $log_contents = $this->logger->get_log_contents();
        include BIHRWI_PLUGIN_DIR . 'admin/views/logs-page.php';
    }

    public function render_products_page() {
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page     = 20;

        // Récupération des filtres
        $filter_search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        $filter_stock    = isset( $_GET['stock_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['stock_filter'] ) ) : '';
        $filter_price_min = isset( $_GET['price_min'] ) ? sanitize_text_field( wp_unslash( $_GET['price_min'] ) ) : '';
        $filter_price_max = isset( $_GET['price_max'] ) ? sanitize_text_field( wp_unslash( $_GET['price_max'] ) ) : '';
        $filter_category = isset( $_GET['category_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['category_filter'] ) ) : '';
        $sort_by         = isset( $_GET['sort_by'] ) ? sanitize_text_field( wp_unslash( $_GET['sort_by'] ) ) : '';

        $products     = $this->product_sync->get_products( $current_page, $per_page, $filter_search, $filter_stock, $filter_price_min, $filter_price_max, $filter_category, $sort_by );
        $total        = $this->product_sync->get_products_count( $filter_search, $filter_stock, $filter_price_min, $filter_price_max, $filter_category );
        $total_pages  = max( 1, ceil( $total / $per_page ) );
        
        // Récupérer la liste des catégories disponibles pour le dropdown
        $available_categories = $this->product_sync->get_distinct_categories();

        include BIHRWI_PLUGIN_DIR . 'admin/views/products-page.php';
    }

    public function render_imported_products_page() {
        include BIHRWI_PLUGIN_DIR . 'admin/views/imported-products-page.php';
    }

    public function render_compatibility_page() {
        include BIHRWI_PLUGIN_DIR . 'admin/views/compatibility-page.php';
    }

    public function render_orders_settings_page() {
        // Gestion de la sauvegarde des paramètres
        if ( isset( $_POST['bihrwi_save_order_settings'] ) ) {
            check_admin_referer( 'bihrwi_order_settings_action', 'bihrwi_order_settings_nonce' );
            
            update_option( 'bihrwi_auto_sync_orders', isset( $_POST['bihrwi_auto_sync_orders'] ) ? 1 : 0 );
            update_option( 'bihrwi_auto_checkout', isset( $_POST['bihrwi_auto_checkout'] ) ? 1 : 0 );
            update_option( 'bihrwi_weekly_free_shipping', isset( $_POST['bihrwi_weekly_free_shipping'] ) ? 1 : 0 );
            update_option( 'bihrwi_delivery_mode', sanitize_text_field( $_POST['bihrwi_delivery_mode'] ?? 'Default' ) );
            
            $success_message = 'Paramètres de commandes sauvegardés avec succès.';
        }

        // Récupération des options
        $auto_sync_orders      = get_option( 'bihrwi_auto_sync_orders', 1 );
        $auto_checkout         = get_option( 'bihrwi_auto_checkout', 1 );
        $weekly_free_shipping  = get_option( 'bihrwi_weekly_free_shipping', 1 );
        $delivery_mode         = get_option( 'bihrwi_delivery_mode', 'Default' );

        include BIHRWI_PLUGIN_DIR . 'admin/views/orders-settings-page.php';
    }

    // === HANDLERS FORM ===

    public function handle_authenticate() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( 'bihrwi_authenticate_action', 'bihrwi_authenticate_nonce' );

        $username = isset( $_POST['bihrwi_username'] ) ? sanitize_text_field( wp_unslash( $_POST['bihrwi_username'] ) ) : '';
        $password = isset( $_POST['bihrwi_password'] ) ? sanitize_text_field( wp_unslash( $_POST['bihrwi_password'] ) ) : '';
        $openai_key = isset( $_POST['bihrwi_openai_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bihrwi_openai_key'] ) ) : '';

        update_option( 'bihrwi_username', $username );
        update_option( 'bihrwi_password', $password );
        update_option( 'bihrwi_openai_key', $openai_key );

        $redirect_url = add_query_arg( array( 'page' => 'bihrwi_auth' ), admin_url( 'admin.php' ) );

        // Test de l'authentification Bihr
        try {
            $token = $this->api_client->get_token();
            $this->logger->log( 'Auth: succès pour ' . $username );
            $redirect_url = add_query_arg( array( 'bihrwi_auth_success' => 1 ), $redirect_url );
        } catch ( Exception $e ) {
            $this->logger->log( 'Auth: échec pour ' . $username . ' – ' . $e->getMessage() );
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_auth_error' => 1,
                    'bihrwi_msg'        => urlencode( $e->getMessage() ),
                ),
                $redirect_url
            );
        }

        // Test de la clé OpenAI si renseignée
        if ( ! empty( $openai_key ) ) {
            $ai_enrichment = new BihrWI_AI_Enrichment( $this->logger );
            $test_result = $this->test_openai_key( $openai_key );
            
            if ( $test_result === true ) {
                $this->logger->log( 'OpenAI: clé API valide et opérationnelle' );
                $redirect_url = add_query_arg( array( 'bihrwi_openai_success' => 1 ), $redirect_url );
            } else {
                $this->logger->log( 'OpenAI: erreur de validation - ' . $test_result );
                $redirect_url = add_query_arg(
                    array(
                        'bihrwi_openai_error' => 1,
                        'bihrwi_openai_msg'   => urlencode( $test_result ),
                    ),
                    $redirect_url
                );
            }
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Test la validité de la clé OpenAI
     * @return bool|string true si valide, message d'erreur sinon
     */
    protected function test_openai_key( $api_key ) {
        $endpoint = 'https://api.openai.com/v1/models';

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'timeout' => 10,
        );

        $response = wp_remote_get( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return 'Erreur de connexion : ' . $response->get_error_message();
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            return true;
        } elseif ( $status_code === 401 ) {
            return 'Clé API invalide ou expirée';
        } elseif ( $status_code === 429 ) {
            return 'Quota dépassé sur votre compte OpenAI';
        } else {
            $body = wp_remote_retrieve_body( $response );
            return 'Erreur HTTP ' . $status_code . ' : ' . substr( $body, 0, 100 );
        }
    }

    public function handle_clear_logs() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( 'bihrwi_clear_logs_action', 'bihrwi_clear_logs_nonce' );

        $this->logger->clear_logs();

        $redirect_url = add_query_arg(
            array(
                'page'            => 'bihrwi_logs',
                'bihrwi_cleared'  => 1,
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function handle_start_prices_generation() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( 'bihrwi_start_prices_action', 'bihrwi_start_prices_nonce' );

        $redirect_url = add_query_arg( array( 'page' => 'bihrwi_products' ), admin_url( 'admin.php' ) );

        try {
            // Démarre la génération du catalog Prices (l'API ajoutera /Full automatiquement)
            $ticket_id = $this->api_client->start_catalog_generation( 'Prices' );

            update_option(
                'bihrwi_prices_generation',
                array(
                    'ticket_id'  => $ticket_id,
                    'started_at' => current_time( 'mysql' ),
                )
            );

            $this->logger->log( 'Prices: génération démarrée (ticket_id=' . $ticket_id . ').' );

            // On force un cron dans 5 minutes
            wp_schedule_single_event( time() + 300, 'bihrwi_check_prices_catalog_event' );

            $redirect_url = add_query_arg( array( 'bihrwi_prices_started' => 1 ), $redirect_url );
        } catch ( Exception $e ) {
            $this->logger->log( 'Prices: erreur démarrage – ' . $e->getMessage() );
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_prices_error' => 1,
                    'bihrwi_msg'          => urlencode( $e->getMessage() ),
                ),
                $redirect_url
            );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function handle_import_product() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( 'bihrwi_import_product_action', 'bihrwi_import_product_nonce' );

        $product_id = isset( $_POST['bihrwi_product_id'] ) ? intval( $_POST['bihrwi_product_id'] ) : 0;

        $redirect_url = add_query_arg( array( 'page' => 'bihrwi_products' ), admin_url( 'admin.php' ) );

        if ( ! $product_id ) {
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_import_error' => 1,
                    'bihrwi_msg'          => urlencode( 'ID produit invalide.' ),
                ),
                $redirect_url
            );
            wp_safe_redirect( $redirect_url );
            exit;
        }

        try {
            $wc_id       = $this->product_sync->import_to_woocommerce( $product_id );
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_import_success' => 1,
                    'imported_id'           => $wc_id,
                ),
                $redirect_url
            );
        } catch ( Exception $e ) {
            $this->logger->log( 'Import product: erreur – ' . $e->getMessage() );
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_import_error' => 1,
                    'bihrwi_msg'          => urlencode( $e->getMessage() ),
                ),
                $redirect_url
            );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handler pour le bouton "Fusionner les catalogues"
     */
    public function handle_merge_catalogs() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( 'bihrwi_merge_catalogs_action', 'bihrwi_merge_catalogs_nonce' );

        $redirect_url = add_query_arg( array( 'page' => 'bihrwi_products' ), admin_url( 'admin.php' ) );

        try {
            $count = $this->product_sync->merge_catalogs_from_directory();
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_merge_success' => 1,
                    'bihrwi_merge_count'   => $count,
                ),
                $redirect_url
            );
        } catch ( Exception $e ) {
            $this->logger->log( 'Fusion catalogues: erreur – ' . $e->getMessage() );
            $redirect_url = add_query_arg(
                array(
                    'bihrwi_merge_error' => 1,
                    'bihrwi_msg'         => urlencode( $e->getMessage() ),
                ),
                $redirect_url
            );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }
	public function handle_reset_data() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Permission denied.' );
    }

    check_admin_referer( 'bihrwi_reset_data_action', 'bihrwi_reset_data_nonce' );

    global $wpdb;

    // 1) On efface la table
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bihr_products" );

    // 2) On efface les fichiers CSV
    $import_dir = WP_CONTENT_DIR . '/uploads/bihr-import/';
    if ( is_dir( $import_dir ) ) {
        foreach ( glob( $import_dir . '*.csv' ) as $file ) {
            @unlink( $file );
        }
        foreach ( glob( $import_dir . '*.zip' ) as $file ) {
            @unlink( $file );
        }
    }

    // 3) On supprime les options internes
    delete_option( 'bihrwi_prices_generation' );
    delete_transient( 'bihrwi_api_token' );

    // Redirection avec notification
    $redirect = add_query_arg(
        array(
            'page'                => 'bihrwi_products',
            'bihrwi_reset_success' => 1,
        ),
        admin_url( 'admin.php' )
    );

    wp_safe_redirect( $redirect );
    exit;
}

	/**
	 * Handler pour télécharger tous les catalogues nécessaires en une seule action
	 */
	public function handle_download_all_catalogs() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}

		check_admin_referer( 'bihrwi_download_all_action', 'bihrwi_download_all_nonce' );

		$redirect_url = add_query_arg( array( 'page' => 'bihrwi_products' ), admin_url( 'admin.php' ) );

		try {
			$this->logger->log( 'Téléchargement de tous les catalogues: démarrage' );

            // Liste des catalogues à télécharger
            $catalogs = array(
                'References'         => 'References',
                'ExtendedReferences' => 'ExtendedReferences',
                'Attributes'         => 'Attributes',
                'Images'             => 'Images',
                'Stocks'             => 'Stocks',
            );			$downloaded_files = array();

			foreach ( $catalogs as $name => $path ) {
				$this->logger->log( "Téléchargement du catalogue: {$name}" );

				// 1. Démarrer la génération
				$ticket_id = $this->api_client->start_catalog_generation( $path );
				$this->logger->log( "Ticket ID pour {$name}: {$ticket_id}" );

				// 2. Attendre que le fichier soit prêt (max 5 minutes)
				$max_attempts = 60; // 60 * 5 secondes = 5 minutes
				$attempt      = 0;
				$status       = 'PROCESSING';

				while ( $attempt < $max_attempts && $status === 'PROCESSING' ) {
					sleep( 5 );
					$status_response = $this->api_client->get_catalog_status( $ticket_id );
					$status          = strtoupper( $status_response['status'] ?? '' );
					$attempt++;

					$this->logger->log( "Status {$name} (tentative {$attempt}): {$status}" );
				}

				if ( $status === 'ERROR' ) {
					$error_msg = $status_response['error'] ?? 'Erreur inconnue';
					throw new Exception( "Erreur lors de la génération du catalogue {$name}: {$error_msg}" );
				}

				if ( $status !== 'DONE' ) {
					throw new Exception( "Timeout lors de la génération du catalogue {$name}" );
				}

				// 3. Télécharger le fichier
				$download_id = $status_response['downloadId'] ?? '';
				if ( empty( $download_id ) || $download_id === '00000000000000000000000000000000' ) {
					$this->logger->log( "Catalogue {$name} non disponible (downloadId vide ou nul), passage au suivant" );
					continue; // Passe au catalogue suivant
				}

				$zip_file = $this->api_client->download_catalog_file( $download_id, strtolower( $name ) );
				if ( ! $zip_file ) {
					$this->logger->log( "Échec téléchargement {$name}, passage au suivant" );
					continue; // Passe au catalogue suivant au lieu de planter
				}

				$downloaded_files[ $name ] = $zip_file;
				$this->logger->log( "Catalogue {$name} téléchargé: {$zip_file}" );
			}

			// 4. Extraire tous les fichiers ZIP dans le dossier d'import
			$import_dir = WP_CONTENT_DIR . '/uploads/bihr-import/';
			if ( ! is_dir( $import_dir ) ) {
				wp_mkdir_p( $import_dir );
			}

			$total_extracted = 0;
			foreach ( $downloaded_files as $name => $zip_file ) {
				$extracted = $this->product_sync->extract_zip_to_import_dir( $zip_file );
				$total_extracted += $extracted;
				$this->logger->log( "Extraction {$name}: {$extracted} fichiers" );
			}

			$catalogs_downloaded = count( $downloaded_files );
			$this->logger->log( "Téléchargement terminé: {$catalogs_downloaded} catalogues, {$total_extracted} fichiers CSV extraits" );

			$redirect_url = add_query_arg(
				array(
					'bihrwi_download_success'   => 1,
					'bihrwi_files_count'        => $total_extracted,
					'bihrwi_catalogs_count'     => $catalogs_downloaded,
				),
				$redirect_url
			);

		} catch ( Exception $e ) {
			$this->logger->log( 'Erreur téléchargement catalogues: ' . $e->getMessage() );

			$redirect_url = add_query_arg(
				array(
					'bihrwi_download_error' => 1,
					'bihrwi_msg'            => urlencode( $e->getMessage() ),
				),
				$redirect_url
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handler AJAX pour le téléchargement de tous les catalogues
	 */
	public function ajax_download_all_catalogs() {
		check_ajax_referer( 'bihrwi_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		try {
			$this->logger->log( 'AJAX: Téléchargement de tous les catalogues' );

            // Liste des catalogues
            $catalogs = array(
                'References'         => 'References',
                'ExtendedReferences' => 'ExtendedReferences',
                'Attributes'         => 'Attributes',
                'Images'             => 'Images',
                'Stocks'             => 'Stocks',
            );		$downloaded_files = array();
		$failed_catalogs  = array();
		$max_retries      = 3; // Nombre de tentatives pour chaque catalogue

		// Première passe : essayer de télécharger tous les catalogues
		foreach ( $catalogs as $name => $path ) {
			$this->logger->log( "AJAX: Téléchargement du catalogue: {$name}" );

			try {
				$ticket_id       = $this->api_client->start_catalog_generation( $path );
				
				// Attendre 2 secondes avant de vérifier le statut (rate limit: 1/sec)
				sleep( 2 );
				
				$max_attempts    = 120; // 120 * 5 sec = 10 minutes max
				$attempt         = 0;

				// Vérifie immédiatement le statut
				$status_response = $this->api_client->get_catalog_status( $ticket_id );
				$status          = strtoupper( $status_response['status'] ?? '' );

				// Continue à vérifier tant que c'est en PROCESSING
				while ( $attempt < $max_attempts && $status === 'PROCESSING' ) {
					sleep( 5 );
					$status_response = $this->api_client->get_catalog_status( $ticket_id );
					$status          = strtoupper( $status_response['status'] ?? '' );
					$attempt++;
					
					// Log toutes les 6 tentatives (30 secondes)
					if ( $attempt % 6 === 0 ) {
						$elapsed = ( $attempt * 5 ) / 60;
						$this->logger->log( "AJAX: Status {$name}: {$status} (temps écoulé: " . number_format( $elapsed, 1 ) . " min)" );
					}
				}

				if ( $status === 'ERROR' ) {
					$error_msg = $status_response['error'] ?? 'Erreur inconnue';
					$this->logger->log( "AJAX: Erreur génération {$name}: {$error_msg}" );
					$failed_catalogs[ $name ] = $path;
					continue;
				}

				if ( $status === 'PROCESSING' ) {
					$this->logger->log( "AJAX: Catalogue {$name} toujours en PROCESSING après 10 minutes, ajout pour réessai" );
					$failed_catalogs[ $name ] = $path;
					continue;
				}

				if ( $status !== 'DONE' ) {
					$this->logger->log( "AJAX: Statut inattendu pour {$name}: {$status}" );
					$failed_catalogs[ $name ] = $path;
					continue;
				}

				$download_id = $status_response['downloadId'] ?? '';
				if ( empty( $download_id ) || $download_id === '00000000000000000000000000000000' ) {
					$this->logger->log( "AJAX: Catalogue {$name} non disponible (downloadId vide ou nul)" );
					continue; // Ne pas réessayer si le catalogue n'existe pas
				}

				$zip_file = $this->api_client->download_catalog_file( $download_id, strtolower( $name ) );
				if ( ! $zip_file ) {
					$this->logger->log( "AJAX: Échec téléchargement {$name}" );
					$failed_catalogs[ $name ] = $path;
					continue;
				}

				$downloaded_files[ $name ] = $zip_file;
				$this->logger->log( "AJAX: Catalogue {$name} téléchargé avec succès" );
				
				// Attendre 2 secondes avant le prochain catalogue (rate limit: 1/sec)
				sleep( 2 );

			} catch ( Exception $catalog_error ) {
				$this->logger->log( "AJAX: Exception pour {$name}: " . $catalog_error->getMessage() );
				$failed_catalogs[ $name ] = $path;
				
				// Attendre 2 secondes même en cas d'erreur pour éviter le rate limit
				sleep( 2 );
			}
		}

		// Réessayer les catalogues échoués
		$retry_count = 0;
		while ( ! empty( $failed_catalogs ) && $retry_count < $max_retries ) {
			$retry_count++;
			$this->logger->log( "AJAX: Nouvelle tentative ({$retry_count}/{$max_retries}) pour " . count( $failed_catalogs ) . " catalogue(s)" );
			
			// Attendre 15 secondes entre chaque série de réessais pour laisser l'API respirer
			if ( $retry_count > 1 ) {
				$this->logger->log( "AJAX: Pause de 15 secondes avant la nouvelle tentative..." );
				sleep( 15 );
			}
			
			$still_failed = array();

			foreach ( $failed_catalogs as $name => $path ) {
				$this->logger->log( "AJAX: Réessai du catalogue: {$name}" );

				try {
					$ticket_id       = $this->api_client->start_catalog_generation( $path );
					
					// Attendre 2 secondes avant de vérifier le statut (rate limit: 1/sec)
					sleep( 2 );
					
					$max_attempts    = 120; // 10 minutes max
					$attempt         = 0;

					$status_response = $this->api_client->get_catalog_status( $ticket_id );
					$status          = strtoupper( $status_response['status'] ?? '' );

					while ( $attempt < $max_attempts && $status === 'PROCESSING' ) {
						sleep( 5 );
						$status_response = $this->api_client->get_catalog_status( $ticket_id );
						$status          = strtoupper( $status_response['status'] ?? '' );
						$attempt++;
						
						// Log toutes les 6 tentatives (30 secondes)
						if ( $attempt % 6 === 0 ) {
							$elapsed = ( $attempt * 5 ) / 60;
							$this->logger->log( "AJAX: Réessai {$name}: {$status} (temps écoulé: " . number_format( $elapsed, 1 ) . " min)" );
						}
					}

					if ( $status === 'ERROR' ) {
						$this->logger->log( "AJAX: Erreur lors du réessai de {$name}" );
						$still_failed[ $name ] = $path;
						continue;
					}

					if ( $status === 'PROCESSING' ) {
						$this->logger->log( "AJAX: {$name} toujours en PROCESSING après 10 minutes (réessai)" );
						$still_failed[ $name ] = $path;
						continue;
					}

					if ( $status !== 'DONE' ) {
						$still_failed[ $name ] = $path;
						continue;
					}

					$download_id = $status_response['downloadId'] ?? '';
					if ( empty( $download_id ) || $download_id === '00000000000000000000000000000000' ) {
						continue; // Ne pas réessayer
					}

					$zip_file = $this->api_client->download_catalog_file( $download_id, strtolower( $name ) );
					if ( ! $zip_file ) {
						$still_failed[ $name ] = $path;
						continue;
					}

					$downloaded_files[ $name ] = $zip_file;
					$this->logger->log( "AJAX: Catalogue {$name} téléchargé avec succès (après réessai)" );
					
					// Attendre 2 secondes avant le prochain catalogue (rate limit: 1/sec)
					sleep( 2 );

				} catch ( Exception $catalog_error ) {
					$this->logger->log( "AJAX: Exception réessai {$name}: " . $catalog_error->getMessage() );
					$still_failed[ $name ] = $path;
					
					// Attendre 2 secondes même en cas d'erreur pour éviter le rate limit
					sleep( 2 );
				}
			}

			$failed_catalogs = $still_failed;
		}			// Extraction
			$import_dir = WP_CONTENT_DIR . '/uploads/bihr-import/';
			if ( ! is_dir( $import_dir ) ) {
				wp_mkdir_p( $import_dir );
			}

			$total_extracted = 0;
			foreach ( $downloaded_files as $name => $zip_file ) {
				$extracted        = $this->product_sync->extract_zip_to_import_dir( $zip_file );
				$total_extracted += $extracted;
			}

			$catalogs_downloaded = count( $downloaded_files );
			$this->logger->log( "AJAX: Téléchargement terminé - {$catalogs_downloaded} catalogues, {$total_extracted} fichiers" );

			wp_send_json_success( array( 
				'files_count'    => $total_extracted,
				'catalogs_count' => $catalogs_downloaded,
			) );

		} catch ( Exception $e ) {
			$this->logger->log( 'AJAX: Erreur - ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX pour la fusion des catalogues
	 */
	public function ajax_merge_catalogs() {
		check_ajax_referer( 'bihrwi_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		try {
			$this->logger->log( 'AJAX: Fusion des catalogues' );

			$count = $this->product_sync->merge_catalogs_from_directory();

			$this->logger->log( "AJAX: Fusion terminée - {$count} produits" );

			wp_send_json_success( array( 'count' => $count ) );

		} catch ( Exception $e ) {
			$this->logger->log( 'AJAX: Erreur fusion - ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Handler AJAX pour l'import d'un seul produit
	 */
	public function ajax_import_single_product() {
		check_ajax_referer( 'bihrwi_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => 'ID de produit invalide.' ) );
		}

		try {
			$this->logger->log( "AJAX: Import du produit ID {$product_id}" );

			$wc_id = $this->product_sync->import_to_woocommerce( $product_id );

			if ( $wc_id ) {
				$this->logger->log( "AJAX: Produit {$product_id} importé avec succès (WC ID: {$wc_id})" );
				wp_send_json_success( array( 
					'wc_id' => $wc_id,
					'message' => 'Produit importé avec succès.'
				) );
			} else {
				$this->logger->log( "AJAX: Échec de l'import du produit {$product_id}" );
				wp_send_json_error( array( 'message' => 'Échec de l\'import du produit.' ) );
			}

		} catch ( Exception $e ) {
			$this->logger->log( "AJAX: Erreur import produit {$product_id} - " . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Rafraîchir le stock en temps réel depuis l'API BIHR
	 */
	public function ajax_refresh_stock() {
		check_ajax_referer( 'bihrwi_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permissions insuffisantes.' ) );
		}

		$product_code = sanitize_text_field( $_POST['product_code'] ?? '' );
		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;

		if ( empty( $product_code ) ) {
			wp_send_json_error( array( 'message' => 'Code produit manquant.' ) );
		}

		try {
			$api = new BihrWI_API_Client( $this->logger );
			$stock_data = $api->get_real_time_stock( $product_code );

			if ( $stock_data === false ) {
				wp_send_json_error( array( 
					'message' => 'Impossible de récupérer le stock depuis l\'API BIHR.' 
				) );
			}

			$stock_level = $stock_data['stock_level'];

			// Si un product_id WooCommerce est fourni, mettre à jour le stock
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$product->set_stock_quantity( $stock_level );
					
					// Mise à jour du statut de stock
					if ( $stock_level > 0 ) {
						$product->set_stock_status( 'instock' );
					} else {
						$product->set_stock_status( 'outofstock' );
					}
					
					$product->save();
					
					$this->logger->log( sprintf(
						'Stock WooCommerce mis à jour pour %s (ID: %d): %d unités',
						$product_code,
						$product_id,
						$stock_level
					) );
				}
			} else {
				$this->logger->log( sprintf(
					'Stock temps réel récupéré pour %s: %d unités (pas de mise à jour WooCommerce)',
					$product_code,
					$stock_level
				) );
			}

			wp_send_json_success( array(
				'stock_level' => $stock_level,
				'product_code' => $product_code,
				'updated' => $product_id > 0
			) );

		} catch ( Exception $e ) {
			$this->logger->log( 'AJAX: Erreur refresh stock - ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Import de la liste des véhicules depuis VehiclesList.csv
	 */
	public function ajax_import_vehicles() {
		check_ajax_referer( 'bihrwi_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission refusée' ) );
		}

		try {
			$compatibility = new BihrWI_Vehicle_Compatibility();
			$result = $compatibility->import_vehicles_list();
			
			if ( $result['success'] ) {
				wp_send_json_success( array(
					'message' => sprintf(
						'Import terminé : %d véhicules importés, %d échecs',
						$result['imported'],
						$result['errors']
					),
					'imported' => $result['imported'],
					'errors' => $result['errors']
				) );
			} else {
				wp_send_json_error( array( 'message' => $result['message'] ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Import de compatibilités pour une marque spécifique
	 */
	public function ajax_import_compatibility() {
		check_ajax_referer( 'bihrwi_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission refusée' ) );
		}

		$brand = isset( $_POST['brand'] ) ? sanitize_text_field( $_POST['brand'] ) : '';
		
		if ( empty( $brand ) ) {
			wp_send_json_error( array( 'message' => 'Marque non spécifiée' ) );
		}

		try {
			$compatibility = new BihrWI_Vehicle_Compatibility();
			$result = $compatibility->import_brand_compatibility( $brand );
			
			if ( $result['success'] ) {
				wp_send_json_success( array(
					'message' => sprintf(
						'%s : %d compatibilités importées, %d échecs',
						$brand,
						$result['imported'],
						$result['errors']
					),
					'imported' => $result['imported'],
					'errors' => $result['errors'],
					'brand' => $brand
				) );
			} else {
				wp_send_json_error( array( 'message' => $result['message'] ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Import de toutes les compatibilités (toutes les marques)
	 */
	public function ajax_import_all_compatibility() {
		check_ajax_referer( 'bihrwi_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission refusée' ) );
		}

		try {
			$compatibility = new BihrWI_Vehicle_Compatibility();
			$brands = array( 'SHIN YO', 'TECNIUM', 'V BIKE', 'V PARTS', 'VECTOR', 'VICMA' );
			
			$total_imported = 0;
			$total_errors = 0;
			$results = array();

			foreach ( $brands as $brand ) {
				$result = $compatibility->import_brand_compatibility( $brand );
				
				if ( $result['success'] ) {
					$total_imported += $result['imported'];
					$total_errors += $result['errors'];
					$results[] = sprintf( '%s: %d importés', $brand, $result['imported'] );
				} else {
					$results[] = sprintf( '%s: ÉCHEC - %s', $brand, $result['message'] );
				}
			}

			wp_send_json_success( array(
				'message' => sprintf(
					'Import global terminé : %d compatibilités importées, %d échecs',
					$total_imported,
					$total_errors
				),
				'total_imported' => $total_imported,
				'total_errors' => $total_errors,
				'details' => $results
			) );

		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	
	
}
