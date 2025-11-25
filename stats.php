<?php
require 'header.php';
require_once 'helpers/smartctl_analyzer.php'; // Include smartctl analyzer

// Initialize stats array with default values to prevent errors on display
$stats = [
    'total_drives' => 0,
    'dead_drives' => 0,
    'total_capacity_gb' => 0,
    'total_files' => 0,
    'total_used_bytes' => 0,
    'files_by_category' => [],
    'largest_files' => [],
    'drives_most_free' => [],
    'drives_least_free' => [],
    'drives_scanned_completed' => 0, // New stat
    'drives_with_smart_issues' => [], // New stat
    'duplicate_files' => [],
    'total_duplicates' => 0,
    'total_wasted_space' => 0,
];
$error_message = '';

$limit_options = [10, 20, 50, 100];
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_options) ? (int)$_GET['limit'] : 20;
$duplicate_limit = isset($_GET['duplicate_limit']) && in_array((int)$_GET['duplicate_limit'], $limit_options) ? (int)$_GET['duplicate_limit'] : 20;

try {
    // 1. Get drive stats: total count and total capacity
    $drive_stats = $pdo->query("
        SELECT
            COUNT(id) AS total_drives,
            SUM(size) AS total_capacity_gb
        FROM st_drives
        WHERE dead = 0
    ")->fetch();

    if ($drive_stats) {
        $stats['total_drives'] = $drive_stats['total_drives'];
        $stats['total_capacity_gb'] = $drive_stats['total_capacity_gb'];
    }

    // Get dead drive count
    $dead_drives_count = $pdo->query("SELECT COUNT(id) FROM st_drives WHERE dead = 1")->fetchColumn();
    $stats['dead_drives'] = $dead_drives_count ?: 0;

    // 2. Get file stats: total count and total size used
    $file_summary_stats = $pdo->query("
        SELECT
            COUNT(id) AS total_files,
            SUM(size) AS total_used_bytes
        FROM st_files
        WHERE date_deleted IS NULL
    ")->fetch();

    if ($file_summary_stats) {
        $stats['total_files'] = $file_summary_stats['total_files'];
        $stats['total_used_bytes'] = $file_summary_stats['total_used_bytes'];
    }

    // 3. Get file counts grouped by category
    $stats['files_by_category'] = $pdo->query("
        SELECT
            file_category,
            COUNT(id) AS file_count
        FROM st_files
        WHERE date_deleted IS NULL
        GROUP BY file_category
        ORDER BY file_count DESC
    ")->fetchAll();

    // 4. Get the N largest files (optimized two-step query)
    // Step 1: Get largest files using size index (fast)
    $stmt = $pdo->prepare("
        SELECT
            id,
            path,
            size,
            drive_id
        FROM
            st_files
        WHERE date_deleted IS NULL
        ORDER BY
            size DESC
        LIMIT :limit
    ");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $largest_files = $stmt->fetchAll();

    // Step 2: Get drive names for only these N files (fast lookup)
    if (!empty($largest_files)) {
        $drive_ids = array_unique(array_column($largest_files, 'drive_id'));
        $placeholders = str_repeat('?,', count($drive_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id, name FROM st_drives WHERE id IN ($placeholders)");
        $stmt->execute(array_values($drive_ids));
        $drive_names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Merge drive names into results
        foreach ($largest_files as &$file) {
            $file['drive_name'] = $drive_names[$file['drive_id']] ?? 'Unknown';
        }
        unset($file); // Break reference
    }
    $stats['largest_files'] = $largest_files;

    // 5. Get drive usage stats for free space calculation (OPTIMIZED: single query)
    $all_drive_usage = $pdo->query("
        SELECT
            d.id,
            d.name,
            d.an_serial,
            d.serial,
            d.size AS capacity_gb,
            (d.size * 1073741824) AS capacity_bytes,
            IFNULL(SUM(f.size), 0) AS used_bytes,
            ((d.size * 1073741824) - IFNULL(SUM(f.size), 0)) AS free_space_bytes
        FROM
            st_drives d
        LEFT JOIN
            st_files f ON d.id = f.drive_id AND f.date_deleted IS NULL
        WHERE d.dead = 0
        GROUP BY
            d.id, d.name, d.an_serial, d.serial, d.size
    ")->fetchAll();

    // Sort in PHP instead of running query 3 times
    $usage_by_free_desc = $all_drive_usage;
    $usage_by_free_asc = $all_drive_usage;
    $usage_by_data = $all_drive_usage;

    usort($usage_by_free_desc, function($a, $b) { return $b['free_space_bytes'] <=> $a['free_space_bytes']; });
    usort($usage_by_free_asc, function($a, $b) { return $a['free_space_bytes'] <=> $b['free_space_bytes']; });
    usort($usage_by_data, function($a, $b) { return $b['used_bytes'] <=> $a['used_bytes']; });

    $stats['drives_most_free'] = array_slice($usage_by_free_desc, 0, 5);
    $stats['drives_least_free'] = array_slice($usage_by_free_asc, 0, 5);
    $stats['drives_most_data'] = array_slice($usage_by_data, 0, 5);

    // 6. Get drives that require a scan
    $stats['drives_scan_required'] = $pdo->query("
        SELECT
            d.id,
            d.name,
            d.an_serial,
            d.serial
        FROM
            st_drives d
        LEFT JOIN
            st_scans s ON d.id = s.drive_id
        WHERE
            d.dead = 0 AND d.empty = 0 AND d.online = 0 AND s.scan_date IS NULL
    ")->fetchAll();

    // 7. Get count of drives with completed scans
    $stats['drives_scanned_completed'] = $pdo->query("SELECT COUNT(DISTINCT drive_id) FROM st_scans WHERE status = 'completed'")->fetchColumn();

    // 8. Get duplicate file STATISTICS (fast summary without detailed list)
    // Full duplicate detection with detailed file list is available on separate duplicates page
    try {
        $duplicate_stats = prepare_with_timeout($pdo, "
            SELECT
                COUNT(*) as total_files_with_hash,
                COUNT(DISTINCT md5_hash) as unique_hashes
            FROM st_files
            WHERE date_deleted IS NULL
                AND md5_hash IS NOT NULL
                AND md5_hash != ''
        ", [], 30)->fetch();

        if ($duplicate_stats) {
            // Approximate duplicate count: total files with hash - unique hashes
            $potential_duplicates = $duplicate_stats['total_files_with_hash'] - $duplicate_stats['unique_hashes'];
            $stats['total_duplicates'] = max(0, $potential_duplicates);

            // Estimate wasted space (rough approximation)
            // This is faster than calculating exact wasted space
            if ($potential_duplicates > 0) {
                $avg_size = prepare_with_timeout($pdo, "
                    SELECT AVG(size) as avg_size
                    FROM st_files
                    WHERE date_deleted IS NULL
                        AND md5_hash IS NOT NULL
                        AND md5_hash != ''
                ", [], 30)->fetchColumn();
                $stats['total_wasted_space'] = $potential_duplicates * $avg_size;
            }
        }
    } catch (\PDOException $e) {
        log_error("Duplicate stats query error: " . $e->getMessage());
        $stats['total_duplicates'] = 0;
        $stats['total_wasted_space'] = 0;
    }

    // Empty array for duplicate_files since we're not showing detailed list on this page
    $stats['duplicate_files'] = [];

    // 9. Get duplicate files (files with same MD5 hash across drives or within same drive)
    // PERFORMANCE NOTE: Detailed duplicate queries are EXTREMELY slow with large datasets (180+ seconds with 7.2M files)
    // TODO: Implement separate duplicates.php page with pagination
    /* DISABLED - Use duplicates.php page instead
    $stmt = $pdo->prepare("
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
        HAVING instance_count > 1
        ORDER BY wasted_space DESC
        LIMIT :duplicate_limit
    ");
    $stmt->bindParam(':duplicate_limit', $duplicate_limit, PDO::PARAM_INT);
    $stmt->execute();
    $duplicate_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each duplicate group, get the locations
    foreach ($duplicate_groups as &$group) {
        $stmt = $pdo->prepare("
            SELECT
                f.id,
                f.path,
                f.drive_id,
                d.name AS drive_name
            FROM st_files f
            JOIN st_drives d ON f.drive_id = d.id
            WHERE f.md5_hash = :md5_hash
                AND f.date_deleted IS NULL
            ORDER BY d.name, f.path
        ");
        $stmt->execute(['md5_hash' => $group['md5_hash']]);
        $group['locations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($group); // Break reference
    $stats['duplicate_files'] = $duplicate_groups;

    // Get total duplicate statistics (all duplicates, not just displayed limit)
    $total_duplicate_stats = $pdo->query("
        SELECT
            COUNT(DISTINCT md5_hash) AS duplicate_groups,
            SUM((instance_count - 1) * file_size) AS total_wasted
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
            HAVING instance_count > 1
        ) AS duplicates
    ")->fetch(PDO::FETCH_ASSOC);

    if ($total_duplicate_stats) {
        $stats['total_duplicates'] = $total_duplicate_stats['duplicate_groups'] ?: 0;
        $stats['total_wasted_space'] = $total_duplicate_stats['total_wasted'] ?: 0;
    }
    */ // END DISABLED DUPLICATE QUERIES

    // 9. Get drives with SMART issues
    $stmt = $pdo->query("
        SELECT
            d.id, d.name, d.an_serial, d.serial,
            s.output AS smartctl_output
        FROM
            st_drives d
        JOIN
            st_smart s ON d.id = s.drive_id
        WHERE
            s.scan_date = (SELECT MAX(scan_date) FROM st_smart WHERE drive_id = d.id)
    ");
    $all_drives_with_smart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_drives_with_smart_data as $drive) {
        $analysis = analyzeSmartctlOutput($drive['smartctl_output']);
        if ($analysis['status'] === 'CRITICAL' || $analysis['status'] === 'WARNING') {
            $drive['smart_issues'] = $analysis['issues'];
            $drive['smart_status'] = $analysis['status'];
            $stats['drives_with_smart_issues'][] = $drive;
        }
    }

} catch (\PDOException $e) {
    log_error("Database Error in stats.php: " . $e->getMessage());
    $error_message = "An unexpected database error occurred while fetching statistics. Please try again. Details: " . $e->getMessage();
}
?>

<h1>Statistics</h1>

<?php if ($error_message): ?>
    <p class="error"><?= htmlspecialchars($error_message) ?></p>
<?php else: ?>
    <div class="stats-grid">
        <div class="stat-card"><h3>Number of Drives</h3><p><?= number_format($stats['total_drives']) ?></p></div>
        <div class="stat-card"><h3>Dead Drives</h3><p><?= number_format($stats['dead_drives']) ?></p></div>
        <div class="stat-card"><h3>Total Storage Capacity</h3><p><?= formatSize((int)$stats['total_capacity_gb']) ?></p></div>
        <div class="stat-card"><h3>Total Storage Used</h3><p><?= formatBytes((int)$stats['total_used_bytes']) ?></p></div>
        <div class="stat-card"><h3>Total Number of Files</h3><p><?= number_format($stats['total_files']) ?></p></div>
        <div class="stat-card"><h3>Duplicate File Groups</h3><p><?= number_format($stats['total_duplicates']) ?></p></div>
        <div class="stat-card"><h3>Wasted Space (Duplicates)</h3><p><?= formatBytes((int)$stats['total_wasted_space']) ?></p></div>
        <div class="stat-card"><h3>Drives With Scan Required</h3><p><?= number_format(count($stats['drives_scan_required'])) ?></p></div>
        <div class="stat-card"><h3>Drives Scanned (Completed)</h3><p><?= number_format($stats['drives_scanned_completed']) ?></p></div>
    </div>

    <details style="margin-top: 25px;">
        <summary><h2>Files by Category</h2></summary>
        <?php if (empty($stats['files_by_category'])): ?>
            <p>No file data available to generate category statistics.</p>
        <?php else: ?>
            <table><thead><tr><th>Category</th><th>File Count</th></tr></thead>
                <tbody><?php foreach ($stats['files_by_category'] as $category): ?><tr><td><?= htmlspecialchars($category['file_category']) ?></td><td><?= number_format($category['file_count']) ?></td></tr><?php endforeach; ?></tbody>
            </table>
        <?php endif; ?>
    </details>

    <details>
        <summary><h2>Duplicate Files</h2></summary>
        <?php if ($stats['total_duplicates'] > 0): ?>
            <p style="margin-bottom: 15px; background-color: #2a2a2a; padding: 15px; border-radius: 5px; border-left: 4px solid #3a7ab8;">
                <strong>Summary Statistics:</strong><br>
                • Approximate duplicate file instances: <?= number_format($stats['total_duplicates']) ?><br>
                • Estimated wasted space: <?= formatBytes((int)$stats['total_wasted_space']) ?><br>
                <br>
                <a href="duplicates.php" style="display: inline-block; margin-top: 10px; padding: 10px 20px; background-color: #3a7ab8; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
                    View Detailed Duplicate Files →
                </a>
                <br><br>
                <em style="font-size: 0.9em;">Note: Detailed duplicate file browsing is available on the dedicated Duplicates page with pagination for better performance.</em>
            </p>
        <?php elseif ($stats['total_duplicates'] == 0): ?>
            <p>No duplicate files found. All files have unique content (based on MD5 hash).</p>
        <?php endif; ?>

        <?php if (false): // Disabled detailed duplicate list ?>
        <div class="table-toolbar">
            <form action="stats.php" method="get">
                <label for="duplicate_limit">Show:</label>
                <select name="duplicate_limit" id="duplicate_limit" onchange="this.form.submit()">
                    <?php foreach ($limit_options as $option): ?>
                        <option value="<?= $option ?>" <?= ($duplicate_limit == $option) ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php if (!empty($stats['duplicate_files'])): ?>
            <p style="margin-bottom: 15px;">
                <strong>Summary:</strong> Found <?= number_format($stats['total_duplicates']) ?> groups of duplicate files.
                Removing duplicates could free up <?= formatBytes((int)$stats['total_wasted_space']) ?> of storage space.
                Showing top <?= $duplicate_limit ?> by wasted space.
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Sample Filename</th>
                        <th>File Size</th>
                        <th>Instances</th>
                        <th>Wasted Space</th>
                        <th>MD5 Hash</th>
                        <th>Locations</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['duplicate_files'] as $duplicate): ?>
                        <tr>
                            <td><?= htmlspecialchars($duplicate['sample_filename']) ?></td>
                            <td><?= formatBytes($duplicate['file_size']) ?></td>
                            <td><?= number_format($duplicate['instance_count']) ?></td>
                            <td><?= formatBytes($duplicate['wasted_space']) ?></td>
                            <td style="font-family: monospace; font-size: 0.85em;">
                                <a href="files.php?search=<?= urlencode($duplicate['md5_hash']) ?>" title="Search for all instances">
                                    <?= htmlspecialchars(substr($duplicate['md5_hash'], 0, 16)) ?>...
                                </a>
                            </td>
                            <td>
                                <details>
                                    <summary><?= count($duplicate['locations']) ?> location<?= count($duplicate['locations']) > 1 ? 's' : '' ?></summary>
                                    <ul style="margin: 5px 0; padding-left: 20px;">
                                        <?php foreach ($duplicate['locations'] as $location): ?>
                                            <li>
                                                <strong><?= htmlspecialchars($location['drive_name']) ?>:</strong>
                                                <?php
                                                    $dir_path = dirname($location['path']);
                                                    $link_path = ($dir_path === '.' || $dir_path === '/') ? '' : ltrim($dir_path, '/');
                                                ?>
                                                <a href="browse.php?drive_id=<?= htmlspecialchars($location['drive_id']) ?>&path=<?= urlencode($link_path) ?>">
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
        <?php endif; ?>
        <?php endif; // End of if (false) - disabled detailed duplicate list ?>
    </details>

    <details>
        <summary><h2>Largest Files</h2></summary>
        <div class="table-toolbar">
            <form action="stats.php" method="get">
                <label for="limit">Show:</label>
                <select name="limit" id="limit" onchange="this.form.submit()">
                    <?php foreach ($limit_options as $option): ?>
                        <option value="<?= $option ?>" <?= ($limit == $option) ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php if (empty($stats['largest_files'])): ?>
            <p>No file data available to determine largest files.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>File Path</th>
                        <th>Size</th>
                        <th>Name</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['largest_files'] as $file): ?>
                        <tr>
                            <td>
                                <?php
                                    $dir_path = dirname($file['path']);
                                    $link_path = ($dir_path === '.' || $dir_path === '/') ? '' : ltrim($dir_path, '/');
                                ?>
                                <a href="browse.php?drive_id=<?= htmlspecialchars($file['drive_id']) ?>&path=<?= urlencode($link_path) ?>" title="Browse directory"><?= htmlspecialchars($file['path']) ?></a>
                            </td>
                            <td><?= formatBytes($file['size']) ?></td>
                            <td><a href="browse.php?drive_id=<?= htmlspecialchars($file['drive_id']) ?>"><?= htmlspecialchars($file['drive_name']) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </details>

    <div>
            <details>
                <summary><h2>Drives With Scan Required</h2></summary>
                <?php if (empty($stats['drives_scan_required'])): ?>
                    <p>No drives require a scan.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>AN Serial</th>
                                <th>Name</th>
                                <th>Serial</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['drives_scan_required'] as $drive): ?>
                                <tr>
                                    <td><?= htmlspecialchars($drive['an_serial']) ?></td>
                                    <td><a href="browse.php?drive_id=<?= htmlspecialchars($drive['id']) ?>"><?= htmlspecialchars($drive['name']) ?></a></td>
                                    <td><?= htmlspecialchars($drive['serial']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </details>
            <details>
                <summary><h2>Drives With Most Free Space</h2></summary>
                <?php if (empty($stats['drives_most_free'])): ?>
                    <p>No drive data available.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>AN Serial</th>
                                <th>Serial</th>
                                <th>Free Space</th>
                                <th>Used %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['drives_most_free'] as $drive): ?>
                                <tr>
                                    <td><a href="browse.php?drive_id=<?= htmlspecialchars($drive['id']) ?>"><?= htmlspecialchars($drive['name']) ?></a></td>
                                    <td><?= htmlspecialchars($drive['an_serial']) ?></td>
                                    <td><?= htmlspecialchars($drive['serial']) ?></td>
                                    <td><?= formatBytes($drive['free_space_bytes']) ?></td>
                                    <td style="width: 150px;"><?php
                                        $capacity_bytes = $drive['capacity_gb'] * 1073741824;
                                        $percentage_used = ($capacity_bytes > 0) ? ($drive['used_bytes'] / $capacity_bytes) * 100 : 0;
                                        $percentage_rounded = round($percentage_used, 1);

                                        $bar_color_class = 'green';
                                        if ($percentage_used >= 85) {
                                            $bar_color_class = 'red';
                                        } elseif ($percentage_used >= 50) {
                                            $bar_color_class = 'yellow';
                                        }
                                    ?><div class="progress-bar-container" title="<?= $percentage_rounded ?>% Used">
                                            <div class="progress-bar <?= $bar_color_class ?>" style="width: <?= $percentage_used ?>%;"></div>
                                            <span class="progress-bar-text"><?= $percentage_rounded ?>%</span>
                                        </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </details>
            <details>
                <summary><h2>Drives With Most Data</h2></summary>
                <?php if (empty($stats['drives_most_data'])): ?>
                    <p>No drive data available.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>AN Serial</th>
                                <th>Serial</th>
                                <th>Used Space</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['drives_most_data'] as $drive): ?>
                                <tr>
                                    <td><a href="browse.php?drive_id=<?= htmlspecialchars($drive['id']) ?>"><?= htmlspecialchars($drive['name']) ?></a></td>
                                    <td><?= htmlspecialchars($drive['an_serial']) ?></td>
                                    <td><?= htmlspecialchars($drive['serial']) ?></td>
                                    <td><?= formatBytes($drive['used_bytes']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </details>
            <details>
                <summary><h2>Drives with SMART Issues</h2></summary>
                <?php if (empty($stats['drives_with_smart_issues'])): ?>
                    <p>No drives with SMART issues detected.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>AN Serial</th>
                                <th>Name</th>
                                <th>Serial</th>
                                <th>SMART Status</th>
                                <th>Issues</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['drives_with_smart_issues'] as $drive): ?>
                                <tr>
                                    <td><?= htmlspecialchars($drive['an_serial']) ?></td>
                                    <td><a href="browse.php?drive_id=<?= htmlspecialchars($drive['id']) ?>"><?= htmlspecialchars($drive['name']) ?></a></td>
                                    <td><?= htmlspecialchars($drive['serial']) ?></td>
                                    <td><span class="smart-status-<?= strtolower($drive['smart_status']) ?>"><?= htmlspecialchars($drive['smart_status']) ?></span></td>
                                    <td>
                                        <?php if (!empty($drive['smart_issues'])): ?>
                                            <ul>
                                                <?php foreach ($drive['smart_issues'] as $issue): ?>
                                                    <li><?= htmlspecialchars($issue) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            No specific issues.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </details>
        </div>

        <?php endif; ?>

<?php require 'footer.php'; ?>