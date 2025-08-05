# Vanilla Storage Tracker

A simple PHP and MySQL-based application for tracking storage drives and their contents.

## Features

*   Add, edit, and delete storage drives.
*   Scan drives to index their files.
*   Search for drives and files.
*   View statistics about your storage.

## Setup

1.  Create a MySQL database.
2.  Import the `storage_schema.sql` file to create the necessary tables.
3.  Copy `config.php.example` to `config.php` and update it with your database credentials.
    **Important:** Ensure you update `config.php` with your actual database credentials and other necessary configurations.
4.  Run `sudo php scan_drive.php <drive_id> <mount_point>` to index your drives.
