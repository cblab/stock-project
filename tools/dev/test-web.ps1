param(
    [string]$TestPath = ""
)

$ErrorActionPreference = "Stop"

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
$ComposeArgs = @(
    "compose",
    "--env-file", "docker/prod.env",
    "-p", "stock-project-dev",
    "-f", "compose.yaml",
    "-f", "docker/compose.dev.yml"
)

Push-Location $RepoRoot
try {
    Write-Host "Preparing stock_project_test ..."
    docker @ComposeArgs --profile test run --rm test-db-setup
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }

    $PhpUnitTarget = if ([string]::IsNullOrWhiteSpace($TestPath)) { "" } else { " `"$TestPath`"" }
    $Command = "composer install --no-interaction --prefer-dist && APP_ENV=test php bin/phpunit$PhpUnitTarget"

    Write-Host "Running PHPUnit in web-test ..."
    docker @ComposeArgs --profile test run --rm web-test sh -lc $Command
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}
