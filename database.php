<?php
/**
 * Establishes and configures the PDO database connection.
 *
 * This script creates a $pdo object that can be used by any script that includes it.
 * It will terminate with an error message if the connection fails.
 */

$config = require_once __DIR__ . '/config.php';

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
} catch (\PDOException $e) {
    // If the database connection fails, we can't do anything.
    // Die and show a simple error message, consistent with the app's style.
    $_SESSION['flash_message'] = ['text' => "Database Connection Failed: " . $e->getMessage(), 'type' => 'error'];
    header('Location: index.php');
    exit();
}