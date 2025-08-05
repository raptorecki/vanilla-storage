<?php
// --- Form Submission Logic (Add New Drive) ---
$add_form_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_drive'])) {
    require_once 'database.php'; // Include database connection here for early processing
    require_once __DIR__ . '/helpers/error_logger.php'; // Include error logger

    $required_fields = ['an_serial', 'name', 'vendor', 'model', 'model_number', 'size', 'serial'];
    $form_data = [];
    foreach ($_POST as $key => $value) {
        $form_data[$key] = trim($value);
    }
    $form_data['dead'] = isset($_POST['dead']) ? 1 : 0;
    $form_data['online'] = isset($_POST['online']) ? 1 : 0;
    $form_data['offsite'] = isset($_POST['offsite']) ? 1 : 0;
    $form_data['encrypted'] = isset($_POST['encrypted']) ? 1 : 0;
    $form_data['empty'] = isset($_POST['empty']) ? 1 : 0;
    $form_data['filesystem'] = trim($_POST['filesystem'] ?? '');

    foreach ($required_fields as $field) {
        if (empty($form_data[$field])) {
            $add_form_error = 'Please fill in all required fields (marked with *).';
            break;
        }
    }

    if (empty($add_form_error)) {
        try {
            $pdo->beginTransaction();

            $sql = "INSERT INTO st_drives (an_serial, name, legacy_name, vendor, model, model_number, size, serial, firmware, smart, summary, pair_id, dead, online, offsite, encrypted, empty, filesystem, date_added, date_updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$form_data['an_serial'], $form_data['name'], $form_data['legacy_name'], $form_data['vendor'], $form_data['model'], $form_data['model_number'], $form_data['size'], $form_data['serial'], $form_data['firmware'], $form_data['smart'], $form_data['summary'], $form_data['pair_id'] ?: null, $form_data['dead'], $form_data['online'], $form_data['offsite'], $form_data['encrypted'], $form_data['empty'], $form_data['filesystem'] ?: null]);
            
            $new_drive_id = $pdo->lastInsertId();

            // If a pair was selected, update the other drive to point back to this new one
            if (!empty($form_data['pair_id'])) {
                $stmt = $pdo->prepare("UPDATE st_drives SET pair_id = ? WHERE id = ?");
                $stmt->execute([$new_drive_id, $form_data['pair_id']]);
            }

            $pdo->commit();

            session_start(); // Start session before setting flash message
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Successfully added new drive.'];
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            log_error("Error adding drive: " . $e->getMessage()); // Log the detailed error
            $add_form_error = "An unexpected error occurred while adding the drive. Please try again."; // Generic message for the user
        }
    }
}

require 'header.php';

// --- Sorting Logic ---
$allowed_columns = ['id', 'an_serial', 'name', 'legacy_name', 'vendor', 'model', 'model_number', 'size', 'serial', 'firmware', 'smart', 'summary', 'pair_name', 'dead', 'online', 'offsite', 'encrypted', 'empty', 'filesystem', 'date_added', 'date_updated'];
$sort_column = $_GET['sort'] ?? 'id';
if (!in_array($sort_column, $allowed_columns)) $sort_column = 'id';
$sort_direction = strtolower($_GET['dir'] ?? 'asc');
if (!in_array($sort_direction, ['asc', 'desc'])) $sort_direction = 'asc';

// --- Search Logic ---
$search_term = trim($_GET['search'] ?? '');
$searchable_columns = ['d1.an_serial', 'd1.name', 'd1.legacy_name', 'd1.vendor', 'd1.model', 'd1.model_number', 'd1.serial', 'd1.summary', 'd1.filesystem'];

$drives = [];
$error_message = '';

try {
    // Query to get drives and their pair's name using a self-join
    $sql = "SELECT d1.*, d2.name as pair_name FROM st_drives d1 LEFT JOIN st_drives d2 ON d1.pair_id = d2.id";
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
    $drives = $stmt->fetchAll();

    // Get a list of drives that can be paired (i.e., don't have a pair yet)
    $unpaired_drives = $pdo->query("SELECT id, name, serial FROM st_drives WHERE pair_id IS NULL ORDER BY name ASC")->fetchAll();

} catch (\PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
}
?>

<h1>Drive Inventory</h1>
<form method="GET" action="drives.php" class="search-container">
    <input type="text" name="search" placeholder="Search for drive by vendor, model, serial, etc..." value="<?= htmlspecialchars($search_term) ?>">
    <button type="submit">Search</button>
    <?php if (!empty($search_term)): ?>
        <a href="drives.php">Clear</a>
    <?php endif; ?>
</form>

<details <?= !empty($add_form_error) ? 'open' : '' ?>>
    <summary>Add New Drive</summary>
    <form method="POST" action="drives.php" class="form-container">
        <input type="hidden" name="add_drive" value="1">
        <?php if ($add_form_error): ?>
            <div class="error" style="grid-column: 1 / -1;"><?= htmlspecialchars($add_form_error) ?></div>
        <?php endif; ?>
        <div class="form-group"><label for="an_serial">AN Serial *</label><input type="text" id="an_serial" name="an_serial" required value="<?= htmlspecialchars($_POST['an_serial'] ?? '') ?>"></div>
        <div class="form-group"><label for="name">Name *</label><input type="text" id="name" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"></div>
        <div class="form-group"><label for="legacy_name">Legacy Name</label><input type="text" id="legacy_name" name="legacy_name" value="<?= htmlspecialchars($_POST['legacy_name'] ?? '') ?>"></div>
        <div class="form-group"><label for="vendor">Vendor *</label><input type="text" id="vendor" name="vendor" required value="<?= htmlspecialchars($_POST['vendor'] ?? '') ?>"></div>
        <div class="form-group"><label for="model">Model *</label><input type="text" id="model" name="model" required value="<?= htmlspecialchars($_POST['model'] ?? '') ?>"></div>
        <div class="form-group"><label for="model_number">Model Number *</label><input type="text" id="model_number" name="model_number" required value="<?= htmlspecialchars($_POST['model_number'] ?? '') ?>"></div>
        <div class="form-group"><label for="size">Size (GB) *</label><input type="number" id="size" name="size" required value="<?= htmlspecialchars($_POST['size'] ?? '') ?>"></div>
        <div class="form-group"><label for="serial">Serial *</label><input type="text" id="serial" name="serial" required value="<?= htmlspecialchars($_POST['serial'] ?? '') ?>"></div>
        <div class="form-group"><label for="firmware">Firmware</label><input type="text" id="firmware" name="firmware" value="<?= htmlspecialchars($_POST['firmware'] ?? '') ?>"></div>
        <div class="form-group"><label for="smart">SMART</label><input type="text" id="smart" name="smart" value="<?= htmlspecialchars($_POST['smart'] ?? '') ?>"></div>
        <div class="form-group"><label for="filesystem">Filesystem</label><input type="text" id="filesystem" name="filesystem" placeholder="e.g., ext4, ntfs" value="<?= htmlspecialchars($_POST['filesystem'] ?? '') ?>"></div>
        <div class="form-group">
            <label for="pair_id">Pair with Drive (RAID)</label>
            <select id="pair_id" name="pair_id">
                <option value="">None</option>
                <?php foreach ($unpaired_drives as $drive_option): ?>
                    <option value="<?= htmlspecialchars($drive_option['id']) ?>" <?= (isset($_POST['pair_id']) && $_POST['pair_id'] == $drive_option['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($drive_option['name'] . ' (' . $drive_option['serial'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="grid-column: 1 / -1; display: flex; flex-wrap: wrap; flex-direction: row; gap: 30px; align-items: center; border-top: 1px solid #333; padding-top: 15px;">
            <label class="checkbox-label"><input type="checkbox" name="dead" value="1" <?= isset($_POST['dead']) ? 'checked' : '' ?>> Is Damaged/Dead</label>
            <label class="checkbox-label"><input type="checkbox" name="online" value="1" <?= isset($_POST['online']) ? 'checked' : '' ?>> Is Online</label>
            <label class="checkbox-label"><input type="checkbox" name="offsite" value="1" <?= isset($_POST['offsite']) ? 'checked' : '' ?>> Is Offsite</label>
            <label class="checkbox-label"><input type="checkbox" name="encrypted" value="1" <?= isset($_POST['encrypted']) ? 'checked' : '' ?>> Is Encrypted</label>
            <label class="checkbox-label"><input type="checkbox" name="empty" value="1" <?= isset($_POST['empty']) ? 'checked' : '' ?>> Is Empty</label>
        </div>
        <div class="form-group" style="grid-column: 1 / -1;"><label for="summary">Summary</label><input type="text" id="summary" name="summary" value="<?= htmlspecialchars($_POST['summary'] ?? '') ?>"></div>
        <div class="form-actions"><button type="submit">Add Drive</button></div>
    </form>
</details>

<?php if ($error_message): ?>
    <p class="error"><?= htmlspecialchars($error_message) ?></p>
<?php elseif (empty($drives)): ?>
    <p>No drives found matching your criteria.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <?php
                $headers = [
                    'id' => 'ID', 'an_serial' => 'AN Serial', 'name' => 'Name', 'legacy_name' => 'Legacy Name',
                    'vendor' => 'Vendor', 'model' => 'Model', 'model_number' => 'Model No.', 'size' => 'Size', 'serial' => 'Serial', 'firmware' => 'Firmware',
                    'smart' => 'SMART', 'summary' => 'Summary', 'dead' => 'Dead', 'online' => 'Online', 'offsite' => 'Offsite',
                    'encrypted' => 'Encrypted', 'empty' => 'Empty', 'filesystem' => 'Filesystem', 'pair_name' => 'Paired With',
                    'date_added' => 'Added', 'date_updated' => 'Last Scanned', 'actions' => 'Actions'
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
            <?php foreach ($drives as $drive): ?>
                <tr>
                    <td><?= htmlspecialchars($drive['id']) ?></td>
                    <td><?= htmlspecialchars($drive['an_serial']) ?></td>
                    <td><a href="browse.php?drive_id=<?= htmlspecialchars($drive['id']) ?>"><?= htmlspecialchars($drive['name']) ?></a></td>
                    <td><?= htmlspecialchars($drive['legacy_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($drive['vendor']) ?></td>
                    <td><?= htmlspecialchars($drive['model']) ?></td>
                    <td><?= htmlspecialchars($drive['model_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(formatSize((int)$drive['size'])) ?></td>
                    <td><?= htmlspecialchars($drive['serial']) ?></td>
                    <td><?= htmlspecialchars($drive['firmware'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($drive['smart'] ?? '—') ?></td>
                    <td><span title="<?= htmlspecialchars($drive['summary'] ?? '') ?>"><?= htmlspecialchars(mb_strimwidth($drive['summary'] ?? '—', 0, 40, "...")) ?></span></td>
                    <td><?= $drive['dead'] ? 'Yes' : 'No' ?></td>
                    <td><?= $drive['online'] ? 'Yes' : 'No' ?></td>
                    <td><?= $drive['offsite'] ? 'Yes' : 'No' ?></td>
                    <td><?= $drive['encrypted'] ? 'Yes' : 'No' ?></td>
                    <td><?= $drive['empty'] ? 'Yes' : 'No' ?></td>
                    <td><?= htmlspecialchars($drive['filesystem'] ?? '—') ?></td>
                    <td><?= !empty($drive['pair_id']) ? '<a href="?search=' . htmlspecialchars($drive['pair_name'] ?? '') . '">' . htmlspecialchars($drive['pair_name'] ?? 'ID: ' . $drive['pair_id']) . '</a>' : '—' ?></td>
                    <td><?= htmlspecialchars($drive['date_added']) ?></td>
                    <td><?= htmlspecialchars($drive['date_updated']) ?></td>
                    <td><a href="edit_drive.php?id=<?= htmlspecialchars($drive['id']) ?>">Edit</a> | <a href="delete_drive.php?id=<?= htmlspecialchars($drive['id']) ?>">Delete</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require 'footer.php'; ?>