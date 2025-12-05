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

    }
	
	/**
	 * Charge les assets CSS et JS pour l'admin
	 */
	public function enqueue_admin_assets( $hook ) {
		// Charge uniquement sur les pages du plugin
		if ( strpos( $hook, 'bihrwi' ) === false ) {
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
                delete_option( 'bihrwi_prices_generation' );

                $redirect_url = add_query_arg(
                    array(
                        'bihrwi_check_status' => 'done',
                        'bihrwi_file'         => urlencode( $file_path ),
                    ),
                    $redirect_url
                );
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
    }

    // === RENDER PAGES ===

    public function render_auth_page() {
        $username   = get_option( 'bihrwi_username', '' );
        $password   = get_option( 'bihrwi_password', '' );
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
        $filter_search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        $filter_stock  = isset( $_GET['stock_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['stock_filter'] ) ) : '';
        $filter_price  = isset( $_GET['price_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['price_filter'] ) ) : '';

        $products     = $this->product_sync->get_products( $current_page, $per_page, $filter_search, $filter_stock, $filter_price );
        $total        = $this->product_sync->get_products_count( $filter_search, $filter_stock, $filter_price );
        $total_pages  = max( 1, ceil( $total / $per_page ) );

        include BIHRWI_PLUGIN_DIR . 'admin/views/products-page.php';
    }

    // === HANDLERS FORM ===

    public function handle_authenticate() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }

        check_admin_referer( 'bihrwi_authenticate_action', 'bihrwi_authenticate_nonce' );

        $username = isset( $_POST['bihrwi_username'] ) ? sanitize_text_field( wp_unslash( $_POST['bihrwi_username'] ) ) : '';
        $password = isset( $_POST['bihrwi_password'] ) ? sanitize_text_field( wp_unslash( $_POST['bihrwi_password'] ) ) : '';

        update_option( 'bihrwi_username', $username );
        update_option( 'bihrwi_password', $password );

        $redirect_url = add_query_arg( array( 'page' => 'bihrwi_auth' ), admin_url( 'admin.php' ) );

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

        wp_safe_redirect( $redirect_url );
        exit;
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
                'Inventory'          => 'Inventory',
            );		$downloaded_files = array();
		$failed_catalogs  = array();
		$max_retries      = 3; // Nombre de tentatives pour chaque catalogue

		// Première passe : essayer de télécharger tous les catalogues
		foreach ( $catalogs as $name => $path ) {
			$this->logger->log( "AJAX: Téléchargement du catalogue: {$name}" );

			try {
				$ticket_id       = $this->api_client->start_catalog_generation( $path );
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

			} catch ( Exception $catalog_error ) {
				$this->logger->log( "AJAX: Exception pour {$name}: " . $catalog_error->getMessage() );
				$failed_catalogs[ $name ] = $path;
			}
		}

		// Réessayer les catalogues échoués
		$retry_count = 0;
		while ( ! empty( $failed_catalogs ) && $retry_count < $max_retries ) {
			$retry_count++;
			$this->logger->log( "AJAX: Nouvelle tentative ({$retry_count}/{$max_retries}) pour " . count( $failed_catalogs ) . " catalogue(s)" );
			
			$still_failed = array();

			foreach ( $failed_catalogs as $name => $path ) {
				$this->logger->log( "AJAX: Réessai du catalogue: {$name}" );

				try {
					$ticket_id       = $this->api_client->start_catalog_generation( $path );
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

				} catch ( Exception $catalog_error ) {
					$this->logger->log( "AJAX: Exception réessai {$name}: " . $catalog_error->getMessage() );
					$still_failed[ $name ] = $path;
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

	
	
}
