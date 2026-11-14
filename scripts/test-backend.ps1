param(
    [string]$Filter = ''
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot

Push-Location $projectRoot

try {
    $dockerArgs = @(
        'compose'
        'exec'
        '-T'
        '-e'
        'APP_ENV=testing'
        '-e'
        'APP_CONFIG_CACHE=/tmp/facturly-phpunit-config.php'
        '-e'
        'DB_CONNECTION=pgsql'
        '-e'
        'DB_URL='
        '-e'
        'DB_HOST=db'
        '-e'
        'DB_PORT=5432'
        '-e'
        'DB_DATABASE=facturly_testing'
        '-e'
        'DB_USERNAME=facturly_test'
        '-e'
        'DB_PASSWORD=facturly_test_local'
        '-e'
        'CACHE_STORE=array'
        '-e'
        'SESSION_DRIVER=array'
        '-e'
        'QUEUE_CONNECTION=sync'
        '-e'
        'MAIL_MAILER=array'
        '-e'
        'BCRYPT_ROUNDS=4'
        '-e'
        'SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000'
        'app'
        'php'
        'vendor/bin/phpunit'
    )

    if ($Filter) {
        $dockerArgs += @('--filter', $Filter)
    }

    docker @dockerArgs

    if ($LASTEXITCODE -ne 0) {
        throw 'La ejecución de pruebas terminó con errores.'
    }
}
finally {
    Pop-Location
}