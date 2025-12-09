jQuery(document).ready(function($) {
    
    // ============================================
    // GESTION DE LA SÉLECTION MULTIPLE DE PRODUITS
    // ============================================
    
    var selectedProducts = [];
    
    // Fonction pour mettre à jour le compteur
    function updateSelectedCount() {
        var count = $('.bihr-product-checkbox:checked').length;
        $('#bihr-selected-count').text(count);
        $('#bihr-import-selected').prop('disabled', count === 0);
        selectedProducts = [];
        $('.bihr-product-checkbox:checked').each(function() {
            selectedProducts.push({
                id: $(this).val(),
                name: $(this).data('name')
            });
        });
    }
    
    // Sélectionner tout
    $('#bihr-select-all, #bihr-select-all-checkbox').on('click', function(e) {
        if ($(this).is('#bihr-select-all-checkbox')) {
            var checked = $(this).prop('checked');
            $('.bihr-product-checkbox').prop('checked', checked);
        } else {
            e.preventDefault();
            $('.bihr-product-checkbox').prop('checked', true);
            $('#bihr-select-all-checkbox').prop('checked', true);
        }
        updateSelectedCount();
    });
    
    // Désélectionner tout
    $('#bihr-deselect-all').on('click', function(e) {
        e.preventDefault();
        $('.bihr-product-checkbox').prop('checked', false);
        $('#bihr-select-all-checkbox').prop('checked', false);
        updateSelectedCount();
    });
    
    // Changement de sélection
    $(document).on('change', '.bihr-product-checkbox', function() {
        updateSelectedCount();
        // Mettre à jour la checkbox "tout sélectionner"
        var totalCheckboxes = $('.bihr-product-checkbox').length;
        var checkedCheckboxes = $('.bihr-product-checkbox:checked').length;
        $('#bihr-select-all-checkbox').prop('checked', totalCheckboxes === checkedCheckboxes);
    });
    
    // Import des produits sélectionnés
    $('#bihr-import-selected').on('click', function(e) {
        e.preventDefault();
        
        if (selectedProducts.length === 0) {
            alert('Veuillez sélectionner au moins un produit.');
            return;
        }
        
        if (!confirm('Voulez-vous importer ' + selectedProducts.length + ' produit(s) dans WooCommerce ?')) {
            return;
        }
        
        // Afficher la barre de progression
        var $progressContainer = $('#bihr-import-progress');
        var $progressBar = $('#bihr-progress-bar');
        var $progressText = $('#bihr-progress-text');
        var $progressDetails = $('#bihr-progress-details');
        
        $progressContainer.show();
        $progressBar.css('width', '0%').text('0%');
        $progressText.text('0 / ' + selectedProducts.length + ' produits importés');
        $progressDetails.html('');
        
        // Désactiver les boutons
        $('#bihr-import-selected, #bihr-select-all, #bihr-deselect-all').prop('disabled', true);
        $('.bihr-product-checkbox').prop('disabled', true);
        
        // Importer les produits un par un
        var currentIndex = 0;
        var successCount = 0;
        var errorCount = 0;
        
        function importNextProduct() {
            if (currentIndex >= selectedProducts.length) {
                // Terminé
                $progressBar.css('width', '100%').text('100%');
                var finalMsg = 'Import terminé : ' + successCount + ' succès';
                if (errorCount > 0) {
                    finalMsg += ', ' + errorCount + ' erreur(s)';
                }
                $progressText.html('<strong style="color: green;">' + finalMsg + '</strong>');
                
                // Réactiver les boutons après 2 secondes
                setTimeout(function() {
                    $('#bihr-import-selected, #bihr-select-all, #bihr-deselect-all').prop('disabled', false);
                    $('.bihr-product-checkbox').prop('disabled', false);
                    // Décocher tous les produits importés avec succès
                    $('.bihr-product-checkbox:checked').each(function() {
                        var productId = $(this).val();
                        var wasSuccess = $('#bihr-success-' + productId).length > 0;
                        if (wasSuccess) {
                            $(this).prop('checked', false);
                        }
                    });
                    updateSelectedCount();
                }, 2000);
                
                return;
            }
            
            var product = selectedProducts[currentIndex];
            var percent = Math.round(((currentIndex + 1) / selectedProducts.length) * 100);
            
            $progressBar.css('width', percent + '%').text(percent + '%');
            $progressText.text((currentIndex + 1) + ' / ' + selectedProducts.length + ' produits importés');
            
            // Ajouter une ligne de progression
            $progressDetails.append('<div id="bihr-import-' + product.id + '" style="padding: 5px; border-bottom: 1px solid #ddd;">' +
                '<span class="dashicons dashicons-update" style="color: #2271b1; animation: rotation 1s infinite linear;"></span> ' +
                '<strong>' + product.name + '</strong> (ID: ' + product.id + ') - Import en cours...' +
                '</div>');
            
            // Scroll vers le bas
            $progressDetails.scrollTop($progressDetails[0].scrollHeight);
            
            // Appel AJAX pour importer le produit
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bihrwi_import_single_product',
                    product_id: product.id,
                    nonce: bihrProgressData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#bihr-import-' + product.id).html(
                            '<span id="bihr-success-' + product.id + '" class="dashicons dashicons-yes-alt" style="color: green;"></span> ' +
                            '<strong>' + product.name + '</strong> - Importé avec succès (WC ID: ' + response.data.wc_id + ')'
                        );
                        successCount++;
                    } else {
                        $('#bihr-import-' + product.id).html(
                            '<span class="dashicons dashicons-dismiss" style="color: red;"></span> ' +
                            '<strong>' + product.name + '</strong> - Erreur : ' + response.data.message
                        );
                        errorCount++;
                    }
                },
                error: function() {
                    $('#bihr-import-' + product.id).html(
                        '<span class="dashicons dashicons-dismiss" style="color: red;"></span> ' +
                        '<strong>' + product.name + '</strong> - Erreur de connexion'
                    );
                    errorCount++;
                },
                complete: function() {
                    currentIndex++;
                    // Petit délai pour éviter de surcharger le serveur
                    setTimeout(importNextProduct, 500);
                }
            });
        }
        
        // Démarrer l'import
        importNextProduct();
    });
    
    // ============================================
    // GESTION DU TÉLÉCHARGEMENT DES CATALOGUES
    // ============================================
    
    // Gestion du téléchargement automatique des catalogues
    $('#bihr-download-all-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $button = $form.find('input[type="submit"]');
        var $progressContainer = $('#bihr-download-progress');
        var $progressBar = $('#bihr-download-progress-bar');
        var $progressText = $('#bihr-download-progress-text');
        
        // Désactive le bouton
        $button.prop('disabled', true);
        
        // Affiche la barre de progression
        $progressContainer.show();
        $progressBar.css('width', '0%');
        $progressText.text('Démarrage du téléchargement...');
        
        // Démarre le téléchargement
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bihrwi_download_all_catalogs_ajax',
                nonce: bihrProgressData.nonce
            },
            success: function(response) {
                if (response.success) {
                    $progressBar.css('width', '100%');
                    var msg = '✓ Téléchargement terminé ! ';
                    if (response.data.catalogs_count) {
                        msg += response.data.catalogs_count + ' catalogue(s), ';
                    }
                    msg += response.data.files_count + ' fichier(s) extraits.';
                    $progressText.text(msg);
                    $progressBar.addClass('complete');
                    
                    // Recharge la page après 2 secondes
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    $progressBar.css('width', '100%');
                    $progressBar.addClass('error');
                    $progressText.text('✗ Erreur : ' + response.data.message);
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $progressBar.css('width', '100%');
                $progressBar.addClass('error');
                $progressText.text('✗ Erreur de connexion');
                $button.prop('disabled', false);
            },
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                
                // Simulation de progression (puisque c'est une opération longue)
                var progressInterval = setInterval(function() {
                    var currentWidth = parseInt($progressBar.css('width'));
                    var containerWidth = $progressBar.parent().width();
                    var currentPercent = (currentWidth / containerWidth) * 100;
                    
                    if (currentPercent < 90) {
                        var newPercent = currentPercent + (Math.random() * 5);
                        $progressBar.css('width', newPercent + '%');
                        
                        // Messages de progression
                        if (newPercent < 20) {
                            $progressText.text('Téléchargement du catalogue References...');
                        } else if (newPercent < 40) {
                            $progressText.text('Téléchargement du catalogue ExtendedReferences...');
                        } else if (newPercent < 55) {
                            $progressText.text('Téléchargement du catalogue Attributes...');
                        } else if (newPercent < 70) {
                            $progressText.text('Téléchargement du catalogue Images...');
                        } else if (newPercent < 90) {
                            $progressText.text('Téléchargement du catalogue Stocks...');
                        }
                    }
                }, 3000);
                
                // Nettoie l'intervalle quand la requête est terminée
                xhr.addEventListener('loadend', function() {
                    clearInterval(progressInterval);
                });
                
                return xhr;
            }
        });
    });
    
    // Gestion de la fusion des catalogues
    $('#bihr-merge-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $button = $form.find('input[type="submit"]');
        var $progressContainer = $('#bihr-merge-progress');
        var $progressBar = $('#bihr-merge-progress-bar');
        var $progressText = $('#bihr-merge-progress-text');
        
        // Désactive le bouton
        $button.prop('disabled', true);
        
        // Affiche la barre de progression
        $progressContainer.show();
        $progressBar.css('width', '0%');
        $progressText.text('Démarrage de la fusion...');
        
        // Démarre la fusion
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bihrwi_merge_catalogs_ajax',
                nonce: bihrProgressData.nonce
            },
            success: function(response) {
                if (response.success) {
                    $progressBar.css('width', '100%');
                    $progressText.text('✓ Fusion terminée ! ' + response.data.count + ' produits fusionnés.');
                    $progressBar.addClass('complete');
                    
                    // Recharge la page après 2 secondes
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    $progressBar.css('width', '100%');
                    $progressBar.addClass('error');
                    $progressText.text('✗ Erreur : ' + response.data.message);
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $progressBar.css('width', '100%');
                $progressBar.addClass('error');
                $progressText.text('✗ Erreur de connexion');
                $button.prop('disabled', false);
            },
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                
                // Simulation de progression
                var progressInterval = setInterval(function() {
                    var currentWidth = parseInt($progressBar.css('width'));
                    var containerWidth = $progressBar.parent().width();
                    var currentPercent = (currentWidth / containerWidth) * 100;
                    
                    if (currentPercent < 90) {
                        var newPercent = currentPercent + (Math.random() * 10);
                        $progressBar.css('width', newPercent + '%');
                        
                        // Messages de progression
                        if (newPercent < 30) {
                            $progressText.text('Lecture des fichiers CSV...');
                        } else if (newPercent < 60) {
                            $progressText.text('Fusion des catalogues...');
                        } else if (newPercent < 90) {
                            $progressText.text('Enregistrement dans la base de données...');
                        }
                    }
                }, 500);
                
                xhr.addEventListener('loadend', function() {
                    clearInterval(progressInterval);
                });
                
                return xhr;
            }
        });
    });
    
});
