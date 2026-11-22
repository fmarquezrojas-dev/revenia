function CheckNames($Object,[string[]]$Allowed) {
    $names = @($Object.PSObject.Properties.Name)
    if ($names.Count -ne $Allowed.Count -or @($names | Where-Object { $_ -cnotin $Allowed }).Count) { throw 'El modelo Compose no coincide con el entorno aislado.' }
}
function BindPath([string]$Value) {
    if ($Value -match '^/(?:run/desktop/mnt/host/|host_mnt/|mnt/)?([A-Za-z])/(.*)$') { $Value = $Matches[1]+':/'+$Matches[2] }
    [IO.Path]::GetFullPath($Value)
}
function UsesViewerNetwork { return [bool]($st.PSObject.Properties['viewerIngress'] -and $st.viewerIngress -eq 1) }
function RecoveryNetworks { if (UsesViewerNetwork) { 'viewer' }; 'isolated' }
function ViewerReady {
    $uri = "http://127.0.0.1:$($st.port)/"
    for ($attempt = 0; $attempt -lt 15; $attempt++) {
        $request = [Net.HttpWebRequest]::Create($uri)
        $request.Proxy = $null
        $request.Timeout = 1000
        $request.AllowAutoRedirect = $false
        $response = $null
        try { $response = $request.GetResponse() }
        catch [Net.WebException] { $response = $_.Exception.Response }
        if ($response) {
            try { if ([int]$response.StatusCode -eq 401 -and $response.Headers['WWW-Authenticate'] -like '*Revenia*') { return } }
            finally { $response.Dispose() }
        }
        Start-Sleep -Milliseconds 250
    }
    throw 'El visor no responde en el puerto local de esta instancia.'
}
function RecoveryCompose([string[]]$A,[string]$Step) {
    $overlay = @()
    if (UsesViewerNetwork) { $overlay = @('-f',(Join-Path $Repo 'compose.recovery.viewer.yaml')) }
    RunNative docker (@('compose','--project-directory',$Repo,'--env-file',(Join-Path $Instance 'empty.env'),'-p',$st.project,'-f',(Join-Path $Repo 'compose.recovery.yaml'))+$overlay+$A) $Step -Vars $rv
}
function RecoveryPlan {
    $config = RecoveryCompose @('config','--format','json') 'validar Compose aislado'
    $model = $config | ConvertFrom-Json
    CheckNames $model.services @('db','ops','review')
    CheckNames $model.volumes @('pg','private')
    CheckNames $model.networks @(RecoveryNetworks)
    if ($model.name -cne $st.project -or !$model.networks.isolated.internal -or $model.networks.isolated.name -cne ($st.project+'_isolated')) { throw 'Red o proyecto inesperados.' }
    foreach ($v in @('pg','private')) {
        $spec = $model.volumes.$v
        if ($spec.name -cne ($st.project+'_'+$v) -or $spec.PSObject.Properties['external'] -or $spec.PSObject.Properties['driver_opts'] -or $spec.name -in $st.sourceVolumes) { throw 'Volumen de destino inseguro.' }
    }
    $expected = ExpectedMounts
    foreach ($service in @('db','ops','review')) {
        $spec = $model.services.$service
        if ($service -eq 'review' -and (UsesViewerNetwork)) {
            CheckNames $spec.networks @('viewer')
            if ($spec.user -cne '65534:65534' -or @($spec.cap_drop) -cnotcontains 'ALL' -or
                @($spec.security_opt) -cnotcontains 'seccomp=./scripts/recovery/viewer-seccomp.json' -or
                @($spec.security_opt) -cnotcontains 'no-new-privileges:true') { throw 'Faltan restricciones del visor.' }
        } else { CheckNames $spec.networks @('isolated') }
        if ($spec.volumes.Count -ne $expected[$service].Count -or $spec.PSObject.Properties['privileged'] -or $spec.PSObject.Properties['network_mode']) { throw 'Configuracion de montaje o red inesperada.' }
        foreach ($mount in $spec.volumes) {
            $want = $expected[$service][$mount.target]
            if (!$want) { throw 'Montaje no autorizado.' }
            if ($mount.type -eq 'volume') {
                if ($want -cne ('volume:'+$mount.source) -or !$mount.volume.nocopy) { throw 'Volumen incorrecto o con inicializacion desde la imagen.' }
                if ($service -eq 'ops' -and !$mount.read_only) { throw 'El disco privado del verificador debe montarse en solo lectura.' }
            } elseif ($mount.type -ne 'bind' -or !$mount.read_only -or (BindPath $mount.source) -ne $want -or !(Test-Path -LiteralPath $want)) { throw 'Ruta de montaje no valida o inexistente.' }
        }
        if ($service -ne 'review' -and $spec.PSObject.Properties['ports']) { throw 'Puerto inesperado en la base o en el servicio de operaciones.' }
    }
    $pub = @($model.services.review.ports)
    if ($pub.Count -ne 1 -or $pub[0].host_ip -ne '127.0.0.1' -or [int]$pub[0].published -ne $st.port -or [int]$pub[0].target -ne 8080) { throw 'La publicacion del visor no es local.' }
}
function ExpectedMounts {
    $pw = Join-Path $Instance 'db.password'
    $ops = @{'/restore'='volume:private'; '/package'=$st.package; '/run/recovery-password'=$pw; '/tools'=$Tools}
    foreach ($item in @('app','artisan','bootstrap/app.php','bootstrap/providers.php','composer.json','config','database/migrations','routes','vendor')) {
        $ops['/app/'+$item] = [IO.Path]::GetFullPath((Join-Path $Repo ('backend/'+$item)))
    }
    @{db=@{'/var/lib/postgresql/data'='volume:pg'; '/run/recovery-password'=$pw}; ops=$ops;
      review=@{'/viewer/review.php'=(Join-Path $Tools 'review.php'); '/reports'=(Join-Path $Instance 'reports'); '/run/viewer.sha256'=(Join-Path $Instance 'viewer.sha256')}}
}
function RecoveryVars {
    $resourceToken = if ($st.PSObject.Properties['resourceToken']) { $st.resourceToken } else { 'legacy' }
    if ($resourceToken -ne 'legacy' -and $resourceToken -cnotmatch '^[a-f0-9]{32}$') { throw 'Identificador de recursos no valido.' }
    @{RV_STATE=$Instance; RV_PACKAGE=$st.package; RV_PG_IMAGE=$st.pgImage; RV_PHP_IMAGE=$st.phpImage; RV_DATABASE=$st.database; RV_PORT=$st.port; RV_RESOURCE_TOKEN=$resourceToken}
}
function CheckDockerObjects([switch]$AllowMissing) {
    $expected = ExpectedMounts
    $result = @{}
    $ids = Dkr @('ps','-a','-q','--no-trunc','--filter',('label=com.docker.compose.project='+$st.project)) 'validar contenedores de la copia'
    foreach ($id in @($ids -split '\s+' | Where-Object { $_ })) {
        $c = Container $id
        $service = Label $c 'com.docker.compose.service'
        if ($rv.RV_RESOURCE_TOKEN -ne 'legacy' -and (Label $c 'io.revenia.recovery.instance') -cne $rv.RV_RESOURCE_TOKEN) { throw 'El contenedor no fue creado para esta instancia.' }
        $network = $st.project+'_isolated'
        if ($service -eq 'review' -and (UsesViewerNetwork)) {
            $network = $st.project+'_viewer'
            $options = @($c.HostConfig.SecurityOpt)
            $seccomp = @($options | Where-Object { $_ -clike 'seccomp=*' })
            if (!$c.HostConfig.ReadonlyRootfs -or $c.Config.User -cne '65534:65534' -or @($c.HostConfig.CapDrop) -cnotcontains 'ALL' -or
                !@($options | Where-Object { $_ -in @('no-new-privileges','no-new-privileges:true','no-new-privileges=true') }).Count -or $seccomp.Count -ne 1) { throw 'Visor sin restricciones efectivas.' }
            $actualPolicy = $seccomp[0].Substring(8) | ConvertFrom-Json
            $expectedPolicy = ReadJson (Join-Path $Tools 'viewer-seccomp.json')
            if ((ConvertTo-Json $actualPolicy -Depth 16 -Compress) -cne (ConvertTo-Json $expectedPolicy -Depth 16 -Compress)) { throw 'El filtro de llamadas del visor no coincide.' }
        }
        if (!$expected.ContainsKey([string]$service) -or $result.ContainsKey($service) -or $c.HostConfig.NetworkMode -cne $network) { throw 'Contenedor ajeno o red no aislada.' }
        $image = if ($service -eq 'db') { $st.pgImage } else { $st.phpImage }
        if ($c.Image -ne $image) { throw 'Imagen inesperada.' }
        $attached = @()
        if ($null -ne $c.NetworkSettings.Networks) { $attached = @($c.NetworkSettings.Networks.PSObject.Properties | Where-Object MemberType -eq NoteProperty | ForEach-Object Name) }
        if (@($attached | Where-Object { $_ -ne $network }).Count -or ($c.State.Running -and $attached.Count -ne 1)) { throw 'Red efectiva no aislada.' }
        $result[$service] = $id
        $seen = @{}
        foreach ($mount in $c.Mounts) {
            if ($mount.Type -eq 'tmpfs' -and $service -eq 'ops' -and $mount.Destination -in @('/tmp','/app/bootstrap/cache','/app/storage')) { continue }
            $want = $expected[$service][$mount.Destination]
            if (!$want) { throw 'Montaje efectivo no autorizado.' }
            if ($mount.Type -eq 'volume') {
                if ($want -notlike 'volume:*' -or $mount.Name -cne ($st.project+'_'+$want.Substring(7)) -or $mount.Name -in $st.sourceVolumes) { throw 'Volumen efectivo compartido o incorrecto.' }
                if ($service -eq 'ops' -and $rv.RV_RESOURCE_TOKEN -ne 'legacy' -and $mount.RW) { throw 'La verificacion requiere un montaje privado de solo lectura.' }
            } elseif ($mount.Type -ne 'bind' -or $mount.RW -or (BindPath $mount.Source) -ne $want) { throw 'Bind efectivo no autorizado.' }
            $seen[$mount.Destination]=$true
        }
        if ($seen.Count -ne $expected[$service].Count) { throw 'Montajes incompletos.' }
        $bindings = @()
        if ($null -ne $c.HostConfig.PortBindings) { $bindings = @($c.HostConfig.PortBindings.PSObject.Properties | Where-Object MemberType -eq NoteProperty) }
        if ($service -eq 'review') {
            if ($bindings.Count -ne 1 -or $bindings[0].Name -ne '8080/tcp' -or @($bindings[0].Value).Count -ne 1 -or
                $bindings[0].Value[0].HostIp -ne '127.0.0.1' -or [int]$bindings[0].Value[0].HostPort -ne $st.port) { throw 'Puerto efectivo no autorizado.' }
        } elseif ($bindings.Count) { throw 'Puerto adicional.' }
    }
    if (!$AllowMissing -and $result.Count -ne 3) { throw 'Faltan servicios de la copia.' }
    foreach ($kind in @('volume','network')) {
        $names = if ($kind -eq 'volume') { @('pg','private') } else { @(RecoveryNetworks) }
        $known = (Dkr @($kind,'ls','--format','{{.Name}}') "identificar recursos $kind") -split '\s+'
        foreach ($n in $names) {
            $fullName = $st.project+'_'+$n
            if ($fullName -notin $known) {
                if ($AllowMissing) { continue }
                throw 'Falta un recurso de la instancia.'
            }
            $res = (Dkr @($kind,'inspect',$fullName) 'comprobar propiedad del recurso' | ConvertFrom-Json)[0]
            if ($res.Labels.'com.docker.compose.project' -cne $st.project -or $res.Labels.("com.docker.compose."+$kind) -cne $n) { throw 'El recurso pertenece a otro proyecto.' }
            if ($rv.RV_RESOURCE_TOKEN -ne 'legacy' -and $res.Labels.'io.revenia.recovery.instance' -cne $rv.RV_RESOURCE_TOKEN) { throw 'El recurso no pertenece a esta instancia de recuperacion.' }
            if ($kind -eq 'volume' -and ($res.Driver -cne 'local' -or $res.Scope -cne 'local' -or
                ($null -ne $res.Options -and @($res.Options.PSObject.Properties | Where-Object MemberType -eq NoteProperty).Count))) {
                throw 'El volumen no es local o redirige el almacenamiento.'
            }
            if ($kind -eq 'network' -and $n -eq 'viewer' -and ($res.Driver -cne 'bridge' -or $res.Options.'com.docker.network.bridge.enable_icc' -cne 'false' -or $res.Options.'com.docker.network.bridge.enable_ip_masquerade' -cne 'false')) { throw 'Red del visor inesperada.' }
            if ($kind -eq 'network' -and $n -eq 'isolated' -and !$res.Internal) { throw 'La red permite salida.' }
        }
    }
    return $result
}
function AssertNewProject {
    $prefix = $st.project
    if ((Dkr @('ps','-a','-q','--filter',"label=com.docker.compose.project=$prefix") 'descartar contenedores anteriores') -or
        (Dkr @('volume','ls','-q','--filter',"label=com.docker.compose.project=$prefix") 'descartar volumenes anteriores')) { throw 'El proyecto ya contiene recursos.' }
    foreach ($kind in @('volume','network')) {
        $names = (Dkr @($kind,'ls','--format','{{.Name}}') 'descartar recursos preexistentes') -split '\s+'
        if (@($names | Where-Object { $_ -like ($prefix+'_*') }).Count) { throw 'Nombre de volumen o red ya ocupado.' }
    }
}
function WriteState { SaveJson (Join-Path $Instance 'instance.json') $st }
function AssertEmptyRecoveryVolumes {
    # Both mounts are read-only and nocopy. The PostgreSQL entrypoint has not run yet.
    $probe = 'foreach (["/check-db","/check-private"] as $path) { if (iterator_count(new FilesystemIterator($path)) !== 0) { exit(1); } }'
    [void](Dkr @('run','--rm','--pull=never','--network','none','--read-only',
        '--mount',("type=volume,source="+$st.project+"_pg,target=/check-db,readonly,volume-nocopy"),
        '--mount',("type=volume,source="+$st.project+"_private,target=/check-private,readonly,volume-nocopy"),
        '--entrypoint','php',$st.phpImage,'-r',$probe) 'comprobar que ambos volumenes estan vacios antes de iniciar PostgreSQL')
}
function ResultPath { Join-Path $Instance 'reports/resultado.json' }
function CheckCluster($ids) {
    $now = Identity $ids.db 'postgres' $st.database
    if ($now.db -cne $st.database -or $now.cluster -eq $st.sourceCluster -or ($st.cluster -and $now.cluster -ne $st.cluster)) { throw 'La base no es el destino aislado esperado.' }
    return $now
}
function Verify-Recovery($ids,$m,$report) {
    $report.ok=$false
    $report.checked_utc=$null
    $report.details=$null
    $report.package='Manifiesto y sumas correctos'
    $report.files='Pendiente de cotejar'
    $report.relations='Pendiente de comprobar'
    $report.baseline='Pendiente de comparar con el respaldo'
    SaveJson (ResultPath) $report
    $audit = Join-Path $Instance ('inventory-'+[guid]::NewGuid().ToString('N')+'.ndjson')
    try {
        [void](CheckCluster $ids)
        Snapshot $ids.db 'postgres' $st.database $audit
    } catch {
        $report.relations='No se pudo completar el inventario SQL'
        SaveJson (ResultPath) $report
        throw
    }
    $verifyArgs = @('exec','-i',$ids.ops,'php','/tools/files.php','verify')
    if ($st.phase -eq 'migrated') { $verifyArgs += 'after-migration' }
    try {
        $v = RunNative docker $verifyArgs 'verificar archivos y PDF sin escritura' -InFile $audit | ConvertFrom-Json
    } catch {
        $report.files='No se pudo completar la lectura o el cotejo de los archivos privados'
        SaveJson (ResultPath) $report
        throw
    }
    $same = (Digest $audit) -ceq (Digest (Join-Path $st.package 'inventory.ndjson'))
    $report.details = $v
    $report.package = 'Manifiesto y sumas correctos'
    $report.checked_utc = Utc
    $report.files = if ($v.all_files_match -and !$v.missing -and !$v.different) { 'Coinciden los archivos y las referencias PDF' } else { 'Diferencias detectadas' }
    $report.relations = if (!$v.problems) { 'Comprobadas' } else { 'Diferencias detectadas' }
    $historic = $false
    if ($st.phase -eq 'migrated') {
        $prior = @($m.migrations.applied)
        $allowed = @('2026_11_20_000002_add_document_payment_terms','2026_11_20_000003_bind_payment_receivers','2026_11_20_000004_add_incident_follow_up','2026_11_20_000005_add_odoo_transfers')
        $additions = @($v.migrations | Where-Object { $_.name -notin $prior.name })
        $lost = @($prior | Where-Object { $record=$_; !@($v.migrations | Where-Object { $_.name -ceq $record.name -and $_.batch -eq $record.batch }).Count })
        $historic = $v.history_matches_package -and !$lost.Count -and
            !@($additions | Where-Object { $_.name -notin $allowed }).Count -and !@($allowed | Where-Object { $_ -notin $v.migrations.name }).Count
    }
    $baselineOk = $same
    $report.baseline = if ($same) { 'Coincide con el inventario del respaldo' } else { 'Diferencias con el origen' }
    if ($st.phase -eq 'migrated') {
        $baselineOk = $historic
        $report.baseline = if ($historic) { 'Cuatro migraciones registradas; se conservan los recuentos historicos, empresas y referencias PDF' } else { 'Diferencias en las migraciones o en el inventario historico del paquete' }
    } elseif ($st.phase -eq 'migrating') {
        $baselineOk = $false
        $report.baseline = 'La aplicacion de migraciones sigue sin confirmarse'
    }
    $report.ok = [bool]($v.all_files_match -and !$v.missing -and !$v.different -and !$v.problems -and $baselineOk)
    SaveJson (ResultPath) $report
    if (!$report.ok) { throw 'La comprobacion encontro diferencias. Consulta reports/resultado.json.' }
    return $report
}
function ResolveImage([string]$Ref) {
    if (!$Ref -or $Ref.StartsWith('-')) { throw 'Referencia de imagen no valida.' }
    $id = Dkr @('image','inspect','--format','{{.Id}}',$Ref) 'resolver una imagen local (sin descargarla)'
    if ($id -notmatch '^sha256:[0-9a-f]{64}$') { throw 'Imagen local no disponible.' }
    return $id
}
function RecordExactResult($report) {
    if (!$report.ok -or $st.phase -ne 'restored') { throw 'La copia exacta aun no ha superado la comprobacion.' }
    SaveJson (Join-Path $Instance 'reports/origen-comprobado.json') $report
    $st.phase='verified'
    WriteState
}
function Restore-Revenia {
    $Package = ExternalPath $Package
    $m = ValidatePackage $Package $ExpectedManifestHash
    $engine = LocalEngine
    if ($Port -in $m.source.ports -or $Port -in @(5173,8000,8025,8069,5432) -or
        $Port -in @([Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners() | ForEach-Object Port)) { throw 'El puerto coincide con el origen o esta ocupado.' }
    $token = [guid]::NewGuid().ToString('N').Substring(0,12)
    $project = 'revenia-restore-'+$token
    $dbName = 'rv_restore_'+$token
    if ($project -eq $m.source.project -or $dbName -eq $m.source.database.db) { throw 'El destino coincide con el origen.' }
    if (!$PhpImage) { $PhpImage=$m.versions.php_image }
    if (!$PostgresImage) { $PostgresImage=$m.versions.pg_image }
    $Instance = ExternalPath (Join-Path (ExternalPath $RecoveryRoot) $project)
    OutsidePrivateDisks $Instance $m.source.private_bind_dirs
    $st = [pscustomobject]@{project=$project; database=$dbName; engine=$engine; package=$Package; manifest=(Digest (Join-Path $Package 'manifest.json'));
        port=$Port; sourceCluster=$m.source.database.cluster; sourceVolumes=@($m.source.volumes); phpImage=(ResolveImage $PhpImage); pgImage=(ResolveImage $PostgresImage);
        cluster=''; phase='new'; viewerIngress=1; repo=$Repo; resourceToken=[guid]::NewGuid().ToString('N')}
    Invoke-ExclusiveOperation $Instance { Initialize-Recovery $m }
}
function Initialize-Recovery($m) {
    $project=$st.project
    $dbName=$st.database
    AssertNewProject
    if ($Instance.StartsWith($Package+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'El entorno de recuperacion debe estar fuera del paquete.' }
    $watch=[Diagnostics.Stopwatch]::StartNew()
    $password = Read-Host 'Elige una clave temporal para el visor local (usuario operador)' -AsSecureString
    if (!$password.Length) { throw 'El visor requiere una clave temporal.' }
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $secretBytes = $Utf8.GetBytes((New-Object Net.NetworkCredential('', $password)).Password)
        try { $viewerHash = [BitConverter]::ToString($sha.ComputeHash($secretBytes)).Replace('-','').ToLowerInvariant() }
        finally { [Array]::Clear($secretBytes,0,$secretBytes.Length) }
    } finally { $password.Dispose(); $sha.Dispose() }
    PrivateDirectory $Instance
    [IO.File]::WriteAllText((Join-Path $Instance 'viewer.sha256'),$viewerHash,$Utf8)
    Write-Host "Instancia nueva: $Instance"
    [void][IO.Directory]::CreateDirectory((Join-Path $Instance 'reports'))
    [IO.File]::WriteAllText((Join-Path $Instance 'empty.env'),'',$Utf8)
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    [IO.File]::WriteAllText((Join-Path $Instance 'db.password'),[Convert]::ToBase64String($bytes),$Utf8)
    WriteState
    $rv = RecoveryVars
    $report = [pscustomobject]@{project=$project; package='Manifiesto y sumas correctos'; postgres='Pendiente'; files='Pendiente'; relations='Pendiente';
        package_bytes=$m.payload_bytes; baseline='Pendiente'; functional='Pendiente de validacion por el operador'; ok=$false; details=$null; checked_utc=$null; restore_seconds=$null}
    SaveJson (ResultPath) $report
    try {
        RecoveryPlan
        AssertNewProject
        [void](RecoveryCompose @('create','--no-build','--pull','never','db','ops','review') 'crear los recursos vacios')
        $ids = CheckDockerObjects
        AssertEmptyRecoveryVolumes
        [void](Dkr @('start',$ids.db,$ids.ops) 'iniciar PostgreSQL y el servicio aislado de comprobacion')
        $ready=$false
        for ($i=0;$i -lt 60;$i++) {
            try {
                # TCP becomes available only after the entrypoint finishes its temporary initialization server.
                [void](Dkr @('exec',$ids.db,'pg_isready','-h','127.0.0.1','-U','postgres','-d',$dbName) 'esperar a PostgreSQL')
                $ready=$true; break
            } catch { Start-Sleep -Seconds 1 }
        }
        if (!$ready) { throw 'PostgreSQL no esta listo.' }
        $ids = CheckDockerObjects
        $new = CheckCluster $ids
        if (($new.pg -split '\.')[0] -ne ($m.source.database.pg -split '\.')[0]) { throw 'La version mayor de PostgreSQL difiere del origen.' }
        foreach ($k in @('encoding','collate','ctype','provider','locale','collation_version')) { if ($new.$k -cne $m.source.database.$k) { throw 'La configuracion regional o codificacion no coincide.' } }
        $objects = Dkr ((DbArgs $ids.db 'postgres' $dbName)+@('-c',"SELECT count(*) FROM pg_namespace WHERE nspname NOT IN ('public','information_schema') AND nspname NOT LIKE 'pg_%'")) 'validar esquemas vacios'
        $relations = Dkr ((DbArgs $ids.db 'postgres' $dbName)+@('-c',"SELECT count(*) FROM pg_class WHERE relnamespace='public'::regnamespace")) 'validar base vacia'
        if ($objects -ne '0' -or $relations -ne '0') { throw 'La base contiene objetos anteriores.' }
        try {
            [void](Dkr @('run','--rm','--pull=never','--network','none','--read-only',
                '--mount',("type=volume,source="+$st.project+"_private,target=/restore,volume-nocopy"),
                '--mount',("type=bind,source="+$st.package+",target=/package,readonly"),
                '--mount',("type=bind,source="+(Join-Path $Tools 'files.php')+",target=/files.php,readonly"),
                '--entrypoint','php',$st.phpImage,'/files.php','unpack') 'extraer y verificar en el volumen privado vacio')
        } catch {
            $report.files='Extraccion o cotejo de archivos incompleto'
            throw
        }
        $report.files='Contenido del archivo privado extraido y cotejado'
        $st.cluster=$new.cluster; $st.phase='restoring'; WriteState
        try {
            [void](RunNative docker @('exec','-i',$ids.db,'pg_restore','--single-transaction','--exit-on-error','--no-owner','--no-privileges','--no-tablespaces','-U','postgres','-d',$st.database) 'restaurar el archivo PostgreSQL en una transaccion' -InFile (Join-Path $st.package 'database.dump'))
        } catch {
            $report.postgres='La importacion no pudo confirmarse'
            throw
        }
        $report.postgres='Volcado restaurado en una transaccion'
        $st.phase='restored'; WriteState
        try { $m = ValidatePackage $st.package $st.manifest } catch {
            $report.package='El paquete cambio durante la restauracion'
            throw
        }
        $report = Verify-Recovery $ids $m $report
        $report.restore_seconds=$watch.Elapsed.TotalSeconds
        [void](Dkr @('start',$ids.review) 'abrir el visor local')
        ViewerReady
        RecordExactResult $report
        Write-Host "Visor de solo lectura: http://127.0.0.1:$Port/"
    } catch {
        $report.ok=$false
        throw
    } finally {
        $report.restore_seconds=$watch.Elapsed.TotalSeconds
        SaveJson (ResultPath) $report
    }
    [pscustomobject]@{Instance=$Instance; Report=(ResultPath); Url="http://127.0.0.1:$Port/"}
}

function Manage-Recovery([string]$Operation) {
    $Instance = ExternalPath $Instance
    $st = ReadJson (Join-Path $Instance 'instance.json')
    if ($st.project -notmatch '^revenia-restore-([a-f0-9]{12})$' -or $st.database -cne ('rv_restore_'+$Matches[1]) -or
        (Split-Path -Leaf $Instance) -cne $st.project -or $st.repo -ne $Repo -or (LocalEngine) -ne $st.engine) { throw 'La identidad local de la instancia no coincide.' }
    $rv=RecoveryVars
    if ($Operation -eq 'Remove') {
        if ($st.phase -eq 'removed') { throw 'Esta instancia ya fue retirada.' }
        $ids = CheckDockerObjects -AllowMissing
        if ((Read-Host "Para retirar SOLO los recursos Docker de esta copia escribe $($st.project)") -cne $st.project) { throw 'Retiro cancelado.' }
        $ids = CheckDockerObjects -AllowMissing
        # Exact inspected IDs/names only. Never run down on the source or delete a host directory.
        foreach ($key in @('review','ops','db')) {
            if ($ids.ContainsKey($key)) { [void](Dkr @('rm','-f',$ids[$key]) 'retirar el contenedor de recuperacion') }
        }
        foreach ($kind in @('volume','network')) {
            $list=(Dkr @($kind,'ls','--format','{{.Name}}') 'listar recursos de recuperacion') -split '\s+'
            $suffixes=if ($kind -eq 'volume') { @('pg','private') } else { @(RecoveryNetworks) }
            foreach ($suffix in $suffixes) {
                $name=$st.project+'_'+$suffix
                if ($name -in $list) { [void](Dkr @($kind,'rm',$name) 'retirar el recurso de recuperacion') }
            }
        }
        [IO.File]::Delete((Join-Path $Instance 'db.password'))
        [IO.File]::Delete((Join-Path $Instance 'viewer.sha256'))
        $st.phase='removed'; WriteState
        Write-Host "Recursos Docker retirados. Se conservan el paquete y los informes en $Instance."
        return
    }
    if ($st.phase -notin @('restored','verified','migrated','migrating')) { throw 'La restauracion de PostgreSQL no concluyo.' }
    $report=ReadJson (ResultPath)
    $report.ok=$false; $report.package='Comprobacion en curso'; $report.checked_utc=$null
    SaveJson (ResultPath) $report
    try { $m = ValidatePackage $st.package $st.manifest } catch {
        $report.package='Paquete ausente, modificado o incompleto'; SaveJson (ResultPath) $report
        throw
    }
    RecoveryPlan
    $ids = CheckDockerObjects
    [void](CheckCluster $ids)
    $report=ReadJson (ResultPath)
    if ($Operation -eq 'Migrate') {
        if ($st.phase -notin @('verified','migrating')) { throw 'Se requiere una copia exacta comprobada antes de migrar.' }
        if ($st.phase -eq 'verified') { $report=Verify-Recovery $ids $m $report }
        if ((Read-Host "Para aplicar las cuatro migraciones SOLO en $($st.database) escribe MIGRAR") -cne 'MIGRAR') { throw 'Migracion cancelada.' }
        $st.phase='migrating'; WriteState
        $report.baseline='Migracion manual en curso o incompleta'; $report.ok=$false; SaveJson (ResultPath) $report
        [void](Dkr @('exec',$ids.ops,'php','/tools/migrate.php',$st.database,$st.cluster) 'aplicar las cuatro migraciones en la copia')
        $st.phase='migrated'; WriteState
    }
    $report = Verify-Recovery $ids $m $report
    if ($st.phase -eq 'restored') { RecordExactResult $report }
    Write-Host "Informe de solo lectura: $(ResultPath)"
    [pscustomobject]@{Instance=$Instance; Report=(ResultPath)}
}

