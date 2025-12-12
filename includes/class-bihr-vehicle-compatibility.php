<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gestion de la compatibilité véhicule-produit
 */
class BihrWI_Vehicle_Compatibility {

    protected $logger;
    protected $vehicles_table;
    protected $compatibility_table;
    protected $import_dir;

    public function __construct( BihrWI_Logger $logger = null ) {
        global $wpdb;
        
        $this->logger              = $logger ?? new BihrWI_Logger();
        $this->vehicles_table      = $wpdb->prefix . 'bihr_vehicles';
        $this->compatibility_table = $wpdb->prefix . 'bihr_vehicle_compatibility';
        $this->import_dir          = trailingslashit( wp_upload_dir()['basedir'] ) . 'bihr-import/';
        
        // Vérifier et créer les tables si nécessaire
        $this->ensure_tables_exist();
        $this->ensure_import_dir_exists();
    }

    /**
     * S'assure que le dossier d'import existe
     */
    protected function ensure_import_dir_exists() {
        if ( ! file_exists( $this->import_dir ) ) {
            wp_mkdir_p( $this->import_dir );
        }
    }

    /**
     * Vérifie si les tables existent et les crée si nécessaire
     */
    protected function ensure_tables_exist() {
        global $wpdb;
        
        $vehicles_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->vehicles_table
            )
        );
        
        $compatibility_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->compatibility_table
            )
        );
        
        if ( ! $vehicles_exists || ! $compatibility_exists ) {
            $this->create_tables();
        }
    }

    /**
     * Crée les tables de compatibilité
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();

        // Table des véhicules
        $sql_vehicles = "CREATE TABLE IF NOT EXISTS {$this->vehicles_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_code VARCHAR(50) NOT NULL,
            version_code VARCHAR(50),
            commercial_model_code VARCHAR(50),
            manufacturer_code VARCHAR(50),
            vehicle_year YEAR,
            version_name VARCHAR(255),
            commercial_model_name VARCHAR(255),
            manufacturer_name VARCHAR(100),
            universe_name VARCHAR(100),
            category_name VARCHAR(100),
            displacement_cm3 INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY vehicle_code (vehicle_code),
            KEY manufacturer_code (manufacturer_code),
            KEY vehicle_year (vehicle_year),
            KEY commercial_model_code (commercial_model_code)
        ) $charset_collate;";

        // Table de compatibilité produit-véhicule
        $sql_compatibility = "CREATE TABLE IF NOT EXISTS {$this->compatibility_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_code VARCHAR(50) NOT NULL,
            part_number VARCHAR(100) NOT NULL,
            barcode VARCHAR(100),
            manufacturer_part_number VARCHAR(100),
            position_id VARCHAR(50),
            position_value VARCHAR(255),
            attributes TEXT,
            source_brand VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY vehicle_code (vehicle_code),
            KEY part_number (part_number),
            KEY manufacturer_part_number (manufacturer_part_number),
            KEY source_brand (source_brand)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql_vehicles );
        dbDelta( $sql_compatibility );

        $this->logger->log( 'Tables de compatibilité véhicule créées' );
    }

    /**
     * Importe la liste des véhicules depuis VehiclesList.csv
     */
    public function import_vehicles_list( $file_path = null ) {
        $this->logger->log( '=== IMPORT LISTE VÉHICULES ===' );

        $file_path = $file_path ?: $this->import_dir . 'VehiclesList.csv';
        $this->logger->log( "Fichier: {$file_path}" );

        if ( ! file_exists( $file_path ) ) {
            $this->logger->log( 'Erreur: Fichier introuvable' );
            return array(
                'success' => false,
                'message' => 'Fichier introuvable: ' . $file_path,
                'imported' => 0,
                'errors' => 0
            );
        }

        global $wpdb;

        // Vider la table avant import
        $wpdb->query( "TRUNCATE TABLE {$this->vehicles_table}" );
        $this->logger->log( 'Table véhicules vidée' );

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            $this->logger->log( 'Erreur: Impossible d\'ouvrir le fichier' );
            return array(
                'success' => false,
                'message' => 'Impossible d\'ouvrir le fichier',
                'imported' => 0,
                'errors' => 0
            );
        }

        // Lire le header
        $header = fgetcsv( $handle, 10000, ',' );
        if ( ! $header ) {
            fclose( $handle );
            return array(
                'success' => false,
                'message' => 'Fichier CSV invalide (header manquant)',
                'imported' => 0,
                'errors' => 0
            );
        }

        $count = 0;
        $errors = 0;

        while ( ( $row = fgetcsv( $handle, 10000, ',' ) ) !== false ) {
            if ( count( $row ) < 11 ) {
                continue;
            }

            $vehicle_data = array(
                'vehicle_code'           => $row[0],
                'version_code'           => $row[1],
                'commercial_model_code'  => $row[2],
                'manufacturer_code'      => $row[3],
                'vehicle_year'           => $row[4],
                'version_name'           => $row[5],
                'commercial_model_name'  => $row[6],
                'manufacturer_name'      => $row[7],
                'universe_name'          => $row[8],
                'category_name'          => $row[9],
                'displacement_cm3'       => intval( $row[10] ),
            );

            $result = $wpdb->insert( $this->vehicles_table, $vehicle_data );
            
            if ( $result ) {
                $count++;
            } else {
                $errors++;
            }
        }

        fclose( $handle );

        $this->logger->log( "✓ Import terminé: {$count} véhicules importés, {$errors} erreurs" );
        $this->logger->log( '==============================' );

        return array(
            'success' => true,
            'message' => "{$count} véhicules importés, {$errors} erreurs",
            'imported' => $count,
            'errors' => $errors
        );
    }

    /**
     * Vide les tables de compatibilité
     */
    public function clear_data() {
        global $wpdb;

        $wpdb->query( "TRUNCATE TABLE {$this->vehicles_table}" );
        $wpdb->query( "TRUNCATE TABLE {$this->compatibility_table}" );

        $this->logger->log( 'Tables de compatibilité vidées' );

        return array(
            'success'  => true,
            'message'  => 'Tables vidées',
            'vehicles' => 0,
            'links'    => 0,
        );
    }

    /**
     * Décompresse une archive ZIP dans le dossier d'import
     */
    public function unzip_to_import_dir( $zip_path ) {
        $this->ensure_import_dir_exists();

        if ( ! file_exists( $zip_path ) ) {
            return array(
                'success' => false,
                'message' => 'Archive introuvable: ' . $zip_path,
            );
        }

        if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        $result = unzip_file( $zip_path, $this->import_dir );

        if ( is_wp_error( $result ) ) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'message' => 'Archive extraite',
            'target'  => $this->import_dir,
        );
    }

    /**
     * Importe les compatibilités par marque avec support de progression
     * 
     * @param string $brand_name Nom de la marque
     * @param string $file_path Chemin du fichier CSV
     * @param int $batch_start Ligne de départ pour ce batch
     * @return array Résultat avec progression
     */
    public function import_brand_compatibility( $brand_name, $file_path = null, $batch_start = 0 ) {
        $file_path = $file_path ?: $this->import_dir . '[' . $brand_name . '].csv';
        
        if ( ! file_exists( $file_path ) ) {
            return array(
                'success'       => false,
                'imported'      => 0,
                'errors'        => 0,
                'total_lines'   => 0,
                'processed'     => 0,
                'progress'      => 0,
                'is_complete'   => false
            );
        }

        global $wpdb;

        // Compter le nombre total de lignes une seule fois (stocké en transient)
        $transient_key = 'bihr_import_total_' . md5( $file_path );
        $total_lines = get_transient( $transient_key );
        
        if ( false === $total_lines ) {
            $total_lines = $this->count_csv_lines( $file_path );
            set_transient( $transient_key, $total_lines, HOUR_IN_SECONDS );
        }

        $batch_size = 100; // Traiter 100 lignes par batch
        $h = fopen( $file_path, 'r' );
        
        if ( ! $h ) {
            return array(
                'success'       => false,
                'imported'      => 0,
                'errors'        => 0,
                'total_lines'   => $total_lines,
                'processed'     => 0,
                'progress'      => 0,
                'is_complete'   => false
            );
        }

        // Lire le header
        $header = fgetcsv( $h, 10000, ',' );
        if ( ! $header ) {
            fclose( $h );
            delete_transient( $transient_key );
            return array(
                'success'       => false,
                'imported'      => 0,
                'errors'        => 0,
                'total_lines'   => $total_lines,
                'processed'     => 0,
                'progress'      => 0,
                'is_complete'   => false
            );
        }

        // Sauter aux lignes précédentes si reprise
        for ( $i = 0; $i < $batch_start; $i++ ) {
            fgetcsv( $h, 10000, ',' );
        }

        $count = 0;
        $errors = 0;
        $batch = array();
        $current_line = $batch_start;

        // Traiter un batch
        while ( ( $row = fgetcsv( $h, 10000, ',' ) ) !== false && $current_line < $batch_start + $batch_size ) {
            if ( count( $row ) < 3 ) {
                $current_line++;
                continue;
            }

            $batch[] = array(
                'vehicle_code'              => trim( $row[0] ?? '' ),
                'part_number'               => trim( $row[1] ?? '' ),
                'barcode'                   => trim( $row[2] ?? '' ),
                'manufacturer_part_number'  => trim( $row[3] ?? '' ),
                'position_id'               => trim( $row[4] ?? '' ),
                'position_value'            => trim( $row[5] ?? '' ),
                'attributes'                => trim( $row[6] ?? '' ),
                'source_brand'              => $brand_name,
            );

            $current_line++;
        }

        fclose( $h );

        // Insérer le batch
        foreach ( $batch as $data ) {
            if ( $wpdb->insert( $this->compatibility_table, $data ) ) {
                $count++;
            } else {
                $errors++;
            }
        }

        wp_cache_flush();

        // Calculer la progression
        $processed = $batch_start + count( $batch );
        $progress = $total_lines > 0 ? round( ( $processed / $total_lines ) * 100 ) : 100;
        $is_complete = $processed >= $total_lines;

        // Nettoyer le transient si terminé
        if ( $is_complete ) {
            delete_transient( $transient_key );
        }

        return array(
            'success'       => true,
            'imported'      => $count,
            'errors'        => $errors,
            'total_lines'   => $total_lines,
            'processed'     => $processed,
            'progress'      => $progress,
            'is_complete'   => $is_complete,
            'next_batch'    => $is_complete ? 0 : $processed,
        );
    }

    /**
     * Compte les lignes dans un fichier CSV
     * 
     * @param string $file_path Chemin du fichier
     * @return int Nombre de lignes (sans le header)
     */
    protected function count_csv_lines( $file_path ) {
        $count = 0;
        $h = fopen( $file_path, 'r' );
        
        if ( ! $h ) {
            return 0;
        }

        // Passer le header
        fgetcsv( $h, 10000, ',' );

        // Compter les lignes
        while ( fgetcsv( $h, 10000, ',' ) !== false ) {
            $count++;
        }

        fclose( $h );
        return $count;
    }

    /**
     * Récupère tous les fabricants distincts
     */
    public function get_manufacturers() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT DISTINCT manufacturer_code, manufacturer_name 
             FROM {$this->vehicles_table} 
             WHERE manufacturer_name IS NOT NULL AND manufacturer_name != ''
             ORDER BY manufacturer_name ASC",
            ARRAY_A
        );

        return $results;
    }

    /**
     * Récupère les modèles pour un fabricant donné
     */
    public function get_models_by_manufacturer( $manufacturer_code ) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT commercial_model_code, commercial_model_name 
                 FROM {$this->vehicles_table} 
                 WHERE manufacturer_code = %s 
                 AND commercial_model_name IS NOT NULL 
                 AND commercial_model_name != ''
                 ORDER BY commercial_model_name ASC",
                $manufacturer_code
            ),
            ARRAY_A
        );

        return $results;
    }

    /**
     * Récupère les versions pour un modèle donné
     */
    public function get_versions_by_model( $commercial_model_code ) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT vehicle_code, version_name, vehicle_year, displacement_cm3 
                 FROM {$this->vehicles_table} 
                 WHERE commercial_model_code = %s 
                 ORDER BY vehicle_year DESC, version_name ASC",
                $commercial_model_code
            ),
            ARRAY_A
        );

        return $results;
    }

    /**
     * Récupère les produits compatibles avec un véhicule
     */
    public function get_compatible_products( $vehicle_code ) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT c.part_number, c.source_brand, c.manufacturer_part_number
                 FROM {$this->compatibility_table} c
                 WHERE c.vehicle_code = %s
                 ORDER BY c.part_number ASC",
                $vehicle_code
            ),
            ARRAY_A
        );

        return $results;
    }

    /**
     * Récupère les véhicules compatibles avec un produit
     */
    public function get_compatible_vehicles( $part_number ) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT 
                    v.vehicle_code,
                    v.manufacturer_name,
                    v.commercial_model_name,
                    v.version_name,
                    v.vehicle_year,
                    v.displacement_cm3,
                    c.source_brand
                 FROM {$this->compatibility_table} c
                 INNER JOIN {$this->vehicles_table} v ON c.vehicle_code = v.vehicle_code
                 WHERE c.part_number = %s
                 ORDER BY v.manufacturer_name ASC, v.commercial_model_name ASC, v.vehicle_year DESC",
                $part_number
            ),
            ARRAY_A
        );

        return $results;
    }

    /**
     * Vérifie si un produit est compatible avec un véhicule
     */
    public function is_compatible( $part_number, $vehicle_code ) {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
                 FROM {$this->compatibility_table} 
                 WHERE part_number = %s AND vehicle_code = %s",
                $part_number,
                $vehicle_code
            )
        );

        return $count > 0;
    }

    /**
     * Obtient des statistiques sur les compatibilités
     */
    public function get_statistics() {
        global $wpdb;

        $stats = array();

        // Nombre total de véhicules
        $stats['total_vehicles'] = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->vehicles_table}" );

        // Nombre total de compatibilités
        $stats['total_compatibilities'] = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->compatibility_table}" );

        // Nombre de produits avec compatibilités
        $stats['products_with_compatibility'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT part_number) FROM {$this->compatibility_table}"
        );

        // Marques sources
        $stats['source_brands'] = $wpdb->get_results(
            "SELECT source_brand, COUNT(*) as count 
             FROM {$this->compatibility_table} 
             GROUP BY source_brand 
             ORDER BY count DESC",
            ARRAY_A
        );

        // Fabricants de véhicules
        $stats['manufacturers'] = $wpdb->get_results(
            "SELECT manufacturer_name, COUNT(*) as count 
             FROM {$this->vehicles_table} 
             GROUP BY manufacturer_name 
             ORDER BY count DESC 
             LIMIT 10",
            ARRAY_A
        );

        return $stats;
    }
}
