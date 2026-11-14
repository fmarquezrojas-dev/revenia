<?php

use App\Actions\Documents\DraftDocuments;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

$connection = require __DIR__.'/bootstrap.php';
[$script,$actorId,$documentId,$directory,$worker] = $argv;
$actor = User::findOrFail($actorId);
$doc = Document::with('items')->findOrFail($documentId);
$data = ['version' => 1, 'doc_type' => $doc->doc_type, 'currency' => $doc->currency, 'issue_date' => $doc->issue_date->format('Y-m-d'), 'customer_id' => $doc->customer_id, 'series_id' => $doc->series_id,
    'items' => [['id' => $doc->items[0]->id, 'product_id' => $doc->items[0]->product_id, 'quantity' => '2']]];
$pid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
file_put_contents($directory.'/ready-'.$worker, (string) $pid);
$deadline = microtime(true) + 60;
while (! file_exists($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    } usleep(10000);
}
try {
    $connection->transaction(function () use ($actor, $data, $documentId): void {
        (new DraftDocuments)->save($actor, $data, $documentId);
        usleep(300000);
    });
    $status = 200;
    $code = null;
} catch (HttpResponseException $e) {
    $status = $e->getResponse()->getStatusCode();
    $code = json_decode($e->getResponse()->getContent(), true)['code'];
}
echo json_encode(compact('pid', 'status', 'code'), JSON_THROW_ON_ERROR);
