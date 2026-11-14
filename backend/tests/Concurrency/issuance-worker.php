<?php

use App\Actions\Delivery\GenerateDocumentPdf;
use App\Actions\Issuance\EmissionOutbox;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Contracts\TaxProvider;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentPdfRenderer;
use App\Services\SimulatedTaxProvider;
use Illuminate\Database\Connection;
use Illuminate\Http\Exceptions\HttpResponseException;

$connection = require __DIR__.'/bootstrap.php';
[$script,$mode,$actorId,$documentId,$key,$directory,$worker] = $argv;
$pid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
file_put_contents($directory.'/ready-'.$worker, (string) $pid);
$deadline = microtime(true) + 90;
while (! file_exists($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    }usleep(10000);
}
try {
    if ($mode === 'issue') {
        $connection->transaction(function () use ($actorId, $documentId, $key): void {
            app(RequestIssuance::class)->handle(User::findOrFail($actorId), $documentId, 1, $key);
            usleep(300000);
        });
        $status = 202;
    } elseif ($mode === 'job') {
        app()->bind(TaxProvider::class, function () use ($connection): TaxProvider {
            return new class($connection) implements TaxProvider
            {
                public function __construct(private Connection $connection) {}

                public function lookup(string $id): ?array
                {
                    if ($this->connection->transactionLevel() !== 0) {
                        throw new RuntimeException('Provider called inside transaction');
                    }

                    return (new SimulatedTaxProvider)->lookup($id);
                }

                public function submit(string $id, array $payload): array
                {
                    if ($this->connection->transactionLevel() !== 0) {
                        throw new RuntimeException('Provider called inside transaction');
                    }

                    return (new SimulatedTaxProvider)->submit($id, $payload);
                }
            };
        });
        app(ProcessEmission::class)->handle(Document::findOrFail($documentId)->company_id, $documentId, 2);
        $status = 200;
    } elseif ($mode === 'pdf') {
        app()->bind(DocumentPdfRenderer::class, function () use ($connection): DocumentPdfRenderer {
            return new class($connection) extends DocumentPdfRenderer
            {
                public function __construct(private Connection $connection) {}

                public function render(Document $document): string
                {
                    if ($this->connection->transactionLevel() !== 0) {
                        throw new RuntimeException('Rendering inside a transaction');
                    }

                    return parent::render($document);
                }
            };
        });
        app(GenerateDocumentPdf::class)->handle(Document::findOrFail($documentId)->company_id, $documentId, 1);
        $status = 200;
    } else {
        config(['issuance.queue_connection' => 'database', 'delivery.queue_connection' => 'database']);
        app(EmissionOutbox::class)->publish();
        $status = 200;
    }
} catch (HttpResponseException $e) {
    $status = $e->getResponse()->getStatusCode();
}
echo json_encode(compact('pid', 'status'), JSON_THROW_ON_ERROR);
