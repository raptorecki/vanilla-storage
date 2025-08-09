<?php
/**
 * CLI Script to Check Thumbnail Generation Status for a Drive
 *
 * This script queries the `st_thumbnail_queue` table to report the status
 * of thumbnail generation for files belonging to a specific drive ID.
 *
 * Usage:
 * php check_thumbnails.php <drive_id>
 *
 * Arguments:
 *   <drive_id>          : The integer ID of the drive to check.
 */

// --- Basic CLI Sanity Checks ---
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

require_once 'database.php';
require_once 'helpers/error_logger.php';

// --- Argument Parsing ---
$args = $argv;
array_shift($args); // Remove the script name itself.

// --- Usage and Validation ---
$usage = "Usage: php " . basename(__FILE__) . " <drive_id>\n";

if (count($args) < 1) {
    echo $usage;
    exit(1);
}

$driveId = (int)$args[0];

if ($driveId <= 0) {
    echo "Error: Invalid drive_id provided.\n";
    exit(1);
}

echo "Checking thumbnail generation status for Drive ID: {$driveId}\n\n";

try {
    // Query to get counts of different statuses for the given drive_id
    $stmt = $pdo->prepare("
        SELECT
            stq.status,
            COUNT(*) AS count
        FROM
            st_thumbnail_queue stq
        JOIN
            st_files stf ON stq.file_id = stf.id
        WHERE
            stf.drive_id = ?
        GROUP BY
            stq.status
    ");
    $stmt->execute([$driveId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusCounts = [
        'pending' => 0,
        'processing' => 0,
        'completed' => 0,
        'failed' => 0,
    ];

    foreach ($results as $row) {
        $statusCounts[$row['status']] = (int)$row['count'];
    }

    $totalQueued = array_sum($statusCounts);

    echo "--- Thumbnail Status for Drive ID {$driveId} ---\n";
    echo "Total Queued:     " . number_format($totalQueued) . "\n";
    echo "Pending:          " . number_format($statusCounts['pending']) . "\n";
    echo "Processing:       " . number_format($statusCounts['processing']) . "\n";
    echo "Completed:        " . number_format($statusCounts['completed']) . "\n";
    echo "Failed:           " . number_format($statusCounts['failed']) . "\n";
    echo "------------------------------------\n";

    if ($statusCounts['pending'] === 0 && $statusCounts['processing'] === 0) {
        echo "All thumbnail generation jobs for Drive ID {$driveId} are either completed or failed.\n";
        echo "You can safely disconnect the drive if no further operations are pending.\n";
    } else {
        echo "Thumbnail generation is still in progress or has pending items for Drive ID {$driveId}.\n";
        echo "Please wait for all 'Pending' and 'Processing' items to complete.\n";
    }

} catch (PDOException $e) {
    log_error("Database error checking thumbnail status for drive ID {$driveId}: " . $e->getMessage());
    echo "An error occurred while checking thumbnail status. Check logs for details.\n";
    exit(1);
}

?>