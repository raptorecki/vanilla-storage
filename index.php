<?php
require_once __DIR__ . '/helpers/error_logger.php';
require 'header.php';
?>
<!-- The main content for index.php starts here, header.php has already been included -->
<h1>Welcome to Vanilla Storage Tracker</h1>

<p style="text-align: center; font-size: 1.2em; margin-bottom: 40px;">
    Your central hub for managing and searching your storage drive inventory.
</p>

<div class="search-forms-container">
    <div class="search-form-wrapper">
        <h2>Search Drives</h2>
        <p>Find a specific drive by its name, serial number, model, and more.</p>
        <form method="GET" action="drives.php" class="search-container">
            <input type="text" name="search" placeholder="Search for a drive...">
            <button type="submit">Search Drives</button>
        </form>
    </div>

    <div class="search-form-wrapper">
        <h2>Search Files</h2>
        <p>Perform a detailed search for files by name, path, or MD5 hash.</p>
        <form method="GET" action="files.php" class="search-container">
            <input type="text" name="filename" placeholder="Search for a file...">
            <button type="submit">Search Files</button>
        </form>
    </div>
</div>

<?php require 'footer.php'; ?>
