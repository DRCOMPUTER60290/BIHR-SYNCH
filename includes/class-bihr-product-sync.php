<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BihrWI_Product_Sync {

    protected $logger;
    protected $table_name;

    public function __construct( BihrWI_Logger $logger ) {
        global $wpdb;
        $this->logger     = $logger;
        $this->table_name = $wpdb->prefix . 'bihr_products';
    }

    /* =========================================================
     *   LECTURE / LISTE DES PRODUITS (pour la page d’admin)
     * ======================================================= */


    /**
     * Récupère la liste des catégories distinctes dans la base
     */
    public function get_distinct_categories() {
        global $wpdb;
        
        $sql = "SELECT DISTINCT category 
                FROM {$this->table_name} 
                WHERE category IS NOT NULL AND category != '' 
                ORDER BY category ASC";
        
        return $wpdb->get_col( $sql );
    }
    /**
     * Retourne une page de produits depuis wp_bihr_products avec filtres
     */
    public function get_products( $page = 1, $per_page = 20, $search = '', $stock_filter = '', $price_filter = '', $category_filter = '' ) {
        global $wpdb;

        $offset = ( max( 1, (int) $page ) - 1 ) * max( 1, (int) $per_page );

        // Construction de la requête avec filtres
        $where = array( '1=1' );
        
        // Filtre de recherche (code produit, nom, description)
        if ( ! empty( $search ) ) {
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]     = $wpdb->prepare(
                '(product_code LIKE %s OR name LIKE %s OR description LIKE %s)',
                $search_like,
                $search_like,
                $search_like
            );
        }

        // Filtre de stock
        if ( $stock_filter === 'in_stock' ) {
            $where[] = 'stock_level > 0';
        } elseif ( $stock_filter === 'out_of_stock' ) {
            $where[] = '(stock_level = 0 OR stock_level IS NULL)';
        }

        // Filtre de prix
        if ( $price_filter === 'with_price' ) {
            $where[] = 'dealer_price_ht IS NOT NULL AND dealer_price_ht > 0';
        } elseif ( $price_filter === 'without_price' ) {
            $where[] = '(dealer_price_ht IS NULL OR dealer_price_ht = 0)';
        }

        // Filtre de catégorie
        if ( ! empty( $category_filter ) ) {
            $where[] = $wpdb->prepare( 'category = %s', $category_filter );
        }

        $where_clause = implode( ' AND ', $where );

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY id ASC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Nombre total de lignes dans wp_bihr_products avec filtres
     */
    public function get_products_count( $search = '', $stock_filter = '', $price_filter = '', $category_filter = '' ) {
        global $wpdb;

        // Construction de la requête avec filtres
        $where = array( '1=1' );
        
        // Filtre de recherche
        if ( ! empty( $search ) ) {
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]     = $wpdb->prepare(
                '(product_code LIKE %s OR name LIKE %s OR description LIKE %s)',
                $search_like,
                $search_like,
                $search_like
            );
        }

        // Filtre de stock
        if ( $stock_filter === 'in_stock' ) {
            $where[] = 'stock_level > 0';
        } elseif ( $stock_filter === 'out_of_stock' ) {
            $where[] = '(stock_level = 0 OR stock_level IS NULL)';
        }

        // Filtre de prix
        if ( $price_filter === 'with_price' ) {
            $where[] = 'dealer_price_ht IS NOT NULL AND dealer_price_ht > 0';
        } elseif ( $price_filter === 'without_price' ) {
            $where[] = '(dealer_price_ht IS NULL OR dealer_price_ht = 0)';
        }

        // Filtre de catégorie
        if ( ! empty( $category_filter ) ) {
            $where[] = $wpdb->prepare( 'category = %s', $category_filter );
        }

        $where_clause = implode( ' AND ', $where );

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}" );
    }

    /* =========================================================
     *          IMPORT D’UN PRODUIT DANS WOOCOMMERCE
     * ======================================================= */

    /**
     * Importe un produit Bihr (ligne de wp_bihr_products) vers WooCommerce
     */
    public function import_to_woocommerce( $product_id ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                (int) $product_id
            )
        );

        if ( ! $row ) {
            throw new Exception( 'Produit introuvable dans wp_bihr_products.' );
        }

        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            throw new Exception( 'WooCommerce n’est pas chargé.' );
        }

        $this->logger->log( 'Import WooCommerce: préparation produit ' . $row->product_code );

        // Création d’un produit simple
        $product = new WC_Product_Simple();

        // Nom du produit
        $name = $row->name ?: $row->product_code;
        $product->set_name( $name );

        // Description
        if ( ! empty( $row->description ) ) {
            $product->set_description( $row->description );
        }

        // Prix HT (on le met comme prix catalogue – à adapter si tu veux une marge)
        if ( $row->dealer_price_ht !== null ) {
            $product->set_regular_price( wc_format_decimal( $row->dealer_price_ht ) );
        }

        // Gestion du stock
        if ( $row->stock_level !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( (int) $row->stock_level );
            $product->set_stock_status( (int) $row->stock_level > 0 ? 'instock' : 'outofstock' );
        }

        // Sauvegarde du produit
        $product_id_wc = $product->save();

        // Meta Bihr
        update_post_meta( $product_id_wc, '_bihr_product_code', $row->product_code );
        if ( ! empty( $row->new_part_number ) ) {
            update_post_meta( $product_id_wc, '_bihr_new_part_number', $row->new_part_number );
        }

        // Gestion des catégories
        if ( ! empty( $row->category ) ) {
            $category_ids = $this->create_or_get_category_hierarchy( $row->category );
            if ( ! empty( $category_ids ) ) {
                wp_set_object_terms( $product_id_wc, $category_ids, 'product_cat' );
                $this->logger->log( 'Catégories assignées : ' . implode( ', ', $category_ids ) );
            }
        }

        // Image principale (si URL disponible)
        if ( ! empty( $row->image_url ) ) {
            $attachment_id = $this->download_and_attach_image( $row->image_url, $product_id_wc );
            if ( $attachment_id ) {
                $product->set_image_id( $attachment_id );
                $product->save();
            }
        }

        $this->logger->log(
            'Import WooCommerce: produit ' . $row->product_code . ' importé avec succès (post_id=' . $product_id_wc . ')'
        );

        return $product_id_wc;
    }

    /**
     * Télécharge et attache une image à un produit WooCommerce
     */
    protected function download_and_attach_image( $image_url, $post_id ) {

        // OPTION 2 : si l'URL ne commence pas par http, on ajoute le préfixe https://api.mybihr.com
        if ( ! preg_match( '#^https?://#i', $image_url ) ) {
            $image_url = rtrim( BIHRWI_IMAGE_BASE_URL, '/' ) . '/' . ltrim( $image_url, '/' );
        }

        $this->logger->log( 'Téléchargement image : ' . $image_url );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Vérifie si on a déjà une image avec cette URL (évite les doublons)
        $existing_id = $this->find_existing_attachment_by_url( $image_url );
        if ( $existing_id ) {
            $this->logger->log( 'Image déjà présente (attachment_id=' . $existing_id . ').' );
            set_post_thumbnail( $post_id, $existing_id );
            return $existing_id;
        }

        // Télécharge l'image avec media_sideload_image
        $tmp = download_url( $image_url );

        if ( is_wp_error( $tmp ) ) {
            $this->logger->log( 'Erreur download_url : ' . $tmp->get_error_message() );
            return 0;
        }

        // Détecte le type MIME du fichier téléchargé
        $file_type = wp_check_filetype_and_ext( $tmp, basename( parse_url( $image_url, PHP_URL_PATH ) ) );
        
        // Si le type n'est pas détecté, on essaie avec mime_content_type
        if ( ! $file_type['ext'] && function_exists( 'mime_content_type' ) ) {
            $mime = mime_content_type( $tmp );
            $mime_to_ext = array(
                'image/jpeg' => 'jpg',
                'image/jpg'  => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            );
            
            if ( isset( $mime_to_ext[ $mime ] ) ) {
                $file_type['ext']  = $mime_to_ext[ $mime ];
                $file_type['type'] = $mime;
            }
        }
        
        // Génère un nom de fichier avec l'extension appropriée
        $filename = basename( parse_url( $image_url, PHP_URL_PATH ) );
        
        // Si le fichier n'a pas d'extension reconnue, on ajoute celle détectée
        if ( ! empty( $file_type['ext'] ) ) {
            $path_info = pathinfo( $filename );
            if ( empty( $path_info['extension'] ) || ! in_array( strtolower( $path_info['extension'] ), array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) ) ) {
                $filename = $path_info['filename'] . '.' . $file_type['ext'];
            }
        }

        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $tmp,
            'type'     => $file_type['type'],
        );

        $attachment_id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            $this->logger->log( 'Erreur media_handle_sideload : ' . $attachment_id->get_error_message() );
            @unlink( $tmp );
            return 0;
        }

        // On stocke la source dans un meta pour éviter les doublons plus tard
        update_post_meta( $attachment_id, '_bihr_image_source', esc_url_raw( $image_url ) );

        return $attachment_id;
    }

    /**
     * Crée ou récupère une catégorie WooCommerce (simple, sans hiérarchie)
     * @param string $category_name Ex: "RIDER GEAR" ou "VEHICLE PARTS & ACCESSORIES"
     * @return array Liste contenant l'ID de la catégorie
     */
    protected function create_or_get_category_hierarchy( $category_name ) {
        if ( empty( $category_name ) ) {
            return array();
        }

        // Chercher si la catégorie existe déjà
        $existing_term = get_term_by( 'name', $category_name, 'product_cat' );
        
        if ( $existing_term ) {
            // La catégorie existe déjà
            return array( $existing_term->term_id );
        }

        // Créer la nouvelle catégorie
        $term = wp_insert_term( $category_name, 'product_cat' );

        if ( is_wp_error( $term ) ) {
            // Si erreur "duplicate", récupérer l'ID existant
            if ( isset( $term->error_data['term_exists'] ) ) {
                return array( $term->error_data['term_exists'] );
            } else {
                $this->logger->log( 'Erreur création catégorie "' . $category_name . '": ' . $term->get_error_message() );
                return array();
            }
        }

        return array( $term['term_id'] );
    }

    /**
     * Retrouve un attachment existant par son meta _bihr_image_source
     */
    protected function find_existing_attachment_by_url( $image_url ) {
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'   => '_bihr_image_source',
                    'value' => esc_url_raw( $image_url ),
                ),
            ),
            'fields'         => 'ids',
        );

        $ids = get_posts( $args );
        if ( ! empty( $ids ) ) {
            return (int) $ids[0];
        }

        return 0;
    }

    /* =========================================================
     *      FUSION DES CATALOGUES CSV -> TABLE wp_bihr_products
     * ======================================================= */

    /**
     * Fusionne les différents catalogues CSV présents dans wp-content/uploads/bihr-import/
     */
    public function merge_catalogs_from_directory() {
        $upload_dir = WP_CONTENT_DIR . '/uploads/bihr-import';

        if ( ! is_dir( $upload_dir ) ) {
            wp_mkdir_p( $upload_dir );
        }

        $this->logger->log( 'Fusion catalogues: dossier = ' . $upload_dir );

        // Recherche des différents fichiers (le plus récent pour chaque type)
        $files = array(
            'references'         => $this->find_latest_catalog_file( $upload_dir, 'ref' ),
            'extendedreferences' => $this->find_latest_catalog_file( $upload_dir, 'extref' ),
            'prices'             => $this->find_latest_catalog_file( $upload_dir, 'prices' ),
            'images'             => $this->find_latest_catalog_file( $upload_dir, 'images' ),
            'inventory'          => $this->find_latest_catalog_file( $upload_dir, 'inventory' ),
            'attributes'         => $this->find_latest_catalog_file( $upload_dir, 'attributes' ),
        );

        $this->logger->log( 'Fusion catalogues: fichiers trouvés = ' . print_r( $files, true ) );

        $references_data         = array();
        $extendedreferences_data = array();
        $prices_data             = array();
        $images_data             = array();
        $inventory_data          = array();
        $attributes_data         = array();

        if ( ! empty( $files['references'] ) ) {
            $references_data = $this->parse_references_csv( $files['references'] );
        } else {
            $this->logger->log( 'ATTENTION: Fichier References manquant - les noms de produits ne seront pas disponibles!' );
        }

        if ( ! empty( $files['extendedreferences'] ) ) {
            // ExtendedReferences peut être divisé en plusieurs fichiers (_A, _B, etc.)
            $extref_pattern = str_replace( basename( $files['extendedreferences'] ), 'cat-extref-full-*.csv', $files['extendedreferences'] );
            $all_extref_files = glob( $extref_pattern );
            
            if ( ! empty( $all_extref_files ) ) {
                $this->logger->log( 'ExtendedReferences: ' . count( $all_extref_files ) . ' fichiers trouvés' );
                foreach ( $all_extref_files as $extref_file ) {
                    $partial_data = $this->parse_extendedreferences_csv( $extref_file );
                    
                    // Merge intelligent : ne pas écraser la catégorie si elle existe déjà
                    foreach ( $partial_data as $code => $data ) {
                        if ( ! isset( $extendedreferences_data[ $code ] ) ) {
                            // Nouveau produit : on l'ajoute tel quel
                            $extendedreferences_data[ $code ] = $data;
                        } else {
                            // Produit existant : on merge SAUF la catégorie si elle existe déjà
                            foreach ( $data as $key => $value ) {
                                if ( $key === 'category' && isset( $extendedreferences_data[ $code ]['category'] ) && ! empty( $extendedreferences_data[ $code ]['category'] ) ) {
                                    // Garder la première catégorie trouvée
                                    continue;
                                }
                                $extendedreferences_data[ $code ][ $key ] = $value;
                            }
                        }
                    }
                }
            } else {
                $extendedreferences_data = $this->parse_extendedreferences_csv( $files['extendedreferences'] );
            }
        } else {
            $this->logger->log( 'ATTENTION: Fichier ExtendedReferences manquant - les descriptions longues ne seront pas disponibles!' );
        }

        if ( ! empty( $files['prices'] ) ) {
            $prices_data = $this->parse_prices_csv( $files['prices'] );
        }

        if ( ! empty( $files['images'] ) ) {
            $images_data = $this->parse_images_csv( $files['images'] );
        }

        if ( ! empty( $files['inventory'] ) ) {
            $inventory_data = $this->parse_inventory_csv( $files['inventory'] );
        }

        if ( ! empty( $files['attributes'] ) ) {
            $attributes_data = $this->parse_attributes_csv( $files['attributes'] );
        }

        // Fusion par code produit
        $merged = array();

        // Références comme base principale
        foreach ( $references_data as $code => $row ) {
            if ( ! isset( $merged[ $code ] ) ) {
                $merged[ $code ] = array();
            }
            $merged[ $code ] = array_merge( $merged[ $code ], $row );
        }

        // ExtendedReferences : on fusionne en priorité pour écraser les descriptions de base
        foreach ( $extendedreferences_data as $code => $row ) {
            if ( ! isset( $merged[ $code ] ) ) {
                $merged[ $code ] = array( 'product_code' => $code );
            }
            
            // Fusionner les données SAUF le nom et la catégorie s'ils existent déjà
            foreach ( $row as $key => $value ) {
                // Ne pas écraser le nom s'il est déjà défini (priorité à References)
                if ( $key === 'name' && isset( $merged[ $code ]['name'] ) && ! empty( $merged[ $code ]['name'] ) ) {
                    continue;
                }
                // Ne pas écraser la catégorie si elle est déjà définie
                if ( $key === 'category' && isset( $merged[ $code ]['category'] ) && ! empty( $merged[ $code ]['category'] ) ) {
                    continue;
                }
                $merged[ $code ][ $key ] = $value;
            }
        }

        // On ajoute ce qui n’est pas encore présent
        foreach ( $prices_data as $code => $row ) {
            if ( ! isset( $merged[ $code ] ) ) {
                $merged[ $code ] = array( 'product_code' => $code );
            }
            $merged[ $code ] = array_merge( $merged[ $code ], $row );
        }

        foreach ( $images_data as $code => $row ) {
            if ( ! isset( $merged[ $code ] ) ) {
                $merged[ $code ] = array( 'product_code' => $code );
            }
            $merged[ $code ] = array_merge( $merged[ $code ], $row );
        }

        foreach ( $inventory_data as $code => $row ) {
            if ( ! isset( $merged[ $code ] ) ) {
                $merged[ $code ] = array( 'product_code' => $code );
            }
            $merged[ $code ] = array_merge( $merged[ $code ], $row );
        }

        foreach ( $attributes_data as $code => $row ) {
            if ( ! isset( $merged[ $code ] ) ) {
                $merged[ $code ] = array( 'product_code' => $code );
            }
            $merged[ $code ] = array_merge( $merged[ $code ], $row );
        }

        // Log d'un exemple de produit fusionné pour debug
        if ( ! empty( $merged ) ) {
            $first_merged = reset( $merged );
            $first_code = key( $merged );
            reset( $merged );
            $this->logger->log( 'Exemple de produit fusionné - Code: ' . $first_code . ', Name: ' . ( isset( $first_merged['name'] ) ? substr( $first_merged['name'], 0, 50 ) : 'NULL' ) . ', Stock: ' . ( isset( $first_merged['stock_level'] ) ? $first_merged['stock_level'] : 'NULL' ) . ', Description: ' . ( isset( $first_merged['description'] ) ? substr( $first_merged['description'], 0, 50 ) : 'NULL' ) );
        }

        // Log des statistiques de fusion
        $this->logger->log( 'Stats fusion - References: ' . count( $references_data ) . ', ExtendedReferences: ' . count( $extendedreferences_data ) . ', Prices: ' . count( $prices_data ) . ', Images: ' . count( $images_data ) . ', Inventory: ' . count( $inventory_data ) . ', Attributes: ' . count( $attributes_data ) );

        // Écriture dans la table wp_bihr_products
        $count = $this->save_merged_products( $merged );

        $this->logger->log( 'Fusion catalogues: terminé – ' . $count . ' produits fusionnés.' );

        return $count;
    }

    /**
     * Trouve le fichier CSV le plus récent contenant un mot clé
     */
    protected function find_latest_catalog_file( $dir, $keyword ) {
        $pattern = trailingslashit( $dir ) . '*' . $keyword . '*.csv';

        $files = glob( $pattern );
        if ( empty( $files ) ) {
            $this->logger->log( "Aucun fichier trouvé pour pattern: {$pattern}" );
            return '';
        }

        usort(
            $files,
            function( $a, $b ) {
                return filemtime( $b ) - filemtime( $a );
            }
        );

        $this->logger->log( "Fichier trouvé pour '{$keyword}': " . basename( $files[0] ) );
        return $files[0];
    }

    /**
     * Lit un CSV et retourne un tableau associatif (en minuscules pour les clés)
     */
    protected function read_csv_assoc( $file_path ) {
        $rows = array();

        if ( ! file_exists( $file_path ) ) {
            return $rows;
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return $rows;
        }

        // Détection du séparateur ; ou ,
        $first_line = fgets( $handle );
        rewind( $handle );
        $delimiter = ( substr_count( $first_line, ';' ) > substr_count( $first_line, ',' ) ) ? ';' : ',';

        $header = fgetcsv( $handle, 0, $delimiter );
        if ( ! $header ) {
            fclose( $handle );
            return $rows;
        }

        $header = array_map(
            function( $h ) {
                return strtolower( trim( $h ) );
            },
            $header
        );

        while ( ( $data = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            if ( count( $data ) !== count( $header ) ) {
                continue;
            }

            $row = array();
            foreach ( $header as $i => $key ) {
                $row[ $key ] = isset( $data[ $i ] ) ? $data[ $i ] : '';
            }

            $rows[] = $row;
        }

        fclose( $handle );

        return $rows;
    }

    /**
     * Essaie de récupérer le code produit à partir d’une ligne CSV
     * (ProductCode, ProductId, etc.)
     */
    protected function get_product_code_from_row( $row ) {
        if ( isset( $row['productcode'] ) && $row['productcode'] !== '' ) {
            return trim( $row['productcode'] );
        }
        if ( isset( $row['productid'] ) && $row['productid'] !== '' ) {
            return trim( $row['productid'] );
        }
        if ( isset( $row['code'] ) && $row['code'] !== '' ) {
            return trim( $row['code'] );
        }

        return '';
    }

    /* ======== PARSING DES DIFFÉRENTS CATALOGUES ======== */

    /**
     * Parsing du catalog References
     * Fichier : ProductCode, NewPartNumber, ShortDescription, FurtherDescription, ...
     */
    protected function parse_references_csv( $file_path ) {
        $this->logger->log( 'Parsing References CSV : ' . $file_path );

        $rows   = $this->read_csv_assoc( $file_path );
        $result = array();

        // Log des en-têtes pour debug
        if ( ! empty( $rows ) ) {
            $first_row_keys = array_keys( $rows[0] );
            $this->logger->log( 'En-têtes CSV References : ' . implode( ', ', $first_row_keys ) );
            
            // Log de la première ligne de données pour voir les valeurs
            $first_code = $this->get_product_code_from_row( $rows[0] );
            $first_long1 = isset( $rows[0]['longdescription1'] ) ? $rows[0]['longdescription1'] : 'N/A';
            $first_short = isset( $rows[0]['shortdescription'] ) ? $rows[0]['shortdescription'] : 'N/A';
            $this->logger->log( "Première ligne - Code: {$first_code}, LongDescription1: {$first_long1}, ShortDescription: {$first_short}" );
        }

        foreach ( $rows as $row ) {
            $code = $this->get_product_code_from_row( $row );
            if ( $code === '' ) {
                continue;
            }

            $new_part_number = isset( $row['newpartnumber'] ) ? trim( $row['newpartnumber'] ) : '';
            $name            = '';

            // Utiliser LongDescription1 pour le nom du produit
            if ( ! empty( $row['longdescription1'] ) ) {
                $name = trim( $row['longdescription1'] );
            } elseif ( ! empty( $row['shortdescription'] ) ) {
                $name = trim( $row['shortdescription'] );
            } elseif ( ! empty( $row['furtherdescription'] ) ) {
                $name = trim( $row['furtherdescription'] );
            }

            $description = '';
            if ( ! empty( $row['furtherdescription'] ) ) {
                $description = trim( $row['furtherdescription'] );
            }

            $result[ $code ] = array(
                'product_code'    => $code,
                'new_part_number' => $new_part_number ?: null,
                'name'            => $name ?: null,
                'description'     => $description ?: null,
            );
        }

        $this->logger->log( 'Parsing References: ' . count( $result ) . ' lignes.' );

        return $result;
    }

    /**
     * Mapping des codes de catégorie vers leurs noms complets
     */
    protected function get_category_mapping() {
        return array(
            'A' => 'RIDER GEAR',
            'B' => 'VEHICLE PARTS & ACCESSORIES',
            'C' => 'LIQUIDS & LUBRICANTS',
            'D' => 'TIRES & ACCESSORIES',
            'E' => 'TOOLING & WS',
            'G' => 'OTHER PRODUCTS & SERVICES',
        );
    }

    /**
     * Extrait le code de catégorie depuis le nom du fichier
     * Ex: cat-extref-full-FR01-FR001-fr-2025_12_04_01_15_01_A.csv → A
     */
    protected function extract_category_from_filename( $file_path ) {
        $basename = basename( $file_path );
        
        // Pattern: cat-extref-full-..._X.csv où X est A, B, C, D, E ou G
        if ( preg_match( '/_([A-G])\.csv$/i', $basename, $matches ) ) {
            $code = strtoupper( $matches[1] );
            $mapping = $this->get_category_mapping();
            
            if ( isset( $mapping[ $code ] ) ) {
                return $mapping[ $code ];
            }
        }
        
        return null;
    }

    /**
     * Parsing du catalog ExtendedReferences
     * Fichier : ProductCode, Description, LongDescription, TechnicalDescription, FurtherDescription
     * Catégorie extraite depuis le nom du fichier (ex: *_A.csv = RIDER GEAR)
     */
    protected function parse_extendedreferences_csv( $file_path ) {
        $this->logger->log( 'Parsing ExtendedReferences CSV : ' . $file_path );

        $rows   = $this->read_csv_assoc( $file_path );
        $result = array();

        // Log des en-têtes pour debug
        if ( ! empty( $rows ) ) {
            $first_row_keys = array_keys( $rows[0] );
            $this->logger->log( 'En-têtes CSV ExtendedReferences : ' . implode( ', ', $first_row_keys ) );
            
            // Log de la première ligne
            $first_code = $this->get_product_code_from_row( $rows[0] );
            $first_long1 = isset( $rows[0]['longdescription1'] ) ? substr( $rows[0]['longdescription1'], 0, 50 ) : 'N/A';
            $first_long = isset( $rows[0]['longdescription'] ) ? substr( $rows[0]['longdescription'], 0, 50 ) : 'N/A';
            $this->logger->log( "Première ligne ExtendedRef - Code: {$first_code}, LongDescription1: {$first_long1}, LongDescription: {$first_long}" );
        }

        foreach ( $rows as $row ) {
            $code = $this->get_product_code_from_row( $row );
            if ( $code === '' ) {
                continue;
            }

            // Tentative de récupération de la description la plus complète
            $description = '';
            
            // Priorité : LongDescription > TechnicalDescription > Description
            if ( ! empty( $row['longdescription'] ) ) {
                $description = trim( $row['longdescription'] );
            } elseif ( ! empty( $row['technicaldescription'] ) ) {
                $description = trim( $row['technicaldescription'] );
            } elseif ( ! empty( $row['description'] ) ) {
                $description = trim( $row['description'] );
            }

            // Nom : utiliser LongDescription1 en priorité (pour le nom du produit WooCommerce)
            $name = '';
            if ( ! empty( $row['longdescription1'] ) ) {
                $name = trim( $row['longdescription1'] );
            } elseif ( ! empty( $row['furtherdescription'] ) ) {
                $name = trim( $row['furtherdescription'] );
            } elseif ( ! empty( $row['shortdescription'] ) ) {
                $name = trim( $row['shortdescription'] );
            } elseif ( ! empty( $row['name'] ) ) {
                $name = trim( $row['name'] );
            }

            // Extraction de la catégorie depuis le nom du fichier
            $category = $this->extract_category_from_filename( $file_path );

            $result[ $code ] = array(
                'description' => $description ?: null,
                'category'    => $category,
            );

            // On écrase toujours le nom avec celui d'ExtendedReferences (priorité absolue)
            if ( $name ) {
                $result[ $code ]['name'] = $name;
            }
        }

        $this->logger->log( 'Parsing ExtendedReferences: ' . count( $result ) . ' lignes.' );

        return $result;
    }

    /**
     * Parsing du catalog Prices
     * Fichier : ProductCode, DealerPrice, ...
     */
    protected function parse_prices_csv( $file_path ) {
        $this->logger->log( 'Parsing Prices CSV : ' . $file_path );

        $rows   = $this->read_csv_assoc( $file_path );
        $result = array();

        foreach ( $rows as $row ) {
            $code = $this->get_product_code_from_row( $row );
            if ( $code === '' ) {
                continue;
            }

            // Colonne DealerPrice, parfois DealerPriceHT, etc.
            $price = null;
            if ( isset( $row['dealerprice'] ) && $row['dealerprice'] !== '' ) {
                $price = (float) str_replace( ',', '.', $row['dealerprice'] );
            } elseif ( isset( $row['dealerpriceht'] ) && $row['dealerpriceht'] !== '' ) {
                $price = (float) str_replace( ',', '.', $row['dealerpriceht'] );
            }

            if ( $price === null ) {
                continue;
            }

            $result[ $code ] = array(
                'dealer_price_ht' => $price,
            );
        }

        $this->logger->log( 'Parsing Prices: ' . count( $result ) . ' lignes.' );

        return $result;
    }

    /**
     * Parsing du catalog Images
     * On récupère simplement le chemin (colonne Url) sans préfixe.
     * Le préfixe https://api.mybihr.com sera ajouté plus tard.
     * Fichier : ProductCode, Url, IsDefault, NewPartNumber
     */
    protected function parse_images_csv( $file_path ) {
        $this->logger->log( 'Parsing Images CSV : ' . $file_path );

        $rows   = $this->read_csv_assoc( $file_path );
        $result = array();

        foreach ( $rows as $row ) {
            $code = $this->get_product_code_from_row( $row );
            if ( $code === '' ) {
                continue;
            }

            // Colonne URL du CSV (en minuscules -> 'url')
            $url_path = isset( $row['url'] ) ? trim( $row['url'] ) : '';
            if ( $url_path === '' ) {
                continue;
            }

            // Colonne IsDefault (facultative) :
            // On ne filtre pas sur ce champ pour l'instant,
            // on prendra simplement la première image trouvée pour chaque produit.

            // Si une image existe déjà pour ce code, on ne la remplace pas
            if ( isset( $result[ $code ] ) ) {
                continue;
            }

            $result[ $code ] = array(
                'image_url' => $url_path, // chemin brut, sans préfixe
            );
        }

        $this->logger->log( 'Parsing Images: ' . count( $result ) . ' lignes.' );

        return $result;
    }

    /**
     * Parsing du catalog Inventory (Stock)
     * Fichier : ProductId, StockLevel, StockLevelDescription, NewPartNumber
     */
    protected function parse_inventory_csv( $file_path ) {
        $this->logger->log( 'Parsing Inventory CSV : ' . $file_path );

        $rows   = $this->read_csv_assoc( $file_path );
        $result = array();

        // Log des en-têtes pour debug
        if ( ! empty( $rows ) ) {
            $first_row_keys = array_keys( $rows[0] );
            $this->logger->log( 'En-têtes CSV Inventory : ' . implode( ', ', $first_row_keys ) );
            
            // Log de la première ligne
            $first_code = $this->get_product_code_from_row( $rows[0] );
            $first_stock = isset( $rows[0]['stocklevel'] ) ? $rows[0]['stocklevel'] : 'N/A';
            $this->logger->log( "Première ligne Inventory - Code: {$first_code}, StockLevel: {$first_stock}" );
        }

        foreach ( $rows as $row ) {
            $code = $this->get_product_code_from_row( $row );
            if ( $code === '' ) {
                continue;
            }

            $stock_level       = isset( $row['stocklevel'] ) ? (int) $row['stocklevel'] : null;
            $stock_description = isset( $row['stockleveldescription'] ) ? trim( $row['stockleveldescription'] ) : '';

            $result[ $code ] = array(
                'stock_level'       => $stock_level,
                'stock_description' => $stock_description ?: null,
            );
        }

        $this->logger->log( 'Parsing Inventory: ' . count( $result ) . ' lignes.' );

        return $result;
    }

    /**
     * Parsing du catalog Attributes (optionnel)
     * Ici, on se contente de concaténer les attributs dans la description.
     */
    protected function parse_attributes_csv( $file_path ) {
        $this->logger->log( 'Parsing Attributes CSV : ' . $file_path );

        $rows   = $this->read_csv_assoc( $file_path );
        $result = array();

        foreach ( $rows as $row ) {
            $code = $this->get_product_code_from_row( $row );
            if ( $code === '' ) {
                continue;
            }

            // On concatène grossièrement tous les champs (sauf le code) en texte
            $parts = array();
            foreach ( $row as $key => $value ) {
                if ( in_array( $key, array( 'productcode', 'productid', 'code' ), true ) ) {
                    continue;
                }
                if ( $value === '' ) {
                    continue;
                }
                $parts[] = $key . '=' . $value;
            }

            if ( empty( $parts ) ) {
                continue;
            }

            $attr_text = 'Attributs Bihr : ' . implode( ' | ', $parts );

            $result[ $code ] = array(
                'attributes_text' => $attr_text,
            );
        }

        $this->logger->log( 'Parsing Attributes: ' . count( $result ) . ' lignes.' );

        return $result;
    }

    /**
     * Enregistre la fusion finale dans la table wp_bihr_products
     */
    protected function save_merged_products( $merged ) {
        global $wpdb;

        $count = 0;

        foreach ( $merged as $code => $data ) {
            if ( empty( $code ) ) {
                continue;
            }

            // Description + ajout éventuel des attributs
            $description = '';
            if ( isset( $data['description'] ) && $data['description'] !== null ) {
                $description = (string) $data['description'];
            }

            if ( isset( $data['attributes_text'] ) && $data['attributes_text'] !== null ) {
                $description .= "\n\n" . $data['attributes_text'];
            }

            // Vérifier si le produit existe déjà
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE product_code = %s",
                    $code
                ),
                ARRAY_A
            );

            // Construction des champs à enregistrer (seulement les champs présents dans $data)
            $fields = array( 'product_code' => $code );
            $formats = array( '%s' );

            // Ne mettre à jour que les champs qui sont présents dans $data
            if ( isset( $data['new_part_number'] ) ) {
                $fields['new_part_number'] = $data['new_part_number'];
                $formats[] = '%s';
            }

            if ( isset( $data['name'] ) ) {
                $fields['name'] = $data['name'];
                $formats[] = '%s';
            }

            if ( $description !== '' ) {
                $fields['description'] = $description;
                $formats[] = '%s';
            }

            if ( isset( $data['image_url'] ) ) {
                $fields['image_url'] = $data['image_url'];
                $formats[] = '%s';
            }

            if ( isset( $data['dealer_price_ht'] ) ) {
                $fields['dealer_price_ht'] = $data['dealer_price_ht'];
                $formats[] = '%f';
            }

            if ( isset( $data['stock_level'] ) ) {
                $fields['stock_level'] = $data['stock_level'];
                $formats[] = '%d';
            }

            if ( isset( $data['stock_description'] ) ) {
                $fields['stock_description'] = $data['stock_description'];
                $formats[] = '%s';
            }

            if ( isset( $data['category'] ) ) {
                $fields['category'] = $data['category'];
                $formats[] = '%s';
            }

            if ( $existing ) {
                // UPDATE : ne mettre à jour que les champs fournis
                $where = array( 'product_code' => $code );
                $where_format = array( '%s' );
                
                // Retirer product_code des champs à mettre à jour
                unset( $fields['product_code'] );
                array_shift( $formats );
                
                if ( ! empty( $fields ) ) {
                    $wpdb->update( $this->table_name, $fields, $where, $formats, $where_format );
                }
            } else {
                // INSERT : nouveau produit
                $wpdb->insert( $this->table_name, $fields, $formats );
            }
            
            $count++;
        }

        return $count;
    }

    /**
     * Extrait un fichier ZIP vers le dossier d'import
     * Retourne le nombre de fichiers CSV extraits
     */
    public function extract_zip_to_import_dir( $zip_file ) {
        $import_dir = WP_CONTENT_DIR . '/uploads/bihr-import/';
        
        if ( ! is_dir( $import_dir ) ) {
            wp_mkdir_p( $import_dir );
        }

        if ( ! file_exists( $zip_file ) ) {
            $this->logger->log( "Fichier ZIP introuvable: {$zip_file}" );
            return 0;
        }

        // Utilise la classe WP_Filesystem
        WP_Filesystem();
        global $wp_filesystem;

        $unzipped = unzip_file( $zip_file, $import_dir );

        if ( is_wp_error( $unzipped ) ) {
            $this->logger->log( 'Erreur extraction ZIP: ' . $unzipped->get_error_message() );
            return 0;
        }

        // Compte les fichiers CSV extraits
        $csv_files = glob( $import_dir . '*.csv' );
        $count     = count( $csv_files );

        // Log des noms de fichiers extraits pour debug
        if ( $count > 0 && $count <= 15 ) {
            $file_names = array_map( 'basename', $csv_files );
            $this->logger->log( "Fichiers CSV extraits: " . implode( ', ', $file_names ) );
        }

        $this->logger->log( "Extraction ZIP réussie: {$count} fichiers CSV dans {$import_dir}" );

        // Supprime le fichier ZIP après extraction
        @unlink( $zip_file );

        return $count;
    }
}

