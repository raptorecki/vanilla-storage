# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.15] - 2025-08-24

### Fixed

- `scan_drive.php`: Corrected ETA display to always include a newline character.

### Changed

- `scan_drive.php`: Implemented conditional updates for `bytes_processed` in `st_scans` table, ensuring compatibility with older schemas.
- `scan_drive.php`: Improved ETA accuracy by basing calculations on item count rather than bytes processed.

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
- Redirection issue in `delete_drive.php`.
- Display flash messages immediately after drive actions.
- Standardized form actions and repaired `edit_drive.php` page.

### Changed

- Reverted to in-line thumbnail generation from asynchronous.
- Improved scan logic and stats accuracy.
- Improved project setup and error handling.
- Updated various PHP files.
- Added common development files and directories to `.gitignore`.

