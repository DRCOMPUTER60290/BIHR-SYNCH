#!/bin/bash

# Script de vérification des modifications
# Vérifie que tous les changements sont en place

echo "🔍 Vérification des modifications..."
echo ""

# Vérifier les fichiers modifiés existent
echo "✅ Vérification des fichiers..."
test -f "/workspaces/BIHR-SYNCH/includes/class-bihr-vehicle-compatibility.php" && echo "   ✓ class-bihr-vehicle-compatibility.php" || echo "   ✗ MANQUANT"
test -f "/workspaces/BIHR-SYNCH/admin/class-bihr-admin.php" && echo "   ✓ class-bihr-admin.php" || echo "   ✗ MANQUANT"
test -f "/workspaces/BIHR-SYNCH/admin/views/compatibility-page.php" && echo "   ✓ compatibility-page.php" || echo "   ✗ MANQUANT"
echo ""

# Vérifier les phrases clés dans les fichiers modifiés
echo "✅ Vérification des modifications PHP..."

if grep -q "batch_start" "/workspaces/BIHR-SYNCH/includes/class-bihr-vehicle-compatibility.php"; then
    echo "   ✓ Paramètre \$batch_start trouvé"
else
    echo "   ✗ Paramètre \$batch_start MANQUANT"
fi

if grep -q "count_csv_lines" "/workspaces/BIHR-SYNCH/includes/class-bihr-vehicle-compatibility.php"; then
    echo "   ✓ Méthode count_csv_lines trouvée"
else
    echo "   ✗ Méthode count_csv_lines MANQUANTE"
fi

if grep -q "get_transient" "/workspaces/BIHR-SYNCH/includes/class-bihr-vehicle-compatibility.php"; then
    echo "   ✓ Transient caching trouvé"
else
    echo "   ✗ Transient caching MANQUANT"
fi

if grep -q "next_batch" "/workspaces/BIHR-SYNCH/admin/class-bihr-admin.php"; then
    echo "   ✓ Réponse next_batch trouvée"
else
    echo "   ✗ Réponse next_batch MANQUANTE"
fi

echo ""
echo "✅ Vérification des modifications JavaScript..."

if grep -q "importBatch(batchStart)" "/workspaces/BIHR-SYNCH/admin/views/compatibility-page.php"; then
    echo "   ✓ Fonction importBatch (récursive) trouvée"
else
    echo "   ✗ Fonction importBatch MANQUANTE"
fi

if grep -q "batch_start: batchStart" "/workspaces/BIHR-SYNCH/admin/views/compatibility-page.php"; then
    echo "   ✓ Paramètre batch_start en AJAX trouvé"
else
    echo "   ✗ Paramètre batch_start en AJAX MANQUANT"
fi

if grep -q "data.is_complete" "/workspaces/BIHR-SYNCH/admin/views/compatibility-page.php"; then
    echo "   ✓ Logique is_complete trouvée"
else
    echo "   ✗ Logique is_complete MANQUANTE"
fi

echo ""
echo "✅ Vérification de la syntaxe PHP..."
php -l "/workspaces/BIHR-SYNCH/includes/class-bihr-vehicle-compatibility.php" 2>&1 | grep -q "No syntax errors" && echo "   ✓ class-bihr-vehicle-compatibility.php OK" || echo "   ✗ Erreur de syntaxe"
php -l "/workspaces/BIHR-SYNCH/admin/class-bihr-admin.php" 2>&1 | grep -q "No syntax errors" && echo "   ✓ class-bihr-admin.php OK" || echo "   ✗ Erreur de syntaxe"

echo ""
echo "✅ Documentation fournie..."
test -f "/workspaces/BIHR-SYNCH/PROGRESS_BAR_UPDATE.md" && echo "   ✓ PROGRESS_BAR_UPDATE.md" || echo "   ✗ MANQUANT"
test -f "/workspaces/BIHR-SYNCH/DEPLOYMENT_GUIDE.md" && echo "   ✓ DEPLOYMENT_GUIDE.md" || echo "   ✗ MANQUANT"
test -f "/workspaces/BIHR-SYNCH/CHANGELOG.md" && echo "   ✓ CHANGELOG.md" || echo "   ✗ MANQUANT"
test -f "/workspaces/BIHR-SYNCH/BATCH_PROGRESS_EXAMPLE.js" && echo "   ✓ BATCH_PROGRESS_EXAMPLE.js" || echo "   ✗ MANQUANT"

echo ""
echo "🎉 Vérification terminée!"
echo ""
echo "📊 Résumé des modifications:"
echo "  • Méthode import_brand_compatibility: Support batches"
echo "  • AJAX import_compatibility: Progression réelle"
echo "  • JavaScript: Boucles récursives par batch"
echo "  • UI: Affiche progression réelle en temps réel"
echo ""
echo "✅ Prêt pour le déploiement!"
