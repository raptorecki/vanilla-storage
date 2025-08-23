<?php
require_once 'database.php';
require_once 'header.php';

$driveId = $_GET['id'] ?? null;
$smartctlOutput = null;
$driveName = 'Unknown Drive';

if ($driveId) {
    try {
        $stmt = $pdo->prepare("SELECT d.name, s.output AS smartctl_output FROM st_drives d LEFT JOIN st_smart s ON d.id = s.drive_id WHERE d.id = ? ORDER BY s.scan_date DESC LIMIT 1");
        $stmt->execute([$driveId]);
        $driveData = $stmt->fetch();

        if ($driveData) {
            $driveName = htmlspecialchars($driveData['name']);
            $smartctlOutput = $driveData['smartctl_output'];
        } else {
            echo '<p class="error">Drive not found.</p>';
        }
    } catch (PDOException $e) {
        echo '<p class="error">Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}

?>

<h1>SMART Data for Drive: <?= $driveName ?></h1>

<?php if ($smartctlOutput): ?>
    <pre><?= htmlspecialchars($smartctlOutput) ?></pre>
<?php else: ?>
    <p>No SMART data available for this drive.</p>
<?php endif; ?>

<p><a href="drives.php">Back to Drives</a></p>

<?php require_once 'footer.php'; ?>