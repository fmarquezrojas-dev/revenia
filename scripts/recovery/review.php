<?php

declare(strict_types=1);

// Router of the isolated inspection service. Never dispatch a request to Laravel or a filesystem path.
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; form-action 'none'; base-uri 'none'");
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!in_array($path, ['/', '/resultado.json'], true)) {
    http_response_code(404);
    exit;
}
$expected = @file_get_contents('/run/viewer.sha256');
if ($expected === false || ($_SERVER['PHP_AUTH_USER'] ?? '') !== 'operador'
    || !hash_equals(trim($expected), hash('sha256', $_SERVER['PHP_AUTH_PW'] ?? ''))) {
    header('WWW-Authenticate: Basic realm="Revenia - Recuperacion", charset="UTF-8"');
    http_response_code(401);
    exit;
}
$report = @file_get_contents('/reports/resultado.json');
if ($report === false) {
    http_response_code(503);
    echo 'La comprobación todavía está pendiente.';
    exit;
}
if ($path === '/resultado.json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo $report;
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
$data = json_decode($report, true, flags: JSON_THROW_ON_ERROR);
function esc(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="es"><meta charset="utf-8"><title>Revenia · Respaldo y restauración</title>
<main><h1>Respaldo y restauración</h1><p>Instancia aislada de revisión · <?= esc($data['project']) ?></p>
<dl><?php foreach (['package'=>'Integridad del paquete','postgres'=>'Restauración de PostgreSQL','files'=>'Archivos privados','relations'=>'Relaciones','baseline'=>'Comparación con el respaldo','functional'=>'Validación funcional'] as $key=>$label): ?>
<dt><?= esc($label) ?></dt><dd><?= esc($data[$key]) ?></dd><?php endforeach ?></dl>
<p><a href="/resultado.json">Consultar el informe completo con los recuentos por empresa y las migraciones</a></p></main></html>

