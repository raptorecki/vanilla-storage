<?php
// --- Helper Functions ---

/**
 * Formats a size in gigabytes into a more readable format (GB or TB).
 */
function formatSize(int $sizeInGB): string
{
    if ($sizeInGB >= 1000) {
        return round($sizeInGB / 1000, 2) . ' TB';
    }
    return $sizeInGB . ' GB';
}

/**
 * Formats a size in bytes into a more readable format (B, KB, MB, GB, TB).
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    if ($bytes <= 0) return '0 B';
    $base = log($bytes, 1024);
    $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

/**
 * Renders a progress bar.
 */
function render_progress_bar(float $percentage_used): string
{
    $percentage_rounded = round($percentage_used, 1);
    $bar_color_class = 'green';
    if ($percentage_used >= 85) {
        $bar_color_class = 'red';
    } elseif ($percentage_used >= 50) {
        $bar_color_class = 'yellow';
    }

    return <<<HTML
<div class="progress-bar-container" title="{$percentage_rounded}% Used">
    <div class="progress-bar {$bar_color_class}" style="width: {$percentage_used}%;"></div>
    <span class="progress-bar-text">{$percentage_rounded}%</span>
</div>
HTML;
}

/**
 * Formats a duration in seconds into a human-readable format.
 */
function formatDuration(int $seconds): string
{
    if ($seconds < 0) {
        return 'N/A';
    }
    if ($seconds == 0) {
        return '0s';
    }

    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;

    $parts = [];
    if ($h > 0) {
        $parts[] = "{$h}h";
    }
    if ($m > 0) {
        $parts[] = "{$m}m";
    }
    if ($s > 0 || empty($parts)) {
        $parts[] = "{$s}s";
    }

    return implode(' ', $parts);
}