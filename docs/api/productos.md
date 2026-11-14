# API de Productos y servicios

Contrato OpenAPI: [openapi-products.json](openapi-products.json).
Sesiones Sanctum existentes, cookies y CSRF: antes de escribir consultar
`/sanctum/csrf-cookie` y enviar `X-XSRF-TOKEN`. Cuenta y empresa activas.

| Método | Ruta | Resultado |
| --- | --- | --- |
| GET | /api/v1/products | Colección paginada, 200 |
| GET | /api/v1/products/{id} | Detalle, 200 |
| POST | /api/v1/products | Creado activo, 201 |
| PATCH | /api/v1/products/{id} | Actualizado, 200 |

Dueño y emisor escriben y consultan; contador solo consulta.
401 sin sesión, 403 operación prohibida propia, 404 UUID ajeno/inexistente o
mal formado, 422 validación; 419 CSRF vencido. No existe DELETE.
La empresa procede de sesión; company_id se rechaza, incluso si es null.
id y fechas tampoco se aceptan en escrituras. Campos desconocidos se ignoran
sin persistirlos. Los Resources no exponen company_id.

Filtros opcionales: q hasta 200 caracteres (código/descripción parcial,
insensible a mayúsculas; %, _ y barra invertida literales), is_active 0/1,
currency PEN/USD, tax_type 10/20/30. page >= 1, per_page 1..100 (15 por defecto).
Orden estable por description e id. Respuesta data, links, meta Laravel,
incluyendo current_page, last_page, per_page y total. Páginas fuera de rango
retornan data vacío; React reajusta la página.

POST exige code, description, unit_code, currency, unit_price_cents, tax_type.
PATCH permite cualquiera de esos campos y/o is_active; no exige reenviar todo.
Desactivar/reactivar es PATCH con is_active false/true y conserva el registro.

```json
{"code":"001-SERV","description":"Servicio de soporte","unit_code":"ZZ","currency":"PEN","unit_price_cents":1050,"tax_type":"10"}
```

Precio entero sin IGV, entre 0 y 9007199254740991 inclusive; tax_type es string.
Código hasta 30 caracteres y descripción hasta 250.
Ver [ADR-015](../adr/ADR-015-moneda-y-precio-del-catalogo.md) para normalización,
conversión decimal, moneda y futura integridad histórica.
Guardar usa la respuesta del servidor en detalle e invalida/refresca el listado
respetando filtros; cancelar desmonta el formulario y descarta sus valores.
