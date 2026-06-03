$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$phpFiles = Get-ChildItem -Path $root -Recurse -Filter '*.php' -File |
  Where-Object {
    $_.FullName -notmatch '\\vendor\\' -and
    $_.FullName -notmatch '\\.git\\'
  }

if (-not $phpFiles) {
  Write-Host 'No PHP files found.'
  exit 0
}

$failed = $false

foreach ($file in $phpFiles) {
  Write-Host "Linting $($file.FullName)"
  & php -l $file.FullName
  if ($LASTEXITCODE -ne 0) {
    $failed = $true
  }
}

if ($failed) {
  Write-Error 'PHP lint failures detected.'
  exit 1
}

Write-Host 'PHP lint passed.'
