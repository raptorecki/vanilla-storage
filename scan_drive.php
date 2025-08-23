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

// Check for --version flag
if (in_array('--version', $argv)) {
    $versionConfig = require __DIR__ . '/version.php';
    echo basename(__FILE__) . " version " . ($versionConfig['app_version'] ?? 'unknown') . "\n";
    exit(0);
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
$generateThumbnails = true; // Default to true for in-line generation
$useExternalThumbGen = false; // New flag for external generation
$resumeScan = false;
$skipExisting = false;
$debugMode = false; // New debug flag

// Create a mapping for flags to their variables.
$flagMap = [
    '--no-md5' => &$calculateMd5,
    '--no-drive-info-update' => &$updateDriveInfo,
    '--no-thumbnails' => &$generateThumbnails,
    '--use-external-thumb-gen' => &$useExternalThumbGen,
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

// If external thumbnail generation is requested, disable in-line generation.
if ($useExternalThumbGen) {
    $generateThumbnails = false;
}

// Re-index the arguments array after removing flags.
$args = array_values($args);

// --- Usage and Validation ---
$usage = "Usage: php " . basename(__FILE__) . " [options] <drive_id> <partition_number> <mount_point>\n" .
    "Options:\n" .
    "  --no-md5                Skip MD5 hash calculation for a faster scan.\n" .
    "  --no-drive-info-update  Skip updating drive model, serial, and filesystem type.\n" .
    "  --no-thumbnails         Skip generating thumbnails for image files.\n" .
    "  --use-external-thumb-gen Use external script for thumbnail generation (disables in-line).\n" .
    "  --resume                Resume an interrupted scan for the specified drive.\n" .
    "  --skip-existing         Skip files that already exist in the database (for adding new files).\n" .
    "  --debug                 Enable verbose debug output.\n" .
    "  --help                  Display this help message.\n" .
    "  --version               Display the application version.\n";

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
    'scanned' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0,
    
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
        $stats['skipped'] = $lastScan['files_skipped']; // Load skipped count
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
register_shutdown_function(function() use ($scanId) {
    global $interrupted, $pdo, $current_scanned_path; // Access $pdo and $current_scanned_path globally
    // Check if the script was interrupted or if there was a fatal error
    $error = error_get_last();
    $isFatalError = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]));

    if ($scanId && ($interrupted || $isFatalError)) {
        try {
            // Ensure transaction is rolled back if still active
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
                $GLOBALS['interrupted'] = true; // Mark as interrupted
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
    // Manually flag as interrupted so the shutdown function correctly marks the scan status.
    $GLOBALS['interrupted'] = true;
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
 * Parses and validates a date string from EXIF data.
 *
 * - Converts various EXIF date formats (including ISO 8601 with timezones)
 *   to a MySQL-compatible 'Y-m-d H:i:s' format.
 * - Returns null if the date is empty, invalid, or before a reasonable
 *   threshold (1980) to filter out "garbage" dates.
 *
 * @param string|null $dateString The date string from EXIF data.
 * @return string|null The formatted date string or null if invalid.
 */
function parseAndValidateExifDate(?string $dateString): ?string
{
    if (empty($dateString)) {
        return null;
    }

    try {
        // The DateTime constructor is powerful and can parse many formats,
        // including the ISO 8601 format with 'T' and timezone offsets.
        $date = new DateTime($dateString);

        // Sanity check: if the year is before 1980, consider it invalid.
        if ((int)$date->format('Y') < 1980) {
            return null;
        }

        // Return the date in the format MySQL expects.
        return $date->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        // If parsing fails for any reason, return null.
        log_error("Failed to parse date string: '{$dateString}'. Error: " . $e->getMessage());
        return null;
    }
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
            $rawDate = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
            $exif_data['date_taken'] = parseAndValidateExifDate($rawDate); // Validate and format the date
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

/**
 * Generates a nested path for a thumbnail based on the file ID.
 *
 * @param int $fileId The unique ID of the file.
 * @return string The relative path for the thumbnail, e.g., "thumbnails/00/00/12/000012345.jpg".
 */
function getThumbnailPath(int $fileId): string
{
    // Pad the ID to 9 digits with leading zeros
    $paddedId = str_pad($fileId, 9, '0', STR_PAD_LEFT);

    // Create the path parts
    $part1 = substr($paddedId, 0, 2);
    $part2 = substr($paddedId, 2, 2);
    $part3 = substr($paddedId, 4, 2);

    $directoryPath = "thumbnails/{$part1}/{$part2}/{$part3}";

    // The full path to the directory on the filesystem
    $fullDirectoryPath = __DIR__ . '/' . $directoryPath;

    // Ensure the directory exists before saving the file
    if (!is_dir($fullDirectoryPath)) {
        // The 'true' parameter creates nested directories recursively
        if (!mkdir($fullDirectoryPath, 0755, true)) {
            // Handle the error case where directory creation fails
            log_error("Failed to create thumbnail directory: {$fullDirectoryPath}");
            return ''; // Return empty string on failure
        }
    }

    return "{$directoryPath}/{$paddedId}.jpg";
}

/**
 * Creates a thumbnail for an image file.
 * @param string $sourcePath The full path to the source image.
 * @param string $destinationPath The full path to save the thumbnail.
 * @param int $maxWidth The maximum width of the thumbnail.
 * @return bool True on success, false on failure.
 */
function createThumbnail(string $sourcePath, string $destinationPath, int $maxWidth = 400): bool
{
    if (!extension_loaded('gd')) {
        log_error("PHP GD extension not loaded. Cannot create thumbnail.");
        return false;
    }

    // Ensure the destination directory exists
    $dir = dirname($destinationPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            log_error("Failed to create directory for thumbnail: {$dir}");
            return false;
        }
    }

    list($width, $height, $type) = @getimagesize($sourcePath);
    if (!$width || !$height) {
        log_error("Could not get image size for thumbnail: {$sourcePath}");
        return false;
    }

    $newWidth = min($width, $maxWidth);
    $newHeight = floor($height * ($newWidth / $width));

    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    if ($thumb === false) {
        log_error("Failed to create true color image for thumbnail: {$sourcePath}");
        return false;
    }

    $source = null;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = @imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $source = @imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_GIF:
            $source = @imagecreatefromgif($sourcePath);
            break;
        default:
            log_error("Unsupported image type for thumbnail: {$sourcePath}");
            return false; // Unsupported image type
    }

    if ($source === false) {
        log_error("Failed to create image from source for thumbnail: {$sourcePath}");
        return false;
    }

    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $success = imagejpeg($thumb, $destinationPath, 85); // Save as JPEG with 85% quality

    imagedestroy($thumb);
    imagedestroy($source);

    if (!$success) {
        log_error("Failed to save thumbnail image: {$destinationPath}");
    }

    return $success;
}

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
            "UPDATE st_scans SET\n                last_scanned_path = ?, total_items_scanned = ?, new_files_added = ?,\n                existing_files_updated = ?, files_marked_deleted = ?, files_skipped = ?\n WHERE scan_id = ?"
        );
        $updateStmt->execute([
            $lastPath, $stats['scanned'], $stats['added'], $stats['updated'],
            $stats['deleted'], $stats['skipped'], $scanId
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

    // Step 1: File scan will begin. Deleted files will be marked at the end of a completed scan.
echo "Step 1: Beginning filesystem scan...\n";

    // Step 2: Prepare the main SQL statement for inserting or updating file records.
    $update_clauses = [
        "date_deleted = NULL", "last_scan_id = VALUES(last_scan_id)", "ctime = VALUES(ctime)",
        "mtime = VALUES(mtime)", "size = VALUES(size)", "media_format = VALUES(media_format)",
        "media_codec = VALUES(media_codec)", "media_resolution = VALUES(media_resolution)",
        "media_duration = VALUES(media_duration)", "exif_date_taken = VALUES(exif_date_taken)",
        "exif_camera_model = VALUES(exif_camera_model)", "file_category = VALUES(file_category)",
        "is_directory = VALUES(is_directory)", "partition_number = VALUES(partition_number)",
        "product_name = VALUES(product_name)", "product_version = VALUES(product_version)",
        "exiftool_json = VALUES(exiftool_json)", "thumbnail_path = VALUES(thumbnail_path)",
        "filetype = VALUES(filetype)"
    ];
    $insert_cols_array = [
        "drive_id", "path", "path_hash", "filename", "size", "ctime", "mtime",
        "file_category", "media_format", "media_codec", "media_resolution",
        "media_duration", "exif_date_taken", "exif_camera_model", "is_directory",
        "partition_number", "product_name", "product_version", "exiftool_json", "thumbnail_path", "filetype", "date_added", "date_deleted", "last_scan_id"
    ];
    $insert_vals_array = [
        ":drive_id", ":path", ":path_hash", ":filename", ":size", ":ctime", ":mtime",
        ":file_category", ":media_format", ":media_codec", ":media_resolution",
        ":media_duration", ":exif_date_taken", ":exif_camera_model", ":is_directory",
        ":partition_number", ":product_name", ":product_version", ":exiftool_json", ":thumbnail_path", ":filetype", "NOW()", "NULL", ":last_scan_id"
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
                $stats['skipped']++; // Increment the counter
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

                $filetype = null;
                if (!$fileInfo->isDir()) {
                    $filetype = trim(@shell_exec('file -b ' . escapeshellarg($path)));
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
                    'filetype' => $filetype,
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

        // 1. Execute the main database operation
        $upsertStmt->execute($fileData);
        $rowCount = $upsertStmt->rowCount();

        // Update stats based on whether it was an INSERT (1) or UPDATE (2)
        if ($rowCount === 1) $stats['added']++;
        elseif ($rowCount === 2) $stats['updated']++;

        // 2. Display the main progress message first
        echo $progressMessage; // Display what was just scanned

        // 3. Handle thumbnail generation ONLY if necessary
        if ($generateThumbnails && $category === 'Image' && !$fileInfo->isDir()) {
            $fileId = 0;
            $existingThumbnailPath = null;

            // For newly inserted files, get the ID for free
            if ($rowCount === 1) {
                $fileId = $pdo->lastInsertId();
            }
            // For updated files, we must run a SELECT to get the existing ID and thumbnail path
            elseif ($rowCount === 2) {
                $fileIdStmt = $pdo->prepare("SELECT id, thumbnail_path FROM st_files WHERE drive_id = ? AND path_hash = ?");
                $fileIdStmt->execute([$driveId, $fileData['path_hash']]);
                $existingFileData = $fileIdStmt->fetch();
                if ($existingFileData) {
                    $fileId = $existingFileData['id'];
                    $existingThumbnailPath = $existingFileData['thumbnail_path'];
                }
            }

            // 4. If we have a valid fileId, proceed with thumbnailing
            if ($fileId) {
                // If a thumbnail already exists and the file is present, skip creation
                if (!empty($existingThumbnailPath) && file_exists(__DIR__ . '/' . $existingThumbnailPath)) {
                    if ($debugMode) {
                        echo " (Thumb exists)";
                    }
                } else {
                    $thumbnailRelPath = getThumbnailPath($fileId);
                    if (!empty($thumbnailRelPath)) {
                        $thumbDestination = __DIR__ . '/' . $thumbnailRelPath;
                        if (createThumbnail($path, $thumbDestination)) {
                            // Update the file record with the new thumbnail path
                            $updateThumbnailStmt = $pdo->prepare("UPDATE st_files SET thumbnail_path = ? WHERE id = ?");
                            $updateThumbnailStmt->execute([$thumbnailRelPath, $fileId]);
                            $stats['thumbnails_created']++;
                            echo " (Thumb ID: {$fileId})"; // Append thumb info to the line
                            if ($debugMode) {
                                echo " DEBUG: Thumbnail created for {$relativePath} with ID {$fileId}";
                            }
                        } else {
                            $stats['thumbnails_failed']++;
                            if ($debugMode) {
                                echo " DEBUG: Thumbnail creation failed for {$relativePath}";
                            }
                        }
                    } else {
                        $stats['thumbnails_failed']++;
                    }
                }
            }
        }

        // 5. Add a final newline to complete the progress indicator line
        echo "
";

        // Periodically commit the transaction
        commit_progress($pdo, $scanId, $relativePath, $stats);
    }

    // Commit any remaining changes in the last batch
    $pdo->commit();
    echo "\nStep 3: Finalizing scan and updating drive timestamp...\n";
    $stmt = $pdo->prepare("UPDATE st_drives SET date_updated = NOW() WHERE id = ?");
    $stmt->execute([$driveId]);

    // Final step: Mark the scan as completed
    $duration = microtime(true) - $startTime;
    $finalStmt = $pdo->prepare("UPDATE st_scans SET status = 'completed', scan_duration = ?, files_skipped = ? WHERE scan_id = ?");
    $finalStmt->execute([round($duration), $stats['skipped'], $scanId]);

    // New Step: Mark files that were not found in this scan as deleted
    echo "\nStep 4: Marking files not found in this scan as deleted...\n";
    $markDeletedStmt = $pdo->prepare(
        "UPDATE st_files SET date_deleted = NOW() WHERE drive_id = ? AND (last_scan_id != ? OR last_scan_id IS NULL) AND date_deleted IS NULL"
    );
    $markDeletedStmt->execute([$driveId, $scanId]);
    $deletedCount = $markDeletedStmt->rowCount();
    echo "  > Marked {$deletedCount} files as deleted.\n";

    // Update the final stats with the correct deleted count
    $updateDeletedStmt = $pdo->prepare("UPDATE st_scans SET files_marked_deleted = ? WHERE scan_id = ?");
    $updateDeletedStmt->execute([$deletedCount, $scanId]);
    
    // Unset the global scanId to prevent the shutdown function from marking a completed scan as interrupted
    $GLOBALS['scanId'] = null;

} catch (
Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Manually flag as interrupted so the shutdown function correctly marks the scan status.
    $GLOBALS['interrupted'] = true;

    // Construct a detailed error message for logging
    $logMessage = "Unrecoverable exception during scan.";
    if (!empty($GLOBALS['current_scanned_path'])) {
        $logMessage .= " Failing file: " . $GLOBALS['current_scanned_path'] . ".";
    }
    $logMessage .= " Details: " . $e->getMessage();
    log_error($logMessage); // Log the error to application.log

    // Display the error to the console
    echo "\nERROR: An unrecoverable exception occurred. Scan has been marked as interrupted.\n";
    if (!empty($GLOBALS['current_scanned_path'])) {
        echo "Failing file: " . $GLOBALS['current_scanned_path'] . "\n";
    }
    echo "Error details: " . $e->getMessage() . "\n";
    echo "The error has been recorded in application.log.\n";

    // The shutdown function will handle marking the scan as interrupted.
    exit(1);
}

// --- Final Statistics Calculation and Output ---

// First, do a final update of the main scan stats from our in-memory counters
// to ensure the database record is fully up-to-date before we read from it.
$finalStatsUpdateStmt = $pdo->prepare(
    "UPDATE st_scans SET
        total_items_scanned = ?,
        new_files_added = ?,
        existing_files_updated = ?,
        files_skipped = ?,
        thumbnails_created = ?,
        thumbnail_creations_failed = ?
    WHERE scan_id = ?"
);
$finalStatsUpdateStmt->execute([
    $stats['scanned'],
    $stats['added'],
    $stats['updated'],
    $stats['skipped'],
    $stats['thumbnails_created'] ?? 0,
    $stats['thumbnails_failed'] ?? 0,
    $scanId
]);

$finalStatsStmt = $pdo->prepare("SELECT * FROM st_scans WHERE scan_id = ?");
$finalStatsStmt->execute([$scanId]);
$finalStats = $finalStatsStmt->fetch();



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
echo "Existing Files Skipped: " . number_format($finalStats['files_skipped']) . "\n";
echo "Scan Duration:        {$durationFormatted}\n";
echo "---------------------\n";