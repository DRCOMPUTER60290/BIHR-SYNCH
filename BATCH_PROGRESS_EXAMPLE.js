/**
 * Exemple d'utilisation du nouveau système de progression avec batches
 * Pour les gros fichiers CSV (ex: TECNIUM.csv - 27 MB)
 */

// ============================================
// IMPORT D'UNE MARQUE AVEC PROGRESSION RÉELLE
// ============================================

function importBrandWithProgress(brand, onProgress, onComplete, onError) {
    let totalImported = 0;
    let totalErrors = 0;
    
    function importBatch(batchStart = 0) {
        // Envoi du batch AJAX
        $.post(ajaxUrl, {
            action: 'bihrwi_import_compatibility',
            nonce: window.bihrNonce,  // Doit être défini globalement
            brand: brand,
            batch_start: batchStart
        }, function(response) {
            if (!response.success) {
                onError && onError(response.data.message);
                return;
            }
            
            const data = response.data;
            totalImported += data.imported;
            totalErrors += data.errors;
            
            // Appel du callback de progression
            if (onProgress) {
                onProgress({
                    brand: brand,
                    progress: data.progress,
                    processed: data.processed,
                    total: data.total_lines,
                    imported: totalImported,
                    errors: totalErrors,
                    message: `${data.progress}% (${data.processed}/${data.total_lines})`
                });
            }
            
            // Si pas complet, continuer avec le prochain batch
            if (!data.is_complete && data.next_batch !== undefined) {
                importBatch(data.next_batch);
            } else {
                // Import terminé
                onComplete && onComplete({
                    brand: brand,
                    totalImported: totalImported,
                    totalErrors: totalErrors,
                    success: true
                });
            }
        }).fail(function(xhr, status, error) {
            onError && onError('Erreur de connexion: ' + error);
        });
    }
    
    // Démarrer l'import du premier batch
    importBatch();
}

// ============================================
// IMPORT DE TOUTES LES MARQUES SÉQUENTIELLEMENT
// ============================================

function importAllBrandsWithProgress(brands, onBrandProgress, onAllComplete) {
    let brandIndex = 0;
    let totalAllImported = 0;
    let totalAllErrors = 0;
    
    function importNextBrand() {
        if (brandIndex >= brands.length) {
            // Tous les marques sont terminés
            onAllComplete && onAllComplete({
                totalImported: totalAllImported,
                totalErrors: totalAllErrors,
                success: true
            });
            return;
        }
        
        const brand = brands[brandIndex];
        
        importBrandWithProgress(
            brand,
            // onProgress
            function(progress) {
                onBrandProgress && onBrandProgress({
                    currentBrand: brand,
                    brandIndex: brandIndex,
                    totalBrands: brands.length,
                    progress: progress
                });
            },
            // onComplete
            function(result) {
                totalAllImported += result.totalImported;
                totalAllErrors += result.totalErrors;
                brandIndex++;
                importNextBrand();
            },
            // onError
            function(error) {
                console.error('Erreur pour ' + brand + ': ' + error);
                brandIndex++;
                importNextBrand();
            }
        );
    }
    
    importNextBrand();
}

// ============================================
// EXEMPLE D'UTILISATION
// ============================================

// Importer une seule marque
importBrandWithProgress('TECNIUM',
    function(progress) {
        console.log(progress.message);  // "45% (2250/5000)"
        $('#progress-bar').css('width', progress.progress + '%');
        $('#progress-text').text(progress.message);
    },
    function(result) {
        console.log('✅ Terminé! ' + result.totalImported + ' importés');
    },
    function(error) {
        console.error('❌ ' + error);
    }
);

// Importer toutes les marques
importAllBrandsWithProgress(
    ['SHIN YO', 'TECNIUM', 'V BIKE', 'V PARTS', 'VECTOR', 'VICMA'],
    function(progress) {
        const overallProgress = 
            ((progress.brandIndex / progress.totalBrands) * 100) +
            ((progress.progress.progress / 100) * (100 / progress.totalBrands));
        
        console.log(`[${progress.brandIndex + 1}/${progress.totalBrands}] ${progress.currentBrand}: ${progress.progress.message}`);
        $('#overall-progress').css('width', Math.round(overallProgress) + '%');
    },
    function(result) {
        console.log('✅ Import de tous les marques terminé! ' + result.totalImported + ' au total');
    }
);
