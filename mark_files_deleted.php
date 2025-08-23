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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_mark_deleted'])) {
    if (strtolower(trim($_POST['confirmation_text'])) === 'yes') {
        try {
            $stmt = $pdo->prepare("UPDATE st_files SET date_deleted = NOW() WHERE drive_id = ?");
            $stmt->execute([$drive_id]);

            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'All files for drive "' . htmlspecialchars($drive['name']) . '" have been marked as deleted.'];
            header('Location: drives.php');
            exit();

        } catch (PDOException $e) {
            log_error("Error marking files as deleted: " . $e->getMessage());
            $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'An unexpected error occurred. Please try again.'];
            header('Location: drives.php');
            exit();
        }
    } else {
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Action not confirmed. Please type "yes" to confirm.'];
        header('Location: mark_files_deleted.php?id=' . $drive_id);
        exit();
    }
}

require 'header.php';
?>

<h1>Confirm Marking All Files as Deleted</h1>

<div class="warning">
    <p><strong>Warning:</strong> You are about to mark all files associated with the following drive as deleted:</p>
    <ul>
        <li><strong>ID:</strong> <?= htmlspecialchars($drive['id']) ?></li>
        <li><strong>Name:</strong> <?= htmlspecialchars($drive['name']) ?></li>
        <li><strong>Serial:</strong> <?= htmlspecialchars($drive['serial']) ?></li>
    </ul>
    <p>This action will set the 'date_deleted' for all files on this drive, effectively hiding them from the main view. This action can be reversed manually in the database.</p>
    <p>To confirm, please type "yes" in the box below and click "Mark Files as Deleted".</p>
</div>

<form method="POST" action="mark_files_deleted.php?id=<?= $drive_id ?>" class="form-container">
    <div class="form-group">
        <label for="confirmation_text">Type "yes" to confirm:</label>
        <input type="text" id="confirmation_text" name="confirmation_text" required autofocus>
    </div>
    <div class="form-actions">
        <button type="submit" name="confirm_mark_deleted">Mark Files as Deleted</button>
        <a href="drives.php" class="button">Cancel</a>
    </div>
</form>

<?php require 'footer.php'; ?>
