<?php
function collect_recovery_data(PDO $pdo, int $driveId, int $scanId, string $deviceForSerial) {
    echo "  > Collecting recovery data...\n";

    $recoveryData = [
        [
            'tool' => 'sgdisk',
            'command' => "sgdisk --backup=- {$deviceForSerial}",
            'output_type' => 'blob',
            'description' => 'GPT partition table backup.',
        ],
        [
            'tool' => 'dd',
            'command' => "dd if={$deviceForSerial} bs=512 count=1",
            'output_type' => 'blob',
            'description' => 'MBR backup.',
        ],
        [
            'tool' => 'testdisk',
            'command' => "testdisk /dev/stdin /log", // testdisk doesn't support stdout, so we pipe the log
            'output_type' => 'text',
            'description' => 'TestDisk partition analysis log.',
        ],
    ];

    $partitions = glob("{$deviceForSerial}*");
    foreach ($partitions as $partition) {
        if (is_block($partition) && preg_match('/\d$/', $partition)) {
            $fsType = trim(shell_exec("lsblk -no FSTYPE " . escapeshellarg($partition)));
            if ($fsType === 'ntfs') {
                $recoveryData[] = [
                    'tool' => 'ntfsinfo',
                    'command' => "ntfsinfo -m " . escapeshellarg($partition),
                    'output_type' => 'text',
                    'description' => "NTFS volume information for {$partition}.",
                ];
                $recoveryData[] = [
                    'tool' => 'ntfsclone',
                    'command' => "ntfsclone --metadata -o - " . escapeshellarg($partition),
                    'output_type' => 'blob',
                    'description' => "NTFS MFT backup for {$partition}.",
                ];
            }
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO st_recovery (drive_id, scan_id, tool, command, output_type, output_data, output_text, description) 
         VALUES (:drive_id, :scan_id, :tool, :command, :output_type, :output_data, :output_text, :description)"
    );

    foreach ($recoveryData as $data) {
        echo "    > Running: {$data['command']}\n";
        $output = shell_exec($data['command']);

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
    }

    echo "  > Recovery data collection complete.\n";
}
