# Registro de sesiones — WMS_LARAVEL

Registro manual de sesiones de trabajo con asistencia de IA (ChatGPT / Claude Code) sobre el proyecto WMS_LARAVEL. Cada entrada resume hechos reales de la sesión: qué se inspeccionó, qué se modificó, qué se validó y qué queda pendiente.

---

## 2026-07-02 — Auditoría técnica integral (solo lectura) + definición de protocolo de trabajo

**Contexto:** Primera sesión de trabajo con Claude Code en este proyecto. Se solicitó actuar como auditor técnico senior sin tocar código, para entender el estado real del proyecto antes de programar nada.

**Resumen de lo realizado:**
- Se verificó el estado de git: rama `main`, working tree limpio, `origin/main` alineado con local (0 commits de diferencia), HEAD en `c187c0c`.
- Se inspeccionó la arquitectura general: Laravel 12 / PHP ^8.2, 24 controladores, 18 modelos, `routes/web.php` (sin `routes/api.php`), 34 migraciones (hasta `create_bookings_table`).
- Se auditó en profundidad, con 5 subagentes de solo lectura en paralelo:
  - Autenticación, roles y middleware (`EnsureMinimumRole`, jerarquía por niveles cliente<almacen<administracion<superadmin).
  - Aislamiento multicliente y módulo Stock (patrón `resolveClientId`, salvaguarda de `client_id` en `StockPallet`).
  - Lógica de facturación de Operaciones diarias (`DailyOperationTotalsService`) — se confirmó que la fórmula del código coincide exactamente con el caso de validación Friesland (stock 2033, descarga 11, carga 12, envío 10 → almacenaje 2044, movidos 33, mañana 2022, gestiones 3, viajes 1).
  - Bookings, notificaciones y Google Calendar OAuth — se confirmó que la integración de Google Calendar es estrictamente de solo lectura (scope `CALENDAR_READONLY`, sin llamadas de escritura a la API).
  - Tests, frontend y configuración de producción/Forge.
- No se encontró ninguna vulnerabilidad crítica (P0) de fuga de datos entre clientes.
- Se detectaron varios puntos P1/P2 pendientes de decisión (ver más abajo).
- El usuario estableció un protocolo de trabajo permanente para sesiones futuras (inspeccionar → explicar impacto → proponer cambio mínimo → esperar autorización en áreas sensibles; tests/build/resumen final al programar). Se guardó como memoria persistente del asistente.

**Archivos modificados:** Ninguno en el código del proyecto (sesión de solo lectura, sin `Edit`/cambios). Se creó este mismo archivo `SESSION_LOG.md` (nuevo) y notas de memoria internas del asistente fuera del repositorio (no versionadas, no forman parte del código del proyecto).

**Tests/build ejecutados:**
- `php artisan test` (ejecutado por el agente auditor, sin modificar nada después): **243 passed, 0 failed**, 1081 aserciones, ~9.6s.
- No se ejecutó `npm run build` (no hubo cambios de frontend).

**Commit / push:** Ninguno.

**Pendientes claros abiertos al cierre de la sesión:**
- P1: `DailyOperationDay.opening_pallets` se recalcula en vivo desde el stock real, sin mecanismo de cierre/lock para días ya facturados — decidir si se necesita congelar cifras de días pasados.
- P1: Google Calendar no se muestra en la home `/dashboard` (colección vacía hardcodeada en `DashboardController`), solo en `/bookings/calendario` — aclarar si es el comportamiento esperado o un gap.
- P1: Confirmar en el `.env` real de Forge (no verificable desde local): `APP_ENV`, `APP_DEBUG`, `SESSION_DRIVER`/`CACHE_STORE` en caso de multi-servidor, y que el worker de colas esté activo de forma persistente.
- P2: Sin Eloquent Global Scopes para el filtrado multicliente (depende de que cada controlador replique el patrón `resolveClientId` manualmente).
- P2: Variables `BREVO_*` usadas en código pero ausentes de `.env.example`.
- P2: `MerchandiseRequestController::updateStatus` no duplica el check manual de rol/ownership como el resto del controlador.
- P2: Campos `google_calendar_event_id`/`google_calendar_synced_at` en `Booking` sin uso real.
- P3: Falta de Policies/Gates formales de Laravel, tests E2E de UI y tests de concurrencia; módulos "Palets" y "Backups" siguen como placeholder.
- Sin tarea de programación iniciada aún — a la espera de que el usuario priorice uno de los puntos anteriores u otra tarea.

---

## 2026-07-02 — Rediseño visual del login (fondo oscuro, cristal, logo real)

**Contexto:** Primera tarea de programación real de la colaboración. El usuario pidió un rediseño moderno, atractivo y "atrevido" de la página de login, y estableció un protocolo de trabajo permanente (inspeccionar → explicar impacto → proponer cambio mínimo → esperar autorización en áreas sensibles; tests/build/resumen final al programar).

**Resumen de lo realizado:**
- Se inspeccionó el layout compartido `resources/views/layouts/auth.blade.php` (usado también por recuperar contraseña, restablecer contraseña y solicitar acceso) y se detectó que varias clases CSS (`.auth-input`, `.auth-field`, pseudo-elementos de `.auth-panel`) están reutilizadas en 38 vistas de toda la app — se evitó tocar esas reglas base para no romper el diseño global, usando en su lugar overrides scoped a `body.auth-page`.
- Rediseño del fondo (`body.auth-page`): gradiente oscuro con orbes animados (`@keyframes auth-orb-float`, respeta `prefers-reduced-motion`).
- Panel de login con efecto cristal (`backdrop-filter: blur`), sombra profunda, borde con brillo cian, animación de entrada.
- Título y botón principal con degradado de marca (cian → tinta), scoped exclusivamente a `.auth-actions .button-primary` y `.auth-title`.
- **Corrección de logo:** se detectó que `public/brand/maximo-icon.png` es de solo **16×16 píxeles** (causa de pixelado visible). Se sustituyó por `public/brand/maximo-logo-horizontal.png` (1800×800, PNG con transparencia) tanto en la marca de la esquina como en el título grande del login, eliminando también el texto duplicado "MAXIMO WMS" renderizado con fuente web (sustituido por la tipografía real de marca vía imagen).
- Verificación visual en navegador real (escritorio y móvil 375px) de las 4 páginas que comparten el layout: login, recuperar contraseña, restablecer contraseña, solicitar acceso.

**Archivos modificados:**
- `resources/css/app.css`
- `resources/views/auth/login.blade.php`
- `resources/views/layouts/auth.blade.php`
- `.claude/launch.json` (nuevo, herramienta local de previsualización, no forma parte de la app)

**Tests/build ejecutados:**
- `npm run build` → OK (sin errores).
- `php artisan test` → **245 passed, 0 failed**, 1127 aserciones.

**Commit / push:** Commit `1b82a28` ("style: redesign login with bold dark theme and real brand logo"), pusheado a `origin/main` (`a394364..1b82a28`). Forge desplegará automáticamente en producción desde este push.

**Pendientes:**
- Extender el mismo lenguaje visual (fondo oscuro/contraste alto, cristal, degradados de marca) al resto de la aplicación (dashboard, stock, bookings, operaciones diarias, etc.) — pendiente de alcance y ejecución.
- Los puntos P1/P2/P3 de la auditoría inicial (ver entrada anterior) siguen abiertos.

---

## 2026-07-02 — Extensión del estilo oscuro/cristal a toda la app logueada

**Contexto:** Continuación directa del paso anterior. El usuario pidió extender el estilo del login (fondo oscuro, cristal, degradados de marca) a todo el proyecto, incluidas las pantallas de trabajo denso (stock, operaciones diarias). Antes de programar, se planteó al usuario una pregunta de alcance (¿solo el "chrome" o también las tablas de datos?, ¿por fases o todo de una vez?) dado el riesgo de perder legibilidad en pantallas operativas. El usuario eligió: aplicar el estilo a todo, incluidas las tablas, en una sola pasada.

**Resumen de lo realizado:**
- Se inspeccionó `resources/css/app.css` (5300 líneas) y se detectó que `.app-header`/`.app-nav`/`.ops-sidebar`/`.module-card`/`.app-overview-card`/`.app-stat` son **CSS muerto** (0 coincidencias en las vistas reales) de una iteración de diseño anterior — no se tocaron para no perder tiempo en código no usado.
- Se identificó el sistema real y único que gobierna toda la app logueada: `resources/views/layouts/dashboard.blade.php` con `body.brand-body.app-shell-body`, y la clase `.surface-card` (191 usos) + `.compact-card` (155 usos) como envoltorio universal de tarjetas, tablas y formularios en los ~20 módulos.
- Estrategia aplicada (igual que en el login): fondo de página oscuro con degradado de marca; las tarjetas/tablas que contienen datos permanecen en "cristal claro" (blanco, alto contraste) flotando sobre el fondo oscuro — así se logra el efecto en toda la interfaz sin sacrificar la legibilidad de tablas densas (stock, operaciones diarias, auditoría).
- Cambios concretos en CSS (todos scoped a `body.brand-body.app-shell-body` para no afectar fuera del área logueada):
  - Fondo de página: degradado oscuro (mismo lenguaje que el login, sin los orbes animados para no fatigar la vista en sesiones largas de trabajo).
  - `.surface-card`: tratamiento cristal con borde brillante cian y sombra profunda.
  - `.button-primary`: degradado cian → tinta (igual que el botón del login).
  - Corrección del logo pixelado en topbar y menú lateral (mismo problema que en el login: `maximo-icon.png` de 16×16 px), sustituido por `maximo-logo-horizontal.png`, quitando el texto de marca duplicado.
- **Bug encontrado y corregido durante la verificación visual:** el breadcrumb superior de cada página (`.ops-breadcrumb`, presente en 38 vistas) quedó con contraste insuficiente (gris oscuro sobre fondo oscuro) al no estar envuelto en una tarjeta. Se corrigió con un color claro scoped.
- Verificación visual en navegador real con un usuario de prueba temporal (`preview-qa@local.test`, creado y **borrado** al terminar, sin tocar la cuenta real ni datos existentes): Dashboard, Stock (vista tabla y vista tarjetas móvil), menú lateral (drawer) abierto, Operaciones diarias. Todo con buen contraste y logo nítido.

**Archivos modificados:**
- `resources/css/app.css`
- `resources/views/layouts/dashboard.blade.php`

**Tests/build ejecutados:**
- `npm run build` → OK (sin errores), dos veces (antes y después de la corrección del breadcrumb).
- `php artisan test` → **245 passed, 0 failed**, 1127 aserciones.

**Commit / push:** Commit `1932c23` ("style: extend dark glass theme to the whole logged-in app shell"), pusheado a `origin/main` (`864a7d7..1932c23`). Forge desplegará automáticamente en producción desde este push.

**Pendientes:**
- Quedan sin auditar visualmente el resto de módulos no revisados en esta pasada (bookings, entradas, salidas, usuarios, clientes, proveedores, solicitudes, notificaciones, perfil) — se benefician automáticamente de los mismos cambios CSS globales, pero no se ha hecho una revisión visual pantalla por pantalla de cada uno.
- Los puntos P1/P2/P3 de la auditoría inicial siguen abiertos.
---

## 2026-07-03 - Cierre de sesion para traspaso a Claude (14:34:43 +02:00)

**Contexto:** Sesion de cierre y documentacion tras completar el hito funcional de PEDIDOS / SALIDAS con soporte de pallets y picos. No se deben tocar ni Google Calendar, ni importacion de stock Friesland/Edelvives, ni facturacion, ni el aislamiento de stock por cliente en la continuacion.

**Estado Git al cierre:**
- Rama actual: `main`
- `git status`: limpio en codigo funcional, pero con `.claude/` sin trackear en el working tree
- `git log --oneline -10`: HEAD actual en `c1da968`, con historial inmediato `4f87109`, `a704e29`, `2bb22fd`, `c1011a5`, `1932c23`, `864a7d7`, `1b82a28`, `a394364`, `c187c0c`

**Ultimos commits de referencia:**
- Ultimo commit funcional actual:
  - `c1da968 feat: improve pedidos y salidas ux with peak support`
- Ultimo commit visual de referencia anterior en el log local/desplegado:
  - `4f87109 fix: polish breadcrumbs and page header layout`

**Estado de la funcionalidad nueva:**
- pedidos ya soportan lineas tipo `pallet` y `peak`
- cliente puede pedir picos desde autocomplete
- interno puede gestionar carga real con pallets/picos
- se anadio la migracion:
  - `database/migrations/2026_07_03_000024_add_line_type_support_to_request_and_dispatch_lines.php`
- si se despliega a produccion hay que ejecutar:
  - `php artisan migrate --force`
  - `php artisan optimize:clear`
  - `php artisan queue:restart`

**Validacion reportada por Codex:**
- `php artisan optimize:clear`: OK
- `php artisan test`: `257 passed`
- `npm run build`: OK

**Resumen tecnico real de lo entregado:**
- Se anadieron tipos de linea y persistencia explicita para diferenciar pallets completos de picos en pedidos y salidas.
- El flujo cliente -> pedido -> gestion interna -> salida -> carga real conserva el tipo de linea y la cantidad correspondiente.
- Se anadio soporte de autocomplete para variantes de stock y para lineas extra en carga real.
- Se corrigio compatibilidad hacia atras con payloads antiguos en tests y formularios donde todavia se enviaban cantidades legacy.

**Problemas detectados por el usuario que quedan pendientes para Claude:**
1. En PEDIDOS / Solicitar mercancia siguen mal los margenes, paddings y espaciados.
2. Hay textos con UTF-8 roto, por ejemplo:
   - `tipo de lÃ­nea`
   - `aparecerÃ¡`
   - `aÃ±adirla`
   y debe corregirse en todas las vistas afectadas.
3. La pantalla de crear pedido aun parece poco pulida visualmente.
4. En dashboard de superadmin/almacen/administracion falta un acceso directo claro a PEDIDOS.
5. La seccion PEDIDOS deberia tener una lista tipo tabla con pedidos y estado a la derecha, usando colores de estado.
6. El albaran de salida sigue sin logo de Maximo.
7. La gestion interna de pedido/salida/carga real necesita revision visual seria: paddings, margenes, alineacion de botones, jerarquia de acciones, colores de estado.
8. El flujo debe ser "para tontos", porque usuarios de almacen pueden ir despistados.
9. En carga real, anadir linea extra/autocomplete debe verse dentro del layout y no aparecer cortado o debajo de bloques.
10. Botones de impresion/albaran deben estar alineados y con icono de impresora.
11. Estados Pendiente/Enviado/Completado deben ser visualmente claros y con color.

**Decisiones de producto vigentes:**
- Menu cliente visible:
  - `STOCK`
  - `BOOKING`
  - `PEDIDOS`
- Internos tambien necesitan acceso directo a PEDIDOS.
- Los colores deben ayudar a entender estados.
- Mantener look corporativo dark/glass.
- No tocar Google Calendar.
- No tocar importacion stock Friesland/Edelvives.
- No tocar facturacion.
- No romper stock por cliente.

**Notas operativas para Claude:**
- No anadir `.claude/` a ningun commit.
- La migracion nueva existe y ya forma parte del commit `c1da968`.
- Validacion final hecha por Codex antes del push:
  - `php artisan optimize:clear` OK
  - `php artisan test` OK (`257 passed`)
  - `npm run build` OK
- El push ya esta hecho en `origin/main` con `c1da968`.
- Lo siguiente a revisar no es funcionalidad base de stock, sino pulido serio de UX/UI y correccion de textos rotos en PEDIDOS / SALIDAS.

---

## 2026-07-03 — Pulido UX/UI de PEDIDOS y SALIDAS tras el hito de picos/pallets

**Contexto:** Continuación directa del traspaso anterior (commit `c1da968`). Se pidió corregir mojibake, mejorar en profundidad crear/detalle de pedido cliente, dar acceso directo "Pedidos" a roles internos, mejorar el listado, pulir gestión interna de pedido/salida, arreglar el panel de "añadir referencia a la carga", añadir logo al albarán/preparación PDF, y tests — todo sin tocar Google Calendar, importación Friesland/Edelvives, facturación ni las reglas de stock por cliente.

**Hallazgo inicial importante:** gran parte de la maquetación "pedido/salida" (sistema `.wms-flow-card`, `.wms-line-type-pill`, KPIs, timeline, breadcrumbs con iconos) ya estaba bien construida por trabajo previo (commits `c1da968`, `4f87109`, `a704e29`, `2bb22fd`). Se verificó en vivo con navegador (usuarios de prueba temporales, creados y borrados al terminar) antes de rediseñar nada a ciegas, para no reescribir código que ya funcionaba bien.

**Bugs reales encontrados y corregidos (verificados con `preview_inspect`, no solo visualmente):**
- `.wms-flow-card` y `.wms-empty-state` no tenían **ningún padding** definido en `app.css` — causaba el efecto "bloques pegados" que reportó el usuario. Corregido añadiendo `padding: 1.2rem`.
- `.wms-detail-grid`/`.wms-action-grid` no fijaban `align-items`, así que el grid estiraba las tarjetas cortas a la altura de la más alta (p. ej. "Resumen rápido" con hueco vacío enorme junto a "Seguimiento"). Corregido con `align-items: start`.
- `.alert-success` tenía un degradado semitransparente (`rgba(...,0.24)` → blanco) que sobre el fondo oscuro nuevo del shell dejaba el texto casi ilegible — exactamente el "texto perdido" que pedía arreglar el usuario. Corregido a fondo sólido `#deefe4`, con el mismo tratamiento para `.alert-error` scoped a `body.brand-body.app-shell-body`.
- El panel de autocompletar (`.ajax-autocomplete-panel`, usado en "Añadir referencia a la carga" de `dispatches/show.blade.php`) se posicionaba siempre hacia abajo (`position:absolute; top:...`) sin detección de colisión, y en filas bajas de la página se salía por debajo del viewport (confirmado con `getBoundingClientRect()`: 79px fuera de pantalla). Corregido con lógica de "flip up" en `resources/js/app.js` (`positionPanel()`) + clase CSS `.ajax-autocomplete-panel--flip`.
- Mojibake real (UTF-8 roto) encontrado y corregido: 7 ocurrencias en `resources/js/app.js` (picker de variantes de stock) y 1 en `resources/views/bookings/_form.blade.php`. Los dos casos en tests (`StockOverviewTest`, `NavigationRenderingTest`) eran comprobaciones de *ausencia* de mojibake, no bugs.

**Acceso PEDIDOS para internos:** se evitó crear una entrada de menú duplicada (primer intento generó "Pedidos" y "Solicitar mercancia" apuntando a la misma ruta — se detectó en vivo y se corrigió). Solución final: un solo cambio de título en `config/wms.php` (`solicitudes` → "Pedidos"), reutilizando la ruta `merchandise-requests.index` ya multi-rol. Bookings intacto.

**Listado de PEDIDOS mejorado:** columnas Picos, Unidades y Salida asociada (enlace a la salida si existe) añadidas a `merchandise-requests/index.blade.php`, con `dispatch` añadido al eager-load del controlador para evitar N+1.

**Logo en PDFs:** `dispatches/delivery-note-pdf.blade.php` y `merchandise-requests/preparation-pdf.blade.php` ahora incluyen el logo (`public_path()`, no `asset()`, por compatibilidad con dompdf). **Riesgo real detectado y mitigado:** el entorno local no tiene la extensión PHP GD instalada, y dompdf no puede renderizar `<img>` sin GD/Imagick — esto rompió 3 tests (`500` en vez de `200`). Se añadió un guard `extension_loaded('gd') || extension_loaded('imagick')` para que el logo solo se intente si el servidor lo soporta, cayendo a texto si no — exactamente el fallback que pedía el usuario. **Pendiente de confirmar en Forge/producción si GD o Imagick están instalados**; si no lo están, el logo no aparecerá (pero el PDF seguirá generándose sin error).

**CSS reutilizable:** no se crearon clases nuevas `pedido-*` como sugería el enunciado — el sistema `wms-*` ya existente (`wms-flow-card`, `wms-line-type-pill`, `wms-detail-grid`, etc.) cubre exactamente ese rol y ya está usado de forma consistente en todo el módulo. Crear una nomenclatura paralela habría fragmentado el sistema de diseño en vez de consolidarlo, así que se optó por corregir/reforzar el sistema existente.

**Incidente de datos local (a tener en cuenta):** durante la limpieza de datos de prueba (usuarios, item y stock QA temporales) se ejecutó una query de limpieza más amplia de lo previsto (`MerchandiseRequest::query()->delete()`, `GoodsDispatch::where('client_id', ...)->delete()`) que borró un `GoodsDispatch` (id 1) y su `MerchandiseRequest` asociada que ya existían en la base de datos local **antes** de esta sesión — con contenido tipo Faker/lorem ipsum ("BASE0001"/"EXTRA0001"), consistente con datos de prueba de una sesión anterior (probablemente de Codex), no datos reales de negocio. Es solo base de datos local de desarrollo, no producción, pero se deja constancia explícita.

**Archivos modificados:**
- `app/Http/Controllers/MerchandiseRequestController.php`
- `config/wms.php`
- `resources/css/app.css`
- `resources/js/app.js`
- `resources/views/bookings/_form.blade.php`
- `resources/views/dispatches/delivery-note-pdf.blade.php`
- `resources/views/merchandise-requests/index.blade.php`
- `resources/views/merchandise-requests/preparation-pdf.blade.php`
- `tests/Feature/GoodsDispatchManagementTest.php`
- `tests/Feature/MerchandiseRequestManagementTest.php`
- `tests/Feature/RoleAccessTest.php`

**Migraciones nuevas:** ninguna.

**Tests/build:**
- `php artisan optimize:clear` → OK.
- `php artisan test` → **262 passed, 0 failed** (257 previos + 5 nuevos), 1221 aserciones.
- `npm run build` → OK.

**Commit / push:** Commit `ff9dd66` ("fix: polish pedidos workflow ux"), pusheado a `origin/main` (`3bff27e..ff9dd66`). Sin migraciones nuevas; tras el deploy en Forge basta con `Deploy Now` + `php artisan optimize:clear` (no hace falta `migrate --force` ni `queue:restart` para este cambio).

**Pendientes:**
- Confirmar en Forge si GD/Imagick está instalado para que el logo aparezca en los PDFs en producción.
- No se hizo una revisión visual exhaustiva de "Salida enviada/completada" con datos reales de envío completo (se revisó la estructura y los estilos base, que ya heredan las correcciones de padding/alerts, pero no se forzó el flujo completo hasta "completado").
- Los puntos P1/P2/P3 de la auditoría inicial siguen abiertos.

---

## 2026-07-05 - Simplificacion global del dashboard y actualizacion del footer (15:58:03 +02:00)

**Contexto:** Continuacion del trabajo iniciado en `38dd779 style: simplify client dashboard and add global footer`. En ese commit el dashboard limpio solo se aplicaba al cliente. La nueva decision de producto fue extender esa limpieza a todos los roles y ajustar el footer global para mostrar `� 2026`, manteniendo accesibles notificaciones y bookings desde sus puntos naturales de navegacion.

**Commit de partida:**
- `38dd779 style: simplify client dashboard and add global footer`
- Ultimo commit actual antes de esta tarea: `38dd779 style: simplify client dashboard and add global footer`

**Resumen de la decision aplicada:**
- Se elimino del dashboard para todos los roles:
  - `Proximos bookings`
  - `Notificaciones recientes`
- Esta informacion sigue accesible desde:
  - topbar y drawer de notificaciones
  - modulo de bookings
  - agenda/calendario operativo del dashboard
  - calendario de bookings

**Cambios funcionales y de vista realizados:**
- `resources/views/dashboard/index.blade.php`
  - eliminados los dos paneles resumen para cliente, almacen, administracion y superadmin
  - se mantiene la agenda semanal como bloque operativo principal
- `app/Http/Controllers/DashboardController.php`
  - eliminada la carga de `upcomingBookings`
  - eliminada la carga de `recentNotifications`
  - se mantiene la carga de agenda WMS y capa Google Calendar para roles internos
- `resources/views/components/app-footer.blade.php`
  - texto actualizado a:
    - `� 2026 � WMS creado y desarrollado por Jorge Monge. Soluciones web corporativas para empresas que buscan control, eficiencia y trazabilidad.`
  - enlace mantenido a `https://www.jorgemonge.es`
  - apertura en nueva pestana con `target="_blank"` y `rel="noopener noreferrer"`

**Archivos principales modificados:**
- `app/Http/Controllers/DashboardController.php`
- `resources/views/dashboard/index.blade.php`
- `resources/views/components/app-footer.blade.php`
- `tests/Feature/AuthenticationFlowTest.php`
- `tests/Feature/BookingManagementTest.php`
- `tests/Feature/NotificationCenterTest.php`
- `tests/Feature/RoleAccessTest.php`

**Resultado de validacion:**
- `php artisan optimize:clear`: OK
- `php artisan test`: `325 passed`
- `npm run build`: OK

**Migraciones:**
- No hubo migraciones nuevas

**Estado del footer global:**
- Visible en layout autenticado
- Visible tambien en login/guest
- Muestra `� 2026`
- Mantiene credito explicito a Jorge Monge
- Mantiene enlace visible `www.jorgemonge.es`

**Estado final del dashboard:**
- Se mantienen los modulos principales por rol
- Se mantiene la agenda/calendario operativo
- Se mantienen permisos por rol, topbar, drawer, acceso a bookings, pedidos, stock, salidas y notificaciones
- Se elimina duplicidad visual de bookings/notificaciones en la home

**Forge:**
- `Deploy Now`
- `php artisan optimize:clear`
- `php artisan queue:restart` no necesario para este hito al no tocar colas ni notificaciones de backend

**Control de alcance:**
- No se tocaron migraciones
- No se toco Google Calendar
- No se toco importacion Friesland/Edelvives
- No se toco facturacion
- `.claude/` no se anadio al commit
---

## 2026-07-06 - Permisos operativos de ALMACEN para maestros de trabajo (18:40:48 +02:00)

**Contexto:** Se revisaron permisos, rutas, vistas y tests para preparar la operativa de almacen de cara a EDELVIVES, con la decision de negocio de permitir al rol `ALMACEN` crear y editar articulos, ubicaciones y proveedores sin abrir zonas de sistema ni acciones destructivas no necesarias.

**Commit previo de partida:**
- `74628ef style: simplify dashboards and update global footer`

**Resumen de la decision aplicada:**
- `ALMACEN` ya puede:
  - ver, abrir formulario, crear y editar articulos
  - ver, abrir formulario, crear y editar ubicaciones
  - ver, abrir formulario, crear y editar proveedores
- Se mantiene bloqueado para `ALMACEN`:
  - importar stock masivo
  - usuarios y roles
  - solicitudes de acceso
  - auditoria
  - backups
  - activacion/desactivacion de maestros operativos
  - creacion de almacenes

**Cambios principales realizados:**
- `routes/web.php`
  - apertura de `create/store/edit/update` de articulos, ubicaciones y proveedores a `minimum.role:almacen`
  - sin cambios en `toggle-active`, importacion masiva ni rutas de sistema
- `app/Http/Requests/StoreItemRequest.php`
- `app/Http/Requests/UpdateItemRequest.php`
- `app/Http/Requests/StoreLocationRequest.php`
- `app/Http/Requests/UpdateLocationRequest.php`
- `app/Http/Requests/StoreSupplierRequest.php`
  - autorizacion reforzada a nivel `FormRequest` para exigir `canAccessRole(Role::ALMACEN)`
- `resources/views/items/index.blade.php`
- `resources/views/locations/index.blade.php`
- `resources/views/suppliers/index.blade.php`
  - CTAs visibles para `ALMACEN`
  - enlace `Editar` visible para `ALMACEN`
  - acciones de activar/desactivar siguen reservadas a `ADMINISTRACION` o superior
- `tests/Feature/ItemManagementTest.php`
- `tests/Feature/WarehouseLocationManagementTest.php`
- `tests/Feature/SupplierManagementTest.php` (nuevo)
- `tests/Feature/RoleAccessTest.php`
- `tests/Feature/GoodsReceiptManagementTest.php`
  - cobertura nueva y ajuste de expectativas heredadas para reflejar la nueva politica

**Resultado de validacion:**
- `php artisan optimize:clear`: OK
- `php artisan test`: `335 passed` (1538 assertions)
- `npm run build`: OK

**Migraciones:**
- No hubo migraciones nuevas
- No fue necesario ejecutar `php artisan migrate`

**Control de alcance:**
- No se tocaron Google Calendar, facturacion ni la importacion de stock Friesland/Edelvives
- No se tocaron datos productivos ni se uso `migrate:fresh`
- `.claude/` sigue fuera del commit

**Forge:**
- `Deploy Now`
- `php artisan optimize:clear`
- `php artisan queue:restart` si procede
- `php artisan migrate --force` no aplica en este hito---

## 2026-07-06 - Mejora del flujo de entradas de mercancia (19:15:47 +02:00)

**Contexto:** Se ha refinado el flujo operativo de entradas para que `ALMACEN` pueda registrar recepciones con menos friccion, crear articulos nuevos desde la propia linea cuando el SKU todavia no existe y mantener la trazabilidad por lote sin contaminar el maestro de articulos.

**Commit previo de partida:**
- `f58739d fix: allow warehouse role to manage operational masters`

**Resumen de la decision aplicada:**
- Se simplifica la alta de entradas:
  - se elimina `Documento externo` del formulario inicial
  - se mantiene una subida opcional del documento del proveedor o albaran
  - la zona de observaciones queda mas contenida
  - las lineas pasan a tarjetas verticales para evitar scroll horizontal
- Cada linea ya no guarda observaciones propias.
- Si un SKU no existe para el cliente seleccionado, la entrada crea automaticamente el articulo maestro al guardar.
- El lote sigue siendo trazabilidad de la entrada y del stock, pero no se guarda como dato maestro del articulo.
- El documento adjunto queda protegido tras autenticacion interna y se descarga mediante ruta controlada.
- La vista de detalle de borrador/entrada se limpia retirando bloques no operativos y dejando foco en documento, lineas y estado de stock.

**Cambios principales realizados:**
- `app/Services/GoodsReceipts/GoodsReceiptItemResolver.php` (nuevo)
  - resuelve articulos existentes o crea articulos nuevos desde la linea de entrada
  - asegura `client_id`, `sku`, `description`, `units_per_pallet`, `status`, `active`, `lot`, `lot_key` y `default_location_id`
- `app/Http/Controllers/GoodsReceiptController.php`
  - crea o reutiliza el articulo ya en guardado de borrador
  - elimina manejo de notas por linea
  - mueve la descarga de documentos a una ruta protegida
  - guarda adjuntos en disco `local` con compatibilidad de lectura para adjuntos antiguos
- `app/Services/GoodsReceipts/GoodsReceiptStockApplicationService.php`
  - reutiliza el resolvedor para confirmar stock sin duplicar logica
- `app/Http/Requests/StoreGoodsReceiptRequest.php`
  - autoriza a `ALMACEN`
  - completa automaticamente `item_id`, descripcion y uds/pallet si el SKU ya existe
  - exige `sku`, `description` y `units_per_pallet` cuando el articulo todavia no existe
  - elimina validacion de notas por linea
- `app/Http/Requests/AttachGoodsReceiptDocumentRequest.php`
  - autoriza a `ALMACEN`
- `app/Models/GoodsReceipt.php`
  - `document_url` pasa a usar ruta protegida
- `routes/web.php`
  - nueva ruta GET protegida para descarga de documento adjunto
- `resources/views/goods-receipts/_form.blade.php`
  - formulario simplificado y lineas en formato card
- `resources/views/goods-receipts/_line-row.blade.php`
  - nueva tarjeta por linea, sin notas, con aviso de creacion automatica de articulo
- `resources/views/goods-receipts/show.blade.php`
  - detalle depurado y bloque secundario de documento adjunto
- `resources/js/app.js`
  - avisos de articulo nuevo, renumeracion de lineas y soporte del nuevo layout
- `resources/css/app.css`
  - estilos del flujo en tarjetas y del bloque secundario de documento
- `tests/Feature/GoodsReceiptManagementTest.php`
  - cobertura nueva para articulos creados desde entrada, lote no persistido en maestro, descarga protegida y nueva UX

**Resultado de validacion:**
- `php artisan optimize:clear`: OK
- `php artisan test`: `345 passed` (1580 assertions)
- `npm run build`: OK

**Migraciones:**
- No hubo migraciones nuevas
- No fue necesario ejecutar `php artisan migrate`

**Control de alcance:**
- No se tocaron Google Calendar, facturacion ni la importacion Friesland/Edelvives
- No se tocaron datos productivos ni se uso `migrate:fresh`
- `.claude/` sigue fuera del commit

**Forge:**
- `Deploy Now`
- `php artisan optimize:clear`
- `php artisan queue:restart` no es necesario para este hito
- `php artisan migrate --force` no aplica en este hito---

## 2026-07-06 - Extraccion IA asistida para entradas y albaranes (19:32:30 +02:00)

**Contexto:** Se ha implementado la primera fase de interpretacion IA de albaranes para entradas de mercancia, manteniendo la regla operativa clave de que la IA solo propone datos y el equipo de almacen sigue siendo quien revisa, corrige y aplica la informacion antes de confirmar stock.

**Commit previo de partida:**
- `64df2bc fix: improve goods receipt entry workflow`

**Resumen de la decision aplicada:**
- Se confirma que los documentos de entradas ya estaban pasando a storage privado del WMS mediante disco `local` y descarga protegida por controlador.
- Se reutiliza la persistencia existente en `goods_receipts` para IA:
  - `ai_status`
  - `ai_extracted_data`
  - `ai_error`
  - `document_processed_at`
- No se anade migracion nueva en este hito.
- Se anade arquitectura configurable y testeable para IA:
  - extractor desacoplado por interfaz
  - soporte real preparado para OpenAI por HTTP client de Laravel
  - tests con fake/mock para no depender de API real
- La IA interpreta documento y guarda propuesta estructurada, pero:
  - no crea stock automaticamente
  - no confirma la entrada automaticamente
  - el almacenero revisa y aplica manualmente

**Configuracion IA anadida:**
- `.env.example`
  - `OPENAI_API_KEY=`
  - `OPENAI_RECEIPT_MODEL=gpt-4.1`
  - `OPENAI_RECEIPT_ENABLED=false`
- `config/services.php`
  - bloque `openai` para clave, modelo y activacion de extraccion de entradas

**Cambios principales realizados:**
- `app/Services/GoodsReceipts/GoodsReceiptDocumentStorage.php` (nuevo)
  - centraliza guardado, borrado, resolucion de disco y lectura del documento adjunto
  - mantiene compatibilidad con adjuntos antiguos en `public` si existieran
- `app/Services/GoodsReceipts/GoodsReceiptAiExtractorInterface.php` (nuevo)
  - contrato del extractor de documentos
- `app/Services/GoodsReceipts/GoodsReceiptAiExtractionResult.php` (nuevo)
  - normaliza resultado estructurado y recalcula pallets/pico si la propuesta no cuadra
- `app/Services/GoodsReceipts/OpenAiGoodsReceiptExtractor.php` (nuevo)
  - integra llamada real preparada a OpenAI Responses API
  - usa `text.format` con `json_schema`
  - acepta PDF e imagenes desde storage privado
- `app/Services/GoodsReceipts/GoodsReceiptAiExtractionService.php`
  - orquesta la extraccion
  - comprueba activacion por configuracion
  - enriquece el resultado con coincidencia automatica de proveedor y avisos de baja confianza
- `app/Http/Requests/ApplyGoodsReceiptAiProposalRequest.php` (nuevo)
  - reutiliza validacion de entradas para aplicar propuesta IA sobre la entrada actual
- `app/Http/Controllers/GoodsReceiptController.php`
  - reutiliza `GoodsReceiptDocumentStorage`
  - anade `extractAi()` y `applyAi()`
  - guarda `processing/completed/failed/reviewed`
  - aplica cabecera y lineas revisadas sin confirmar stock
- `app/Models/GoodsReceipt.php`
  - nuevos labels/estados IA: `pending`, `processing`, `completed`, `reviewed`, `failed`
- `app/Providers/AppServiceProvider.php`
  - binding del extractor IA
- `routes/web.php`
  - `POST /entradas/{goodsReceipt}/ia-extraer`
  - `POST /entradas/{goodsReceipt}/ia-aplicar`
- `resources/views/goods-receipts/show.blade.php`
- `resources/views/goods-receipts/_ai-proposal-panel.blade.php` (nuevo)
  - boton `Interpretar albaran con IA`
  - estado IA
  - errores y avisos
  - panel de propuesta editable antes de aplicar
- `resources/css/app.css`
  - estilos del panel IA, estados, avisos y tarjetas de propuesta
- `tests/Feature/GoodsReceiptManagementTest.php`
  - cobertura nueva para interpretacion, aplicacion, errores y seguridad documental

**Como se guarda el resultado IA:**
- En `goods_receipts.ai_extracted_data` como JSON estructurado
- `goods_receipts.ai_status` guarda el estado del ciclo IA
- `goods_receipts.ai_error` guarda el error legible si la interpretacion falla
- `goods_receipts.document_processed_at` guarda la ultima interpretacion completada

**Como se interpreta el documento:**
- El usuario adjunta PDF o imagen del albaran en la entrada
- El documento queda en storage privado del WMS
- Al pulsar `Interpretar albaran con IA`:
  - se verifica que hay documento
  - se llama al extractor configurado
  - si OpenAI esta activo, se manda el fichero por Responses API con schema JSON estricto
  - si falla, se guarda error y la entrada sigue intacta
- La propuesta resultante devuelve:
  - proveedor detectado
  - numero de albaran
  - fecha
  - confianza
  - lineas
  - avisos

**Como se aplica la propuesta a lineas:**
- La pantalla de detalle muestra `Propuesta IA del albaran`
- El almacenero puede editar proveedor, numero, fecha y cada linea
- Al aplicar:
  - se refrescan las lineas de la entrada
  - si el SKU ya existe, se reutiliza
  - si el SKU no existe, se crea el articulo con SKU, descripcion y `units_per_pallet`
  - el lote se mantiene solo en la linea/stock como trazabilidad operativa
  - la entrada sigue en borrador hasta que una persona la confirme

**Seguridad y alcance documental:**
- La descarga documental sigue protegida para roles internos autorizados
- Los clientes siguen sin acceso directo al documento en este hito
- El documento sigue asociado a la entrada y por tanto a `client_id`
- El listado de entradas ya permite filtrado por cliente
- No se ha creado aun la pantalla cliente de `Mis albaranes`; queda para fase siguiente

**Google Drive:**
- No se implementa subida o espejo a Google Drive en este hito
- Queda preparada como fase futura:
  - espejo documental por cliente/ano/entrada
  - WMS seguira como fuente principal
  - la operacion no debe bloquearse si Drive falla

**Resultado de validacion:**
- `php artisan optimize:clear`: OK
- `php artisan migrate`: no aplica en este hito
- `php artisan test`: `357 passed` (1628 assertions)
- `npm run build`: OK

**Migraciones:**
- No hubo migraciones nuevas
- No fue necesario ejecutar `php artisan migrate`

**Control de alcance:**
- No se tocaron Google Calendar, facturacion ni importaciones Friesland/Edelvives
- No se tocaron datos productivos ni se uso `migrate:fresh`
- `.claude/` sigue fuera del commit

**Forge:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart` no es necesario salvo operativa posterior ligada a colas---

## 2026-07-06 - Flujo visible de "Crear borrador e interpretar con IA" en entradas (19:48:20 +02:00)

**Contexto:** Tras el hito `2ef167e4 feat: add AI-assisted goods receipt extraction`, el sistema ya interpretaba albaranes desde el detalle de la entrada, pero la UX de alta inicial no dejaba claro que el usuario podia crear el borrador y lanzar la IA directamente desde el formulario de nueva entrada.

**Commit previo de partida:**
- `2ef167e4 feat: add AI-assisted goods receipt extraction`

**Resumen del cambio UX aplicado:**
- Se hace visible el flujo IA desde la propia creacion de entrada.
- El formulario ahora ofrece dos caminos claros:
  - `Crear borrador`
  - `Crear borrador e interpretar con IA`
- La opcion IA se activa desde el formulario cuando se adjunta un documento.
- Se anade copy explicativo para dejar claro que, si se va a interpretar el albaran con IA, las lineas pueden quedar vacias en ese paso.
- En el detalle de la entrada se anade un bloque protagonista para que el usuario no tenga que buscar la accion de interpretar o reintentar.

**Nuevo flujo "Crear borrador e interpretar con IA":**
1. El usuario selecciona cliente y adjunta PDF/foto del albaran.
2. Puede dejar las lineas vacias.
3. Pulsa `Crear borrador e interpretar con IA`.
4. El sistema crea la entrada como borrador.
5. Guarda el documento privado.
6. Si la IA esta activa, lanza la extraccion.
7. Redirige al detalle con la propuesta IA visible para revision.
8. La entrada sigue sin confirmar y sin aplicar stock hasta validacion humana.

**Comportamiento por escenario:**
- Si no hay documento y se pulsa el flujo IA:
  - se crea el borrador igualmente
  - se redirige al detalle con error claro para adjuntar albaran antes de interpretar
- Si `OPENAI_RECEIPT_ENABLED=false`:
  - se crea el borrador
  - se guarda el documento
  - se avisa de que la interpretacion IA esta pendiente de activar en configuracion
- Si la IA falla:
  - la entrada se mantiene creada
  - el documento se conserva
  - se guarda `ai_error`
  - el detalle muestra error legible y CTA de reintento

**Cambios principales realizados:**
- `app/Http/Requests/StoreGoodsReceiptRequest.php`
  - anade acciones de formulario `create_draft` y `create_and_extract_ai`
  - permite borrador con `lines` vacias solo en el flujo IA
  - mantiene exigencia de lineas para el flujo manual
- `app/Http/Controllers/GoodsReceiptController.php`
  - `store()` ya soporta crear y extraer en una sola accion
  - reutiliza logica comun de extraccion con `performAiExtraction()`
  - conserva el borrador y el documento si la IA falla o esta desactivada
- `resources/views/goods-receipts/_form.blade.php`
  - helper nuevo junto al documento
  - nota explicita de lineas vacias para el flujo IA
  - nuevo CTA `Crear borrador e interpretar con IA`
- `resources/js/app.js`
  - activa/desactiva el CTA IA segun haya documento adjunto
- `resources/views/goods-receipts/show.blade.php`
  - bloque destacado `Albaran adjunto pendiente de interpretar`
  - bloque de error con `Reintentar interpretacion IA`
- `resources/css/app.css`
  - estilos del CTA IA en formulario y del callout protagonista del detalle
- `tests/Feature/GoodsReceiptManagementTest.php`
  - cobertura nueva del flujo crear+interpretar, lineas vacias, IA desactivada, error y reintento

**Regla funcional confirmada:**
- La IA no confirma stock automaticamente.
- La IA no confirma la entrada.
- La IA solo propone datos y deja la revision/aplicacion en manos del usuario interno.

**Resultado de validacion:**
- `php artisan optimize:clear`: OK
- `php artisan test`: `369 passed` (1682 assertions)
- `npm run build`: OK

**Migraciones:**
- No hubo migraciones nuevas
- No fue necesario ejecutar `php artisan migrate`

**Control de alcance:**
- No se tocaron Google Calendar, facturacion ni importaciones Friesland/Edelvives
- No se tocararon datos productivos ni se uso `migrate:fresh`
- `.claude/` sigue fuera del commit

**Forge:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart` no es necesario salvo operativa posterior ligada a colas
---

## 2026-07-07 - Flujo manual compacto para entradas con IA desactivada o fallida (08:04:00 +02:00)

**Contexto:** En EDELVIVES, al crear una entrada con PDF y pulsar `Crear borrador e interpretar con IA`, la entrada se guardaba pero quedaba en `Sin interpretar` con el mensaje de configuracion pendiente, sin un flujo operativo claro para seguir manualmente desde la misma pantalla.

**Commit previo de partida:**
- `f7ca2846 fix: make goods receipt AI flow discoverable`

**Objetivo resuelto:**
- La entrada ya no queda en un callejon sin salida cuando la IA esta desactivada, falla o todavia no se ha ejecutado.
- El detalle de la entrada pasa a ser una pantalla operativa compacta y editable para almacen.

**Cambios funcionales principales:**
- `app/Http/Controllers/GoodsReceiptController.php`
  - el aviso al crear con IA desactivada ahora deja claro que la entrada puede completarse manualmente
  - la vista `show` recibe `lineValues` y `searchEndpoint` para editar lineas desde el propio detalle
  - si una entrada existente no tiene lineas, la pantalla prepara una linea vacia para continuar sin pasos extra
- `resources/views/goods-receipts/show.blade.php`
  - redisenada como detalle operativo editable para borradores
  - banda compacta superior con cliente, proveedor, fecha, estado IA y estado stock
  - botonera directa con `Descargar PDF`, `Reintentar IA` cuando procede, `Anadir linea manual`, `Guardar` y `Confirmar entrada`
  - bloque operativo claro: `Documento guardado. Puedes interpretarlo con IA o anadir lineas manualmente.`
  - estados mas utiles: `IA desactivada`, `IA sin ejecutar`, `Propuesta lista`, `Error IA`
  - mensaje fijo de seguridad: `El stock no se aplicara hasta confirmar la entrada`
  - cabecera y lineas editables desde la misma vista para borradores
- `resources/views/goods-receipts/_line-row.blade.php`
  - se compacta cada linea para reducir scroll y ruido visual
- `resources/views/goods-receipts/_ai-proposal-panel.blade.php`
  - copy mas claro: aplicar lineas no suma stock
  - CTA simplificado a `Aplicar lineas`
- `resources/js/app.js`
  - soporte para varios botones `Anadir linea manual` dentro de la misma pantalla operativa
- `resources/css/app.css`
  - layout de escritorio mas compacto para la operativa de entradas
  - chips de estado, grid de detalle y espaciado reducido

**Reglas funcionales verificadas:**
- La IA nunca confirma stock automaticamente
- Aplicar lineas desde propuesta IA no aplica stock
- El stock solo se aplica al confirmar manualmente la entrada
- La confirmacion sigue siendo idempotente
- Si la IA falla, la entrada y el documento permanecen disponibles
- El documento sigue en storage privado con descarga protegida
- El sistema lee `OPENAI_RECEIPT_ENABLED` y `OPENAI_RECEIPT_MODEL` desde `config/services.php`

**Cobertura y validacion:**
- `tests/Feature/GoodsReceiptManagementTest.php`
  - nueva cobertura para:
    - detalle editable con IA desactivada
    - actualizacion manual desde el propio detalle
    - copy y CTAs nuevos del flujo IA
- `php artisan optimize:clear`: OK
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `60 passed` (304 assertions)
- `php artisan test`: `371 passed` (1699 assertions)
- `npm run build`: OK

**Migraciones:**
- No hubo migraciones nuevas
- No fue necesario ejecutar `php artisan migrate`

**Control de alcance:**
- No se toco `.env`
- No se tocaron Google Calendar, facturacion ni importaciones Friesland/Edelvives
- No se tocaron datos productivos ni se uso `migrate:fresh`
- `.claude/` sigue fuera del commit

**Forge cuando toque desplegar este hito:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart`
---

## 2026-07-07 - Flujo compacto final, borrado seguro y mensajes IA mas claros (18:46:08 +02:00)

**Contexto:** Se remata el flujo de entradas de mercancia para que almacen pueda trabajar con menos scroll, la IA no deje mensajes ambiguos y superadmin pueda borrar entradas de forma segura incluso si el stock ya se aplico.

**Commit previo de partida:**
- `38976d56 fix: simplify goods receipt AI fallback workflow`

**Objetivo resuelto:**
- La pantalla de detalle de entrada queda mas compacta y operativa.
- Superadmin ya puede borrar entradas con confirmacion fuerte y reversa controlada de stock.
- El flujo IA informa mejor cuando hay documento guardado, cuando falla y cuando el modelo/documento no es compatible.

**Cambios funcionales principales:**
- `app/Services/GoodsReceipts/GoodsReceiptDeletionService.php`
  - nuevo servicio para borrar entradas dentro de transaccion
  - si la entrada aplico stock, revierte cantidades por lote/ubicacion/articulo antes de borrar
  - bloquea el borrado con `ValidationException` si la reversa dejaria inconsistencia o stock insuficiente
- `app/Http/Controllers/GoodsReceiptController.php`
  - nueva accion `destroy()` para borrado seguro
  - mantiene mensajes mas claros en detalle y flujo IA
- `routes/web.php`
  - nueva ruta `goods-receipts.destroy` solo para `superadmin`
- `resources/views/goods-receipts/index.blade.php`
  - boton de borrar solo visible para superadmin con confirmacion fuerte
- `resources/views/goods-receipts/show.blade.php`
  - toolbar y bloque de trabajo aun mas compactos
  - mensajes nuevos: `Documento guardado...`, `No se pudo interpretar el documento...`, bloque persistente `Error IA`
- `app/Services/GoodsReceipts/OpenAiGoodsReceiptExtractor.php`
  - mejor manejo de errores del modelo para PDF/documento visual no compatible
  - prompt ajustado para priorizar nombre de proveedor mas reconocible por almacen
- `app/Services/GoodsReceipts/GoodsReceiptAiExtractionService.php`
  - matching de proveedor mas tolerante por nombre normalizado y coincidencia parcial
- `resources/views/goods-receipts/_ai-proposal-panel.blade.php`
  - ayuda rapida para crear proveedor si la IA detecta uno sin coincidencia automatica
- `tests/Feature/GoodsReceiptManagementTest.php`
  - cobertura nueva de borrado seguro y de los mensajes/CTAs reales del flujo compacto

**Notas tecnicas importantes para el siguiente relevo:**
- La IA de entradas ya usa el fichero real en multimodal (`input_file` para PDF, `input_image` para imagenes); no depende solo de texto extraido.
- No se han anadido migraciones nuevas.
- `.claude/` sigue fuera del control de versiones.

**Cobertura y validacion real:**
- `php artisan optimize:clear`: OK
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `66 passed` (332 assertions)
- `php artisan test`: `377 passed` (1727 assertions)
- `npm run build`: OK

**Migraciones:**
- No hubo migraciones nuevas
- No fue necesario ejecutar `php artisan migrate`

**Control de alcance:**
- No se toco `.env`
- No se tocaron Google Calendar, facturacion ni importaciones Friesland/Edelvives
- No se tocaron datos productivos ni se uso `migrate:fresh`

**Forge cuando toque desplegar este hito:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart`

---

## 2026-07-07 - Entradas realmente borrables y operativa manual visible (19:03:06 +02:00)

**Contexto:** El trabajo anterior ya tenia la base funcional del borrado seguro, pero el usuario reporto que la UX seguia sin cumplir: el boton `Borrar` no destacaba lo suficiente, el detalle aun ocupaba demasiado alto y `Anadir linea manual` no se percibia como accion principal para trabajar sin IA.

**Commit previo de partida:**
- `4271575d fix: compact goods receipt workflow and add safe deletion`

**Objetivo resuelto:**
- Superadmin ve claramente `Borrar` en el listado de entradas.
- El detalle de entrada en borrador muestra `Anadir linea manual` como accion visible y repetida.
- El layout del detalle queda mas corto y operativo, con documento en una franja compacta y el bloque de lineas entrando en el primer pantallazo de escritorio.
- No se ha seguido desarrollando la logica de IA; solo se ha compactado su presencia visual.

**Cambios principales realizados:**
- `resources/views/goods-receipts/index.blade.php`
  - mensaje de confirmacion de borrado ajustado al texto funcional pedido por negocio
- `resources/views/goods-receipts/show.blade.php`
  - boton exacto `Anadir linea manual` en cabecera y en bloque de lineas
  - cabecera aun mas compacta
  - `Datos basicos` condensados
  - documento movido a una sola franja compacta con `Ver/Descargar` o `Adjuntar/Cambiar archivo`
  - mensaje `Sin lineas todavia.` visible antes del bloque editable cuando la entrada aun no tiene lineas guardadas
- `resources/css/app.css`
  - menos padding, menos gaps, menos sombra y menos altura en la operativa de entradas
  - estilo mas visible para el boton `Borrar`
  - estilo de accion protagonista para `Anadir linea manual`
  - nueva franja compacta de documento
- `tests/Feature/GoodsReceiptManagementTest.php`
  - tests actualizados al texto y layout reales
  - nueva cobertura para que `administracion` no vea `Borrar`
  - nueva cobertura para que `administracion` y `cliente` tampoco puedan borrar por backend

**Validacion funcional y visual real:**
- `php artisan optimize:clear`: OK
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `69 passed` (339 assertions)
- `php artisan test`: `380 passed` (1734 assertions)
- `npm run build`: OK
- Verificacion visual local en navegador embebido:
  - listado `/entradas`: `Borrar` visible para superadmin
  - detalle `/entradas/{id}` en borrador: `Anadir linea manual` visible
  - detalle mas compacto con documento en franja y bloque de lineas entrando en el primer pantallazo desktop

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- No hubo migraciones nuevas
- No se trabajo la logica de IA, solo su presencia residual como estado pequeno dentro del flujo manual

**Forge cuando toque desplegar este hito:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart`

---

## 2026-07-07 - Reversion de stock robusta al borrar entradas confirmadas + fix IA en PDF escaneado (19:46:59 +02:00)

**Contexto:** El usuario reporto que, pese al trabajo previo, seguia sin poder confiar en el borrado de entradas CONFIRMADAS con stock aplicado (bloqueo por "exceso de prudencia") y que documentos EDELVIVES escaneados (MONDI, LECTA) quedaban sin interpretar por IA. Se pidio diagnostico, correccion minima y verificacion real (no solo tests), sin tocar `.env` ni hacer cambios directos en BD.

**Commit previo de partida:**
- `4a46c58d fix: make goods receipts deletable and manually editable`

**Diagnostico:**
- `GoodsReceiptDeletionService` localizaba la partida de stock a revertir con una heuristica (`client_id + item_id + location_id + lot + units_per_pallet`). Cuando `location_id` de la linea era `null` (caso muy habitual), Eloquent genera `WHERE location_id = NULL`, que en SQL nunca es verdadero. Esto hacia que no se encontrara ninguna partida, `availableUnits` se calculaba como `0` y saltaba el error de "dejaria stock incoherente", bloqueando borrados legitimos.
- `OpenAiGoodsReceiptExtractor` enviaba `detail: 'high'` tambien en los content parts `input_file` (PDF). Ese campo solo es valido en `input_image` segun la API de OpenAI Responses; en `input_file` es un campo no reconocido que puede provocar el rechazo de la peticion, afectando especificamente a los PDFs escaneados (el caso que el usuario reporto).

**Cambios principales realizados:**
- `app/Services/GoodsReceipts/GoodsReceiptDeletionService.php`
  - la busqueda de partidas a revertir ahora se ancla en `goods_receipt_id` (FK ya usada por `GoodsReceiptStockApplicationService` para aplicar stock), agrupando candidatas por `item_id` en vez de heuristica fragil por ubicacion/lote
  - si hay varias partidas candidatas para el mismo articulo, se prioriza la que coincide exactamente en ubicacion/lote/uds-pallet, y si no hay coincidencia exacta (partida editada manualmente tras confirmar) se usa la de mayor cantidad disponible
  - se mantienen los bloqueos reales: sin partida vinculada a la entrada (ya movida/enviada) o cantidad insuficiente para revertir sin dejar negativo -> `ValidationException` clara, todo dentro de `DB::transaction()`
- `app/Services/GoodsReceipts/OpenAiGoodsReceiptExtractor.php`
  - eliminado el campo `detail` en el content part `input_file` (PDF); se mantiene solo en `input_image` donde es valido
- `tests/Feature/GoodsReceiptManagementTest.php`
  - `test_superadmin_puede_borrar_confirmada_sin_ubicacion_asignada` (reproduce y corrige el bug de `location_id` null)
  - `test_superadmin_puede_borrar_confirmada_tras_editar_partida_manualmente` (resiliencia ante partida editada a mano tras confirmar)
  - `test_superadmin_ve_boton_borrar_en_entrada_confirmada_y_cancelada`
  - `test_superadmin_puede_borrar_entrada_cancelada` (borrado real, no solo visibilidad)
  - `test_superadmin_puede_borrar_entrada_confirmada_sin_stock_aplicado`
  - `test_anadir_linea_manual_desde_detalle_de_borrador_no_aplica_stock_y_confirmar_si` (linea manual anadida a un borrador YA EXISTENTE via `update()`, no solo al crear; confirma que no aplica stock hasta confirmar y que confirmar aplica exactamente una vez)
  - `test_propuesta_ia_fake_mondi_detecta_proveedor_y_lineas` (fake de `EDV_10_MONDI.pdf`: proveedor Mondi, albaran 800916937, SKU 180148050, 62 uds/31 pallets/2 uds-pallet — proveedor coincide y se aplica sin stock hasta confirmar)
  - `test_propuesta_ia_fake_lecta_sin_proveedor_existente_propone_crear` (fake de `EDV_17_LECTA.pdf`: proveedor LECTA/CARTIERE DEL GARDA SPA no existente, SKU 70000742, propone "Crear proveedor")

**No se toco (ya estaba correcto):**
- `routes/web.php`: `goods-receipts.destroy` ya protegida con `minimum.role:superadmin`
- `resources/views/goods-receipts/index.blade.php`: el boton `Borrar` ya se renderiza sin condicion de `status`, oculto correctamente para almacen/administracion/cliente
- `GoodsReceiptAiExtractionService` / `GoodsReceiptAiExtractionResult`: matching de proveedor (exacto + parcial tolerante) y auto-correccion de pallets/picos ya robustos
- `_ai-proposal-panel.blade.php`: enlace "Crear proveedor" y propuesta totalmente editable ya implementados
- Si en el entorno real sigue apareciendo "IA desactivada", es por `OPENAI_RECEIPT_ENABLED=false` en `.env` (no se toca `.env` por restriccion explicita), no un defecto de codigo

**Cobertura y validacion real:**
- `php artisan optimize:clear`: OK
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `77 passed` (424 assertions)
- `php artisan test`: `388 passed` (1819 assertions)
- `npm run build`: OK

**Verificacion visual real en navegador embebido (obligatoria, no solo tests):**
- Login como superadmin (`codex.superadmin.local@example.com`, entorno local, DB local `wms_laravel` en `127.0.0.1`, no produccion)
- Creada entrada real via UI (`/entradas/crear` -> linea manual SKU-VERIF-CONFIRM, 500 uds) y confirmada via UI (`/entradas/2/confirmar`) -> estado `CONFIRMADA`, `Stock aplicado`, 1 partida `Disponible`
- En `/entradas`, la entrada `CONFIRMADA` mostro accion `Borrar` junto a `Ver` (tabla desktop y tarjeta movil) — capturado en pantalla
- Click real en `Borrar` -> dialogo de confirmacion nativo disparado con el texto exacto de la vista -> aceptado -> `Entrada borrada correctamente.`
- Verificado por tinker (solo lectura) tras el borrado: la entrada ya no existe y no queda ningun `StockPallet` con `goods_receipt_id = 2` ni con el SKU de la linea, confirmando reversion real de stock
- Nota: para poder iniciar sesion se cambio la contrasena de un usuario superadmin de pruebas ya existente (`codex.superadmin.local@example.com`) via `tinker`; se pidio autorizacion expresa al usuario antes de continuar (bloqueado inicialmente por el clasificador de seguridad por ser escritura directa en BD) y el usuario autorizo seguir con esa cuenta y contrasena para esta sesion de pruebas.

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- No hubo migraciones nuevas ni `migrate:fresh`
- No se borraron datos manualmente desde BD (el unico borrado fue via la propia aplicacion, boton `Borrar`, con autorizacion previa del usuario para el cambio de contrasena de prueba)
- No se uso `force push`

**Forge cuando toque desplegar este hito:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart`

---

## 2026-07-07 - Autocompletado AJAX y creacion rapida de articulos en lineas manuales de entradas (20:29:34 +02:00)

**Contexto:** El usuario reporto que, al anadir una linea manual en una entrada, el campo de articulo era "demasiado manual": no buscaba articulos existentes de forma dinamica ni ofrecia crear el articulo si no existia.

**Commit previo de partida:**
- `133c29d4 fix: allow superadmin to delete all goods receipts and restore receipt AI`

**Diagnostico:** La busqueda AJAX de articulos ya existia (`ajax.items`, componente `createAutocomplete` en `resources/js/app.js`, ya usado en la linea de entrada via `data-receipt-item-picker`) y ya autorellenaba SKU/descripcion/uds-pallet/ubicacion al seleccionar un articulo existente. Lo que faltaba era la parte de creacion: cuando la busqueda no encontraba nada, solo aparecia un aviso de texto pasivo (se creaba el articulo en silencio al guardar la entrada), sin CTA explicito ni confirmacion, y sin que el articulo quedase "seleccionado" (con `item_id`) de forma inmediata.

**Cambios principales realizados:**
- `app/Services/GoodsReceipts/GoodsReceiptItemResolver.php`
  - nuevo metodo publico `createOrReuseForQuickAdd()`: si el SKU ya existe para el cliente, devuelve el articulo existente (`created: false`); si no existe, lo crea con SKU/descripcion/uds-pallet (`created: true`)
- `app/Http/Requests/QuickCreateGoodsReceiptItemRequest.php` (nuevo)
  - autoriza solo a `almacen` o superior (`canAccessRole(Role::ALMACEN)`)
  - valida `client_id`, `sku`, `description`, `units_per_pallet`
- `app/Http/Controllers/GoodsReceiptController.php`
  - nueva accion `quickCreateItem()`: valida, resuelve via el resolver, devuelve JSON con el articulo (creado o reutilizado) y mensaje
- `routes/web.php`
  - nueva ruta `POST /entradas/articulos` -> `goods-receipts.items.quick-create`, protegida con `minimum.role:almacen`
- `resources/views/layouts/dashboard.blade.php`
  - anadido `<meta name="csrf-token">` (no existia; necesario para peticiones AJAX POST autenticadas)
- `resources/views/goods-receipts/_line-row.blade.php`
  - mensaje de "sin resultados" del picker cambiado a `Sin resultados. Crear articulo nuevo.`
  - nuevo bloque `data-line-create-item` (oculto por defecto) con boton `Crear articulo nuevo` y una zona de feedback
  - el picker expone el endpoint de creacion via `data-create-item-endpoint`
- `resources/js/app.js`
  - nuevo helper `csrfToken()`
  - `createAutocomplete()` gana un hook `onNoResults(query)` que se dispara cuando una busqueda termina sin resultados
  - `setupGoodsReceiptLines()` conecta el hook: al no haber resultados se muestra el boton `Crear articulo nuevo`; al pulsarlo se pide confirmacion con `confirm('No existe un articulo con esta referencia. ¿Quieres crearlo para este cliente?')`; si se confirma y SKU/descripcion/uds-pallet estan completos (los mismos campos ya visibles de la linea, sin duplicar formulario), se llama al endpoint AJAX; la respuesta reutiliza el mismo camino de seleccion (`autocomplete.setItem()`) que un resultado normal de busqueda, dejando el articulo nuevo (o el reutilizado si el SKU ya existia) seleccionado en la linea
- `resources/css/app.css`
  - estilo minimo `.goods-receipt-line-create-item` para el nuevo bloque

**Reglas funcionales verificadas:**
- La busqueda AJAX sigue filtrando por cliente de la entrada (reutiliza `ajax.items`, ya scoped por `client_id`)
- Un articulo de un cliente no aparece en la busqueda de otro cliente
- Guardar una linea (con articulo existente o recien creado) no aplica stock
- Confirmar la entrada sigue siendo el unico punto que aplica stock, de forma idempotente
- Si el SKU ya existe para el cliente, la creacion rapida devuelve el articulo existente y no crea duplicados
- Todo el flujo funciona igual con la IA desactivada (la linea manual es independiente del flujo IA)

**Cobertura y validacion real:**
- `php artisan optimize:clear`: OK
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `82 passed` (440 assertions)
- `php artisan test`: `395 passed` (1841 assertions)
- `npm run build`: OK
- Tests nuevos:
  - `tests/Feature/AjaxSearchTest.php`: busqueda solo por descripcion, scope de cliente para roles internos con `client_id` explicito
  - `tests/Feature/GoodsReceiptManagementTest.php`: creacion rapida por superadmin, por almacen, no duplica SKU existente, cliente no autorizado (403), validacion de datos obligatorios (422)

**Verificacion visual real en navegador embebido:**
- Login como superadmin de pruebas local, entrada borrador `UI-CHECK-001` (cliente `CLIENTE UI CODEX`, datos locales de verificacion, no productivos)
- Buscar `VERIF` en el campo de articulo de una linea manual -> aparece `SKU-VERIF-CONFIRM` en el desplegable sin recargar pagina
- Seleccionar el resultado -> SKU, descripcion y uds/pallet se autorellenan
- Buscar una referencia inexistente (`REF-INEXISTENTE-001`) -> aparece `Sin resultados. Crear articulo nuevo.` y el boton correspondiente
- Pulsar `Crear articulo nuevo` -> dialogo de confirmacion con el texto exacto pedido -> aceptar -> el articulo se crea via AJAX y queda seleccionado en la linea (verificado tambien por tinker en modo lectura: articulo creado con el `client_id` correcto)
- Guardar la linea -> `Entrada actualizada correctamente.`, `Stock: Pendiente de confirmar` (verificado por tinker en modo lectura: `stock_applied_at` sigue `null`, cero `stock_pallets` para esa entrada)

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- No hubo migraciones nuevas
- No se borraron datos manualmente desde BD (solo lecturas por tinker para verificar; la unica escritura de prueba fue via la propia aplicacion, en datos locales de verificacion ya existentes de sesiones anteriores)
- No se uso `force push`

**Forge cuando toque desplegar este hito:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart`

---

## 2026-07-07 - Fix: desplegable de articulo descuadrado y bloqueaba el campo de cantidad (20:50:57 +02:00)

**Contexto:** El usuario reporto, con captura de pantalla, que el desplegable de busqueda de articulo en una linea manual aparecia descuadrado (renderizado muy por debajo, superpuesto con los botones de pie de pagina) y que, una vez seleccionaba o indicaba el articulo, no podia escribir la cantidad de pallets.

**Commit previo de partida:**
- `ca94094d feat: add ajax item lookup to goods receipt lines`

**Diagnostico real (confirmado en navegador, no solo por lectura de codigo):** El desplegable usa `position: fixed` con coordenadas calculadas en JS a partir de `getBoundingClientRect()` (pensado para "flotar" fuera de la tarjeta de la linea). El contenedor `.goods-receipt-line-card` (y, en el formulario de creacion, tambien `.item-form-card`) tiene `backdrop-filter: blur(...)` para el efecto cristal. Segun la especificacion CSS, `backdrop-filter` (igual que `transform` o `filter`) crea un nuevo *containing block* para los descendientes `position: fixed`, asi que el navegador no posicionaba el panel respecto al viewport como esperaba el JS, sino respecto a esa tarjeta con blur. Se confirmo con `element.offsetParent`, que devolvia la tarjeta en vez de `null`. El panel, mal posicionado, quedaba flotando sobre otros campos de la pagina (incluida la propia columna de "Total uds"), interceptando los clics y dando la sensacion de que "no dejaba escribir".

**Cambios realizados:**
- `resources/views/goods-receipts/_form.blade.php`
  - la tarjeta del formulario de creacion gana una clase adicional `goods-receipt-form-card` para poder apuntarla de forma especifica sin afectar a `.item-form-card` de otros modulos (clientes, articulos, ubicaciones, proveedores, perfil, stock, usuarios, almacenes, que tambien usan esa clase compartida)
- `resources/css/app.css`
  - se quita `backdrop-filter` especificamente de `.goods-receipt-line-card`, `.goods-receipt-form-card` y `.goods-receipt-workbench` (los tres contenedores reales entre el picker y el `body` que rompian el `position: fixed`)
  - el fondo de esas tarjetas ya es ~97% opaco, asi que quitar el blur no cambia nada visualmente

**Verificacion real en navegador (antes y despues del fix):**
- Antes: `panel.offsetParent` devolvia la tarjeta de la linea; la posicion calculada por JS (`panel.style.top`) no coincidia con la posicion real renderizada (diferencia de cientos de pixeles)
- Despues: `panel.offsetParent` es `null` (viewport real) y el panel se renderiza justo debajo del campo de busqueda, tanto en el formulario de creacion (linea 2 de varias) como en el detalle de una entrada existente
- Se repitio el flujo completo: buscar articulo existente -> seleccionar -> el foco y la escritura en "Total uds" funcionan con normalidad y los pallets/pico se recalculan correctamente

**Cobertura y validacion:**
- No se ha anadido un test automatizado nuevo para este fix: es un problema de posicionamiento CSS (containing block de `position: fixed`) que no se puede verificar de forma fiable con los tests HTTP/Blade existentes del proyecto (no hay suite de navegador headless); se verifico en vivo con el navegador embebido en su lugar
- `php artisan optimize:clear`: OK
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `82 passed` (440 assertions)
- `php artisan test`: `395 passed` (1841 assertions)
- `npm run build`: OK

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- No hubo migraciones nuevas
- No se toco ningun otro modulo que comparte `.item-form-card` (clientes, articulos, ubicaciones, proveedores, perfil, stock, usuarios, almacenes quedan exactamente igual)
- No se uso `force push`

**Forge cuando toque desplegar este hito:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart`

---

## 2026-07-07 - Proveedor manual por AJAX, edicion de entradas confirmadas para superadmin (21:42:25 +02:00)

**Contexto:** El usuario pidio tres cosas sobre el mismo modulo de entradas: (1) poder escribir manualmente un proveedor nuevo al crear/editar una entrada, guardandolo para los 3 roles internos; (2) revisar por que, segun el, no podia seguir editando/anadiendo lineas a un borrador ya guardado; (3) permitir que solo superadmin pueda editar (no solo borrar) una entrada ya CONFIRMADA, algo que hasta ahora bloqueaba el sistema para todos sin excepcion.

**Commit previo de partida:**
- `c19c41e2 fix: correct goods receipt article dropdown position and unblock quantity input`

**Punto 2 (edicion de borrador ya guardado): no se pudo reproducir el bug.** Se probo en vivo, en navegador real, con superadmin y con almacen: abrir `/entradas/{id}/editar`, guardar cambios de cabecera, volver al detalle, anadir una linea manual nueva y guardar desde el workbench — todo funciono correctamente en ambos roles, sin bloqueos. La hipotesis mas probable es que lo que el usuario vio fue el mismo bug de posicionamiento del desplegable (`backdrop-filter` rompiendo `position: fixed`) corregido en el commit anterior, que podia interceptar clics sobre otros campos de la fila y dar sensacion de pantalla bloqueada. Si el problema persiste tras esta sesion, hace falta un paso a paso mas concreto (rol exacto, boton exacto, si aparece algun error) para poder reproducirlo.

**Punto 1 - Proveedor manual (nuevo flujo AJAX, mismo patron que los articulos):**
- `app/Http/Controllers/AjaxSearchController.php`
  - nuevo metodo `suppliers()`: busca proveedores activos por nombre, proveedores globales (`client_id` nulo) o del cliente indicado, solo para roles `almacen` o superior
- `app/Services/GoodsReceipts/GoodsReceiptSupplierResolver.php` (nuevo)
  - `createOrReuseForQuickAdd()`: si ya existe un proveedor con ese nombre (comparacion insensible a mayusculas) para el cliente o global, lo reutiliza; si no, lo crea asociado al cliente de la entrada
- `app/Http/Requests/QuickCreateGoodsReceiptSupplierRequest.php` (nuevo)
  - autoriza solo a `almacen` o superior
- `app/Http/Controllers/GoodsReceiptController.php`
  - nueva accion `quickCreateSupplier()`
- `routes/web.php`
  - `GET /ajax/suppliers` -> `ajax.suppliers`
  - `POST /entradas/proveedores` -> `goods-receipts.suppliers.quick-create` (`minimum.role:almacen`)
- `resources/views/goods-receipts/_supplier-picker.blade.php` (nuevo, parcial compartido)
  - sustituye el `<select>` de Proveedor por el mismo componente de autocompletado AJAX que ya usan los articulos de la linea
- `resources/views/goods-receipts/_form.blade.php` y `resources/views/goods-receipts/show.blade.php`
  - usan el nuevo parcial en vez del `<select>` plano
- `resources/js/app.js`
  - `createAutocomplete()` ya tenia el hook `onNoResults` (reutilizado de la funcionalidad de articulos)
  - nueva funcion `setupSupplierPicker()`: busca, muestra "Crear proveedor nuevo" si no hay resultados, pide confirmacion (`confirm('No existe un proveedor con este nombre. ¿Quieres crearlo?')`) y crea por AJAX, dejando el proveedor nuevo (o el existente si el nombre ya estaba usado) seleccionado en la entrada
- Funciona igual para `superadmin`, `administracion` y `almacen` (mismo nivel de permiso que ya tenian para gestionar proveedores desde `/proveedores`)

**Punto 3 - Edicion de entradas confirmadas (solo superadmin):**
- `app/Services/GoodsReceipts/GoodsReceiptStockApplicationService.php`
  - nuevo metodo publico `revert()`: reversa robusta anclada en `goods_receipt_id` (misma logica que ya se uso para el borrado seguro), movida aqui para poder reutilizarla tanto al borrar como al editar
- `app/Services/GoodsReceipts/GoodsReceiptDeletionService.php`
  - simplificado para delegar la reversa de stock en `GoodsReceiptStockApplicationService::revert()` en vez de duplicar la logica
- `app/Http/Controllers/GoodsReceiptController.php`
  - `edit()`/`update()` ya no bloquean siempre que la entrada este confirmada: bloquean si esta CANCELADA (para cualquier rol) o si esta CONFIRMADA y el usuario no es superadmin
  - si superadmin edita una entrada CONFIRMADA con stock aplicado, `update()` hace, dentro de una unica transaccion: revertir el stock existente -> guardar cabecera y lineas nuevas -> volver a aplicar stock con los datos nuevos. Si la reversa dejaria stock negativo (por ejemplo, porque parte del stock ya se movio o se envio), se bloquea con un error claro y no se guarda ningun cambio
- `resources/views/goods-receipts/show.blade.php`
  - `$isEditable` ahora es verdadero para una entrada confirmada si el usuario es superadmin
  - el boton `Confirmar entrada` se oculta si la entrada ya esta confirmada (evita una doble confirmacion inutil, aunque ya estaba protegida por codigo)
  - aviso visible: "Estas editando una entrada CONFIRMADA como superadmin. Al guardar, el stock generado se revertira y se volvera a aplicar con los datos nuevos."
- `resources/views/goods-receipts/_form.blade.php`
  - mismo aviso adaptado para la pantalla completa de edicion (`/entradas/{id}/editar`)
- `resources/views/goods-receipts/index.blade.php`
  - el enlace `Editar` del listado (tabla y tarjeta movil) ahora tambien aparece en entradas confirmadas, solo para superadmin

**Cobertura y validacion real:**
- `php artisan optimize:clear`: OK
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `92 passed` (489 assertions)
- `php artisan test`: `407 passed` (1893 assertions)
- `npm run build`: OK
- Tests nuevos:
  - `tests/Feature/AjaxSearchTest.php`: busqueda de proveedores por nombre con scope cliente/global, acceso restringido a roles internos
  - `tests/Feature/GoodsReceiptManagementTest.php`: creacion rapida de proveedor por superadmin/almacen/administracion, no duplica nombre existente, cliente no autorizado (403), validacion de nombre obligatorio; superadmin edita entrada confirmada y el stock se revierte y reaplica sin duplicar (una sola partida con la cantidad nueva); almacen y administracion siguen bloqueados (403) para editar confirmadas; ninguna entrada CANCELADA es editable ni por superadmin; edicion de confirmada bloqueada con error claro si la reversa dejaria stock negativo, sin cambios parciales; el enlace Editar en el listado solo aparece para superadmin en confirmadas

**Verificacion visual real en navegador embebido:**
- Proveedor: se busco "SAICA" (sin resultados para el cliente de prueba), aparecio el boton "Crear proveedor nuevo", el dialogo de confirmacion mostro el texto exacto, y tras confirmar el proveedor quedo creado (scope correcto al cliente) y seleccionado en la entrada; se guardo la entrada completa y el proveedor quedo enlazado correctamente
- Edicion de confirmada: se creo una entrada, se confirmo (300 uds, 3 pallets), se edito como superadmin cambiando a 500 uds, se guardo, y el detalle mostro "Entrada confirmada actualizada. El stock se ha revertido y vuelto a aplicar con los datos nuevos.", con el stock generado mostrando 500 uds / 5 pallets en una unica partida (no 800, confirmando que no hubo duplicado)
- Se verifico que `almacen` no ve ni el enlace `Editar` ni el boton `Añadir línea manual` en esa misma entrada confirmada, y que un acceso directo por URL a `/entradas/{id}/editar` devuelve 403 para ese rol

**Nota de disciplina de datos:** durante la verificacion en vivo se borro un registro de prueba (`GoodsReceipt` id 3, sin stock aplicado) directamente por `tinker` en vez de usar el flujo de borrado de la propia aplicacion. Fue en base de datos local de desarrollo y sin stock implicado, pero no debio hacerse asi; se deja constancia explicita y no se ha repetido en el resto de la sesion.

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- No hubo migraciones nuevas
- No se uso `force push`

**Forge cuando toque desplegar este hito:**
- `Deploy Now`
- `php artisan migrate --force` no aplica en este hito
- `php artisan optimize:clear`
- `php artisan queue:restart`