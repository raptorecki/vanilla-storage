<?php
/**
 * Command-Line Drive Indexing Script
 *
 * This script scans a specified mount point for a given drive ID and partition number,
 * updating the file index in the database. It also attempts to automatically detect
 * and update drive-specific information like model number, serial, and filesystem type.
 *
 * Usage:
 * php scan_drive.php [--no-md5] [--no-drive-info-update] <drive_id> <partition_number> <mount_point>
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
 *
 * Examples:
 *   php scan_drive.php 5 1 /mnt/my_external_drive
 *   php scan_drive.php --no-md5 5 1 /mnt/my_external_drive
 *   php scan_drive.php --no-drive-info-update 5 1 /mnt/my_external_drive
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


// --- Argument Parsing ---
// Retrieve command-line arguments passed to the script.
$args = $argv;
array_shift($args); // Remove the script name itself from the arguments array.

// Initialize flags based on default behavior.
$calculateMd5 = true; // By default, MD5 hashes will be calculated.
$updateDriveInfo = true; // By default, drive information will be updated.

// Check for the '--no-md5' flag.
// If found, set $calculateMd5 to false and remove the flag from the arguments array.
$noMd5Key = array_search('--no-md5', $args);
if ($noMd5Key !== false) {
    $calculateMd5 = false;
    unset($args[$noMd5Key]);
}

// Check for the '--no-drive-info-update' flag.
// If found, set $updateDriveInfo to false and remove the flag from the arguments array.
$noDriveInfoUpdateKey = array_search('--no-drive-info-update', $args);
if ($noDriveInfoUpdateKey !== false) {
    $updateDriveInfo = false;
    unset($args[$noDriveInfoUpdateKey]);
}

// Re-index the arguments array after removing optional flags.
$args = array_values($args);

// Validate the number of remaining required arguments.
// Expects drive_id, partition_number, and mount_point.
if (count($args) < 3) {
    echo "Usage: php " . basename(__FILE__) . " [--no-md5] [--no-drive-info-update] <drive_id> <partition_number> <mount_point>\n";
    echo "  --no-md5 : Optional. Skips MD5 hash calculation for a faster scan.\n";
    echo "  --no-drive-info-update : Optional. Skips updating drive model, serial, and filesystem type.\n";
    echo "Example: php " . basename(__FILE__) . " 5 1 /mnt/my_external_drive\n";
    echo "Example: php " . basename(__FILE__) . " --no-md5 5 1 /mnt/my_external_drive\n";
    echo "Example: php " . basename(__FILE__) . " --no-drive-info-update 5 1 /mnt/my_external_drive\n";
    exit(1);
}

// Assign parsed arguments to variables.
$driveId = (int)$args[0]; // Cast drive_id to an integer.
$partitionNumber = (int)$args[1]; // Cast partition_number to an integer.
$mountPoint = $args[2]; // The mount point path.

// Validate drive ID and mount point.
if ($driveId <= 0) {
    echo "Error: Invalid drive_id provided.\n";
    exit(1);
}
if (!is_dir($mountPoint)) {
    echo "Error: Mount point '{$mountPoint}' is not a valid directory.\n";
    exit(1);
}

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
    'ppt' => 'Document', 'pptx' => 'Document', 'txt' => 'Document', 'rtf' => 'Document',
    // Archive extensions
    'zip' => 'Archive', 'rar' => 'Archive', '7z' => 'Archive', 'tar' => 'Archive', 'gz' => 'Archive',
    // Executable extensions
    'exe' => 'Executable', 'msi' => 'Executable', 'bat' => 'Executable', 'sh' => 'Executable',
];

// --- Main Scanning Logic ---

// Initialize statistics counters for the scan process.
$stats = [
    'scanned' => 0, // Total items (files/directories) scanned.
    'added' => 0,   // New files added to the database.
    'updated' => 0, // Existing files updated in the database.
    'deleted' => 0, // Files marked as deleted (not found during current scan).
];

try {
    // Start a database transaction for atomicity. All changes will be committed together or rolled back on error.
    $pdo->beginTransaction();

    // 1. Mark all existing, non-deleted files for this drive as "deleted".
    // This is a soft delete. If a file is found during the current scan, its `date_deleted` will be set back to NULL.
    echo "Step 1: Marking existing files for deletion check...\n";
    $stmt = $pdo->prepare("UPDATE st_files SET date_deleted = NOW() WHERE drive_id = ? AND date_deleted IS NULL");
    $stmt->execute([$driveId]);
    $stats['deleted'] = $stmt->rowCount(); // The number of rows affected (marked for deletion) is stored.

    // 2. Prepare the main SQL statement for inserting or updating file records.
    // This uses MySQL's `ON DUPLICATE KEY UPDATE` syntax for efficient upsert operations.
    $update_clauses = [
        "date_deleted = NULL", // Un-delete the file if it's found again.
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
        "partition_number = VALUES(partition_number)", // Update partition number.
        "product_name = VALUES(product_name)",
        "product_version = VALUES(product_version)"
    ];

    $insert_cols_base = "drive_id, path, path_hash, filename, size, ctime, mtime, file_category, media_format, media_codec, media_resolution, media_duration, exif_date_taken, exif_camera_model, is_directory, partition_number, product_name, product_version, date_added, date_deleted";
    $insert_vals_base = ":drive_id, :path, :path_hash, :filename, :size, :ctime, :mtime, :file_category, :media_format, :media_codec, :media_resolution, :media_duration, :exif_date_taken, :exif_camera_model, :is_directory, :partition_number, :product_name, :product_version, NOW(), NULL";

    if ($calculateMd5) {
        // If MD5 calculation is enabled, include md5_hash in insert/update.
        $insert_cols = "md5_hash, " . $insert_cols_base;
        $insert_vals = ":md5_hash, " . $insert_vals_base;
        $update_clauses[] = "md5_hash = VALUES(md5_hash)";
    } else {
        // If MD5 calculation is disabled, exclude md5_hash.
        $insert_cols = $insert_cols_base;
        $insert_vals = $insert_vals_base;
        // When not calculating MD5, we don't update the existing hash on duplicates.
    }

    // Construct the final SQL query.
    $sql = sprintf(
        "INSERT INTO st_files (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s",
        $insert_cols,
        $insert_vals,
        implode(",\n            ", $update_clauses)
    );
    $upsertStmt = $pdo->prepare($sql);

    // 3. Recursively scan the directory structure of the mount point.
    $scan_message = $calculateMd5 ? "Scanning filesystem and updating database (MD5 hashing may be slow)..." : "Scanning filesystem and updating database (skipping MD5 hashing)...";
    echo "Step 2: {$scan_message}\n\n";

    // Create a RecursiveDirectoryIterator to traverse the filesystem.
    // SKIP_DOTS ignores '.' and '..' entries.
    // UNIX_PATHS ensures consistent path separators.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mountPoint, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::SELF_FIRST // Process directories before their contents.
    );

    // Get terminal width for clean progress display. Fallback to 80 characters if not detectable.
    $termWidth = (int) @shell_exec('tput cols');
    if ($termWidth <= 0) {
        $termWidth = 80;
    }

    // Iterate over each file and directory found during the scan.
    foreach ($iterator as $fileInfo) {
        $stats['scanned']++; // Increment scanned item count.
        $path = $fileInfo->getPathname(); // Full path of the current item.
        // Calculate the relative path from the mount point.
        $relativePath = substr($path, strlen($mountPoint));

        // --- Verbose Progress Indicator ---
        // Format a progress message including the scanned count and relative path.
        $progressMessage = sprintf("[%' 9d] %s", $stats['scanned'], $relativePath);
        // Truncate the message if it exceeds terminal width to prevent line wrapping.
        if (mb_strlen($progressMessage) > $termWidth) {
            $progressMessage = mb_substr($progressMessage, 0, $termWidth - 4) . '...';
        }
        // Output the progress message, each on a new line.
        echo $progressMessage . "\n";

        // --- Metadata Extraction ---
        $extension = strtolower($fileInfo->getExtension()); // Get file extension.
        $category = $extensionMap[$extension] ?? 'Other'; // Categorize file based on extension map.
        // Initialize metadata array with default null values.
        $metadata = ['format' => null, 'codec' => null, 'resolution' => null, 'duration' => null, 'exif_date_taken' => null, 'exif_camera_model' => null, 'product_name' => null, 'product_version' => null];

        // Extract media metadata for video and audio files if ffprobe is available.
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
        // Extract image metadata using native PHP functions (does not depend on ffprobe).
        if (!$fileInfo->isDir() && $category === 'Image') {
            $metadata = array_merge($metadata, getImageInfo($path) ?? []);
        }

        // Prepare parameters for the database upsert operation.
        $params = [
            'drive_id' => $driveId,
            'path' => $relativePath,
            'path_hash' => hash('sha256', $relativePath), // SHA256 hash of the relative path for quick lookups.
            'filename' => $fileInfo->getFilename(),
            'size' => $fileInfo->isDir() ? 0 : $fileInfo->getSize(), // Size is 0 for directories.
            'ctime' => date('Y-m-d H:i:s', $fileInfo->getCTime()), // Creation time.
            'mtime' => date('Y-m-d H:i:s', $fileInfo->getMTime()), // Modification time.
            'media_format' => $metadata['format'],
            'media_codec' => $metadata['codec'],
            'media_resolution' => $metadata['resolution'],
            'media_duration' => $metadata['duration'],
            'exif_date_taken' => $metadata['exif_date_taken'],
            'exif_camera_model' => $metadata['exif_camera_model'],
            'file_category' => $fileInfo->isDir() ? 'Directory' : $category, // Set category to 'Directory' for directories.
            'is_directory' => $fileInfo->isDir() ? 1 : 0, // Boolean flag for directory.
            'partition_number' => $partitionNumber, // The partition number for the file.
        ];

        // Calculate MD5 hash if enabled and the current item is a file (not a directory).
        if ($calculateMd5) {
            $md5_hash = null;
            if (!$fileInfo->isDir()) {
                // Use hash_file() for memory-efficient hashing of large files.
                $md5_hash = hash_file('md5', $path);
            }
            $params['md5_hash'] = $md5_hash;
        }

        // Execute the prepared upsert statement with the collected parameters.
        $upsertStmt->execute($params);
        $rowCount = $upsertStmt->rowCount(); // Get the number of rows affected by the upsert.

        // Update statistics based on the upsert result.
        if ($rowCount === 1) { // A new row was inserted.
            $stats['added']++;
            $stats['deleted']--; // A newly added file cannot be a previously deleted one.
        } elseif ($rowCount === 2) { // An existing row was updated.
            $stats['updated']++;
            $stats['deleted']--; // This file was found and updated, so it's not deleted.
        }
    }

    // Add a blank line for better readability in the console output.
    echo "\n";

    // 4. Update the `date_updated` timestamp on the parent drive record in `st_drives`.
    echo "Step 3: Finalizing scan and updating drive timestamp...\n";
    $stmt = $pdo->prepare("UPDATE st_drives SET date_updated = NOW() WHERE id = ?");
    $stmt->execute([$driveId]);

    // 5. Commit the database transaction. All changes are now permanently saved.
    $pdo->commit();

} catch (\Exception $e) {
    // If any exception occurs during the process, roll back all changes made within the transaction.
    $pdo->rollBack();
    echo "\nERROR: An exception occurred. Rolling back changes.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

// Calculate the total duration of the scan.
$endTime = microtime(true);
$duration = $endTime - $startTime;

// Format the duration for display.
if ($duration < 60) {
    $durationFormatted = round($duration, 2) . " seconds";
} else {
    $minutes = floor($duration / 60);
    $seconds = round($duration % 60);
    $durationFormatted = "{$minutes} minutes, {$seconds} seconds";
}

// --- Scan Summary Output ---
echo "\n--- Scan Complete ---\n";
echo "Total Items Scanned:  " . number_format($stats['scanned']) . "\n";
echo "New Files Added:      " . number_format($stats['added']) . "\n";
echo "Existing Files Updated: " . number_format($stats['updated']) . "\n";
echo "Files Marked Deleted: " . number_format($stats['deleted']) . "\n";
echo "Scan Duration:        {$durationFormatted}\n";
echo "---------------------\n";

?>