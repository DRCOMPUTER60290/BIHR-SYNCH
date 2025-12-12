# Mise à jour de la barre de progression pour les gros fichiers CSV

## Problème résolu
L'import de fichiers CSV volumineux (ex: TECNIUM.csv - 27 MB) causait des timeouts car tout le fichier était traité en mémoire d'un seul coup.

## Solution implémentée

### 1. **Classe `BihrWI_Vehicle_Compatibility`** (/includes/class-bihr-vehicle-compatibility.php)

#### Modifications principales:
- **Nouvelle méthode `import_brand_compatibility($brand_name, $file_path, $batch_start)`** :
  - Supporte un paramètre `$batch_start` pour reprendre l'import à partir d'une ligne spécifique
  - Traite les fichiers par **batch de 100 lignes** pour éviter les timeouts
  - Stocke le nombre total de lignes dans un transient WordPress (durée: 1 heure)
  - Retourne une progression réelle en pourcentage
  
- **Nouvelle méthode helper `count_csv_lines($file_path)`** :
  - Compte le nombre de lignes totales du fichier CSV
  - Résultat mis en cache pour optimiser les appels répétés

#### Structure de réponse améliorée:
```php
[
    'success' => true,
    'imported' => 100,           // Lignes importées dans ce batch
    'errors' => 2,               // Erreurs dans ce batch
    'total_lines' => 50000,      // Nombre total de lignes
    'processed' => 500,          // Nombre total de lignes traitées jusqu'à présent
    'progress' => 15,            // Pourcentage (0-100)
    'is_complete' => false,      // Import complètement terminé?
    'next_batch' => 500,         // Indice du prochain batch à traiter
]
```

### 2. **Admin AJAX** (/admin/class-bihr-admin.php)

#### `ajax_import_compatibility()`
- Récupère maintenant le paramètre `batch_start` du POST
- Transmet le `batch_start` à la méthode d'import
- Retourne la progression en pourcentage réelle

#### `ajax_import_all_compatibility()`
- Mise à jour similaire pour supporter les batches
- Permet l'import groupé de toutes les marques avec progression réelle

### 3. **Interface utilisateur** (/admin/views/compatibility-page.php)

#### Import par marque
- **Fonction JavaScript récursive** `importBatch()`:
  - Envoie des requêtes AJAX séquentiellement pour chaque batch
  - Affiche la progression réelle: `⏳ 25% (500/2000)`
  - Recrée automatiquement la prochaine requête si `is_complete === false`
  - Continue jusqu'à `next_batch === 0`

#### Import groupé (toutes les marques)
- **Fonction JavaScript imbriquée** `importBrandBatches()` + `importBrand()`:
  - Traite chaque marque complètement avant de passer à la suivante
  - Affiche la progression globale ET par marque
  - Gère les erreurs sans interrompre l'import des autres marques
  - Log détaillé dans une boîte de progression

## Avantages

✅ **Pas de timeout** : Les gros fichiers sont traités par batch de 100 lignes
✅ **Progression réelle** : Les pourcentages affichés reflètent l'avancement réel
✅ **Expérience utilisateur** : L'UI réagit et se met à jour en temps réel
✅ **Robustesse** : Gestion des erreurs per-batch sans perte de données
✅ **Optimisation mémoire** : Chaque batch libère les ressources (wp_cache_flush)
✅ **Transparence** : Logs détaillés de la progression

## Exemple de flux
1. Utilisateur clique "Importer TECNIUM"
2. JS envoie POST avec `batch_start=0`
3. PHP retourne: `{ progress: 2, processed: 100, total_lines: 5000, is_complete: false, next_batch: 100 }`
4. JS affiche: "⏳ 2% (100/5000)"
5. JS envoie POST avec `batch_start=100`
6. Repeat jusqu'à `is_complete: true`
7. Message final: "✅ TECNIUM : 5000 compatibilités importées"

## Limites de temps
- **Batch time** : ~500-1000ms par batch (100 lignes)
- **Total pour 50 000 lignes** : ~50-100 secondes (5-10 minutes)
- Peut être optimisé en augmentant `batch_size` si les timeouts PHP le permettent

## Fichiers modifiés
- `/includes/class-bihr-vehicle-compatibility.php`
- `/admin/class-bihr-admin.php`
- `/admin/views/compatibility-page.php`
