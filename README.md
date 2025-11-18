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

## Database Performance Optimization

As your file database grows into millions of records, the `drives.php` page can become slow to load. This is because it calculates storage usage statistics for every drive by querying the `st_files` table. To maintain fast performance with large datasets, composite indexes have been implemented.

### Understanding the Performance Problem

The `drives.php` page displays a list of all drives with real-time statistics, including:
- Total used space per drive (calculated from millions of file records)
- Last scan date (queried from scan history)
- Scan status (whether a drive has been scanned)

Without proper indexing, MySQL must scan millions of rows for each drive, resulting in page load times of 30+ seconds with datasets containing 6-7 million files across ~100 drives.

### The Optimization Solution: Composite Indexes

Two composite indexes were added to optimize the most expensive queries:

#### 1. Files Usage Index
```sql
CREATE INDEX idx_files_drive_deleted_size ON st_files(drive_id, date_deleted, size);
```

**What it does:** This is a "covering index" that allows MySQL to calculate total storage usage without reading the full table rows.

**How it works:**
- Groups all file records by `drive_id` (for quick drive filtering)
- Includes `date_deleted` to filter out deleted files (soft deletes)
- Includes `size` so the SUM can be calculated directly from the index

**Query it optimizes:**
```sql
SELECT SUM(size) FROM st_files WHERE drive_id = ? AND date_deleted IS NULL
```

**Why this structure:** The column order matters. MySQL reads indexes left-to-right:
1. First, it filters by `drive_id` (narrows to one drive's files)
2. Then, it filters by `date_deleted IS NULL` (excludes soft-deleted files)
3. Finally, it sums the `size` column (directly from index, no table lookup needed)

**Performance impact:** Reduces rows examined from millions to just hundreds per query. For 98 drives, this saves examining ~6.5 million unnecessary rows.

#### 2. Scan Date Index
```sql
CREATE INDEX idx_scans_drive_date ON st_scans(drive_id, scan_date);
```

**What it does:** Speeds up lookups for the most recent scan date per drive.

**How it works:**
- Indexes scans by `drive_id` first
- Then by `scan_date` for efficient MAX() calculation

**Query it optimizes:**
```sql
SELECT MAX(scan_date) FROM st_scans WHERE drive_id = ?
```

**Why this structure:** By indexing both columns together, MySQL can quickly find the latest scan for each drive without sorting the entire scans table.

**Performance impact:** Allows instant lookup of scan dates instead of scanning all historical scan records.

### Applying the Optimization

**For new installations:** The indexes are included in `storage_schema.sql` (v1.0.7+).

**For existing installations:** Run the optimization script:
```bash
php run_optimization.php
```

This will:
- Check if indexes already exist (safe to run multiple times)
- Create the indexes (takes 2-3 minutes for millions of records)
- Verify the indexes were created correctly

**Expected Results:**
- Page load time: 30+ seconds → ~2 seconds (93% improvement)
- Database CPU usage: Significantly reduced
- Scalability: Handles millions of files efficiently

### Technical Details

**Index Type:** Both indexes are B-Tree indexes (MySQL default), which provide O(log n) lookup time instead of O(n) full table scans.

**Covering Index Concept:** The `idx_files_drive_deleted_size` is called a "covering index" because it contains all columns needed for the query. MySQL never has to look up the actual table rows—it reads everything directly from the index, which is much faster.

**Trade-offs:**
- **Disk space:** Indexes consume additional storage (typically 10-15% of table size)
- **Write performance:** Inserts/updates are slightly slower because indexes must be updated
- **Read performance:** Dramatically faster for queries that use these indexes

For this application, the trade-off is worthwhile because:
1. Scans are infrequent (write-heavy operations happen rarely)
2. Drive browsing is frequent (read-heavy operations happen often)
3. The performance gain is substantial (93% faster)