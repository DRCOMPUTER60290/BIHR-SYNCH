# Guide de déploiement - Mise à jour barre de progression

## Résumé de la solution

La solution corrige le problème de timeout lors de l'import de fichiers CSV volumineux (ex: TECNIUM.csv - 27 MB) en implémentant un système de **progression par batch** avec mise à jour en temps réel.

## Architecture

### Côté serveur (PHP)

1. **Comptage des lignes** (une seule fois, en cache)
   - Utilise les transients WordPress pour stocker le nombre de lignes
   - Durée de cache: 1 heure
   - Clé: `bihr_import_total_{md5(file_path)}`

2. **Traitement par batch** (100 lignes par batch)
   - Chaque appel AJAX traite exactement 100 lignes
   - Libère la mémoire après chaque batch (`wp_cache_flush`)
   - Temps estimé par batch: 500-1000ms

3. **Réponse JSON enrichie**
   - `progress`: Pourcentage (0-100)
   - `processed`: Nombre de lignes traitées jusqu'à présent
   - `total_lines`: Nombre total de lignes
   - `is_complete`: Import terminé?
   - `next_batch`: Indice du prochain batch

### Côté client (JavaScript)

1. **Boucle AJAX séquentielle**
   - Envoie les batches un par un (pas de parallélisation)
   - Attend la réponse avant de continuer
   - Affiche la progression en temps réel

2. **Gestion des erreurs**
   - Continue même en cas d'erreur
   - Affiche l'erreur mais ne bloque pas le flux

3. **UI améliorée**
   - Affiche le pourcentage en temps réel: "⏳ 45% (2250/5000)"
   - Log détaillé des actions

## Points critiques

### ✅ Optimisations implémentées

1. **Pas de rechargement de page** : Les données sont conservées en transient
2. **Timeouts PHP** : Chaque batch < 1s donc < timeout PHP (généralement 30-300s)
3. **Mémoire faible** : Traitement par batch, libération après chaque insert
4. **Expérience UX** : Feedback en temps réel

### ⚠️ Considérations

1. **Taille du batch** : Actuellement 100 lignes
   - Peut être augmenté à 200-500 si PHP permet (profiler nécessaire)
   - Diminuer à 50 si les timeouts persistent

2. **Cache des transients**
   - Durée: 1 heure (peut être changée)
   - Nettoie automatiquement à la fin de l'import

3. **Concurrence**
   - ⚠️ Ne pas importer la même marque deux fois simultanément
   - Chaque import utilise sa propre clé de transient (basée sur le chemin du fichier)

## Configuration recommandée

Dans `php.ini` ou `.htaccess`:
```
max_execution_time = 60        # Au minimum 30s
memory_limit = 256M             # Au minimum 128M
post_max_size = 64M
upload_max_filesize = 64M
```

## Tests

### Test unitaire fourni
```bash
php test-batch-logic.php
```
Vérifie que la logique de batch fonctionne correctement pour 249 lignes.

### Test d'intégration
1. Uploader un petit fichier CSV (~100 lignes) via l'admin
2. Observer la progression: "⏳ X% (Y/Z)"
3. Vérifier que les données sont importées correctement

### Test de gros fichier
1. Uploader TECNIUM.csv (27 MB, ~50 000 lignes)
2. Observer les batches s'afficher
3. Vérifier le temps total (5-10 minutes attendu)

## Performance

### Estimations
| Taille | Lignes | Batches | Temps estimé |
|--------|--------|---------|--------------|
| 1 MB   | 2 000  | 20      | 15-20s       |
| 10 MB  | 20 000 | 200     | 2.5-3 min    |
| 27 MB  | 50 000 | 500     | 6-8 min      |

### Optimisations futures possibles
- [ ] Augmenter batch_size basé sur la performance mesurée
- [ ] Utiliser les workers AJAX pour paralléliser les marques
- [ ] Implémenter un système de queue persistant (Cron WP)

## Problèmes courants et solutions

### Problème: "Erreur de connexion" sur certains batches
**Solution**: Augmenter `max_execution_time` dans PHP

### Problème: "Import très lent"
**Solution**: Vérifier si la DB est surchargée, ou diminuer batch_size

### Problème: "Transient pas supprimé"
**Solution**: Transients auto-nettoyés après 1 heure, ou via `delete_transient()`

### Problème: "Import partiel" (arrêt soudain)
**Solution**: Reprendre à partir du `next_batch` fourni (implémenté automatiquement)

## Documentation pour l'utilisateur

### Via l'admin
Aucune documentation supplémentaire nécessaire - l'interface affiche:
- Progression en temps réel
- Pourcentage
- Nombre de lignes traitées
- Messages d'erreur détaillés

### Messages affichés
```
⏳ 0% - Import démarré
⏳ 25% (500/2000) - Premier quart
⏳ 50% (1000/2000) - À mi-chemin
⏳ 75% (1500/2000) - Trois quarts
✅ 100% - Terminé! 2000 compatibilités importées
```

## Fichiers modifiés

1. **`includes/class-bihr-vehicle-compatibility.php`**
   - Nouvelle méthode `import_brand_compatibility()` avec support batches
   - Nouvelle méthode helper `count_csv_lines()`

2. **`admin/class-bihr-admin.php`**
   - `ajax_import_compatibility()` : Support du paramètre `batch_start`
   - `ajax_import_all_compatibility()` : Support du paramètre `batch_start`

3. **`admin/views/compatibility-page.php`**
   - Import par marque : Boucle récursive pour les batches
   - Import groupé : Marques séquentielles avec batches pour chacune

## Rollback

Si problèmes, le rollback est simple (aucune DB migration):
1. Restaurer les 3 fichiers de la sauvegarde
2. Rafraîchir la page admin
3. Les transients (transitoires) seront auto-supprimés après 1 heure

## Support

Pour toute question ou problème:
1. Vérifier les logs WordPress
2. Vérifier la console navigateur (DevTools)
3. Vérifier les fichiers dans `/wp-content/uploads/bihr-import/`
4. Augmenter `batch_size` ou diminuer selon les performances
