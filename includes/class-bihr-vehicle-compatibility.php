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

    public function __construct( BihrWI_Logger $logger = null ) {
        global $wpdb;
        
        $this->logger              = $logger ?? new BihrWI_Logger();
        $this->vehicles_table      = $wpdb->prefix . 'bihr_vehicles';
        $this->compatibility_table = $wpdb->prefix . 'bihr_vehicle_compatibility';
        
        // Vérifier et créer les tables si nécessaire
        $this->ensure_tables_exist();
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
    public function import_vehicles_list( $file_path ) {
        $this->logger->log( '=== IMPORT LISTE VÉHICULES ===' );
        $this->logger->log( "Fichier: {$file_path}" );

        if ( ! file_exists( $file_path ) ) {
            $this->logger->log( 'Erreur: Fichier introuvable' );
            return false;
        }

        global $wpdb;

        // Vider la table avant import
        $wpdb->query( "TRUNCATE TABLE {$this->vehicles_table}" );
        $this->logger->log( 'Table véhicules vidée' );

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            $this->logger->log( 'Erreur: Impossible d\'ouvrir le fichier' );
            return false;
        }

        // Lire le header
        $header = fgetcsv( $handle, 10000, ',' );
        if ( ! $header ) {
            fclose( $handle );
            return false;
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

        return $count;
    }

    /**
     * Importe les compatibilités depuis un fichier CSV de marque
     */
    public function import_brand_compatibility( $file_path, $brand_name ) {
        $this->logger->log( "=== IMPORT COMPATIBILITÉ {$brand_name} ===" );
        $this->logger->log( "Fichier: {$file_path}" );

        if ( ! file_exists( $file_path ) ) {
            $this->logger->log( 'Erreur: Fichier introuvable' );
            return false;
        }

        global $wpdb;

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            $this->logger->log( 'Erreur: Impossible d\'ouvrir le fichier' );
            return false;
        }

        // Lire le header
        $header = fgetcsv( $handle, 10000, ',' );
        if ( ! $header ) {
            fclose( $handle );
            return false;
        }

        $count = 0;
        $errors = 0;

        while ( ( $row = fgetcsv( $handle, 10000, ',' ) ) !== false ) {
            if ( count( $row ) < 3 ) {
                continue;
            }

            $compatibility_data = array(
                'vehicle_code'             => $row[0],
                'part_number'              => $row[1],
                'barcode'                  => $row[2] ?? '',
                'manufacturer_part_number' => $row[3] ?? '',
                'position_id'              => $row[4] ?? '',
                'position_value'           => $row[5] ?? '',
                'attributes'               => $row[6] ?? '',
                'source_brand'             => $brand_name,
            );

            $result = $wpdb->insert( $this->compatibility_table, $compatibility_data );
            
            if ( $result ) {
                $count++;
            } else {
                $errors++;
            }
        }

        fclose( $handle );

        $this->logger->log( "✓ Import terminé: {$count} compatibilités importées, {$errors} erreurs" );
        $this->logger->log( '==============================' );

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
