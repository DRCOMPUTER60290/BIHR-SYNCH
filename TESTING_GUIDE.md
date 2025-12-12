# 🧪 Guide de test et validation

## Tests avant déploiement

### Test 1: Vérification de la syntaxe

```bash
# Vérifier PHP
php -l includes/class-bihr-vehicle-compatibility.php
php -l admin/class-bihr-admin.php
php -l admin/views/compatibility-page.php

# Vérifier JavaScript (si JSHint/ESLint disponible)
npm run lint admin/views/compatibility-page.php
```

**Résultat attendu:**
```
No syntax errors detected
```

---

### Test 2: Accès à l'interface admin

1. Ouvrir: `/wp-admin/admin.php?page=bihrwi_compatibility`
2. Vérifier que la page charge sans erreur
3. Vérifier que les boutons sont présents:
   - ✅ "Créer/Recréer les tables"
   - ✅ "Effacer toutes les données"
   - ✅ "Importer les véhicules"
   - ✅ "Importer [MARQUE]" (x6)
   - ✅ "Importer toutes les marques"

---

### Test 3: Import d'un petit fichier (test rapide)

#### Préparation
1. Créer un fichier CSV de test: `test-marque.csv`
   ```
   vehicle_code,part_number,barcode,manufacturer_part_number,position_id,position_value,attributes
   VEH001,PART001,BC001,MPN001,POS001,VAL001,ATTR001
   VEH002,PART002,BC002,MPN002,POS002,VAL002,ATTR002
   ... (au moins 250 lignes pour 3 batches)
   ```

2. Uploader dans `/wp-content/uploads/bihr-import/test-marque.csv`

#### Exécution
1. Vérifier les tables: `wp bihr_vehicles count`, etc.
2. Cliquer "Importer [TEST-MARQUE]"
3. Observer la progression affichée

#### Attendu
```
⏳ 0% (0/250)
⏳ 33% (100/250)
⏳ 66% (200/250)
⏳ 100% (250/250)
✅ TEST-MARQUE : 250 compatibilités importées
```

#### Vérification
```sql
SELECT COUNT(*) FROM wp_bihr_vehicle_compatibility 
WHERE source_brand = 'TEST-MARQUE';
-- Résultat: 250
```

---

### Test 4: Import d'un gros fichier (TECNIUM.csv)

#### Conditions
- Fichier: TECNIUM.csv (27 MB, ~50 000 lignes)
- Timeout PHP: >= 60s
- Mémoire: >= 256 MB

#### Exécution
1. Cliquer "Importer TECNIUM"
2. Observer pendant 5-10 minutes
3. Rafraîchir la page si besoin

#### Attendu
- ✅ Progression s'affiche avec % réel
- ✅ Pas d'erreur 502/504
- ✅ Pas de timeout
- ✅ Nombre de lignes importées augmente progressivement
- ✅ Après 8-10 minutes: "✅ TECNIUM : 50000 importés"

#### Vérification
```sql
SELECT COUNT(*) FROM wp_bihr_vehicle_compatibility 
WHERE source_brand = 'TECNIUM';
-- Résultat: ~50000 (selon le fichier)

SELECT COUNT(DISTINCT source_brand) FROM wp_bihr_vehicle_compatibility;
-- Résultat: >= 1 (TECNIUM)
```

---

### Test 5: Import groupé (toutes les marques)

#### Exécution
1. Cliquer "Importer toutes les marques"
2. Observer la progression des marques

#### Attendu
```
⏳ Démarrage de SHIN YO...
  ⏳ SHIN YO : 10%
  ⏳ SHIN YO : 20%
  ...
  ⏳ SHIN YO : 100%
✅ SHIN YO terminé

⏳ Démarrage de TECNIUM...
  ⏳ TECNIUM : 10%
  ...
✅ TECNIUM terminé
... (autres marques)

✅ Import de tous les marques terminé!
```

#### Temps estimé
- 6 marques × 5-10 min chacune = 30-60 min total

---

### Test 6: Reprise sur erreur

#### Simulation d'une interruption
1. Lancer l'import TECNIUM
2. Après 30 secondes, arrêter manuelle (Ctrl+C ou fermer navigateur)
3. Relancer l'import

#### Attendu
- ❓ Transient encore en cache
- ✅ Import repart du début (pas de corruption)
- ✅ Résultats identiques

---

### Test 7: Gestion des erreurs

#### Fichier mal formé
1. Créer CSV avec colonnes manquantes
2. Importer

#### Attendu
- ✅ Import continue (pas d'erreur fatale)
- ✅ Affichage du nombre d'erreurs
- ❌ Status: `❌ Message d'erreur`

---

### Test 8: Vérification de la mémoire

#### Monitoring pendant l'import
```bash
# Dans un terminal séparé
watch -n 1 'ps aux | grep php'

# Ou via WordPress
wp shell
> wp_memory_get_usage()
```

#### Attendu
- ✅ Mémoire stable (pas de croissance linéaire)
- ✅ Pic temporaire à chaque batch, puis baisse
- ✅ Pas de dépassement de `memory_limit`

---

## Tests de performance

### Benchmark par batch_size

```
CSV: 5000 lignes
batch_size = 50:   50 batches × 200ms = 10s ✅
batch_size = 100:  50 batches × 400ms = 20s ✅
batch_size = 200:  25 batches × 800ms = 20s ✅
batch_size = 500:  10 batches × 2000ms = 20s (risqué)
```

**Recommandation:**
- Actuellement: batch_size = 100
- Si rapide: augmenter à 200-300
- Si lent: réduire à 50

---

## Checklist de validation

### ✅ Avant déploiement
- [ ] Syntaxe PHP valide
- [ ] Pas d'erreur JavaScript (console)
- [ ] Test petit fichier OK
- [ ] Test TECNIUM OK
- [ ] Mémoire stable
- [ ] Pas de timeout
- [ ] Transients nettoyés après
- [ ] Logs visibles dans l'admin

### ✅ Après déploiement
- [ ] Import test réussi
- [ ] Données correctes en DB
- [ ] Progression affichée
- [ ] Pas de crash
- [ ] Logs dans WordPress

---

## Troubleshooting

### Problème: "Erreur 502"
**Solution:**
- Augmenter `max_execution_time` dans PHP
- Réduire `batch_size` à 50
- Vérifier mémoire disponible

### Problème: "Progression ne s'affiche pas"
**Solution:**
- Vérifier DevTools (F12) → Network
- Vérifier que AJAX retourne JSON valide
- Vérifier nonce correct

### Problème: "Import très lent"
**Solution:**
- Vérifier requêtes DB lentes
- Augmenter `batch_size` à 200
- Vérifier CPU/disque

### Problème: "Transient pas nettoyé"
**Solution:**
- Attendre 1 heure (durée du transient)
- Ou lancer: `wp transient delete bihr_import_total_*`

---

## Rapports de test

### Template de rapport
```
Test: [NOM]
Date: [DATE]
Durée: [TEMPS]
Fichier: [NOM.csv]
Lignes: [NOMBRE]
Résultat: ✅ OK / ❌ ÉCHEC
Détails:
- Progression: [OK/KO]
- Données: [NOMBRE] importées
- Erreurs: [NOMBRE]
- Mémoire: [USAGE]
- Temps par batch: [MS]

Notes:
[OBSERVATIONS]
```

---

## Automatisation des tests

### Script PHP pour test automatisé
```php
<?php
function test_batch_import() {
    // Créer un fichier de test
    $csv = create_test_csv(5000);
    
    // Tester avec différents batch_start
    $total_imported = 0;
    for ($batch = 0; $batch < 5000; $batch += 100) {
        $result = import_batch($batch);
        $total_imported += $result['imported'];
        assert($result['progress'] > 0, "Progress not increasing");
    }
    
    assert($total_imported == 5000, "Not all lines imported");
    echo "✅ Test automatisé réussi";
}
```

---

**Estimation du temps de test: 4-6 heures**
*Incluant tests rapides, gros fichier, monitoring, documentation*
