# ADR-018: Dinero exacto, snapshots y version de borradores

Fecha: 2026-11-11. Decision: sin dependencia decimal adicional.
Money/Currency, Quantity y TaxCalculator son independientes de Laravel/Eloquent.
Se reutilizan MAX_PRICE_CENTS (ahora alias del limite Money), RucValidator y los
catalogos existentes. No se invoca ReserveNextNumber desde borradores.

Cantidad maxima: 1000000.000, internamente 1000000000 milesimas; precio y total
maximos: 9007199254740991 centimos. La multiplicacion se descompone en cociente
y resto antes de multiplicar para evitar overflow incluso al usar precios maximos
con cantidades fraccionarias. Cada suma/multiplicacion comprueba su limite antes
de operar. Resto por cantidad y resto para IGV estan acotados por construccion.
Base e IGV redondean mitad hacia arriba por linea. Suma final de impuestos de
linea. Casos A-E de la especificacion cubiertos con valores esperados independientes.

Una linea por producto, 1..50; cantidades string; precios no editables en el MVP.
IDs de linea identifican snapshots: cambiar solo cantidad conserva los datos;
reemplazar producto los toma del catalogo actual. No se recrean todas las lineas.
Restricciones unicas diferidas permiten reordenar/reemplazar IDs atomicamente.

Documentos e items contienen company_id. FKs compuestas a cliente, creador,
serie, documento y producto impiden mezclar empresas directamente en SQL.
Los indices de referencia se agregan sin cambiar registros existentes.
El servicio bloquea documento primero, luego referencias compartidas (serie,
cliente, productos ordenados por ID). Preview usa la misma transaccion de lectura
y las mismas reglas; no escribe. Guardado elimina solo items retirados y conserva
las identidades restantes. Version aumenta una vez, desde1; 409 para version
obsoleta o estado distinto de draft. DELETE valida ambos antes de eliminar.

Estado issued reservado en el CHECK para el futuro, sin endpoint de transicion.
En draft number/issued_at son null. No cuotas, emision, PDF ni SUNAT.
Politica de cliente: boleta PEN anonima hasta umbral inclusive de70000 inicial;
USD identificada. Serie: selector limitado owner/issuer separado de administracion.
Referencias inactivas se consultan pero bloquean guardado hasta ser reemplazadas.

Ver [contrato](../api/comprobantes.md) y [modelo](../MODELO_DATOS.md).

Cambiar moneda no reinterpreta snapshots existentes aunque cambie el catalogo.
Una linea retenida del mismo producto conserva la moneda de su documento original;
se debe reemplazar por otro producto o quitar y agregar explicitamente una nueva
linea del catalogo actual. La respuesta incluye currency del snapshot y
product_currency actual para distinguirlos.
