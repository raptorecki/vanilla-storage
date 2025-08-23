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
];
$error_message = '';

$limit_options = [10, 20, 50, 100];
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_options) ? (int)$_GET['limit'] : 20;

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

    // 4. Get the N largest files
    $stmt = $pdo->prepare("
        SELECT
            f.path,
            f.size,
            f.drive_id,
            d.name AS drive_name
        FROM
            st_files AS f
        JOIN
            st_drives AS d ON f.drive_id = d.id
        WHERE f.date_deleted IS NULL
        ORDER BY
            f.size DESC
        LIMIT :limit
    ");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $stats['largest_files'] = $stmt->fetchAll();

    // 5. Get drive usage stats for free space calculation
    $drive_usage_query = "
        WITH DriveUsage AS (
            SELECT
                d.id,
                d.name,
                d.an_serial,
                d.serial,
                d.size AS capacity_gb,
                (d.size * 1073741824) AS capacity_bytes,
                IFNULL(SUM(f.size), 0) AS used_bytes
            FROM
                st_drives d
            LEFT JOIN
                st_files f ON d.id = f.drive_id AND f.date_deleted IS NULL
            WHERE d.dead = 0
            GROUP BY
                d.id, d.name, d.an_serial, d.serial, d.size
        )
        SELECT
            id,
            name,
            an_serial,
            serial,
            capacity_gb,
            used_bytes,
            (capacity_bytes - used_bytes) AS free_space_bytes
        FROM DriveUsage
    ";

    $stats['drives_most_free'] = $pdo->query($drive_usage_query . " ORDER BY free_space_bytes DESC LIMIT 5")->fetchAll();
    $stats['drives_least_free'] = $pdo->query($drive_usage_query . " ORDER BY free_space_bytes ASC LIMIT 5")->fetchAll();
    $stats['drives_most_data'] = $pdo->query($drive_usage_query . " ORDER BY used_bytes DESC LIMIT 5")->fetchAll();

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
            d.dead = 0 AND d.empty = 0 AND s.scan_date IS NULL
    ")->fetchAll();

    // 7. Get count of drives with completed scans
    $stats['drives_scanned_completed'] = $pdo->query("SELECT COUNT(DISTINCT drive_id) FROM st_scans WHERE status = 'completed'")->fetchColumn();

    // 8. Get drives with SMART issues
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