# Operacion y recuperacion

El estado de negocio se consulta en PostgreSQL. API, worker y scheduler deben compartir base, Redis, prefijos y almacenamiento privado. Cambiar APP_NAME no debe cambiar cookie, prefijos o identidad Compose; esos valores heredados estan fijados en configuracion.

## Emision y outbox

`issuance:tick` publica outbox y recupera intentos cuyo lease vencio. Una cola inaccesible deja trabajo pendiente. Un trabajo duplicado se valida contra estado/version; un resultado tardio no reemplaza un intento nuevo. Los reintentos estan limitados. Consultar eventos y resultado del simulador antes de intervenir.

```powershell
docker compose exec -T app php artisan issuance:tick
docker compose logs --tail 80 worker scheduler
```

No reiniciar series ni modificar un documento aceptado para resolver un fallo de transporte. No eliminar filas de outbox o consumo manualmente.

## PDF y correo

Los archivos estan en el disco documents privado; no ejecutar storage:link para exponerlos. Regenerar desde la UI conserva el PDF anterior hasta tener uno nuevo y requiere permisos. Un PDF fallido no cambia la aceptacion tributaria.

Correo sent significa aceptacion por SMTP, no lectura. Un resultado unknown requiere revisar el transporte por Message-ID antes de usar el comando existente `delivery:resolve`; no reenviar a ciegas. [Reglas y comandos detallados](PDF_Y_CORREO.md).

## Pagos y planes

`payments:reconcile --tick --limit=50` procesa trabajos pendientes. La consulta al proveedor es autoritativa; el retorno del navegador no activa derechos. Excesos, cambios contradictorios, reembolsos y pagos tardios pueden requerir revision. No hay reembolso automatico. `subscriptions:maintain` audita consumo; no sustituye una verificacion externa sandbox.

## Limites de operacion

Compose usa servidores de desarrollo, SMTP local y secretos sinteticos. Publicar el codigo no despliega un servicio. Una instancia publica necesita HTTPS, APP_DEBUG=false, secretos propios, cookies Secure, origen configurado, backups/restauracion, supervisores y politica de retencion. No se afirma disponibilidad ni recuperacion ante desastre verificada.
