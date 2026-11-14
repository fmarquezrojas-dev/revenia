PDF privado, correo, permisos y errores: [contrato de entrega](../PDF_Y_CORREO.md).

# Comprobantes y emision simulada

Emision, reintento, estados e historial: [contrato y operacion](../EMISION_SIMULADA.md).
El contrato de borradores siguiente se conserva; los numerados son inmutables.

# Comprobantes: borradores del simulador

Contrato: OpenAPI 1.4.0 en openapi.json. Autenticacion Sanctum por cookies;
CSRF en POST/PATCH/DELETE. Owner e issuer escriben/previsualizan; accountant
solo lista y consulta. Referencias ajenas/no existentes: 404; accion propia
sin permiso: 403; sin sesion: 401; validacion: 422; conflictos: 409.

## Crear

POST /api/v1/documents, respuesta 201 {data: documento}:

```json
{"doc_type":"01","currency":"PEN","issue_date":"2026-11-11",
 "customer_id":"UUID_CLIENTE","series_id":"UUID_SERIE",
 "items":[{"product_id":"UUID_PRODUCTO","quantity":"3.000"}]}
```

Serie activa del mismo tipo. Cliente activo obligatorio con RUC valido para
factura. Solo 01/03. Fecha no futura en America/Lima. Entre 1 y 50 productos
unicos de la moneda seleccionada. Precio, descripcion, unidad y afectacion se
obtienen del servidor. Empresa y creador proceden de la sesion.
Se rechazan company_id, created_by, status, number, issued_at y todos los totales,
incluso null; tampoco se aceptan snapshots arbitrarios en items.

## Editar, previsualizar, eliminar

PATCH /api/v1/documents/{id} recibe TODOS los campos de creacion mas version.
Cada linea existente envia su id; el mismo producto conserva su snapshot al
cambiar cantidad. Otro producto toma catalogo actual conservando el ID de linea.
Las lineas omitidas se eliminan; las nuevas no tienen id. Producto unico.

POST /api/v1/documents/preview usa ese mismo payload; para editar agrega
document_id y version. Para alta omite ambos y todos los IDs de linea.
Devuelve {data:{items,totals,version,number:null,status:"draft"}}. No escribe,
reserva numeros, incrementa version ni consume cuotas. Las referencias deben
estar activas tambien en preview. El guardado vuelve a calcular, no confia en
un resultado anterior de preview.

DELETE /api/v1/documents/{id} envia JSON {"version":1}; respuesta 204.
Actualizacion/eliminacion bloquean el documento antes de validar estado/version.
409 incluye code document_version_conflict o document_not_draft y message.
La interfaz conserva cambios locales; recargar requiere una decision explicita.

## Consulta y seleccion

GET /api/v1/documents: doc_type, status, customer_id, date_from, date_to,
page y per_page (1..100, defecto15). Fechas inclusivas; orden issue_date DESC,
id DESC; envelope data/meta/links de Laravel.
GET /api/v1/documents/{id}: incluye referencias, lineas con IDs/snapshots/importes,
version y totales. Los borradores tienen status draft, number null e issued_at null. Los numerados exponen el estado y datos historicos descritos en EMISION_SIMULADA.md. Referencias inactivas
siguen visibles, pero deben reemplazarse antes de guardar. Las referencias fiscales
siguen siendo datos actuales; su congelamiento definitivo pertenece a la emision.

GET /api/v1/document-series-options?doc_type=01 devuelve exclusivamente id/code/
doc_type de series activas propias compatibles. Disponible a owner/issuer, no
accountant. No abre las rutas administrativas de Series al emisor.

## Identificacion del simulador

config/documents.php define anonymous_pen_threshold_cents, inicial 70000.
Boleta PEN sin cliente permitida hasta el umbral INCLUSIVE; mayor exige cliente.
Boleta USD siempre exige cliente: no se convierte el umbral. null representa
anonimo; no se crea cliente ficticio. Son politicas del simulador y no una
validacion de normativa tributaria vigente.

## Importes

Cantidad string, punto, 0.001..1000000.000; salida tres decimales. Sin exponentes,
signos, miles ni mas de tres decimales. Frontend admite coma y normaliza.
Precio sin IGV: entero 0..9007199254740991 centimos, mismo limite del catalogo.
Base por linea: cantidad por precio, mitad hacia arriba al centimo. IGV 18% sobre
esa base redondeada solo para 10; 20/30 cero. Total suma bases e IGV de cada linea,
no IGV recalculado globalmente. Cada importe/total tiene el mismo maximo seguro JS.
Si la multiplicacion, IGV o suma no cabe, 422; no se usa float en ningun paso.

Cambiar moneda no reinterpreta snapshots existentes aunque cambie el catalogo.
Una linea retenida del mismo producto conserva la moneda de su documento original;
se debe reemplazar por otro producto o quitar y agregar explicitamente una nueva
linea del catalogo actual. La respuesta incluye currency del snapshot y
product_currency actual para distinguirlos.


## Acciones del listado

La interfaz ofrece Ver detalle a todos los roles y Editar/Eliminar a owner e
issuer solo para draft. Eliminar usa el mismo DELETE con version de la fila,
confirmacion y bloqueo de doble envio. Un409 conserva la fila y permite recargar
explicitamente; nunca elimina automaticamente con una version distinta.
