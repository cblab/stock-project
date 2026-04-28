param()

$ErrorActionPreference = "Stop"

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
$IndexPath = Join-Path $RepoRoot ".gitnexus"
$MetaPath = Join-Path $IndexPath "meta.json"

Push-Location $RepoRoot
try {
    Write-Host "Repo root: $RepoRoot"
    Write-Host "Index path: $IndexPath"
    Write-Host "Current HEAD: $(git rev-parse --short HEAD)"
    Write-Host "Canonical container path: /work/stock-project"

    try {
        $version = & npx --yes gitnexus --version 2>$null
        Write-Host "GitNexus CLI: $version"
    }
    catch {
        Write-Warning "GitNexus CLI not available via npx."
        return
    }

    if (Test-Path $MetaPath) {
        $meta = Get-Content $MetaPath -Raw | ConvertFrom-Json
        Write-Host "Indexed repoPath: $($meta.repoPath)"
        Write-Host "Indexed commit: $($meta.lastCommit.Substring(0,7))"
        Write-Host "Indexed at: $($meta.indexedAt)"
        Write-Host "Embeddings present: $([int]$meta.stats.embeddings -gt 0)"
    }
    else {
        Write-Warning ".gitnexus/meta.json not found."
    }

    & npx --yes gitnexus status
}
finally {
    Pop-Location
}
