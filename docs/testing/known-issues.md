# Limitaciones y hallazgos conocidos

- **Límites de la devolución.** Solo se acreditan líneas originales completas. Las fracciones, descuentos libres, reembolsos automáticos y emisión real ante SUNAT quedan pendientes. Un resultado incierto conserva la reserva. Tras agotar los intentos de recuperación requiere intervención operativa.
- **Pendiente externo:** Mercado Pago sandbox no se ha verificado con cuenta test y URL publica autorizada. Un adaptador sintetico no demuestra checkout/webhook externo. Las cuentas de comercio y la cuenta de suscripciones de Revenia requieren verificación externa por separado.
- **Correo:** Mailpit confirma recepcion SMTP local. No se verifican llegada a buzones externos ni lectura.
- **Publicacion:** repositorio de demostracion, sin despliegue publico, carga productiva, clientes comerciales ni certificacion de seguridad.
- **Herramientas:** npm informa fin de soporte de ESLint 9; las auditorias ejecutadas no reportaron vulnerabilidades. No se incluyo un cambio mayor de herramientas para maquillar el resultado.
- **Pruebas:** no hay cobertura porcentual medida ni Larastan configurado. CI remoto debe observarse; no se sustituye por resultados locales.

Corregidos con evidencia: bypass local de Horizon (gate y regresion); Actualizar estado bloqueado al agotar cuota (regresion React); dependencia transitiva minimatch (override acotado y auditoria); fecha UTC del ejecutor E2E (ahora America/Lima). Los nombres facturly de infraestructura, cookies y claves de idempotencia se conservan intencionalmente.
