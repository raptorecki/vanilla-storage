<?php
/**
 * Command-Line Drive Indexing Script
 *
 * Scans a specified mount point for a given drive_id, updating the file index.
 *
 * Usage:
 * php scan_drive.php <drive_id> <mount_point>
 *
 * Example:
 * php scan_drive.php 5 /mnt/my_external_drive
 */

// --- Basic CLI Sanity Checks ---
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

require_once 'database.php';
require_once 'helpers/error_logger.php';


// --- Argument Parsing ---
$args = $argv;
array_shift($args); // Remove script name

// Check for flags
$calculateMd5 = true;
$noMd5Key = array_search('--no-md5', $args);
if ($noMd5Key !== false) {
    $calculateMd5 = false;
    unset($args[$noMd5Key]);
}

// Re-index and check for required arguments
$args = array_values($args);

if (count($args) < 3) {
    echo "Usage: php " . basename(__FILE__) . " [--no-md5] <drive_id> <partition_number> <mount_point>\n";
    echo "  --no-md5 : Optional. Skips MD5 hash calculation for a faster scan.\n";
    echo "Example: php " . basename(__FILE__) . " 5 1 /mnt/my_external_drive\n";
    echo "Example: php " . basename(__FILE__) . " --no-md5 5 1 /mnt/my_external_drive\n";
    exit(1);
}

$driveId = (int)$args[0];
$partitionNumber = (int)$args[1];
$mountPoint = $args[2];

if ($driveId <= 0) {
    echo "Error: Invalid drive_id provided.\n";
    exit(1);
}
if (!is_dir($mountPoint)) {
    echo "Error: Mount point '{$mountPoint}' is not a valid directory.\n";
    exit(1);
}

// --- Drive Serial Number Verification ---
echo "Verifying drive serial number...\n";
try {
    // 1. Get the physical device for the mount point
    $devicePath = trim(shell_exec("df --output=source " . escapeshellarg($mountPoint) . " | tail -n 1"));
    if (empty($devicePath)) {
        throw new Exception("Could not determine the device for mount point '{$mountPoint}'.");
    }
    echo "  > Mount point '{$mountPoint}' is on device '{$devicePath}'.\n";

    // 2. Get the serial number of the physical device.
    // First, find the parent block device (e.g., /dev/sda from /dev/sda1) as serials are on the main device.
    $parentDeviceName = trim(shell_exec("lsblk -no pkname " . escapeshellarg($devicePath)));
    $deviceForSerial = $devicePath; // Default to the original device path
    if (!empty($parentDeviceName)) {
        $deviceForSerial = "/dev/" . $parentDeviceName;
        echo "  > Found parent device '{$deviceForSerial}' for serial number lookup.\n";
    }

    $physicalSerial = '';
    // Use hdparm to get the serial number as it is often more reliable for SATA devices.
    if (strpos($deviceForSerial, '/dev/sd') === 0) {
        echo "  > Querying serial number with hdparm...\n";
        $hdparm_output = shell_exec("hdparm -I " . escapeshellarg($deviceForSerial) . " 2>/dev/null | grep 'Serial Number:'");
        if (!empty($hdparm_output) && preg_match('/Serial Number:\s*(.*)/', $hdparm_output, $matches)) {
            $physicalSerial = trim($matches[1]);
        }
    } else {
        echo "  > Device '{$deviceForSerial}' is not a standard SATA device (/dev/sdX). Cannot use hdparm.\n";
    }

    if (empty($physicalSerial)) {
        throw new Exception("Could not read serial number from device '{$deviceForSerial}' using hdparm. This can happen with virtual drives, some USB-to-SATA adapters, or if the user lacks permissions. Please ensure the drive has a readable serial number and the script has sufficient privileges (e.g., run with sudo).");
    }
    echo "  > Physical device serial: {$physicalSerial}\n";

    // 3. Get the expected serial number from the database
    $stmt = $pdo->prepare("SELECT serial FROM st_drives WHERE id = ?");
    $stmt->execute([$driveId]);
    $driveFromDb = $stmt->fetch();

    if (!$driveFromDb) {
        throw new Exception("No drive found in database with ID {$driveId}.");
    }
    $dbSerial = $driveFromDb['serial'];
    echo "  > Database serial for ID {$driveId}: {$dbSerial}\n";

    // 4. Compare serials
    if ($physicalSerial === $dbSerial) {
        echo "  > OK: Serial numbers match.\n\n";
    } else {
        echo "\n!! WARNING: SERIAL NUMBER MISMATCH !!\n";
        echo "The physical drive serial ('{$physicalSerial}') does not match the database serial ('{$dbSerial}').\n";
        echo "Continuing will index the physical drive '{$physicalSerial}' under the database entry for '{$dbSerial}'.\n";
        
        // 5. Require confirmation
        while (true) {
            echo "Are you sure you want to continue? (yes/no): ";
            $line = strtolower(trim(fgets(STDIN)));
            if ($line === 'yes') {
                echo "\nUser confirmed. Continuing with scan...\n\n";
                break;
            } elseif ($line === 'no') {
                echo "Aborting scan.\n";
                exit(2);
            }
        }
    }
} catch (Exception $e) {
    log_error("Error during drive verification: " . $e->getMessage());
    echo "Error during drive verification. Check logs for details.\n";
    exit(1);
}

$startTime = microtime(true);

echo "Starting scan for drive_id: {$driveId} at '{$mountPoint}'...\n";

// --- Prerequisite Check ---
$ffprobePath = trim(@shell_exec('which ffprobe'));
if (empty($ffprobePath)) {
    echo "WARNING: `ffprobe` command not found. Media file metadata (codec, resolution) will not be extracted. Please install FFmpeg to enable this functionality.\n\n";
}

if (!function_exists('exif_read_data')) {
    echo "WARNING: PHP `exif` extension not found. Image EXIF data will not be extracted. Please install `php-exif` to enable this functionality.\n\n";
}

/**
 * Extracts video metadata using ffprobe.
 * @param string $filePath The full path to the video file.
 * @param string $ffprobePath The path to the ffprobe executable.
 * @return array|null An array with metadata or null on failure.
 */
function getVideoInfo(string $filePath, string $ffprobePath): ?array
{
    $command = sprintf(
        '%s -v quiet -print_format json -show_format -show_streams -select_streams v:0 %s',
        $ffprobePath,
        escapeshellarg($filePath)
    );
    $jsonOutput = @shell_exec($command);
    if (empty($jsonOutput)) return null;
    $data = json_decode($jsonOutput, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['streams'][0])) return null;
    $stream = $data['streams'][0];
    $format = $data['format'] ?? [];
    $resolution = (isset($stream['width'], $stream['height'])) ? $stream['width'] . 'x' . $stream['height'] : null;
    return [
        'format' => $format['format_name'] ?? null,
        'codec' => $stream['codec_name'] ?? null,
        'resolution' => $resolution,
        'duration' => isset($format['duration']) ? (float)$format['duration'] : null,
    ];
}

/**
 * Extracts audio metadata using ffprobe.
 * @param string $filePath The full path to the audio file.
 * @param string $ffprobePath The path to the ffprobe executable.
 * @return array|null An array with metadata or null on failure.
 */
function getAudioInfo(string $filePath, string $ffprobePath): ?array
{
    $command = sprintf(
        '%s -v quiet -print_format json -show_format -show_streams -select_streams a:0 %s',
        $ffprobePath,
        escapeshellarg($filePath)
    );
    $jsonOutput = @shell_exec($command);
    if (empty($jsonOutput)) return null;
    $data = json_decode($jsonOutput, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['streams'][0])) return null;
    $stream = $data['streams'][0];
    $format = $data['format'] ?? [];

    $codec_parts = [];
    if (isset($stream['codec_long_name'])) {
        $codec_parts[] = $stream['codec_long_name'];
    }
    $bitrate = $stream['bit_rate'] ?? $format['bit_rate'] ?? null;
    if ($bitrate) {
        $codec_parts[] = round($bitrate / 1000) . ' kbps';
    }
    if (isset($stream['sample_rate'])) {
        $codec_parts[] = ($stream['sample_rate'] / 1000) . ' kHz';
    }

    return [
        'format' => $format['format_name'] ?? null,
        'codec' => implode(', ', $codec_parts),
        'resolution' => null, // Not applicable for audio
        'duration' => isset($format['duration']) ? (float)$format['duration'] : null,
    ];
}

/**
 * Extracts image metadata using native PHP.
 * @param string $filePath The full path to the image file.
 * @return array|null An array with metadata or null on failure.
 */
function getImageInfo(string $filePath): ?array
{
    $imageInfo = @getimagesize($filePath);
    if ($imageInfo === false) {
        return null;
    }

    $exif_data = [];
    // Check for exif support and that it's a file type that can contain exif (JPEG/TIFF)
    if (function_exists('exif_read_data') && in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM])) {
        $exif = @exif_read_data($filePath);
        if ($exif !== false) {
            // Prioritize DateTimeOriginal, fall back to DateTime
            $exif_data['date_taken'] = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
            $exif_data['camera_model'] = isset($exif['Model']) ? trim($exif['Model']) : null;
        }
    }

    return [
        'format' => image_type_to_mime_type($imageInfo[2]),
        'codec' => null, // Not applicable for images
        'resolution' => $imageInfo[0] . 'x' . $imageInfo[1],
        'duration' => null,
        'exif_date_taken' => $exif_data['date_taken'] ?? null,
        'exif_camera_model' => $exif_data['camera_model'] ?? null,
    ];
}

// --- File Type Categorization ---
$extensionMap = [
    // Video
    'mp4' => 'Video', 'mkv' => 'Video', 'mov' => 'Video', 'avi' => 'Video', 'wmv' => 'Video',
    'flv' => 'Video', 'webm' => 'Video', 'mpg' => 'Video', 'mpeg' => 'Video', 'm4v' => 'Video', 'ts' => 'Video',
    // Audio
    'mp3' => 'Audio', 'wav' => 'Audio', 'aac' => 'Audio', 'flac' => 'Audio', 'ogg' => 'Audio',
    'm4a' => 'Audio', 'wma' => 'Audio',
    // Image
    'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image', 'bmp' => 'Image',
    'webp' => 'Image', 'tiff' => 'Image', 'svg' => 'Image',
    // Document
    'pdf' => 'Document', 'doc' => 'Document', 'docx' => 'Document', 'xls' => 'Document', 'xlsx' => 'Document',
    'ppt' => 'Document', 'pptx' => 'Document', 'txt' => 'Document', 'rtf' => 'Document',
    // Archive
    'zip' => 'Archive', 'rar' => 'Archive', '7z' => 'Archive', 'tar' => 'Archive', 'gz' => 'Archive',
    // Executable
    'exe' => 'Executable', 'msi' => 'Executable', 'bat' => 'Executable', 'sh' => 'Executable',
];

// --- Main Scanning Logic ---

$stats = [
    'scanned' => 0,
    'added' => 0,
    'updated' => 0,
    'deleted' => 0,
];

try {
    $pdo->beginTransaction();

    // 1. Mark all existing, non-deleted files for this drive as "deleted".
    // If they are found during the scan, they will be updated and "undeleted".
    echo "Step 1: Marking existing files for deletion check...\n";
    $stmt = $pdo->prepare("UPDATE st_files SET date_deleted = NOW() WHERE drive_id = ? AND date_deleted IS NULL");
    $stmt->execute([$driveId]);
    $stats['deleted'] = $stmt->rowCount(); // Initially, all files are candidates for deletion.

    // 2. Prepare the main statement for inserting/updating files.
    $update_clauses = [
        "date_deleted = NULL",
        "ctime = VALUES(ctime)",
        "mtime = VALUES(mtime)",
        "size = VALUES(size)",
        "media_format = VALUES(media_format)",
        "media_codec = VALUES(media_codec)",
        "media_resolution = VALUES(media_resolution)",
        "media_duration = VALUES(media_duration)",
        "exif_date_taken = VALUES(exif_date_taken)",
        "exif_camera_model = VALUES(exif_camera_model)",
        "file_category = VALUES(file_category)",
        "is_directory = VALUES(is_directory)",
        "partition_number = VALUES(partition_number)"
    ];

    if ($calculateMd5) {
        $insert_cols = "drive_id, path, path_hash, filename, size, md5_hash, ctime, mtime, file_category, media_format, media_codec, media_resolution, media_duration, exif_date_taken, exif_camera_model, is_directory, partition_number, date_added, date_deleted";
        $insert_vals = ":drive_id, :path, :path_hash, :filename, :size, :md5_hash, :ctime, :mtime, :file_category, :media_format, :media_codec, :media_resolution, :media_duration, :exif_date_taken, :exif_camera_model, :is_directory, :partition_number, NOW(), NULL";
        $update_clauses[] = "md5_hash = VALUES(md5_hash)";
    } else {
        $insert_cols = "drive_id, path, path_hash, filename, size, ctime, mtime, file_category, media_format, media_codec, media_resolution, media_duration, exif_date_taken, exif_camera_model, is_directory, partition_number, date_added, date_deleted";
        $insert_vals = ":drive_id, :path, :path_hash, :filename, :size, :ctime, :mtime, :file_category, :media_format, :media_codec, :media_resolution, :media_duration, :exif_date_taken, :exif_camera_model, :is_directory, :partition_number, NOW(), NULL";
        // When not calculating MD5, we don't update the existing hash on duplicates.
    }

    $sql = sprintf(
        "INSERT INTO st_files (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s",
        $insert_cols,
        $insert_vals,
        implode(",\n            ", $update_clauses)
    );
    $upsertStmt = $pdo->prepare($sql);

    // 3. Recursively scan the directory.
    $scan_message = $calculateMd5 ? "Scanning filesystem and updating database (MD5 hashing may be slow)..." : "Scanning filesystem and updating database (skipping MD5 hashing)...";
    echo "Step 2: {$scan_message}\n\n";
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mountPoint, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    // Get terminal width for clean progress display. Fallback to 80.
    $termWidth = (int) @shell_exec('tput cols');
    if ($termWidth <= 0) {
        $termWidth = 80;
    }

    foreach ($iterator as $fileInfo) {
        $stats['scanned']++;
        $path = $fileInfo->getPathname();
        $relativePath = substr($path, strlen($mountPoint));

        // --- Verbose Progress Indicator ---
        $progressMessage = sprintf("[%' 9d] %s", $stats['scanned'], $relativePath);
        // Truncate message if it's longer than the terminal width to prevent wrapping
        if (mb_strlen($progressMessage) > $termWidth) {
            $progressMessage = mb_substr($progressMessage, 0, $termWidth - 4) . '...';
        }
        // Use newline (\n) to show progress on a separate line for each file
        echo $progressMessage . "\n";

        // --- Metadata Extraction ---
        $extension = strtolower($fileInfo->getExtension());
        $category = $extensionMap[$extension] ?? 'Other';
        $metadata = ['format' => null, 'codec' => null, 'resolution' => null, 'duration' => null, 'exif_date_taken' => null, 'exif_camera_model' => null];

        if (!$fileInfo->isDir() && !empty($ffprobePath)) {
            switch ($category) {
                case 'Video':
                    $metadata = array_merge($metadata, getVideoInfo($path, $ffprobePath) ?? []);
                    break;
                case 'Audio':
                    $metadata = array_merge($metadata, getAudioInfo($path, $ffprobePath) ?? []);
                    break;
            }
        }
        // Image info uses native PHP, so it doesn't depend on ffprobe
        if (!$fileInfo->isDir() && $category === 'Image') {
            $metadata = array_merge($metadata, getImageInfo($path) ?? []);
        }

        $params = [
            'drive_id' => $driveId,
            'path' => $relativePath,
            'path_hash' => hash('sha256', $relativePath),
            'filename' => $fileInfo->getFilename(),
            'size' => $fileInfo->isDir() ? 0 : $fileInfo->getSize(),
            'ctime' => date('Y-m-d H:i:s', $fileInfo->getCTime()),
            'mtime' => date('Y-m-d H:i:s', $fileInfo->getMTime()),
            'media_format' => $metadata['format'],
            'media_codec' => $metadata['codec'],
            'media_resolution' => $metadata['resolution'],
            'media_duration' => $metadata['duration'],
            'exif_date_taken' => $metadata['exif_date_taken'],
            'exif_camera_model' => $metadata['exif_camera_model'],
            'file_category' => $fileInfo->isDir() ? 'Directory' : $category,
            'is_directory' => $fileInfo->isDir() ? 1 : 0,
            'partition_number' => $partitionNumber,
        ];

        if ($calculateMd5) {
            $md5_hash = null;
            // Only calculate hash for files. This can be slow for large files.
            if (!$fileInfo->isDir()) {
                // Using hash_file is memory-efficient for large files.
                $md5_hash = hash_file('md5', $path);
            }
            $params['md5_hash'] = $md5_hash;
        }

        $upsertStmt->execute($params);
        $rowCount = $upsertStmt->rowCount();

        if ($rowCount === 1) { // 1 means a new row was inserted.
            $stats['added']++;
            $stats['deleted']--; // A newly added file can't be a deleted one.
        } elseif ($rowCount === 2) { // 2 means an existing row was updated.
            $stats['updated']++;
            $stats['deleted']--; // This file was found, so it's not deleted.
        }
    }

    // Add a blank line for spacing before the final steps.
    echo "\n";

    // 4. Update the date_updated on the parent drive.
    echo "Step 3: Finalizing scan and updating drive timestamp...\n";
    $stmt = $pdo->prepare("UPDATE st_drives SET date_updated = NOW() WHERE id = ?");
    $stmt->execute([$driveId]);

    // 5. Commit the transaction.
    $pdo->commit();

} catch (\Exception $e) {
    // If anything goes wrong, roll back all changes.
    $pdo->rollBack();
    echo "\nERROR: An exception occurred. Rolling back changes.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

$endTime = microtime(true);
$duration = $endTime - $startTime;

if ($duration < 60) {
    $durationFormatted = round($duration, 2) . " seconds";
} else {
    $minutes = floor($duration / 60);
    $seconds = round($duration % 60);
    $durationFormatted = "{$minutes} minutes, {$seconds} seconds";
}

echo "\n--- Scan Complete ---\n";
echo "Total Items Scanned:  " . number_format($stats['scanned']) . "\n";
echo "New Files Added:      " . number_format($stats['added']) . "\n";
echo "Existing Files Updated: " . number_format($stats['updated']) . "\n";
echo "Files Marked Deleted: " . number_format($stats['deleted']) . "\n";
echo "Scan Duration:        {$durationFormatted}\n";
echo "---------------------\n";

?>