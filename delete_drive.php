<?php
session_start(); // Start the session to handle flash messages
require_once 'database.php';
require_once __DIR__ . '/helpers/error_logger.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Invalid drive ID.'];
    header('Location: drives.php');
    exit();
}

$drive_id = (int)$_GET['id'];

// Fetch drive details for confirmation
$stmt = $pdo->prepare("SELECT * FROM st_drives WHERE id = ?");
$stmt->execute([$drive_id]);
$drive = $stmt->fetch();

if (!$drive) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Drive not found.'];
    header('Location: drives.php');
    exit();
}

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    if (strtolower(trim($_POST['confirmation_text'])) === 'yes') {
        try {
            $pdo->beginTransaction();

            // Delete the drive
            $stmt = $pdo->prepare("DELETE FROM st_drives WHERE id = ?");
            $stmt->execute([$drive_id]);

            $pdo->commit();

            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Drive "' . htmlspecialchars($drive['name']) . '" successfully deleted.'];
            header('Location: drives.php');
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            log_error("Error deleting drive: " . $e->getMessage());
            $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'An unexpected error occurred while deleting the drive. Please try again.'];
            header('Location: drives.php');
            exit();
        }
    } else {
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Deletion not confirmed. Please type "yes" to confirm.'];
        // Redirect back to the confirmation page to show the error
        header('Location: delete_drive.php?id=' . $drive_id);
        exit();
    }
}

require 'header.php';

// Display confirmation form
?>

<h1>Confirm Drive Deletion</h1>

<div class="error">
    <p><strong>WARNING:</strong> You are about to permanently delete the following drive:</p>
    <ul>
        <li><strong>ID:</strong> <?= htmlspecialchars($drive['id']) ?></li>
        <li><strong>AN Serial:</strong> <?= htmlspecialchars($drive['an_serial']) ?></li>
        <li><strong>Name:</strong> <?= htmlspecialchars($drive['name']) ?></li>
        <li><strong>Serial:</strong> <?= htmlspecialchars($drive['serial']) ?></li>
    </ul>
    <p>This action cannot be undone. All associated files will also be orphaned.</p>
    <p>To confirm deletion, please type "yes" in the box below and click "Delete Drive".</p>
</div>

<form method="POST" action="delete_drive.php?id=<?= $drive_id ?>" class="form-container">
    <div class="form-group">
        <label for="confirmation_text">Type "yes" to confirm:</label>
        <input type="text" id="confirmation_text" name="confirmation_text" required autofocus>
    </div>
    <div class="form-actions">
        <button type="submit" name="confirm_delete">Delete Drive</button>
        <a href="drives.php" class="button" style="margin-left: 10px;">Cancel</a>
    </div>
</form>

<?php require 'footer.php'; ?>