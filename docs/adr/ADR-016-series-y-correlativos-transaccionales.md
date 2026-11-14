# ADR-016: Series y correlativos transaccionales

Fecha: 10 de noviembre de 2026. Estado: implementado para el simulador.

## Nomenclatura y persistencia

Se crea document_series (no había tabla ni numerador previo). Cada fila pertenece
a una empresa y contiene doc_type, code, current_number e is_active.

| Tipo | Prefijo | Ejemplo |
| --- | --- | --- |
| 01 Factura | F | F001 |
| 03 Boleta | B | B001 |
| 07 Nota de crédito para factura | F | FC01 |
| 07 Nota de crédito para boleta | B | BC01 |

Son reglas simplificadas del simulador, no validación completa de SUNAT.
El código se normaliza con trim exterior de PHP y strtoupper ASCII; luego debe
tener exactamente cuatro caracteres ASCII alfanuméricos y el prefijo indicado.
No se eliminan espacios interiores ni se transliteran letras Unicode.
SeriesCode aplica la regla en PHP; CHECK con COLLATE C y rangos explícitos ASCII
aplica la misma regla canónica en PostgreSQL. Escrituras SQL que no pasen por la
normalización deben proporcionar el código ya normalizado o serán rechazadas.

Unicidad: company_id + doc_type + code. F001 puede existir en 01 y 07 dentro de
la misma empresa, y en otras empresas. Desactivar no libera la combinación.
No hay DELETE ni soft deletes. La API y la Action administrativa no permiten
cambiar tipo, código, empresa o contador. Un error de serie se corrige desactivando
y creando otra. Una importación requerirá un flujo auditado distinto, aún no creado.

El contador BIGINT inicia en 0 y admite hasta 99999999. Ese rango es exacto en
PostgreSQL, PHP y JavaScript. No existe endpoint de reserva, incremento o reinicio.
El dueño administra; emisor y contador no consultan este módulo. El futuro selector
para emisión será otra capacidad con campos y permisos limitados.

## Contrato transaccional

`ReserveNextNumber::handle(Connection $connection, string $companyId, string $seriesId): int`

- La conexión debe tener una transacción Laravel abierta y una transacción PDO real;
  sin ambas condiciones lanza LogicException antes de consultar la serie.
- El contexto de empresa debe proceder de una autorización previa del llamador.
  Esta primitiva interna no concede permisos ni sustituye la autorización de emitir.
- Consulta document_series por empresa e id y ejecuta SELECT FOR UPDATE en esa
  misma conexión. Serie ajena/inexistente: ModelNotFoundException.
- Después de obtener el bloqueo comprueba actividad y agotamiento: DomainException.
- Incrementa exactamente una vez por llamada, actualiza updated_at y devuelve el
  entero asignado. No abre ni confirma otra transacción, no reintenta internamente,
  no usa redes ni publica jobs. Un rollback del llamador revierte el incremento.
- El bloqueo persiste hasta commit/rollback del llamador. La administración de
  estado bloquea la misma fila: desactivar y asignar se serializan.

NO usar la primitiva en una transacción aislada que confirme solo el contador en
producción o desarrollo. Las pruebas dedicadas son la única invocación actual.
Todavía no hay emisión idempotente. La futura emisión deberá confirmar asignación,
documento e ítems/snapshots, cuota, evento, idempotencia y outbox juntos en la misma
conexión y transacción. Los reintentos serán de esa unidad completa y resolverán
la clave de idempotencia antes de volver a asignar. La red se ejecutará fuera
de esa transacción a través del flujo de outbox futuro.

## Orden de bloqueos previsto para emisión (no implementada)

1. Empresa y su control de cuota, en ese orden; compatible con ADR-014.
2. Registro de idempotencia de la empresa/operación (crear o bloquear).
3. Documento/borrador existente, si corresponde.
4. Serie; si una operación requiere varias, orden por doc_type, code e id.
5. Escritura de documento, ítems, consumo de cuota, evento y outbox; un commit.

El futuro módulo debe concretar sus tablas y verificar este orden en todos sus
caminos. No debe bloquear primero una serie y luego empresa/borrador. La
administración actual bloquea solo la serie y no adquiere después esos bloqueos.

## Estados que no deben confundirse

- Serie creada: configuración activa con contador 0; UI muestra Sin emisiones.
- Número asignado: entero reservado en una transacción; provisional hasta commit.
- Documento emitido: futura operación que persistirá el documento y sus efectos.
- Documento aceptado por el simulador: futuro resultado posterior a la emisión.

Los dos últimos estados no están implementados. DocumentNumber es un valor PHP
independiente de Laravel: valida tipo/código y número 1..99999999 y formatea
F001-00000001. Cero nunca representa un comprobante. La UI solo muestra el
último asignado, sin prometer el siguiente. Documentos rechazados u otros estados
conservarán su número: no se promete una secuencia sin saltos entre aceptados.

Ver [contrato API](../api/series.md) y [prueba de concurrencia](../PRUEBAS_CONCURRENCIA_SERIES.md).
