<?php
session_start(); // Start the session to handle flash messages
require_once 'database.php';
require_once __DIR__ . '/helpers/error_logger.php';

// --- Initial Setup & Data Fetching ---
$drive_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$drive_id) {
	$_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Invalid Drive ID provided.'];
	header('Location: drives.php');
	exit();
}

$error_message = '';
$drive_data = null;
$unpaired_drives = [];

try {
	// Fetch the drive to be edited
	$stmt = $pdo->prepare("SELECT * FROM st_drives WHERE id = ?");
	$stmt->execute([$drive_id]);
	$drive_data = $stmt->fetch();

	if (!$drive_data) {
		// Use a session flash message for redirection
		$_SESSION['flash_message'] = ['type' => 'error', 'text' => "Drive with ID {$drive_id} not found."];
		header('Location: drives.php');
		exit();
	}

	// Get a list of drives that can be paired.
	// This includes drives with no pair, and the current drive's existing partner.
	$stmt = $pdo->prepare("SELECT id, name, serial FROM st_drives WHERE pair_id IS NULL OR id = ? ORDER BY name ASC");
	$stmt->execute([$drive_data['pair_id'] ?? 0]);
	$unpaired_drives = $stmt->fetchAll();

} catch (\Exception $e) {
	// This catch is for initial data load failure.
	$error_message = "Error loading drive data: " . $e->getMessage();
	// Set drive_data to false to prevent form rendering
	$drive_data = false;
}

// --- Form Submission Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';

	// --- Handle Drive Details Update ---
	if ($action === 'update_details') {
		try {
			$required_fields = ['an_serial', 'name', 'vendor', 'model', 'model_number', 'size', 'serial'];
			$form_data = [];
			foreach ($_POST as $key => $value) {
				$form_data[$key] = trim($value);
			}
			// Handle checkboxes
			$form_data['dead'] = isset($_POST['dead']) ? 1 : 0;
			$form_data['online'] = isset($_POST['online']) ? 1 : 0;
			$form_data['offsite'] = isset($_POST['offsite']) ? 1 : 0;
			$form_data['encrypted'] = isset($_POST['encrypted']) ? 1 : 0;
			$form_data['empty'] = isset($_POST['empty']) ? 1 : 0;

			foreach ($required_fields as $field) {
				if (empty($form_data[$field])) {
					throw new Exception('Please fill in all required fields (marked with *).');
				}
			}

			$pdo->beginTransaction();

			// --- Handle Pairing Logic ---
			$old_pair_id = $drive_data['pair_id'];
			$new_pair_id = !empty($form_data['pair_id']) ? (int)$form_data['pair_id'] : null;

			// 1. If the drive was previously paired, but now isn't (or is paired with a different drive), un-pair the old partner.
			if ($old_pair_id && $old_pair_id != $new_pair_id) {
				$stmt = $pdo->prepare("UPDATE st_drives SET pair_id = NULL WHERE id = ?");
				$stmt->execute([$old_pair_id]);
			}

			// 2. Update the main drive's details, including its new pair_id
			$sql = "UPDATE st_drives SET
                        an_serial = ?, name = ?, legacy_name = ?, vendor = ?, model = ?,
                        model_number = ?, size = ?, serial = ?, firmware = ?, smart = ?,
                        summary = ?, pair_id = ?, dead = ?, online = ?, offsite = ?,
                        encrypted = ?, empty = ?, filesystem = ?, date_updated = NOW()
                    WHERE id = ?";
			$stmt = $pdo->prepare($sql);
			$stmt->execute([
				$form_data['an_serial'], $form_data['name'], $form_data['legacy_name'], $form_data['vendor'], $form_data['model'],
				$form_data['model_number'], $form_data['size'], $form_data['serial'], $form_data['firmware'], $form_data['smart'],
				$form_data['summary'], $new_pair_id, $form_data['dead'], $form_data['online'], $form_data['offsite'],
				$form_data['encrypted'], $form_data['empty'], $form_data['filesystem'] ?: null,
				$drive_id
			]);

			// 3. If a new pair was selected, update the new partner to point back to this drive.
			if ($new_pair_id) {
				$stmt = $pdo->prepare("UPDATE st_drives SET pair_id = ? WHERE id = ?");
				$stmt->execute([$drive_id, $new_pair_id]);
			}

			$pdo->commit();

			$_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Drive details updated successfully.'];
			header('Location: drives.php'); // Redirect to the list view
			exit();

		} catch (\Exception $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			$error_message = $e->getMessage();
			// To show the error without losing user's input, merge form data with the original drive data
			$drive_data = array_merge($drive_data, $form_data);
		}
	}
}

require 'header.php';
?>

<h1>Edit Drive: <?= htmlspecialchars($drive_data['name'] ?? 'Not Found') ?></h1>

<?php if ($error_message): ?>
    <div class="error"><?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>

<?php if ($drive_data): ?>
<form method="POST" action="edit_drive.php?id=<?= $drive_id ?>" class="form-container">
    <input type="hidden" name="action" value="update_details">

    <div class="form-group"><label for="an_serial">AN Serial *</label><input type="text" id="an_serial" name="an_serial" required value="<?= htmlspecialchars($drive_data['an_serial'] ?? '') ?>"></div>
    <div class="form-group"><label for="name">Name *</label><input type="text" id="name" name="name" required value="<?= htmlspecialchars($drive_data['name'] ?? '') ?>"></div>
    <div class="form-group"><label for="legacy_name">Legacy Name</label><input type="text" id="legacy_name" name="legacy_name" value="<?= htmlspecialchars($drive_data['legacy_name'] ?? '') ?>"></div>
    <div class="form-group"><label for="vendor">Vendor *</label><input type="text" id="vendor" name="vendor" required value="<?= htmlspecialchars($drive_data['vendor'] ?? '') ?>"></div>
    <div class="form-group"><label for="model">Model *</label><input type="text" id="model" name="model" required value="<?= htmlspecialchars($drive_data['model'] ?? '') ?>"></div>
    <div class="form-group"><label for="model_number">Model Number *</label><input type="text" id="model_number" name="model_number" required value="<?= htmlspecialchars($drive_data['model_number'] ?? '') ?>"></div>
    <div class="form-group"><label for="size">Size (GB) *</label><input type="number" id="size" name="size" required value="<?= htmlspecialchars($drive_data['size'] ?? '') ?>"></div>
    <div class="form-group"><label for="serial">Serial *</label><input type="text" id="serial" name="serial" required value="<?= htmlspecialchars($drive_data['serial'] ?? '') ?>"></div>
    <div class="form-group"><label for="firmware">Firmware</label><input type="text" id="firmware" name="firmware" value="<?= htmlspecialchars($drive_data['firmware'] ?? '') ?>"></div>
    <div class="form-group"><label for="smart">SMART</label><input type="text" id="smart" name="smart" value="<?= htmlspecialchars($drive_data['smart'] ?? '') ?>"></div>
    <div class="form-group"><label for="filesystem">Filesystem</label><input type="text" id="filesystem" name="filesystem" placeholder="e.g., ext4, ntfs" value="<?= htmlspecialchars($drive_data['filesystem'] ?? '') ?>"></div>
    <div class="form-group"><label for="pair_id">Pair with Drive (RAID)</label><select id="pair_id" name="pair_id"><option value="">None</option><?php foreach ($unpaired_drives as $drive_option): ?><?php if ($drive_option['id'] == $drive_id) continue; ?><option value="<?= htmlspecialchars($drive_option['id']) ?>" <?= (isset($drive_data['pair_id']) && $drive_data['pair_id'] == $drive_option['id']) ? 'selected' : '' ?>><?= htmlspecialchars($drive_option['name'] . ' (' . $drive_option['serial'] . ')') ?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="grid-column: 1 / -1; display: flex; flex-wrap: wrap; flex-direction: row; gap: 30px; align-items: center; border-top: 1px solid #333; padding-top: 15px;">
        <label class="checkbox-label"><input type="checkbox" name="dead" value="1" <?= !empty($drive_data['dead']) ? 'checked' : '' ?>> Is Damaged/Dead</label>
        <label class="checkbox-label"><input type="checkbox" name="online" value="1" <?= !empty($drive_data['online']) ? 'checked' : '' ?>> Is Online</label>
        <label class="checkbox-label"><input type="checkbox" name="offsite" value="1" <?= !empty($drive_data['offsite']) ? 'checked' : '' ?>> Is Offsite</label>
        <label class="checkbox-label"><input type="checkbox" name="encrypted" value="1" <?= !empty($drive_data['encrypted']) ? 'checked' : '' ?>> Is Encrypted</label>
        <label class="checkbox-label"><input type="checkbox" name="empty" value="1" <?= !empty($drive_data['empty']) ? 'checked' : '' ?>> Is Empty</label>
    </div>
    <div class="form-group" style="grid-column: 1 / -1;"><label for="summary">Summary</label><input type="text" id="summary" name="summary" value="<?= htmlspecialchars($drive_data['summary'] ?? '') ?>"></div>
    <div class="form-actions"><button type="submit">Update Drive</button><a href="drives.php" class="button">Cancel</a></div>
</form>
<?php else: ?>
    <p>Could not load drive data. It may have been deleted.</p>
<?php endif; ?>

<?php require 'footer.php'; ?>
