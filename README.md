# Vanilla Storage Tracker

A simple PHP and MySQL-based application for tracking storage drives and their contents.

## Features

*   Add, edit, and delete storage drives.
*   Scan drives to index their files.
*   Search for drives and files.
*   View statistics about your storage.
*   Asynchronous thumbnail generation for image files.

## Setup

1.  Create a MySQL database.
2.  If a schema file is provided (e.g., `storage_schema.sql`), import it to create the necessary tables.
3.  **Database Schema Update:** After initial setup, you need to update your database schema to support asynchronous thumbnail generation. Execute the following SQL command on your MySQL database:
    ```sql
    ALTER TABLE st_scans
    ADD COLUMN thumbnails_queued INT DEFAULT 0,
    ADD COLUMN thumbnail_queueing_failed INT DEFAULT 0;

    CREATE TABLE IF NOT EXISTS st_thumbnail_queue (
        queue_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        file_id BIGINT UNSIGNED NOT NULL,
        status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
        added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_date TIMESTAMP NULL,
        error_message TEXT,
        INDEX (file_id),
        INDEX (status)
    );
    ```
    Remember to replace `your_username` and `your_database_name` with your actual MySQL credentials if running from the command line.
4.  Copy `config.php.example` to `config.php` and update it with your database credentials.
5.  Use the `scan_drive.php` script to index your drives. See the **Scanning Drives** section below for detailed instructions.

## Dependencies

The `scan_drive.php` script relies on several external tools and PHP extensions for full functionality:

*   **`ffprobe`**: (Part of FFmpeg) Required for extracting metadata (codec, resolution, duration) from video and audio files.
*   **`exiftool`**: Required for extracting metadata (Product Name, Product Version) from executable files and comprehensive file information.
*   **`php-exif` extension**: PHP extension required for extracting EXIF data (date taken, camera model) from image files.
*   **`php-gd` extension**: PHP extension required for in-line thumbnail generation.
*   **`hdparm`**: (Linux only) Used for reading physical serial and model numbers of SATA drives.
*   **`lsblk`**: (Linux only) Used for identifying block devices and their filesystem types.
*   **`df`**: (Linux only) Used for determining the source device of a mounted filesystem.

## Scanning Drives

The `scan_drive.php` script is a powerful command-line tool for indexing the contents of your storage drives. It is designed to be robust, allowing for resumable scans and automatic recovery from common errors.

### Usage

```bash
sudo php scan_drive.php [--no-md5] [--no-drive-info-update] [--no-thumbnails] [--use-external-thumb-gen] [--resume] [--skip-existing] [--debug] <drive_id> <partition_number> <mount_point>
```

### Arguments

*   `<drive_id>`: The numeric ID of the drive as it appears in the `st_drives` table.
*   `<partition_number>`: The specific partition number on the drive that you are scanning (e.g., `1`).
*   `<mount_point>`: The absolute filesystem path where the drive is mounted (e.g., `/mnt/my_external_drive`).

### Options

The script accepts several optional flags to control its behavior:

*   `--no-md5`: Skips the calculation of MD5 hashes for each file. This significantly speeds up the scanning process.
*   `--no-drive-info-update`: Prevents the script from automatically updating the drive's model, serial number, and filesystem type in the database.
*   `--no-thumbnails`: Skips generating thumbnails for image files. If `--use-external-thumb-gen` is also used, this flag is redundant as in-line thumbnail generation is already disabled.
*   `--use-external-thumb-gen`: Disables in-line thumbnail generation in `scan_drive.php`, allowing an external script (like `generate_thumbnails.php`) to handle thumbnail creation asynchronously.
*   `--resume`: If a previous scan was interrupted, this flag allows you to resume the scan from the last successfully processed file, preventing you from having to start over.
*   `--skip-existing`: This flag tells the script to ignore any file that already exists in the database for that drive. This is very useful for quickly adding new files to a drive that has already been scanned.
*   `--debug`: Enables verbose debug output during the scan process.

### Automatic Error Recovery

If the script encounters a filesystem I/O error during a scan (e.g., the drive disconnects), it will automatically attempt to remount the drive and continue the scan. It will try this up to 5 times before gracefully stopping. If it stops, the scan is marked as 'interrupted', and you can continue it later using the `--resume` flag.

### Examples

**Standard Scan:**

```bash
sudo php scan_drive.php 5 1 /mnt/my_external_drive
```

**Fast Scan (No MD5 Hashes or Thumbnail Queuing):**

```bash
sudo php scan_drive.php --no-md5 --no-thumbnails 5 1 /mnt/my_external_drive
```

**Resuming an Interrupted Scan:**

```bash
sudo php scan_drive.php --resume 5 1 /mnt/my_external_drive
```

**Adding Only New Files to an Already Scanned Drive:**

```bash
sudo php scan_drive.php --skip-existing 5 1 /mnt/my_external_drive
```

## Thumbnail Generation

Thumbnail generation is now handled asynchronously by a dedicated CLI script, `generate_thumbnails.php`. This allows `scan_drive.php` to complete faster by offloading the resource-intensive thumbnail creation process.

### Running the Thumbnail Generator

To start the thumbnail generation process, run the following command in your terminal. It's recommended to run this script in the background, especially for large queues.

```bash
php generate_thumbnails.php &
```

This script will continuously check the `st_thumbnail_queue` table for pending jobs, generate thumbnails, and update their status.

### Checking Thumbnail Status for a Drive

You can check the progress of thumbnail generation for a specific drive using the `check_thumbnails.php` script:

```bash
php check_thumbnails.php <drive_id>
```

Replace `<drive_id>` with the ID of the drive you are interested in. The script will report the number of pending, processing, completed, and failed thumbnail jobs for that drive. Once all jobs are either `completed` or `failed`, you can safely disconnect the drive (assuming no other operations are pending).
