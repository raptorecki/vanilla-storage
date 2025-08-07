<?php
require_once 'database.php';

echo "<pre>\n";
echo "Attempting to update database schema...\n\n";

try {
    // --- Create st_scans table ---
    echo "Checking for `st_scans` table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS `st_scans` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `drive_id` INT NOT NULL,
        `scan_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `total_items_scanned` INT NOT NULL DEFAULT 0,
        `new_files_added` INT NOT NULL DEFAULT 0,
        `existing_files_updated` INT NOT NULL DEFAULT 0,
        `files_marked_deleted` INT NOT NULL DEFAULT 0,
        `scan_duration` FLOAT NOT NULL DEFAULT 0,
        `thumbnails_created` INT NOT NULL DEFAULT 0,
        `thumbnails_failed` INT NOT NULL DEFAULT 0,
        FOREIGN KEY (`drive_id`) REFERENCES `st_drives`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "`st_scans` table created or already exists.\n\n";

    // --- Add scan_id to st_files table ---
    echo "Checking for `scan_id` column in `st_files` table...\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM `st_files` LIKE 'scan_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `st_files` ADD COLUMN `scan_id` INT NULL AFTER `drive_id`, ADD CONSTRAINT `fk_scan_id` FOREIGN KEY (`scan_id`) REFERENCES `st_scans`(`id`) ON DELETE SET NULL;");
        echo "`scan_id` column added to `st_files` table.\n";
    } else {
        echo "`scan_id` column already exists in `st_files`.\n";
    }

    echo "\nSchema update completed successfully!\n";

} catch (PDOException $e) {
    die("Database schema update failed: " . $e->getMessage() . "\n");
}

echo "</pre>\n";