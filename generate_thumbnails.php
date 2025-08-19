<?php
/**
 * CLI Script for Asynchronous Thumbnail Generation
 *
 * This script processes pending thumbnail generation requests from the `st_thumbnail_queue` table.
 * It generates thumbnails for image files and updates the status in the queue.
 *
 * Usage:
 * php generate_thumbnails.php
 */

// --- Basic CLI Sanity Checks ---
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

require_once 'database.php';
require_once 'helpers/error_logger.php';

// --- Thumbnail Generation Functions (moved from scan_drive.php) ---

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

// --- Main Processing Logic ---

echo "Starting thumbnail generation process...\n";

// Check if PHP's GD extension is loaded for thumbnail generation.
if (!extension_loaded('gd')) {
    echo "ERROR: PHP `gd` extension not found. Thumbnail generation is not possible. Please install `php-gd`.\n";
    exit(1);
}

$batchSize = 10; // Process 10 thumbnails at a time

while (true) {
    // Fetch pending jobs
    $stmt = $pdo->prepare(
        "SELECT queue_id, file_id FROM st_thumbnail_queue WHERE status = 'pending' LIMIT ?"
    );
    $stmt->execute([$batchSize]);
    $pendingJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendingJobs)) {
        echo "No pending thumbnail jobs found. Exiting.\n";
        break; // No more jobs, exit loop
    }

    echo "Processing " . count($pendingJobs) . " thumbnail jobs...\n";

    foreach ($pendingJobs as $job) {
        $queueId = $job['queue_id'];
        $fileId = $job['file_id'];
        $errorMessage = null;

        try {
            // Mark job as processing
            $updateQueueStmt = $pdo->prepare("UPDATE st_thumbnail_queue SET status = 'processing', processed_date = NOW() WHERE queue_id = ?");
            $updateQueueStmt->execute([$queueId]);

            // Get file path from st_files table
            $fileStmt = $pdo->prepare("SELECT path, drive_id FROM st_files WHERE id = ?");
            $fileStmt->execute([$fileId]);
            $fileData = $fileStmt->fetch(PDO::FETCH_ASSOC);

            if (!$fileData) {
                throw new Exception("File with ID {$fileId} not found in st_files.");
            }

            // Mount point will be determined from CLI argument or config.php
            $fullFilePath = rtrim($thumbnailSourceBasePath, '/') . '/' . ltrim($fileData['path'], '/');

            if (!file_exists($fullFilePath)) {
                throw new Exception("Source file does not exist: {$fullFilePath}");
            }

            $thumbnailRelPath = getThumbnailPath($fileId);
            if (empty($thumbnailRelPath)) {
                throw new Exception("Failed to determine thumbnail path for file ID {$fileId}.");
            }
            $thumbDestination = __DIR__ . '/' . $thumbnailRelPath;

            if (createThumbnail($fullFilePath, $thumbDestination)) {
                // Update st_files with thumbnail path
                $updateFileStmt = $pdo->prepare("UPDATE st_files SET thumbnail_path = ? WHERE id = ?");
                $updateFileStmt->execute([$thumbnailRelPath, $fileId]);

                // Mark job as completed
                $updateQueueStmt = $pdo->prepare("UPDATE st_thumbnail_queue SET status = 'completed' WHERE queue_id = ?");
                $updateQueueStmt->execute([$queueId]);
                echo "  > Thumbnail generated for file ID {$fileId}.\n";
            } else {
                throw new Exception("Thumbnail creation failed for file ID {$fileId}.");
            }

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            log_error("Error processing thumbnail job {$queueId} (file ID {$fileId}): " . $errorMessage);
            // Mark job as failed
            $updateQueueStmt = $pdo->prepare("UPDATE st_thumbnail_queue SET status = 'failed', error_message = ? WHERE queue_id = ?");
            $updateQueueStmt->execute([$errorMessage, $queueId]);
            echo "  > Failed to generate thumbnail for file ID {$fileId}: {$errorMessage}\n";
        }
    }
    // Small delay to prevent busy-waiting if there are continuous jobs
    sleep(1);
}

echo "Thumbnail generation process finished.\n";

?>