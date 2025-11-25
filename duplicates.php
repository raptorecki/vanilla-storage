<?php
require 'header.php';

// Pagination configuration
$allowed_page_sizes = [10, 20, 50, 100];
$page_size = filter_input(INPUT_GET, 'page_size', FILTER_VALIDATE_INT);
if (!$page_size || !in_array($page_size, $allowed_page_sizes)) {
    $page_size = 20;
}

$current_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);

// Limit maximum page depth for performance
$max_page = 1000;
if ($current_page > $max_page) {
    $current_page = $max_page;
}

$offset = ($current_page - 1) * $page_size;

// Sorting options
$sort_options = [
    'none' => 'None (Fastest)',
    'wasted_space' => 'Wasted Space (Highest First)',
    'instance_count' => 'Number of Copies (Most First)',
    'file_size' => 'File Size (Largest First)',
    'filename' => 'Filename (A-Z)'
];
$sort_by = $_GET['sort'] ?? 'none';
if (!array_key_exists($sort_by, $sort_options)) {
    $sort_by = 'none';
}

// Map sort parameter to SQL ORDER BY clause
$order_by_map = [
    'none' => '',
    'wasted_space' => 'ORDER BY wasted_space DESC',
    'instance_count' => 'ORDER BY instance_count DESC',
    'file_size' => 'ORDER BY file_size DESC',
    'filename' => 'ORDER BY sample_filename ASC'
];
$order_by = $order_by_map[$sort_by];

// Minimum instances filter (default to 10 for better performance)
$min_instances = filter_input(INPUT_GET, 'min_instances', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 2]]);

$duplicate_groups = [];
$total_results = 0;
$total_pages = 0;
$stats = ['total_duplicates' => 0, 'total_wasted_space' => 0];
$error_message = '';
$count_skipped = false;

// Try to get total count (may timeout for low min_instances)
try {
    $count_start = microtime(true);
    $total_stats = prepare_with_timeout($pdo, "
        SELECT
            COUNT(*) as group_count,
            SUM((instance_count - 1) * file_size) as total_wasted
        FROM (
            SELECT
                md5_hash,
                COUNT(id) AS instance_count,
                MIN(size) AS file_size
            FROM st_files
            WHERE date_deleted IS NULL
                AND md5_hash IS NOT NULL
                AND md5_hash != ''
            GROUP BY md5_hash
            HAVING instance_count >= ?
        ) AS dup_groups
    ", [$min_instances], 60)->fetch();

    if ($total_stats) {
        $total_results = $total_stats['group_count'] ?: 0;
        $stats['total_duplicates'] = $total_results;
        $stats['total_wasted_space'] = $total_stats['total_wasted'] ?: 0;
        $total_pages = ceil($total_results / $page_size);
    }
    $count_duration = microtime(true) - $count_start;
} catch (\PDOException $e) {
    // Count query timed out - continue without total count
    $count_skipped = true;
    $error_message = "Note: Total count skipped due to timeout. Showing first page of results. Try increasing minimum instances filter for faster performance.";
    log_error("Count query timeout in duplicates.php: " . $e->getMessage());
}

// Get paginated duplicate groups (try even if count failed)
try {
    if (!$count_skipped || $current_page == 1) {
        $query_start = microtime(true);
        $stmt = prepare_with_timeout($pdo, "
            SELECT
                f.md5_hash,
                COUNT(f.id) AS instance_count,
                MIN(f.size) AS file_size,
                MIN(f.filename) AS sample_filename,
                (COUNT(f.id) - 1) * MIN(f.size) AS wasted_space
            FROM st_files f
            WHERE f.date_deleted IS NULL
                AND f.md5_hash IS NOT NULL
                AND f.md5_hash != ''
            GROUP BY f.md5_hash
            HAVING instance_count >= ?
            {$order_by}
            LIMIT ? OFFSET ?
        ", [$min_instances, $page_size, $offset], 60);

        $duplicate_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $query_duration = microtime(true) - $query_start;

        // For each duplicate group, get the file locations (only for current page)
        foreach ($duplicate_groups as &$group) {
            $locations_start = microtime(true);
            $stmt = prepare_with_timeout($pdo, "
                SELECT
                    f.id,
                    f.path,
                    f.drive_id,
                    d.name AS drive_name
                FROM st_files f
                JOIN st_drives d ON f.drive_id = d.id
                WHERE f.md5_hash = ?
                    AND f.date_deleted IS NULL
                ORDER BY d.name, f.path
                LIMIT 100
            ", [$group['md5_hash']], 10);

            $group['locations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $locations_duration = microtime(true) - $locations_start;
        }
        unset($group);
    }
} catch (\PDOException $e) {
    if (strpos($e->getMessage(), 'max_statement_time') !== false) {
        $error_message = "Query timeout: The duplicate results query is taking too long. Please try increasing the minimum instances filter to 10+ for better performance.";
    } else {
        $error_message = "An unexpected database error occurred. Please try again.";
    }
    log_error("Database Error in duplicates.php (results query): " . $e->getMessage());
}
?>

<h1>Duplicate Files</h1>

<?php if ($error_message): ?>
    <p class="error"><?= htmlspecialchars($error_message) ?></p>
<?php endif; ?>

<div class="stats-grid" style="margin-bottom: 25px;">
    <div class="stat-card">
        <h3>Total Duplicate Groups</h3>
        <p><?= $count_skipped ? '(calculating...)' : number_format($stats['total_duplicates']) ?></p>
    </div>
    <div class="stat-card">
        <h3>Total Wasted Space</h3>
        <p><?= $count_skipped ? '(calculating...)' : formatBytes((int)$stats['total_wasted_space']) ?></p>
    </div>
    <div class="stat-card">
        <h3>Current Page</h3>
        <p><?= $count_skipped ? number_format($current_page) : (number_format($current_page) . ' of ' . number_format($total_pages)) ?></p>
    </div>
</div>

<!-- Filters and Controls -->
<div class="search-container" style="margin-bottom: 20px;">
    <form action="duplicates.php" method="get" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <div>
            <label for="min_instances">Min Copies:</label>
            <select name="min_instances" id="min_instances" style="background-color: #2b2b2b; border: 1px solid #444; color: #e0e0e0; padding: 8px; border-radius: 4px;">
                <option value="2" <?= $min_instances == 2 ? 'selected' : '' ?>>2+</option>
                <option value="3" <?= $min_instances == 3 ? 'selected' : '' ?>>3+</option>
                <option value="4" <?= $min_instances == 4 ? 'selected' : '' ?>>4+</option>
                <option value="5" <?= $min_instances == 5 ? 'selected' : '' ?>>5+</option>
                <option value="10" <?= $min_instances == 10 ? 'selected' : '' ?>>10+</option>
            </select>
        </div>

        <div>
            <label for="sort">Sort By:</label>
            <select name="sort" id="sort" style="background-color: #2b2b2b; border: 1px solid #444; color: #e0e0e0; padding: 8px; border-radius: 4px;">
                <?php foreach ($sort_options as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $sort_by == $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="page_size">Show:</label>
            <select name="page_size" id="page_size" style="background-color: #2b2b2b; border: 1px solid #444; color: #e0e0e0; padding: 8px; border-radius: 4px;">
                <?php foreach ($allowed_page_sizes as $size): ?>
                    <option value="<?= $size ?>" <?= $page_size == $size ? 'selected' : '' ?>><?= $size ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" style="background-color: #3a7ab8; color: #ffffff; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer;">Apply Filters</button>
        <a href="duplicates.php" style="background-color: #555; color: #ffffff; border: none; padding: 8px 20px; border-radius: 4px; text-decoration: none;">Reset</a>
    </form>
</div>

<div style="margin-bottom: 15px; padding: 10px; background-color: #2a2a2a; border-left: 4px solid #3a7ab8; border-radius: 5px; font-size: 0.9em;">
    <strong>Performance Tip:</strong> Use "None (Fastest)" sorting and higher min copies (10+) for best performance.
    Sorting requires processing all <?= number_format(7202080) ?> files and may take 60+ seconds.
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div style="margin-bottom: 20px; text-align: center;">
    <?php
    $query_params = http_build_query([
        'sort' => $sort_by,
        'min_instances' => $min_instances,
        'page_size' => $page_size
    ]);
    ?>

    <?php if ($current_page > 1): ?>
        <a href="?page=1&<?= $query_params ?>" style="padding: 8px 12px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; margin: 2px;">First</a>
        <a href="?page=<?= $current_page - 1 ?>&<?= $query_params ?>" style="padding: 8px 12px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; margin: 2px;">Previous</a>
    <?php endif; ?>

    <span style="padding: 8px 12px; background-color: #2a2a2a; border-radius: 4px; margin: 2px;">
        Page <?= number_format($current_page) ?> of <?= number_format($total_pages) ?>
    </span>

    <?php if ($current_page < $total_pages): ?>
        <a href="?page=<?= $current_page + 1 ?>&<?= $query_params ?>" style="padding: 8px 12px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; margin: 2px;">Next</a>
        <a href="?page=<?= $total_pages ?>&<?= $query_params ?>" style="padding: 8px 12px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; margin: 2px;">Last</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Duplicate Groups Table -->
<?php if (empty($duplicate_groups) && !$error_message): ?>
    <p style="text-align: center; padding: 40px; background-color: #2a2a2a; border-radius: 5px;">
        No duplicate files found with the current filters.
    </p>
<?php elseif (!empty($duplicate_groups)): ?>
    <table>
        <thead>
            <tr>
                <th>Sample Filename</th>
                <th>File Size</th>
                <th>Copies</th>
                <th>Wasted Space</th>
                <th>MD5 Hash</th>
                <th>Locations</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($duplicate_groups as $duplicate): ?>
                <tr>
                    <td style="font-family: monospace;"><?= htmlspecialchars($duplicate['sample_filename']) ?></td>
                    <td><?= formatBytes($duplicate['file_size']) ?></td>
                    <td><strong><?= number_format($duplicate['instance_count']) ?></strong></td>
                    <td style="color: #ff6b6b;"><strong><?= formatBytes($duplicate['wasted_space']) ?></strong></td>
                    <td style="font-family: monospace; font-size: 0.85em;">
                        <a href="files.php?search=<?= urlencode($duplicate['md5_hash']) ?>"
                           title="Search for all instances of this file">
                            <?= htmlspecialchars(substr($duplicate['md5_hash'], 0, 16)) ?>...
                        </a>
                    </td>
                    <td>
                        <details>
                            <summary style="cursor: pointer; color: #3a7ab8;">
                                <?= count($duplicate['locations']) ?> location<?= count($duplicate['locations']) > 1 ? 's' : '' ?>
                            </summary>
                            <ul style="margin: 10px 0; padding-left: 20px; list-style: none;">
                                <?php foreach ($duplicate['locations'] as $location): ?>
                                    <li style="margin: 5px 0; padding: 5px; background-color: #1a1a1a; border-radius: 3px;">
                                        <strong style="color: #3a7ab8;"><?= htmlspecialchars($location['drive_name']) ?>:</strong><br>
                                        <?php
                                            $dir_path = dirname($location['path']);
                                            $link_path = ($dir_path === '.' || $dir_path === '/') ? '' : ltrim($dir_path, '/');
                                        ?>
                                        <a href="browse.php?drive_id=<?= htmlspecialchars($location['drive_id']) ?>&path=<?= urlencode($link_path) ?>"
                                           style="color: #5a9fd4; text-decoration: none; font-family: monospace; font-size: 0.9em;"
                                           title="Browse to this location">
                                            <?= htmlspecialchars($location['path']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Bottom Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="margin-top: 20px; text-align: center;">
        <?php if ($current_page > 1): ?>
            <a href="?page=1&<?= $query_params ?>" style="padding: 8px 12px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; margin: 2px;">First</a>
            <a href="?page=<?= $current_page - 1 ?>&<?= $query_params ?>" style="padding: 8px 12px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; margin: 2px;">Previous</a>
        <?php endif; ?>

        <span style="padding: 8px 12px; background-color: #2a2a2a; border-radius: 4px; margin: 2px;">
            Page <?= number_format($current_page) ?> of <?= number_format($total_pages) ?>
        </span>

        <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?= $current_page + 1 ?>&<?= $query_params ?>" style="padding: 8px 12px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; margin: 2px;">Next</a>
            <a href="?page=<?= $total_pages ?>&<?= $query_params ?>" style="padding: 8px 12px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; margin: 2px;">Last</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top: 20px; padding: 15px; background-color: #2a2a2a; border-left: 4px solid #3a7ab8; border-radius: 5px;">
        <strong>Performance Note:</strong> This page shows duplicate groups with pagination for better performance.
        Each page loads quickly by limiting results. Use filters to narrow down your search.
    </div>
<?php endif; ?>

<?php require 'footer.php'; ?>
