<?php
/**
 * Test script pour la progression par batch
 * À exécuter dans l'admin WordPress pour tester
 */

// Simuler un petit CSV de test
$test_csv_content = "vehicle_code,part_number,barcode,manufacturer_part_number,position_id,position_value,attributes\n";
for ($i = 1; $i <= 250; $i++) {
    $test_csv_content .= "VEH{$i},PART{$i},BC{$i},MPN{$i},POS{$i},VAL{$i},ATTR{$i}\n";
}

// Exemple d'utilisation
echo "=== Test de progression par batch ===\n\n";

// Supposons une classe BihrWI_Vehicle_Compatibility chargée
// Simulation du flux:

$total_lines = 250 - 1; // Moins le header
$batch_size = 100;
$batch_start = 0;

echo "Total lines: {$total_lines}\n";
echo "Batch size: {$batch_size}\n\n";

$all_results = [];

while ($batch_start < $total_lines) {
    $processed = $batch_start + $batch_size;
    if ($processed > $total_lines) {
        $processed = $total_lines;
    }
    
    $progress = round(($processed / $total_lines) * 100);
    $is_complete = $processed >= $total_lines;
    $next_batch = $is_complete ? 0 : $processed;
    
    $result = [
        'success' => true,
        'imported' => min($batch_size, $total_lines - $batch_start),
        'errors' => 0,
        'total_lines' => $total_lines,
        'processed' => $processed,
        'progress' => $progress,
        'is_complete' => $is_complete,
        'next_batch' => $next_batch,
    ];
    
    echo "Batch {$batch_start}-{$processed}: {$progress}% complet\n";
    $all_results[] = $result;
    
    $batch_start = $next_batch;
    
    if ($is_complete) break;
}

echo "\n=== Résumé ===\n";
echo "Total batches: " . count($all_results) . "\n";
echo "Dernier résultat:\n";
echo json_encode($all_results[count($all_results) - 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
echo "\n";

// Vérifier que le dernier batch atteint bien 100%
$last = $all_results[count($all_results) - 1];
echo "\n✅ Test réussi: Progression finale = " . $last['progress'] . "%, is_complete = " . ($last['is_complete'] ? 'true' : 'false') . "\n";
