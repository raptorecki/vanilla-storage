<?php
/**
 * Command-Line Drive Indexing Script
 *
 * This script scans a specified mount point for a given drive ID and partition number,
 * updating the file index in the database. It also attempts to automatically detect
 * and update drive-specific information like model number, serial, and filesystem type.
 *
 * Usage:
 * php scan_drive.php [--no-md5] [--no-drive-info-update] [--no-thumbnails] [--no-exif] [--no-filetype] [--smart-only] <drive_id> <partition_number> <mount_point>
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
 *   --no-exif           : Do not use exiftool to scan files.
 *   --no-filetype       : Do not use 'file' command to determine file type.
 *   --smart-only        : Only retrieves and saves smartctl data for the drive, skipping file scanning.
 *   --safe-delay <microseconds> : Introduces a delay (in microseconds) between I/O operations (exiftool, file command, MD5, thumbnail generation). This can help prevent I/O overload.
 *
 * Examples:
 *   php scan_drive.php 5 1 /mnt/my_external_drive
 *   php scan_drive.php --no-md5 5 1 /mnt/my_external_drive
 *   php scan_drive.php --smart-only 5 1 /mnt/my_external_drive
 */

// --- Basic CLI Sanity Checks ---
// Ensure the script is being run from the command line interface (CLI).
// If not, terminate execution with an error message.
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Check for root/sudo privileges
if (posix_getuid() !== 0) {
    die("This script requires root or sudo privileges to run commands like hdparm and smartctl. Please run with 'sudo php " . basename(__FILE__) . "'.\n");
}

// Include necessary external files.
// 'database.php' provides the PDO database connection ($pdo object).
// 'helpers/error_logger.php' provides a function for logging errors.
require_once 'database.php';
require_once 'helpers/error_logger.php';
require_once 'helpers.php';

$config = require 'config.php';
// Set memory limit from config
if (isset($config['scan_memory_limit']) && !empty($config['scan_memory_limit'])) {
    if (preg_match('/^-?\d+[KMG]?$/i', $config['scan_memory_limit'])) {
        ini_set('memory_limit', $config['scan_memory_limit']);
    } else {
        echo "Warning: Invalid memory limit format in config. Using default.\n";
    }
}

$safeDelayUs = $config['safe_delay_us'] ?? 0;

    /**
     * Manages commit frequency based on time elapsed or record count.
     */
    class CommitManager {
        private $lastCommitTime;
        private $recordCount = 0;
        private $maxSeconds;
        private $maxRecords;
        
        public function __construct($maxSeconds = 8, $maxRecords = 2000) {
            $this->lastCommitTime = microtime(true);
            $this->maxSeconds = $maxSeconds;
            $this->maxRecords = $maxRecords;
        }
        
        public function shouldCommit(): bool {
            $timeElapsed = microtime(true) - $this->lastCommitTime;
            return $timeElapsed >= $this->maxSeconds || 
                   $this->recordCount >= $this->maxRecords;
        }

        public function recordProcessed(): void {
            $this->recordCount++;
        }

        public function reset(): void {
            $this->lastCommitTime = microtime(true);
            $this->recordCount = 0;
        }
    }




// --- Argument Parsing ---
$args = $argv;
array_shift($args); // Remove the script name itself.

// --- Configuration ---


// Initialize flags with default values.
$calculateMd5 = true;
$updateDriveInfo = true;
$generateThumbnails = true; // Default to true for in-line generation
$useExternalThumbGen = false; // New flag for external generation
$resumeScan = false;
$skipExisting = false;
$debugMode = false; // New debug flag
$smartOnly = false; // New flag for smartctl only scan
$useExiftool = true;
$useFiletype = true;
$safeDelayUs = 0; // Default value, will be overridden by config or CLI arg

$usage = "Usage: php " . basename(__FILE__) . " [options] <drive_id> <partition_number> <mount_point>\n" .
    "Options:\n" .
    "  --no-md5                Skip MD5 hash calculation for a faster scan.\n" .
    "  --no-drive-info-update  Skip updating drive model, serial, and filesystem type.\n" .
    "  --no-thumbnails         Skip generating thumbnails for image files.\n" .
    "  --no-exif               Do not use exiftool to scan files.\n" .
    "  --no-filetype           Do not use 'file' command to determine file type.\n" .
    "  --use-external-thumb-gen Use external script for thumbnail generation (disables in-line).\n" .
    "  --resume                Resume an interrupted scan for the specified drive.\n" .
    "  --skip-existing         Skip files that already exist in the database (for adding new files).\n" .
    "  --debug                 Enable verbose debug output.\n" .
    "  --smart-only            Only retrieve and save smartctl data, skipping file scanning.\n" .
    "  --safe-delay <microseconds> Introduce a delay between I/O operations (e.g., 100000 for 0.1 seconds).\n" .
    "  --help                  Display this help message.\n" .
    "  --version               Display the application version.\n";

// Process flags.
while (isset($args[0]) && strpos($args[0], '--') === 0) {
    $arg = array_shift($args);

    switch ($arg) {
        case '--no-md5':
            $calculateMd5 = false;
            break;
        case '--no-drive-info-update':
            $updateDriveInfo = false;
            break;
        case '--no-thumbnails':
            $generateThumbnails = false;
            break;
        case '--no-exif':
            $useExiftool = false;
            break;
        case '--no-filetype':
            $useFiletype = false;
            break;
        case '--use-external-thumb-gen':
            $useExternalThumbGen = true;
            break;
        case '--resume':
            $resumeScan = true;
            break;
        case '--skip-existing':
            $skipExisting = true;
            break;
        case '--debug':
            $debugMode = true;
            break;
        case '--smart-only':
            $smartOnly = true;
            break;
        case '--safe-delay':
            if (!isset($args[0]) || !is_numeric($args[0])) {
                echo "Error: --safe-delay requires a numeric value (microseconds).\n";
                exit(1);
            }
            $safeDelayUs = (int)array_shift($args);
            break;
        case '--help':
            echo $usage;
            exit(0);
        case '--version':
            $versionConfig = require __DIR__ . '/version.php';
            echo basename(__FILE__) . " version " . ($versionConfig['app_version'] ?? 'unknown') . "\n";
            exit(0);
        default:
            echo "Error: Unknown option '{$arg}'\n";
            exit(1);
    }
}

// Re-index the arguments array after removing flags.
$args = array_values($args);

// --- Usage and Validation ---
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

// Validate read/write permissions for the mount point
if (!is_readable($mountPoint) || !is_writable($mountPoint)) {
    echo "Error: Insufficient permissions to read from or write to mount point '{$mountPoint}'. Please ensure the script has appropriate read/write access.\n";
    exit(1);
}

// --- Scan Initialization (for full scans) ---
$scanId = null;
$lastScannedPath = null;
$stats = [
    'scanned' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0,
    'thumbnails_created' => 0, 'thumbnails_failed' => 0,
];

// Global flag to indicate if the script has been interrupted
$GLOBALS['interrupted'] = false;

// --- Interruption Handling ---
// Global variable to hold the scan ID for the shutdown function
$GLOBALS['scanId'] = null; // Will be set if a full scan is initiated
$GLOBALS['pdo'] = $pdo;
$GLOBALS['current_scanned_path'] = null;

// Define the signal handler
function signal_handler($signo) {
    if ($signo === SIGINT || $signo === SIGTERM) {
        $GLOBALS['interrupted'] = true;
    }
}

// Register the signal handler
// Check if the PCNTL extension is loaded to prevent fatal errors on systems where it's not available.
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, 'signal_handler'); // Ctrl+C
    pcntl_signal(SIGTERM, 'signal_handler'); // Kill
}

// Define a final shutdown function that handles cleanup
register_shutdown_function(function() {
    // Check if the script was interrupted or if there was a fatal error
    $error = error_get_last();
    $isFatalError = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]));

    // Only update scan status if a scanId was actually created (i.e., not a smart-only run)
    if (!empty($GLOBALS['scanId']) && ($GLOBALS['interrupted'] || $isFatalError)) {
        try {
            // Ensure transaction is rolled back if still active
            if ($GLOBALS['pdo']->inTransaction()) {
                $GLOBALS['pdo']->rollBack();
            }
            $stmt = $GLOBALS['pdo']->prepare("UPDATE st_scans SET status = 'interrupted', last_scanned_path = ? WHERE scan_id = ? AND status = 'running'");
            $stmt->execute([$GLOBALS['current_scanned_path'], $GLOBALS['scanId']]);
            echo "\nScan interrupted. Run with --resume to continue.\n";
        } catch (PDOException $e) {
            // Log the error for debugging
            error_log("PDOException in shutdown function: " . $e->getMessage());
            echo "Error updating scan status during interruption: " . $e->getMessage() . "\n";
        }
    }
});

// --- Drive Serial Number and Info Verification (Always runs) ---
echo "Verifying drive serial number...\n";
try {
    // 1. Get the physical device path for the given mount point.
    $devicePath = trim(shell_exec("df --output=source " . escapeshellarg($mountPoint) . " | tail -n 1"));
    if (empty($devicePath)) {
        throw new Exception("Could not determine the device for mount point '{$mountPoint}'.");
    }
    echo "  > Mount point '{$mountPoint}' is on device '{$devicePath}'.\n";

    // 2. Determine the parent block device
    $parentDeviceName = trim(shell_exec("lsblk -no pkname " . escapeshellarg($devicePath)));
    $deviceForSerial = $devicePath;
    if (!empty($parentDeviceName)) {
        $deviceForSerial = "/dev/" . $parentDeviceName;
        echo "  > Found parent device '{$deviceForSerial}' for serial number lookup.\n";
    }

    $physicalSerial = '';
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

    $physicalModel = '';
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

    // --- Get smartctl output (Always runs) ---
    $smartctlOutput = '';
    echo "  > Querying SMART data with smartctl...\n";
    $smartctlCommand = "smartctl -a " . escapeshellarg($deviceForSerial);
    $smartctlResult = shell_exec($smartctlCommand . " 2>&1");

    if (strpos($smartctlResult, 'Unknown USB bridge') !== false && strpos($smartctlResult, 'Please specify device type with the -d option.') !== false) {
        echo "  > Detected USB bridge, retrying with -d sat...\n";
        $smartctlCommand = "smartctl -a -d sat " . escapeshellarg($deviceForSerial);
        $smartctlResult = shell_exec($smartctlCommand . " 2>&1");
    }

    if (!empty($smartctlResult)) {
        $smartctlOutput = trim($smartctlResult);
        echo "  > SMART data retrieved.\n";
    } else {
        echo "  > Could not retrieve SMART data.\n";
    }

    // Insert smartctl output into st_smart table (Always runs)
    if (!empty($smartctlOutput)) {
        $insertSmartStmt = $pdo->prepare("INSERT INTO st_smart (drive_id, output) VALUES (?, ?)");
        $insertSmartStmt->execute([$driveId, $smartctlOutput]);
        echo "  > SMART data saved to st_smart table.\n";
    }

    // --- If --smart-only is set, exit here ---
    if ($smartOnly) {
        echo "\n--smart-only flag detected. Skipping file scan.\n";
        echo "SMART data collection complete.\n";
        exit(0); // Exit gracefully
    }

    $filesystemType = '';
    $lsblk_fstype_output = shell_exec("lsblk -no FSTYPE " . escapeshellarg($devicePath));
    if (!empty($lsblk_fstype_output)) {
        $filesystemType = trim($lsblk_fstype_output);
    }
    if (!empty($filesystemType)) {
        echo "  > Filesystem type: {$filesystemType}\n";
    }

    $stmt = $pdo->prepare("SELECT serial, model_number, filesystem FROM st_drives WHERE id = ?");
    $stmt->execute([$driveId]);
    $driveFromDb = $stmt->fetch();

    if (!$driveFromDb) {
        throw new Exception("No drive found in database with ID {$driveId}.");
    }
    $dbSerial = $driveFromDb['serial'];
    $dbModelNumber = $driveFromDb['model_number'];
    $dbFilesystemType = $driveFromDb['filesystem'];
    echo "  > Database serial for ID {$driveId}: {$dbSerial}\n";

    if ($physicalSerial === $dbSerial) {
        echo "  > OK: Serial numbers match.\n\n";
    } else {
        echo "\n!! WARNING: SERIAL NUMBER MISMATCH !!\n";
        echo "The physical drive serial ('{$physicalSerial}') does not match the database serial ('{$dbSerial}').\n";
        echo "Continuing will index the physical drive '{$physicalSerial}' under the database entry for '{$dbSerial}'.\n";
        
        while (true) {
            echo "Are you sure you want to continue? (yes/no): ";
            $line = strtolower(trim(fgets(STDIN)));
            if ($line === 'yes') {
                echo "\nUser confirmed. Continuing with scan...\n\n";
                break;
            } elseif ($line === 'no') {
                echo "Aborting scan.\n";
                $GLOBALS['interrupted'] = true;
                exit(2);
            }
        }
    }

    if ($updateDriveInfo) {
        $updateFields = [];
        $updateParams = [];

        if (!empty($physicalSerial) && $physicalSerial !== $dbSerial) {
            $updateFields[] = "serial = ?";
            $updateParams[] = $physicalSerial;
        }
        if (!empty($physicalModel) && $physicalModel !== $dbModelNumber) {
            $updateFields[] = "model_number = ?";
            $updateParams[] = $physicalModel;
        }
        if (!empty($filesystemType) && $filesystemType !== $dbFilesystemType) {
            $updateFields[] = "filesystem = ?";
            $updateParams[] = $filesystemType;
        }

        if (!empty($updateFields)) {
            $updateSql = "UPDATE st_drives SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $updateParams[] = $driveId;
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($updateParams);
            echo "  > Updated drive information in database.\n";
        }
    }

} catch (Exception $e) {
    log_error("Error during drive verification: " . $e->getMessage());
    echo "Error during drive verification. Check logs for details.\n";
    $GLOBALS['interrupted'] = true;
    exit(1);
}    

// --- Full Scan Logic (only if --smart-only is NOT set) ---
if (!$smartOnly) {
    if ($resumeScan) {
        echo "Attempting to resume scan for drive ID {$driveId}...\n";
        $stmt = $pdo->prepare("SELECT * FROM st_scans WHERE drive_id = ? AND status = 'interrupted' ORDER BY scan_date DESC LIMIT 1");
        $stmt->execute([$driveId]);
        $lastScan = $stmt->fetch();

        if ($lastScan) {
            $scanId = $lastScan['scan_id'];
            $lastScannedPath = $lastScan['last_scanned_path'];
            $stats['scanned'] = $lastScan['total_items_scanned'];
            $stats['added'] = $lastScan['new_files_added'];
            $stats['updated'] = $lastScan['existing_files_updated'];
            $stats['deleted'] = $lastScan['files_marked_deleted'];
            $stats['skipped'] = $lastScan['files_skipped'];
            $stats['thumbnails_created'] = $lastScan['thumbnails_created'];
            $stats['thumbnails_failed'] = $lastScan['thumbnail_creations_failed'];

            echo "  > Resuming scan_id: {$scanId}\n";
            echo "  > Starting from path: " . ($lastScannedPath ?: 'beginning') . "\n";
            
            $updateStatusStmt = $pdo->prepare("UPDATE st_scans SET status = 'running' WHERE scan_id = ?");
            $updateStatusStmt->execute([$scanId]);
        } else {
            echo "  > No interrupted scan found for drive ID {$driveId}. Starting a new scan.\n";
            $resumeScan = false;
        }
    }

    if (!$resumeScan) {
        $insertScanStmt = $pdo->prepare(
            "INSERT INTO st_scans (drive_id, scan_date, status) VALUES (?, NOW(), 'running')"
        );
        $insertScanStmt->execute([$driveId]);
        $scanId = $pdo->lastInsertId();
        echo "Starting new scan (scan_id: {$scanId}) for drive_id: {$driveId} at '{$mountPoint}'...\n";
    }
    $GLOBALS['scanId'] = $scanId; // Set global scanId for shutdown function

    $startTime = microtime(true);

    $commitManager = new CommitManager();

    echo "Starting scan for drive_id: {$driveId} at '{$mountPoint}'...\n";

    // --- Prerequisite Check ---
    $ffprobePath = trim(@shell_exec('which ffprobe'));
    if (empty($ffprobePath)) {
        echo "WARNING: `ffprobe` command not found. Media file metadata (codec, resolution) will not be extracted. Please install FFmpeg to enable this functionality.\n\n";
    }

    if (!function_exists('exif_read_data')) {
        echo "WARNING: PHP `exif` extension not found. Image EXIF data will not be extracted. Please install `php-exif` to enable this functionality.\n\n";
    }

    $exiftoolPath = trim(@shell_exec('which exiftool'));
    if (empty($exiftoolPath)) {
        echo "WARNING: `exiftool` command not found. Executable metadata (Product Name, Product Version) will not be extracted. Please install ExifTool to enable this functionality.\n\n";
    }

    if ($generateThumbnails && !extension_loaded('gd')) {
        echo "WARNING: PHP `gd` extension not loaded. Thumbnail generation is disabled. Please install `php-gd` to enable this functionality.\n\n";
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
        $command = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams -select_streams v:0 %s
',
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
     * @return array|null An array with metadata (format, codec, duration) or null on failure.
     */
    function getAudioInfo(string $filePath, string $ffprobePath): ?array
    {
        $command = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams -select_streams a:0 %s
',
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
            'resolution' => null,
            'duration' => isset($format['duration']) ? (float)$format['duration'] : null,
        ];
    }

    /**
     * Parses and validates a date string from EXIF data.
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
            $date = new DateTime($dateString);
            if ((int)$date->format('Y') < 1970) {
                return null;
            }
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
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
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo === false) {
            return null;
        }

        $exif_data = [];
        if (function_exists('exif_read_data') && in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM])) {
            $exif = @exif_read_data($filePath);
            if ($exif !== false) {
                $rawDate = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
                $exif_data['date_taken'] = parseAndValidateExifDate($rawDate);
                $exif_data['camera_model'] = isset($exif['Model']) ? trim($exif['Model']) : null;
            }
        }

        return [
            'format' => image_type_to_mime_type($imageInfo[2]),
            'codec' => null,
            'resolution' => $imageInfo[0] . 'x' . $imageInfo[1],
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
    class ExiftoolManager {
        private $proc;
        private $pipes;

        public function __construct(string $exiftoolPath) {
            $descriptorSpec = [
                0 => ["pipe", "r"],  // stdin
                1 => ["pipe", "w"],  // stdout
                2 => ["pipe", "w"]   // stderr
            ];

            $command = sprintf('%s -charset filename=UTF8 -stay_open True -@ -', escapeshellarg($exiftoolPath));

            $this->proc = proc_open($command, $descriptorSpec, $this->pipes);

            if (!is_resource($this->proc)) {
                throw new Exception("Failed to open exiftool process.");
            }
        }

        public function getMetadata(string $filePath): ?array {
            fwrite($this->pipes[0], "-G\n-s\n-json\n");
            fwrite($this->pipes[0], "$filePath\n");
            fwrite($this->pipes[0], "-execute\n");

            $output = '';
            while (true) {
                $line = fgets($this->pipes[1]);
                if ($line === false || trim($line) === '{ready}') {
                    break;
                }
                $output .= $line;
            }

            $data = json_decode($output, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data; // Return the full array
            }

            return null;
        }

        public function __destruct() {
            if (is_resource($this->proc)) {
                fwrite($this->pipes[0], "-stay_open\nFalse\n");
                fclose($this->pipes[0]);
                fclose($this->pipes[1]);
                fclose($this->pipes[2]);
                proc_close($this->proc);
            }
        }
    }

    


    // --- File Type Categorization ---
    $extensionMap = [
        'mp4' => 'Video', 'mkv' => 'Video', 'mov' => 'Video', 'avi' => 'Video', 'wmv' => 'Video',
        'flv' => 'Video', 'webm' => 'Video', 'mpg' => 'Video', 'mpeg' => 'Video', 'm4v' => 'Video', 'ts' => 'Video',
        'mp3' => 'Audio', 'wav' => 'Audio', 'aac' => 'Audio', 'flac' => 'Audio', 'ogg' => 'Audio',
        'm4a' => 'Audio', 'wma' => 'Audio',
        'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image', 'bmp' => 'Image',
        'webp' => 'Image', 'tiff' => 'Image', 'svg' => 'Image',
        'pdf' => 'Document', 'doc' => 'Document', 'docx' => 'Document', 'xls' => 'Document', 'xlsx' => 'Document',
        'ppt' => 'Document', 'pptx' => 'Document', 'txt' => 'Document', 'rtf' => 'Document', 'odt' => 'Document',
        'zip' => 'Archive', 'rar' => 'Archive', '7z' => 'Archive', 'tar' => 'Archive', 'gz' => 'Archive',
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
        $paddedId = str_pad($fileId, 9, '0', STR_PAD_LEFT);
        $part1 = substr($paddedId, 0, 2);
        $part2 = substr($paddedId, 2, 2);
        $part3 = substr($paddedId, 4, 2);
        $directoryPath = "thumbnails/{$part1}/{$part2}/{$part3}";
        $fullDirectoryPath = __DIR__ . '/'.$directoryPath;

        if (!is_dir($fullDirectoryPath)) {
            if (!mkdir($fullDirectoryPath, 0755, true)) {
                log_error("Failed to create thumbnail directory: {$fullDirectoryPath}");
                return '';
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
                return false;
        }

        if ($source === false) {
            log_error("Failed to create image from source for thumbnail: {$sourcePath}");
            return false;
        }

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $success = imagejpeg($thumb, $destinationPath, 85);

        imagedestroy($thumb);
        imagedestroy($source);

        if (!$success) {
            log_error("Failed to save thumbnail image: {$destinationPath}");
        }

        return $success;
    }

    // --- Main Scanning Logic ---

        function commit_progress(PDO $pdo, int $scanId, string $lastPath, array $stats, CommitManager $commitManager): void {
        // The CommitManager handles the logic of when to commit.
        // This function is now only responsible for the actual commit operation and updating scan stats.
        echo "  > Committing progress... ({$stats['scanned']} items scanned)\n";
        $pdo->commit();

        $updateStmt = $pdo->prepare(
            "UPDATE st_scans SET
                last_scanned_path = ?, total_items_scanned = ?, new_files_added = ?,
                existing_files_updated = ?, files_marked_deleted = ?, files_skipped = ?
 WHERE scan_id = ?"
        );
        $updateStmt->execute([
            $lastPath, $stats['scanned'], $stats['added'],
            $stats['updated'],
            $stats['deleted'], $stats['skipped'], $scanId
        ]);

        $pdo->beginTransaction();
        $commitManager->reset(); // Reset the commit manager after a successful commit
    }

    function attempt_remount(string $mountPoint, string $physicalSerial, string $devicePath): bool {
        echo "!! Filesystem error detected. Attempting to remount!\n";
        $targetDeviceName = basename($devicePath);

        for ($i = 1; $i <= 5; $i++) {
            echo "  > Attempt {$i} of 5!\n";

            @shell_exec("umount " . escapeshellarg($mountPoint));
            sleep(2);

            $lsblk_json = shell_exec('lsblk -o NAME,SERIAL -J');
            $devices = json_decode($lsblk_json, true);
            $deviceToMount = '';

            if ($devices && isset($devices['blockdevices'])) {
                foreach ($devices['blockdevices'] as $device) {
                    if (isset($device['serial']) && $device['serial'] === $physicalSerial) {
                        if (!empty($device['children'])) {
                            foreach($device['children'] as $child) {
                                if ($child['name'] === $targetDeviceName) {
                                    $deviceToMount = '/dev/' . $child['name'];
                                    echo "  > Found matching partition {$deviceToMount} for serial {$physicalSerial}.\n";
                                    break 2;
                                }
                            }
                            echo "  > Error: Device with serial {$physicalSerial} was found, but partition {$targetDeviceName} was not found.\n";
                        } else {
                            if ($device['name'] === $targetDeviceName) {
                                $deviceToMount = '/dev/' . $device['name'];
                                echo "  > Found device {$deviceToMount} for serial {$physicalSerial}.\n";
                                break;
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

            echo "  > Mounting {$deviceToMount} to {$mountPoint}!\n";
            shell_exec("mount " . escapeshellarg($deviceToMount) . " " . escapeshellarg($mountPoint));
            sleep(2);

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
        $pdo->beginTransaction();

        echo "Step 1: Beginning filesystem scan...\n";

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

        // Prepare statement for checking existing file data for MD5 optimization
        $stmtCheckExisting = $pdo->prepare("SELECT md5_hash, mtime, size FROM st_files WHERE drive_id = ? AND path_hash = ?");

        echo "Step 2: " . ($calculateMd5 ? "Scanning filesystem (MD5 hashing may be slow)..." : "Scanning filesystem (skipping MD5 hashing).\n\n");

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($mountPoint, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $termWidth = (int) @shell_exec('tput cols') ?: 80;
        $foundResumePath = ($lastScannedPath === null);

        $exiftoolManager = null;
        if ($useExiftool && !empty($exiftoolPath)) {
            $exiftoolManager = new ExiftoolManager($exiftoolPath);
        }

        foreach ($iterator as $fileInfo) {
            $path = $fileInfo->getPathname();
            $relativePath = substr($path, strlen($mountPoint));
            // Ensure directories have a trailing slash
            if ($fileInfo->isDir() && substr($relativePath, -1) !== '/') {
                $relativePath .= '/';
            }
            // Ensure relativePath always starts with a leading slash
            if (substr($relativePath, 0, 1) !== '/') {
                $relativePath = '/' . $relativePath;
            }
            $GLOBALS['current_scanned_path'] = $relativePath;

            // After path construction, add validation:
            if ($debugMode) {
                echo "DEBUG: Constructed path: '{$relativePath}', filename: '{$fileInfo->getFilename()}'\n";
            }

            // Ensure path consistency
            if (!preg_match('/^\//', $relativePath)) {
                throw new Exception("Path construction error: missing leading slash in '{$relativePath}'");
            }

            if ($fileInfo->isDir() && !preg_match('/\/$/', $relativePath)) {
                throw new Exception("Directory path construction error: missing trailing slash in '{$relativePath}'");
            }

            if (!$foundResumePath) {
                if ($relativePath === $lastScannedPath) {
                    $foundResumePath = true;
                    echo "  > Resumed scan, found last path. Processing this file and continuing...\n";
                } else {
                    continue; // Skip files until we find the resume point
                }
            }

            if ($GLOBALS['interrupted']) {
                echo "\nInterruption detected. Exiting scan loop gracefully.\n";
                exit(0);
            }

            $stats['scanned']++;
            $commitManager->recordProcessed();

            if ($skipExisting) {
                $checkStmt = $pdo->prepare("SELECT 1 FROM st_files WHERE drive_id = ? AND path_hash = ?");
                $checkStmt->execute([$driveId, hash('sha256', $relativePath)]);
                if ($checkStmt->fetch()) {
                    $stats['skipped']++;
                    continue;
                }
            }

            $fileData = null;
            $maxRetries = 5;
            for ($retry = 0; $retry < $maxRetries; $retry++) {
                try {
                    $progressMessage = sprintf("[%' 9d] %s", $stats['scanned'], $relativePath);
                    if (mb_strlen($progressMessage) > $termWidth) {
                        $progressMessage = mb_substr($progressMessage, 0, $termWidth - 4) . '...';
                    }

                    $extension = strtolower($fileInfo->getExtension());
                    $category = $extensionMap[$extension] ?? 'Other';
                    $metadata = ['format' => null, 'codec' => null, 'resolution' => null, 'duration' => null, 'exif_date_taken' => null, 'exif_camera_model' => null, 'product_name' => null, 'product_version' => null];
                    $exiftoolJson = null;

                    if (!$fileInfo->isDir()) {
                        if ($useExiftool && $exiftoolManager) {
                            $exiftoolData = $exiftoolManager->getMetadata($path);
                            if ($exiftoolData) {
                                $exiftoolJson = json_encode($exiftoolData);
                                $metadata['product_name'] = $exiftoolData['ProductName'] ?? null;
                                $metadata['product_version'] = $exiftoolData['ProductVersion'] ?? null;
                            }
                        }
                        if ($safeDelayUs > 0) usleep($safeDelayUs);

                        if (!empty($ffprobePath)) {
                            if ($category === 'Video') {
                                $metadata = array_merge($metadata, getVideoInfo($path, $ffprobePath) ?? []);
                                if ($safeDelayUs > 0) usleep($safeDelayUs);
                            }
                            if ($category === 'Audio') {
                                $metadata = array_merge($metadata, getAudioInfo($path, $ffprobePath) ?? []);
                                if ($safeDelayUs > 0) usleep($safeDelayUs);
                            }
                        }
                        if ($category === 'Image') {
                            $metadata = array_merge($metadata, getImageInfo($path) ?? []);
                            if ($safeDelayUs > 0) usleep($safeDelayUs);
                        }
                    }

                    $filetype = null;
                    if ($useFiletype && !$fileInfo->isDir()) {
                        $filetype = trim(@shell_exec('file -b ' . escapeshellarg($path) . ' 2>/dev/null'));
                        if ($safeDelayUs > 0) usleep($safeDelayUs);
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
                        $currentMtime = $fileInfo->getMTime();
                        $currentSize = $fileInfo->getSize();

                        // Check if the file exists in the database and its mtime and size match
                        $stmtCheckExisting->execute([$driveId, hash('sha256', $relativePath)]);
                        $existingFileData = $stmtCheckExisting->fetch(PDO::FETCH_ASSOC);

                        if ($existingFileData &&
                            $existingFileData['mtime'] == date('Y-m-d H:i:s', $currentMtime) &&
                            $existingFileData['size'] == $currentSize) {
                            // File hasn't changed, use existing hash
                            $fileData['md5_hash'] = $existingFileData['md5_hash'];
                        } else {
                            // File has changed or is new, calculate new hash
                            $fileData['md5_hash'] = $fileInfo->isDir() ? null : hash_file('md5', $path);
                            if ($safeDelayUs > 0) usleep($safeDelayUs);
                        }
                    }

                    break;

                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'stat failed') !== false) {
                        echo "Warning: Caught I/O Error: " . $e->getMessage() . "\n";
                        if (attempt_remount($mountPoint, $physicalSerial, $devicePath)) {
                            echo "  > Retrying file operation...\n";
                            continue;
                        } else {
                            echo "Error: Could not recover drive. Aborting.\n";
                            throw new Exception("Drive recovery failed.", 0, $e);
                        }
                    } else {
                        throw $e;
                    }
                }
            }

            if ($fileData === null) {
                echo "Error: Could not process file {$relativePath} after retries. Skipping.\n";
                continue;
            }

            $upsertStmt->execute($fileData);
            $rowCount = $upsertStmt->rowCount();

            $lastInsertId = $pdo->lastInsertId();

            if ($lastInsertId > 0) { // This was an insert
                $stats['added']++;
                $fileId = $lastInsertId;
            } else { // This was an update or no-op
                if ($rowCount > 0) {
                    $stats['updated']++;
                }
                // Fetch the existing file ID and thumbnail path
                $stmt = $pdo->prepare("SELECT id, thumbnail_path FROM st_files WHERE drive_id = ? AND path_hash = ?");
                $stmt->execute([$driveId, $fileData['path_hash']]);
                $result = $stmt->fetch();
                $fileId = $result ? $result['id'] : null;
                $existingThumbnailPath = $result ? $result['thumbnail_path'] : null;
            }

            echo $progressMessage;

            if ($generateThumbnails && $category === 'Image' && !$fileInfo->isDir()) {
                // $fileId and $existingThumbnailPath are already set by the new logic above
                // No need for the internal if/elseif ($rowCount === 1) block anymore

                if ($fileId) {
                    if (!empty($existingThumbnailPath) && file_exists(__DIR__ . '/' . $existingThumbnailPath)) {
                        if ($debugMode) {
                            echo " (Thumb exists)";
                        }
                    } else {
                        $thumbnailRelPath = getThumbnailPath($fileId);
                        if (!empty($thumbnailRelPath)) {
                            $thumbDestination = __DIR__ . '/' . $thumbnailRelPath;
                            if (createThumbnail($path, $thumbDestination)) {
                                $updateThumbnailStmt = $pdo->prepare("UPDATE st_files SET thumbnail_path = ? WHERE id = ?");
                                $updateThumbnailStmt->execute([$thumbnailRelPath, $fileId]);
                                $stats['thumbnails_created']++;
                                echo " (Thumb ID: {$fileId})";
                                if ($debugMode) {
                                    echo " DEBUG: Thumbnail created for {$relativePath} with ID {$fileId}";
                                }
                            } else {
                                $stats['thumbnails_failed']++;
                                if ($debugMode) {
                                    echo " DEBUG: Thumbnail creation failed for {$relativePath}";
                                }
                            }
                            if ($safeDelayUs > 0) usleep($safeDelayUs);
                        } else {
                            $stats['thumbnails_failed']++;
                        }
                    }
                }
            }

            echo "\n";

            if ($commitManager->shouldCommit()) {
                commit_progress($pdo, $scanId, $relativePath, $stats, $commitManager);
            }
        }

        $pdo->commit();
        echo "\nStep 3: Finalizing scan and updating drive timestamp...\n";
        $stmt = $pdo->prepare("UPDATE st_drives SET date_updated = NOW() WHERE id = ?");
        $stmt->execute([$driveId]);

        $duration = microtime(true) - $startTime;

        echo "\nStep 4: Marking files not found in this scan as deleted...\n";
        $markDeletedStmt = $pdo->prepare(
            "UPDATE st_files SET date_deleted = NOW() WHERE drive_id = ? AND (last_scan_id != ? OR last_scan_id IS NULL) AND date_deleted IS NULL"
        );
        $markDeletedStmt->execute([$driveId, $scanId]);
        $deletedCount = $markDeletedStmt->rowCount();
        echo "  > Marked {$deletedCount} files as deleted.\n";

        // Consolidate all final stats into one update
        $finalStatsUpdateStmt = $pdo->prepare(
            "UPDATE st_scans SET
                status = 'completed',
                scan_duration = ?,
                total_items_scanned = ?,
                new_files_added = ?,
                existing_files_updated = ?,
                files_marked_deleted = ?,
                files_skipped = ?,
                thumbnails_created = ?,
                thumbnail_creations_failed = ?
            WHERE scan_id = ?"
        );
        $finalStatsUpdateStmt->execute([
            round($duration),
            $stats['scanned'],
            $stats['added'],
            $stats['updated'],
            $deletedCount,
            $stats['skipped'],
            $stats['thumbnails_created'] ?? 0,
            $stats['thumbnails_failed'] ?? 0,
            $scanId
        ]);

        // Display final report from in-memory stats
        $durationFormatted = round($duration) . " seconds";
        if ($duration > 60) {
            $minutes = floor($duration / 60);
            $seconds = round($duration) % 60;
            $durationFormatted = "{$minutes} minutes, {$seconds} seconds";
        }

        echo "\n--- Scan Complete ---\n";
        echo "Total Items Scanned:  " . number_format($stats['scanned']) . "\n";
        echo "New Files Added:      " . number_format($stats['added']) . "\n";
        echo "Existing Files Updated: " . number_format($stats['updated']) . "\n";
        echo "Files Marked Deleted: " . number_format($deletedCount) . "\n";
        echo "Existing Files Skipped: " . number_format($stats['skipped']) . "\n";
        echo "Scan Duration:        {$durationFormatted}\n";
        echo "---------------------\n";

        $GLOBALS['scanId'] = null;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $GLOBALS['interrupted'] = true;

        $logMessage = "Unrecoverable exception during scan.";
        if (!empty($GLOBALS['current_scanned_path'])) {
            $logMessage .= " Failing file: " . $GLOBALS['current_scanned_path'] . ".";
        }
        $logMessage .= " Details: " . $e->getMessage();
        log_error($logMessage);

        echo "\nERROR: An unrecoverable exception occurred. Scan has been marked as interrupted.\n";
        if (!empty($GLOBALS['current_scanned_path'])) {
            echo "Failing file: " . $GLOBALS['current_scanned_path'] . "\n";
        }
        echo "Error details: " . $e->getMessage() . "\n";
        echo "The error has been recorded in application.log.\n";

        exit(1);
    }
}
