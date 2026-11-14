# Arquitectura frontend

Actualizado: 2026-11-11.

React y TypeScript conservan el cliente HTTP existente (cookies Sanctum y CSRF).
AuthLayout presenta /acceso; AppShell contiene sidebar, header, cuenta y contenido.
El acceso privado espera la respuesta de sesion antes de mostrar los modulos.
Un 401 limpia el estado privado y vuelve al acceso con retorno interno validado.

## Rutas y estado

React Router usa createBrowserRouter y RouterProvider. Los modulos conservan sus
componentes y contratos HTTP. /account, /customers, /products, /company, /users y
/series son rutas reales; /new, /:id y /:id/edit existen segun las operaciones
permitidas de cada modulo. Company usa /company/edit. No se permite editar el
correlativo ni los identificadores de una serie.

useModuleNavigation mantiene busqueda, filtros y pagina en query parameters.
Recargar y usar atras/adelante recupera la vista. Las rutas desconocidas muestran
404; las restringidas muestran acceso denegado antes de solicitar datos.
Owner administra todos los modulos; issuer escribe clientes/productos;
accountant consulta. La autorizacion definitiva permanece en el backend.

FormGuard protege cambios sin guardar, recarga y salida durante una peticion.
Los formularios conservan errores de validacion y bloquean envios repetidos.
No se agregaron almacenamiento de tokens, TanStack Query ni otra capa de formularios.

## Sistema visual y accesibilidad

styles/tokens.css centraliza color, espacio, tipografia, radios y foco.
index.css implementa los layouts y componentes. DataTable mantiene tabla semantica,
caption y desplazamiento horizontal localizado; nunca transforma filas en divs.
Los formularios usan una o dos columnas segun espacio. El sidebar es persistente
 desde 1024px; debajo se usa dialog modal con ciclo de Tab, Escape y retorno del foco.
La navegacion usa enlaces, aria-current, salto al contenido y titulos de pagina.
Los iconos Lucide decorativos no sustituyen etiquetas accesibles.

## Dependencias incorporadas

- react-router: rutas, historial, retorno seguro y bloqueo de navegacion.
- lucide-react: iconos SVG consistentes, importados por nombre.

Se conserva CSS nativo y el stack existente; versiones exactas en package-lock.json.

## Desarrollo y comprobacion

Desde la raiz: docker compose up -d. Abrir http://localhost:5173/acceso.
Revisar escritorio a 1440px y movil a 390px; abrir el menu, usar Tab/Escape,
crear un producto y comprobar filtros al volver al listado.

```powershell
docker compose exec -T frontend npm run build
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm test -- --pool=vmThreads --maxWorkers=1
node scripts/test-users-ui.cjs
```

En Docker sobre Windows, forks/threads presentaron timeout al arrancar workers;
vmThreads completo las 75 pruebas con aislamiento por archivo.
El script Chrome usa datos sinteticos y API simulada: no altera desarrollo.
Cubre formularios, errores, permisos, historial, sesion, menu y anchos
360/390/768/1024/1440. Capturas en capturas/redisenio-frontend.
Queda pendiente el recorrido completo de navegador contra una API real aislada,
la evaluacion con lector de pantalla y la validacion en Safari/Firefox.
No se afirma certificacion WCAG. No hay control de versiones optimista implementado.

## Fallback SPA para un despliegue futuro

Vite conserva sus proxies /api, /sanctum, /login y /logout. La pagina de acceso
usa /acceso para no interceptar el endpoint POST /login.
Ejemplo conceptual de Caddy (no instalado ni desplegado por este cambio):

```caddyfile
:80 {
    @backend path /api /api/* /sanctum /sanctum/* /login /logout
    handle @backend {
        reverse_proxy backend-http:8000
    }
    handle {
        root * /srv/frontend
        try_files {path} /index.html
        file_server
    }
}
```

backend-http representa un servidor HTTP de Laravel preparado para produccion;
no prescribe artisan serve. Ajustar origen, TLS y cookies para el entorno real.
Las peticiones backend se resuelven antes del fallback de archivos/rutas SPA.

Referencias: [React Router](https://reactrouter.com/start/data/installation),
[Caddy SPA](https://caddyserver.com/docs/caddyfile/patterns#single-page-apps-spas).


## Comprobantes

/documents, /documents/new, /documents/:id y /documents/:id/edit mantienen el
shell existente. DocumentEditor reutiliza FormGuard; DocumentLookup pagina
clientes/productos; DocumentTotals solo presenta resultados del backend.
Preview espera350ms y usa AbortController mas una bandera de vigencia y clave
para descartar respuestas antiguas incluso si el transporte ignora abort.
Los totales desaparecen al cambiar el payload hasta obtener el nuevo calculo.
409 conserva cambios locales y exige recarga explicita; no reintenta con otra version.
La comprobacion real de navegador del nuevo modulo esta en PRUEBAS_BORRADORES.md.


El listado de Comprobantes incluye Editar y Eliminar con los mismos permisos que
el detalle. Durante DELETE comunica el estado de guardado al AppShell y deshabilita
acciones de fila. Tras eliminar la ultima fila de una pagina vuelve a la anterior.
En conflicto conserva la fila hasta que se confirma recargar el listado.
