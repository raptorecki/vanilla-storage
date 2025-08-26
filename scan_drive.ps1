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
    The letter of the drive to scan (e.g., "D:"). Alias: DL

.PARAMETER PartitionNumber
    The partition number being scanned on the physical drive. Alias: PN

.PARAMETER OutputPath
    Optional. The directory where the output files will be saved. 
    Defaults to the script's current directory.

.PARAMETER Help
    Displays this help message. Alias: H

.EXAMPLE
    .\scan_drive.ps1 -DriveLetter E: -PartitionNumber 1
    (Scans E: partition 1, saves output to the current directory)

.EXAMPLE
    .\scan_drive.ps1 -DL F: -PN 1 -OutputPath "C:\scans"
    (Scans F: partition 1 using aliases, saves output to C:\scans)
#>
[CmdletBinding()]
param (
    [Parameter(Mandatory = $true)]
    [Alias('DL')]
    [string]$DriveLetter,

    [Parameter(Mandatory = $true)]
    [Alias('PN')]
    [int]$PartitionNumber,

    [Parameter(Mandatory = $false)]
    [string]$OutputPath,

    [Parameter(Mandatory = $false)]
    [Alias('H')]
    [switch]$Help
)

function Show-Help {
    Write-Host "
    Vanilla Storage Drive Scanner

    This script scans a drive partition and creates a .csv file with file metadata
    and a .ini file with drive hardware information, suitable for import into the
    Vanilla Storage application.

    USAGE:
        .\scan_drive.ps1 -DriveLetter <DRIVE> -PartitionNumber <PART_NUM> [-OutputPath <PATH>]

    PARAMETERS:
        -DriveLetter, -DL      (Required) The letter of the drive to scan (e.g., 'F:').
        -PartitionNumber, -PN  (Required) The integer number of the partition to scan.
        -OutputPath            (Optional) The path to save the output files.
                               Defaults to the script's directory.
        -Help, -H              (Optional) Displays this help message.

    EXAMPLES:
        .\scan_drive.ps1 -DriveLetter E: -PartitionNumber 1
        .\scan_drive.ps1 -DL G: -PN 1 -OutputPath C:\scans
    "
}

if ($Help -or ($PSBoundParameters.Count -eq 0)) {
    Show-Help
    exit 0
}

# --- Initial Setup and Safeguards ---

# Administrator check
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error "This script requires Administrator privileges to query hardware information. Please re-run as Administrator."
    exit 1
}

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

# --- Drive Analysis (Robust Method) ---
Write-Host "Step 1: Analyzing drive $DriveLetter..."

try {
    $partition = Get-Partition -DriveLetter $DriveLetter.Trim(':') -ErrorAction Stop
    $physicalDisk = $partition | Get-Disk -ErrorAction Stop
    $volume = $partition | Get-Volume -ErrorAction Stop
} catch {
    Write-Error "Could not determine the physical disk for drive $DriveLetter. Please ensure it is a basic physical disk. Error: $_"
    exit 1
}

$driveModel = $physicalDisk.Model
$driveFilesystem = $volume.FileSystem
$driveDevicePathForHDParm = "\\.\PhysicalDrive" + $physicalDisk.Number

Write-Host "  > Drive Model: $driveModel"
Write-Host "  > Filesystem: $driveFilesystem"
Write-Host "  > Device Path: $driveDevicePathForHDParm"

# Get Serial Number from hdparm
Write-Host "  > Querying serial number with hdparm..."
$hdparmOutput = (hdparm -I $driveDevicePathForHDParm)
$driveSerial = ($hdparmOutput | Select-String -Pattern 'Serial Number:' | ForEach-Object { ($_.ToString() -split ':')[1].Trim() }) -join ''

if ([string]::IsNullOrEmpty($driveSerial)) {
    # Fallback for NVMe drives or where hdparm fails
    Write-Warning "hdparm failed to get serial number. Trying fallback method for NVMe/SCSI..."
    $driveSerial = $physicalDisk.SerialNumber.Trim()
}

if ([string]::IsNullOrEmpty($driveSerial)) {
    Write-Error "Could not retrieve drive serial number. Cannot proceed."
    exit 1
}

# Sanitize serial number for use as a filename
$safeSerial = $driveSerial -replace '[^a-zA-Z0-9_.-]', ''
Write-Host "  > Found Serial Number: $driveSerial (Safe Filename: $safeSerial)"

# Get SMART data from smartctl
Write-Host "  > Querying SMART data with smartctl..."
# Note: smartctl can often use the drive letter directly
$smartctlOutput = (smartctl -a $DriveLetter) -join "`n"

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

# Correctly write the header to the CSV file
'"' + ($csvHeader -join '","' ) + '"' | Out-File -FilePath $csvFilePath -Encoding utf8

# Get all file system objects
$allItems = Get-ChildItem -Path $TargetDriveRoot -Recurse -Force -ErrorAction SilentlyContinue
$totalItems = $allItems.Count
$currentItem = 0

# Start scanning
foreach ($item in $allItems) {
    $currentItem++
    $percentComplete = if ($totalItems -gt 0) { [math]::Round(($currentItem / $totalItems) * 100, 2) } else { 0 }
    $progressMessage = "[{0}/{1} - {2}%] Scanning: {3}" -f $currentItem, $totalItems, $percentComplete, $item.FullName
    Write-Progress -Activity "Scanning Drive" -Status $progressMessage -PercentComplete $percentComplete

    # --- Data Collection for a single item ---
    $relativePath = $item.FullName.Substring($TargetDriveRoot.Length)
    if ($item.PSIsContainer -and !$relativePath.EndsWith('\')) {
        $relativePath += '\'
    }

    # Correctly generate path_hash for both files and directories
    if ($item.PSIsContainer) {
        $utf8bytes = [System.Text.Encoding]::UTF8.GetBytes($relativePath)
        $hashAlgorithm = [System.Security.Cryptography.SHA256]::Create()
        $path_hash_bytes = $hashAlgorithm.ComputeHash($utf8bytes)
        $path_hash = [System.BitConverter]::ToString($path_hash_bytes).Replace('-', '')
    } else {
        $path_hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $item.FullName -ErrorAction SilentlyContinue).Hash
    }

    $md5_hash = if (-not $item.PSIsContainer) { (Get-FileHash -Algorithm MD5 -LiteralPath $item.FullName -ErrorAction SilentlyContinue).Hash } else { $null }
    
    $filetype = $null
    try {
        $filetypeRaw = (fil $item.FullName) -join ' '
        # The output is in the format 'C:\path\file.txt: description'
        # We remove the known full path and the colon to isolate the description.
        $filetype = $filetypeRaw.Replace($item.FullName + ':', '').Trim()
        if ([string]::IsNullOrWhiteSpace($filetype)) {
            $filetype = $null
        }
    } catch {
        Write-Warning "Could not determine filetype for $($item.FullName)"
    }

    $extension = $item.Extension.TrimStart('.').ToLower()
    # Robustly determine file category using an if/elseif block
    $file_category = 'Other' # Default value
    if ($item.PSIsContainer) {
        $file_category = 'Directory'
    } elseif (@('mp4', 'mkv', 'mov', 'avi', 'wmv', 'flv', 'webm', 'mpg', 'mpeg', 'm4v', 'ts') -contains $extension) {
        $file_category = 'Video'
    } elseif (@('mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a', 'wma') -contains $extension) {
        $file_category = 'Audio'
    } elseif (@('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'svg', 'heic', 'raw') -contains $extension) {
        $file_category = 'Image'
    } elseif (@('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt') -contains $extension) {
        $file_category = 'Document'
    } elseif (@('zip', 'rar', '7z', 'tar', 'gz') -contains $extension) {
        $file_category = 'Archive'
    } elseif (@('exe', 'msi', 'bat', 'sh') -contains $extension) {
        $file_category = 'Executable'
    }

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
            $exiftool_json = (exiftool -G -s -json $item.FullName) -join "`n"
        }

        if (-not [string]::IsNullOrEmpty($exiftool_json) -and ($exiftool_json.StartsWith('[') -or $exiftool_json.StartsWith('{'))) {
            $exifData = $exiftool_json | ConvertFrom-Json

            # Check if $exifData is not null and not empty before accessing elements
            if ($exifData -and $exifData.Count -gt 0) {
                # Common fields
                $media_format = $exifData[0].FileType

                # Executable Info
                $product_name = $exifData[0].ProductName
                $product_version = $exifData[0].ProductName

                # Image/Video Info
                $exif_camera_model = $exifData[0].Model
                if ($exifData[0].DateTimeOriginal) {
                    try { $exif_date_taken = [datetime]::ParseExact($exifData[0].DateTimeOriginal, 'yyyy:MM:dd HH:mm:ss', $null).ToString('yyyy-MM-dd HH:mm:ss') } catch {}
                }
                if ($exifData[0].ImageWidth -and $exifData[0].ImageHeight) {
                    $media_resolution = "{0}x{1}" -f $exifData[0].ImageWidth, $exifData[0].ImageHeight
                }
            }
        }

        if ($file_category -in @('Video', 'Audio')) {
            $ffprobeJson = (ffprobe -v quiet -print_format json -show_format -show_streams $item.FullName) -join "`n"
            if (-not [string]::IsNullOrEmpty($ffprobeJson) -and $ffprobeJson.StartsWith('{')) {
                $ffprobeData = $ffprobeJson | ConvertFrom-Json
                # Check if $ffprobeData.streams is not null and not empty before accessing elements
                if ($ffprobeData -and $ffprobeData.streams -and $ffprobeData.streams.Count -gt 0) {
                    $stream = $ffprobeData.streams[0]
                    $media_codec = $stream.codec_name
                    $media_duration = if ($ffprobeData.format.duration) { [math]::Round([double]$ffprobeData.format.duration, 4) } else { $null }
                    if ($stream.width -and $stream.height) {
                        $media_resolution = "{0}x{1}" -f $stream.width, $stream.height
                    }
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
        path_hash         = [string]$path_hash
        filename          = $item.Name
        size              = if ($item.PSIsContainer) { 0 } else { $item.Length }
        md5_hash          = [string](if (-not $item.PSIsContainer) { (Get-FileHash -Algorithm MD5 -LiteralPath $item.FullName -ErrorAction SilentlyContinue).Hash } else { $null })
        media_format      = [string]$media_format
        media_codec       = [string]$media_codec
        media_resolution  = [string]$media_resolution
        ctime             = $item.CreationTime.ToString('yyyy-MM-dd HH:mm:ss')
        mtime             = $item.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss')
        file_category     = [string]$file_category
        is_directory      = if ($item.PSIsContainer) { 1 } else { 0 }
        media_duration    = [string]$media_duration
        exif_date_taken   = [string]$exif_date_taken
        exif_camera_model = [string]$exif_camera_model
        product_name      = [string]$product_name
        product_version   = [string]$product_version
        exiftool_json     = [string]$exiftool_json
        filetype          = [string]$filetype
    }

    # Append the new row to the CSV file
    $csvRowObject | ConvertTo-Csv -NoTypeInformation | Select-Object -Skip 1 | Out-File -FilePath $csvFilePath -Encoding utf8 -Append
}

Write-Host "`nStep 4: Scan Complete!" -ForegroundColor Green
Write-Host "Output files have been generated:"
Write-Host "  - $iniFilePath"
Write-Host "  - $csvFilePath"