# ADR-015: Moneda y precio del catálogo

Fecha: 10 de noviembre de 2026. Estado: aceptado e implementado.

## Contexto
La solicitud identifica PEN y USD para comprobantes, sin moneda asignada al
precio del producto. No existe tabla products previa ni conversor Money en el
repositorio inspeccionado. ADR-014 era el último ADR disponible.

## Decisión
Cada producto o servicio tiene currency PEN o USD y unit_price_cents entero,
sin IGV. No hay conversión automática, tipos de cambio ni comprobantes en este bloque.
Los futuros comprobantes solo seleccionarán productos de su misma moneda.
NIU representa bienes y ZZ servicios; tax_type es texto: 10 gravado, 20 exonerado,
30 inafecto. Estas unidades son el alcance simplificado solicitado.

Máximo: 9007199254740991 céntimos (90071992547409.91 unidades monetarias),
Number.MAX_SAFE_INTEGER, inferior a BIGINT PostgreSQL y PHP entero de 64 bits.
La API exige un entero JSON: rechaza strings, booleanos, fracciones y valores
fuera de rango. La representación léxica JSON no se conserva tras decodificar;
los clientes deben enviar enteros sin notación exponencial.
La base impone también ambos límites. Se requiere PHP de 64 bits.

El formulario recibe texto, recorta espacios exteriores, admite punto o coma
y uno o dos decimales. No admite signo, separadores de miles, exponentes,
fracciones sin parte entera, separador sin decimales ni redondeo implícito.
Ejemplos: 10 -> 1000; 10.50 o 10,50 -> 1050; 0.35 -> 35; 0 -> 0.
Se concatenan dígitos y se compara longitud/orden con el máximo antes de Number.
El formato inverso usa división y resto BigInt, sin aritmética decimal flotante.

Códigos: trim exterior y mayúsculas Unicode en el servidor; conservan ceros,
signos y espacios interiores. Unicidad por empresa y código normalizado.
La restricción SQL evita duplicados simultáneos; solo su conflicto se convierte
 en 422, otros errores SQL se propagan. No hay eliminación física.

## Compatibilidad e historia
La migración crea una tabla nueva: no requiere rellenar moneda de registros
anteriores. No existe integración con comprobantes en el código inspeccionado.
Cuando exista, cada ítem guardará snapshots de descripción, unidad, precio,
moneda y afectación. Editar o desactivar el catálogo no alterará documentos emitidos.
Los snapshots y la restricción de moneda del comprobante quedan previstos,
no implementados ni verificados aquí.
