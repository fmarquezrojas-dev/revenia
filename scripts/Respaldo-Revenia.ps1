#requires -Version 5.1
[CmdletBinding()]
param(
    [ValidateSet('Backup','Restore','Verify','Migrate','Remove')][string]$Action = 'Backup',
    [string]$Destination = (Join-Path $env:LOCALAPPDATA 'Revenia\Respaldos'),
    [string]$RecoveryRoot = (Join-Path $env:LOCALAPPDATA 'Revenia\Recuperaciones'),
    [string]$Package,
    [string]$Instance,
    [ValidatePattern('^[a-fA-F0-9]{64}$')][string]$ExpectedManifestHash,
    [ValidatePattern('^[a-z0-9][a-z0-9_-]*$')][string]$SourceProject,
    [ValidateRange(1024,65535)][int]$Port = 18080,
    [string]$PhpImage,
    [string]$PostgresImage
)
. (Join-Path $PSScriptRoot 'recovery/common.ps1')
. (Join-Path $PSScriptRoot 'recovery/backup.ps1')
. (Join-Path $PSScriptRoot 'recovery/restore.ps1')
switch ($Action) {
    Backup { Invoke-ExclusiveOperation ('origen:'+$Repo) { Backup-Revenia } }
    Restore { Restore-Revenia }
    default { Invoke-ExclusiveOperation (ExternalPath $Instance) { Manage-Recovery $Action } }
}

