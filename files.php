<?php
require 'header.php';

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

// --- Search & Pagination Configuration ---
$search_params = [
    'filename' => trim($_GET['filename'] ?? ''), // This now accepts filename, path, or MD5
    'drive_id' => filter_input(INPUT_GET, 'drive_id', FILTER_VALIDATE_INT),
    'partition_number' => filter_input(INPUT_GET, 'partition_number', FILTER_VALIDATE_INT),
    'category' => trim($_GET['category'] ?? ''),
    'size_min' => filter_input(INPUT_GET, 'size_min', FILTER_VALIDATE_FLOAT),
    'size_max' => filter_input(INPUT_GET, 'size_max', FILTER_VALIDATE_FLOAT),
    'size_unit' => $_GET['size_unit'] ?? 'MB',
    'mtime_after' => trim($_GET['mtime_after'] ?? ''),
    'mtime_before' => trim($_GET['mtime_before'] ?? ''),
    'codec' => trim($_GET['codec'] ?? ''),
    'resolution' => trim($_GET['resolution'] ?? ''),
    'product_name' => trim($_GET['product_name'] ?? ''),
    'product_version' => trim($_GET['product_version'] ?? ''),
];

$allowed_page_sizes = [10, 20, 50, 100, 200, 500, 1000];
$page_size = filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT);
if (!$page_size || !in_array($page_size, $allowed_page_sizes)) {
    $page_size = 20;
}

$current_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$offset = ($current_page - 1) * $page_size;

$is_search_active = !empty(array_filter($search_params));

$files = [];
$total_results = 0;
$total_pages = 0;
$error_message = '';

try {
    // Fetch drives and categories for the search form dropdowns
    $drives_for_form = $pdo->query("SELECT id, name FROM st_drives ORDER BY name ASC")->fetchAll();
    $categories_for_form = $pdo->query("SELECT DISTINCT file_category FROM st_files WHERE file_category IS NOT NULL AND file_category != 'Directory' ORDER BY file_category ASC")->fetchAll();

    if ($is_search_active) {
        $where_clauses = ["f.date_deleted IS NULL", "f.is_directory = 0"];
        $params = [];

        if ($search_params['filename']) {
            $filename_search = $search_params['filename'];
            // If the search term is a 32-char hex string, assume it could be an MD5 hash
            if (preg_match('/^[a-f0-9]{32}$/i', $filename_search)) {
                $where_clauses[] = "(f.filename LIKE ? OR f.path LIKE ? OR f.md5_hash = ?)";
                $params[] = "%{$filename_search}%";
                $params[] = "%{$filename_search}%";
                $params[] = $filename_search;
            } else {
                $where_clauses[] = "(f.filename LIKE ? OR f.path LIKE ?)";
                $params[] = "%{$filename_search}%";
                $params[] = "%{$filename_search}%";
            }
        }
        if ($search_params['drive_id']) {
            $where_clauses[] = "f.drive_id = ?";
            $params[] = $search_params['drive_id'];
        }
        if ($search_params['partition_number']) {
            $where_clauses[] = "f.partition_number = ?";
            $params[] = $search_params['partition_number'];
        }
        if ($search_params['category']) {
            $where_clauses[] = "f.file_category = ?";
            $params[] = $search_params['category'];
        }
        if ($search_params['mtime_after']) {
            $where_clauses[] = "f.mtime >= ?";
            $params[] = $search_params['mtime_after'];
        }
        if ($search_params['mtime_before']) {
            $where_clauses[] = "f.mtime <= ?";
            $params[] = $search_params['mtime_before'];
        }
        if ($search_params['codec']) {
            $where_clauses[] = "f.media_codec LIKE ?";
            $params[] = "%{$search_params['codec']}%";
        }
        if ($search_params['resolution']) {
            $where_clauses[] = "f.media_resolution = ?";
            $params[] = $search_params['resolution'];
        }

        // Handle size conversion from units (MB, GB) to bytes
        $size_multiplier = ($search_params['size_unit'] === 'GB') ? 1073741824 : 1048576;
        if ($search_params['size_min']) {
            $where_clauses[] = "f.size >= ?";
            $params[] = $search_params['size_min'] * $size_multiplier;
        }
        if ($search_params['size_max']) {
            $where_clauses[] = "f.size <= ?";
            $params[] = $search_params['size_max'] * $size_multiplier;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Get total count for pagination
        $count_stmt = $pdo->prepare("SELECT COUNT(f.id) FROM st_files f WHERE $where_sql");
        $count_stmt->execute($params);
        $total_results = $count_stmt->fetchColumn();
        $total_pages = ceil($total_results / $page_size);

        // Get the actual results for the current page
        $sql = "SELECT f.*, d.name as drive_name FROM st_files f JOIN st_drives d ON f.drive_id = d.id WHERE $where_sql ORDER BY f.mtime DESC LIMIT ? OFFSET ?";
        $params[] = $page_size;
        $params[] = $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $files = $stmt->fetchAll();
    }
} catch (\PDOException $e) {
    $error_message = "An unexpected error occurred while searching for files. Please try again.";
    log_error("Database Error in files.php: " . $e->getMessage());
}
?>

<h1>Advanced File Search</h1>

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

<form method="GET" action="files.php" class="search-container" style="flex-direction: column; gap: 20px;">
    <div style="display: flex; gap: 10px;">
        <input type="text" name="filename" placeholder="Search by filename, path, or MD5 hash..." value="<?= htmlspecialchars($search_params['filename']) ?>">
        <button type="submit">Search</button>
        <a href="files.php">Clear</a>
    </div>

    <details>
        <summary>Advanced Search Options</summary>
        <div class="form-container" style="padding-top: 15px;">
            <div class="form-group"><label>Drive</label><select name="drive_id"><option value="">Any Drive</option><?php foreach ($drives_for_form as $drive): ?><option value="<?= $drive['id'] ?>" <?= ($search_params['drive_id'] == $drive['id']) ? 'selected' : '' ?>><?= htmlspecialchars($drive['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Partition</label><input type="number" name="partition_number" placeholder="e.g., 1" value="<?= htmlspecialchars($search_params['partition_number'] ?? '') ?>"></div>
            <div class="form-group"><label>Category</label><select name="category"><option value="">Any Category</option><?php foreach ($categories_for_form as $cat): ?><option value="<?= htmlspecialchars($cat['file_category']) ?>" <?= ($search_params['category'] == $cat['file_category']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['file_category']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Min Size</label><input type="number" step="any" name="size_min" placeholder="e.g., 500" value="<?= htmlspecialchars($search_params['size_min'] ?? '') ?>"></div>
            <div class="form-group"><label>Max Size</label><input type="number" step="any" name="size_max" placeholder="e.g., 1024" value="<?= htmlspecialchars($search_params['size_max'] ?? '') ?>"></div>
            <div class="form-group"><label>Size Unit</label><select name="size_unit"><option value="MB" <?= $search_params['size_unit'] == 'MB' ? 'selected' : '' ?>>MB</option><option value="GB" <?= $search_params['size_unit'] == 'GB' ? 'selected' : '' ?>>GB</option></select></div>
            <div class="form-group"><label>Modified After</label><input type="date" name="mtime_after" value="<?= htmlspecialchars($search_params['mtime_after']) ?>"></div>
            <div class="form-group"><label>Modified Before</label><input type="date" name="mtime_before" value="<?= htmlspecialchars($search_params['mtime_before']) ?>"></div>
            <div class="form-group"><label>Codec</label><input type="text" name="codec" placeholder="e.g., h264, flac" value="<?= htmlspecialchars($search_params['codec']) ?>"></div>
            <div class="form-group"><label>Resolution</label><input type="text" name="resolution" placeholder="e.g., 1920x1080" value="<?= htmlspecialchars($search_params['resolution']) ?>"></div>
            <div class="form-group"><label>Product Name</label><input type="text" name="product_name" placeholder="e.g., Microsoft Office" value="<?= htmlspecialchars($search_params['product_name'] ?? '') ?>"></div>
            <div class="form-group"><label>Product Version</label><input type="text" name="product_version" placeholder="e.g., 1.0.0.0" value="<?= htmlspecialchars($search_params['product_version'] ?? '') ?>"></div>
        </div>
    </details>

    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 10px;">
        <label for="page_size">Results per page:</label>
        <select name="page_size" id="page_size" onchange="this.form.submit()">
            <?php foreach ($allowed_page_sizes as $size): ?>
                <option value="<?= $size ?>" <?= ($page_size == $size) ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if ($error_message): ?>
    <p class="error"><?= htmlspecialchars($error_message) ?></p>
<?php elseif ($is_search_active): ?>
    <h2>Search Results</h2>
    <p>Found <?= number_format($total_results) ?> result(s). Page <?= $current_page ?> of <?= $total_pages ?>.</p>

    <?php if (empty($files)): ?>
        <p>No files found matching your criteria.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Drive</th>
                    <th>Partition</th>
                    <th>Filename</th>
                    <th>Path</th>
                    <th>Category</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th>Format</th>
                    <th>Codec</th>
                    <th>Resolution</th>
                    <th>Product Name</th>
                    <th>Product Version</th>
                    <th>MD5 Hash</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $file): ?>
                    <tr>
                        <td><a href="browse.php?drive_id=<?= htmlspecialchars($file['drive_id']) ?>"><?= htmlspecialchars($file['drive_name']) ?></a></td>
                        <td><?= htmlspecialchars($file['partition_number']) ?></td>
                        <td>
                            <a href="files.php?filename=<?= urlencode(basename($file['path'])) ?>" title="Search for this filename">
                                <?= htmlspecialchars(basename($file['path'])) ?>
                            </a>
                        </td>
                        <td>
                            <?php
                                $dir_path = dirname($file['path']);
                                $display_path = ($dir_path === '.' || $dir_path === '/') ? '/' : $dir_path;
                                $link_path = ($dir_path === '.' || $dir_path === '/') ? '' : ltrim($dir_path, '/');
                            ?>
                            <a href="browse.php?drive_id=<?= htmlspecialchars($file['drive_id']) ?>&path=<?= urlencode($link_path) ?>" title="Browse this directory: <?= htmlspecialchars($display_path) ?>">
                                <?= htmlspecialchars(mb_strimwidth($display_path, 0, 50, "...")) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($file['file_category'] ?? '—') ?></td>
                        <td><?= formatBytes($file['size']) ?></td>
                        <td><?= htmlspecialchars($file['mtime']) ?></td>
                        <td><?= htmlspecialchars($file['media_format'] ?? '—') ?></td>
                        <td style="white-space: normal;"><?= htmlspecialchars($file['media_codec'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($file['media_resolution'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($file['product_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($file['product_version'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($file['md5_hash'])): ?>
                                <a href="files.php?filename=<?= htmlspecialchars($file['md5_hash']) ?>" title="Find duplicates"><?= htmlspecialchars($file['md5_hash']) ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($file['exiftool_json'])): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['view_exif' => $file['id']])) ?>">View EXIF</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination Controls -->
        <div class="pagination">
            <?php
                // Build query string for pagination links, removing page number
                $query_params = $_GET;
                unset($query_params['page']);
                $query_string = http_build_query($query_params);

                if ($current_page > 1) {
                    echo '<a href="?page=1&' . $query_string . '">&laquo; First</a> ';
                    echo '<a href="?page=' . ($current_page - 1) . '&' . $query_string . '">&lsaquo; Prev</a> ';
                }

                echo " <span>Page {$current_page} / {$total_pages}</span> ";

                if ($current_page < $total_pages) {
                    echo ' <a href="?page=' . ($current_page + 1) . '&' . $query_string . '">Next &rsaquo;</a>';
                    echo ' <a href="?page=' . $total_pages . '&' . $query_string . '">Last &raquo;</a>';
                }
            ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <p style="text-align: center; font-size: 1.1em; margin-top: 50px;">Use the form above to search for files across all drives.</p>
<?php endif; ?>

<?php require 'footer.php'; ?>