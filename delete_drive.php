<?php
require 'header.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Invalid drive ID.'];
    header('Location: drives.php');
    exit();
}

$drive_id = (int)$_GET['id'];

try {
    $pdo->beginTransaction();

    // Check if the drive exists
    $stmt = $pdo->prepare("SELECT id FROM st_drives WHERE id = ?");
    $stmt->execute([$drive_id]);
    $drive = $stmt->fetch();

    if (!$drive) {
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Drive not found.'];
        header('Location: drives.php');
        exit();
    }

    // Delete the drive
    $stmt = $pdo->prepare("DELETE FROM st_drives WHERE id = ?");
    $stmt->execute([$drive_id]);

    $pdo->commit();

    $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Drive successfully deleted.'];
    header('Location: drives.php');
    exit();

} catch (\PDOException $e) {
    $pdo->rollBack();
    log_error("Error deleting drive: " . $e->getMessage());
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'An unexpected error occurred while deleting the drive. Please try again.'];
    header('Location: drives.php');
    exit();
}
?>