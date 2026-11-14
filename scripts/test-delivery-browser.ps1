$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runId = [Guid]::NewGuid().ToString('N')
$testDatabase = 'facturly_series_testing_' + $runId
$apiContainer = 'facturly-delivery-api-' + $runId
$uiContainer = 'facturly-delivery-ui-' + $runId
if ($testDatabase -notmatch '^facturly_series_testing_[a-f0-9]{32}$') { throw 'Unsafe test database name.' }
$created = $false
Push-Location $projectRoot
try {
 docker compose exec -T db psql -v ON_ERROR_STOP=1 -U facturly -d postgres -c "CREATE DATABASE $testDatabase OWNER facturly_test;"
 if ($LASTEXITCODE -ne 0) { throw 'Could not create test database.' }; $created = $true
 $envArgs = @('-e','APP_ENV=testing','-e',"APP_CONFIG_CACHE=/tmp/$testDatabase-config.php",'-e','DB_CONNECTION=pgsql','-e','DB_URL=','-e','DB_HOST=db','-e','DB_PORT=5432','-e',"DB_DATABASE=$testDatabase",'-e','DB_USERNAME=facturly_test','-e','DB_PASSWORD=facturly_test_local','-e','CACHE_STORE=array','-e','SESSION_DRIVER=database','-e','SESSION_COOKIE=facturly_drafts_test_session','-e','QUEUE_CONNECTION=database','-e','EMISSION_QUEUE_CONNECTION=database','-e','DELIVERY_QUEUE_CONNECTION=database','-e',"DOCUMENT_STORAGE_ROOT=/app/storage/framework/testing/$testDatabase/files",'-e','SIMULATION_DEMO_CONTROLS=true','-e','MAIL_MAILER=smtp','-e','BCRYPT_ROUNDS=4','-e','APP_URL=http://localhost:5174','-e','SANCTUM_STATEFUL_DOMAINS=localhost:5174')
 $fixtureOutput = docker compose exec -T @envArgs app php tests/Concurrency/delivery-browser-fixtures.php
 $fixtureExitCode = $LASTEXITCODE
 $fixtureOutput | Write-Output
 if ($fixtureExitCode -ne 0 -or ($fixtureOutput -join "`n") -notmatch 'DELIVERY_FIXTURES_READY') { throw 'Fixture setup failed.' }
 New-Item -ItemType Directory -Force -Path docs/examples/pdf | Out-Null
 docker compose cp "app:/app/storage/framework/testing/$testDatabase/samples/." docs/examples/pdf
 if ($LASTEXITCODE -ne 0) { throw 'Could not copy synthetic PDF examples.' }
 docker compose run -d --no-deps --name $apiContainer @envArgs app php artisan serve --host=0.0.0.0 --port=8000 --no-reload
 if ($LASTEXITCODE -ne 0) { throw 'Test API start failed.' }
 docker compose run -d --no-deps --name $uiContainer -p 127.0.0.1:5174:5174 -e "FACTURLY_API_TARGET=http://${apiContainer}:8000" frontend npm run dev -- --host 0.0.0.0 --port 5174 --strictPort
 if ($LASTEXITCODE -ne 0) { throw 'Test UI start failed.' }
 $previousTestApi = $env:FACTURLY_TEST_API
 $env:FACTURLY_TEST_API = $apiContainer
 node scripts/test-delivery-ui.cjs
 if ($LASTEXITCODE -ne 0) { throw 'Real API browser test failed.' }
} finally {
 $env:FACTURLY_TEST_API = $previousTestApi
 docker rm -f $uiContainer $apiContainer 2>$null | Out-Null
 if ($created) {
  docker compose exec -T db psql -v ON_ERROR_STOP=1 -U facturly -d postgres -c "DROP DATABASE $testDatabase WITH (FORCE);"
  if ($LASTEXITCODE -ne 0) { throw "Could not remove test database $testDatabase" }
 }
 $testArtifactRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot 'backend/storage/framework/testing'))
 $testArtifactPath = [IO.Path]::GetFullPath((Join-Path $testArtifactRoot $testDatabase))
 if ($testArtifactPath.StartsWith($testArtifactRoot + [IO.Path]::DirectorySeparatorChar) -and $testDatabase -match '^facturly_series_testing_[a-f0-9]{32}$' -and (Test-Path -LiteralPath $testArtifactPath)) { Remove-Item -LiteralPath ('\\?\' + $testArtifactPath) -Recurse -Force }
 Pop-Location
}
