# ============================================================
# VUQA Color Replacement Script
# Run from your project root: C:\laragon\www\vuqa\
# ============================================================

$rootPath = Get-Location
$extensions = @("*.php", "*.css", "*.html", "*.js")

# Old → New color mappings (both upper and lower case)
$colorMap = @{
    # Primary: dark blue → deep purple
    "#0D1A63" = "#2D008A"
    "#0d1a63" = "#2D008A"

    # Secondary: light blue → off-white/cream
    "#9CCFFF" = "#FDFCF9"
    "#9ccfff" = "#FDFCF9"

    # Tertiary: purple → soft purple
    "#685AFF" = "#AC80EE"
    "#685aff" = "#AC80EE"

    # Fourth: pink → green
    "#FFADAD" = "#04B04B"
    "#ffadad" = "#04B04B"

    # Common Bootstrap/inline danger colors → red
    "#dc3545" = "#E41E39"
    "#DC3545" = "#E41E39"
    "#c82333" = "#E41E39"  # Bootstrap danger hover
    "#C82333" = "#E41E39"

    # Any remaining accent/highlight colors → amber
    "#FFC107" = "#FFC12E"  # Bootstrap warning
    "#ffc107" = "#FFC12E"
}

$totalFiles = 0
$totalReplacements = 0

# Get all files recursively, excluding vendor and backup folders
$files = Get-ChildItem -Path $rootPath -Recurse -Include $extensions |
    Where-Object { $_.FullName -notmatch "\\vendor\\" -and $_.FullName -notmatch "\\backup\\" }

foreach ($file in $files) {
    $content = Get-Content $file.FullName -Raw
    $original = $content
    $fileChanged = $false

    foreach ($old in $colorMap.Keys) {
        if ($content -match [regex]::Escape($old)) {
            $content = $content -replace [regex]::Escape($old), $colorMap[$old]
            $fileChanged = $true
        }
    }

    if ($fileChanged) {
        Set-Content -Path $file.FullName -Value $content -NoNewline
        $totalFiles++
        Write-Host "Updated: $($file.FullName)" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "Done! Updated $totalFiles file(s)." -ForegroundColor Cyan