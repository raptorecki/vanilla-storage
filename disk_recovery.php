<?php
// disk_recovery.php
//
// This script is designed to collect critical disk recovery information from a storage drive.
// It performs a series of read-only operations to back up partition tables and file system metadata.
// All operations are non-destructive and do not modify the drive in any way.
//
// The script will perform the following actions:
// 1. Back up the GPT partition table using sgdisk.
// 2. Back up the Master Boot Record (MBR) using dd.
// 3. Analyze partitions and create a log using TestDisk.
// 4. For each NTFS partition found, it will:
//    a. Gather detailed NTFS volume information using ntfsinfo.
//    b. Back up the NTFS Master File Table (MFT) using ntfsclone.
// 5. Store all collected data in the 'st_recovery' database table.
//

/**
 * Collects disk recovery data from a specified drive and stores it in the database.
 *
 * @param PDO $pdo The database connection object.
 * @param int $driveId The ID of the drive being scanned.
 * @param int $scanId The ID of the current scan.
 * @param string $deviceForSerial The device path (e.g., /dev/sda).
 */
function collect_recovery_data(PDO $pdo, int $driveId, int $scanId, string $deviceForSerial) {
    echo "  > Starting disk recovery data collection process.\n";
    echo "  > All operations are read-only and non-destructive.\n";

    // Define the sequence of recovery commands to be executed.
    $recoveryData = [
        [
            'tool' => 'sgdisk',
            'command' => "sgdisk --backup=- {$deviceForSerial}",
            'output_type' => 'blob',
            'description' => 'GPT partition table backup. This is a critical backup of the partition scheme.',
        ],
        [
            'tool' => 'dd',
            'command' => "dd if={$deviceForSerial} bs=512 count=1",
            'output_type' => 'blob',
            'description' => 'MBR backup. This captures the Master Boot Record from the very beginning of the drive.',
        ],
        [
            'tool' => 'testdisk',
            'command' => "testdisk /dev/stdin /log", // testdisk doesn't support stdout, so we pipe the log
            'output_type' => 'text',
            'description' => 'TestDisk partition analysis log. This provides a detailed analysis of the drive\'s partitions.',
        ],
    ];

    echo "  > Searching for partitions on {$deviceForSerial}...";
    // Find all partitions on the specified device.
    $partitions = glob("{$deviceForSerial}*");
    foreach ($partitions as $partition) {
        // Check if the entry is a block device and a partition number.
        if (is_block($partition) && preg_match('/\d$/', $partition)) {
            echo "    > Found partition: {$partition}\n";
            // Get the filesystem type of the partition.
            $fsType = trim(shell_exec("lsblk -no FSTYPE " . escapeshellarg($partition)));
            echo "      - Filesystem type: {$fsType}\n";

            // If the partition is NTFS, collect additional metadata.
            if ($fsType === 'ntfs') {
                echo "      - NTFS partition found. Collecting additional metadata.\n";
                $recoveryData[] = [
                    'tool' => 'ntfsinfo',
                    'command' => "ntfsinfo -m " . escapeshellarg($partition),
                    'output_type' => 'text',
                    'description' => "NTFS volume information for {$partition}. Contains detailed stats about the NTFS volume.",
                ];
                $recoveryData[] = [
                    'tool' => 'ntfsclone',
                    'command' => "ntfsclone --metadata -o - " . escapeshellarg($partition),
                    'output_type' => 'blob',
                    'description' => "NTFS MFT backup for {$partition}. This is a critical backup of the Master File Table.",
                ];
            }
        }
    }

    echo "  > Preparing to store recovery data in the database.";
    // Prepare the SQL statement for inserting recovery data.
    $stmt = $pdo->prepare(
        "INSERT INTO st_recovery (drive_id, scan_id, tool, command, output_type, output_data, output_text, description) 
         VALUES (:drive_id, :scan_id, :tool, :command, :output_type, :output_data, :output_text, :description)"
    );

    // Execute each command and store the output in the database.
    foreach ($recoveryData as $data) {
        echo "    > Executing command: {$data['command']}\n";
        // Execute the command and capture the output.
        $output = shell_exec($data['command']);

        // Bind parameters and execute the insert statement.
        if ($data['output_type'] === 'blob') {
            $stmt->execute([
                'drive_id' => $driveId,
                'scan_id' => $scanId,
                'tool' => $data['tool'],
                'command' => $data['command'],
                'output_type' => $data['output_type'],
                'output_data' => $output,
                'output_text' => null,
                'description' => $data['description'],
            ]);
        } else {
            $stmt->execute([
                'drive_id' => $driveId,
                'scan_id' => $scanId,
                'tool' => $data['tool'],
                'command' => $data['command'],
                'output_type' => $data['output_type'],
                'output_data' => null,
                'output_text' => $output,
                'description' => $data['description'],
            ]);
        }
        echo "      - Data stored successfully.\n";
    }

    echo "  > Recovery data collection complete.\n";
}