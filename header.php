<?php
session_start(); // Start the session to handle flash messages

require_once __DIR__ . '/helpers/error_logger.php';
require_once 'database.php';

// --- Helper Functions ---

/**
 * Formats a size in gigabytes into a more readable format (GB or TB).
 */
function formatSize(int $sizeInGB): string
{
    if ($sizeInGB >= 1000) {
        return round($sizeInGB / 1000, 2) . ' TB';
    }
    return $sizeInGB . ' GB';
}

/**
 * Formats a size in bytes into a more readable format (B, KB, MB, GB, TB).
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    if ($bytes <= 0) return '0 B';
    $base = log($bytes, 1024);
    $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage Drive Inventory</title>
    <style>
        /* --- Elegant Dark Mode CSS --- */
        body {
            background-color: #121212;
            color: #e0e0e0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }
        .logo {
            font-family: "Courier New", Courier, monospace;
            white-space: pre;
            text-align: center;
            color: #a9d1ff;
            padding: 20px 0 10px 0;
            font-size: 0.9em;
            line-height: 1.1;
        }
        nav {
            background-color: #1f1f1f;
            border-bottom: 1px solid #333;
            text-align: center;
            padding: 5px 0;
            margin-bottom: 20px;
        }
        nav a {
            color: #e0e0e0;
            text-decoration: none;
            padding: 15px 25px;
            display: inline-block;
            font-weight: bold;
        }
        nav a:hover {
            background-color: #2a2a2a;
        }
        nav a.active {
            color: #ffffff;
            background-color: #3a7ab8;
        }
        h1 {
            color: #ffffff;
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-top: 0;
        }
        h2 {
            color: #ffffff;
            border-bottom: 1px solid #333;
            padding-bottom: 8px;
            margin-top: 40px;
        }
        .container {
            max-width: 95%;
            margin: 0 auto;
            padding: 0 20px 20px 20px;
            overflow-x: auto;
        }
        .error {
            background-color: #5d1a1a; color: #ffc4c4; padding: 15px;
            border: 1px solid #a53030; border-radius: 5px; margin-bottom: 20px;
        }
        .flash-message {
            padding: 15px; border-radius: 5px; margin-bottom: 20px; color: #ffffff;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.5);
            font-size: 1.1em;
        }
        .flash-message.success {
            background-color: #1a5d2b; border: 1px solid #30a54a;
        }
        .search-container {
            margin-bottom: 25px; display: flex; gap: 10px;
        }
        .search-container input[type="text"] {
            flex-grow: 1; background-color: #2b2b2b; border: 1px solid #444;
            color: #e0e0e0; padding: 10px 15px; border-radius: 4px; font-size: 1em;
        }
        .search-container input[type="text"]:focus {
            outline: none; border-color: #5a9fd4;
        }
        .search-container button, .search-container a {
            background-color: #3a7ab8; color: #ffffff; border: none;
            padding: 10px 20px; border-radius: 4px; cursor: pointer;
            font-size: 1em; text-decoration: none; white-space: nowrap;
        }
        details {
            background-color: #1f1f1f; border: 1px solid #333;
            border-radius: 5px; margin-bottom: 25px;
        }
        summary {
            padding: 15px; font-weight: bold; cursor: pointer; outline: none;
        }
        .form-container {
            padding: 0 20px 20px 20px; display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        .form-group {
            display: flex; flex-direction: column;
        }
        .form-group label {
            margin-bottom: 5px; font-size: 0.9em; color: #c0c0c0;
        }
        .form-group input, .form-group textarea, .form-group select {
            background-color: #2b2b2b; border: 1px solid #444; color: #e0e0e0;
            padding: 10px; border-radius: 4px; font-size: 1em;
        }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            outline: none; border-color: #5a9fd4;
        }
        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            align-items: center;
        }
        .form-actions button {
            background-color: #3a7ab8; color: #ffffff; border: none;
            padding: 12px 20px; border-radius: 4px; cursor: pointer;
            font-size: 1em; font-weight: bold;
        }
        .form-actions a.button {
            background-color: #6c757d; /* A neutral/secondary color for cancel/back actions */
            color: #ffffff;
            padding: 12px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 1em;
            font-weight: bold;
        }
        .form-actions button:hover {
            background-color: #4a8ac8;
        }
        .form-actions a.button:hover {
            background-color: #5a6268;
        }
        table {
            width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 0.9em;
        }
        th, td {
            border: 1px solid #333; padding: 12px 15px;
            text-align: left; white-space: nowrap;
        }
        thead th {
            background-color: #1f1f1f; color: #f5f5f5; font-weight: bold;
            position: sticky; top: 0;
        }
        tbody tr:nth-child(even) {
            background-color: #1a1a1a;
        }
        tbody tr:hover {
            background-color: #2a2a2a;
        }
        thead th a {
            color: inherit; text-decoration: none; display: block;
        }
        thead th a:hover {
            color: #ffffff;
        }
        tbody td a {
            color: #a9d1ff; text-decoration: none;
        }
        tbody td a:hover {
            text-decoration: underline;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .stat-card {
            background-color: #1f1f1f;
            border: 1px solid #333;
            padding: 20px;
            border-radius: 5px;
        }
        .stat-card h3 {
            margin-top: 0;
            color: #a9d1ff;
        }
        .stat-card p {
            font-size: 2em;
            margin: 0;
            font-weight: bold;
            color: #ffffff;
        }
        .stat-card ul {
            padding-left: 20px;
            margin-top: 10px;
        }
        .search-forms-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 50px;
        }
        .search-form-wrapper {
            background-color: #1f1f1f;
            border: 1px solid #333;
            padding: 25px;
            border-radius: 5px;
        }
        .search-form-wrapper h2 {
            margin-top: 0;
            border-bottom: none;
        }
        .search-form-wrapper p {
            color: #c0c0c0;
            margin-bottom: 20px;
        }
        @media (max-width: 800px) {
            .search-forms-container { grid-template-columns: 1fr; }
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 12px;
            border: 1px solid #333;
            background-color: #1f1f1f;
            color: #a9d1ff;
            text-decoration: none;
            border-radius: 4px;
        }
        .pagination a:hover {
            background-color: #3a7ab8;
            color: #fff;
            border-color: #3a7ab8;
        }
        .pagination span {
            background-color: transparent;
            border-color: transparent;
            color: #e0e0e0;
            font-weight: bold;
        }
        .progress-bar-container {
            background-color: #2b2b2b;
            border-radius: 4px;
            height: 22px;
            width: 100%;
            min-width: 100px;
            position: relative;
            border: 1px solid #444;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            transition: width 0.4s ease-in-out;
        }
        .progress-bar.green { background-color: #4caf50; }
        .progress-bar.yellow { background-color: #ffc107; }
        .progress-bar.red { background-color: #f44336; }
        .progress-bar-text {
            position: absolute;
            width: 100%;
            text-align: center;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #ffffff;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.7);
        }
    </style>
</head>
<body>
<div class="logo">
 ___      ___  __      _____  ___    __    ___      ___            __            ________  ___________  ______     _______        __       _______    _______ 
|"  \    /"  |/""\    (\"   \|"  \  |" \  |"  |    |"  |          /""\          /"       )("     _   ")/    " \   /"      \      /""\     /" _   "|  /"     "|
 \   \  //  //    \   |.\\   \    | ||  | ||  |    ||  |         /    \        (:   \___/  )__/  \\__/// ____  \ |:        |    /    \   (: ( \___) (: ______)
  \\  \/. .//' /\  \  |: \.   \\  | |:  | |:  |    |:  |        /' /\  \        \___  \       \\_ /  /  /    ) :)|_____/   )   /' /\  \   \/ \       \/    |  
   \.    ////  __'  \ |.  \    \. | |.  |  \  |___  \  |___    //  __'  \        __/  \\      |.  | (: (____/ //  //      /   //  __'  \  //  \ ___  // ___)_ 
    \\   //   /  \\  \|    \    \ | /\  |\( \_|:  \( \_|:  \  /   /  \\  \      /" \   :)     \:  |  \        /  |:  __   \  /   /  \\  \(:   _(  _|(:      "|
     \__/(___/    \___)\___|\____\)(__\_|_)\_______)\_______)(___/    \___)    (_______/       \__|   \"_____/   |__|  \___)(___/    \___)\_______)  \_______)
                                                                                                                                                               
 </div>
<nav>
    <a href="index.php" class="<?= $current_page == 'index.php' ? 'active' : '' ?>">Home</a>
    <a href="drives.php" class="<?= $current_page == 'drives.php' ? 'active' : '' ?>">Drives</a>
    <a href="files.php" class="<?= $current_page == 'files.php' ? 'active' : '' ?>">Files</a>
    <a href="scans.php" class="<?= $current_page == 'scans.php' ? 'active' : '' ?>">Scans</a>
    <a href="stats.php" class="<?= $current_page == 'stats.php' ? 'active' : '' ?>">Stats</a>
</nav>
<div class="container">

<?php
// Display flash message from session if it exists
if (isset($_SESSION['flash_message'])):
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
?>
    <div class="flash-message <?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
<?php endif; ?>