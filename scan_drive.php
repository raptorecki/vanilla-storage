<?php
/**
 * Command-Line Drive Indexing Script
 *
 * This script scans a specified mount point for a given drive ID and partition number,
 * updating the file index in the database. It also attempts to automatically detect
 * and update drive-specific information like model number, serial, and filesystem type.
 *
 * Usage:
 * php scan_drive.php [--no-md5] [--no-drive-info-update] [--no-thumbnails] <drive_id> <partition_number> <mount_point>
 *
 * Arguments:
 *   <drive_id>          : The integer ID of the drive as stored in the `st_drives` table.
 *   <partition_number>  : The integer number of the partition being scanned (e.g., 1 for the first partition).
 *   <mount_point>       : The absolute path to the mount point of the drive/partition to be scanned.
 *
 * Optional Flags:
 *   --no-md5            : Skips MD5 hash calculation for files, which can significantly speed up the scan.
 *   --no-drive-info-update : Skips the automatic update of drive model, serial, and filesystem type
 *                             in the `st_drives` table.
 *   --no-thumbnails     : Skips generating thumbnails for image files.
 *
 * Examples:
 *   php scan_drive.php 5 1 /mnt/my_external_drive
 *   php scan_drive.php --no-md5 5 1 /mnt/my_external_drive
 *   php scan_drive.php --no-drive-info-update --no-thumbnails 5 1 /mnt/my_external_drive
 */

// --- Basic CLI Sanity Checks ---
// Ensure the script is being run from the command line interface (CLI).
// If not, terminate execution with an error message.
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Include necessary external files.
// 'database.php' provides the PDO database connection ($pdo object).
// 'helpers/error_logger.php' provides a function for logging errors.
require_once 'database.php';
require_once 'helpers/error_logger.php';
require_once 'helpers.php';


// --- Argument Parsing ---
$args = $argv;
array_shift($args); // Remove the script name itself.

// --- Configuration ---
$commitInterval = 10; // Commit progress to the database every 10 files.

// Initialize flags with default values.
$calculateMd5 = true;
$updateDriveInfo = true;
$generateThumbnails = true;
$resumeScan = false;
$skipExisting = false;
$debugMode = false; // New debug flag

// Create a mapping for flags to their variables.
$flagMap = [
    '--no-md5' => &$calculateMd5,
    '--no-drive-info-update' => &$updateDriveInfo,
    '--no-thumbnails' => &$generateThumbnails,
    '--resume' => &$resumeScan,
    '--skip-existing' => &$skipExisting,
    '--debug' => &$debugMode, // New debug flag
];

// Process flags.
foreach ($flagMap as $flag => &$variable) {
    if (($key = array_search($flag, $args)) !== false) {
        // For --resume, --skip-existing, and --debug, set to true if present
        if ($flag === '--resume' || $flag === '--skip-existing' || $flag === '--debug') {
            $variable = true;
        } else { // For --no-* flags, set to false if present
            $variable = false;
        }
        unset($args[$key]);
    }
}


// Re-index the arguments array after removing flags.
$args = array_values($args);

// --- Usage and Validation ---
$usage = "Usage: php " . basename(__FILE__) . " [options] <drive_id> <partition_number> <mount_point>\n" .
    "Options:\n" .
    "  --no-md5                Skip MD5 hash calculation for a faster scan.\n" .
    "  --no-drive-info-update  Skip updating drive model, serial, and filesystem type.\n" .
    "  --no-thumbnails         Skip generating thumbnails for image files.\n" .
    "  --resume                Resume an interrupted scan for the specified drive.\n" .
    "  --skip-existing         Skip files that already exist in the database (for adding new files).\n" .
    "  --debug                 Enable verbose debug output.\n" .
    "  --help                  Display this help message.\n";

// Check for --help flag first
if (in_array('--help', $argv)) {
    echo $usage;
    exit(0);
}

if (count($args) < 3) {
    echo $usage;
    exit(1);
}

if ($resumeScan && $skipExisting) {
    echo "Error: The --resume and --skip-existing flags cannot be used together.\n";
    exit(1);
}

// Assign parsed arguments to variables.
$driveId = (int)$args[0];
$partitionNumber = (int)$args[1];
$mountPoint = $args[2];

// Validate drive ID and mount point.
if ($driveId <= 0) {
    echo "Error: Invalid drive_id provided.\n";
    exit(1);
}
if (!is_dir($mountPoint)) {
    echo "Error: Mount point '{$mountPoint}' is not a valid directory.\n";
    exit(1);
}

// --- Scan Initialization ---
$scanId = null;
$lastScannedPath = null;
$stats = [
    'scanned' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0,
    'thumbnails_created' => 0, 'thumbnails_failed' => 0, 'thumbnails_size' => 0,
];

if ($resumeScan) {
    echo "Attempting to resume scan for drive ID {$driveId}...\n";
    $stmt = $pdo->prepare("SELECT * FROM st_scans WHERE drive_id = ? AND status = 'interrupted' ORDER BY scan_date DESC LIMIT 1");
    $stmt->execute([$driveId]);
    $lastScan = $stmt->fetch();

    if ($lastScan) {
        $scanId = $lastScan['scan_id'];
        $lastScannedPath = $lastScan['last_scanned_path'];
        // Load stats from the last scan to continue counting
        $stats['scanned'] = $lastScan['total_items_scanned'];
        $stats['added'] = $lastScan['new_files_added'];
        $stats['updated'] = $lastScan['existing_files_updated'];
        $stats['deleted'] = $lastScan['files_marked_deleted'];
        $stats['thumbnails_created'] = $lastScan['thumbnails_created'];
        $stats['thumbnails_failed'] = $lastScan['thumbnail_creations_failed'];

        echo "  > Resuming scan_id: {$scanId}\n";
        echo "  > Starting from path: " . ($lastScannedPath ?: 'beginning') . "\n";
        
        $updateStatusStmt = $pdo->prepare("UPDATE st_scans SET status = 'running' WHERE scan_id = ?");
        $updateStatusStmt->execute([$scanId]);
    } else {
        echo "  > No interrupted scan found for drive ID {$driveId}. Starting a new scan.\n";
        $resumeScan = false; // Switch back to normal mode
    }
}

if (!$resumeScan) {
    // Create a new scan record
    $insertScanStmt = $pdo->prepare(
        "INSERT INTO st_scans (drive_id, scan_date, status) VALUES (?, NOW(), 'running')"
    );
    $insertScanStmt->execute([$driveId]);
    $scanId = $pdo->lastInsertId();
    echo "Starting new scan (scan_id: {$scanId}) for drive_id: {$driveId} at '{$mountPoint}'...\n";
}

// Global flag to indicate if the script has been interrupted
$GLOBALS['interrupted'] = false;

// --- Interruption Handling ---
// Global variable to hold the scan ID for the shutdown function
$GLOBALS['scanId'] = $scanId;
$GLOBALS['pdo'] = $pdo;
$GLOBALS['current_scanned_path'] = null;

// Define the signal handler
function signal_handler($signo) {
    global $interrupted;
    if ($signo === SIGINT || $signo === SIGTERM) {
        $interrupted = true;
    }
}

// Register the signal handler
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, 'signal_handler'); // Ctrl+C
    pcntl_signal(SIGTERM, 'signal_handler'); // Kill
}

// Define a final shutdown function that handles cleanup
register_shutdown_function(function() use ($scanId, &$pdo) {
    global $interrupted;
    // Check if the script was interrupted or if there was a fatal error
    $error = error_get_last();
    $isFatalError = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]));

    if ($scanId && ($interrupted || $isFatalError)) {
        try {
            // Ensure transaction is rolled back if still active
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Access global current_scanned_path
            global $current_scanned_path;
            $stmt = $pdo->prepare("UPDATE st_scans SET status = 'interrupted', last_scanned_path = ? WHERE scan_id = ? AND status = 'running'");
            $stmt->execute([$current_scanned_path, $scanId]);
            echo "\nScan interrupted. Run with --resume to continue.\n";
        } catch (PDOException $e) {
            // Log the error for debugging
            error_log("PDOException in shutdown function: " . $e->getMessage());
            echo "Error updating scan status during interruption: " . $e->getMessage() . "\n";
        }
    }
    // If the script completed normally, the status would have been set to 'completed' already
    // and $GLOBALS['scanId'] would have been unset.
});

// --- Drive Serial Number and Info Verification ---
echo "Verifying drive serial number...\n";
try {
    // 1. Get the physical device path for the given mount point.
    // Uses `df --output=source` to find the source device (e.g., /dev/sdb1) of the mount point.
    $devicePath = trim(shell_exec("df --output=source " . escapeshellarg($mountPoint) . " | tail -n 1"));
    if (empty($devicePath)) {
        throw new Exception("Could not determine the device for mount point '{$mountPoint}'.");
    }
    echo "  > Mount point '{$mountPoint}' is on device '{$devicePath}'.\n";

    // 2. Determine the parent block device (e.g., /dev/sda from /dev/sda1) for serial number lookup.
    // `lsblk -no pkname` returns the parent device name for a given partition.
    $parentDeviceName = trim(shell_exec("lsblk -no pkname " . escapeshellarg($devicePath)));
    $deviceForSerial = $devicePath; // Default to the original device path.
    if (!empty($parentDeviceName)) {
        $deviceForSerial = "/dev/" . $parentDeviceName; // Construct full path to parent device.
        echo "  > Found parent device '{$deviceForSerial}' for serial number lookup.\n";
    }

    $physicalSerial = '';
    // Attempt to get the serial number using `hdparm` for SATA devices.
    // `hdparm -I` provides detailed ATA identify information, including serial number.
    if (strpos($deviceForSerial, '/dev/sd') === 0) {
        echo "  > Querying serial number with hdparm...\n";
        $hdparm_output = shell_exec("hdparm -I " . escapeshellarg($deviceForSerial) . " 2>/dev/null | grep 'Serial Number:'");
        if (!empty($hdparm_output) && preg_match('/Serial Number:\s*(.*)/', $hdparm_output, $matches)) {
            $physicalSerial = trim($matches[1]);
        }
    } else {
        echo "  > Device '{$deviceForSerial}' is not a standard SATA device (/dev/sdX). Cannot use hdparm.\n";
    }

    // If serial number could not be read, throw an exception.
    if (empty($physicalSerial)) {
        throw new Exception("Could not read serial number from device '{$deviceForSerial}' using hdparm. This can happen with virtual drives, some USB-to-SATA adapters, or if the user lacks permissions. Please ensure the drive has a readable serial number and the script has sufficient privileges (e.g., run with sudo).");
    }
    echo "  > Physical device serial: {$physicalSerial}\n";

    $physicalModel = '';
    // Attempt to get the model number using `hdparm` for SATA devices.
    if (strpos($deviceForSerial, '/dev/sd') === 0) {
        echo "  > Querying model number with hdparm...\n";
        $hdparm_output = shell_exec("hdparm -I " . escapeshellarg($deviceForSerial) . " 2>/dev/null | grep 'Model Number:'");
        if (!empty($hdparm_output) && preg_match('/Model Number:\s*(.*)/', $hdparm_output, $matches)) {
            $physicalModel = trim($matches[1]);
        }
    }
    if (!empty($physicalModel)) {
        echo "  > Physical device model: {$physicalModel}\n";
    }

    $filesystemType = '';
    // Get filesystem type using `lsblk -no FSTYPE` for the specific device path.
    // This is more reliable than `df -T` for getting the actual filesystem type (e.g., ntfs instead of fuseblk).
    $lsblk_fstype_output = shell_exec("lsblk -no FSTYPE " . escapeshellarg($devicePath));
    if (!empty($lsblk_fstype_output)) {
        $filesystemType = trim($lsblk_fstype_output);
    }
    if (!empty($filesystemType)) {
        echo "  > Filesystem type: {$filesystemType}\n";
    }

    // 3. Retrieve the expected serial number, model number, and filesystem from the database
    // for the given drive ID.
    $stmt = $pdo->prepare("SELECT serial, model_number, filesystem FROM st_drives WHERE id = ?");
    $stmt->execute([$driveId]);
    $driveFromDb = $stmt->fetch();

    // If no drive is found in the database with the provided ID, throw an exception.
    if (!$driveFromDb) {
        throw new Exception("No drive found in database with ID {$driveId}.");
    }
    $dbSerial = $driveFromDb['serial'];
    $dbModelNumber = $driveFromDb['model_number'];
    $dbFilesystemType = $driveFromDb['filesystem'];
    echo "  > Database serial for ID {$driveId}: {$dbSerial}\n";

    // 4. Compare the physical serial number with the one stored in the database.
    // If they don't match, issue a warning and prompt for user confirmation to proceed.
    if ($physicalSerial === $dbSerial) {
        echo "  > OK: Serial numbers match.\n\n";
    } else {
        echo "\n!! WARNING: SERIAL NUMBER MISMATCH !!\n";
        echo "The physical drive serial ('{$physicalSerial}') does not match the database serial ('{$dbSerial}').\n";
        echo "Continuing will index the physical drive '{$physicalSerial}' under the database entry for '{$dbSerial}'.\n";
        
        // Loop to get user confirmation.
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

    // Update drive information in the database if the flag is not set to disable it
    // and if the detected information is different from what's already in the DB.
    if ($updateDriveInfo) {
        $updateFields = [];
        $updateParams = [];

        // Check and add serial to update if different.
        if (!empty($physicalSerial) && $physicalSerial !== $dbSerial) {
            $updateFields[] = "serial = ?";
            $updateParams[] = $physicalSerial;
        }
        // Check and add model number to update if different.
        if (!empty($physicalModel) && $physicalModel !== $dbModelNumber) {
            $updateFields[] = "model_number = ?";
            $updateParams[] = $physicalModel;
        }
        // Check and add filesystem type to update if different.
        if (!empty($filesystemType) && $filesystemType !== $dbFilesystemType) {
            $updateFields[] = "filesystem = ?";
            $updateParams[] = $filesystemType;
        }

        // If there are fields to update, construct and execute the UPDATE query.
        if (!empty($updateFields)) {
            $updateSql = "UPDATE st_drives SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $updateParams[] = $driveId;
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($updateParams);
            echo "  > Updated drive information in database.\n";
        }
    }

} catch (Exception $e) {
    // Catch any exceptions during drive verification and log/display the error.
    log_error("Error during drive verification: " . $e->getMessage());
    echo "Error during drive verification. Check logs for details.\n";
    exit(1);
}

// Record the start time of the scan for duration calculation.
$startTime = microtime(true);

echo "Starting scan for drive_id: {$driveId} at '{$mountPoint}'...\n";

// --- Prerequisite Check ---
// Check if `ffprobe` is available for media metadata extraction.
$ffprobePath = trim(@shell_exec('which ffprobe'));
if (empty($ffprobePath)) {
    echo "WARNING: `ffprobe` command not found. Media file metadata (codec, resolution) will not be extracted. Please install FFmpeg to enable this functionality.\n\n";
}

// Check if PHP's `exif` extension is loaded for image EXIF data extraction.
if (!function_exists('exif_read_data')) {
    echo "WARNING: PHP `exif` extension not found. Image EXIF data will not be extracted. Please install `php-exif` to enable this functionality.\n\n";
}

// Check if `exiftool` is available for executable metadata extraction.
$exiftoolPath = trim(@shell_exec('which exiftool'));
if (empty($exiftoolPath)) {
    echo "WARNING: `exiftool` command not found. Executable metadata (Product Name, Product Version) will not be extracted. Please install ExifTool to enable this functionality.\n\n";
}

// Check if PHP's GD extension is loaded for thumbnail generation.
if ($generateThumbnails && !extension_loaded('gd')) {
    echo "WARNING: PHP `gd` extension not found. Thumbnail generation is disabled. Please install `php-gd` to enable this functionality.\n\n";
    $generateThumbnails = false;
}

/**
 * Extracts video metadata using ffprobe.
 * @param string $filePath The full path to the video file.
 * @param string $ffprobePath The path to the ffprobe executable.
 * @return array|null An array with metadata (format, codec, resolution, duration) or null on failure.
 */
function getVideoInfo(string $filePath, string $ffprobePath): ?array
{
    // Construct the ffprobe command to get video stream information in JSON format.
    $command = sprintf(
        '%s -v quiet -print_format json -show_format -show_streams -select_streams v:0 %s',
        $ffprobePath,
        escapeshellarg($filePath)
    );
    $jsonOutput = @shell_exec($command);
    if (empty($jsonOutput)) return null;
    $data = json_decode($jsonOutput, true);
    // Validate JSON decoding and ensure a video stream is present.
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['streams'][0])) return null;
    $stream = $data['streams'][0];
    $format = $data['format'] ?? [];
    // Determine resolution from width and height.
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
 * @return array|null An array with metadata (format, codec, duration) or null on failure.
 */
function getAudioInfo(string $filePath, string $ffprobePath): ?array
{
    // Construct the ffprobe command to get audio stream information in JSON format.
    $command = sprintf(
        '%s -v quiet -print_format json -show_format -show_streams -select_streams a:0 %s',
        $ffprobePath,
        escapeshellarg($filePath)
    );
    $jsonOutput = @shell_exec($command);
    if (empty($jsonOutput)) return null;
    $data = json_decode($jsonOutput, true);
    // Validate JSON decoding and ensure an audio stream is present.
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
        'resolution' => null, // Not applicable for audio files.
        'duration' => isset($format['duration']) ? (float)$format['duration'] : null,
    ];
}

/**
 * Extracts image metadata using native PHP functions.
 * @param string $filePath The full path to the image file.
 * @return array|null An array with metadata (format, resolution, exif_date_taken, exif_camera_model) or null on failure.
 */
function getImageInfo(string $filePath): ?array
{
    // Use getimagesize() to get basic image dimensions and type.
    $imageInfo = @getimagesize($filePath);
    if ($imageInfo === false) {
        return null;
    }

    $exif_data = [];
    // Check for exif extension support and if the image type can contain EXIF data (JPEG/TIFF).
    if (function_exists('exif_read_data') && in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM])) {
        $exif = @exif_read_data($filePath); // Read EXIF data.
        if ($exif !== false) {
            // Prioritize 'DateTimeOriginal' for date taken, fall back to 'DateTime'.
            $exif_data['date_taken'] = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
            $exif_data['camera_model'] = isset($exif['Model']) ? trim($exif['Model']) : null;
        }
    }

    return [
        'format' => image_type_to_mime_type($imageInfo[2]), // Convert image type constant to MIME type.
        'codec' => null, // Not applicable for images.
        'resolution' => $imageInfo[0] . 'x' . $imageInfo[1], // Image width x height.
        'duration' => null,
        'exif_date_taken' => $exif_data['date_taken'] ?? null,
        'exif_camera_model' => $exif_data['camera_model'] ?? null,
    ];
}

/**
 * Extracts all available metadata from a file using exiftool and returns it as a JSON string.
 * @param string $filePath The full path to the file.
 * @param string $exiftoolPath The path to the exiftool executable.
 * @return string|null The full JSON output from exiftool, or null on failure.
 */
function getExiftoolJson(string $filePath, string $exiftoolPath): ?string
{
    // -G: print group name for each tag, -s: short tag names, -json: output in JSON format.
    $command = sprintf(
        '%s -G -s -json %s',
        $exiftoolPath,
        escapeshellarg($filePath)
    );
    $jsonOutput = @shell_exec($command);

    if (empty($jsonOutput)) {
        return null;
    }

    // Basic validation to ensure it's a single JSON object in an array.
    $data = json_decode($jsonOutput, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($data[0])) {
        return $jsonOutput;
    }

    return null;
}

/**
 * Extracts executable metadata (Product Name, Product Version) using exiftool.
 * @param string $filePath The full path to the executable file.
 * @param string $exiftoolPath The path to the exiftool executable.
 * @return array An array with 'product_name' and 'product_version' or null values.
 */
function getExecutableInfo(string $filePath, string $exiftoolPath): array
{
    $productName = null;
    $productVersion = null;

    // Execute exiftool to extract Product Name and Product Version.
    // -s3 suppresses tag names, -ProductVersion and -ProductName specify the tags.
    // -json outputs in JSON format for easier parsing.
    $command = sprintf(
        '%s -s3 -ProductVersion -ProductName -json %s',
        $exiftoolPath,
        escapeshellarg($filePath)
    );
    $jsonOutput = @shell_exec($command);

    if (!empty($jsonOutput)) {
        $data = json_decode($jsonOutput, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data[0])) {
            $productName = $data[0]['ProductName'] ?? null;
            $productVersion = $data[0]['ProductVersion'] ?? null;
        }
    }

    return [
        'product_name' => $productName,
        'product_version' => $productVersion,
    ];
}

/**
 * Queues a file for thumbnail generation.
 *
 * @param PDO $pdo The database connection object.
 * @param int $fileId The ID of the file to queue.
 * @return bool True on success, false on failure.
 */
function queueThumbnail(PDO $pdo, int $fileId): bool
{
    try {
        global $debugMode; // Access the global debugMode variable
        if ($debugMode) { echo "  > DEBUG: Inside queueThumbnail for fileId: {$fileId}\n"; }

        // Check if the file is already in the queue (pending or completed)
        $checkStmt = $pdo->prepare("SELECT 1 FROM st_thumbnail_queue WHERE file_id = ? AND (status = 'pending' OR status = 'completed')");
        $checkStmt->execute([$fileId]);
        if ($checkStmt->fetch()) {
            if ($debugMode) { echo "  > DEBUG: fileId {$fileId} already in queue or completed. Skipping insert.\n"; }
            // File already in queue or thumbnail already generated, no need to re-queue
            return true;
        }

        if ($debugMode) { echo "  > DEBUG: fileId {$fileId} not found in queue. Attempting insert.\n"; }

        $stmt = $pdo->prepare(
            "INSERT INTO st_thumbnail_queue (file_id, status) VALUES (?, 'pending')"
        );
        $result = $stmt->execute([$fileId]);

        if ($debugMode) { echo "  > DEBUG: Insert result for fileId {$fileId}: " . ($result ? 'true' : 'false') . "\n"; }
        return $result;
    } catch (PDOException $e) {
        log_error("Failed to queue thumbnail for file_id {$fileId}: " . $e->getMessage());
        if ($debugMode) { echo "  > DEBUG: PDOException in queueThumbnail for fileId {$fileId}: " . $e->getMessage() . "\n"; }
        return false;
    }
}

// --- File Type Categorization ---
// A map of common file extensions to their general categories.
$extensionMap = [
    // Video extensions
    'mp4' => 'Video', 'mkv' => 'Video', 'mov' => 'Video', 'avi' => 'Video', 'wmv' => 'Video',
    'flv' => 'Video', 'webm' => 'Video', 'mpg' => 'Video', 'mpeg' => 'Video', 'm4v' => 'Video', 'ts' => 'Video',
    // Audio extensions
    'mp3' => 'Audio', 'wav' => 'Audio', 'aac' => 'Audio', 'flac' => 'Audio', 'ogg' => 'Audio',
    'm4a' => 'Audio', 'wma' => 'Audio',
    // Image extensions
    'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image', 'bmp' => 'Image',
    'webp' => 'Image', 'tiff' => 'Image', 'svg' => 'Image',
    // Document extensions
    'pdf' => 'Document', 'doc' => 'Document', 'docx' => 'Document', 'xls' => 'Document', 'xlsx' => 'Document',
    'ppt' => 'Document', 'pptx' => 'Document', 'txt' => 'Document', 'rtf' => 'Document', 'odt' => 'Document',
    // Archive extensions
    'zip' => 'Archive', 'rar' => 'Archive', '7z' => 'Archive', 'tar' => 'Archive', 'gz' => 'Archive',
    // Executable extensions
    'exe' => 'Executable', 'msi' => 'Executable', 'bat' => 'Executable', 'sh' => 'Executable',
];

// --- Main Scanning Logic ---

// A function to commit the current batch of file updates and record progress.
function commit_progress(PDO $pdo, int $scanId, string $lastPath, array $stats): void {
    global $commitInterval;
    static $filesInTransaction = 0;

    $filesInTransaction++;

    if ($filesInTransaction >= $commitInterval) {
        echo "  > Committing progress... ({$stats['scanned']} items scanned)\n";
        $pdo->commit(); // Commit the current transaction

        // Update the scan record with the latest stats
        $updateStmt = $pdo->prepare(
            "UPDATE st_scans SET\n                last_scanned_path = ?, total_items_scanned = ?, new_files_added = ?,\n                existing_files_updated = ?, files_marked_deleted = ?, thumbnails_queued = ?,\n                thumbnail_queueing_failed = ?\n            WHERE scan_id = ?"
        );
        $updateStmt->execute([
            $lastPath, $stats['scanned'], $stats['added'], $stats['updated'],
            $stats['deleted'], $stats['thumbnails_queued'], $stats['thumbnails_failed_to_queue'], $scanId
        ]);

        $filesInTransaction = 0;
        $pdo->beginTransaction(); // Start a new transaction for the next batch
    }
}

// A function to handle I/O errors and attempt to remount the drive.
function attempt_remount(string $mountPoint, string $physicalSerial, string $devicePath): bool {
    echo "!! Filesystem error detected. Attempting to remount!\n";
    $targetDeviceName = basename($devicePath);

    for ($i = 1; $i <= 5; $i++) {
        echo "  > Attempt {$i} of 5!\n";

        // Unmount first, suppress errors if it's already unmounted
        @shell_exec("umount " . escapeshellarg($mountPoint));
        sleep(2);

        // Find the device by serial number
        $lsblk_json = shell_exec('lsblk -o NAME,SERIAL -J');
        $devices = json_decode($lsblk_json, true);
        $deviceToMount = '';

        if ($devices && isset($devices['blockdevices'])) {
            foreach ($devices['blockdevices'] as $device) {
                if (isset($device['serial']) && $device['serial'] === $physicalSerial) {
                    // This is the parent device, we need to find the correct partition.
                    if (!empty($device['children'])) {
                        foreach($device['children'] as $child) {
                            if ($child['name'] === $targetDeviceName) {
                                $deviceToMount = '/dev/' . $child['name'];
                                echo "  > Found matching partition {$deviceToMount} for serial {$physicalSerial}.\n";
                                break 2; // Break out of both foreach loops
                            }
                        }
                        // If we are here, the specific partition was not found, which is an error.
                        echo "  > Error: Device with serial {$physicalSerial} was found, but partition {$targetDeviceName} was not found.\n";
                    } else {
                        // This case handles drives without partitions (e.g. /dev/sdb)
                        if ($device['name'] === $targetDeviceName) {
                            $deviceToMount = '/dev/' . $device['name'];
                            echo "  > Found device {$deviceToMount} for serial {$physicalSerial}.\n";
                            break; // Break from the parent device loop
                        }
                    }
                }
            }
        }

        if (empty($deviceToMount)) {
            echo "  > Could not find device with serial {$physicalSerial} and partition {$targetDeviceName}. Retrying in 5 seconds!\n";
            sleep(5);
            continue;
        }

        // Attempt to mount
        echo "  > Mounting {$deviceToMount} to {$mountPoint}!\n";
        shell_exec("mount " . escapeshellarg($deviceToMount) . " " . escapeshellarg($mountPoint));
        sleep(2);

        // Check if mount was successful
        $df_output = shell_exec("df " . escapeshellarg($mountPoint));
        if (strpos($df_output, $deviceToMount) !== false) {
            echo "  > Remount successful.\n";
            return true;
        } else {
            echo "  > Failed to remount. Retrying in 5 seconds!\n";
            sleep(5);
        }
    }
    return false;
}


try {
    // This is the main transaction for a batch of files.
    $pdo->beginTransaction();

    // Step 1: Mark existing files for deletion check (only for new, non-skip scans)
    $scanStartTimeForDeletion = date('Y-m-d H:i:s');
    if (!$resumeScan && !$skipExisting) {
        echo "Step 1: Marking existing files for deletion check...\n";
        $stmt = $pdo->prepare("UPDATE st_files SET date_deleted = ? WHERE drive_id = ? AND date_deleted IS NULL");
        $stmt->execute([$scanStartTimeForDeletion, $driveId]);
    } else {
        echo "Step 1: Skipped marking files for deletion due to --resume or --skip-existing flag.\n";
    }

    // Step 2: Prepare the main SQL statement for inserting or updating file records.
    $update_clauses = [
        "date_deleted = NULL", "last_scan_id = VALUES(last_scan_id)", "ctime = VALUES(ctime)",
        "mtime = VALUES(mtime)", "size = VALUES(size)", "media_format = VALUES(media_format)",
        "media_codec = VALUES(media_codec)", "media_resolution = VALUES(media_resolution)",
        "media_duration = VALUES(media_duration)", "exif_date_taken = VALUES(exif_date_taken)",
        "exif_camera_model = VALUES(exif_camera_model)", "file_category = VALUES(file_category)",
        "is_directory = VALUES(is_directory)", "partition_number = VALUES(partition_number)",
        "product_name = VALUES(product_name)", "product_version = VALUES(product_version)",
        "exiftool_json = VALUES(exiftool_json)", "thumbnail_path = VALUES(thumbnail_path)"
    ];
    $insert_cols_array = [
        "drive_id", "path", "path_hash", "filename", "size", "ctime", "mtime",
        "file_category", "media_format", "media_codec", "media_resolution",
        "media_duration", "exif_date_taken", "exif_camera_model", "is_directory",
        "partition_number", "product_name", "product_version", "exiftool_json", "thumbnail_path", "date_added", "date_deleted", "last_scan_id"
    ];
    $insert_vals_array = [
        ":drive_id", ":path", ":path_hash", ":filename", ":size", ":ctime", ":mtime",
        ":file_category", ":media_format", ":media_codec", ":media_resolution",
        ":media_duration", ":exif_date_taken", ":exif_camera_model", ":is_directory",
        ":partition_number", ":product_name", ":product_version", ":exiftool_json", ":thumbnail_path", "NOW()", "NULL", ":last_scan_id"
    ];

    if ($calculateMd5) {
        array_splice($insert_cols_array, 5, 0, "md5_hash");
        array_splice($insert_vals_array, 5, 0, ":md5_hash");
        $update_clauses[] = "md5_hash = VALUES(md5_hash)";
    }

    $sql = sprintf(
        "INSERT INTO st_files (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s",
        implode(", ", $insert_cols_array),
        implode(", ", $insert_vals_array),
        implode(",\n            ", $update_clauses)
    );
    $upsertStmt = $pdo->prepare($sql);

    // Step 3: Recursively scan the directory structure.
    $scan_message = $calculateMd5 ? "Scanning filesystem (MD5 hashing may be slow)..." : "Scanning filesystem (skipping MD5 hashing)...";
    echo "Step 2: {$scan_message}\n\n";

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mountPoint, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $termWidth = (int) @shell_exec('tput cols') ?: 80;
    $foundResumePath = ($lastScannedPath === null);

    foreach ($iterator as $fileInfo) {
        $path = $fileInfo->getPathname();
        $relativePath = substr($path, strlen($mountPoint));
        $GLOBALS['current_scanned_path'] = $relativePath; // Update current scanned path

        if (!$foundResumePath) {
            if ($relativePath === $lastScannedPath) {
                $foundResumePath = true;
                echo "  > Resumed scan, found last path. Continuing...\n";
            }
            continue; // Skip until we find the last scanned path
        }

        // Check for interruption flag
        if ($GLOBALS['interrupted']) {
            echo "\nInterruption detected. Exiting scan loop gracefully.\n";
            // The shutdown function will handle marking the scan as interrupted.
            exit(0); // Exit the script immediately
        }

        $stats['scanned']++;

        // --- Skip Existing File Logic ---
        if ($skipExisting) {
            $checkStmt = $pdo->prepare("SELECT 1 FROM st_files WHERE drive_id = ? AND path_hash = ?");
            $checkStmt->execute([$driveId, hash('sha256', $relativePath)]);
            if ($checkStmt->fetch()) {
                // Optional: Add a message to show it's being skipped
                // echo "Skipping existing file: $relativePath\n";
                continue;
            }
        }

        $fileData = null;
        $maxRetries = 5;
        for ($retry = 0; $retry < $maxRetries; $retry++) {
            try {
                // --- Verbose Progress Indicator ---
                $progressMessage = sprintf("[%' 9d] %s", $stats['scanned'], $relativePath);
                if (mb_strlen($progressMessage) > $termWidth) {
                    $progressMessage = mb_substr($progressMessage, 0, $termWidth - 4) . '...';
                }
                echo $progressMessage . "\n";

                // --- Metadata Extraction ---
                $extension = strtolower($fileInfo->getExtension());
                $category = $extensionMap[$extension] ?? 'Other';
                $metadata = ['format' => null, 'codec' => null, 'resolution' => null, 'duration' => null, 'exif_date_taken' => null, 'exif_camera_model' => null, 'product_name' => null, 'product_version' => null];
                $exiftoolJson = null;

                if (!$fileInfo->isDir()) {
                    if (!empty($ffprobePath)) {
                        if ($category === 'Video') $metadata = array_merge($metadata, getVideoInfo($path, $ffprobePath) ?? []);
                        if ($category === 'Audio') $metadata = array_merge($metadata, getAudioInfo($path, $ffprobePath) ?? []);
                    }
                    if ($category === 'Image') $metadata = array_merge($metadata, getImageInfo($path) ?? []);
                    if (!empty($exiftoolPath) && $category === 'Executable') $metadata = array_merge($metadata, getExecutableInfo($path, $exiftoolPath));
                    if (!empty($exiftoolPath)) $exiftoolJson = getExiftoolJson($path, $exiftoolPath);
                }

                $fileData = [
                    'drive_id' => $driveId, 'path' => $relativePath, 'path_hash' => hash('sha256', $relativePath),
                    'filename' => $fileInfo->getFilename(), 'size' => $fileInfo->isDir() ? 0 : $fileInfo->getSize(),
                    'ctime' => date('Y-m-d H:i:s', $fileInfo->getCTime()), 'mtime' => date('Y-m-d H:i:s', $fileInfo->getMTime()),
                    'media_format' => $metadata['format'], 'media_codec' => $metadata['codec'], 'media_resolution' => $metadata['resolution'],
                    'media_duration' => $metadata['duration'], 'exif_date_taken' => $metadata['exif_date_taken'],
                    'exif_camera_model' => $metadata['exif_camera_model'], 'file_category' => $fileInfo->isDir() ? 'Directory' : $category,
                    'is_directory' => $fileInfo->isDir() ? 1 : 0, 'partition_number' => $partitionNumber,
                    'product_name' => $metadata['product_name'], 'product_version' => $metadata['product_version'],
                    'exiftool_json' => $exiftoolJson, 'thumbnail_path' => null, 'last_scan_id' => $scanId,
                ];

                if ($calculateMd5) {
                    $fileData['md5_hash'] = $fileInfo->isDir() ? null : hash_file('md5', $path);
                }

                break; // Success, exit retry loop

            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'stat failed') !== false) {
                    echo "Warning: Caught I/O Error: " . $e->getMessage() . "\n";
                    if (attempt_remount($mountPoint, $physicalSerial, $devicePath)) {
                        echo "  > Retrying file operation...\n";
                        continue; // Retry the operation on the same file
                    } else {
                        echo "Error: Could not recover drive. Aborting.\n";
                        throw new Exception("Drive recovery failed.", 0, $e);
                    }
                } else {
                    throw $e; // Re-throw other exceptions
                }
            }
        }

        if ($fileData === null) {
            echo "Error: Could not process file {$relativePath} after retries. Skipping.\n";
            continue;
        }

        // Execute the prepared upsert statement.
        $upsertStmt->execute($fileData);
        $rowCount = $upsertStmt->rowCount();

        // --- Thumbnail Queueing ---
        if ($generateThumbnails && !$fileInfo->isDir() && $category === 'Image') {
            if ($debugMode) { echo "  > DEBUG: Attempting to queue thumbnail for: {$relativePath}\n"; }
            $fileId = $pdo->lastInsertId();
            if ($debugMode) { echo "  > DEBUG: fileId from lastInsertId(): {$fileId}\n"; }
            // If the row was updated, lastInsertId will be 0. We need to get the ID.
            if ($fileId == 0) {
                $idStmt = $pdo->prepare("SELECT id FROM st_files WHERE drive_id = ? AND path_hash = ?");
                $idStmt->execute([$driveId, hash('sha256', $relativePath)]);
                $fileId = $idStmt->fetchColumn();
                if ($debugMode) { echo "  > DEBUG: fileId from SELECT fallback: {$fileId}\n"; }
            }

            if ($fileId) {
                if ($debugMode) { echo "  > DEBUG: Valid fileId ({$fileId}) obtained. Calling queueThumbnail.\n"; }
                if (queueThumbnail($pdo, $fileId)) {
                    $stats['thumbnails_queued']++;
                } else {
                    $stats['thumbnails_failed_to_queue']++;
                }
            } else {
                if ($debugMode) { echo "  > DEBUG: Invalid fileId ({$fileId}) for {$relativePath}. Skipping thumbnail queueing.\n"; }
            }
        }

        if ($rowCount === 1) $stats['added']++;
        elseif ($rowCount === 2) $stats['updated']++;

        // Commit progress periodically
        commit_progress($pdo, $scanId, $relativePath, $stats);
    }

    // Commit any remaining changes in the last batch
    $pdo->commit();
    echo "\nStep 3: Finalizing scan and updating drive timestamp...\n";
    $stmt = $pdo->prepare("UPDATE st_drives SET date_updated = NOW() WHERE id = ?");
    $stmt->execute([$driveId]);

    // Final step: Mark the scan as completed
    $duration = microtime(true) - $startTime;
    $finalStmt = $pdo->prepare("UPDATE st_scans SET status = 'completed', scan_duration = ? WHERE scan_id = ?");
    $finalStmt->execute([round($duration), $scanId]);
    
    // Unset the global scanId to prevent the shutdown function from marking a completed scan as interrupted
    $GLOBALS['scanId'] = null;

} catch (\Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nERROR: An unrecoverable exception occurred. Scan has been marked as interrupted.\n";
    echo $e->getMessage() . "\n";
    // The shutdown function will handle marking the scan as interrupted.
    exit(1);
}

// --- Final Statistics Calculation and Output ---
$finalStatsStmt = $pdo->prepare("SELECT * FROM st_scans WHERE scan_id = ?");
$finalStatsStmt->execute([$scanId]);
$finalStats = $finalStatsStmt->fetch();

// Recalculate deleted files count if it was a fresh scan
if (!$resumeScan && !$skipExisting) {
    $deletedStmt = $pdo->prepare("SELECT COUNT(*) FROM st_files WHERE drive_id = ? AND date_deleted = ?");
    $deletedStmt->execute([$driveId, $scanStartTimeForDeletion]);
    $finalStats['files_marked_deleted'] = $deletedStmt->fetchColumn();
    
    // Update the final count in the database
    $updateDeletedStmt = $pdo->prepare("UPDATE st_scans SET files_marked_deleted = ? WHERE scan_id = ?");
    $updateDeletedStmt->execute([$finalStats['files_marked_deleted'], $scanId]);
}

$durationFormatted = round($finalStats['scan_duration']) . " seconds";
if ($finalStats['scan_duration'] > 60) {
    $minutes = floor($finalStats['scan_duration'] / 60);
    $seconds = $finalStats['scan_duration'] % 60;
    $durationFormatted = "{$minutes} minutes, {$seconds} seconds";
}

echo "\n--- Scan Complete ---\n";
echo "Total Items Scanned:  " . number_format($finalStats['total_items_scanned']) . "\n";
echo "New Files Added:      " . number_format($finalStats['new_files_added']) . "\n";
echo "Existing Files Updated: " . number_format($finalStats['existing_files_updated']) . "\n";
echo "Files Marked Deleted: " . number_format($finalStats['files_marked_deleted']) . "\n";
if ($generateThumbnails) {
    echo "Thumbnails Queued:   " . number_format($finalStats['thumbnails_queued']) . "\n";
    echo "Thumbnails Failed to Queue:    " . number_format($finalStats['thumbnail_queueing_failed']) . "\n";
}
echo "Scan Duration:        {$durationFormatted}\n";
echo "---------------------\n";