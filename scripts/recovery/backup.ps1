function StorageMount($ContainerInfo,[string]$Disk) {
    $candidates = @($ContainerInfo.Mounts | Where-Object { $Disk -ceq $_.Destination -or $Disk.StartsWith($_.Destination.TrimEnd('/')+'/',[StringComparison]::Ordinal) } |
        Sort-Object { $_.Destination.Length } -Descending)
    if (!$candidates.Count -or $candidates[0].Type -notin @('volume','bind')) { throw 'El disco privado no esta en un montaje reproducible.' }
    $mount = $candidates[0]
    return $mount.Type+'|'+$mount.Source+'|'+$mount.Destination
}
function SourceBindDirectories($AppInfo,[string[]]$Disks) {
    foreach ($disk in $Disks) {
        $mount = $AppInfo.Mounts | Where-Object { $disk -ceq $_.Destination -or $disk.StartsWith($_.Destination.TrimEnd('/')+'/',[StringComparison]::Ordinal) } |
            Sort-Object { $_.Destination.Length } -Descending | Select-Object -First 1
        if ($mount.Type -eq 'bind') {
            $base = BindPath $mount.Source
            $relative = $disk.Substring($mount.Destination.Length).TrimStart('/')
            if ($relative) { $base = [IO.Path]::GetFullPath((Join-Path $base $relative)) }
            $base
        }
    }
}
function AssertPaused([string]$ProjectName) {
    foreach ($service in @('app','worker','scheduler')) {
        if (Dkr @('ps','-q','--filter',"label=com.docker.compose.project=$ProjectName",'--filter',"label=com.docker.compose.service=$service") 'comprobar la pausa de escritura') {
            throw 'Hay escritores del proyecto activos durante el respaldo.'
        }
    }
}
function Backup-Revenia {
    $engine = LocalEngine
    $appId = SourceId 'app'
    $dbId = SourceId 'db'
    $app = Container $appId
    $pg = Container $dbId
    $project = Label $app 'com.docker.compose.project'
    if (!$app.State.Running -or !$pg.State.Running -or !$project -or $project -like 'revenia-restore-*' -or
        (Label $pg 'com.docker.compose.project') -ne $project -or (Label $app 'com.docker.compose.service') -ne 'app' -or (Label $pg 'com.docker.compose.service') -ne 'db') {
        throw 'Se requieren la app y PostgreSQL del proyecto de origen en ejecucion.'
    }
    $rootLabel = Label $app 'com.docker.compose.project.working_dir'
    if (!$rootLabel -or (BindPath $rootLabel) -ne $Repo) { throw 'La aplicacion no corresponde al repositorio actual.' }
    $meta = RunNative docker @('exec','-i',$appId,'php') 'leer los discos y la identidad de Laravel' -InFile (Join-Path $Tools 'source.php') | ConvertFrom-Json
    if ($meta.db -notmatch '^[a-zA-Z_][a-zA-Z0-9_]{0,62}$' -or $meta.role -notmatch '^[a-zA-Z_][a-zA-Z0-9_]{0,62}$' -or !$meta.has_key) { throw 'Base, rol o APP_KEY no disponibles para el respaldo.' }
    $identity = Identity $dbId $meta.role $meta.db
    if ($identity.cluster -ne $meta.cluster -or $identity.db -ne $meta.db) { throw 'La base efectiva de Laravel no coincide con el contenedor de origen.' }
    if ($meta.local -cne '/app/storage/app/private' -or $meta.documents -notmatch '^/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+$') { throw 'La ruta efectiva del disco privado requiere revision.' }
    if (!$meta.documents.StartsWith($meta.local+'/',[StringComparison]::Ordinal) -and
        !@($app.Mounts | Where-Object { $_.Destination -ceq $meta.documents -and $_.Destination -notin @('/app','/tmp','/run','/var','/app/storage') }).Count) {
        throw 'El disco de documentos personalizado debe tener un montaje dedicado.'
    }

    $backend = @($app.Mounts | Where-Object { $_.Destination -ceq '/app' -and $_.Type -eq 'bind' })
    if ($backend.Count -ne 1 -or (BindPath $backend[0].Source) -ne (Join-Path $Repo 'backend')) { throw 'El codigo montado en el origen no es el de esta copia local.' }
    $localMount = StorageMount $app $meta.local
    $documentsMount = StorageMount $app $meta.documents
    $writers = @()
    foreach ($service in @('app','scheduler','worker')) {
        $id = SourceId $service
        if (!$id) { continue }
        $container = Container $id
        if ((Label $container 'com.docker.compose.project') -ne $project -or (Label $container 'com.docker.compose.service') -ne $service) { throw 'Origen ambiguo.' }
        if ($container.State.Paused -or $container.State.Restarting) { throw 'Espera un estado estable de los escritores antes de la pausa.' }
        if ($container.State.Running) {
            if ((StorageMount $container $meta.local) -cne $localMount -or (StorageMount $container $meta.documents) -cne $documentsMount) { throw 'Los escritores no comparten el almacenamiento privado de la aplicacion.' }
            $writers += $container
        }
    }
    $allIds = (Dkr @('ps','--no-trunc','-q','--filter',"label=com.docker.compose.project=$project") 'comprobar servicios del origen') -split '\s+'
    $ports = @()
    $images = [ordered]@{}
    $volumes = @()
    foreach ($id in $allIds) {
        $c = Container $id
        if ((Label $c 'com.docker.compose.oneoff') -eq 'True' -or (Label $c 'com.docker.compose.service') -notin @('app','worker','scheduler','db','frontend','redis','mailpit','odoo','odoo-db')) { throw 'Deten los contenedores auxiliares del proyecto antes del respaldo.' }
        $service = Label $c 'com.docker.compose.service'
        if ($service -notin @('odoo','odoo-db')) { $images[$service] = $c.Image }
        $volumes += @($c.Mounts | Where-Object Type -eq 'volume' | ForEach-Object Name)
        if ($null -eq $c.NetworkSettings.Ports) { continue }
        foreach ($portEntry in $c.NetworkSettings.Ports.PSObject.Properties) {
            $ports += @($portEntry.Value | Where-Object { $_ } | ForEach-Object { [int]$_.HostPort })
        }
    }
    $base = ExternalPath $Destination
    $protected = @(SourceBindDirectories $app @($meta.local,$meta.documents))
    OutsidePrivateDisks $base $protected
    $name = 'revenia-' + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-' + [guid]::NewGuid().ToString('N').Substring(0,8)
    $complete = ExternalPath (Join-Path $base $name)
    $partial = $complete + '.partial'
    Write-Host "Origen: $project / $($meta.db) (PostgreSQL $($identity.pg)). Destino: $complete"
    Write-Host "Discos: $($meta.local) y $($meta.documents). Pausa prevista: $($writers.Name -join ', ')."
    Write-Host 'Cierra las sesiones de trabajo, espera los trabajos en curso y detiene otros clientes que puedan escribir en SQL o en los discos.'
    if ((Read-Host 'Confirma la custodia de APP_KEY, el acceso exclusivo y la pausa escribiendo RESPALDAR') -cne 'RESPALDAR') { throw 'Respaldo cancelado antes de la pausa.' }
    $started = Utc
    $watch = [Diagnostics.Stopwatch]::StartNew()
    PrivateDirectory $partial
    $stopped = New-Object System.Collections.ArrayList
    try {
        foreach ($c in $writers) {
            [void]$stopped.Add($c.Id)
            [void](Dkr @('stop','--time','120',$c.Id) 'pausar un escritor')
            $after = Container $c.Id
            if ($after.State.Running -or $after.State.ExitCode -eq 137) { throw 'Un escritor no finalizo limpiamente. Revisa sus trabajos antes de repetir.' }
        }
        AssertPaused $project
        $other = Dkr ((DbArgs $dbId $meta.role $meta.db) + @('-c',"SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND backend_type='client backend'")) 'comprobar acceso exclusivo'
        if ($other -ne '0') { throw 'Hay otros clientes conectados a la base. Cierra sus sesiones y repite.' }
        $inventory = Join-Path $partial 'inventory.ndjson'
        Snapshot $dbId $meta.role $meta.db $inventory
        $dump = Join-Path $partial 'database.dump'
        RunNative docker @('exec',$dbId,'pg_dump','--format=custom','--no-owner','--no-privileges','--no-tablespaces','-U',$meta.role,'-d',$meta.db) 'volcar PostgreSQL' -OutFile $dump
        $packer = @('run','--rm','--pull=never','--network','none','--read-only','--tmpfs','/tmp','--volumes-from',($appId+':ro'),
            '--mount',("type=bind,source=$Tools,target=/tools,readonly"),'--mount',("type=bind,source=$partial,target=/package"),
            '--entrypoint','php',$app.Image,'/tools/files.php','pack',$meta.local,$meta.documents)
        [void](Dkr $packer 'copiar y cotejar los archivos privados y PDF con el inventario')
        $second = Join-Path $partial 'repeat.ndjson'
        Snapshot $dbId $meta.role $meta.db $second
        if ((Digest $second) -ne (Digest $inventory)) { throw 'El inventario cambio durante el respaldo.' }
        [IO.File]::Delete($second)
        AssertPaused $project
    } finally {
        $resumeFailures = @()
        foreach ($id in $stopped) {
            try { [void](Dkr @('start',$id) 'reanudar el escritor que estaba activo') } catch { $resumeFailures += $id }
        }
        if ($resumeFailures.Count) { throw "Reanuda manualmente estos contenedores de origen: $($resumeFailures -join ', '). El paquete sigue incompleto." }
    }
    # Read every data block after resuming the source. Generate SQL into /dev/null, without a connection.
    [void](Dkr @('run','--rm','--pull=never','--network','none','--read-only','--mount',("type=bind,source=$partial,target=/package,readonly"),'--entrypoint','pg_restore',$pg.Image,'--file=/dev/null','/package/database.dump') 'leer el volcado PostgreSQL completo')
    $files = @(foreach ($n in (PackageFiles)) {
        $path = Join-Path $partial $n
        [pscustomobject][ordered]@{name=$n; bytes=(Get-Item -LiteralPath $path).Length; sha256=(Digest $path)}
    })
    $applied = @(foreach ($line in [IO.File]::ReadLines($inventory,$Utf8)) {
        if ($line -notmatch '^\{\s*"kind"\s*:\s*"migration"') { break }
        $row = $line | ConvertFrom-Json
        if ($row.kind -eq 'migration') { $row }
    })
    $pending = @(Get-ChildItem -File -LiteralPath (Join-Path $Repo 'backend/database/migrations') -Filter '*.php' | Where-Object { $_.BaseName -notin $applied.name } | Sort-Object Name | ForEach-Object BaseName)
    $manifest = [ordered]@{
        format='revenia-backup-1'; status='complete'; started_utc=$started; completed_utc=(Utc); seconds=$watch.Elapsed.TotalSeconds
        payload_bytes=($files | Measure-Object bytes -Sum).Sum; files=$files
        source=[ordered]@{project=$project; engine=$engine; database=$identity; private_bind_dirs=$protected; volumes=@($volumes | Sort-Object -Unique); ports=@($ports | Sort-Object -Unique); documents=$meta.documents}
        versions=[ordered]@{php=$meta.php; laravel=$meta.laravel; horizon=$meta.horizon; images=$images; php_image=$app.Image; pg_image=$pg.Image; docker=(Dkr @('version','--format','{{.Server.Version}}') 'version de Docker'); compose=(Dkr @('compose','version','--short') 'version de Compose')}
        code=[ordered]@{branch=(GitText @('branch','--show-current')); sha=(GitText @('rev-parse','HEAD')); dirty=[bool](GitText @('status','--porcelain=v1','--untracked-files=normal')); dirty_note='Si dirty es true, el SHA no identifica todo el codigo local.'}
        migrations=[ordered]@{applied=$applied; pending_in_checkout=$pending}
        private_inventory='files.json'; sql_inventory='inventory.ndjson'; consistency='Escritores detenidos y acceso exclusivo confirmado por el operador.'
    }
    SaveJson (Join-Path $partial 'manifest.json') $manifest
    $checksum = Digest (Join-Path $partial 'manifest.json')
    [IO.File]::WriteAllText((Join-Path $partial 'manifest.sha256'),$checksum,$Utf8)
    if ((Split-Path -Parent (ExternalPath $partial)) -ne $base -or (Split-Path -Parent (ExternalPath $complete)) -ne $base) { throw 'Ruta de publicacion fuera del destino autorizado.' }
    [IO.Directory]::Move((ExternalPath $partial),(ExternalPath $complete))
    Write-Host "Respaldo completo. Custodia por separado la huella del manifiesto: $checksum"
    [pscustomobject]@{Package=$complete; ManifestHash=$checksum}
}

