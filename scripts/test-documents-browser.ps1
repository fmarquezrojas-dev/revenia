$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runId = [Guid]::NewGuid().ToString('N')
$testDatabase = 'facturly_series_testing_' + $runId
$apiContainer = 'facturly-drafts-api-' + $runId
$uiContainer = 'facturly-drafts-ui-' + $runId
if ($testDatabase -notmatch '^facturly_series_testing_[a-f0-9]{32}$') { throw 'Unsafe test database name.' }
$created = $false
Push-Location $projectRoot
try {
 docker compose exec -T db psql -v ON_ERROR_STOP=1 -U facturly -d postgres -c "CREATE DATABASE $testDatabase OWNER facturly_test;"
 if ($LASTEXITCODE -ne 0) { throw 'Could not create test database.' }; $created = $true
 $envArgs = @('-e','APP_ENV=testing','-e',"APP_CONFIG_CACHE=/tmp/$testDatabase-config.php",'-e','DB_CONNECTION=pgsql','-e','DB_URL=','-e','DB_HOST=db','-e','DB_PORT=5432','-e',"DB_DATABASE=$testDatabase",'-e','DB_USERNAME=facturly_test','-e','DB_PASSWORD=facturly_test_local','-e','CACHE_STORE=array','-e','SESSION_DRIVER=database','-e','SESSION_COOKIE=facturly_drafts_test_session','-e','QUEUE_CONNECTION=sync','-e','MAIL_MAILER=array','-e','BCRYPT_ROUNDS=4','-e','APP_URL=http://localhost:5174','-e','SANCTUM_STATEFUL_DOMAINS=localhost:5174')
 docker compose exec -T @envArgs app php tests/Concurrency/documents-browser-fixtures.php
 if ($LASTEXITCODE -ne 0) { throw 'Fixture setup failed.' }
 docker compose run -d --no-deps --name $apiContainer @envArgs app php artisan serve --host=0.0.0.0 --port=8000 --no-reload
 if ($LASTEXITCODE -ne 0) { throw 'Test API start failed.' }
 docker compose run -d --no-deps --name $uiContainer -p 127.0.0.1:5174:5174 -e "FACTURLY_API_TARGET=http://${apiContainer}:8000" frontend npm run dev -- --host 0.0.0.0 --port 5174 --strictPort
 if ($LASTEXITCODE -ne 0) { throw 'Test UI start failed.' }
 node scripts/test-documents-ui.cjs
 if ($LASTEXITCODE -ne 0) { throw 'Real API browser test failed.' }
} finally {
 docker rm -f $uiContainer $apiContainer 2>$null | Out-Null
 if ($created) {
  docker compose exec -T db psql -v ON_ERROR_STOP=1 -U facturly -d postgres -c "DROP DATABASE $testDatabase WITH (FORCE);"
  if ($LASTEXITCODE -ne 0) { throw "Could not remove test database $testDatabase" }
 }
 Pop-Location
}
