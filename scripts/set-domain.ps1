param(
    [Parameter(Mandatory=$true)]
    [string]$Domain
)
# Replaces the starter placeholder and current FreightKit host in canonical URLs, social tags,
# structured data, sitemap.xml and robots.txt. Subfolder sites are supported.
$ErrorActionPreference = 'Stop'
$Domain = $Domain.Trim().TrimEnd('/')
if ($Domain -notmatch '^https://[a-zA-Z0-9.-]+(/[a-zA-Z0-9._~-]+)*$') {
    throw 'Use the final HTTPS address only, for example https://freightkit.com or https://username.github.io/freightkit (no trailing slash).'
}
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$extensions = @('.html', '.xml', '.txt')
$files = Get-ChildItem -Path $root -Recurse -File | Where-Object {
    $extensions -contains $_.Extension.ToLower() -and $_.FullName -notmatch '[\\/]\.git[\\/]'
}
$oldDomains = @('https://YOUR-DOMAIN.com', 'https://freightkit.in')
$count = 0
foreach ($file in $files) {
    $content = [System.IO.File]::ReadAllText($file.FullName)
    $updated = $content
    foreach ($oldDomain in $oldDomains) {
        $updated = $updated.Replace($oldDomain, $Domain)
    }
    # The 404 page can be served at any URL depth, so its links must be absolute.
    if ($file.Name -eq '404.html' -and $file.DirectoryName -eq $root) {
        $updated = [regex]::Replace($updated, '\b(href|src)="/(?!/)', ('$1="' + $Domain + '/'))
    }
    if ($updated -ne $content) {
        [System.IO.File]::WriteAllText($file.FullName, $updated, [System.Text.UTF8Encoding]::new($false))
        $count++
    }
}
Write-Host "Updated domain references in $count file(s) to $Domain"
if ($Domain -match '^https://[^/]+/.+') {
    Write-Host 'Note: this is a subfolder site. Search engines only read robots.txt at the domain root, so submit sitemap.xml directly in Google Search Console.'
}
Write-Host 'Review the changes with: git diff'
