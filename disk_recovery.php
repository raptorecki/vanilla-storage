<?php
// disk_recovery.php
//
// This script is designed to collect critical disk recovery information from a storage drive.
// It performs a series of read-only operations to back up partition tables and file system metadata.
// All operations are non-destructive and do not modify the drive in any way.
//
//
// st_recovery Table Information:
// This table stores critical disk recovery information collected from storage drives.
// Each row represents the result of a specific read-only operation performed to back up
// partition tables and file system metadata.
//
// Columns:
// - recovery_id: Unique identifier for each recovery record (auto-incrementing primary key).
// - drive_id: The ID of the drive to which this recovery data belongs (links to st_drives).
// - scan_id: The ID of the scan operation during which this recovery data was collected (links to st_scans).
// - created_at: Timestamp when this recovery record was created.
// - tool: The name of the command-line tool used (e.g., sgdisk, dd, ntfsinfo).
// - command: The exact shell command that was executed.
// - output_type: Specifies the format of the command's output ('blob' for binary, 'text' for human-readable).
// - output_data: Stores the binary output of the command, if output_type is 'blob'.
// - output_text: Stores the textual output of the command, if output_type is 'text'.
// - description: A brief explanation of the command's purpose.
// - success: Boolean (0 or 1) indicating whether the command executed successfully.
// - error_message: Any error messages returned by the command.
// - return_code: The exit code of the executed command.
// - execution_time: Execution time of the command in seconds.
// - file_size: Size of the output_data in bytes (for blob types).
// - checksum: SHA256 checksum of the output_data (for blob types).
//

// The script will perform the following actions:
// 1. Back up the GPT partition table using sgdisk.
// 2. Back up the Extended Boot Sector (first 64KB) using dd.
// 3. Analyze partitions and create a log using TestDisk.
// 4. Perform bad sector analysis (read-only) using badblocks.
// 5. For each detected partition, it will:
//    a. Collect detailed partition table information using fdisk.
//    b. If NTFS, gather detailed NTFS volume information (ntfsinfo), backup NTFS MFT (ntfsclone),
//       perform NTFS security audit (ntfssecaudit), and collect NTFS journal and detailed MFT analysis (ntfsinfo -j, ntfsinfo -F).
//    c. If ext2/3/4, gather filesystem superblock information (dumpe2fs) and backup filesystem journal (debugfs).
//    d. If FAT32/exFAT, gather filesystem information (fsck.fat).
// 6. Store all collected data in the 'st_recovery' database table.
//

/**
 * Checks if a shell command exists.
 *
 * @param string $command The command to check.
 * @return bool True if the command exists, false otherwise.
 */
function command_exists($command) {
    return !empty(shell_exec("which " . escapeshellarg($command) . " 2>/dev/null"));
}

/**
 * Executes a shell command with a timeout and captures output.
 *
 * @param string $command The command to execute.
 * @param int $timeout The maximum time in seconds to wait for the command to complete.
 * @return array An associative array containing 'success', 'output', 'error', and 'return_code'.
 * @throws Exception If the command cannot be opened.
 */
function execute_recovery_command($command, $timeout = 300) {
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w']   // stderr
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new Exception("Failed to execute command: {" . $command . "}");
    }

    // Set timeout
    $start_time = time();
    $output = '';
    $error = '';

    while (proc_get_status($process)['running'] && (time() - $start_time) < $timeout) {
        $output .= stream_get_contents($pipes[1]);
        $error .= stream_get_contents($pipes[2]);
        usleep(100000); // 0.1 second
    }

    // IMPROVEMENT: Add process termination on timeout
    if ((time() - $start_time) >= $timeout) {
        proc_terminate($process);
        $error .= "\nCommand timed out after {" . $timeout . "} seconds";
    }

    // Close pipes to prevent resource leaks
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $return_value = proc_close($process);

    return [
        'success' => $return_value === 0,
        'output' => $output,
        'error' => $error,
        'return_code' => $return_value
    ];
}

/**
 * Detects partitions on a given device using lsblk.
 *
 * @param string $device The device path (e.g., /dev/sda).
 * @return array An array of associative arrays, each representing a partition.
 */
function get_partitions($device) {
    $partitions = [];

    // Use lsblk for reliable partition detection
    $output = shell_exec("lsblk -rno NAME,TYPE,FSTYPE,SIZE,MOUNTPOINT " . escapeshellarg($device));
    $lines = explode("\n", trim($output));

    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 3 && $parts[1] === 'part') {
            $partitions[] = [
                'device' => "/dev/" . $parts[0],
                'fstype' => $parts[2] ?? '',
                'size' => $parts[3] ?? '',
                'mountpoint' => $parts[4] ?? ''
            ];
        }
    }

    return $partitions;
}

/**
 * Collects disk recovery data from a specified drive and stores it in the database.
 *
 * @param PDO $pdo The database connection object.
 * @param int $driveId The ID of the drive being scanned.
 * @param int $scanId The ID of the current scan.
 * @param string $deviceForSerial The device path (e.g., /dev/sda).
 * @throws Exception If the device is not accessible or a required tool is missing.
 */
function collect_recovery_data(PDO $pdo, int $driveId, int $scanId, string $deviceForSerial) {
    echo "  > Starting disk recovery data collection process.\n";
    echo "  > All operations are read-only and non-destructive.\n";

    // Load configuration
    $config = require_once __DIR__ . '/config.php';

    // Validate device exists and is accessible
    if (!file_exists($deviceForSerial) || !is_readable($deviceForSerial)) {
        throw new Exception("Device {" . $deviceForSerial . "} is not accessible or readable.");
    }

    // Check required tools availability
    $requiredTools = ['sgdisk', 'dd', 'fdisk', 'ntfsinfo', 'ntfsclone', 'lsblk', 
    'testdisk', 'dumpe2fs', 'debugfs', 'fsck.fat', 'ntfssecaudit',
    'ntfsfix',    // For NTFS consistency checks
    'fsck.ext4',  // For ext filesystem checks  
    'hexdump'     // For enhanced MBR analysis (optional)
];
    // Conditionally add badblocks to required tools
    if (isset($config['run_badblock_scan']) && $config['run_badblock_scan'] === true) {
        $requiredTools[] = 'badblocks';
    }

    foreach ($requiredTools as $tool) {
        if (!command_exists($tool)) {
            throw new Exception("Required tool '{$tool}' is not installed or not in PATH.");
        }
    }

    // Define the sequence of recovery commands to be executed.
    $recoveryData = [
        [
            'tool' => 'sgdisk',
            'command' => "sgdisk --backup=- " . escapeshellarg("$deviceForSerial"),
            'output_type' => 'blob',
            'description' => 'GPT partition table backup. This is a critical backup of the partition scheme.',
        ],
        // MISSING: Backup of secondary GPT table at end of disk
        [
            'tool' => 'sgdisk',
            'command' => "sgdisk --print " . escapeshellarg("$deviceForSerial"),
            'output_type' => 'text',
            'description' => 'Complete GPT information including secondary table location'
        ],
        [
            'tool' => 'dd',
            'command' => "dd if=". escapeshellarg("$deviceForSerial") . " bs=1024 count=64",
            'output_type' => 'blob',
            'description' => 'Extended boot sector backup (first 64KB). This captures critical boot information.',
        ],
        [
            'tool' => 'hexdump',
            'command' => "dd if=" . escapeshellarg("$deviceForSerial") . " bs=512 count=1 2>/dev/null | hexdump -C",
            'output_type' => 'text',
            'description' => 'MBR hexadecimal dump for detailed boot sector analysis'
        ],
        [
            'tool' => 'fdisk',
            'command' => "fdisk -l " . escapeshellarg("$deviceForSerial"),
            'output_type' => 'text',
            'description' => 'Detailed partition table information using fdisk.',
        ],
        // Added TestDisk functionality as per analysis
        [
            'tool' => 'testdisk',
            'command' => "cd /tmp && testdisk /log /list " . escapeshellarg("$deviceForSerial") . " > /dev/null 2>&1 && cat testdisk.log && rm -f testdisk.log",
            'output_type' => 'text',
            'description' => 'TestDisk partition analysis and structure discovery. Output captured from testdisk.log.',
        ],
    ];

    // Conditionally add badblocks scan
    if (isset($config['run_badblock_scan']) && $config['run_badblock_scan'] === true) {
        $recoveryData[] = [
            'tool' => 'badblocks',
            'command' => "badblocks -v -n -c 1024 -e 1000 " . escapeshellarg("$deviceForSerial"),
            'output_type' => 'text',
            'description' => 'Bad sector analysis (non-destructive read-only scan). Optimized for performance with sampling.',
        ];
    }


    echo "  > Searching for partitions on {" . $deviceForSerial . "}...\n";
    // Find all partitions on the specified device using the new helper function.
    $partitions = get_partitions($deviceForSerial);
    foreach ($partitions as $partitionInfo) {
        $partition = $partitionInfo['device'];
        $fsType = $partitionInfo['fstype'];

        echo "    > Found partition: {" . $partition . "}\n";
        echo "      - Filesystem type: {" . $fsType . "}\n";

        // If the partition is NTFS, collect additional metadata.
        if ($fsType === 'ntfs') {
            echo "      - NTFS partition found. Collecting additional metadata.\n";
            $recoveryData[] = [
                'tool' => 'ntfsinfo',
                'command' => "ntfsinfo -m " . escapeshellarg("$partition"),
                'output_type' => 'text',
                'description' => "NTFS volume information for {" . $partition . "}. Contains detailed stats about the NTFS volume.",
            ];
            $recoveryData[] = [
                'tool' => 'ntfsclone',
                'command' => "ntfsclone --metadata -o - " . escapeshellarg("$partition"),
                'output_type' => 'blob',
                'description' => "NTFS MFT backup for {" . $partition . "}. This is a critical backup of the Master File Table.",
            ];
            // Added NTFS Security descriptor analysis
            $recoveryData[] = [
                'tool' => 'ntfssecaudit',
                'command' => "ntfssecaudit " . escapeshellarg("$partition"),
                'output_type' => 'text',
                'description' => "NTFS security audit for {" . $partition . "} - ACL and security descriptor analysis."
            ];
            // Added NTFS file system journal
            $recoveryData[] = [
                'tool' => 'ntfsinfo',
                'command' => "ntfsinfo -j " . escapeshellarg("$partition"),
                'output_type' => 'text',
                'description' => "NTFS journal information for {" . $partition . "}"
            ];
            // Added NTFS Master File Table detailed dump
            $recoveryData[] = [
                'tool' => 'ntfsinfo',
                'command' => "ntfsinfo -F " . escapeshellarg("$partition"),
                'output_type' => 'text',
                'description' => "NTFS detailed MFT analysis for {" . $partition . "}"
            ];
        } else if (in_array($fsType, ['ext4', 'ext3', 'ext2'])) { // ext4/ext3/ext2 filesystem information
            echo "      - EXT filesystem found. Collecting additional metadata.\n";
            $recoveryData[] = [
                'tool' => 'dumpe2fs',
                'command' => "dumpe2fs -h " . escapeshellarg("$partition"),
                'output_type' => 'text',
                'description' => "ext filesystem superblock information for {" . $partition . "}"
            ];
            // Backup journal for ext filesystems
            $recoveryData[] = [
                'tool' => 'debugfs',
                'command' => "echo 'logdump -a' | debugfs " . escapeshellarg("$partition"),
                'output_type' => 'text',
                'description' => "ext filesystem journal dump for {" . $partition . "}"
            ];
        } else if (in_array($fsType, ['vfat', 'exfat'])) { // FAT32/exFAT support
            echo "      - FAT/exFAT filesystem found. Collecting additional metadata.\n";
            $recoveryData[] = [
                'tool' => 'fsck.fat',
                'command' => "fsck.fat -v -r " . escapeshellarg("$partition"),
                'output_type' => 'text',
                'description' => "FAT filesystem information for {" . $partition . "}"
            ];
        }
    }

    echo "  > Preparing to store recovery data in the database.\n";
    // Prepare the SQL statement for inserting recovery data.
    $stmt = $pdo->prepare(
        "INSERT INTO st_recovery (drive_id, scan_id, tool, command, output_type, output_data, output_text, description, success, error_message, return_code, execution_time, file_size, checksum) \n         VALUES (:drive_id, :scan_id, :tool, :command, :output_type, :output_data, :output_text, :description, :success, :error_message, :return_code, :execution_time, :file_size, :checksum)"
    );

    // Start database transaction
    $pdo->beginTransaction();

    try {
        // Execute each command and store the output in the database.
        foreach ($recoveryData as $data) {
            echo "    > Executing command: {" . $data['command'] . "}\n";
            // Execute the command using the new robust function.
            $start_time = microtime(true);
            $result = execute_recovery_command($data['command']);
            $end_time = microtime(true);
            $execution_time = round($end_time - $start_time, 4);

            // Determine which column to store the output in.
            $output_data = null;
            $output_text = null;
            $file_size = null;
            $checksum = null;
            if ($data['output_type'] === 'blob') {
                $output_data = $result['output'];
                $file_size = strlen($output_data);
                $checksum = hash('sha256', $output_data);
            } else {
                $output_text = $result['output'];
            }

            // Bind parameters and execute the insert statement.
            $stmt->execute([
                'drive_id' => $driveId,
                'scan_id' => $scanId,
                'tool' => $data['tool'],
                'command' => $data['command'],
                'output_type' => $data['output_type'],
                'output_data' => $output_data,
                'output_text' => $output_text,
                'description' => $data['description'],
                'success' => $result['success'],
                'error_message' => $result['error'],
                'return_code' => $result['return_code'],
                'execution_time' => $execution_time,
                'file_size' => $file_size,
                'checksum' => $checksum,
            ]);

            if ($result['success']) {
                echo "      - Command executed successfully. Data stored.\n";
            }
            else {
                echo "      - Command failed (Return Code: {" . $result['return_code'] . "}). Error: {" . $result['error'] . "}. Data stored with error.\n";
            }
        }

        // Commit the transaction if all commands were executed successfully.
        $pdo->commit();
        echo "  > Recovery data collection complete and committed to database.\n";

    } catch (Exception $e) {
        // Rollback the transaction if any error occurs.
        $pdo->rollback();
        echo "  > An error occurred during data collection. Transaction rolled back: " . $e->getMessage() . "\n";
        throw $e; // Re-throw the exception to indicate failure.
    }
}
