param(
    [switch]$Embeddings,
    [switch]$Force
)

$ErrorActionPreference = "Stop"

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
$MetaPath = Join-Path $RepoRoot ".gitnexus\meta.json"

Push-Location $RepoRoot
try {
    $Args = @("--yes", "gitnexus", "analyze", ".", "--skip-agents-md")
    if ($Force) {
        $Args += "--force"
    }
    if ($Embeddings) {
        $Args += "--embeddings"
    }

    if (Test-Path $MetaPath) {
        $meta = Get-Content $MetaPath -Raw | ConvertFrom-Json
        if ([int]$meta.stats.embeddings -gt 0 -and -not $Embeddings) {
            Write-Host "Existing embeddings detected."
            Write-Host "gitnexus analyze without --embeddings preserves existing embeddings."
            Write-Host "Use -Embeddings only when you want to rebuild embeddings too."
        }
    }

    Write-Host "Index owner: host"
    Write-Host "Container reader path: /work/stock-project/.gitnexus"

    if ($IsWindows) {
        $NpmArgs = @("exec", "--yes", "--", "gitnexus", "analyze", ".", "--skip-agents-md")
        if ($Force) {
            $NpmArgs += "--force"
        }
        if ($Embeddings) {
            $NpmArgs += "--embeddings"
        }
        & npm @NpmArgs
    }
    else {
        & npx @Args
    }

    if (Test-Path $MetaPath) {
        $meta = Get-Content $MetaPath -Raw | ConvertFrom-Json
        Write-Host "Indexed commit: $($meta.lastCommit.Substring(0,7))"
        Write-Host "Indexed at: $($meta.indexedAt)"
        Write-Host "Embeddings present: $([int]$meta.stats.embeddings -gt 0)"
    }

    if ($IsWindows) {
        & npm exec --yes -- gitnexus status
    }
    else {
        & npx --yes gitnexus status
    }
}
finally {
    Pop-Location
}
