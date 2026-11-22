#requires -Version 5.1
[CmdletBinding()]
param([ValidateSet('Serve', 'Authorize', 'Revoke')][string]$Action = 'Serve')

$ErrorActionPreference = 'Stop'
$root = (Get-Item -LiteralPath (Join-Path $PSScriptRoot '..\..')).FullName
$sha = [Security.Cryptography.SHA256]::Create()
function Get-Digest([string]$Value) {
    $bytes = [Text.Encoding]::UTF8.GetBytes($Value)
    return [BitConverter]::ToString($sha.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant()
}

# DPAPI binds the encrypted credential to this Windows account. Separate worktrees have separate files.
$profilePath = Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'Revenia\Codex'
$credentialPath = Join-Path $profilePath ((Get-Digest $root.ToLowerInvariant()) + '.dpapi')
$composeArgs = @('compose', '--project-directory', $root, '-f', (Join-Path $root 'compose.yaml'), '-f', (Join-Path $root 'compose.override.yaml'), 'run', '--rm', '--no-deps', '--pull', 'never')
$dockerPath = (Get-Command docker.exe -CommandType Application | Select-Object -First 1).Source
$endpoint = if ($env:DOCKER_HOST -and -not $env:DOCKER_CONTEXT) {
    $env:DOCKER_HOST
} else {
    & $dockerPath context inspect --format '{{.Endpoints.docker.Host}}'
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo comprobar el contexto local de Docker.' }
}
if ($endpoint -notmatch '^npipe:////\./pipe/[^/\\]+$') {
    throw 'Seleccione el contexto local de Docker Desktop de Windows antes de conectar Revenia.'
}

if ($Action -eq 'Serve') {
    # Inherit the native handles. PowerShell 5.1 pipelines would re-encode JSON-RPC.
    $start = New-Object Diagnostics.ProcessStartInfo
    $start.FileName = $dockerPath
    $start.WorkingDirectory = $root
    $start.UseShellExecute = $false
    $start.Arguments = 'compose -f compose.yaml -f compose.override.yaml run --rm --no-deps -T --pull never -e REVENIA_MCP_TOKEN -e APP_DEBUG=false app php artisan mcp:start revenia'
    $start.EnvironmentVariables.Remove('REVENIA_MCP_TOKEN')
    if (Test-Path -LiteralPath $credentialPath -PathType Leaf) {
        $protected = ConvertTo-SecureString ([IO.File]::ReadAllText($credentialPath))
        try {
            $start.EnvironmentVariables['REVENIA_MCP_TOKEN'] = [Net.NetworkCredential]::new('', $protected).Password
        } finally {
            $protected.Dispose()
        }
    }
    $proc = [Diagnostics.Process]::Start($start)
    $proc.WaitForExit()
    exit $proc.ExitCode
}

if ($Action -eq 'Revoke') {
    & $dockerPath @composeArgs app php artisan revenia:mcp-access revoke
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    if (Test-Path -LiteralPath $credentialPath -PathType Leaf) {
        Remove-Item -LiteralPath $credentialPath
    }
    exit 0
}

$random = [Security.Cryptography.RandomNumberGenerator]::Create()
try {
    $bytes = New-Object 'byte[]' 32
    $random.GetBytes($bytes)
    $secret = 'rv_mcp_' + [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
} finally {
    $random.Dispose()
}
$protected = ConvertTo-SecureString $secret -AsPlainText -Force
try {
    $encrypted = ConvertFrom-SecureString $protected
    [void][IO.Directory]::CreateDirectory($profilePath)
    $tempPath = Join-Path $profilePath ([Guid]::NewGuid().ToString('N') + '.tmp')
    [IO.File]::WriteAllText($tempPath, $encrypted)
    & $dockerPath @composeArgs app php artisan revenia:mcp-access grant (Get-Digest $secret)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    if ([IO.File]::Exists($credentialPath)) {
        [IO.File]::Replace($tempPath, $credentialPath, [System.Management.Automation.Language.NullString]::Value)
    } else {
        [IO.File]::Move($tempPath, $credentialPath)
    }
} finally {
    $protected.Dispose()
    if ($tempPath -and [IO.File]::Exists($tempPath)) { [IO.File]::Delete($tempPath) }
}
