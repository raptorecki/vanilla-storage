# Vanilla Storage Tracker

A simple PHP and MySQL-based application for tracking storage drives and their contents.

## Features

*   Add, edit, and delete storage drives.
*   Scan drives to index their files, including detailed file type information.
*   View EXIF data and thumbnails for image files.
*   Search for drives and files.
*   View statistics about your storage.
*   Mark all files on a drive as deleted (soft delete).
*   Permanently remove all file records for a drive from the database.

## Setup

1.  Create a MySQL database.
2.  If a schema file is provided (e.g., `storage_schema.sql`), import it to create the necessary tables.
3.  **Database Schema Update:** If you are upgrading from a previous version, you need to add the `filetype` column to your `st_files` table:
    ```sql
    ALTER TABLE st_files ADD COLUMN filetype TEXT DEFAULT NULL;
    ```
4.  Copy `config.php.example` to `config.php` and update it with your database credentials.
5.  Use the `scan_drive.php` script to index your drives. See the **Scanning Drives** section below for detailed instructions.

## Dependencies

The `scan_drive.php` script relies on several external tools and PHP extensions for full functionality.

**Debian/Ubuntu Installation:**
```bash
sudo apt update
sudo apt install -y ffmpeg libimage-exiftool-perl file php-exif php-gd hdparm util-linux coreutils
```

*   **`ffprobe`**: (Part of FFmpeg) Required for extracting metadata (codec, resolution, duration) from video and audio files.
*   **`exiftool`**: Required for extracting metadata (Product Name, Product Version) from executable files and comprehensive file information.
*   **`file`**: Required for detailed file type identification.
*   **`php-exif` extension**: PHP extension required for extracting EXIF data (date taken, camera model) from image files.
*   **`php-gd` extension**: PHP extension required for in-line thumbnail generation.
*   **`hdparm`**: (Linux only) Used for reading physical serial and model numbers of SATA drives.
*   **`lsblk`**: (Linux only) Used for identifying block devices and their filesystem types.
*   **`df`**: (Linux only) Used for determining the source device of a mounted filesystem.

## Scanning Drives

The `scan_drive.php` script is a powerful command-line tool for indexing the contents of your storage drives. It is designed to be robust, allowing for resumable scans and automatic recovery from common errors. During the scan, it now also generates thumbnails in-line and collects detailed file type information.

### Usage

```bash
sudo php scan_drive.php [--no-md5] [--no-drive-info-update] [--no-thumbnails] [--resume] [--skip-existing] [--debug] <drive_id> <partition_number> <mount_point>
```

### Arguments

*   `<drive_id>`: The numeric ID of the drive as it appears in the `st_drives` table.
*   `<partition_number>`: The specific partition number on the drive that you are scanning (e.g., `1`).
*   `<mount_point>`: The absolute filesystem path where the drive is mounted (e.g., `/mnt/my_external_drive`).

### Options

The script accepts several optional flags to control its behavior:

*   `--no-md5`: Skips the calculation of MD5 hashes for each file. This significantly speeds up the scanning process.
*   `--no-drive-info-update`: Prevents the script from automatically updating the drive's model, serial number, and filesystem type in the database.
*   `--no-thumbnails`: Disables in-line thumbnail generation for image files during the scan.
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

**Fast Scan (No MD5 Hashes or Thumbnails):**

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

Thumbnail generation is now handled in-line during the `scan_drive.php` process. When `scan_drive.php` encounters an image file, it will attempt to generate a thumbnail immediately. This simplifies the workflow and ensures thumbnails are available as soon as a drive is scanned.

**Note:** The `generate_thumbnails.php` and `check_thumbnails.php` scripts are considered legacy and are no longer the primary method for thumbnail generation. They may be removed in future versions. It is recommended to rely on the in-line generation provided by `scan_drive.php`.