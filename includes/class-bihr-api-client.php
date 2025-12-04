<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BihrWI_API_Client {

    protected $logger;
    protected $base_url = 'https://api.bihr.net/api/v2.1';

    public function __construct( BihrWI_Logger $logger ) {
        $this->logger = $logger;
    }

    /**
     * Récupère les identifiants stockés dans les options WP
     */
    protected function get_credentials() {
        $username = get_option( 'bihrwi_username', '' );
        $password = get_option( 'bihrwi_password', '' );

        return array(
            'username' => $username,
            'password' => $password,
        );
    }

    /**
     * Récupère un token valide, sinon en demande un nouveau.
     * L'API Bihr renvoie un JSON avec une clé "access_token".
     */
    public function get_token() {
        // On essaie d'abord de réutiliser un token déjà en cache
        $cached = get_transient( 'bihrwi_api_token' );
        if ( ! empty( $cached ) ) {
            return $cached;
        }

        $creds = $this->get_credentials();
        if ( empty( $creds['username'] ) || empty( $creds['password'] ) ) {
            throw new Exception( 'Identifiants Bihr non configurés.' );
        }

        $this->logger->log( 'Auth: demande d’un nouveau token.' );

        // D'après la doc : POST /Authentication/Token avec UserName & PassWord
        $response = wp_remote_post(
            $this->base_url . '/Authentication/Token',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'text/json',
                ),
                'body'    => array(
                    'UserName' => $creds['username'],
                    'PassWord' => $creds['password'],
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'Auth: erreur HTTP : ' . $response->get_error_message() );
            throw new Exception( 'Erreur HTTP lors de la récupération du token.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        $this->logger->log( 'Auth: code ' . $code . ' – réponse : ' . $body );

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( 'Erreur API Bihr lors de la récupération du token.' );
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
            throw new Exception( 'Réponse de token invalide (pas de champ access_token).' );
        }

        $token = $data['access_token'];

        // Token valable 30 min -> on garde 25 min
        set_transient( 'bihrwi_api_token', $token, 25 * MINUTE_IN_SECONDS );

        return $token;
    }

    /**
     * Lance la génération d’un catalog (References, Prices, Images, Attributes, Stocks, etc.)
     * Exemple type: 'Prices/Full' -> Catalog/LZMA/CSV/Prices/Full
     */
    public function start_catalog_generation( $catalog_path ) {
        $token = $this->get_token();

        $url = $this->base_url . '/Catalog/LZMA/CSV/' . ltrim( $catalog_path, '/' );
        $this->logger->log( 'Catalog: démarrage génération -> ' . $url );

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Accept'        => 'application/json',
                    // IMPORTANT : Bihr attend "Authorization: Bearer <token>"
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'text/json',
                ),
                'body'    => null, // équivalent d'un body vide (Content-Length: 0)
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'Catalog start: erreur HTTP : ' . $response->get_error_message() );
            throw new Exception( 'Erreur HTTP lors du démarrage du catalog.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        $this->logger->log( 'Catalog start: code ' . $code . ' – réponse : ' . $body );

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( 'Erreur API Bihr lors du démarrage du catalog : ' . $body );
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || ( empty( $data['TicketId'] ) && empty( $data['ticketId'] ) ) ) {
            throw new Exception( 'Réponse sans ticketId pour le catalog.' );
        }

        return ! empty( $data['ticketId'] ) ? $data['ticketId'] : $data['TicketId'];
    }

    /**
     * Vérifie le status de génération d’un catalog
     * Appelle GET /Catalog/GenerationStatus?ticketId=...
     */
    public function get_catalog_status( $ticket_id ) {
        $token = $this->get_token();

        $url = $this->base_url . '/Catalog/GenerationStatus?ticketId=' . urlencode( $ticket_id );
        $this->logger->log( 'Catalog status: GET ' . $url );

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'Catalog status: erreur HTTP : ' . $response->get_error_message() );
            throw new Exception( 'Erreur HTTP lors de la récupération du status.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        $this->logger->log( 'Catalog status: code ' . $code . ' – réponse : ' . $body );

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( 'Erreur API Bihr lors de la récupération du status : ' . $body );
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            throw new Exception( 'Réponse status invalide (pas JSON).' );
        }

        // Compat : certains exemples parlent de "requestStatus"
        if ( isset( $data['requestStatus'] ) && ! isset( $data['status'] ) ) {
            $data['status'] = $data['requestStatus'];
        }

        return $data;
    }

    /**
     * Télécharge un catalog généré
     * Appelle GET /Catalog/GeneratedFile?downloadId=...
     * Sauvegarde le contenu dans un fichier .zip dans le dossier des logs.
     */
    public function download_catalog_file( $download_id, $prefix = 'catalog' ) {
        $token = $this->get_token();

        $url = $this->base_url . '/Catalog/GeneratedFile?downloadId=' . urlencode( $download_id );
        $this->logger->log( 'Catalog download: GET ' . $url );

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 120,
                'headers' => array(
                    'Accept'        => '*/*',
                    'Authorization' => 'Bearer ' . $token,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'Catalog download: erreur HTTP : ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            $this->logger->log(
                'Catalog download: code HTTP ' . $code . ' – body: ' . $body
            );
            return false;
        }

        $upload_dir = dirname( BIHRWI_LOG_FILE );
        if ( ! file_exists( $upload_dir ) ) {
            wp_mkdir_p( $upload_dir );
        }

        $filename = $prefix . '-' . date( 'Ymd-His' ) . '.zip';
        $filepath = trailingslashit( $upload_dir ) . $filename;

        file_put_contents( $filepath, $body );

        return $filepath;
    }
}
