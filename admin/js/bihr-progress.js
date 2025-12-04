jQuery(document).ready(function($) {
    
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
                        if (newPercent < 25) {
                            $progressText.text('Téléchargement du catalogue ExtendedReferences...');
                        } else if (newPercent < 50) {
                            $progressText.text('Téléchargement du catalogue Attributes...');
                        } else if (newPercent < 75) {
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
