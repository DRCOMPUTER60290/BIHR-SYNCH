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
        
        <div style="margin-bottom: 15px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <button type="button" class="button button-secondary" id="btn-create-tables">
                🔧 Créer/Recréer les tables
            </button>
            <button type="button" class="button" id="btn-clear-compatibility">
                🗑️ Effacer toutes les données
            </button>
            <span id="create-tables-status" style="color: #666;"></span>
        </div>

        <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center;">
            <button type="button" class="button button-primary button-large" id="btn-import-vehicles">📥 Importer les véhicules</button>
            <div style="flex:1; min-width:250px;">
                <div id="vehicles-progress" style="background:#eef2ff; height:16px; border-radius:8px; overflow:hidden; border:1px solid #cbd5e1;">
                    <div id="vehicles-progress-bar" style="height:100%; width:0%; background:#2563eb;"></div>
                </div>
                <div id="vehicles-progress-text" style="font-size:12px; color:#555; margin-top:4px;"></div>
            </div>
        </div>

        <div style="margin-top:15px; display:flex; gap:20px; flex-wrap:wrap; align-items:center;">
            <div>
                <label><strong>Uploader VehiclesList.zip</strong></label><br>
                <input type="file" id="vehicles-zip" accept=".zip" />
                <button type="button" class="button" id="btn-upload-vehicles-zip" style="margin-top:6px;">⬆️ Envoyer & décompresser</button>
                <div id="vehicles-zip-status" style="font-size:12px; color:#555; margin-top:4px;"></div>
            </div>
        </div>
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
                    <p style="margin: 10px 0; color: #666; font-size: 13px;">
                        📄 <code><?php echo esc_html( $file_name ); ?></code>
                    </p>
                    <button type="button" class="button brand-import-btn" data-brand="<?php echo esc_attr( $brand_name ); ?>">
                        📥 Importer <?php echo esc_html( $brand_name ); ?>
                    </button>
                    <div class="brand-status" data-brand-status="<?php echo esc_attr( $brand_name ); ?>" style="font-size:12px; color:#555; margin-top:6px;"></div>
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

        <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center;">
            <button type="button" class="button button-primary button-large" id="btn-import-all-brands">🚀 Importer toutes les marques</button>
            <div style="flex:1; min-width:250px;">
                <div id="all-brands-progress" style="background:#eef2ff; height:16px; border-radius:8px; overflow:hidden; border:1px solid #cbd5e1;">
                    <div id="all-brands-progress-bar" style="height:100%; width:0%; background:#16a34a;"></div>
                </div>
                <div id="all-brands-progress-text" style="font-size:12px; color:#555; margin-top:4px;"></div>
            </div>
        </div>
        <div id="all-brands-log" style="margin-top:10px; font-size:12px; color:#333; max-height:200px; overflow:auto; background:#f8fafc; border:1px solid #e2e8f0; padding:10px; border-radius:6px;"></div>

        <div style="margin-top:15px; display:flex; gap:20px; flex-wrap:wrap; align-items:center;">
            <div>
                <label><strong>Uploader LinksList.zip (tous les CSV)</strong></label><br>
                <input type="file" id="links-zip" accept=".zip" />
                <button type="button" class="button" id="btn-upload-links-zip" style="margin-top:6px;">⬆️ Envoyer & décompresser</button>
                <div id="links-zip-status" style="font-size:12px; color:#555; margin-top:4px;"></div>
            </div>
        </div>
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
jQuery(function($) {
    const nonce = '<?php echo wp_create_nonce( 'bihrwi_ajax_nonce' ); ?>';
    const ajaxUrl = ajaxurl;
    const brands = <?php echo wp_json_encode( array_keys( $brands ) ); ?>;

    function setProgress(bar, textEl, pct, label) {
        bar.css('width', pct + '%');
        if (textEl) {
            textEl.text(label || pct + '%');
        }
    }

    // Créer/Recréer tables
    $('#btn-create-tables').on('click', function() {
        const btn = $(this);
        const status = $('#create-tables-status');
        btn.prop('disabled', true).text('⏳ Création en cours...');
        status.text('');
        $.post(ajaxUrl, { action: 'bihrwi_create_compatibility_tables', nonce }, function(resp) {
            if (resp.success) {
                status.html('<span style="color:#16a34a;">✅ ' + resp.data.message + '</span>');
                setTimeout(() => location.reload(), 1200);
            } else {
                status.html('<span style="color:#dc2626;">❌ ' + resp.data.message + '</span>');
            }
        }).fail(() => status.html('<span style="color:#dc2626;">❌ Erreur de connexion</span>'))
        .always(() => btn.prop('disabled', false).text('🔧 Créer/Recréer les tables'));
    });

    // Effacer données
    $('#btn-clear-compatibility').on('click', function() {
        if (!confirm('Confirmer la purge des données de compatibilité ?')) return;
        const btn = $(this);
        const status = $('#create-tables-status');
        btn.prop('disabled', true).text('⏳ Suppression...');
        $.post(ajaxUrl, { action: 'bihrwi_clear_compatibility', nonce }, function(resp) {
            if (resp.success) {
                status.html('<span style="color:#16a34a;">✅ ' + resp.data.message + '</span>');
                setTimeout(() => location.reload(), 1200);
            } else {
                status.html('<span style="color:#dc2626;">❌ ' + resp.data.message + '</span>');
            }
        }).fail(() => status.html('<span style="color:#dc2626;">❌ Erreur de connexion</span>'))
        .always(() => btn.prop('disabled', false).text('🗑️ Effacer toutes les données'));
    });

    // Import véhicules
    $('#btn-import-vehicles').on('click', function() {
        const btn = $(this);
        const bar = $('#vehicles-progress-bar');
        const text = $('#vehicles-progress-text');
        btn.prop('disabled', true).text('⏳ Import en cours...');
        setProgress(bar, text, 10, 'Préparation...');

        $.post(ajaxUrl, { action: 'bihrwi_import_vehicles', nonce }, function(resp) {
            if (resp.success) {
                setProgress(bar, text, 100, resp.data.message || 'Import terminé');
            } else {
                setProgress(bar, text, 0, resp.data.message || 'Erreur');
            }
        }).fail(() => setProgress(bar, text, 0, 'Erreur de connexion'))
        .always(() => btn.prop('disabled', false).text('📥 Importer les véhicules'));
    });

    // Upload VehiclesList.zip
    $('#btn-upload-vehicles-zip').on('click', function() {
        const file = $('#vehicles-zip')[0].files[0];
        const status = $('#vehicles-zip-status');
        if (!file) {
            status.text('Sélectionnez un fichier ZIP');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'bihrwi_upload_vehicles_zip');
        formData.append('nonce', nonce);
        formData.append('vehicles_zip', file);
        status.text('⏳ Upload en cours...');
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(resp){
                if (resp.success) {
                    status.html('<span style="color:#16a34a;">✅ ' + resp.data.message + '</span>');
                } else {
                    status.html('<span style="color:#dc2626;">❌ ' + resp.data.message + '</span>');
                }
            },
            error: function(){ status.html('<span style="color:#dc2626;">❌ Erreur de connexion</span>'); }
        });
    });

    // Upload LinksList.zip
    $('#btn-upload-links-zip').on('click', function() {
        const file = $('#links-zip')[0].files[0];
        const status = $('#links-zip-status');
        if (!file) {
            status.text('Sélectionnez un fichier ZIP');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'bihrwi_upload_links_zip');
        formData.append('nonce', nonce);
        formData.append('links_zip', file);
        status.text('⏳ Upload en cours...');
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(resp){
                if (resp.success) {
                    status.html('<span style="color:#16a34a;">✅ ' + resp.data.message + '</span>');
                } else {
                    status.html('<span style="color:#dc2626;">❌ ' + resp.data.message + '</span>');
                }
            },
            error: function(){ status.html('<span style="color:#dc2626;">❌ Erreur de connexion</span>'); }
        });
    });

    // Import par marque (boutons)
    $('.brand-import-btn').on('click', function() {
        const brand = $(this).data('brand');
        const status = $(".brand-status[data-brand-status='" + brand + "']");
        const btn = $(this);
        btn.prop('disabled', true).text('⏳ Import...');
        status.text('Import en cours...');
        $.post(ajaxUrl, { action: 'bihrwi_import_compatibility', nonce, brand }, function(resp) {
            if (resp.success) {
                status.html('<span style="color:#16a34a;">✅ ' + resp.data.message + '</span>');
            } else {
                status.html('<span style="color:#dc2626;">❌ ' + (resp.data.message || 'Erreur') + '</span>');
            }
        }).fail(() => status.html('<span style="color:#dc2626;">❌ Erreur de connexion</span>'))
        .always(() => btn.prop('disabled', false).text('📥 Importer ' + brand));
    });

    // Import groupé avec progression (séquentiel)
    $('#btn-import-all-brands').on('click', function() {
        const btn = $(this);
        const bar = $('#all-brands-progress-bar');
        const text = $('#all-brands-progress-text');
        const logBox = $('#all-brands-log');
        btn.prop('disabled', true).text('⏳ Import en cours...');
        logBox.empty();
        setProgress(bar, text, 0, '0%');

        const total = brands.length;
        let done = 0;

        function importNext() {
            if (done >= total) {
                setProgress(bar, text, 100, 'Terminé');
                btn.prop('disabled', false).text('🚀 Importer toutes les marques');
                return;
            }
            const brand = brands[done];
            logBox.append('<div>⏳ ' + brand + '...</div>');
            $.post(ajaxUrl, { action: 'bihrwi_import_compatibility', nonce, brand }, function(resp) {
                done++;
                const pct = Math.round((done / total) * 100);
                setProgress(bar, text, pct, pct + '%');
                if (resp.success) {
                    logBox.append('<div style="color:#16a34a;">✅ ' + resp.data.message + '</div>');
                } else {
                    logBox.append('<div style="color:#dc2626;">❌ ' + (resp.data.message || 'Erreur') + '</div>');
                }
                importNext();
            }).fail(function(){
                done++;
                const pct = Math.round((done / total) * 100);
                setProgress(bar, text, pct, pct + '%');
                logBox.append('<div style="color:#dc2626;">❌ Erreur de connexion sur ' + brand + '</div>');
                importNext();
            });
        }

        importNext();
    });
});
</script>
