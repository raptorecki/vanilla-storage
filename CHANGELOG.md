# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.36] - 2025-11-25

### Added
- **Dedicated Duplicate Files Page**: Created `duplicates.php` with full-featured duplicate file browser.
  - Pagination support (20 items per page, max 1000 pages).
  - Filters: minimum instances (2+, 3+, 4+, 5+, 10+).
  - Sorting options: wasted space, instance count, file size, filename.
  - Expandable file locations with direct links to browse.php.
  - Query timeout protection with helpful error messages.
- `PERFORMANCE_SUMMARY.md`: Comprehensive documentation of all performance optimizations and current metrics.
- Navigation link to "Duplicates" page in header.php.

### Changed
- `stats.php`: Added approximate duplicate file statistics using fast COUNT queries (replaces slow detailed duplicate list).
  - Shows ~2.6M potential duplicates with estimated wasted space.
  - Added link to new duplicates.php page for detailed browsing.
- `header.php`: Added "Duplicates" navigation menu item.

### Fixed
- File permissions on duplicates.php corrected to 664 (was 600, preventing web server access).

## [1.1.35] - 2025-11-25

### Performance
- **Stats Page Optimization**: Achieved 8x performance improvement on `stats.php` with large datasets (7.2M+ files).
  - Temporarily disabled duplicate file detection queries that were taking 180+ seconds for top-N results and 300+ seconds for total statistics.
  - Consolidated DriveUsage CTE query to run once instead of three times, saving 15.6 seconds.
  - Result: Reduced page load time from 220+ seconds to 27 seconds (87% improvement).
  - Added `idx_files_deleted_mtime` index on `st_files(date_deleted, mtime DESC)` to optimize file search with ORDER BY mtime.
  - See `STATS_OPTIMIZATION_SUMMARY.md` for detailed analysis and future optimization paths.

### Security
- **Database Query Governor**: Implemented comprehensive safeguards to prevent single queries from blocking the entire MySQL database.
  - Added `query_with_timeout()` and `prepare_with_timeout()` wrapper functions in `database.php` to enforce query timeouts.
  - Set global MySQL query timeout to 60 seconds (`max_statement_time = 60000`).
  - Set global lock wait timeout to 30 seconds (`innodb_lock_wait_timeout = 30`).
  - Enabled slow query logging for queries taking more than 10 seconds.
  - Added hard pagination limits in `files.php`: maximum page depth of 5000 (100K offset limit) to prevent slow deep pagination queries.
  - Applied 30-second timeout to file search queries with graceful error handling.
  - See `DATABASE_BLOCKING_FIXES.md` for complete implementation details.

### Changed
- `stats.php`: Duplicate file queries commented out with clear TODO notes for future re-implementation (lines 161-225).
- `stats.php`: DriveUsage CTE query now runs once and results are sorted in PHP instead of running query three times.
- `files.php`: Added pagination depth limits and query timeout protection.
- `database.php`: Added query timeout wrapper functions.

### Added
- `mysql_performance_limits.sql`: SQL script with MySQL performance configuration and documentation.
- `DATABASE_BLOCKING_FIXES.md`: Complete documentation of database blocking prevention measures.
- `STATS_OPTIMIZATION_SUMMARY.md`: Updated with latest performance improvements and recommendations.
- `test_stats_performance.php`: Simple script to test stats page performance.

### Fixed
- Killed two long-running queries that were blocking database: duplicate files query (726 seconds) and deep pagination query (468 seconds).

## [1.1.34] - 2025-11-18

### Performance
- **Stats Page Optimization**: Dramatically improved `stats.php` page load performance with large datasets (6.7M+ file records).
  - Added `idx_files_deleted_category` index on `st_files(date_deleted, file_category)` to optimize file category grouping query.
  - Added `idx_files_deleted_size_desc` index on `st_files(date_deleted, size DESC)` to optimize largest files sorting query.
  - Rewrote "Largest Files" query using two-step approach: sort first using index, then lookup drive names for results only.
  - Result: Reduced critical query times from 174.9 seconds to 4.3 seconds (97.6% improvement, 41x faster).
  - Individual improvements:
    - Files by Category: 113s → 2.2s (98% faster)
    - Largest Files: 60s → <0.001s (99.9% faster, 172,000x improvement)
    - Overall page load: ~3 minutes → ~5-10 seconds
  - Run `php optimize_stats.php` to apply these indexes to existing installations.

### Changed
- `stats.php`: Optimized "Largest Files" query to use two-step process for better index utilization.
- Database schema version bumped to 1.0.8 to reflect new indexes.

## [1.1.33] - 2025-11-18

### Performance
- **Database Optimization**: Added composite indexes to dramatically improve `drives.php` page load performance with large datasets (6.7M+ file records).
  - Added `idx_files_drive_deleted_size` index on `st_files(drive_id, date_deleted, size)` to optimize the used space calculation query.
  - Added `idx_scans_drive_date` index on `st_scans(drive_id, scan_date)` to optimize the last scan date query.
  - Result: Reduced page load time from 30+ seconds to ~2 seconds (93% improvement).
  - Run `php run_optimization.php` to apply these indexes to existing installations.

### Changed
- Database schema version bumped to 1.0.7 to reflect new indexes.

## [1.1.32] - 2025-10-05

### Added
- `scan_drive.php`: Added `--no-hdparm` option to bypass `hdparm` checks for drive serial and model numbers. This is useful for drives that do not support `hdparm` commands, such as veracrypt containers or some USB drives.

## [1.1.31] - 2025-09-19

### Fixed
- `scan_drive.php`: The script will no longer crash with an "Integrity constraint violation" when a file's modification time is corrupted. It now catches the error, logs it, and assigns a default timestamp (`1970-01-01 00:00:01`) to the `mtime` and `ctime` fields, allowing the scan to continue.

## [1.1.30] - 2025-09-18

### Fixed
- `scan_drive.php`: The script will no longer crash when encountering a file with a corrupt or invalid modification/creation timestamp (e.g., a year far in the future). It now validates the timestamp and logs an error while setting the value to NULL in the database, allowing the scan to proceed.

## [1.1.29] - 2025-09-16

### Fixed
- `scan_drive.php`: Fixed script to only check for read permissions on mount points, allowing scanning of read-only mounted drives.

## [1.1.28] - 2025-09-16

### Changed
- `scan_drive.php`: The `--no-disk-recovery` option has been replaced with `--with-disk-recovery`. Disk recovery is now disabled by default and must be explicitly enabled.

## [1.1.27] - 2025-09-10

### Fixed
- `disk_recovery.php`: Corrected TestDisk output capture by reading from `testdisk.log`.
- `disk_recovery.php`: Implemented process termination on timeout in `execute_recovery_command`.

### Changed
- `disk_recovery.php`: Updated `$requiredTools` array to include `ntfsfix`, `fsck.ext4`, and `hexdump`.
- `disk_recovery.php`: Optimized `badblocks` command for performance with sampling.
- `disk_recovery.php`: Added human-readable MBR analysis using `hexdump`.

## [1.1.26] - 2025-09-10

### Added
- `disk_recovery.php`: Added `sgdisk --print` command to collect detailed GPT information, including secondary GPT table location.
- `disk_recovery.php`: Implemented collection of `execution_time`, `file_size`, and `checksum` for recovery command outputs.

## [1.1.25] - 2025-09-10

### Fixed
- `disk_recovery.php`: Added `fclose` calls to `execute_recovery_command` to prevent resource leaks.

## [1.1.24] - 2025-09-10

### Added
- `scan_drive.php`: Added `--no-disk-recovery` option to skip disk recovery data collection and remount attempts.

## [1.1.23] - 2025-09-10

### Changed
- `disk_recovery.php`: Added detailed comments and increased verbosity to the script. The script now clearly lists the actions it performs and emphasizes that all operations are read-only and non-destructive.

## [1.1.22] - 2025-09-10

### Added
- `scan_drive.php`: Added functionality to collect and store drive recovery data.
- `disk_recovery.php`: New file containing functions for collecting drive recovery data.

### Changed
- `scan_drive.php`: Now calls the `collect_recovery_data` function from `disk_recovery.php` to store recovery information.

### Changed
- `scan_drive.php`: Improved debug output verbosity. When run with the `--debug` flag, the script now provides detailed, step-by-step logging for all major operations within the file processing loop, including metadata extraction, hashing, database operations, and thumbnail generation.

## [1.1.20] - 2025-09-10

### Fixed
- `scan_drive.php`: The script no longer crashes when trying to create a thumbnail for a malformed image (e.g., an image with a height of 1px that results in a calculated thumbnail height of 0px). It now skips creating a thumbnail for such files and logs the issue.

## [1.1.19] - 2025-09-10

### Fixed
- `scan_drive.php`: Fixed a bug where the script would crash on filenames with special characters (e.g., containing `!`, `(`, `)`, `'`) or non-ASCII characters. The `ExiftoolManager` now correctly handles such filenames by specifying the UTF-8 charset.

## [1.1.18] - 2025-09-08

### Fixed
- `scan_drive.php`: Corrected a bug where the final scan summary would incorrectly display all zeros. The script now reliably shows the final statistics by using in-memory data for the report and consolidating final database updates into a single query.

## [1.1.17] - 2025-09-07

### Changed
- `drives.php`: "Used/Free" column now displays "DEAD" for dead drives and "ONLINE" for online drives.
- `stats.php`: Excluded dead and online drives from "Drives With Scan Required" section.
- `style.css`: Added `dead-bar` and `online-bar` CSS classes for visual representation of drive status.

## [1.1.16] - 2025-09-07

### Added
- `browse.php`: Added debug output for path variables when debug mode is enabled.

### Changed
- `browse.php`: Changed "← Back to Home/Search" link to point to `drives.php`.

## [1.1.15] - 2025-09-07

### Fixed
- `browse.php`: Corrected SQL query for root directory browsing to properly display directories and files.
- `browse.php`: Resolved PHP parse error on line 122 by ensuring the SQL query string is correctly terminated.

## [1.1.14] - 2025-09-07

### Fixed
- `browse.php`: Standardized path handling in SQL queries to consistently expect paths with a leading slash, improving display reliability.
- `scan_drive.php`: Ensured all stored file paths in the `st_files` table consistently start with a leading slash, resolving inconsistencies.
- Addressed minor code style and HTML entity encoding issues in `browse.php`.

## [1.1.13] - 2025-09-07

### Changed
- `scan_drive.php`: Modified `exiftool_json` storage format to consistently store data as a JSON array, aligning with `exiftool`'s default output and improving compatibility with existing data.

## [1.1.12] - 2025-09-07

### Changed
- Bumped `app_version` to 1.1.12.

## [1.1.11] - 2025-08-24

### Changed
- `scan_drive.php` improvements:
    - Optimized database commit frequency using a time-based strategy with a count-based fallback.

## [1.1.10] - 2025-09-06

### Changed
- Bumped `app_version` to 1.1.10.

### Fixed
- **Scan Script (`scan_drive.php`):**
  - Improved `ExiftoolManager` resource management to prevent leaks by ensuring proper process cleanup during script termination.
  - Enhanced error recovery in the file processing loop, introducing exponential backoff and broader detection of filesystem errors (e.g., 'Permission denied', 'Input/output error').
  - Improved error handling and removed `@` suppressions for critical operations like `getimagesize`, `exif_read_data`, and `shell_exec` for `ffprobe` and `file` commands, ensuring better error context and preventing silent failures.

## [1.1.9] - 2025-09-06

### Added
- **Scan Script (`scan_drive.php`):**
  - Introduced `--safe-delay` command-line option to add a configurable delay (in microseconds) between I/O operations (exiftool, file command, MD5 hash calculation, thumbnail generation). This helps prevent I/O overload and server crashes on slower drives or USB connections.
  - Added `safe_delay_us` configuration option to `config.php.example` to set a default delay value.

## [1.1.8] - 2025-09-06

### Fixed
- **Scan Script (`scan_drive.php`):**
  - Corrected a variable scope issue in the `signal_handler` to ensure interruptions are reliably detected.
  - Refactored the shutdown handler to consistently use `GLOBALS` for accessing shared state, making it more robust.

### Removed
- **Scan Script (`scan_drive.php`):**
  - Removed the unused `$commitInterval` variable.

## [1.1.7] - 2025-09-06

### Fixed
- **Scan Script (`scan_drive.php`):**
  - Corrected a flaw in command-line argument parsing that caused flags like `--no-filetype` and `--no-exif` to be ignored.
  - Hardened the script by adding validation for the `memory_limit` value in the config.
  - Improved handling of `--help` and `--version` flags to ensure they are processed correctly.
  - Fixed a potential notice by initializing the `$GLOBALS["current_scanned_path"]` variable.
  - Added a more explicit check for the `--no-exif` flag in the metadata extraction logic.
  - Removed conflicting logic for the `--use-external-thumb-gen` flag to prevent unintended side effects.

## [1.1.6] - 2025-09-06

### Fixed
- **Scan Script (`scan_drive.php`):**
  - Corrected an issue where using multiple command-line options at once would fail.
  - Hardened argument parsing to enforce that options (e.g., `--no-exif`) must come before positional arguments (`drive_id`, etc.).
  - The script will now exit with an error if an unknown option is used.

## [1.1.5] - 2025-09-06

### Changed
- **Scan Script (`scan_drive.php`):**
  - Implemented `ExiftoolManager` to use a persistent `exiftool` process (`-stay_open` flag), significantly reducing overhead when extracting metadata from a large number of files. This avoids repeatedly launching the `exiftool` executable for each file.

## [1.1.4] - 2025-08-26

### Fixed
- **PowerShell Scan Script (`scan_drive.ps1`):**
  - Corrected `md5_hash` assignment within `PSCustomObject` by using a subexpression `$(...)` for conditional logic, resolving "The term 'if' is not recognized" error.

## [1.1.3] - 2025-08-26

### Fixed
- **PowerShell Scan Script (`scan_drive.ps1`):**
  - Added robust checks for null or empty ExifTool and FFprobe data to prevent "Cannot index into a null array" errors during metadata processing.

## [1.1.2] - 2025-08-26

### Fixed
- **PowerShell Scan Script (`scan_drive.ps1`):**
  - Ensured robust CSV output by explicitly casting all relevant fields to string, resolving `System.Object[]` display issues for `file_category`, `filetype`, and other metadata fields.

## [1.1.1] - 2025-08-26

### Fixed
- **PowerShell Scan Script (`scan_drive.ps1`):**
  - Resolved `System.Object[]` error in CSV output by robustly handling file categorization.
  - Corrected `filetype` parsing to accurately extract description from `fil` command output.
  - Eliminated leading garbage lines in CSV by fixing header generation.
  - Improved drive detection reliability and added Administrator check.

## [1.1.0] - 2025-08-26

### Added
- **External Scanning System:** Introduced a new system for scanning drives on external Windows machines.
  - Created a PowerShell script (`scan_drive.ps1`) to perform comprehensive, read-only scans and generate `.csv` (file data) and `.ini` (drive metadata) files.
  - Added a new "Import" page (`import_csv.php`) to the application for uploading these scan files.
  - The import page features a target drive selector with a serial number mismatch check for safety.
- **Documentation:** Added `README_EXTERNAL.md` with detailed instructions for the new external scanning feature.

### Changed
- The main navigation menu now includes a link to the "Import" page.
- Minor UI improvements to the Import page for consistency and readability.
- Footer version information is now centered.

### Fixed
- Resolved a PHP fatal error on the Import page related to `isset()` usage on older PHP versions.

## [1.0.21] - 2025-08-25

### Fixed
- Added missing HTML block in `browse.php` to correctly display thumbnails when `view_thumb` parameter is used.

## [1.0.20] - 2025-08-25

### Added
- Added debug logging for thumbnail display in `browse.php` to `logs/application.log` when `debug_mode` is enabled.

## [1.0.19] - 2025-08-25

### Changed
- Ensured that directory paths stored in the database from `scan_drive.php` always contain a trailing slash.

## [1.0.18] - 2025-08-25

### Fixed
- Improved file browsing in `browse.php` to correctly display files regardless of whether their paths in the database include a leading slash or not, addressing inconsistencies in path storage.

## [1.0.17] - 2025-08-25

### Fixed
- Corrected `rowCount()` logic in `scan_drive.php` for `ON DUPLICATE KEY UPDATE` to accurately track added/updated files and and ensure correct `fileId` retrieval for thumbnail generation.

## [1.0.16] - 2025-08-24

### Fixed
- Removed ETA calculation from `scan_drive.php` to prevent server crashes.

## [1.0.15] - 2025-08-24

### Fixed
- Improved ETA calculation logic in `scan_drive.php` to be more accurate and avoid unrealistic estimates.
- Refactored terminal output in `scan_drive.php` to use a dedicated `StatusDisplay` class, eliminating flickering and improving progress display.
- Replaced memory-intensive ETA discovery with efficient shell commands (`find` and `du`) to reduce memory consumption on large filesystems.
- Added a warning for when the `pcntl` extension is not loaded, making signal handling behavior more transparent.
- Enhanced the `--bypass-eta` flag to provide a simple progress indicator (items and size scanned) instead of disabling all progress feedback.

## [1.0.14] - 2025-08-24

### Fixed

- `scan_drive.php`: Further refined resume logic to explicitly ensure the last scanned file is processed upon resumption, addressing previous ambiguity.

## [1.0.13] - 2025-08-24

### Fixed

- `scan_drive.php`: Resolved "Class 'CommitManager' not found" error by moving the class definition outside of conditional blocks, ensuring it's always available.

## [1.0.12] - 2025-08-24

### Fixed

- `scan_drive.php`: Corrected resume logic to ensure the last scanned file is processed and not skipped.

### Changed

- `scan_drive.php`: Implemented time-based and count-based commit frequency for more robust progress saving.

## [1.0.11] - 2025-08-24

### Changed

- `scan_drive.php` improvements:
    - Optimized database commit frequency using a time-based strategy with a count-based fallback.

## [1.0.10] - 2025-08-24

### Changed

- `scan_drive.php` improvements:
    - Updated pcntl extension check for more robust validation.
    - Adjusted EXIF date validation cutoff to 1970 (Unix epoch start).
    - Implemented MD5 hashing optimization to skip redundant calculations for unchanged files.

## [1.0.9] - 2025-08-24

### Changed

- Security hardening for `scan_drive.php`:
    - Added root/sudo privilege check.
    - Added mount point read/write access validation.
    - Reverted MIME type detection to use `shell_exec('file -b ...')` as per user's requirement for exact output.

## [1.0.8] - 2025-08-23

### Added

- "Drives Scanned (Completed)" statistic added to `stats.php`.
- "Drives with SMART Issues" section added to `stats.php`, displaying a list of drives with identified SMART issues.
- "Used/Free" space bar added to `drives.php` table, showing usage percentage and "N/A" for unscanned drives.
- Enhanced hover text for "Used/Free" bar in `drives.php` to include used and free space in GB.

## [1.0.7] - 2025-08-23

### Added

- `analyzeSmartctlHistory` function in `helpers/smartctl_analyzer.php` to analyze historical `smartctl` data and report significant changes in drive state.
- `view_smartctl.php` now displays historical `smartctl` analysis results.
- `--smart-only` option added to `scan_drive.php` to allow saving only `smartctl` output without performing a full file scan.

## [1.0.6] - 2025-08-23

### Added

- New `st_smart` table created to store `smartctl` scan results.
- `scan_drive.php` now saves `smartctl` output for each drive scan into the `st_smart` table.
- "Smartctl" action added to `drives.php` to display `smartctl` data for a drive.

## [1.0.5] - 2025-08-23

### Added

- New `st_smart` table created to store `smartctl` scan results.
- `scan_drive.php` now saves `smartctl` output for each drive scan into the `st_smart` table.
- "Smartctl" action added to `drives.php` to display `smartctl` data for a drive.

## [1.0.4] - 2025-08-23

### Added

- Bulk file management actions for drives:
    - Mark all files associated with a drive as deleted (soft delete).
    - Permanently remove all file records for a drive from the database.
- `README.md` updated with latest features, dependency installation for Debian/Ubuntu, and corrected thumbnail generation information.

### Changed

- `app_version` bumped to 1.0.4.

## [1.0.3] - 2025-08-23

### Added

- File type information storage during scan:
    - `scan_drive.php` now executes `file -b` for each file.
    - File type information is stored in a new `filetype` column in the `st_files` table.

### Changed

- `app_version` bumped to 1.0.3.
- `db_schema_version` bumped to 1.0.3.

## [1.0.2] - 2025-08-23

### Added

- EXIF and thumbnail viewers in `browse.php`.

### Changed

- `app_version` bumped to 1.0.2.
- `scan_drive.php` now prevents re-generating thumbnails if they already exist.

## [1.0.1] - 2025-08-23

### Fixed

- Corrected stats display on resumed scan in `scan_drive.php`.

### Changed

- `app_version` bumped to 1.0.1.
- `db_schema_version` updated to 1.0.1.

## [1.0.0] - 2025-08-21

### Added

- Initial application and database versioning.
- Enhanced error logging for scan interruptions.
- EXIF data viewer and improved stats page.
- Scan history and integration with file tracking.
- 3-level directory structure for thumbnails.
- Asynchronous thumbnail generation and status tracking (later reverted to in-line).
- Scan script enhancements with resume and recovery options.
- Scan status to drives UI and dead drives stat.
- Functionality to add, edit, and delete drives.
- Strict delete confirmation for drives.
- Product name and version extraction for executables using `exiftool`.
- Partition numbers in file scanning.

### Fixed

- Invalid EXIF date formats and values handling.
- Bugs in executable scan handling.
- UI elements in Stats.
- Small bugs.
- SQL syntax in `stats.php`.
- Resume minor bugs.
- Schema update issues.
- SQL lock wait timeouts during scan initialization.
- Immediate script termination on Ctrl+C.
- Broken ASCII logo in `header.php`.