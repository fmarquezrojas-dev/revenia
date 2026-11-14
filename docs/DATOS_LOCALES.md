# Datos sinteticos de demostracion

`DemoCompaniesSeeder` crea Demo Alfa y Demo Beta con owner, issuer y accountant mediante firstOrCreate. El script `backend/scripts/prepare-demo.php` verifica entorno local, PostgreSQL efectivo facturly/facturly, host db, controles del simulador y Mailpit antes de preparar el dataset.

```powershell
docker compose exec -T app php scripts/prepare-demo.php
```

Cuentas Alfa: owner@alfa.example.test, issuer@alfa.example.test y accountant@alfa.example.test. Beta usa los mismos roles en beta.example.test. La contrasena publica y sintetica del seeder es `FacturlyLocal2026!`; nunca debe utilizarse en un servicio desplegado.

La preparacion establece membresia del dataset con un borrador, concede una sola asignacion local de plan y completa Alfa. El plan no representa un pago. Beta conserva el conjunto pequeno para comprobar aislamiento. Alfa incluye 20 clientes, 24 productos, tres series y 13 documentos (seis solicitudes de emision, seis borradores del conjunto completo y el borrador inicial de membresia); algunos resultados son fallos deliberados del simulador. Su estado final depende de worker y scheduler.

La identidad `local-demo-2026-v1` es estable. Repetir preserva registros, modificaciones manuales, credenciales y correlativos; no reinicia la cuota. La asignacion demo no renueva indefinidamente un periodo vencido. No hay pagos ficticios cargados como si fueran confirmaciones externas.

Solo ejecutar en una instalacion local propia. La verificacion de publicacion utiliza una copia separada y no recarga datos del workspace del usuario. No usar este script en produccion. [Codigo](../backend/app/Actions/Demo/LocalDemoDataset.php) y [regresion de repeticion](../backend/tests/Feature/LocalDemoDatasetTest.php).
