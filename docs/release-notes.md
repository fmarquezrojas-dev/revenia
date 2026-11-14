# Notas propuestas: v0.1.0-rc.1

Candidata del alcance de portafolio; no es una release estable v1.0.0 ni un servicio desplegado.

## Alcance

API Laravel y SPA React multiempresa: sesion, roles, catalogos, series, borradores, importes exactos, emision simulada, outbox, PDF privado, correo Mailpit, cobranza test, planes/cuotas y dashboard responsive. Incluye pruebas de concurrencia y recuperacion, fixtures sinteticos y documentacion de arquitectura.

## Inicio

Docker Compose y PowerShell. Instalar desde lockfiles, generar APP_KEY solo en copia nueva, migrar y preparar dataset con `php scripts/prepare-demo.php`. [Instrucciones](development.md).

## Verificacion

Consultar [informe](testing/test-report.md) y los runs de Actions del commit. No se anuncia cobertura no medida ni se confunde gateway falso con proveedor externo.

## Limitaciones

Sin SUNAT real, reglas tributarias simplificadas, sin clientes comerciales acreditados. Notas de credito no implementadas. Mercado Pago externo pendiente. Correo solo Mailpit. Una cuenta receptora sandbox compartida no es soporte comercial multicomercio. No hay renovacion automatica ni reembolsos automaticos.

Esta nota se puede conservar como borrador hasta revisar el estado real de CI y las verificaciones externas. Publicar codigo no implica publicar una release estable.
