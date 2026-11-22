#requires -Version 5.1
Set-StrictMode -Version 3
$ErrorActionPreference = 'Stop'
$Utf8 = New-Object Text.UTF8Encoding($false)
$Tools = $PSScriptRoot
$Repo = [IO.Path]::GetFullPath((Join-Path $Tools '../..'))
$SourceArgs = @('compose','--project-directory',$Repo,'-f',(Join-Path $Repo 'compose.yaml'),'-f',(Join-Path $Repo 'compose.override.yaml'))
if ($SourceProject) { $SourceArgs += @('-p',$SourceProject) }

# Windows CreateProcess argv quoting. No shell interpolation or text decoding of binary streams.
function WinArg([string]$Value) {
    '"' + (($Value -replace '(\\*)"', '$1$1\"') -replace '(\\+)$', '$1$1') + '"'
}
function RunNative {
    param([string]$Exe, [string[]]$Arguments, [string]$Step, [string]$InFile, [string]$OutFile, [hashtable]$Vars = @{})
    $si = New-Object Diagnostics.ProcessStartInfo
    $si.FileName = $Exe
    $si.Arguments = (($Arguments | ForEach-Object { WinArg $_ }) -join ' ')
    $si.WorkingDirectory = $Repo
    $si.UseShellExecute = $false
    $si.CreateNoWindow = $true
    $si.RedirectStandardInput = $true
    $si.RedirectStandardOutput = $true
    $si.RedirectStandardError = $true
    $si.StandardOutputEncoding = $Utf8
    foreach ($key in $Vars.Keys) { $si.EnvironmentVariables[$key] = [string]$Vars[$key] }
    $proc = New-Object Diagnostics.Process
    $proc.StartInfo = $si
    $targetStream = $null
    try {
        if ($OutFile) { $targetStream = [IO.File]::Open($OutFile,'CreateNew','Write','None') }
        [void]$proc.Start()
        $errorsTask = $proc.StandardError.ReadToEndAsync()
        if ($OutFile) { $readTask = $proc.StandardOutput.BaseStream.CopyToAsync($targetStream) }
        else { $readTask = $proc.StandardOutput.ReadToEndAsync() }
        if ($InFile) {
            $srcStream = [IO.File]::OpenRead($InFile)
            try { $srcStream.CopyTo($proc.StandardInput.BaseStream) } finally { $srcStream.Dispose() }
        }
        $proc.StandardInput.BaseStream.Close()
        $proc.WaitForExit()
        [void]$errorsTask.GetAwaiter().GetResult()
        if ($proc.ExitCode -ne 0) { throw "Fallo en $Step (codigo $($proc.ExitCode))." }
        if ($OutFile) { [void]$readTask.GetAwaiter().GetResult() }
        else { return $readTask.GetAwaiter().GetResult().TrimEnd() }
    } finally {
        if ($targetStream) { $targetStream.Dispose() }
        $proc.Dispose()
    }
}
function Dkr([string[]]$A, [string]$Step) { RunNative docker $A $Step }
function GitText([string[]]$A) { RunNative git $A 'identificar la revision del repositorio' }
function SaveJson([string]$Path, $Data) {
    $json = ConvertTo-Json -InputObject $Data -Depth 18
    $tmp = $Path + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
    [IO.File]::WriteAllText($tmp,$json,$Utf8)
    if ([IO.File]::Exists($Path)) { [IO.File]::Replace($tmp,$Path,[System.Management.Automation.Language.NullString]::Value) } else { [IO.File]::Move($tmp,$Path) }
}
function ReadJson([string]$Path) { [IO.File]::ReadAllText($Path,$Utf8) | ConvertFrom-Json }
function Digest([string]$Path) { (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant() }
function Utc { [DateTime]::UtcNow.ToString('o') }
function Invoke-ExclusiveOperation([string]$Scope,[scriptblock]$Work) {
    $hasher = [Security.Cryptography.SHA256]::Create()
    try { $key = [BitConverter]::ToString($hasher.ComputeHash($Utf8.GetBytes($Scope.ToUpperInvariant()))).Replace('-','') }
    finally { $hasher.Dispose() }
    $mutex = [Threading.Mutex]::new($false,('Global\Revenia-Recovery-'+$key))
    $held = $false
    try {
        try { $held = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $held = $true }
        if (!$held) { throw 'Otra operacion de respaldo o restauracion esta utilizando el mismo destino u origen.' }
        & $Work
    } finally {
        if ($held) { $mutex.ReleaseMutex() }
        $mutex.Dispose()
    }
}
function ExternalPath([string]$Path) {
    if (!$Path) { throw 'Se requiere una ruta externa.' }
    $full = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    if ($full -notmatch '^[A-Za-z]:\\' -or $full -match '[,\r\n]' -or $full.Substring(2).Contains(':') -or
        $full.Equals($Repo,[StringComparison]::OrdinalIgnoreCase) -or $full.StartsWith($Repo+'\',[StringComparison]::OrdinalIgnoreCase)) {
        throw 'Usa una ruta local fuera del repositorio, sin caracteres especiales de montaje.'
    }
    for ($p = $full; $p; $p = [IO.Path]::GetDirectoryName($p)) {
        if ((Test-Path -LiteralPath $p) -and ((Get-Item -Force -LiteralPath $p).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw 'Se rechazan enlaces y puntos de montaje en el destino.'
        }
    }
    return $full
}
function OutsidePrivateDisks([string]$Target,[string[]]$Protected) {
    foreach ($disk in $Protected) {
        if ($Target.Equals($disk,[StringComparison]::OrdinalIgnoreCase) -or $Target.StartsWith($disk.TrimEnd('\')+'\',[StringComparison]::OrdinalIgnoreCase)) {
            throw 'El destino se encuentra en el almacenamiento privado del origen.'
        }
    }
}
function PrivateDirectory([string]$Path) {
    $full = ExternalPath $Path
    if (Test-Path -LiteralPath $full) { throw 'El directorio de destino ya existe.' }
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    foreach ($who in @($sid, (New-Object Security.Principal.SecurityIdentifier('S-1-5-18')))) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($who,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
        $acl.AddAccessRule($rule)
    }
    [void][IO.Directory]::CreateDirectory($full,$acl)
}
function Container([string]$Id) {
    if ($Id -notmatch '^[0-9a-f]{12,64}$') { throw 'Se esperaba un unico contenedor identificado.' }
    (Dkr @('inspect','--type','container',$Id) 'inspeccionar el contenedor' | ConvertFrom-Json)[0]
}
function Label($Object, [string]$Key) {
    $entry = $Object.Config.Labels.PSObject.Properties[$Key]
    if ($entry) { return $entry.Value }
}
function LocalEngine {
    if ($env:DOCKER_HOST -and $env:DOCKER_CONTEXT) { throw 'Selecciona un solo contexto Docker local.' }
    $endpoint = if ($env:DOCKER_HOST) { $env:DOCKER_HOST } else { Dkr @('context','inspect','--format','{{.Endpoints.docker.Host}}') 'identificar Docker Desktop' }
    if ($endpoint -notmatch '^npipe:') { throw 'El procedimiento requiere el motor Linux local de Docker Desktop por canal de Windows.' }
    $engine = Dkr @('info','--format','{{.OSType}} {{.ID}}') 'identificar el motor Docker'
    if ($engine -notmatch '^linux (.+)$') { throw 'Se requiere un motor Linux local.' }
    return $Matches[1]
}
function SourceId([string]$Service) { Dkr ($SourceArgs + @('ps','-a','-q',$Service)) "identificar el servicio $Service" }
function DbArgs([string]$Id,[string]$Role,[string]$Database) {
    @('exec','-i','-e','PGCLIENTENCODING=UTF8',$Id,'psql','-X','-qAt','-v','ON_ERROR_STOP=1','-U',$Role,'-d',$Database)
}
function Identity($Id,$Role,$Db) {
    $sql = "SELECT json_build_object('db',current_database(),'cluster',system_identifier::text,'pg',current_setting('server_version'),'encoding',pg_encoding_to_char(encoding),'collate',datcollate,'ctype',datctype,'provider',datlocprovider,'locale',to_jsonb(d)->>'datlocale','collation_version',datcollversion) FROM pg_control_system(),pg_database AS d WHERE datname=current_database()"
    Dkr ((DbArgs $Id $Role $Db) + @('-c',$sql)) 'comprobar la identidad de PostgreSQL' | ConvertFrom-Json
}
function Snapshot($Id,$Role,$Db,$Path) { RunNative docker (DbArgs $Id $Role $Db) 'inventario SQL de solo lectura' -InFile (Join-Path $Tools 'inventory.sql') -OutFile $Path }
function PackageFiles { @('database.dump','private.zip','files.json','inventory.ndjson') }
function ValidatePackage([string]$Path,[string]$Expected) {
    $base = ExternalPath $Path
    if ([IO.Path]::GetFileName($base) -like '*.partial') { throw 'Paquete incompleto.' }
    $required = @(PackageFiles) + @('manifest.json','manifest.sha256')
    $entries = @(Get-ChildItem -Force -LiteralPath $base)
    if ($entries.Count -ne $required.Count) { throw 'El paquete contiene archivos inesperados o faltantes.' }
    foreach ($entry in $entries) {
        if ($entry.PSIsContainer -or ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $entry.Name -cnotin $required) { throw 'Entrada de paquete no valida.' }
    }
    $manifestPath = Join-Path $base 'manifest.json'
    $sha = Digest $manifestPath
    if ($sha -cne [IO.File]::ReadAllText((Join-Path $base 'manifest.sha256')).Trim() -or ($Expected -and $Expected.ToLowerInvariant() -cne $sha)) { throw 'El manifiesto no coincide con su huella.' }
    $m = ReadJson $manifestPath
    if ($m.format -cne 'revenia-backup-1' -or $m.status -cne 'complete' -or @($m.files).Count -ne 4) { throw 'Formato de respaldo no valido.' }
    foreach ($name in (PackageFiles)) {
        $items = @($m.files | Where-Object { $_.name -ceq $name })
        $p = Join-Path $base $name
        if ($items.Count -ne 1 -or (Get-Item -LiteralPath $p).Length -ne $items[0].bytes -or (Digest $p) -cne $items[0].sha256) { throw "Integridad incorrecta en $name." }
    }
    return $m
}

