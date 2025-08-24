# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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