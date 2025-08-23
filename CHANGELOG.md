# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

