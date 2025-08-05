<?php
require 'header.php';
// Note: The formatBytes() helper function is now available from header.php

// --- Parameter Handling ---
$drive_id = filter_input(INPUT_GET, 'drive_id', FILTER_VALIDATE_INT);
if (!$drive_id) {
    die("Invalid Drive ID provided.");
}

// Sanitize and normalize the path to prevent directory traversal attacks
$current_path_raw = $_GET['path'] ?? '';
$current_path = trim(preg_replace('#/+#', '/', str_replace(['\\', '../'], '/', $current_path_raw)), '/');

// --- Data Fetching ---
$drive_info = null;
$files = [];
$error_message = '';

try {
    // 1. Get Drive Info
    $stmt = $pdo->prepare("SELECT name FROM st_drives WHERE id = ?");
    $stmt->execute([$drive_id]);
    $drive_info = $stmt->fetch();

    if (!$drive_info) {
        die("Drive with the specified ID was not found.");
    }

    // 2. Get File/Directory Listing for the current path
    $params = [$drive_id];
    if ($current_path === '') {
        // Root directory: find paths where the path, after removing a potential leading slash, contains no other slashes.
        $sql = "SELECT * FROM st_files WHERE drive_id = ? AND TRIM(LEADING '/' FROM path) NOT LIKE '%/%' AND date_deleted IS NULL ORDER BY is_directory DESC, filename ASC";
    } else {
        // Subdirectory: find paths that are one level deeper.
        // The path in the DB is stored with a leading slash (e.g., /movies/file.mkv),
        // while $current_path is 'movies'. We must prepend a slash for the LIKE to match.
        $sql = "SELECT * FROM st_files WHERE drive_id = ? AND path LIKE ? AND path NOT LIKE ? AND date_deleted IS NULL ORDER BY is_directory DESC, filename ASC";
        $path_prefix = '/' . $current_path . '/';
        $params[] = $path_prefix . '%';
        $params[] = $path_prefix . '%/%';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll();

} catch (\PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
}

/**
 * Generates breadcrumb navigation links.
 * @param int $drive_id
 * @param string $current_path
 * @return string HTML for the breadcrumbs.
 */
function generateBreadcrumbs(int $drive_id, string $current_path): string
{
    $base_url = "browse.php?drive_id={$drive_id}";
    $html = '<a href="' . $base_url . '">Root</a>';
    if ($current_path !== '') {
        $path_parts = explode('/', $current_path);
        $built_path = '';
        foreach ($path_parts as $part) {
            $built_path .= ($built_path === '' ? '' : '/') . $part;
            $html .= ' / <a href="' . $base_url . '&path=' . urlencode($built_path) . '">' . htmlspecialchars($part) . '</a>';
        }
    }
    return $html;
}

?>
<!-- The main content for browse.php starts here, header.php has already been included -->
<style>
    /* Page-specific styles for breadcrumbs */
    .breadcrumbs {
        margin-bottom: 20px;
        font-size: 1.1em;
        color: #c0c0c0;
    }
    .breadcrumbs a {
        color: #a9d1ff;
        text-decoration: none;
    }
    .breadcrumbs a:hover {
        text-decoration: underline;
    }
</style>
<h1>Browsing: <?= htmlspecialchars($drive_info['name']) ?></h1>
<div class="breadcrumbs">
    <?= generateBreadcrumbs($drive_id, $current_path) ?>
</div>

<a href="index.php" style="color: #a9d1ff; text-decoration: none; display: inline-block; margin-bottom: 20px;">&larr; Back to Home/Search</a>

        <?php if ($error_message): ?>
            <p class="error"><?= htmlspecialchars($error_message) ?></p>
        <?php elseif (empty($files)): ?>
            <p style="margin-top: 20px;">This directory is empty.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Modified</th>
                        <th>Format</th>
                        <th>Codec</th>
                        <th>Resolution</th>
                        <th>MD5 Hash</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file): ?>
                        <tr>
                            <td><span class="icon"><?= $file['is_directory'] ? '&#128193;' : '&#128441;' ?></span></td>
                            <td>
                                <?php if ($file['is_directory']): ?>
                                    <a href="browse.php?drive_id=<?= $drive_id ?>&path=<?= urlencode($file['path']) ?>"><?= htmlspecialchars($file['filename']) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($file['filename']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($file['file_category'] ?? '—') ?></td>
                            <td><?= $file['is_directory'] ? '—' : formatBytes($file['size']) ?></td>
                            <td><?= htmlspecialchars($file['ctime']) ?></td>
                            <td><?= htmlspecialchars($file['mtime']) ?></td>
                            <td><?= htmlspecialchars($file['media_format'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($file['media_codec'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($file['media_resolution'] ?? '—') ?></td>
                            <td>
                                <?php if (!empty($file['md5_hash'])): ?>
                                    <a href="files.php?filename=<?= htmlspecialchars($file['md5_hash']) ?>" title="Find all files with this hash"><?= htmlspecialchars($file['md5_hash']) ?></a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

<?php require 'footer.php'; ?>