<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Page d'administration pour la compatibilité véhicule
 */

$logger        = new BihrWI_Logger();
$compatibility = new BihrWI_Vehicle_Compatibility( $logger );

// Statistiques
$stats = $compatibility->get_statistics();

?>

<div class="wrap">
    <h1>📋 Compatibilité Véhicule-Produit</h1>

    <?php
    // Notifications
    if ( isset( $_GET['tables_created'] ) ) : ?>
        <div class="notice notice-success"><p>
            ✅ <strong>Tables créées avec succès !</strong>
        </p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['vehicles_imported'] ) ) : ?>
        <div class="notice notice-success"><p>
            ✅ <strong><?php echo intval( $_GET['vehicles_imported'] ); ?> véhicules</strong> importés avec succès !
        </p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['compatibility_imported'] ) ) : ?>
        <div class="notice notice-success"><p>
            ✅ <strong><?php echo intval( $_GET['compatibility_imported'] ); ?> compatibilités</strong> importées avec succès !
            (Marque: <?php echo esc_html( $_GET['brand'] ?? 'N/A' ); ?>)
        </p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['error'] ) ) : ?>
        <div class="notice notice-error"><p>
            ❌ Erreur: <?php echo esc_html( urldecode( $_GET['error'] ) ); ?>
        </p></div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="bihr-section" style="margin-top: 20px;">
        <h2>📊 Statistiques</h2>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div style="background: #f0f6fc; padding: 20px; border-radius: 8px; border-left: 4px solid #0073aa;">
                <h3 style="margin: 0 0 10px 0; color: #0073aa;">🏍️ Véhicules</h3>
                <div style="font-size: 32px; font-weight: bold; color: #0073aa;">
                    <?php echo number_format( $stats['total_vehicles'] ?? 0 ); ?>
                </div>
                <div style="color: #666; font-size: 14px;">véhicules dans la base</div>
            </div>

            <div style="background: #f0fdf4; padding: 20px; border-radius: 8px; border-left: 4px solid #16a34a;">
                <h3 style="margin: 0 0 10px 0; color: #16a34a;">🔗 Compatibilités</h3>
                <div style="font-size: 32px; font-weight: bold; color: #16a34a;">
                    <?php echo number_format( $stats['total_compatibilities'] ?? 0 ); ?>
                </div>
                <div style="color: #666; font-size: 14px;">associations véhicule-produit</div>
            </div>

            <div style="background: #fef3c7; padding: 20px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                <h3 style="margin: 0 0 10px 0; color: #f59e0b;">📦 Produits</h3>
                <div style="font-size: 32px; font-weight: bold; color: #f59e0b;">
                    <?php echo number_format( $stats['products_with_compatibility'] ?? 0 ); ?>
                </div>
                <div style="color: #666; font-size: 14px;">produits avec compatibilité</div>
            </div>
        </div>

        <?php if ( ! empty( $stats['source_brands'] ) ) : ?>
            <h3>🏷️ Marques sources</h3>
            <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th>Marque</th>
                        <th style="text-align: right;">Compatibilités</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $stats['source_brands'] as $brand ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $brand['source_brand'] ); ?></strong></td>
                            <td style="text-align: right;"><?php echo number_format( $brand['count'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Import de la liste des véhicules -->
    <div class="bihr-section" style="margin-top: 30px;">
        <h2>1️⃣ Importer la liste des véhicules</h2>
        <p>
            Importez le fichier <code>VehiclesList.csv</code> pour charger tous les véhicules disponibles.
            <br><strong>⚠️ Cette opération remplace toutes les données existantes de véhicules.</strong>
        </p>
        
        <div style="margin-bottom: 15px;">
            <button type="button" class="button button-secondary" id="btn-create-tables" style="margin-right: 10px;">
                🔧 Créer/Recréer les tables
            </button>
            <span id="create-tables-status" style="color: #666;"></span>
        </div>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'bihrwi_import_vehicles_action', 'bihrwi_import_vehicles_nonce' ); ?>
            <input type="hidden" name="action" value="bihrwi_import_vehicles" />
            
            <p>
                <label for="vehicles_file">
                    <strong>Fichier VehiclesList.csv :</strong><br>
                    <span style="color: #666;">
                        Le fichier doit être dans le dossier <code>/wp-content/uploads/bihr-import/</code>
                    </span>
                </label><br>
                <input type="text" 
                       id="vehicles_file" 
                       name="vehicles_file" 
                       value="VehiclesList.csv" 
                       class="regular-text" 
                       readonly />
            </p>

            <?php submit_button( '📥 Importer les véhicules', 'primary large', 'submit', false ); ?>
        </form>
    </div>

    <!-- Import des compatibilités par marque -->
    <div class="bihr-section" style="margin-top: 30px;">
        <h2>2️⃣ Importer les compatibilités par marque</h2>
        <p>
            Importez les fichiers de compatibilité pour chaque marque.
            <br><strong>💡 Conseil :</strong> Importez d'abord la liste des véhicules (étape 1).
        </p>

        <?php
        $brands = array(
            'SHIN YO'   => '[SHIN YO].csv',
            'TECNIUM'   => '[TECNIUM].csv',
            'V BIKE'    => '[V BIKE].csv',
            'V PARTS'   => '[V PARTS].csv',
            'VECTOR'    => '[VECTOR].csv',
            'VICMA'     => '[VICMA].csv',
        );
        ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            <?php foreach ( $brands as $brand_name => $file_name ) : ?>
                <div style="border: 1px solid #ddd; padding: 20px; border-radius: 8px; background: #fff;">
                    <h3 style="margin-top: 0;">🏷️ <?php echo esc_html( $brand_name ); ?></h3>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0;">
                        <?php wp_nonce_field( 'bihrwi_import_compatibility_action', 'bihrwi_import_compatibility_nonce' ); ?>
                        <input type="hidden" name="action" value="bihrwi_import_compatibility" />
                        <input type="hidden" name="brand_name" value="<?php echo esc_attr( $brand_name ); ?>" />
                        <input type="hidden" name="file_name" value="<?php echo esc_attr( $file_name ); ?>" />
                        
                        <p style="margin: 10px 0; color: #666; font-size: 13px;">
                            📄 <code><?php echo esc_html( $file_name ); ?></code>
                        </p>

                        <?php submit_button( '📥 Importer ' . $brand_name, 'secondary', 'submit', false ); ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Import groupé -->
    <div class="bihr-section" style="margin-top: 30px;">
        <h2>3️⃣ Import groupé (toutes les marques)</h2>
        <p>
            Importez automatiquement les compatibilités de toutes les marques en une seule opération.
            <br><strong>⚠️ Cette opération peut prendre plusieurs minutes.</strong>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'bihrwi_import_all_compatibility_action', 'bihrwi_import_all_compatibility_nonce' ); ?>
            <input type="hidden" name="action" value="bihrwi_import_all_compatibility" />
            
            <?php submit_button( '🚀 Importer toutes les marques', 'primary large', 'submit', false ); ?>
        </form>
    </div>

    <!-- Informations -->
    <div class="bihr-section" style="margin-top: 30px; background: #f8f9fa; border-left: 4px solid #6c757d;">
        <h2>ℹ️ Informations</h2>
        <ul style="line-height: 1.8;">
            <li>📁 <strong>Emplacement des fichiers :</strong> <code>/wp-content/uploads/bihr-import/</code></li>
            <li>📊 <strong>Format :</strong> Fichiers CSV avec séparateur virgule</li>
            <li>🔄 <strong>Mise à jour :</strong> Réimportez les fichiers pour mettre à jour les données</li>
            <li>🏍️ <strong>VehiclesList.csv :</strong> Liste complète des véhicules (fabricants, modèles, années)</li>
            <li>🔗 <strong>Fichiers [MARQUE].csv :</strong> Associations véhicule-produit par marque</li>
        </ul>
    </div>
</div>

<style>
.bihr-section {
    background: white;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bihr-section h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #0073aa;
}

.bihr-section h3 {
    color: #23282d;
    margin-top: 20px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Bouton création des tables
    $('#btn-create-tables').on('click', function() {
        var btn = $(this);
        var status = $('#create-tables-status');
        
        btn.prop('disabled', true).text('⏳ Création en cours...');
        status.text('');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bihrwi_create_compatibility_tables',
                nonce: '<?php echo wp_create_nonce( 'bihrwi_ajax_nonce' ); ?>'
            },
            success: function(response) {
                if (response.success) {
                    status.html('<span style="color: #16a34a;">✅ ' + response.data.message + '</span>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    status.html('<span style="color: #dc2626;">❌ ' + response.data.message + '</span>');
                }
            },
            error: function() {
                status.html('<span style="color: #dc2626;">❌ Erreur de connexion</span>');
            },
            complete: function() {
                btn.prop('disabled', false).text('🔧 Créer/Recréer les tables');
            }
        });
    });
});
</script>
