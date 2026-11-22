<?php

declare(strict_types=1);

// CLI only. Archive paths are logical disk names, never absolute host paths.
function validFileName(string $path): bool
{
    if (!preg_match('~^(local|documents)/~D', $path) || preg_match('/[\\\\:\x00-\x1f]/', $path)) {
        return false;
    }
    return !array_intersect(explode('/', $path), ['', '.', '..']);
}

function readFileIndex(string $package): array
{
    $rows = json_decode(file_get_contents("$package/files.json"), true, flags: JSON_THROW_ON_ERROR);
    $index = [];
    foreach ($rows as $row) {
        $path = $row['path'];
        if (!validFileName($path) || isset($index[$path]) || !is_int($row['bytes']) || $row['bytes'] < 0
            || !preg_match('/^[a-f0-9]{64}$/D', $row['sha256'])) {
            throw new RuntimeException('file_index');
        }
        $index[$path] = $row;
    }
    return $index;
}

// Fingerprint rows that the four additive migrations must preserve, including every PDF reference.
function historicRecord(array $row, $digest): void
{
    $newTables = ['merchant_connections','incident_follow_ups','incident_queue_events','odoo_connections','odoo_mappings','odoo_transfers','odoo_events'];
    if (in_array($row['kind'], ['check','migration'], true)
        || ($row['kind'] === 'sequence' && $row['name'] === 'migrations_id_seq')
        || ($row['kind'] === 'total' && $row['table'] === 'migrations')) {
        return;
    }
    if ($row['kind'] === 'total' && in_array($row['table'], $newTables, true) && $row['rows'] === 0) {
        return;
    }
    hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR)."\n");
}

function checkInventory(string $inventory, array $index): array
{
    $digest = hash_init('sha256');
    $checks = $problems = $pdfs = $missing = $different = 0;
    $migrations = $counts = $groups = $companies = $issues = [];
    $input = fopen($inventory, 'rb');
    while (($line = fgets($input)) !== false) {
        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        historicRecord($row, $digest);
        switch ($row['kind']) {
            case 'check':
                $checks++;
                $problems += $row['bad'];
                if ($row['bad']) {
                    $issues[] = ['check' => $row['name'], 'rows' => $row['bad']];
                }
                break;
            case 'company': $companies[] = $row['id']; break;
            case 'count': $counts[] = $row; break;
            case 'group': $groups[] = $row; break;
            case 'migration': $migrations[] = ['name' => $row['name'], 'batch' => $row['batch']]; break;
            case 'pdf':
                $pdfs++;
                $file = $index['documents/'.$row['path']] ?? null;
                if (!$file) {
                    $missing++;
                    $issues[] = ['missing_pdf' => $row['id']];
                } elseif ($row['disk'] !== 'documents' || $row['path'] !== $row['company'].'/'.$row['document'].'/'.$row['attempt'].'.pdf'
                    || $file['sha256'] !== $row['sha256'] || $file['bytes'] !== $row['bytes']) {
                    $different++;
                    $issues[] = ['different_pdf' => $row['id']];
                }
                break;
            case 'sequence':
            case 'total': break;
            default: throw new RuntimeException('inventory_format');
        }
    }
    if (!feof($input) || !$migrations) {
        throw new RuntimeException('inventory_incomplete');
    }
    fclose($input);
    $historical_hash = hash_final($digest);
    return compact('historical_hash','checks','problems','pdfs','missing','different','issues','migrations','companies','counts','groups');
}

function scanDisk(string $root, string $prefix, ?string $exclude, ?ZipArchive $zip, array &$index): void
{
    if (!is_dir($root)) {
        throw new RuntimeException('disk_unavailable');
    }
    $scan = function (string $dir, string $logical) use (&$scan, $exclude, $zip, &$index): void {
        foreach (new FilesystemIterator($dir) as $entry) {
            $path = $entry->getPathname();
            $name = $logical.'/'.$entry->getFilename();
            if (!validFileName($name) || $entry->isLink()) {
                throw new RuntimeException('unsafe_file');
            }
            if ($path === $exclude) {
                continue;
            }
            if ($entry->isDir()) {
                $scan($path, $name);
            } elseif ($entry->isFile()) {
                $index[$name] = ['path' => $name, 'bytes' => $entry->getSize(), 'sha256' => hash_file('sha256', $path)];
                if ($zip && !$zip->addFile($path, $name)) {
                    throw new RuntimeException('archive_add');
                }
            } else {
                throw new RuntimeException('unsupported_file');
            }
        }
    };
    $scan($root, $prefix);
}

function packPrivateFiles(string $local, string $docs): void
{
    $index = [];
    $zip = new ZipArchive;
    if ($zip->open('/package/private.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true || !$zip->addEmptyDir('local') || !$zip->addEmptyDir('documents')) {
        throw new RuntimeException('archive_create');
    }
    foreach ([$local, $docs] as $root) {
        if (is_link($root) || (file_exists($root) && (!is_dir($root) || realpath($root) !== $root))) {
            throw new RuntimeException('disk_alias');
        }
    }
    if ($local === $docs || str_starts_with($local.'/', $docs.'/')) {
        throw new RuntimeException('overlapping_roots');
    }
    if (is_dir($local)) {
        scanDisk($local, 'local', $docs, $zip, $index);
    }
    if (is_dir($docs)) {
        scanDisk($docs, 'documents', null, $zip, $index);
    }
    if (!$zip->close()) {
        throw new RuntimeException('archive_close');
    }
    ksort($index, SORT_STRING);
    file_put_contents('/package/files.json', json_encode(array_values($index), JSON_THROW_ON_ERROR));
}

function inspectZip(bool $extract): void
{
    $index = readFileIndex('/package');
    $zip = new ZipArchive;
    if ($zip->open('/package/private.zip', ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('archive_invalid');
    }
    if ($extract) {
        if (iterator_count(new FilesystemIterator('/restore')) !== 0) {
            throw new RuntimeException('destination_not_empty');
        }
        mkdir('/restore/local', 0700);
        mkdir('/restore/documents', 0700);
    }
    $seen = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === 'local/' || $name === 'documents/') {
            continue;
        }
        if (!isset($index[$name]) || isset($seen[$name]) || $zip->statIndex($i)['size'] !== $index[$name]['bytes']) {
            throw new RuntimeException('archive_inventory');
        }
        $seen[$name] = true;
        $stream = $zip->getStream($name);
        if ($extract) {
            $dest = '/restore/'.$name;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0700, true);
            }
            $file = fopen($dest, 'xb');
            if (stream_copy_to_stream($stream, $file) !== $index[$name]['bytes']) {
                throw new RuntimeException('file_length');
            }
            fclose($file);
            $hash = hash_file('sha256', $dest);
        } else {
            $ctx = hash_init('sha256');
            if (hash_update_stream($ctx, $stream) !== $index[$name]['bytes']) {
                throw new RuntimeException('archive_length');
            }
            $hash = hash_final($ctx);
        }
        fclose($stream);
        if (!hash_equals($index[$name]['sha256'], $hash)) {
            throw new RuntimeException('file_checksum');
        }
    }
    if (count($seen) !== count($index)) {
        throw new RuntimeException('missing_archive_entry');
    }
    $zip->close();
}

set_error_handler(static function (): never { throw new RuntimeException('filesystem_io'); });
try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_required');
    }
    switch ($argv[1] ?? '') {
        case 'pack':
            packPrivateFiles($argv[2], $argv[3]);
            inspectZip(false);
            $result = checkInventory('/package/inventory.ndjson', readFileIndex('/package'));
            if ($result['missing'] || $result['different'] || $result['problems']) {
                file_put_contents('/package/integridad-incompleta.json', json_encode($result, JSON_THROW_ON_ERROR));
                throw new RuntimeException('source_integrity');
            }
            break;
        case 'unpack': inspectZip(true); break;
        case 'verify':
            $actual = [];
            scanDisk('/restore/local', 'local', null, null, $actual);
            scanDisk('/restore/documents', 'documents', null, null, $actual);
            ksort($actual, SORT_STRING);
            $index = readFileIndex('/package');
            ksort($index, SORT_STRING);
            $result = checkInventory('php://stdin', $actual);
            if (($argv[2] ?? '') === 'after-migration') {
                $source = checkInventory('/package/inventory.ndjson', $index);
                $result['history_matches_package'] = hash_equals($source['historical_hash'], $result['historical_hash']);
            }
            $result['all_files_match'] = $actual === $index;
            $result['private_files'] = count($actual);
            echo json_encode($result, JSON_THROW_ON_ERROR);
            break;
        default: throw new RuntimeException('unknown_command');
    }
} catch (Throwable $error) {
    // Never include record values, SQL, paths under the private disk, or credentials.
    fwrite(STDERR, "Error en la comprobación de archivos privados (".$error::class.").\n");
    exit(1);
}

