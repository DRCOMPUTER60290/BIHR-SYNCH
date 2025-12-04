<?php
/**
 * Plugin Name: Bihr WooCommerce Importer
 * Description: Import des catalogues Bihr (Prices, Images, Attributes, Stocks) et création de produits WooCommerce.
 * Author: Benjamin / DrComputer60290
 * Version: 1.0.0
 * Text Domain: bihr-woocommerce-importer
 */

// Sécurité de base
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constantes
define( 'BIHRWI_VERSION', '1.0.0' );
define( 'BIHRWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIHRWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BIHRWI_LOG_FILE', WP_CONTENT_DIR . '/uploads/bihr-import/bihr-import.log' );
define( 'BIHRWI_IMAGE_BASE_URL', 'https://api.mybihr.com' );

// Autochargement simple de nos classes
require_once BIHRWI_PLUGIN_DIR . 'includes/class-bihr-logger.php';
require_once BIHRWI_PLUGIN_DIR . 'includes/class-bihr-api-client.php';
require_once BIHRWI_PLUGIN_DIR . 'includes/class-bihr-product-sync.php';
require_once BIHRWI_PLUGIN_DIR . 'admin/class-bihr-admin.php';

// Activation : création table + dossier logs
register_activation_hook( __FILE__, 'bihrwi_activate_plugin' );

function bihrwi_activate_plugin() {
    global $wpdb;

    // Dossier logs
    $log_dir = dirname( BIHRWI_LOG_FILE );
    if ( ! file_exists( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
    }
    if ( ! file_exists( BIHRWI_LOG_FILE ) ) {
        file_put_contents( BIHRWI_LOG_FILE, '' );
    }

    // Table wp_bihr_products
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name      = $wpdb->prefix . 'bihr_products';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        product_code VARCHAR(100) NOT NULL,
        new_part_number VARCHAR(100) NULL,
        name TEXT NULL,
        description LONGTEXT NULL,
        image_url TEXT NULL,
        dealer_price_ht DECIMAL(15,4) NULL,
        stock_level INT NULL,
        stock_description TEXT NULL,
        PRIMARY KEY  (id),
        KEY product_code (product_code)
    ) $charset_collate;";

    dbDelta( $sql );
}

// Ajoute un intervalle "tous les 5 minutes" pour WP-Cron
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['five_minutes'] ) ) {
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'bihr-woocommerce-importer' ),
        );
    }
    return $schedules;
} );

// Hook cron pour vérifier l'état du catalog Prices
add_action( 'bihrwi_check_prices_catalog_event', 'bihrwi_check_prices_catalog' );

/**
 * Vérifie en tâche de fond (CRON) si le catalog Prices est prêt,
 * et télécharge le fichier dès que le status passe à DONE.
 */
function bihrwi_check_prices_catalog() {
    $logger = new BihrWI_Logger();
    $api    = new BihrWI_API_Client( $logger );

    $status_data = get_option( 'bihrwi_prices_generation', array() );

    // Si aucun ticket à surveiller, on ne fait rien
    if ( empty( $status_data['ticket_id'] ) ) {
        return;
    }

    try {
        $ticket_id = $status_data['ticket_id'];

        $logger->log( 'CRON: Vérification du status du catalog Prices pour ticket_id=' . $ticket_id );

        $status_response = $api->get_catalog_status( $ticket_id );
        if ( empty( $status_response['status'] ) ) {
            $logger->log( 'CRON: Réponse status invalide pour Prices.' );
            return;
        }

        $status = strtoupper( $status_response['status'] );

        // On mémorise le dernier statut et la dernière vérification pour l’affichage dans l’admin
        $status_data['last_status']  = $status;
        $status_data['last_checked'] = current_time( 'mysql' );
        update_option( 'bihrwi_prices_generation', $status_data );

        // Toujours en traitement côté Bihr
        if ( $status === 'PROCESSING' ) {
            $logger->log( 'CRON: Prices toujours en PROCESSING, on réessaiera plus tard.' );
            return;
        }

        // Erreur de génération
        if ( $status === 'ERROR' ) {
            $error_msg = isset( $status_response['error'] ) ? $status_response['error'] : 'Erreur inconnue.';
            $logger->log( 'CRON: Prices en ERROR : ' . $error_msg );
            delete_option( 'bihrwi_prices_generation' );
            return;
        }

        // Fichier prêt
        if ( $status === 'DONE' && ! empty( $status_response['downloadId'] ) ) {
            $download_id = $status_response['downloadId'];
            $logger->log( 'CRON: Prices DONE, récupération du fichier avec DownloadId=' . $download_id );

            $file_path = $api->download_catalog_file( $download_id, 'prices' );

            if ( $file_path ) {
                $logger->log( 'CRON: Fichier Prices téléchargé : ' . $file_path );
                // Ici tu pourras déclencher automatiquement la fusion si tu veux
            } else {
                $logger->log( 'CRON: Échec du téléchargement du fichier Prices.' );
            }

            // Génération terminée, on nettoie
            delete_option( 'bihrwi_prices_generation' );
        }

    } catch ( Exception $e ) {
        $logger->log( 'CRON: Exception pendant la vérification Prices : ' . $e->getMessage() );
    }
}

// Initialisation de l’admin
add_action( 'plugins_loaded', function() {
    if ( is_admin() ) {
        new BihrWI_Admin();
    }
} );
