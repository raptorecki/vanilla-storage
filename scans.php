<?php
require 'header.php';

// --- Sorting Logic ---
$allowed_columns = ['scan_id', 'drive_id', 'scan_date', 'total_items_scanned', 'new_files_added', 'existing_files_updated', 'files_marked_deleted', 'scan_duration', 'thumbnails_created', 'thumbnail_creations_failed'];
$sort_column = $_GET['sort'] ?? 'scan_id';
if (!in_array($sort_column, $allowed_columns)) $sort_column = 'scan_id';
$sort_direction = strtolower($_GET['dir'] ?? 'desc'); // Default to desc for scan_id
if (!in_array($sort_direction, ['asc', 'desc'])) $sort_direction = 'desc';

// --- Search Logic ---
$search_term = trim($_GET['search'] ?? '');
$searchable_columns = ['s.scan_id', 'd.name', 's.scan_date']; // Search by scan ID or drive name or scan date

$scans = [];
$error_message = '';

try {
    $sql = "SELECT s.*, d.name as drive_name FROM st_scans s JOIN st_drives d ON s.drive_id = d.id";
    $params = [];

    if (!empty($search_term)) {
        $search_conditions = [];
        foreach ($searchable_columns as $column) {
            $search_conditions[] = "{$column} LIKE ?";
            $params[] = "%{$search_term}%";
        }
        $sql .= " WHERE (" . implode(' OR ', $search_conditions) . ")";
    }

    $sql .= " ORDER BY {$sort_column} {$sort_direction}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $scans = $stmt->fetchAll();

} catch (\PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
}
?>

<h1>Scan History</h1>
<form method="GET" action="scans.php" class="search-container">
    <input type="text" name="search" placeholder="Search by scan ID, drive name, or date..." value="<?= htmlspecialchars($search_term) ?>">
    <button type="submit">Search</button>
    <?php if (!empty($search_term)): ?>
        <a href="scans.php">Clear</a>
    <?php endif; ?>
</form>

<?php if ($error_message): ?>
    <p class="error"><?= htmlspecialchars($error_message) ?></p>
<?php elseif (empty($scans)): ?>
    <p>No scan records found matching your criteria.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <?php
                $headers = [
                    'scan_id' => 'Scan ID',
                    'drive_id' => 'Drive',
                    'scan_date' => 'Scan Date',
                    'total_items_scanned' => 'Total Items',
                    'new_files_added' => 'New Files',
                    'existing_files_updated' => 'Updated Files',
                    'files_marked_deleted' => 'Deleted Files',
                    'scan_duration' => 'Duration',
                    'thumbnails_created' => 'Thumbs Created',
                    'thumbnail_creations_failed' => 'Thumbs Failed'
                ];
                foreach ($headers as $col => $title):
                    $is_sorted_column = ($sort_column === $col);
                    $next_direction = ($is_sorted_column && $sort_direction === 'asc') ? 'desc' : 'asc';
                    $sort_indicator = $is_sorted_column ? (($sort_direction === 'asc') ? ' &#9650;' : ' &#9660;') : ' ';
                ?>
                    <th><a href="?sort=<?= $col ?>&dir=<?= $next_direction ?>&search=<?= htmlspecialchars($search_term) ?>"><?= $title ?></a><?= $sort_indicator ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($scans as $scan): ?>
                <tr>
                    <td><?= htmlspecialchars($scan['scan_id']) ?></td>
                    <td><a href="browse.php?drive_id=<?= htmlspecialchars($scan['drive_id']) ?>"><?= htmlspecialchars($scan['drive_name']) ?></a></td>
                    <td><?= htmlspecialchars($scan['scan_date']) ?></td>
                    <td><?= htmlspecialchars($scan['total_items_scanned']) ?></td>
                    <td><?= htmlspecialchars($scan['new_files_added']) ?></td>
                    <td><?= htmlspecialchars($scan['existing_files_updated']) ?></td>
                    <td><?= htmlspecialchars($scan['files_marked_deleted']) ?></td>
                    <td><?= formatDuration((int)$scan['scan_duration']) ?></td>
                    <td><?= htmlspecialchars($scan['thumbnails_created']) ?></td>
                    <td><?= htmlspecialchars($scan['thumbnail_creations_failed']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require 'footer.php'; ?>