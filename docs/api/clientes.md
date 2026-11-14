# API de Clientes

Contrato implementado el 10 de noviembre de 2026. Prefijo: `/api/v1/customers`.

## Sesión y permisos

Se utiliza la sesión Sanctum existente, cookies y CSRF. Las escrituras necesitan
`X-XSRF-TOKEN`, obtenido después de `GET /sanctum/csrf-cookie`.
La cuenta y su empresa deben estar activas.

| Rol | Listado y detalle | Crear, editar, desactivar y reactivar |
| --- | --- | --- |
| Dueño | Sí | Sí |
| Emisor | Sí | Sí |
| Contador | Sí | No |

La empresa procede siempre de la sesión. El listado y cada resolución de UUID
se restringen por `company_id` antes de autorizar o escribir.
Sin sesión: 401. Operación prohibida sobre un recurso propio: 403.
UUID ajeno, inexistente o mal formado: 404.
No existe una operación de eliminación física.

## Endpoints

| Método | Ruta | Respuesta |
| --- | --- | --- |
| GET | /api/v1/customers | 200, colección paginada |
| GET | /api/v1/customers/{id} | 200, detalle |
| POST | /api/v1/customers | 201, cliente creado activo |
| PATCH | /api/v1/customers/{id} | 200, cliente actualizado |

### Listado

Parámetros opcionales:

- `q`: texto hasta 200 caracteres, búsqueda parcial sin distinguir mayúsculas
  por nombre o documento. Se recortan espacios exteriores. Los caracteres
  `%`, `_` y barra invertida se buscan literalmente.
- `is_active`: `1` para activos, `0` para inactivos; omitir para todos.
- `page`: entero desde 1.
- `per_page`: entero entre 1 y 100; predeterminado 15.

Orden estable por `name` y luego `id`. La respuesta incluye `data`,
`links` y `meta` de paginación Laravel: `current_page`, `last_page`,
`per_page` y `total`, entre otros. Una página fuera del rango devuelve
una colección vacía; React ajusta la página si disminuye el total.
El parámetro `company_id` se rechaza con 422.

Ejemplo: `GET /api/v1/customers?q=00123456&is_active=1&page=1&per_page=15`.

### Creación

```json
{
  "doc_type": "dni",
  "doc_number": "00123456",
  "name": "Cliente de ejemplo",
  "address": null,
  "email": null
}
```

Obligatorios: `doc_type`, `doc_number`, `name`.
Opcionales: `address`, `email`. El servidor asigna UUID, empresa,
estado activo y fechas. No enviar `is_active` al crear.

### Edición y estado

PATCH admite modificaciones parciales de los mismos campos y de `is_active`.
Para borrar dirección o correo, enviar `null` o cadena vacía.
Para desactivar: `{"is_active": false}`; para reactivar: `{"is_active": true}`.
El cambio de estado mantiene el registro y la reserva del documento.

Se rechazan `company_id`, `id`, `created_at`, `updated_at`.
Otros campos desconocidos se ignoran: solo se escriben datos validados.
El Resource devuelve explícitamente `id`, `doc_type`, `doc_number`,
`name`, `address`, `email`, `is_active`, `created_at`, `updated_at`.
No expone `company_id`.

## Validación y normalización

- Tipos: `ruc`, `dni`, `other`; se normaliza el tipo a minúsculas.
- Documento: siempre string, hasta 20 caracteres. Los números JSON se rechazan;
  se conservan ceros iniciales. Se recortan espacios exteriores, se reducen
  secuencias de espacios a uno y se convierte a mayúsculas.
- RUC: 11 dígitos ASCII; prefijos del simulador 10, 15, 16, 17 y 20.
- DNI: exactamente 8 dígitos ASCII.
- Otro: texto obligatorio no vacío después de normalizar.
- Nombre: texto obligatorio hasta 200 caracteres, recortado.
- Dirección: opcional, hasta 300 caracteres.
- Correo: opcional, formato email, hasta 200 caracteres.
- Estado: booleano en PATCH.

### Dígito verificador del RUC

`App\Domain\Customers\RucValidator` no depende de Laravel.
Multiplica los primeros diez dígitos por los pesos
`[5,4,3,2,7,6,5,4,3,2]`. El verificador es
`(11 - (suma % 11)) % 10` (10 se transforma en 0; 11 en 1).
Se compara con el último dígito.

No había una especificación previa ni un validador reutilizable; la fórmula
coincide con la usada por la fábrica de empresas existente.
Las pruebas usan ejemplos públicos fijos, no solo valores generados:
20131312955, 20600044975, 20555195444, 20100070970 y 20434327611.
Fuente de esos ejemplos:
[Relación de agentes de retención publicada por SUNAT](https://ww1.sunat.gob.pe/descarga/AgentRet/AgenRet326.html).

Validar matemáticamente el RUC **no acredita existencia, actividad ni condición
en SUNAT**. Este módulo no realiza consultas externas.

## Integridad y errores

La base aplica una FK a companies, UUID, un CHECK de tipos y la restricción
`customers_company_document_unique` sobre
`(company_id, doc_type, doc_number)`. Un documento puede existir en empresas
distintas o con tipos distintos. Los inactivos también participan en la unicidad.

Hay índices por empresa/nombre/id y empresa/estado/nombre/id.
La actualización bloquea el registro dentro de una transacción y revalida
el par tipo/número vigente antes de guardar una edición parcial.
La restricción única protege incluso si dos solicitudes superan la
prevalidación; únicamente ese conflicto se convierte en 422.
Otros errores SQL se propagan.

Ejemplo de error de duplicado:

```json
{
  "message": "Ya existe un cliente con este tipo y número de documento en tu empresa.",
  "errors": {
    "doc_number": ["Ya existe un cliente con este tipo y número de documento en tu empresa."]
  }
}
```

React conserva los campos ante errores, presenta validación por campo y maneja
401, 403, 404, 419, 422 y fallos de conexión. La búsqueda espera 300 ms,
aborta solicitudes anteriores e ignora respuestas antiguas aunque el transporte
no respete la cancelación.
