<?php
require 'header.php';

// Initialize stats array with default values to prevent errors on display
$stats = [
    'total_drives' => 0,
    'total_capacity_gb' => 0,
    'total_files' => 0,
    'total_used_bytes' => 0,
    'files_by_category' => [],
    'largest_files' => [],
    'drives_most_free' => [],
    'drives_least_free' => [],
];
$error_message = '';

try {
    // 1. Get drive stats: total count and total capacity
    $drive_stats = $pdo->query("
        SELECT
            COUNT(id) AS total_drives,
            SUM(size) AS total_capacity_gb
        FROM st_drives
    ")->fetch();

    if ($drive_stats) {
        $stats['total_drives'] = $drive_stats['total_drives'];
        $stats['total_capacity_gb'] = $drive_stats['total_capacity_gb'];
    }

    // 2. Get file stats: total count and total size used
    $file_summary_stats = $pdo->query("
        SELECT
            COUNT(id) AS total_files,
            SUM(size) AS total_used_bytes
        FROM st_files
        WHERE is_directory = 0 AND date_deleted IS NULL
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
        WHERE is_directory = 0 AND date_deleted IS NULL
        GROUP BY file_category
        ORDER BY file_count DESC
    ")->fetchAll();

    // 4. Get the 20 largest files
    $stats['largest_files'] = $pdo->query("
        SELECT
            f.path,
            f.size,
            f.drive_id,
            d.name AS drive_name
        FROM
            st_files AS f
        JOIN
            st_drives AS d ON f.drive_id = d.id
        WHERE
            f.is_directory = 0 AND f.date_deleted IS NULL
        ORDER BY
            f.size DESC
        LIMIT 20
    ")->fetchAll();

    // 5. Get drive usage stats for free space calculation
    $drive_usage_query = "
        WITH DriveUsage AS (
            SELECT
                d.id,
                d.name,
                d.size AS capacity_gb,
                (d.size * 1073741824) AS capacity_bytes,
                IFNULL(SUM(f.size), 0) AS used_bytes
            FROM
                st_drives d
            LEFT JOIN
                st_files f ON d.id = f.drive_id AND f.is_directory = 0 AND f.date_deleted IS NULL
            GROUP BY
                d.id, d.name, d.size
        )
        SELECT
            id,
            name,
            capacity_gb,
            used_bytes,
            (capacity_bytes - used_bytes) AS free_space_bytes
        FROM DriveUsage
    ";

    $stats['drives_most_free'] = $pdo->query($drive_usage_query . " ORDER BY free_space_bytes DESC LIMIT 5")->fetchAll();
    $stats['drives_least_free'] = $pdo->query($drive_usage_query . " ORDER BY free_space_bytes ASC LIMIT 5")->fetchAll();

} catch (\PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
}
?>

<h1>Statistics</h1>

<?php if ($error_message): ?>
    <p class="error"><?= htmlspecialchars($error_message) ?></p>
<?php else: ?>
    <div class="stats-grid">
        <div class="stat-card"><h3>Number of Drives</h3><p><?= number_format($stats['total_drives']) ?></p></div>
        <div class="stat-card"><h3>Total Storage Capacity</h3><p><?= formatSize((int)$stats['total_capacity_gb']) ?></p></div>
        <div class="stat-card"><h3>Total Storage Used</h3><p><?= formatBytes((int)$stats['total_used_bytes']) ?></p></div>
        <div class="stat-card"><h3>Total Number of Files</h3><p><?= number_format($stats['total_files']) ?></p></div>
    </div>

    <h2>Files by Category</h2>
    <?php if (empty($stats['files_by_category'])): ?>
        <p>No file data available to generate category statistics.</p>
    <?php else: ?>
        <table><thead><tr><th>Category</th><th>File Count</th></tr></thead>
            <tbody><?php foreach ($stats['files_by_category'] as $category): ?><tr><td><?= htmlspecialchars($category['file_category']) ?></td><td><?= number_format($category['file_count']) ?></td></tr><?php endforeach; ?></tbody>
        </table>
    <?php endif; ?>

    <h2>Largest Files</h2>
    <?php if (empty($stats['largest_files'])): ?>
        <p>No file data available to determine largest files.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>File Path</th>
                    <th>Size</th>
                    <th>Drive</th>
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

    <div class="stats-grid" style="margin-top: 40px;">
        <div>
            <h2>Drives With Most Free Space</h2>
            <?php if (empty($stats['drives_most_free'])): ?>
                <p>No drive data available.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Drive</th>
                            <th>Free Space</th>
                            <th>Used %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['drives_most_free'] as $drive): ?>
                            <tr>
                                <td><a href="browse.php?drive_id=<?= htmlspecialchars($drive['id']) ?>"><?= htmlspecialchars($drive['name']) ?></a></td>
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
        </div>
        <div>
            <h2>Drives With Least Free Space</h2>
            <?php if (empty($stats['drives_least_free'])): ?>
                <p>No drive data available.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Drive</th><th>Free Space</th><th>Used %</th></tr></thead>
                    <tbody>
                        <?php foreach ($stats['drives_least_free'] as $drive): ?>
                            <tr>
                                <td><a href="browse.php?drive_id=<?= htmlspecialchars($drive['id']) ?>"><?= htmlspecialchars($drive['name']) ?></a></td>
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
        </div>
    </div>
<?php endif; ?>

<?php require 'footer.php'; ?>