<?php
require 'header.php';

// Check for drive ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Invalid drive ID.'];
    header('Location: drives.php');
    exit();
}

$drive_id = (int)$_GET['id'];

// Fetch drive details
$stmt = $pdo->prepare("SELECT * FROM st_drives WHERE id = ?");
$stmt->execute([$drive_id]);
$drive = $stmt->fetch();

if (!$drive) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Drive not found.'];
    header('Location: drives.php');
    exit();
}

// --- Form Submission Logic (Update Drive) ---
$update_form_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_drive'])) {
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
            $update_form_error = 'Please fill in all required fields (marked with *).';
            break;
        }
    }

    if (empty($update_form_error)) {
        try {
            $pdo->beginTransaction();

            $sql = "UPDATE st_drives SET an_serial = ?, name = ?, legacy_name = ?, vendor = ?, model = ?, model_number = ?, size = ?, serial = ?, firmware = ?, smart = ?, summary = ?, pair_id = ?, dead = ?, online = ?, offsite = ?, encrypted = ?, empty = ?, filesystem = ?, date_updated = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$form_data['an_serial'], $form_data['name'], $form_data['legacy_name'], $form_data['vendor'], $form_data['model'], $form_data['model_number'], $form_data['size'], $form_data['serial'], $form_data['firmware'], $form_data['smart'], $form_data['summary'], $form_data['pair_id'] ?: null, $form_data['dead'], $form_data['online'], $form_data['offsite'], $form_data['encrypted'], $form_data['empty'], $form_data['filesystem'] ?: null, $drive_id]);

            // If a pair was selected, update the other drive to point back to this one
            if (!empty($form_data['pair_id'])) {
                $stmt = $pdo->prepare("UPDATE st_drives SET pair_id = ? WHERE id = ?");
                $stmt->execute([$drive_id, $form_data['pair_id']]);
            }

            $pdo->commit();

            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Successfully updated drive.'];
            header('Location: drives.php');
            exit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            $update_form_error = "Error updating drive: " . $e->getMessage();
        }
    }
}

// Get a list of drives that can be paired (i.e., don't have a pair yet)
$unpaired_drives = $pdo->query("SELECT id, name, serial FROM st_drives WHERE pair_id IS NULL OR id = {$drive_id} ORDER BY name ASC")->fetchAll();

?>

<h1>Edit Drive: <?= htmlspecialchars($drive['name']) ?></h1>

<form method="POST" action="edit_drive.php?id=<?= $drive_id ?>" class="form-container">
    <input type="hidden" name="update_drive" value="1">
    <?php if ($update_form_error): ?>
        <div class="error" style="grid-column: 1 / -1;"><?= htmlspecialchars($update_form_error) ?></div>
    <?php endif; ?>
    <div class="form-group"><label for="an_serial">AN Serial *</label><input type="text" id="an_serial" name="an_serial" required value="<?= htmlspecialchars($drive['an_serial']) ?>"></div>
    <div class="form-group"><label for="name">Name *</label><input type="text" id="name" name="name" required value="<?= htmlspecialchars($drive['name']) ?>"></div>
    <div class="form-group"><label for="legacy_name">Legacy Name</label><input type="text" id="legacy_name" name="legacy_name" value="<?= htmlspecialchars($drive['legacy_name']) ?>"></div>
    <div class="form-group"><label for="vendor">Vendor *</label><input type="text" id="vendor" name="vendor" required value="<?= htmlspecialchars($drive['vendor']) ?>"></div>
    <div class="form-group"><label for="model">Model *</label><input type="text" id="model" name="model" required value="<?= htmlspecialchars($drive['model']) ?>"></div>
    <div class="form-group"><label for="model_number">Model Number *</label><input type="text" id="model_number" name="model_number" required value="<?= htmlspecialchars($drive['model_number']) ?>"></div>
    <div class="form-group"><label for="size">Size (GB) *</label><input type="number" id="size" name="size" required value="<?= htmlspecialchars($drive['size']) ?>"></div>
    <div class="form-group"><label for="serial">Serial *</label><input type="text" id="serial" name="serial" required value="<?= htmlspecialchars($drive['serial']) ?>"></div>
    <div class="form-group"><label for="firmware">Firmware</label><input type="text" id="firmware" name="firmware" value="<?= htmlspecialchars($drive['firmware']) ?>"></div>
    <div class="form-group"><label for="smart">SMART</label><input type="text" id="smart" name="smart" value="<?= htmlspecialchars($drive['smart']) ?>"></div>
    <div class="form-group"><label for="filesystem">Filesystem</label><input type="text" id="filesystem" name="filesystem" placeholder="e.g., ext4, ntfs" value="<?= htmlspecialchars($drive['filesystem']) ?>"></div>
    <div class="form-group">
        <label for="pair_id">Pair with Drive (RAID)</label>
        <select id="pair_id" name="pair_id">
            <option value="">None</option>
            <?php foreach ($unpaired_drives as $drive_option): ?>
                <option value="<?= htmlspecialchars($drive_option['id']) ?>" <?= ($drive['pair_id'] == $drive_option['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($drive_option['name'] . ' (' . $drive_option['serial'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="grid-column: 1 / -1; display: flex; flex-wrap: wrap; flex-direction: row; gap: 30px; align-items: center; border-top: 1px solid #333; padding-top: 15px;">
        <label class="checkbox-label"><input type="checkbox" name="dead" value="1" <?= $drive['dead'] ? 'checked' : '' ?>> Is Damaged/Dead</label>
        <label class="checkbox-label"><input type="checkbox" name="online" value="1" <?= $drive['online'] ? 'checked' : '' ?>> Is Online</label>
        <label class="checkbox-label"><input type="checkbox" name="offsite" value="1" <?= $drive['offsite'] ? 'checked' : '' ?>> Is Offsite</label>
        <label class="checkbox-label"><input type="checkbox" name="encrypted" value="1" <?= $drive['encrypted'] ? 'checked' : '' ?>> Is Encrypted</label>
        <label class="checkbox-label"><input type="checkbox" name="empty" value="1" <?= $drive['empty'] ? 'checked' : '' ?>> Is Empty</label>
    </div>
    <div class="form-group" style="grid-column: 1 / -1;"><label for="summary">Summary</label><input type="text" id="summary" name="summary" value="<?= htmlspecialchars($drive['summary']) ?>"></div>
    <div class="form-actions">
        <button type="submit" name="update_drive">Update Drive</button>
        <a href="drives.php">Cancel</a>
    </div>
</form>

<?php require 'footer.php'; ?>