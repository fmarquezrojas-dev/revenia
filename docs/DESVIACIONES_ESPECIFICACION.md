# Diferencias respecto al alcance esperado

La [matriz de estado](ESTADO_PROYECTO.md) es la referencia vigente.

- Notas de credito: no existen dominio 07, reservas ni acreditacion. El dashboard informa esa ausencia y no calcula emision neta con notas.
- Emision: proveedor local simulado, sin conexion/certificacion SUNAT ni validez tributaria.
- Cobranza: adaptador sandbox y contratos disponibles, pero checkout y webhook externos no verificados con una cuenta autorizada. La prueba interna usa un gateway sintetico.
- Suscripciones: planes ficticios, periodo mensual y pago unico. No hay debito recurrente, prorrateo ni devoluciones automaticas.
- Operacion: Compose de desarrollo; no se entrega despliegue publico ni pruebas de carga productiva.
- Marca: Revenia conserva identificadores operativos facturly para evitar crear bases/volumenes/sesiones incompatibles.

No se presenta ningun pendiente como funcionalidad finalizada. [Limitaciones](testing/known-issues.md).
