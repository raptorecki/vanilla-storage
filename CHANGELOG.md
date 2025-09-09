# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
  - Fixed a potential notice by initializing the `$GLOBALS['current_scanned_path']` variable.
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
- Corrected `rowCount()` logic in `scan_drive.php` for `ON DUPLICATE KEY UPDATE` to accurately track added/updated files and ensure correct `fileId` retrieval for thumbnail generation.

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

- New `analyzeSmartctlOutput` helper function in `helpers/smartctl_analyzer.php` to parse and analyze `smartctl` output.
- `view_smartctl.php` now displays a summarized analysis of `smartctl` data, including overall status and identified issues, before showing the raw output.
- Added CSS styling for `smartctl` analysis results in `style.css`.

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