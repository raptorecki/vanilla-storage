<?php
session_start();
require_once 'database.php';
require_once __DIR__ . '/helpers/error_logger.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Invalid drive ID.'];
    header('Location: drives.php');
    exit();
}

$drive_id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM st_drives WHERE id = ?");
$stmt->execute([$drive_id]);
$drive = $stmt->fetch();

if (!$drive) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Drive not found.'];
    header('Location: drives.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete_files'])) {
    if (strtolower(trim($_POST['confirmation_text'])) === 'yes') {
        try {
            $stmt = $pdo->prepare("DELETE FROM st_files WHERE drive_id = ?");
            $stmt->execute([$drive_id]);

            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'All files for drive "' . htmlspecialchars($drive['name']) . '" have been permanently deleted.'];
            header('Location: drives.php');
            exit();

        } catch (PDOException $e) {
            log_error("Error deleting drive files: " . $e->getMessage());
            $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'An unexpected error occurred. Please try again.'];
            header('Location: drives.php');
            exit();
        }
    } else {
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Action not confirmed. Please type "yes" to confirm.'];
        header('Location: delete_drive_files.php?id=' . $drive_id);
        exit();
    }
}

require 'header.php';
?>

<h1>Confirm Deletion of All Files on Drive</h1>

<div class="error">
    <p><strong>DANGER:</strong> You are about to permanently delete all file records associated with the following drive from the database:</p>
    <ul>
        <li><strong>ID:</strong> <?= htmlspecialchars($drive['id']) ?></li>
        <li><strong>Name:</strong> <?= htmlspecialchars($drive['name']) ?></li>
        <li><strong>Serial:</strong> <?= htmlspecialchars($drive['serial']) ?></li>
    </ul>
    <p>This action is irreversible and will remove all file entries for this drive. The drive entry itself will not be deleted.</p>
    <p>To confirm, please type "yes" in the box below and click "Delete All Files".</p>
</div>

<form method="POST" action="delete_drive_files.php?id=<?= $drive_id ?>" class="form-container">
    <div class="form-group">
        <label for="confirmation_text">Type "yes" to confirm:</label>
        <input type="text" id="confirmation_text" name="confirmation_text" required autofocus>
    </div>
    <div class="form-actions">
        <button type="submit" name="confirm_delete_files" class="danger">Delete All Files</button>
        <a href="drives.php" class="button">Cancel</a>
    </div>
</form>

<?php require 'footer.php'; ?>
