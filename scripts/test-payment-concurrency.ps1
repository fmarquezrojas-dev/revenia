$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$testDatabase = 'facturly_series_testing_' + [Guid]::NewGuid().ToString('N')
if ($testDatabase -notmatch '^facturly_series_testing_[a-f0-9]{32}$') { throw 'Unsafe test database name.' }
$created = $false
Push-Location $projectRoot
try {
    docker compose exec -T db psql -v ON_ERROR_STOP=1 -U facturly -d postgres -c "CREATE DATABASE $testDatabase OWNER facturly_test;"
    if ($LASTEXITCODE -ne 0) { throw 'Could not create dedicated test database.' }
    $created = $true
    $testOutput = docker compose exec -T -e APP_ENV=testing -e "APP_CONFIG_CACHE=/tmp/$testDatabase-config.php" -e DB_CONNECTION=pgsql -e DB_URL= -e DB_HOST=db -e DB_PORT=5432 -e "DB_DATABASE=$testDatabase" -e DB_USERNAME=facturly_test -e DB_PASSWORD=facturly_test_local -e "DOCUMENT_STORAGE_ROOT=/app/storage/framework/testing/$testDatabase/files" -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array -e BCRYPT_ROUNDS=4 app php tests/Concurrency/payments.php
    $testExitCode = $LASTEXITCODE
    $testOutput | Write-Output
    if ($testExitCode -ne 0 -or ($testOutput -join "`n") -notmatch '"result": "PASS"') { throw 'Concurrent payment test failed or did not confirm PASS.' }
}
finally {
    try {
        if ($created) {
            # This unique database belongs solely to this run; never clean facturly_testing or facturly.
            docker compose exec -T db psql -v ON_ERROR_STOP=1 -U facturly -d postgres -c "DROP DATABASE $testDatabase WITH (FORCE);"
            if ($LASTEXITCODE -ne 0) { throw "Could not remove dedicated test database $testDatabase." }
        }
    }
    finally {
        $artifactRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot 'backend/storage/framework/testing'))
        $artifactPath = [IO.Path]::GetFullPath((Join-Path $artifactRoot $testDatabase))
        if ($artifactPath.StartsWith($artifactRoot + [IO.Path]::DirectorySeparatorChar) -and $testDatabase -match '^facturly_series_testing_[a-f0-9]{32}$' -and (Test-Path -LiteralPath $artifactPath)) { Remove-Item -LiteralPath ('\\?\' + $artifactPath) -Recurse -Force }
        Pop-Location
    }
}
