# API administrativa de Series

[OpenAPI 3.1 de Productos y Series](openapi.json).
Sanctum por cookie y cuenta/empresa activas. Solo owner puede acceder; issuer y
accountant reciben 403 en recursos propios y listado. Sin sesión: 401. UUID
ajeno/inexistente o mal formado: 404. Validación: 422. CSRF vencido: 419.
Antes de escribir: GET /sanctum/csrf-cookie y cabecera X-XSRF-TOKEN.

| Método | Ruta | Respuesta |
| --- | --- | --- |
| GET | /api/v1/series | 200 colección paginada |
| GET | /api/v1/series/{id} | 200 detalle |
| POST | /api/v1/series | 201 creada activa, contador 0 |
| PATCH | /api/v1/series/{id} | 200 cambio de estado |

Filtros opcionales: doc_type=01/03/07 e is_active=1/0. page desde 1 y per_page
entre 1 y 100, por defecto 15. Orden estable doc_type, code, id. Respuesta data,
links y meta Laravel (current_page, last_page, per_page, total, entre otros).
Fuera del rango: lista vacía; React reajusta página cuando cambia el total.
company_id está prohibido en todas las rutas: la empresa procede de la sesión.

Creación:

```json
{"doc_type":"01","code":" f001 "}
```

Devuelve code F001, current_number 0, is_active true. Tipos string 01/03/07;
cuatro caracteres ASCII alfanuméricos con prefijos F/B según ADR-016.
No se aceptan id, company_id, current_number, is_active ni fechas en POST.
Duplicado de empresa/tipo/código devuelve errors.code incluso ante conflicto SQL;
otros errores SQL no se traducen a duplicados.

Actualización:

```json
{"is_active":false}
```

is_active es obligatorio. id, company_id, doc_type, code, current_number y fechas
se rechazan incluso enviados como null. Otros campos desconocidos se ignoran,
sin persistirse. Tipo/código son inmutables y no hay correlativo inicial configurable.
Activar/desactivar conserva el contador y la fila; no existe DELETE.

Resource explícito: id, doc_type, code, current_number (entero), is_active,
created_at, updated_at. No incluye company_id. No existe endpoint de incremento.

Contrato interno, excepciones y orden de bloqueos:
[ADR-016](../adr/ADR-016-series-y-correlativos-transaccionales.md).
