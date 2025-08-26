<?php
/**
 * Shared footer for the application. Closes the main container, body, and html tags.
 */
$versionConfig = require __DIR__ . '/version.php';
$appVersion = $versionConfig['app_version'] ?? 'unknown';
?>
</div> <!-- closes the .container div from header.php -->

<footer class="footer mt-auto py-3 bg-light">
    <div class="container" style="text-align: center;">
        <span class="text-muted">Vanilla Storage Tracker v<?php echo htmlspecialchars($appVersion); ?></span>
    </div>
</footer>

</body>
</html>