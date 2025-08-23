<?php
require_once 'database.php';
require_once 'header.php';
require_once 'helpers/smartctl_analyzer.php'; // Include the new analyzer function

$driveId = $_GET['id'] ?? null;
$smartctlOutput = null;
$driveName = 'Unknown Drive';
$analysisResult = null;
$historicalAnalysisResult = null;

if ($driveId) {
    try {
        $stmt = $pdo->prepare("SELECT d.name, s.output AS smartctl_output FROM st_drives d LEFT JOIN st_smart s ON d.id = s.drive_id WHERE d.id = ? ORDER BY s.scan_date DESC LIMIT 1");
        $stmt->execute([$driveId]);
        $driveData = $stmt->fetch();

        if ($driveData) {
            $driveName = htmlspecialchars($driveData['name']);
            $smartctlOutput = $driveData['smartctl_output'];
            if ($smartctlOutput) {
                $analysisResult = analyzeSmartctlOutput($smartctlOutput);
                // Perform historical analysis
                $historicalAnalysisResult = analyzeSmartctlHistory($pdo, $driveId, $smartctlOutput);
            }
        } else {
            echo '<p class="error">Drive not found.</p>';
        }
    } catch (PDOException $e) {
        echo '<p class="error">Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}

?>

<h1>SMART Data for Drive: <?= $driveName ?></h1>

<?php if ($analysisResult): ?>
    <div class="smart-analysis-container">
        <h2>Current Analysis: <span class="smart-status-<?= strtolower($analysisResult['status']) ?>"><?= htmlspecialchars($analysisResult['status']) ?></span></h2>
        <?php if (!empty($analysisResult['issues'])): ?>
            <h3>Identified Issues:</h3>
            <ul>
                <?php foreach ($analysisResult['issues'] as $issue): ?>
                    <li><?= htmlspecialchars($issue) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No specific issues identified by the current analyzer.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($historicalAnalysisResult && !empty($historicalAnalysisResult)): ?>
    <div class="smart-analysis-container">
        <h2>Historical Analysis:</h2>
        <?php if (!empty($historicalAnalysisResult)): ?>
            <h3>Significant Changes Detected:</h3>
            <ul>
                <?php foreach ($historicalAnalysisResult as $issue): ?>
                    <li><?= htmlspecialchars($issue) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No significant historical changes detected.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($smartctlOutput): ?>
    <hr>
    <h2>Raw SMARTCTL Output:</h2>
    <pre><?= htmlspecialchars($smartctlOutput) ?></pre>
<?php else: ?>
    <p>No SMART data available for this drive.</p>
<?php endif; ?>

<p><a href="drives.php">Back to Drives</a></p>

<?php require_once 'footer.php'; ?>