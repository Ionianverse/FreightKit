param(
    [Parameter(Mandatory=$true)]
    [string]$Domain
)
$ErrorActionPreference = 'Stop'
$Domain = $Domain.Trim().TrimEnd('/')
if ($Domain -notmatch '^https://[a-zA-Z0-9.-]+$') {
    throw 'Use the final HTTPS domain only, for example https://freightkit.com (no path or trailing slash).'
}
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$extensions = @('.html', '.xml', '.txt')
$files = Get-ChildItem -Path $root -Recurse -File | Where-Object {
    $extensions -contains $_.Extension.ToLower() -and $_.FullName -notmatch '[\\/]\.git[\\/]'
}
$old = 'https://YOUR-DOMAIN.com'
$count = 0
foreach ($file in $files) {
    $content = [System.IO.File]::ReadAllText($file.FullName)
    if ($content.Contains($old)) {
        $content = $content.Replace($old, $Domain)
        [System.IO.File]::WriteAllText($file.FullName, $content, [System.Text.UTF8Encoding]::new($false))
        $count++
    }
}
Write-Host "Updated domain references in $count file(s) to $Domain"
Write-Host 'Review the changes with: git diff'
