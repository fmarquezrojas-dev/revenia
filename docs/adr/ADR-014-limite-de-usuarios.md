# ADR-014: Límite de usuarios por empresa

## Estado

Implementado para el MVP.

## Decisión

Cada empresa puede registrar hasta cinco usuarios en total.

El límite incluye al dueño y a los usuarios desactivados.
Desactivar una cuenta no libera un espacio.

Solo un dueño activo de una empresa activa puede crear usuarios.
La empresa del nuevo usuario se obtiene de la sesión.

## Integridad

La creación bloquea la fila de la empresa dentro de una transacción.
El cupo se consulta y el usuario se crea dentro de esa transacción.

Las futuras operaciones de administración de usuarios deberán
respetar el mismo orden de bloqueo.

El correo es único globalmente.

## Verificación

Las pruebas cubren permisos, aislamiento, validación y límite secuencial.
La comprobación con solicitudes concurrentes queda pendiente.

## Consecuencia

La reactivación reutilizará una cuenta existente.
La eliminación de usuarios no forma parte del MVP.