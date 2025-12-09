<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BihrWI_AI_Enrichment {

    protected $logger;
    protected $api_key;

    public function __construct( BihrWI_Logger $logger ) {
        $this->logger  = $logger;
        $this->api_key = get_option( 'bihrwi_openai_key', '' );
    }

    /**
     * Vérifie si l'enrichissement IA est disponible
     */
    public function is_enabled() {
        return ! empty( $this->api_key );
    }

    /**
     * Génère les descriptions courte et longue via OpenAI GPT-4 Vision
     * @param string $product_name Nom du produit
     * @param string $image_url URL de l'image du produit
     * @param string $product_code Code produit (pour référence)
     * @return array ['short_description' => string, 'long_description' => string] ou false en cas d'erreur
     */
    public function generate_descriptions( $product_name, $image_url = '', $product_code = '' ) {
        if ( ! $this->is_enabled() ) {
            $this->logger->log( 'AI Enrichment: désactivé (pas de clé OpenAI)' );
            return false;
        }

        $this->logger->log( "AI Enrichment: génération pour {$product_code} - {$product_name}" );

        try {
            // Construction du prompt
            $prompt = $this->build_prompt( $product_name, $product_code );

            // Appel à l'API OpenAI
            $messages = array(
                array(
                    'role'    => 'system',
                    'content' => 'Tu es un expert en rédaction de fiches produits pour un site e-commerce spécialisé dans les pièces et accessoires moto. Tes descriptions sont claires, professionnelles et optimisées pour le SEO.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            );

            // Si une image est disponible et que c'est une URL valide, on utilise GPT-4 Vision
            if ( ! empty( $image_url ) && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
                $messages[1]['content'] = array(
                    array(
                        'type' => 'text',
                        'text' => $prompt,
                    ),
                    array(
                        'type'      => 'image_url',
                        'image_url' => array(
                            'url' => $image_url,
                        ),
                    ),
                );
                $model = 'gpt-4o'; // Modèle avec vision
            } else {
                $model = 'gpt-4o-mini'; // Modèle standard sans vision
            }

            $response = $this->call_openai_api( $messages, $model );

            if ( $response === false ) {
                return false;
            }

            // Parse la réponse
            $parsed = $this->parse_response( $response );

            if ( $parsed ) {
                $this->logger->log( "AI Enrichment: succès pour {$product_code}" );
                return $parsed;
            }

            return false;

        } catch ( Exception $e ) {
            $this->logger->log( 'AI Enrichment erreur: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Construit le prompt pour OpenAI
     */
    protected function build_prompt( $product_name, $product_code ) {
        $prompt = "Génère deux descriptions pour ce produit moto :\n\n";
        $prompt .= "**Produit :** {$product_name}\n";
        
        if ( ! empty( $product_code ) ) {
            $prompt .= "**Référence :** {$product_code}\n";
        }

        $prompt .= "\n**Instructions :**\n";
        $prompt .= "1. **Description courte** (2-3 phrases, max 160 caractères) : Résumé accrocheur pour la liste de produits.\n";
        $prompt .= "2. **Description longue** (4-6 paragraphes) : Détails techniques, avantages, compatibilité, utilisation.\n\n";
        $prompt .= "**Format de réponse (strict) :**\n";
        $prompt .= "[SHORT]\n";
        $prompt .= "[Contenu de la description courte]\n";
        $prompt .= "[/SHORT]\n\n";
        $prompt .= "[LONG]\n";
        $prompt .= "[Contenu de la description longue]\n";
        $prompt .= "[/LONG]\n";

        return $prompt;
    }

    /**
     * Appelle l'API OpenAI
     */
    protected function call_openai_api( $messages, $model = 'gpt-4o-mini' ) {
        $endpoint = 'https://api.openai.com/v1/chat/completions';

        $body = array(
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => 1500,
            'temperature' => 0.7,
        );

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'OpenAI API erreur: ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        if ( $status_code !== 200 ) {
            $this->logger->log( "OpenAI API erreur HTTP {$status_code}: {$body}" );
            return false;
        }

        $data = json_decode( $body, true );

        if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
            $this->logger->log( 'OpenAI API réponse invalide: ' . $body );
            return false;
        }

        return $data['choices'][0]['message']['content'];
    }

    /**
     * Parse la réponse d'OpenAI pour extraire les descriptions
     */
    protected function parse_response( $response ) {
        $short_description = '';
        $long_description  = '';

        // Extraction de la description courte
        if ( preg_match( '/\[SHORT\](.*?)\[\/SHORT\]/s', $response, $short_matches ) ) {
            $short_description = trim( $short_matches[1] );
        }

        // Extraction de la description longue
        if ( preg_match( '/\[LONG\](.*?)\[\/LONG\]/s', $response, $long_matches ) ) {
            $long_description = trim( $long_matches[1] );
        }

        // Si les balises ne sont pas trouvées, on essaie de splitter par ligne vide
        if ( empty( $short_description ) || empty( $long_description ) ) {
            $parts = preg_split( '/\n\s*\n/', trim( $response ), 2 );
            if ( count( $parts ) === 2 ) {
                $short_description = trim( $parts[0] );
                $long_description  = trim( $parts[1] );
            } else {
                // Fallback: tout dans la description longue
                $long_description = trim( $response );
                $short_description = wp_trim_words( $long_description, 25, '...' );
            }
        }

        if ( empty( $short_description ) && empty( $long_description ) ) {
            return false;
        }

        return array(
            'short_description' => $short_description,
            'long_description'  => $long_description,
        );
    }
}
