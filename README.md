# Vanilla Storage Tracker

A simple PHP and MySQL-based application for tracking storage drives and their contents.

## Features

*   Add, edit, and delete storage drives.
*   Scan drives to index their files.
*   Search for drives and files.
*   View statistics about your storage.

## Setup

1.  Create a MySQL database.
2.  If a schema file is provided (e.g., `storage_schema.sql`), import it to create the necessary tables.
3.  Copy `config.php.example` to `config.php` and update it with your database credentials.
4.  Use the `scan_drive.php` script to index your drives. See the **Scanning Drives** section below for detailed instructions.

## Scanning Drives

The `scan_drive.php` script is a powerful command-line tool for indexing the contents of your storage drives. It is designed to be robust, allowing for resumable scans and automatic recovery from common errors.

### Usage

```bash
sudo php scan_drive.php [options] <drive_id> <partition_number> <mount_point>
```

### Arguments

*   `<drive_id>`: The numeric ID of the drive as it appears in the `st_drives` table.
*   `<partition_number>`: The specific partition number on the drive that you are scanning (e.g., `1`).
*   `<mount_point>`: The absolute filesystem path where the drive is mounted (e.g., `/mnt/my_external_drive`).

### Options

The script accepts several optional flags to control its behavior:

*   `--no-md5`: Skips the calculation of MD5 hashes for each file. This significantly speeds up the scanning process.
*   `--no-drive-info-update`: Prevents the script from automatically updating the drive's model, serial number, and filesystem type in the database.
*   `--no-thumbnails`: Skips the generation of thumbnails for image files.
*   `--resume`: If a previous scan was interrupted, this flag allows you to resume the scan from the last successfully processed file, preventing you from having to start over.
*   `--skip-existing`: This flag tells the script to ignore any file that already exists in the database for that drive. This is very useful for quickly adding new files to a drive that has already been scanned.

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