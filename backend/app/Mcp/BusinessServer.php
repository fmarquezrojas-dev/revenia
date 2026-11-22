<?php

namespace App\Mcp;

use App\Mcp\Tools\ConsultarComprobante;
use App\Mcp\Tools\ListarComprobantesPendientes;
use App\Mcp\Tools\ResumenCobranza;
use Laravel\Mcp\Server;

class BusinessServer extends Server
{
    protected string $name = 'Consultas de Revenia';

    protected string $version = '1.0.0';

    protected string $instructions = 'Consulta local de solo lectura para la empresa autenticada. Los importes se expresan en céntimos de la moneda indicada.';

    protected array $tools = [ResumenCobranza::class, ListarComprobantesPendientes::class, ConsultarComprobante::class];
}
