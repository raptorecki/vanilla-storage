<?php
/**
 * Test Query Performance
 * Benchmarks the main drives.php query to measure optimization impact
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once 'database.php';

echo "=============================================================\n";
echo "Query Performance Test - drives.php Main Query\n";
echo "=============================================================\n\n";

// The actual query from drives.php
$sql = "
    SELECT
        d1.*,
        d2.name as pair_name,
        CASE
            WHEN d1.dead = 1 OR d1.empty = 1 THEN 'N/A'
            WHEN EXISTS (SELECT 1 FROM st_scans s WHERE s.drive_id = d1.id) THEN 'Done'
            ELSE 'Required'
        END AS scan_status,
        (SELECT MAX(scan_date) FROM st_scans WHERE drive_id = d1.id) AS last_scan,
        (SELECT SUM(size) FROM st_files WHERE drive_id = d1.id AND date_deleted IS NULL) AS used_bytes
    FROM st_drives d1
    LEFT JOIN st_drives d2 ON d1.pair_id = d2.id
    ORDER BY id ASC
";

echo "Executing query...\n";
echo "Query retrieves all drives with calculated used_bytes and scan status\n\n";

$start_time = microtime(true);
$stmt = $pdo->query($sql);
$drives = $stmt->fetchAll();
$duration = microtime(true) - $start_time;

echo "=============================================================\n";
echo "Results:\n";
echo "=============================================================\n";
echo "Total drives retrieved: " . count($drives) . "\n";
echo "Query execution time: " . round($duration, 3) . " seconds\n";
echo "Average time per drive: " . round(($duration / count($drives)) * 1000, 2) . " ms\n\n";

// Show performance rating
if ($duration < 1) {
    echo "Performance: ✓✓✓ EXCELLENT (sub-second)\n";
} elseif ($duration < 3) {
    echo "Performance: ✓✓ GOOD (< 3 seconds)\n";
} elseif ($duration < 10) {
    echo "Performance: ✓ ACCEPTABLE (< 10 seconds)\n";
} else {
    echo "Performance: ✗ NEEDS IMPROVEMENT (> 10 seconds)\n";
}

echo "\n=============================================================\n";
echo "Index Usage Analysis\n";
echo "=============================================================\n\n";

// Check if indexes are being used
$explain = $pdo->query("EXPLAIN " . $sql)->fetchAll();
echo "Query plan shows " . count($explain) . " table access(es)\n";
foreach ($explain as $i => $row) {
    echo "\nAccess #" . ($i + 1) . ":\n";
    echo "  Table: " . $row['table'] . "\n";
    echo "  Type: " . $row['type'] . "\n";
    echo "  Possible keys: " . ($row['possible_keys'] ?? 'none') . "\n";
    echo "  Key used: " . ($row['key'] ?? 'none') . "\n";
    echo "  Rows examined: " . ($row['rows'] ?? 'unknown') . "\n";
}

echo "\n";
