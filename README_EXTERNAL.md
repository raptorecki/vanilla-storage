# External Drive Scanning and Import System

## 1. Overview

This document outlines the architecture and functionality of the external drive scanning system for the Vanilla Storage application. The system is designed to allow a comprehensive, offline scan of a storage drive on a separate machine (specifically Windows) and then import the collected data into the main application database.

This approach is ideal for drives that cannot be directly connected to the application server, expanding the application's utility for cataloging distributed storage assets.

The system consists of two primary components:
1.  A **PowerShell Scan Script** (`scan_drive.ps1`) that runs on the Windows machine, which produces two separate files.
2.  A **PHP Import Script** (`import_csv.php`) integrated into the main web application, which consumes these files.

---

## 2. Component 1: PowerShell Scan Script (`scan_drive.ps1`)

This script is the data collection engine. It performs a deep, read-only analysis of a specified drive and compiles the results into two portable files: a `.csv` file for file data and a `.ini` file for drive metadata.

### 2.1. Purpose

- To be executed on a Windows computer where the target drive is connected.
- To analyze and identify the drive itself using hardware identifiers.
- To recursively scan all files and directories on a specified partition.
- To extract a rich set of metadata from each file.
- To output all collected data into two distinct, linked files.

### 2.2. Prerequisites

The following third-party command-line tools **must** be installed on the Windows machine and be accessible to the PowerShell script (either by being in the same directory or by being listed in the system's `PATH` environment variable):

- **`hdparm.exe`**: For reading drive model and serial numbers.
- **`smartctl.exe`**: For reading detailed SMART health data.
- **`fil.exe`**: For accurately identifying file types based on content.
- **`exiftool.exe`**: For extracting rich metadata from images, executables, and other file types.
- **`ffprobe.exe`**: (Part of the FFmpeg suite) For extracting technical metadata from video and audio files.

### 2.3. Usage

The script is run from a PowerShell terminal with two mandatory parameters:

```powershell
.\scan_drive.ps1 -DriveLetter <drive_letter> -PartitionNumber <partition_number>
```

- **`-DriveLetter`**: The letter of the drive to be scanned (e.g., `D:`).
- **`-PartitionNumber`**: The integer number of the partition being scanned on that physical drive (e.g., `1`).

**Example:**
```powershell
.\scan_drive.ps1 -DriveLetter E: -PartitionNumber 1
```

### 2.4. Execution Flow

1.  **Drive Identification**: The script begins by running `hdparm -I` to get the drive's **Serial Number**, which is used for naming the output files. It also captures the **Model Number** and **Filesystem Type**.
2.  **`.ini` File Generation**:
    - The script creates a text file named `<SerialNumber>.ini` (e.g., `A04178218.ini`).
    - It writes all drive-specific information into this file in an INI-like format.
3.  **`.csv` File Generation**:
    - The script then performs the recursive file scan.
    - It creates a CSV file named `<SerialNumber>.csv` (e.g., `A04178218.csv`).
    - For each file found, it gathers the required metadata and writes one row to the CSV. The columns of this file are designed to directly reflect the structure of the `st_files` database table.

### 2.5. Output File Formats

#### 2.5.1. Drive Info File (`<SerialNumber>.ini`)

This file contains metadata about the physical drive itself.

**Example Format:**
```ini
[DriveInfo]
Model=WD Blue SN550
Serial=A0417821al
Filesystem=NTFS

[SMART]
Data=
--- SMART DATA START ---
smartctl 7.5 2025-04-30 r5714...
... (full multi-line smartctl -a output) ...
--- SMART DATA END ---
```

#### 2.5.2. Files Data File (`<SerialNumber>.csv`)

This file contains one row for each file or directory scanned. Its columns are a subset of the `st_files` table, representing data that can be collected from a scan.

**CSV Columns:**
`partition_number`, `path`, `path_hash`, `filename`, `size`, `md5_hash`, `media_format`, `media_codec`, `media_resolution`, `ctime`, `mtime`, `file_category`, `is_directory`, `media_duration`, `exif_date_taken`, `exif_camera_model`, `product_name`, `product_version`, `exiftool_json`, `filetype`

---

## 3. Component 2: PHP Import Script (`import_csv.php`)

This script provides the web interface within the Vanilla Storage application to import the data from the generated files.

### 3.1. Purpose

- To provide a secure and user-friendly way to upload the scan files.
- To intelligently parse the files and validate the drive against the database.
- To safely update the database with the new file and drive information.
- To handle the addition of new files, the updating of existing files, and the marking of deleted files.

### 3.2. Processing Logic

1.  **UI**: The user is presented with a web page containing two file upload inputs:
    - **CSV File Upload** (Required)
    - **INI File Upload** (Optional)
2.  **Drive Verification**:
    - The script derives the **drive's serial number** from the filename of the uploaded `.csv` file (e.g., `A04178218.csv` -> `A04178218`).
    - It queries the `st_drives` table to find the `drive_id` for a drive with a matching serial number.
    - **Error Handling**: If no matching drive is found, the import is aborted, and an error message instructs the user to register the drive first.
3.  **INI Processing (if provided)**:
    - If the `.ini` file is also uploaded, the script will parse it.
    - It will update the drive's `model_number` in the `st_drives` table.
    - It will insert or update the `smartctl` data in the `st_smart` table.
4.  **Database Transaction**: The script initiates a database transaction to ensure data integrity.
5.  **Marking Deleted Files**:
    - The script first runs an `UPDATE` query to set `date_deleted = NOW()` for **all files** currently associated with the `drive_id`. This marks every file as "deleted" before the import begins.
6.  **Upserting File Data**:
    - The script iterates through each row of the CSV file.
    - For each file, it prepares an `INSERT ... ON DUPLICATE KEY UPDATE` query for the `st_files` table, using the `path_hash` as the unique key. It adds the `drive_id` obtained during verification to each record.
    - This query will **Insert** new files, **Update** existing files, and set their `date_deleted` field back to `NULL`.
7.  **Commit**: Once all rows are processed, the transaction is committed.
8.  **Feedback**: The UI reports the successful completion of the import with statistics.

---

## 4. End-to-End Workflow

1.  **On the Windows Machine**:
    - Connect the drive and run `scan_drive.ps1` with the correct drive letter and partition number.
    - The script will generate two files: `<SerialNumber>.csv` and `<SerialNumber>.ini`.
2.  **Transfer**:
    - Move both generated files to a computer with access to the Vanilla Storage web application.
3.  **In the Vanilla Storage Application**:
    - Navigate to the `import_csv.php` page.
    - Upload the `.csv` file (required) and the `.ini` file (optional).
    - The script processes the files and updates the database.
    - Once complete, the user can browse the newly synchronized drive contents.
