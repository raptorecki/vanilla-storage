<#
.SYNOPSIS
    Scans a drive for files and metadata, producing a .csv and .ini file for the Vanilla Storage application.

.DESCRIPTION
    This script performs a comprehensive, read-only scan of a specified drive partition.
    It captures drive hardware information, SMART data, and detailed metadata for every file.
    The output is split into two files named after the drive's serial number:
    - <SerialNumber>.csv: Contains detailed information for each file, mirroring the st_files table.
    - <SerialNumber>.ini: Contains drive model, serial, filesystem, and full SMART data.

    CRITICAL: This script performs READ-ONLY operations on the target drive. It will also
    prevent writing the output files to the drive being scanned.

.PARAMETER DriveLetter
    The letter of the drive to scan (e.g., "D:").

.PARAMETER PartitionNumber
    The partition number being scanned on the physical drive.

.PARAMETER OutputPath
    Optional. The directory where the output files will be saved. 
    Defaults to the script's current directory.

.EXAMPLE
    .\scan_drive.ps1 -DriveLetter E: -PartitionNumber 1
    (Scans E: partition 1, saves output to the current directory)

.EXAMPLE
    .\scan_drive.ps1 -DriveLetter F: -PartitionNumber 1 -OutputPath "C:\scans"
    (Scans F: partition 1, saves output to C:\scans)
#>
[CmdletBinding()]
param (
    [Parameter(Mandatory = $true)]
    [string]$DriveLetter,

    [Parameter(Mandatory = $true)]
    [int]$PartitionNumber,

    [Parameter(Mandatory = $false)]
    [string]$OutputPath
)

# --- Initial Setup and Safeguards ---

# Ensure drive letter format is correct (e.g., "D:")
if ($DriveLetter -notmatch '^[A-Za-z]:$') {
    Write-Error "Invalid DriveLetter format. Please use the format 'D:'."
    exit 1
}

$TargetDriveRoot = (Get-Item -Path $DriveLetter).FullName

# If OutputPath is not specified, use the script's directory. Otherwise, resolve the provided path.
if ([string]::IsNullOrEmpty($OutputPath)) {
    $FinalOutputPath = $PSScriptRoot
} else {
    if (-not (Test-Path -Path $OutputPath -PathType Container)) {
        Write-Error "The specified OutputPath does not exist or is not a directory: $OutputPath"
        exit 1
    }
    $FinalOutputPath = (Resolve-Path -Path $OutputPath).Path
}

# CRITICAL SAFEGUARD: Prevent writing output to the scanned drive.
if ($FinalOutputPath.StartsWith($TargetDriveRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    Write-Error "CRITICAL SAFEGUARD: The OutputPath cannot be on the drive being scanned. Please choose a different location."
    exit 1
}

Write-Host "Output files will be saved to: $FinalOutputPath" -ForegroundColor Green

# --- Prerequisite Tool Check ---
$requiredTools = @("hdparm.exe", "smartctl.exe", "fil.exe", "exiftool.exe", "ffprobe.exe")
$toolsFound = $true
foreach ($tool in $requiredTools) {
    if ((Get-Command $tool -ErrorAction SilentlyContinue) -eq $null) {
        Write-Error "Prerequisite tool not found in PATH: $tool. Please ensure all required tools are installed and accessible."
        $toolsFound = $false
    }
}
if (-not $toolsFound) {
    exit 1
}

# --- Drive Analysis ---
Write-Host "Step 1: Analyzing drive $DriveLetter..."

# Find the physical drive number for the given letter
$driveVolume = Get-WmiObject -Class Win32_Volume | Where-Object { $_.DriveLetter -eq $DriveLetter }
if (-not $driveVolume) {
    Write-Error "Could not find volume information for drive $DriveLetter."
    exit 1
}

$drivePartitions = Get-WmiObject -Class Win32_DiskDriveToDiskPartition | Where-Object { $driveVolume.DeviceID -match [regex]::Escape($_.Dependent.DeviceID) }
$physicalDrive = Get-WmiObject -Class Win32_DiskDrive | Where-Object { $_.DeviceID -eq $drivePartitions.Antecedent.DeviceID }

if (-not $physicalDrive) {
    Write-Error "Could not determine the physical drive for $DriveLetter."
    exit 1
}

$driveDevicePath = $physicalDrive.DeviceID
$driveModel = $physicalDrive.Model
$driveFilesystem = $driveVolume.FileSystem

Write-Host "  > Drive Model: $driveModel"
Write-Host "  > Filesystem: $driveFilesystem"
Write-Host "  > Device Path: $driveDevicePath"

# Get Serial Number from hdparm
Write-Host "  > Querying serial number with hdparm..."
$hdparmOutput = (hdparm -I $driveDevicePath 2>&1)
$driveSerial = ($hdparmOutput | Select-String -Pattern 'Serial Number:' | ForEach-Object { ($_.ToString() -split ':')[1].Trim() }) -join ''

if ([string]::IsNullOrEmpty($driveSerial)) {
    Write-Error "Could not retrieve drive serial number using hdparm. Cannot proceed."
    exit 1
}
# Sanitize serial number for use as a filename
$safeSerial = $driveSerial -replace '[^a-zA-Z0-9_.-]', ''
Write-Host "  > Found Serial Number: $driveSerial (Safe Filename: $safeSerial)"

# Get SMART data from smartctl
Write-Host "  > Querying SMART data with smartctl..."
$smartctlOutput = (smartctl -a $driveDevicePath 2>&1) -join "`n"

# --- .ini File Generation ---
Write-Host "Step 2: Generating .ini file..."

$iniContent = @'
[DriveInfo]
Model={0}
Serial={1}
Filesystem={2}

[SMART]
Data=
--- SMART DATA START ---
{3}
--- SMART DATA END ---
'@ -f $driveModel, $driveSerial, $driveFilesystem, $smartctlOutput

$iniFilePath = Join-Path -Path $FinalOutputPath -ChildPath "$safeSerial.ini"

try {
    $iniContent | Out-File -FilePath $iniFilePath -Encoding utf8 -NoNewline
    Write-Host "  > Successfully created $iniFilePath" -ForegroundColor Green
} catch {
    Write-Error "Failed to write .ini file: $_"
    exit 1
}

# --- CSV File Generation ---
Write-Host "Step 3: Starting file scan and generating .csv file... (This may take a long time)"
$csvFilePath = Join-Path -Path $FinalOutputPath -ChildPath "$safeSerial.csv"

# Define CSV header to match st_files table structure
$csvHeader = @(
    'partition_number', 'path', 'path_hash', 'filename', 'size', 'md5_hash',
    'media_format', 'media_codec', 'media_resolution', 'ctime', 'mtime',
    'file_category', 'is_directory', 'media_duration', 'exif_date_taken',
    'exif_camera_model', 'product_name', 'product_version', 'exiftool_json', 'filetype'
)

# Write header to CSV file
$csvHeader | ConvertTo-Csv -NoTypeInformation | Select-Object -Skip 1 | Out-File -FilePath $csvFilePath -Encoding utf8

# Get all file system objects
$allItems = Get-ChildItem -Path $TargetDriveRoot -Recurse -Force -ErrorAction SilentlyContinue
$totalItems = $allItems.Count
$currentItem = 0

# Start scanning
foreach ($item in $allItems) {
    $currentItem++
    $percentComplete = [math]::Round(($currentItem / $totalItems) * 100, 2)
    $progressMessage = "[{0}/{1} - {2}%] Scanning: {3}" -f $currentItem, $totalItems, $percentComplete, $item.FullName
    Write-Progress -Activity "Scanning Drive" -Status $progressMessage -PercentComplete $percentComplete

    # --- Data Collection for a single item ---
    $relativePath = $item.FullName.Substring($TargetDriveRoot.Length)
    if ($item.PSIsContainer -and !$relativePath.EndsWith('\')) {
        $relativePath += '\'
    }

    $path_hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $item.FullName -ErrorAction SilentlyContinue).Hash
    $md5_hash = if (-not $item.PSIsContainer) { (Get-FileHash -Algorithm MD5 -LiteralPath $item.FullName -ErrorAction SilentlyContinue).Hash } else { $null }
    
    $filetype = (fil $item.FullName 2>&1 | ForEach-Object { ($_.ToString() -split ':', 2)[1].Trim() }) -join ''

    $extension = $item.Extension.TrimStart('.').ToLower()
    $file_category = switch ($extension) {
        {'mp4', 'mkv', 'mov', 'avi', 'wmv', 'flv', 'webm', 'mpg', 'mpeg', 'm4v', 'ts'} { 'Video' }
        {'mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a', 'wma'} { 'Audio' }
        {'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'svg', 'heic', 'raw'} { 'Image' }
        {'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt'} { 'Document' }
        {'zip', 'rar', '7z', 'tar', 'gz'} { 'Archive' }
        {'exe', 'msi', 'bat', 'sh'} { 'Executable' }
        default { 'Other' }
    }
    if ($item.PSIsContainer) { $file_category = 'Directory' }

    # --- Advanced Metadata (Exiftool, FFprobe) ---
    $media_format = $null
    $media_codec = $null
    $media_resolution = $null
    $media_duration = $null
    $exif_date_taken = $null
    $exif_camera_model = $null
    $product_name = $null
    $product_version = $null
    $exiftool_json = $null

    try {
        if ($file_category -in @('Video', 'Audio', 'Image', 'Executable')) {
            $exiftool_json = (exiftool -G -s -json $item.FullName 2>&1) -join "`n"
        }

        if (-not [string]::IsNullOrEmpty($exiftool_json) -and ($exiftool_json.StartsWith('[') -or $exiftool_json.StartsWith('{'))) {
            $exifData = $exiftool_json | ConvertFrom-Json

            # Common fields
            $media_format = $exifData[0].FileType

            # Executable Info
            $product_name = $exifData[0].ProductName
            $product_version = $exifData[0].ProductVersion

            # Image/Video Info
            $exif_camera_model = $exifData[0].Model
            if ($exifData[0].DateTimeOriginal) {
                try { $exif_date_taken = [datetime]::ParseExact($exifData[0].DateTimeOriginal, 'yyyy:MM:dd HH:mm:ss', $null).ToString('yyyy-MM-dd HH:mm:ss') } catch {}
            }
            if ($exifData[0].ImageWidth -and $exifData[0].ImageHeight) {
                $media_resolution = "{0}x{1}" -f $exifData[0].ImageWidth, $exifData[0].ImageHeight
            }
        }

        if ($file_category -in @('Video', 'Audio')) {
            $ffprobeJson = (ffprobe -v quiet -print_format json -show_format -show_streams $item.FullName 2>&1) -join "`n"
            if (-not [string]::IsNullOrEmpty($ffprobeJson) -and $ffprobeJson.StartsWith('{')) {
                $ffprobeData = $ffprobeJson | ConvertFrom-Json
                $stream = $ffprobeData.streams[0]
                $media_codec = $stream.codec_name
                $media_duration = if ($ffprobeData.format.duration) { [math]::Round([double]$ffprobeData.format.duration, 4) } else { $null }
                if ($stream.width -and $stream.height) {
                    $media_resolution = "{0}x{1}" -f $stream.width, $stream.height
                }
            }
        }
    } catch {
        Write-Warning "Could not process metadata for $($item.FullName). Error: $_"
    }

    # --- Assemble CSV Row Object ---
    $csvRowObject = [PSCustomObject]@{ 
        partition_number  = $PartitionNumber
        path              = $relativePath
        path_hash         = $path_hash
        filename          = $item.Name
        size              = if ($item.PSIsContainer) { 0 } else { $item.Length }
        md5_hash          = $md5_hash
        media_format      = $media_format
        media_codec       = $media_codec
        media_resolution  = $media_resolution
        ctime             = $item.CreationTime.ToString('yyyy-MM-dd HH:mm:ss')
        mtime             = $item.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss')
        file_category     = $file_category
        is_directory      = if ($item.PSIsContainer) { 1 } else { 0 }
        media_duration    = $media_duration
        exif_date_taken   = $exif_date_taken
        exif_camera_model = $exif_camera_model
        product_name      = $product_name
        product_version   = $product_version
        exiftool_json     = $exiftool_json
        filetype          = $filetype
    }

    # Append the new row to the CSV file
    $csvRowObject | ConvertTo-Csv -NoTypeInformation | Select-Object -Skip 1 | Out-File -FilePath $csvFilePath -Encoding utf8 -Append
}

Write-Host "`nStep 4: Scan Complete!" -ForegroundColor Green
Write-Host "Output files have been generated:"
Write-Host "  - $iniFilePath"
Write-Host "  - $csvFilePath"