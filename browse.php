<?php
require 'header.php';

// Load configuration
$config = require 'config.php';
$debug_mode = $config['debug_mode'] ?? false;

// --- Exif Data Viewer ---
$exif_file_id = filter_input(INPUT_GET, 'view_exif', FILTER_VALIDATE_INT);
$exif_data = null;
$exif_error = '';
if ($exif_file_id) {
    try {
        $stmt = $pdo->prepare("SELECT path, exiftool_json FROM st_files WHERE id = ?");
        $stmt->execute([$exif_file_id]);
        $file_data = $stmt->fetch();

        if ($file_data && !empty($file_data['exiftool_json'])) {
            $json_data = json_decode($file_data['exiftool_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($json_data[0])) {
                $exif_data = $json_data[0];
                // Remove the source file from the data to not repeat it
                unset($exif_data['SourceFile']);
            } else {
                $exif_error = 'Invalid JSON format in the database.';
            }
        } else {
            $exif_error = 'No EXIF data found for this file.';
        }
    } catch (\PDOException $e) {
        $exif_error = 'Error fetching EXIF data.';
        log_error("EXIF Fetch Error: " . $e->getMessage());
    }
}

// --- Thumbnail Viewer ---
$thumb_file_id = filter_input(INPUT_GET, 'view_thumb', FILTER_VALIDATE_INT);
$thumb_path = null;
$thumb_error = '';
if ($thumb_file_id) {
    try {
        $stmt = $pdo->prepare("SELECT path, thumbnail_path FROM st_files WHERE id = ?");
        $stmt->execute([$thumb_file_id]);
        $file_data = $stmt->fetch();

        if ($file_data && !empty($file_data['thumbnail_path'])) {
            $thumb_path = $file_data['thumbnail_path'];
        } else {
            $thumb_error = 'No thumbnail found for this file.';
            if ($debug_mode) {
                log_error("Debug: No thumbnail path found in DB for file ID: " . $thumb_file_id);
            }
        }
    } catch (PDOException $e) {
        $thumb_error = 'Error fetching thumbnail data.';
        log_error("Thumbnail Fetch Error: " . $e->getMessage());
    }
}

// --- Filetype Viewer ---
$filetype_file_id = filter_input(INPUT_GET, 'view_filetype', FILTER_VALIDATE_INT);
$filetype_data = null;
$filetype_error = '';
if ($filetype_file_id) {
    try {
        $stmt = $pdo->prepare("SELECT path, filetype FROM st_files WHERE id = ?");
        $stmt->execute([$filetype_file_id]);
        $file_data = $stmt->fetch();

        if ($file_data && !empty($file_data['filetype'])) {
            $filetype_data = $file_data['filetype'];
        } else {
            $filetype_error = 'No filetype data found for this file.';
        }
    } catch (\PDOException $e) {
        $filetype_error = 'Error fetching filetype data.';
        log_error("Filetype Fetch Error: " . $e->getMessage());
    }
}

// Note: The formatBytes() helper function is now available from header.php

// --- Parameter Handling ---
$drive_id = filter_input(INPUT_GET, 'drive_id', FILTER_VALIDATE_INT);
if (!$drive_id) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Invalid Drive ID provided.'];
    header('Location: drives.php');
    exit();
}

// Sanitize and normalize the path to prevent directory traversal attacks
$current_path_raw = $_GET['path'] ?? '';
$current_path = trim(preg_replace('#/+#', '/', str_replace(['\', '../'], '/', $current_path_raw)), '/');


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
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Drive with the specified ID was not found.'];
        header('Location: drives.php');
        exit();
    }

    // 2. Get File/Directory Listing for the current path
    $params = [$drive_id];
    if ($current_path === '') {
        // Root directory: find paths that are direct children of the root.
        // Now assuming all paths in DB have a leading slash.
        $sql = "SELECT * FROM st_files WHERE drive_id = ? AND path NOT LIKE '%/%' AND date_deleted IS NULL ORDER BY is_directory DESC, filename ASC";
    } else {
        // Subdirectory: find paths that are one level deeper.
        // Assuming all paths in DB have a leading slash.
        $sql = "SELECT * FROM st_files WHERE drive_id = ? AND date_deleted IS NULL AND (path LIKE ? AND path NOT LIKE ?) ORDER BY is_directory DESC, filename ASC";

        $path_prefix_with_slash = '/' . $current_path . '/';

        // Add parameters for the single case (paths with leading slash)
        $params[] = $path_prefix_with_slash . '%';
        $params[] = $path_prefix_with_slash . '%/%';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll();

} catch (\PDOException $e) {
    log_error("Database Error in browse.php: " . $e->getMessage());
    $error_message = "An unexpected database error occurred. Please try again.";
}

/**
 * Generates breadcrumb navigation links.
 * @param int $drive_id
 * @param string $current_path
 * @return string HTML for the breadcrumbs.
 */
function generateBreadcrumbs(int $drive_id, string $current_path): string
{
    $base_url = "browse.php?drive_id=".$drive_id;
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

<?php if ($exif_data): ?>
<div class="exif-viewer">
    <h2>EXIF Data for: <?= htmlspecialchars($file_data['path']) ?></h2>
    <a href="?<?= http_build_query(array_merge($_GET, ['view_exif' => null])) ?>" class="close-exif">Close</a>
    <pre><?= htmlspecialchars(print_r($exif_data, true)) ?></pre>
</div>
<?php elseif ($exif_error): ?>
<div class="exif-viewer error">
    <p><?= htmlspecialchars($exif_error) ?></p>
    <a href="?<?= http_build_query(array_merge($_GET, ['view_exif' => null])) ?>">Close</a>
</div>
<?php endif; ?>

<?php if ($filetype_data): ?>
<div class="filetype-viewer">
    <h2>Filetype for: <?= htmlspecialchars($file_data['path']) ?></h2>
    <a href="?<?= http_build_query(array_merge($_GET, ['view_filetype' => null])) ?>" class="close-filetype">Close</a>
    <pre><?= htmlspecialchars($filetype_data) ?></pre>
</div>
<?php elseif ($filetype_error): ?>
<div class="filetype-viewer error">
    <p><?= htmlspecialchars($filetype_error) ?></p>
    <a href="?<?= http_build_query(array_merge($_GET, ['view_filetype' => null])) ?>">Close</a>
</div>
<?php endif; ?>

<?php if ($thumb_path): ?>
<div class="thumbnail-viewer">
    <h2>Thumbnail for: <?= htmlspecialchars($file_data['path']) ?></h2>
    <a href="?<?= http_build_query(array_merge($_GET, ['view_thumb' => null])) ?>" class="close-thumb">Close</a>
    <img src="<?= htmlspecialchars($thumb_path) ?>" alt="Thumbnail">
</div>
<?php elseif ($thumb_error): ?>
<div class="thumbnail-viewer error">
    <p><?= htmlspecialchars($thumb_error) ?></p>
    <a href="?<?= http_build_query(array_merge($_GET, ['view_thumb' => null])) ?>">Close</a>
</div>
<?php endif; ?>

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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file):
                        ?>
                        <tr>
                            <td><span class="icon"><?= $file['is_directory'] ? '&#128193;' : '&#128441;' ?></span></td>
                            <td>
                                <?php if ($file['is_directory']):
                                    ?><a href="browse.php?drive_id=<?= $drive_id ?>&amp;path=<?= urlencode($file['path']) ?>"><?= htmlspecialchars($file['filename']) ?></a><?php
                                else:
                                    ?><?= htmlspecialchars($file['filename']) ?><?php
                                endif; ?>
                            </td>
                            <td><?= htmlspecialchars($file['file_category'] ?? '—') ?></td>
                            <td><?= $file['is_directory'] ? '—' : formatBytes($file['size']) ?></td>
                            <td><?= htmlspecialchars($file['ctime']) ?></td>
                            <td><?= htmlspecialchars($file['mtime']) ?></td>
                            <td><?= htmlspecialchars($file['media_format'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($file['media_codec'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($file['media_resolution'] ?? '—') ?></td>
                            <td>
                                <?php if (!empty($file['md5_hash'])):
                                    ?><a href="files.php?filename=<?= htmlspecialchars($file['md5_hash']) ?>" title="Find all files with this hash"><?= htmlspecialchars($file['md5_hash']) ?></a><?php
                                else:
                                    ?>—<?php
                                endif; ?>
                            </td>
                            <td class="actions-cell">
                                <?php if (!$file['is_directory'] && !empty($file['exiftool_json'])):
                                    ?><a href="?<?= http_build_query(array_merge($_GET, ['view_exif' => $file['id']])) ?>" class="action-btn">Exif</a><?php
                                endif; ?>
                                <?php if (!$file['is_directory'] && !empty($file['thumbnail_path'])):
                                    ?><?php if (!empty($file['exiftool_json'])): ?> | <?php endif; ?><a href="?<?= http_build_query(array_merge($_GET, ['view_thumb' => $file['id']])) ?>" class="action-btn">Thumb</a><?php
                                endif; ?>
                                <?php if (!$file['is_directory'] && !empty($file['filetype'])):
                                    ?><?php if (!empty($file['exiftool_json']) || !empty($file['thumbnail_path'])): ?> | <?php endif; ?><a href="?<?= http_build_query(array_merge($_GET, ['view_filetype' => $file['id']])) ?>" class="action-btn">Filetype</a><?php
                                endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

<?php require 'footer.php'; ?>