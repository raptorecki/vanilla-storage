<?php

/**
 * Analyzes smartctl output to determine drive health.
 *
 * @param string $smartctlOutput The raw output from the smartctl command.
 * @return array An associative array containing:
 *               - 'status': string ('OK', 'WARNING', 'CRITICAL', 'UNKNOWN')
 *               - 'issues': array of strings describing identified issues.
 */
function analyzeSmartctlOutput(string $smartctlOutput): array
{
    $status = 'OK';
    $issues = [];

    // Convert output to lowercase for case-insensitive matching
    $outputLower = strtolower($smartctlOutput);

    // --- Overall SMART health status ---
    if (preg_match('/smart overall-health self-assessment test result: (.*)/i', $smartctlOutput, $matches)) {
        $overallHealth = trim(strtolower($matches[1]));
        if ($overallHealth === 'passed') {
            // All good
        } elseif ($overallHealth === 'failed') {
            $status = 'CRITICAL';
            $issues[] = 'Overall SMART health self-assessment test FAILED.';
        } else {
            $status = 'UNKNOWN';
            $issues[] = 'Could not determine overall SMART health status.';
        }
    } else {
        $status = 'UNKNOWN';
        $issues[] = 'Overall SMART health status line not found.';
    }

    // --- Check for specific SMART attributes (common for HDDs and SSDs) ---

    // Reallocated Sector Count (ID 5)
    if (preg_match('/^  5 Reallocated_Sector_Ct\s+.*?\s+(\d+)$/m', $smartctlOutput, $matches)) {
        $reallocatedSectors = (int)$matches[1];
        if ($reallocatedSectors > 0) {
            $status = ($status === 'OK') ? 'WARNING' : $status; // Don't downgrade CRITICAL
            $issues[] = "Reallocated Sectors: {$reallocatedSectors} (indicates bad sectors have been remapped).";
        }
    }

    // Current Pending Sector Count (ID 197)
    if (preg_match('/^197 Current_Pending_Sector_Ct\s+.*?\s+(\d+)$/m', $smartctlOutput, $matches)) {
        $pendingSectors = (int)$matches[1];
        if ($pendingSectors > 0) {
            $status = ($status === 'OK') ? 'WARNING' : $status;
            $issues[] = "Current Pending Sectors: {$pendingSectors} (unstable sectors waiting to be remapped).";
        }
    }

    // Offline Uncorrectable Sector Count (ID 198)
    if (preg_match('/^198 Offline_Uncorrectable\s+.*?\s+(\d+)$/m', $smartctlOutput, $matches)) {
        $uncorrectableSectors = (int)$matches[1];
        if ($uncorrectableSectors > 0) {
            $status = 'CRITICAL';
            $issues[] = "Offline Uncorrectable Sectors: {$uncorrectableSectors} (data loss likely).";
        }
    }

    // Power-On Hours (ID 9) - informational, but high values might be relevant
    if (preg_match('/^  9 Power_On_Hours\s+.*?\s+(\d+)$/m', $smartctlOutput, $matches)) {
        $powerOnHours = (int)$matches[1];
        if ($powerOnHours > (5 * 365 * 24)) { // More than 5 years of power-on time
            $issues[] = "High Power-On Hours: {$powerOnHours} (drive has significant operational time).";
        }
    }

    // Temperature (ID 194) - common for both
    if (preg_match('/^194 Temperature_Celsius\s+.*?\s+(\d+)$/m', $smartctlOutput, $matches)) {
        $temperature = (int)$matches[1];
        if ($temperature >= 50) { // Warning for 50C and above
            $status = ($status === 'OK') ? 'WARNING' : $status;
            $issues[] = "High Temperature: {$temperature}°C (consider improving cooling).";
        }
    } elseif (preg_match('/Current Drive Temperature:\s+(\d+)\s+C/i', $smartctlOutput, $matches)) { // Alternative temperature format
        $temperature = (int)$matches[1];
        if ($temperature >= 50) {
            $status = ($status === 'OK') ? 'WARNING' : $status;
            $issues[] = "High Temperature: {$temperature}°C (consider improving cooling).";
        }
    }

    // --- SSD Specific Checks ---
    // Percentage Used (ID 173 or 202 depending on vendor)
    if (preg_match('/^173 Wear_Leveling_Count\s+.*?\s+(\d+)$/m', $smartctlOutput, $matches)) {
        $wearLevelingCount = (int)$matches[1];
        if ($wearLevelingCount >= 90) { // 90% or more wear
            $status = ($status === 'OK') ? 'WARNING' : $status;
            $issues[] = "SSD Wear Leveling Count: {$wearLevelingCount}% (drive nearing end of endurance).";
        }
    } elseif (preg_match('/^202 Percentage_Lifetime_Remain\s+.*?\s+(\d+)$/m', $smartctlOutput, $matches)) {
        $lifetimeRemaining = (int)$matches[1];
        if ($lifetimeRemaining <= 10) { // 10% or less remaining
            $status = ($status === 'OK') ? 'WARNING' : $status;
            $issues[] = "SSD Lifetime Remaining: {$lifetimeRemaining}% (drive nearing end of endurance).";
        }
    }

    // Data Units Written/Read (ID 241/242) - informational, but high values might be relevant
    if (preg_match('/^241 Total_LBA_Written\s+.*?\s+(\d+)$/m', $smartctlOutput, $matches)) {
        $lbaWritten = (int)$matches[1];
        // Convert to TB for readability (assuming 512-byte sectors)
        $tbWritten = round($lbaWritten * 512 / (1000 * 1000 * 1000 * 1000), 2);
        if ($tbWritten > 100) { // Example threshold: over 100TB written
            $issues[] = "Total Data Written (SSD): {$tbWritten} TB (high write volume).";
        }
    }

    // If no specific issues found but status is not OK, ensure a generic message is added
    if ($status !== 'OK' && empty($issues)) {
        $issues[] = 'SMART status indicates a potential issue, but no specific attribute thresholds were crossed by this analyzer.';
    }

    return [
        'status' => $status,
        'issues' => $issues,
    ];
}

/**
 * Extracts a specific SMART attribute value from smartctl output.
 *
 * @param string $output The smartctl output.
 * @param string $attributeName The name of the attribute (e.g., 'Reallocated_Sector_Ct').
 * @param int|null $id The ID of the attribute (e.g., 5).
 * @return int|null The attribute value, or null if not found.
 */
function getSmartAttribute(string $output, string $attributeName, ?int $id = null): ?int
{
    $pattern = '';
    if ($id !== null) {
        $pattern = '/^\s*' . preg_quote($id) . '\s+' . preg_quote($attributeName) . '\s+.*?\s+(\d+)$/m';
    } else {
        $pattern = '/^\s*\d+\s+' . preg_quote($attributeName) . '\s+.*?\s+(\d+)$/m';
    }

    if (preg_match($pattern, $output, $matches)) {
        return (int)$matches[1];
    }
    return null;
}

/**
 * Analyzes historical smartctl data for a drive to detect significant changes.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param int $driveId The ID of the drive.
 * @param string $currentSmartctlOutput The latest smartctl output for the drive.
 * @return array An array of strings describing historical issues.
 */
function analyzeSmartctlHistory(PDO $pdo, int $driveId, string $currentSmartctlOutput): array
{
    $historicalIssues = [];

    // Fetch previous smartctl outputs for this drive, excluding the latest one
    $stmt = $pdo->prepare("SELECT output, scan_date FROM st_smart WHERE drive_id = ? ORDER BY scan_date DESC LIMIT 1, 5"); // Get up to 5 previous scans
    $stmt->execute([$driveId]);
    $previousScans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($previousScans)) {
        return ['No historical data for comparison.'];
    }

    $currentAttributes = [
        'Reallocated_Sector_Ct' => getSmartAttribute($currentSmartctlOutput, 'Reallocated_Sector_Ct', 5),
        'Current_Pending_Sector_Ct' => getSmartAttribute($currentSmartctlOutput, 'Current_Pending_Sector_Ct', 197),
        'Offline_Uncorrectable' => getSmartAttribute($currentSmartctlOutput, 'Offline_Uncorrectable', 198),
        'Temperature_Celsius' => getSmartAttribute($currentSmartctlOutput, 'Temperature_Celsius', 194),
        'Wear_Leveling_Count' => getSmartAttribute($currentSmartctlOutput, 'Wear_Leveling_Count', 173), // For SSDs
        'Percentage_Lifetime_Remain' => getSmartAttribute($currentSmartctlOutput, 'Percentage_Lifetime_Remain', 202), // For SSDs
    ];

    foreach ($previousScans as $scan) {
        $previousOutput = $scan['output'];
        $scanDate = $scan['scan_date'];

        $previousAttributes = [
            'Reallocated_Sector_Ct' => getSmartAttribute($previousOutput, 'Reallocated_Sector_Ct', 5),
            'Current_Pending_Sector_Ct' => getSmartAttribute($previousOutput, 'Current_Pending_Sector_Ct', 197),
            'Offline_Uncorrectable' => getSmartAttribute($previousOutput, 'Offline_Uncorrectable', 198),
            'Temperature_Celsius' => getSmartAttribute($previousOutput, 'Temperature_Celsius', 194),
            'Wear_Leveling_Count' => getSmartAttribute($previousOutput, 'Wear_Leveling_Count', 173),
            'Percentage_Lifetime_Remain' => getSmartAttribute($previousOutput, 'Percentage_Lifetime_Remain', 202),
        ];

        // Compare attributes
        foreach ($currentAttributes as $attrName => $currentValue) {
            $previousValue = $previousAttributes[$attrName] ?? null;

            if ($currentValue !== null && $previousValue !== null) {
                switch ($attrName) {
                    case 'Reallocated_Sector_Ct':
                    case 'Current_Pending_Sector_Ct':
                    case 'Offline_Uncorrectable':
                        if ($currentValue > $previousValue) {
                            $historicalIssues[] = "Increased {$attrName} from {$previousValue} to {$currentValue} since {$scanDate}.";
                        }
                        break;
                    case 'Temperature_Celsius':
                        if ($currentValue > $previousValue + 5) { // 5 degree Celsius increase
                            $historicalIssues[] = "Temperature increased from {$previousValue}°C to {$currentValue}°C since {$scanDate}.";
                        } elseif ($currentValue < $previousValue - 5) {
                            $historicalIssues[] = "Temperature decreased from {$previousValue}°C to {$currentValue}°C since {$scanDate}.";
                        }
                        break;
                    case 'Wear_Leveling_Count': // For SSDs, higher is worse
                        if ($currentValue > $previousValue + 5) { // 5% increase in wear
                            $historicalIssues[] = "SSD Wear Leveling Count increased from {$previousValue}% to {$currentValue}% since {$scanDate}.";
                        }
                        break;
                    case 'Percentage_Lifetime_Remain': // For SSDs, lower is worse
                        if ($currentValue < $previousValue - 5) { // 5% decrease in remaining lifetime
                            $historicalIssues[] = "SSD Lifetime Remaining decreased from {$previousValue}% to {$currentValue}% since {$scanDate}.";
                        }
                        break;
                }
            }
        }
    }

    return array_unique($historicalIssues); // Return unique issues
}
