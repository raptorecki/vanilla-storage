<?php
/**
 * Establishes and configures the PDO database connection.
 *
 * This script creates a $pdo object that can be used by any script that includes it.
 * It will terminate with an error message if the connection fails.
 */

$versionConfig = require_once __DIR__ . '/version.php';
$dbConfig = require_once __DIR__ . '/config.php';
$config = array_merge($versionConfig, $dbConfig);

$dbHost = $config['db']['host'];
$dbName = $config['db']['name'];
$dbUser = $config['db']['user'];
$dbPass = $config['db']['pass'];
$charset = $config['db']['charset'];

// --- Data Source Name (DSN) for PDO ---
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);

    // --- Schema Version Check ---
    $requiredSchemaVersion = $config['db_schema_version'] ?? '0';
    
    try {
        $stmt = $pdo->query("SELECT version FROM st_version");
        $currentSchemaVersion = $stmt->fetchColumn();

        if ($currentSchemaVersion !== $requiredSchemaVersion) {
            throw new Exception(
                "Database schema version mismatch. Application requires version '{$requiredSchemaVersion}', but database is at version '{$currentSchemaVersion}'. Please run update_schema.php."
            );
        }
    } catch (PDOException $e) {
        // This likely means the st_version table doesn't exist yet.
        throw new Exception(
            "Could not verify database schema version. Please ensure the 'st_version' table exists and the database is up to date. You may need to run the initial schema setup."
        );
    }

} catch (\Exception $e) {
    // If the database connection or schema check fails, we can't do anything.
    // For CLI, just die. For web, try to show a nice error.
    if (php_sapi_name() === 'cli') {
        die("Error: " . $e->getMessage() . "\n");
    } else {
        // Attempt to prevent a redirect loop if the error is on the index page itself.
        if (basename($_SERVER['PHP_SELF']) !== 'index.php') {
            $_SESSION['flash_message'] = ['text' => "Database Error: " . $e->getMessage(), 'type' => 'error'];
            header('Location: index.php');
            exit();
        } else {
            // If we are already on index.php, just display the error directly.
            die("A critical database error occurred: " . htmlspecialchars($e->getMessage()));
        }
    }
}

/**
 * Execute a query with a timeout to prevent database blocking
 *
 * @param PDO $pdo PDO connection
 * @param string $sql SQL query
 * @param int $timeout_seconds Maximum execution time in seconds (default: 30)
 * @return PDOStatement
 * @throws PDOException if query fails or times out
 */
function query_with_timeout($pdo, $sql, $timeout_seconds = 30) {
    // Set statement timeout for this session
    $pdo->exec("SET SESSION max_statement_time = " . intval($timeout_seconds));

    try {
        $stmt = $pdo->query($sql);
        return $stmt;
    } finally {
        // Reset timeout to default (0 = no limit)
        $pdo->exec("SET SESSION max_statement_time = 0");
    }
}

/**
 * Prepare and execute a query with timeout
 *
 * @param PDO $pdo PDO connection
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @param int $timeout_seconds Maximum execution time in seconds (default: 30)
 * @return PDOStatement
 */
function prepare_with_timeout($pdo, $sql, $params = [], $timeout_seconds = 30) {
    // Set statement timeout for this session
    $pdo->exec("SET SESSION max_statement_time = " . intval($timeout_seconds));

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } finally {
        // Reset timeout to default (0 = no limit)
        $pdo->exec("SET SESSION max_statement_time = 0");
    }
}