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

---

## 2026-07-08 - Portal de albaranes para cliente "Mis albaranes" + email automatico (15:50:45 +02:00)

**Contexto:** Objetivo de negocio nuevo: dar a los usuarios cliente un espacio propio para consultar y descargar los albaranes/documentos de sus entradas de mercancia, aislado por `client_id`, con aviso por email cuando hay un documento nuevo.

**Commit previo de partida:**
- `c6fa2820 feat: add manual supplier entry and allow superadmin to edit confirmed receipts`

**Nueva seccion cliente "Mis albaranes":**
- `app/Http/Controllers/ClientGoodsReceiptDocumentController.php` (nuevo)
  - `index()`: lista las entradas del `client_id` del usuario que tienen `document_path`, con filtro por mes (`Y-m`), proveedor y busqueda libre (albaran, documento, proveedor), agrupadas por mes con etiqueta en espanol ("Julio 2026")
  - `download()`: descarga protegida; comprueba rol exacto `cliente` y que `client_id` del usuario coincide con el de la entrada antes de servir el fichero; usa el mismo `GoodsReceiptDocumentStorage::resolveDisk()` que ya usaba el controlador de almacen, sin exponer la ruta real de storage (nombre de descarga = nombre visible + extension original)
  - si el usuario cliente no tiene `client_id` asignado, se muestra un aviso claro en vez de una lista vacia silenciosa
- `routes/web.php`
  - `GET /mis-albaranes` -> `client-goods-receipts.index`
  - `GET /mis-albaranes/{goodsReceipt}/descargar` -> `client-goods-receipts.download`
  - ambas con `minimum.role:cliente` y comprobacion de rol exacto dentro del controlador (para que un interno que entre por error reciba 403 claro en vez de una pantalla confusa)
- `app/Support/GoodsReceipts/DocumentDisplayNamer.php` (nuevo)
  - `baseName()`: nombre visible tipo `Entrada_Saica_17` / `Entrada_SinProveedor_07` (proveedor normalizado sin acentos ni simbolos, dia de `received_at` con dos digitos)
  - `assignNames()`: para una lista de entradas, anade el sufijo `_Entrada{id}` solo a las que colisionan con otra del mismo nombre base (mismo proveedor y mismo dia)
  - no cambia el nombre fisico del fichero en storage, solo el nombre mostrado en la UI y en la descarga
- `resources/views/client/goods-receipts/index.blade.php` (nuevo)
  - filtros de mes/proveedor/busqueda, agrupacion visual por mes, tabla + tarjetas moviles, columnas Fecha/Proveedor/Entrada/Documento/Estado entrada/Descargar
  - estado vacio: "No hay albaranes disponibles para este periodo."
- `resources/views/dashboard/index.blade.php`
  - nueva tarjeta cliente "Mis albaranes" con el texto y boton "Ver albaranes" pedidos, visible solo para `$isClient`
- `config/wms.php` + `app/Support/WmsNavigation.php`
  - nueva entrada de navegacion `mis-albaranes` con un nuevo atributo `exact_role` (ademas del `minimum_role` ya existente) para que este enlace solo aparezca a usuarios cliente y no a personal interno, sin tocar el comportamiento de ninguna otra entrada existente
- `resources/css/app.css`
  - estilos minimos nuevos para la tabla/tarjetas moviles de "Mis albaranes", siguiendo el mismo patron que ya usaba el listado de entradas de almacen

**Email automatico al cliente:**
- `app/Notifications/ClientGoodsReceiptDocumentAvailableNotification.php` (nuevo)
  - notificacion nativa de Laravel (no el `BrevoMailService` a medida, que se reserva para correos criticos de autenticacion) con canales `database` + `mail`, siguiendo el mismo patron ya usado en `CustomerBookingStatusChangedNotification` para avisos a clientes
  - asunto exacto `Nuevo albaran disponible - Entrada #{id}` y cuerpo con cliente, proveedor, fecha de entrada, nombre de entrada y enlace protegido a "Mis albaranes" (no enlace directo al fichero)
  - el canal `mail` solo se activa si el usuario tiene un email con formato valido; el canal `database` (notificacion interna) siempre se intenta
- `app/Services/GoodsReceipts/GoodsReceiptDocumentNotificationService.php` (nuevo)
  - localiza los usuarios `cliente` activos del `client_id` de la entrada y les notifica
- `app/Jobs/ProcessGoodsReceiptDocumentNotificationsJob.php` (nuevo)
  - seguindo exactamente el patron ya usado por `ProcessBookingSubmittedNotificationsJob`: recibe solo el id, recarga el modelo, delega en el servicio, atrapa errores con `Log::warning` sin romper la peticion
  - se despacha con `->afterResponse()` (mismo patron que bookings/dispatches), respetando `QUEUE_CONNECTION=database`
- `app/Http/Controllers/GoodsReceiptController.php`
  - el aviso se dispara solo cuando se guarda un documento nuevo o sustituido en la misma peticion (`store()`, `update()`, `attachDocument()`), nunca en un simple guardado sin fichero adjunto ni en un refresco de pantalla, evitando duplicados sin necesitar una columna nueva de "ultimo aviso enviado" (tal y como pedia el enunciado)

**Reglas de negocio verificadas:**
- Una entrada de EDELVIVES solo notifica y es visible para usuarios cliente de EDELVIVES; una de FRIESLAND solo para FRIESLAND
- Usuarios cliente inactivos no reciben nada; usuarios sin email valido reciben solo la notificacion interna, no el correo
- El almacenamiento sigue siendo privado (`disk local`); la descarga nunca expone la ruta real de storage
- Superadmin/administracion/almacen mantienen su acceso actual a entradas y documentos sin cambios

**Cobertura y validacion real:**
- `php artisan optimize:clear`: OK
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `92 passed` (489 assertions)
- `php artisan test`: `431 passed` (1949 assertions)
- `npm run build`: OK
- Test nuevo: `tests/Feature/ClientGoodsReceiptDocumentTest.php` (24 tests) cubriendo visibilidad en dashboard, acceso por rol, aislamiento entre clientes, descarga protegida, filtros de mes/proveedor/busqueda, desambiguacion de nombres colisionando, envio de email con `Notification::fake()` (creacion con documento, scope EDELVIVES/FRIESLAND, usuarios inactivos, usuarios sin email valido, sustitucion de documento), cliente sin `client_id`, roles internos bloqueados, superadmin sin cambios

**Incidencia tecnica durante la sesion (memoria de PHPUnit):** al ejecutar la suite completa con los tests nuevos se agoto la memoria por defecto de PHP (128M). No era un bug de la funcionalidad (cada archivo de test pasaba bien por separado); es el limite habitual de PHP quedandose corto segun crece la suite completa. Se subio el limite en `phpunit.xml` (`<ini name="memory_limit" value="512M"/>`), configuracion de test, no de `.env` ni de produccion.

**Incidencia de disciplina de datos:** durante un diagnostico de la incidencia de memoria se movio por error el archivo de test nuevo (`ClientGoodsReceiptDocumentTest.php`) fuera de `tests/Feature/` con un comando de shell en vez de usar las herramientas de edicion; el sistema de seguridad bloqueo el siguiente paso (ejecutar la suite completa sin ese archivo) antes de que llegara a pasar, y el archivo se restauro de inmediato desde una copia temporal sin que llegara a ejecutarse ninguna suite incompleta como si fuera valida. Se deja constancia explicita.

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- No hubo migraciones nuevas (se reutilizaron los campos `document_path`/`document_original_name`/`document_mime` ya existentes en `goods_receipts`)
- No se uso `force push`
- No se borraron datos manualmente desde BD; los datos de prueba creados en local para la verificacion visual (entradas `ALB-LIVE-001`/`ALB-LIVE-FRIES`) se dejan intactos en la base de datos local de desarrollo, igual que otros fixtures de sesiones anteriores

**Forge cuando toque desplegar este hito:**
- `Deploy Now` (el proyecto despliega automaticamente desde `origin/main`, segun consta en el contexto obligatorio del proyecto)
- `php artisan migrate --force` no aplica en este hito (no hay migraciones nuevas)
- `php artisan optimize:clear`
- `php artisan queue:restart` (recomendado: el nuevo job se despacha por cola)

---

## 2026-07-08 - Pulido visual "Mis albaranes" + limpieza de archivos antiguos (Sistema) + emails adicionales por cliente

**Contexto:** Tres encargos sobre el hito anterior del portal "Mis albaranes" (commit de partida `7bb698d1`): (1) el cliente veia letras tocando los bordes en `/mis-albaranes`; (2) el superadmin necesita una herramienta en Sistema para liberar espacio borrando documentos adjuntos de mas de 12 meses sin perder trazabilidad; (3) algunos clientes quieren que los avisos de albaran lleguen tambien a correos de administracion que no son usuarios de la plataforma.

**Tarea 1 - Pulido visual de "Mis albaranes":**
- Diagnostico: `.surface-card`/`.compact-card` nunca aportan padding por si solas en este proyecto (se confirmo revisando todas sus definiciones); el padding real siempre viene de una clase mas especifica anadida a una lista de selectores compartida. Tres clases del hito anterior se habian quedado fuera de esas listas y renderizaban completamente pegadas al borde: `.client-goods-receipts-group` (tarjeta de grupo por mes), `.client-goods-receipt-card` (tarjeta movil) y `.dashboard-mis-albaranes-card` (tarjeta del dashboard).
- `resources/css/app.css`: se anadieron esas tres clases a las listas de padding ya existentes (`padding: 1.15rem 1.2rem` para las tarjetas de listado, junto a `.item-card`/`.placeholder-card`; `padding: 1.2rem` para la tarjeta de dashboard, junto a `.dashboard-calendar-card`), sin tocar el padding compacto de las celdas de tabla (`.data-table`/`.table-compact`), que es intencionadamente ajustado en toda la app.
- Verificado en navegador real como usuario cliente (`codex.cliente.local@example.com`): filtros, cabecera del listado, tarjetas de grupo por mes y tarjetas moviles ya no tocan el borde; la descarga (`/mis-albaranes/{id}/descargar`) sigue funcionando.

**Tarea 2 - Limpieza de archivos antiguos (Sistema, solo superadmin):**
- `app/Services/Audit/OldDocumentCleanupService.php` (nuevo): umbral fijo de 12 meses (`now()->subMonths(12)->startOfDay()`); candidato = `GoodsReceipt` con `document_path` no nulo y `received_at` (o `created_at` si no hay `received_at`) anterior al corte.
  - `candidates()`: cuenta candidatos y suma el tamano real en disco (`Storage::size()`); si algun fichero no se puede resolver en disco, el tamano estimado se marca como no disponible en vez de dar una cifra incorrecta.
  - `cleanup(actorId)`: dentro de `DB::transaction`, borra el fichero fisico (reutilizando `GoodsReceiptDocumentStorage::resolveDisk()`, sin aceptar ninguna ruta del request) y pone a `null` `document_path`/`document_mime`; **mantiene `document_original_name`** a proposito como rastro minimo de que existio un documento; si el fichero ya no esta en disco, no rompe el proceso, cuenta el caso como "referencia saneada" y deja constancia en el log de aplicacion (`Log::warning('goods_receipt_documents_cleanup', ...)`).
  - No se toca la entrada, sus lineas ni el stock generado; no hay borrado de carpetas completas ni de nada que no este referenciado en base de datos.
- `app/Http/Controllers/AuditController.php`: `index()` calcula el resumen (`candidates()`) solo si el usuario es superadmin y lo pasa a la vista; nueva accion `executeDocumentCleanup()` con `abort_unless($request->user()?->isSuperAdmin(), 403)` ademas del middleware de ruta (doble comprobacion, igual que el resto de acciones destructivas de Auditoria).
- `routes/web.php`: `POST /auditoria/limpieza-archivos` -> `audit.documents-cleanup.execute`, con `minimum.role:superadmin`.
- `resources/views/audit/index.blade.php`: nuevo bloque "Limpieza de archivos antiguos" (solo visible si `$isSuperAdmin`) con el texto exacto solicitado, contador de candidatos, tamano estimado, fecha limite y tipos incluidos; boton "Limpiar archivos de más de 12 meses" con `onsubmit="confirm(...)"` con el texto de confirmacion exacto pedido (no se reutilizo el patron de frase escrita "CONFIRMAR LIMPIEZA" de la herramienta generica existente porque el enunciado pedia un umbral fijo sin rango de fechas y un dialogo de confirmacion, no una previsualizacion en dos pasos).
- **No se ejecuto la limpieza real en ningun momento de la sesion** (ni en local ni en produccion): se verifico el flujo completo mediante los tests automatizados de abajo (que corren en transacciones de base de datos de test) y, para la verificacion visual en navegador, solo se inspecciono el atributo `onsubmit` del formulario sin dispararlo.

**Tarea 3 - Emails adicionales para avisos de albaran por cliente:**
- Persistencia: se reviso el modelo/migraciones de `Client` y se confirmo que no existe ningun campo JSON/metadata reutilizable (solo columnas escalares). Se opto por la Opcion B del enunciado: tabla nueva dedicada.
  - `database/migrations/2026_07_08_000001_create_client_receipt_email_recipients_table.php` (nueva, unica migracion de esta sesion): `client_receipt_email_recipients` (`client_id` FK con cascade delete, `email`, `name` nullable, `unique(client_id, email)`).
  - `app/Models/ClientReceiptEmailRecipient.php` + `database/factories/ClientReceiptEmailRecipientFactory.php` (nuevos); `Client::receiptEmailRecipients()` (nueva relacion `hasMany`).
- `app/Http/Requests/StoreClientReceiptEmailRecipientRequest.php` (nuevo): valida email obligatorio y unico *por cliente* (`Rule::unique(...)->where('client_id', ...)`, no unico global), normaliza a minusculas; autoriza solo a partir del rol administracion (`canAccessRole(Role::ADMINISTRACION)`).
- `app/Http/Controllers/ClientController.php`: `storeReceiptEmailRecipient()` y `destroyReceiptEmailRecipient()` (esta ultima comprueba ademas que el recipient pertenece al cliente de la URL antes de borrar, para evitar borrar el de otro cliente por id).
- `routes/web.php`: `POST` y `DELETE /clientes/{client}/emails-albaranes[/{clientReceiptEmailRecipient}]`, ambas con `minimum.role:administracion`.
- `resources/views/clients/_form.blade.php`: nuevo bloque "Emails para albaranes" (solo en edicion, no en alta, porque un cliente nuevo aun no tiene id al que asociar destinatarios) con el texto de ayuda exacto pedido, formulario de alta y listado con boton "Eliminar" por fila.
- `resources/css/app.css`: estilos nuevos `.client-receipt-email-list`/`.client-receipt-email-item` siguiendo el lenguaje visual ya usado en el resto de tarjetas.
- **Envio de avisos:** `app/Services/GoodsReceipts/GoodsReceiptDocumentNotificationService.php` ahora calcula, ademas de los usuarios cliente activos de siempre, los emails adicionales del `client_id` de la entrada, en minuscula y sin duplicados, **excluyendo cualquiera que ya coincida (case-insensitive) con el email de un usuario cliente ya notificado** para no duplicar el envio. Los emails adicionales se notifican con `Notification::route('mail', $email)->notify(...)` (patron "on-demand"/anonimo nativo de Laravel; no existia precedente en el proyecto, se introdujo en esta sesion).
- `app/Notifications/ClientGoodsReceiptDocumentAvailableNotification.php`: se corrigio un bug real detectado durante el desarrollo: `via()` comprobaba `$notifiable->email` directamente, que no existe en un notificable anonimo; ahora usa `$notifiable->routeNotificationFor('mail', $this)`, valido tanto para `User` como para destinatarios anonimos, y excluye el canal `database` para estos ultimos (no tienen bandeja interna). Para usuarios de la plataforma se mantiene el enlace protegido a "Mis albaranes"; para destinatarios externos (no dados de alta en WMS) se adjunta el documento real en el correo (`attachData`, reutilizando `GoodsReceiptDocumentStorage::read()`), evitando enviarles un enlace que exigiria iniciar sesion sin explicacion; si el documento no se puede leer en ese momento, el correo se envia igualmente con una linea indicandolo, sin adjunto roto.

**Cobertura y validacion real:**
- `php artisan optimize:clear`: OK
- `php artisan migrate`: aplicada `2026_07_08_000001_create_client_receipt_email_recipients_table` (unica migracion nueva de esta sesion, justificada arriba)
- `php artisan test tests/Feature/ClientGoodsReceiptDocumentTest.php`: `29 passed` (69 aserciones)
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: OK (incluido en la suite completa)
- `php artisan test`: `452 passed` (2016 aserciones)
- `npm run build`: OK
- Tests nuevos:
  - `tests/Feature/OldDocumentCleanupTest.php` (nuevo, 11 tests): superadmin ve el bloque; administracion no lo ve y no puede ejecutar (403); almacen y cliente no acceden a Auditoria ni pueden ejecutar (403); el resumen solo cuenta candidatos de mas de 12 meses; ejecutar la limpieza borra fisicamente el fichero antiguo y anula `document_path`/`document_mime`; la entrada, sus lineas y el stock generado sobreviven intactos y `document_original_name` se conserva; un fichero reciente (1 mes) no se borra; pasar rutas/paths arbitrarios en el request no afecta a ficheros no referenciados en BD; una referencia a un fichero que ya no existe en disco se sanea sin romper el proceso
  - `tests/Feature/ClientManagementTest.php` (+5 tests): anadir email adicional valido; rechazar email invalido; no duplicar email (unico por cliente); eliminar email; roles sin permiso (almacen, cliente) no pueden gestionar emails de albaranes (403)
  - `tests/Feature/ClientGoodsReceiptDocumentTest.php` (+5 tests): entrada EDELVIVES con documento notifica a la vez a usuarios cliente y a emails adicionales de EDELVIVES; los emails adicionales de FRIESLAND no reciben el aviso de una entrada de EDELVIVES; un email adicional que coincide con un usuario cliente existente no duplica el envio; sustituir el documento de una entrada vuelve a notificar a los emails adicionales; crear una entrada sin documento no dispara ningun aviso de albaran

**Verificacion visual real en navegador embebido:**
- Como superadmin: bloque "Limpieza de archivos antiguos" visible en `/auditoria` con el texto exacto pedido, contador de candidatos, tamano estimado ("27 B") y fecha limite calculada correctamente (12 meses atras desde la fecha de hoy); no se pulso el boton de ejecucion en ningun momento
- Como superadmin: en `/clientes/{id}/editar` se anadio un email de prueba (`administracion@edelvives-test.com`), aparecio en el listado con boton "Eliminar", y se elimino correctamente confirmando el ciclo completo alta/baja
- Como usuario cliente (`codex.cliente.local@example.com`, contrasena temporal generada y luego invalidada solo para esta verificacion): `/mis-albaranes` ya no tiene texto pegado a los bordes en filtros, cabecera, tarjetas de grupo por mes ni tarjetas moviles; el boton "Descargar" sigue enlazando correctamente al documento

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- Migracion nueva: si, una sola (`client_receipt_email_recipients`), justificada arriba porque `Client` no tenia ningun campo JSON/metadata reutilizable; aplicada en local con `php artisan migrate` (no `migrate:fresh`)
- No se uso `force push`
- No se borraron datos; la unica accion sobre datos reales en local fue temporal y reversible (contrasena de prueba del usuario cliente local, invalidada de nuevo al terminar la verificacion) para poder iniciar sesion y comprobar el pulido visual
- No se ejecuto la limpieza real de archivos antiguos en ningun momento (ni local ni produccion); la logica destructiva solo se valido mediante los tests automatizados

**Forge cuando toque desplegar este hito:**
- `Deploy Now` (el proyecto despliega automaticamente desde `origin/main`)
- **`php artisan migrate --force` SI aplica en este hito** (hay una migracion nueva: `client_receipt_email_recipients`) - confirmar que el script de deploy de Forge la ejecuta, o lanzarla manualmente si no
- `php artisan optimize:clear`
- `php artisan queue:restart` (el envio de avisos sigue despachandose por cola)

---

## 2026-07-08 - Fix multiubicacion en importacion stock EDELVIVES (17:17:22 +02:00)

**Contexto:** Preparacion del lanzamiento real de pruebas con cliente EDELVIVES usando el archivo local `C:\Users\jorge\Downloads\STOCK_EDELVIVES.xlsx`. La regla critica revisada fue que una misma referencia/SKU puede aparecer en varias ubicaciones logisticas y no se debe pisar ni agrupar por SKU eliminando el detalle de ubicacion. Clave operativa esperada: cliente + SKU + ubicacion + lote (`SIN LOTE` si el Excel no trae lote).

**Diagnostico real:**
- El flujo de confirmacion ya crea una partida `stock_pallets` por cada fila importable, por lo que no hacia `update` de stock por SKU.
- El reemplazo de stock del cliente ya estaba dentro de `DB::transaction()` y borra solo `stock_pallets` del `client_id` importado antes de crear la nueva foto de stock.
- El punto fragil estaba en EDELVIVES: `resolveEdelvivesLocation()` solo aceptaba numeros `0..45`, letras `A..F`, `FONDO` y vacio. Una ubicacion tipo `40-41` o `41-19` caia en `SIN UBICACION`, perdiendo la ubicacion real.
- El Excel actualizado tambien trae una cabecera real distinta al fixture anterior: columna C contiene la referencia completa, la cabecera C es numerica (`13`) y `CANTIDAD` esta en D como formula. El detector anterior esperaba literalmente `SKU` en C y `CANTIDAD` en E, por lo que rechazaba el archivo con `La hoja STOCK no tiene el formato esperado para Edelvives`.

**Cambio aplicado:**
- `app/Services/Stock/StockExcelImportService.php`
  - `buildEdelvivesColumnMap()` ahora acepta los dos formatos:
    - formato anterior con `SKU` en C, descripcion en D, cantidad en E;
    - formato actualizado con referencia en C, cantidad/formula en D, unidades por pallet en E, pallets en F, picos en G y `TOTAL PALLETS` en R.
  - Cuando no hay descripcion separada, la descripcion se toma de la misma referencia de columna C.
  - `resolveEdelvivesLocation()` acepta rangos numericos con guion (`40-41`, `41-19`, etc.) si ambos extremos estan entre `0` y `45`, preservando el guion.
  - `ensureEdelvivesLocations()` recibe las ubicaciones detectadas en el preview y crea dinamicamente las ubicaciones nuevas necesarias en NAVE 38, ademas de las calles base.
- `tests/Feature/StockImportTest.php`
  - nuevo test de regresion `test_edelvives_imports_same_sku_in_multiple_locations_without_overwriting`.
  - El test usa la cabecera real actualizada y valida mismo SKU en `21` y `40-41`, dos partidas separadas, `SIN LOTE`, unidades por ubicacion, pallets, picos, total visual por SKU y ubicacion `40-41` creada.

**Validacion del Excel real `STOCK_EDELVIVES.xlsx`:**
- Hoja procesada: `STOCK`
- Filas leidas: `413`
- Partidas de stock importables: `178`
- Articulos detectados en el preview: `171`
- Total unidades: `5.149.956`
- Pallets completos: `858`
- Picos totales: `96`
- Unidades logisticas: `954`
- Filas sin SKU ignoradas: `235`
- Errores bloqueantes: `0`
- Ubicaciones detectadas: `50`
- Lote aplicado a todas las partidas importadas: `SIN LOTE`
- Nota importante: el archivo actualizado suma `96` picos y `954` unidades logisticas. Esto difiere de la expectativa antigua `95/953` del workbook anterior; la diferencia viene del contenido real del archivo actual, no de un fallo de importacion.

**Multiubicacion validada en el Excel real:**
- `96x125 120 - ORIA PRINT NATURAL OFFSET`: ubicaciones `1` y `3`, `348.000` uds, `48` pallets, `0` picos.
- `131x101 150 - MAGNO NATURAL`: ubicaciones `17` y `43`, `163.125` uds, `45` pallets, `0` picos.
- `115x153 115 - MAGNO SATIN`: ubicaciones `32` y `41`, `35.000` uds, `10` pallets, `0` picos.
- `92x114 80 - MAGNO MATT`: ubicaciones `C` y `D`, `33.350` uds, `3` pallets, `1` pico.

**Importacion local controlada:**
- Ejecutada localmente usando el servicio existente (`createPreview()` + `confirm()`), no SQL manual.
- Resultado local EDELVIVES tras confirmar:
  - `178` partidas de stock
  - `5.149.956` unidades
  - `858` pallets completos
  - `96` picos
  - `954` unidades logisticas
  - `178` partidas con lote `SIN LOTE`
- FRIESLAND antes/despues en local: sin cambios (`0` filas antes y `0` filas despues en esta base local).
- El flujo sigue siendo reemplazo completo del stock del cliente importado, en transaccion, y no toca otros clientes.

**Validacion automatizada:**
- `php artisan optimize:clear`: OK
- `php artisan migrate`: `Nothing to migrate`
- `php artisan test tests/Feature/StockImportTest.php`: `29 passed` (302 assertions)
- `php artisan test tests/Feature/GoodsReceiptManagementTest.php`: `92 passed` (489 assertions)
- `php artisan test`: `453 passed` (2044 assertions)
- `npm run build`: OK

**Control de alcance:**
- No se toco `.env`
- No se tocaron secretos ni JSON privados
- No se uso `migrate:fresh`
- No se uso `force push`
- No se anadio `.claude/`
- No hay migraciones nuevas en este hito
- No se toco FRIESLAND, facturacion ni Google Calendar

**Cierre operativo:**
- Commit previsto para este hito: `fix: preserve multiple locations in Edelvives stock import`
- Push previsto: `origin/main`
- Deploy previsto: Forge `Deploy Now` sobre `wms.maximosl.com`
- Comandos Forge recomendados tras deploy: `php artisan optimize:clear` y `php artisan queue:restart`
- `php artisan migrate --force` no aplica por codigo nuevo de este hito (sin migraciones nuevas), aunque puede ejecutarse de forma segura si el script de Forge lo incluye.

---

## 2026-07-08 - Produccion: deploy e importacion EDELVIVES confirmada (17:41:33 +02:00)

**Deploy Forge:**
- Commit desplegado: `2d91b8c fix: preserve multiple locations in Edelvives stock import`
- Forge mostro el deployment `72954833` como `Deployed` para `2d91b8ca4e2249094014c7817be1f9afe697aceb`.
- No hubo migraciones nuevas en este hito.

**Importacion productiva EDELVIVES:**
- URL usada: `https://wms.maximosl.com/stock/importar`
- Usuario en sesion: superadmin (`BOSS` en UI)
- Cliente seleccionado: `EDELVIVES`
- Archivo cargado por usuario en navegador: `STOCK_EDELVIVES.xlsx`
- Preview productivo mostrado antes de confirmar:
  - Filas leidas: `412`
  - Ubicaciones usadas: `51`
  - Articulos detectados: `159`
  - Articulos nuevos: `5`
  - Articulos actualizados: `154`
  - Partidas de stock: `179`
  - Total unidades: `5.146.516`
  - Pallets completos: `948`
  - Picos totales: `97`
  - Unidades logisticas: `1.045`
  - Filas ignoradas: `233`
  - Errores bloqueantes: `0`
- Se aviso de que estos totales no coincidian con la validacion local anterior (`413`/`178`/`5.149.956`/`858`/`96`/`954`). El usuario confirmo continuar con `ok dale a ver`.
- Confirmacion ejecutada en produccion: OK.
- Mensaje posterior: `Importacion completada para EDELVIVES. Filas importadas: 179.`
- Historial de importaciones: `08/07/2026 15:36 | EDELVIVES | STOCK_EDELVIVES.xlsx | Importada | 412 | 179`.

**Validacion post-import en produccion:**
- `/stock?client_id=2` mostro `PALLETS TOTALES 1.045` y `Mostrando 1 a 25 de 179 registros`.
- Multiubicacion validada con SKU `110x89 135`:
  - partida 1: ubicacion `19`, lote `SIN LOTE`, `7.426` uds, `1` pallet, `0` picos
  - partida 2: ubicacion `38`, lote `SIN LOTE`, `16.500` uds, `3` pallets, `0` picos
  - KPI filtrado: `PALLETS TOTALES 4`
- Aislamiento por cliente validado desde superadmin filtrando FRIESLAND:
  - `/stock?client_id=1&search=110x89%20135&stock_state=with_stock` devolvio `SIN RESULTADOS`.
- No se valido login como usuario cliente EDELVIVES/FRIESLAND en esta sesion porque no se disponia de credenciales de cliente; se valido el filtrado servidor/UI desde superadmin.

---

## 2026-07-09 - Fix stock facturable con picos en Operaciones Diarias (11:35:03 +02:00)

**Contexto:** En Operaciones Diarias, EDELVIVES recalculaba el stock base con `948` en vez de incluir los picos. Para facturacion operativa, el stock base y el almacenaje deben usar unidades logisticas facturables: pallets completos + picos.

**Diagnostico:**
- `DailyOperationTotalsService::stockBaseForClient()` filtraba correctamente stock con `peaks_count > 0`, pero sumaba solo `full_pallets`.
- El recalculo automatico de entradas usaba solo `pallet_count` y no sumaba un pico cuando `pico_units > 0`.
- El recalculo automatico de salidas usaba solo pallets cargados/solicitados y no sumaba `loaded_peaks`/`requested_peaks`.
- En la sesion productiva anterior se documento que el Excel realmente importado en produccion tenia `948` pallets completos y `97` picos (`1.045` unidades logisticas). La regresion pedida para negocio cubre explicitamente el caso `948 + 96 = 1.044`; en produccion el valor correcto debe ser el que exista en stock real: pallets completos + picos.

**Cambio aplicado:**
- `app/Services/DailyOperations/DailyOperationTotalsService.php`
  - El stock base del cliente ahora suma `COALESCE(full_pallets, 0) + COALESCE(peaks_count, 0)`.
  - Mantiene filtros existentes: cliente, `active`, no obsoleto, item no obsoleto y stock operativo mayor que cero.
- `app/Services/DailyOperations/DailyOperationRecalculationService.php`
  - Entradas confirmadas: `pallet_count + 1` si la linea trae `pico_units > 0`.
  - Salidas enviadas/completadas: `loadedPalletsCount() + loadedPeaksCount()`, con fallback a `palletsCount() + peaksCount()`.
  - Gestion de camion y viajes mantienen la logica actual; se facturan aparte y no alteran stock.
- `resources/views/daily-operations/index.blade.php`
  - Textos de ayuda actualizados para explicar que los calculos usan pallets completos + picos facturables.
- `tests/Feature/DailyOperationsTest.php`
  - Nueva regresion EDELVIVES: `948` pallets + `96` picos = `1.044` stock base, almacenaje y base prevista sin movimientos.
  - Nueva regresion de formula completa: stock base `1.000`, descarga `30`, salida/envio `20` => almacenaje `1.030`, movidos `50`, base manana `1.010`.

**Validacion local:**
- `php artisan optimize:clear`: OK
- `php artisan migrate`: `Nothing to migrate`
- `php artisan test tests/Feature/DailyOperationsTest.php`: `18 passed` (120 assertions)
- `php artisan test tests/Feature/StockImportTest.php`: `29 passed` (302 assertions)
- `php artisan test`: `455 passed` (2064 assertions)
- `npm run build`: OK (`vite build`, 55 modules transformed)

**Control de alcance:**
- No se toco `.env`
- No se tocaron secretos
- No se uso `migrate:fresh`
- No se borraron datos
- No se toco Google Calendar
- No se toco facturacion ni importacion de stock salvo tests de no regresion
- `.claude/` sigue sin anadirse al commit

**Cierre operativo previsto:**
- Commit: `fix: include picos in daily operations billing stock`
- Push: `origin/main`
- Deploy Forge requerido tras push para `wms.maximosl.com`
- Comandos Forge recomendados tras deploy: `php artisan optimize:clear` y `php artisan queue:restart`
- No hay migraciones nuevas en este hito; `php artisan migrate --force` deberia quedar sin cambios pendientes.

---

## 2026-07-09 - Cliente: ALBARANES dividido en entradas y salidas (18:24:10 +02:00)

**Contexto:** Redisenar el portal cliente `/mis-albaranes` para que todo lo visible use `ALBARANES`, con pantalla mas compacta y dos bloques claros: entrada y salida. Se mantiene la URL existente para no romper enlaces, pero se elimina el naming visible "Mis albaranes".

**Cambios realizados:**
- `config/wms.php`, `resources/views/dashboard/index.blade.php` y `resources/views/client/goods-receipts/index.blade.php`
  - menu, dashboard, titulo, topbar y breadcrumbs pasan a `ALBARANES`.
  - se quitan textos largos de relleno.
  - dashboard cliente queda con acceso compacto `ALBARANES`.
- `resources/views/client/goods-receipts/index.blade.php` y `resources/css/app.css`
  - nueva estructura responsive en dos columnas en escritorio y apilada en tablet/movil.
  - bloque `ALBARANES DE ENTRADA` para documentos adjuntos de entradas.
  - bloque `ALBARANES DE SALIDA` para albaranes PDF de salidas ya enviadas/completadas.
  - filas compactas con fecha, proveedor/destino, nombre visible y boton `Descargar`.
  - estado vacio corto: `Sin albaranes.`
  - filtros compactos: `Mes`, `Proveedor`, `Buscar`, `Limpiar`; mes y busqueda aplican tambien a salidas.
- `app/Http/Controllers/ClientGoodsReceiptDocumentController.php`
  - mantiene descarga protegida de entradas.
  - incorpora salidas del mismo `client_id` con estados `sent`/`completed`.
  - nueva descarga protegida de albaran de salida reutilizando el PDF existente `dispatches.delivery-note-pdf`.
  - cliente que intenta ver/descargar documento de otro `client_id` recibe `403`.
- `routes/web.php`
  - nueva ruta protegida `client-goods-receipts.dispatches.download` bajo `/mis-albaranes/salidas/{goodsDispatch}/descargar`.
- `app/Support/GoodsReceipts/DocumentDisplayNamer.php`
  - nombres visibles para salidas tipo `Salida_Edelvives_17`, con desambiguacion si colisionan.
- `app/Notifications/ClientGoodsReceiptDocumentAvailableNotification.php`
  - solo copia visible: las notificaciones apuntan a `ALBARANES` en vez de `Mis albaranes`; no se cambia la logica de envio.

**Seguridad y alcance:**
- No se crea almacenamiento paralelo para salidas; se regenera el PDF existente.
- No se exponen rutas reales de storage.
- No se toco `.env`, secretos ni JSON privados.
- No se uso `migrate:fresh`.
- No se borraron datos.
- No se toco Google Calendar, importacion de stock, facturacion ni stock por cliente.
- `.claude/` sigue sin anadirse.
- No hay migraciones nuevas.

**Validacion automatizada:**
- `php artisan optimize:clear`: OK
- `php artisan migrate`: `Nothing to migrate`
- `php artisan test tests/Feature/ClientGoodsReceiptDocumentTest.php`: `33 passed` (91 assertions)
- `php artisan test tests/Feature/GoodsDispatchManagementTest.php`: `33 passed` (163 assertions)
- `php artisan test`: `462 passed` (2104 assertions)
- `npm run build`: OK (`vite build`, 55 modules transformed)

**Cierre operativo previsto:**
- Commit: `feat: split client delivery notes by entries and dispatches`
- Push: `origin/main`
- Deploy Forge requerido tras push para `wms.maximosl.com`
- Comandos Forge tras deploy: `php artisan optimize:clear` y `php artisan queue:restart`
- `php artisan migrate --force` no deberia aplicar cambios pendientes en este hito.

---

## 2026-07-09 - PEDIDOS cliente simplificado a formato lineas (18:49:53 +02:00)

**Contexto:** El formulario cliente de `PEDIDOS / NUEVO PEDIDO` seguia teniendo textos didacticos y estructura tipo asistente. Producto pidio una pantalla mas directa, como si el cliente construyera el albaran que quiere recibir: referencia, pallets, picos, lineas y enviar.

**Cambios realizados:**
- `resources/views/merchandise-requests/create.blade.php`
  - Titulo/topbar/breadcrumbs pasan a `NUEVO PEDIDO` y `PEDIDOS`.
  - Se eliminan hero, pasos, copy explicativo largo y textos tipo tutorial.
  - Nuevo layout compacto con selector `Referencia / SKU`, cantidad, boton `Añadir línea`, tabla de lineas y boton `ENVIAR PEDIDO`.
  - Mantiene `Camión propio`, aviso de ventana contractual y flujo POST existente.
- `resources/js/app.js`
  - El resumen del pedido deja de renderizar tarjetas grandes y pasa a filas numeradas: `Línea`, `Referencia`, `Pallets`, `Picos`, `Quitar`.
  - Mensajes de feedback acortados.
  - Se mantiene el payload `lines[...]` existente y la distincion entre pallet y pico.
- `resources/css/app.css`
  - Estilos responsive para el nuevo bloque de lineas, totales compactos y tabla adaptable a movil.
- `resources/views/merchandise-requests/index.blade.php`
  - Naming visible simplificado a `PEDIDOS`, CTA `NUEVO PEDIDO`, tabla/listado mas directo.
- `resources/views/merchandise-requests/show.blade.php`
  - Detalle reducido a datos, seguimiento, acciones y lineas; se quitan textos de relleno.
- `tests/Feature/MerchandiseRequestManagementTest.php`
  - Archivo normalizado en UTF-8.
  - Tests actualizados para la nueva UI minimalista.
  - Nuevas regresiones: payload con varias lineas, pedido vacio con `lines`, y confirmacion de que crear un pedido no descuenta stock hasta generar/enviar salida.

**Reglas de negocio preservadas:**
- Cliente solo puede buscar referencias activas de su propio cliente.
- Crear pedido no descuenta stock.
- El descuento sigue ocurriendo en el flujo de salida/despacho.
- Se mantiene soporte de pallets y picos.
- Se mantienen notificaciones, emails y flujo interno de gestion/salida.

**Validacion local:**
- `php artisan optimize:clear`: OK
- `php artisan migrate`: `Nothing to migrate`
- `tests/Feature/ClientMerchandiseRequestTest.php`: no existe en el repo; se ejecuto el equivalente real.
- `php artisan test tests/Feature/MerchandiseRequestManagementTest.php`: `23 passed` (106 assertions)
- `php artisan test tests/Feature/GoodsDispatchManagementTest.php`: `33 passed` (163 assertions)
- `php artisan test`: `465 passed` (2125 assertions)
- `npm run build`: OK (`vite build`, 55 modules transformed)

**Control de alcance:**
- No se toco `.env`
- No se tocaron secretos
- No se uso `migrate:fresh`
- No se borraron datos
- No se toco Google Calendar
- No se toco importacion de stock Friesland/Edelvives
- No se toco facturacion
- No se cambio stock por cliente
- `.claude/` sigue sin anadirse al commit

**Cierre operativo previsto:**
- Commit: `style: simplify client order request workflow`
- Push: `origin/main`
- Deploy Forge requerido tras push para `wms.maximosl.com`
- Comandos Forge tras deploy: `php artisan optimize:clear` y `php artisan queue:restart`
- No hay migraciones nuevas; `php artisan migrate --force` deberia quedar sin cambios pendientes.

---

## 2026-07-09 - Descarga de stock por cliente (Excel/PDF/CSV) desde STOCK

**Contexto:** Con EDELVIVES ya en pruebas reales, se pidio que cada cliente pudiera descargar su propio stock desde la pantalla STOCK en Excel, PDF o CSV, con solo las columnas SKU/DESCRIPCION/LOTE/CANTIDAD, agregando por SKU+lote (sumando cantidades de varias ubicaciones), sin exponer pallets, picos, ubicaciones ni datos tecnicos.

**Commit previo de partida:** `3913628e fix: repair client order form layout`

**Dependencias reutilizadas (no se añadio ninguna libreria nueva):**
- Excel/CSV: `openspout/openspout` 4.28, ya instalado y usado en el proyecto solo para *lectura* de importaciones de stock (`StockExcelImportService`). Esta es la primera vez que se usa su API de *escritura* (`OpenSpout\Writer\XLSX\Writer` / `OpenSpout\Writer\CSV\Writer`) en el proyecto.
- PDF: `barryvdh/laravel-dompdf`, ya usado para los albaranes de salida (`dispatches/delivery-note-pdf.blade.php`) y la hoja de preparacion de pedidos; se siguio exactamente el mismo patron (vista Blade simple + `Pdf::loadView(...)->download(...)`).

**Backend:**
- `app/Support/Stock/StockOverviewBuilder.php`
  - nuevo metodo publico `exportRows(int $clientId): Collection`: reutiliza el mismo `stockQuery()` privado que ya alimenta el listado visible (mismo criterio de `active=true`, exclusion de stock a cero, todos los estados de partida incluidos bloqueados/obsoletos, igual que la vista por defecto), y agrupa los resultados en PHP por `item_id + lot`, sumando `quantity_units`; lote vacio se etiqueta `SIN LOTE`
  - nuevo metodo publico `resolveExportClientId()`: delega en el `resolveClientId()` privado ya existente (mismo criterio de aislamiento por cliente que usa el listado: para rol `cliente` siempre fuerza su propio `client_id` ignorando cualquier valor recibido; para roles internos exige un `client_id` positivo explicito)
- `app/Services/Stock/StockExportService.php` (nuevo)
  - `rows(int $clientId)`: delega en `StockOverviewBuilder::exportRows()`
  - `toXlsxResponse()`: escribe a un fichero temporal con `OpenSpout\Writer\XLSX\Writer` (hoja renombrada `STOCK`, cabecera en negrita, anchos de columna fijos ya que openspout no soporta autosize real), y lo sirve con `response()->download(...)->deleteFileAfterSend(true)`
  - `toCsvResponse()`: mismo patron con `OpenSpout\Writer\CSV\Writer`, delimitador `;` (formato es-ES, tal y como pide el enunciado), con BOM UTF-8 (por defecto de la libreria, compatible con Excel)
  - `toPdfResponse()`: `Pdf::loadView('stock.export-pdf', ...)->download(...)`
  - nombre de fichero: `stock_{codigo_cliente_en_minusculas}_{fecha}.{extension}` (p. ej. `stock_edelvives_2026-07-09.xlsx`)
- `resources/views/stock/export-pdf.blade.php` (nueva) - plantilla HTML simple (misma familia tipografica y estilo de tabla que `delivery-note-pdf.blade.php`), titulo `STOCK {CLIENTE}`, fecha de generacion, tabla con solo las 4 columnas pedidas
- `app/Http/Controllers/StockController.php`
  - `index()`: ahora tambien calcula `canExportStock` (hay un `client_id` resuelto: siempre para `cliente`, solo si hay cliente seleccionado en el filtro para roles internos) y lo pasa a la vista junto al `exportClientId` ya resuelto
  - nueva accion `export(Request $request, string $format)`: repite la misma comprobacion de "cliente sin client_id asignado" que ya usa `index()` (403), valida el `format` contra la lista blanca `xlsx|csv|pdf` (404 si no coincide), resuelve el `client_id` real via `resolveExportClientId()` (nunca confia en el `client_id` del request para el rol cliente), y si no hay cliente resuelto (rol interno sin cliente seleccionado) redirige de vuelta al listado con un aviso en vez de romper
- `routes/web.php`: `GET /stock/exportar/{format}` -> `stock.export`, con `whereIn('format', [...])` y el mismo middleware `minimum.role:cliente` que ya protege `stock.index` (cualquier rol que hoy puede ver stock puede exportarlo, igual que antes con la pantalla)

**Decision de alcance sobre filtros (documentada segun lo pedido):** el export respeta el `client_id` actualmente seleccionado (obligatorio para poder exportar: fuerza el propio para `cliente`, y exige seleccion explicita para roles internos, ocultando el boton si no hay cliente resuelto), pero **no** aplica el resto de filtros de pantalla (busqueda, lote, ubicacion, estado, "solo picos"): el export siempre es el stock completo agregado del cliente resuelto. Se opto por la ruta mas simple indicada como alternativa valida en el propio enunciado ("si complica, exportar stock completo del cliente seleccionado"), evitando la complejidad de trasladar filtros de partida individual a una vista ya agregada por SKU+lote.

**UI:**
- `resources/views/stock/index.blade.php`: la seccion `Pallets totales` se envuelve en un nuevo `.stock-summary-toolbar` (flex, sin tocar la clase `.stock-summary` compartida con otras 3 pantallas) con el boton "Descargar" a la derecha (solo si `canExportStock`) y un `<dialog>` nativo con el modal pedido: titulo "Descargar stock", texto "Elige formato", botones Excel/PDF/CSV (enlaces directos a la exportacion) y Cancelar (`method="dialog"`, cierra sin JS)
- `resources/css/app.css`: estilos nuevos `.stock-summary-toolbar` / `.stock-export-modal` (incluye `::backdrop`) / `.stock-export-modal-actions`, minimalistas, sin tocar ningun estilo compartido existente
- `resources/js/app.js`: nueva `setupStockExportModal()` (abre el dialog con `showModal()`, cierra al hacer click fuera), siguiendo el mismo patron `setup*()` + `boot()` que el resto del fichero

**Cobertura y validacion real:**
- `php artisan optimize:clear`: OK
- `php artisan migrate`: `Nothing to migrate` (sin migraciones nuevas en este hito)
- `php artisan test tests/Feature/StockOverviewTest.php`: `30 passed` (no existe `StockManagementTest.php` en el repo; este es el fichero real de tests de la pantalla STOCK)
- `php artisan test tests/Feature/StockImportTest.php`: `29 passed`
- `php artisan test`: `481 passed` (2176 aserciones)
- `npm run build`: OK
- Test nuevo: `tests/Feature/StockExportTest.php` (16 tests): boton visible para cliente, modal con los tres formatos y Cancelar, descarga Excel/PDF/CSV del propio stock, Excel y CSV contienen exactamente las 4 columnas pedidas (verificado leyendo el fichero xlsx generado con el lector de openspout y el contenido crudo del csv), la vista PDF tampoco menciona pallet/pico/ubicacion, agregacion por SKU+lote sumando cantidades de dos ubicaciones distintas en una sola fila, lote vacio se muestra como "SIN LOTE", EDELVIVES no puede descargar stock de FRIESLAND ni viceversa (aunque se fuerce `client_id` en la URL), usuario cliente sin `client_id` asignado recibe 403, superadmin puede exportar el cliente seleccionado, superadmin sin cliente seleccionado es redirigido al listado en vez de romper, y el stock a cero no aparece en el export (mismo criterio que el listado visible)

**Verificacion visual real en navegador embebido (usuario cliente EDELVIVES real, `codex.cliente.local@example.com`, contrasena temporal generada y despues invalidada solo para esta verificacion):**
- En `/stock` aparece el boton "Descargar" junto a "Pallets totales"; al pulsarlo se abre el modal "Descargar stock" / "Elige formato" con Excel, PDF, CSV y Cancelar
- Descarga real de los tres formatos contra datos reales de EDELVIVES en local (178 partidas de stock existentes): Excel y PDF con `Content-Disposition: attachment; filename=stock_edelvives_2026-07-09.xlsx/.pdf` y content-type correctos; CSV con cabecera exacta `SKU;DESCRIPCIÓN;LOTE;CANTIDAD` y filas agregadas (p. ej. una sola linea para "100x127 135 - MATT COATED" con la cantidad total, sin duplicados por ubicacion)
- Se probo pasar `?client_id=999999` (id inexistente/ajeno) en la URL de descarga estando logueado como cliente EDELVIVES: la respuesta siguio siendo el stock de EDELVIVES (`stock_edelvives_...`), confirmando que el backend ignora cualquier `client_id` recibido del cliente y nunca confia en el valor del request

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- No hubo migraciones nuevas
- No se uso `force push`
- No se anadio ninguna dependencia nueva a `composer.json`/`package.json` (se reutilizo `openspout/openspout` y `barryvdh/laravel-dompdf`, ya instalados)
- No se borraron datos; la unica accion sobre datos reales en local fue temporal y reversible (contrasena de prueba del usuario cliente local, invalidada de nuevo al terminar la verificacion)

**Forge cuando toque desplegar este hito:**
- `Deploy Now` (el proyecto despliega automaticamente desde `origin/main`)
- `php artisan migrate --force` no aplica en este hito (no hay migraciones nuevas)
- `php artisan optimize:clear`
- `npm run build` ya esta contemplado en el propio proceso de deploy de Forge (assets compilados incluidos en el commit no aplica; Forge compila en el propio deploy segun el script existente del proyecto)

## 2026-07-09 - Limpieza dashboard cliente y pantalla STOCK cliente (minimalismo)

**Contexto:** Con EDELVIVES ya en pruebas reales, se pidio quitar relleno visual en dos pantallas: el dashboard cliente mostraba "ALBARANES" duplicado (una vez dentro del bloque Operaciones y otra vez en una tarjeta independiente debajo), y la pantalla STOCK cliente tenia una cabecera grande "Mi inventario" con dos textos explicativos largos, ademas del boton "Descargar" (anadido en el hito anterior) separado y flotando fuera de la tarjeta de "Pallets totales".

**Commit previo de partida:** `da5f6e55 feat: add client stock export downloads`

**Tarea 1 - Duplicado de ALBARANES en el dashboard cliente:**
- `resources/views/dashboard/index.blade.php`: se elimino el bloque `<section class="... dashboard-mis-albaranes-card">` independiente (titulo "ALBARANES" + boton "Entrar") que aparecia debajo de las secciones operativas. El acceso a ALBARANES ya existe y se mantiene intacto como parte del bucle `@foreach ($navigationSections as $section)`, dentro de la seccion "Operaciones" (config `mis-albaranes` en `config/wms.php`, sin tocar). No se toco la ruta `client-goods-receipts.index` ni el controlador; solo se quito el bloque visual duplicado.
- `resources/css/app.css`: se quito `.dashboard-mis-albaranes-card` de la lista de selectores compartida con `.dashboard-calendar-card` (regla ahora huerfana tras el paso anterior, limpieza directa).
- Nota de verificacion: el menu lateral (drawer, `layouts/dashboard.blade.php`) tambien lista "ALBARANES" como enlace de navegacion normal — eso es el menu global de toda la app (presente en cualquier pantalla), no la duplicacion que reportaba el usuario, y no se ha tocado.

**Tarea 2 y 3 - Simplificacion de STOCK cliente e integracion del boton DESCARGAR:**
- `app/Http/Controllers/StockController.php`: `pageTitle` para el rol `cliente` pasa de `'Mi inventario'` a `'STOCK'` (usado en el topbar compacto y en el `<title>` de la pestana, coherente con el breadcrumb que ya decia "STOCK").
- `resources/views/stock/index.blade.php`:
  - la cabecera grande (`ops-page-header stock-intro-card`, con titulo, subtitulo largo y el parrafo "Usa el buscador para localizar por SKU, descripcion o lote.") ahora esta envuelta en `@unless ($isClient)`: para el rol cliente no se renderiza en absoluto; para roles internos (almacen/administracion/superadmin) se mantiene exactamente igual que antes (titulo, subtitulo y accesos a Articulos/Ubicaciones/Importar stock), ya que el enunciado solo pedia simplificar la pantalla del cliente.
  - el bloque "Pallets totales" y el boton "Descargar" (que en el hito anterior vivian en un `<div class="stock-summary-toolbar">` como hermanos, con el boton flotando a la derecha fuera de la tarjeta) se fusionaron en una unica tarjeta: el boton y el `<dialog>` de formatos ahora estan dentro del mismo `<article class="stock-summary-card ... stock-summary-card--with-action">`, con el numero/etiqueta a la izquierda y el boton a la derecha en la misma franja.
  - el texto de ayuda de la tarjeta se acorto de "Total operativo visible para preparacion y expedicion." a "Total visible", igual para cliente y roles internos (es el mismo componente compartido).
- `resources/css/app.css`:
  - se elimino `.stock-summary-toolbar` (ya no se usa) y se anadieron `.stock-summary-card--with-action` (flex, numero a la izquierda / boton a la derecha, con `flex-wrap` para que el boton baje de linea en movil) y `.stock-summary-card-main` (agrupa titulo/numero/texto de ayuda dentro de la tarjeta).
  - se amplio `.stock-summary--single` (clase ya existente pero sin uso previo) de `minmax(13rem, 18rem)` a `minmax(16rem, 28rem)` para dar sitio al boton dentro de la tarjeta, y se reforzo con selectores compuestos (`.stock-summary.stock-summary--single` y `body.brand-body.app-shell-body .stock-summary.stock-summary--single`) para ganar en especificidad CSS a las reglas de `grid-template-columns` ya existentes para `.stock-summary` en los breakpoints de 768px/1024px y en el bloque `body.brand-body.app-shell-body` — sin este ajuste el ancho fijo se habria sobreescrito silenciosamente en pantallas anchas por reglas mas especificas ya presentes en el fichero.
- No se toco el export en si (`StockExportService`, `StockOverviewBuilder::exportRows`, rutas, columnas SKU/DESCRIPCION/LOTE/CANTIDAD): solo su punto de entrada visual.

**Cobertura y validacion real:**
- `php artisan optimize:clear`: OK
- `php artisan migrate`: `Nothing to migrate`
- `php artisan test tests/Feature/ClientGoodsReceiptDocumentTest.php`: `33 passed`
- `php artisan test tests/Feature/StockOverviewTest.php`: `30 passed` (no existe `StockManagementTest.php` en el repo, es el fichero real de tests de STOCK)
- `php artisan test`: `486 passed` (2193 aserciones)
- `npm run build`: OK
- Tests ajustados/nuevos:
  - `tests/Feature/StockOverviewTest.php`: `test_cliente_can_view_only_own_stock_inventory` actualizado para esperar la NO presencia de "Mi inventario" y de los dos textos explicativos (antes esperaba lo contrario, por diseno del hito anterior)
  - `tests/Feature/ClientGoodsReceiptDocumentTest.php` (+3 tests): dashboard cliente muestra "ALBARANES" una sola vez dentro de `<main>` (excluyendo el menu lateral global, que tambien lo lista como navegacion normal y no cuenta como duplicado); dashboard cliente mantiene el acceso a ALBARANES dentro de "Operaciones"; dashboard cliente ya no muestra el bloque `dashboard-mis-albaranes-card`
  - `tests/Feature/StockExportTest.php` (+2 tests): STOCK cliente no muestra "Mi inventario" ni los textos explicativos largos; el boton "Descargar" esta estructuralmente dentro del mismo `<article>` que "Pallets totales" (verificado con una expresion regular sobre el HTML) y ya no existe el contenedor `stock-summary-toolbar` del hito anterior

**Verificacion visual real en navegador embebido (usuario cliente EDELVIVES real, `codex.cliente.local@example.com`, contrasena temporal generada y despues invalidada solo para esta verificacion):**
- Dashboard: "ALBARANES" aparece una unica vez, dentro de "Operaciones" junto a BOOKING y PEDIDOS; no hay tarjeta independiente debajo; se mantienen STOCK, Operaciones y "Agenda de BOOKING"
- STOCK: ya no aparece "Mi inventario" ni los textos explicativos; se entra directamente en la tarjeta "PALLETS TOTALES" con el boton "Descargar" integrado
- A 1440px de ancho (escritorio), el numero de pallets y el boton "Descargar" quedan en la misma franja horizontal dentro de la tarjeta (altura de una sola fila, ~69px); en movil el boton queda debajo pero dentro del mismo bloque
- El boton "Descargar" sigue abriendo el modal "Descargar stock" / "Elige formato" con Excel, PDF, CSV y Cancelar; se descargo CSV real contra los datos de EDELVIVES y siguio devolviendo `stock_edelvives_2026-07-09.csv` con las columnas `SKU;DESCRIPCIÓN;LOTE;CANTIDAD` correctas

**Incidencia de herramienta (no de la aplicacion):** durante la verificacion visual, el clic automatizado del navegador embebido sobre el boton "Descargar" dejaba de disparar el listener tras una recarga de pagina (problema del propio entorno de automatizacion/herramienta de preview, con referencias a nodos obsoletas), mientras que el mismo flujo disparado con `element.click()` directamente sobre el DOM si activaba el modal correctamente. Se dejó constancia porque no es un bug de la aplicacion: el test automatizado (`tests/Feature/StockExportTest.php`) confirma el HTML/estructura, y la comprobacion manual con `click()` directo confirmo que el listener y `showModal()` funcionan correctamente.

**Control de alcance:**
- No se toco `.env`
- No se anadio `.claude/`
- No hubo migraciones nuevas
- No se uso `force push`
- No se toco el export (formatos, columnas, agregacion, permisos) salvo su punto de entrada visual
- No se borraron datos; la unica accion sobre datos reales en local fue temporal y reversible (contrasena de prueba del usuario cliente local, invalidada de nuevo al terminar la verificacion)

**Forge cuando toque desplegar este hito:**
- `Deploy Now` (el proyecto despliega automaticamente desde `origin/main`)
- `php artisan migrate --force` no aplica en este hito (no hay migraciones nuevas)
- `php artisan optimize:clear`

---

## 2026-07-10 - Pedidos pendientes cliente y gestion interna compacta (12:10 +02:00)

**Contexto:** Se simplifico el flujo de PEDIDOS para pruebas reales con EDELVIVES. El criterio principal fue que la gestion interna no volviera a presentar `SOL-xxxxx` como protagonista ni enterrara las lineas/carga real bajo resumenes y textos pedagogicos.

**Punto de partida:** `3f2d5a18 style: simplify client dashboard and stock export layout` en `main`, con `.claude/` sin trackear.

**Cliente - NUEVO PEDIDO:**
- Se anadio `PEDIDOS PENDIENTES` inmediatamente debajo del formulario.
- La consulta fuerza el `client_id` del usuario autenticado y solo incluye estados `pending` y `preparing`.
- Pedidos enviados, completados, cancelados y pedidos de otros clientes no aparecen.
- La tabla compacta muestra numero, fecha, estado, lineas/pallets y accion `Ver`; el vacio muestra `Sin pedidos pendientes.`

**Interno - gestion de pedido:**
- Se elimino la cabecera grande, los bloques `Resumen de operativa`, `Siguiente accion` y el selector manual `Cambiar estado` de este flujo.
- `SOL-xxxxx` queda como referencia secundaria pequena (`Pedido SOL-xxxxx`).
- La cabecera es una unica franja con cliente, estado, fecha, pallets, picos, `GENERAR SALIDA`/`Ver salida`, imprimir preparacion y volver.
- La tabla `LINEAS DEL PEDIDO Y CARGA REAL` aparece inmediatamente despues de la cabecera y muestra SKU, descripcion, lote, ubicacion, solicitado/cargado en pallets y picos, diferencia y observacion.
- Cuando ya existe una salida en preparacion, pallets/picos cargados y observacion se editan en la misma fila y se guardan con un unico boton `GUARDAR PREPARACION`.
- Generar salida y guardar preparacion vuelven a la misma pantalla interna para mantener el flujo continuo.
- El seguimiento queda reducido a una linea compacta al final.
- No se cambio la logica de stock: guardar preparacion no aplica stock; el descuento sigue ocurriendo al enviar/completar mediante el servicio existente.

**Cobertura anadida:**
- Cliente ve pendientes propios y no ve enviados/completados/cancelados ni pedidos de otro cliente.
- Estado vacio y enlace `Ver` protegido.
- Interno ve acciones y lineas antes del seguimiento; no aparecen los textos eliminados ni `Cambiar estado`.
- El numero de pedido no se renderiza como titulo protagonista.
- Con salida asociada aparece `Ver salida` y el editor compacto.
- La carga puede guardarse desde la pantalla del pedido sin enviar la salida ni descontar stock.

**Validacion ejecutada:**
- `php artisan optimize:clear`: OK.
- `php artisan migrate`: `Nothing to migrate`.
- `php artisan test tests/Feature/MerchandiseRequestManagementTest.php`: `26 passed` (123 assertions).
- `php artisan test tests/Feature/GoodsDispatchManagementTest.php`: `36 passed` (191 assertions).
- `php artisan test`: `492 passed` (2236 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `git diff --check`: OK.

**Validacion visual local:**
- Gestion interna pendiente: cabecera de 86,9 px, referencia `SOL-000003` a 11,5 px, tabla inmediatamente debajo y sin overflow horizontal de pagina.
- Tras generar una salida QA: tabla, campos de carga y `GUARDAR PREPARACION` permanecen visibles en el primer viewport de 720 px.
- Cliente EDELVIVES: `PEDIDOS PENDIENTES` aparece justo debajo de `NUEVO PEDIDO`, con 33,6 px de separacion, mostrando solo el pedido abierto propio.
- Se crearon exclusivamente en la base local cuentas/registros `codex-orders-*@local.test` y un pedido QA EDELVIVES para esta comprobacion; no se envio/completo la salida y no se aplico stock.

**Control de alcance:**
- Sin migraciones nuevas, sin `migrate:fresh`, sin borrado de datos y sin force push.
- No se tocaron `.env`, secretos, IA, Google Calendar, importacion, facturacion ni operaciones diarias.
- `.claude/` permanece sin trackear y no se incluira en el commit.

**Publicacion realizada:**
- Commit funcional: `ed3d747c14193d143377417a474ed38bcac9df37 style: simplify client and warehouse order workflow`.
- Push confirmado a `origin/main` (`3f2d5a18..ed3d747c`).
- Forge deployment `73090669`: `Deployed` en 57 segundos.
- Log Forge: `Nothing to migrate`, build Vite OK (55 modulos), release activada y `php artisan queue:restart` completado (`Broadcasting queue restart signal`).
- El lanzador independiente de Commands de Forge no acepto la tecla de ejecucion automatizada para `php artisan optimize:clear`; el comando no se marca falsamente como ejecutado y queda pendiente de lanzamiento manual en Forge.
- Produccion responde en `https://wms.maximosl.com`, pero la sesion del navegador de validacion termino en `/login`, por lo que la validacion visual autenticada se realizo en local.

---

## 2026-07-12 - Pedidos creados por usuarios internos en nombre de cliente

**Contexto:** Se pidio permitir que usuarios internos con rol `almacen`, `administracion` y `superadmin` creen pedidos de mercancia seleccionando cualquier cliente, manteniendo que los usuarios `cliente` solo puedan pedir para su propio cliente aunque manipulen el formulario.

**Comprobaciones iniciales obligatorias:**
- `SESSION_LOG.md` leido completo por bloques.
- `git status --short`: limpio.
- `git branch --show-current`: `main`.
- `git remote -v`: `origin https://github.com/maximoslu/WMS_LARAVEL.git`.
- `git log -5 --oneline`: `023cb75`, `ed3d747`, `3f2d5a1`, `da5f6e5`, `3913628`.
- `git pull --ff-only origin main`: `Already up to date`.

**Cambios realizados:**
- `app/Http/Controllers/MerchandiseRequestController.php`
  - `create`, `searchItems` y `store` aceptan usuarios internos autorizados (`almacen` o superior).
  - Para rol `cliente`, el cliente efectivo sigue siendo siempre `user.client_id`, ignorando cualquier `client_id` recibido.
  - Para roles internos, el cliente efectivo sale del `client_id` seleccionado y debe estar activo.
  - El listado muestra CTA de crear pedido tambien a internos.
  - La busqueda AJAX de referencias queda acotada al cliente seleccionado para internos.
- `app/Http/Requests/StoreMerchandiseRequestRequest.php`
  - Autorizacion ampliada a internos.
  - Validacion de `client_id` obligatorio solo para internos y existente/activo.
  - El resolvedor de lineas valida referencias contra el cliente efectivo, no contra datos manipulables del formulario.
- `resources/views/merchandise-requests/create.blade.php`
  - Selector `Cliente del pedido` solo visible para internos.
  - Sin cliente seleccionado, la pantalla pide seleccionar cliente y no muestra buscador de referencias.
  - El formulario POST interno incluye el `client_id` seleccionado.
- `tests/Feature/MerchandiseRequestManagementTest.php`
  - Cobertura nueva para cliente propio, manipulacion de `client_id`, manipulacion de referencia ajena, `almacen`, `administracion`, `superadmin`, rol no autorizado y busqueda AJAX multi-cliente.

**Reglas de negocio verificadas:**
- Cliente normal crea pedidos solo para su propio `client_id`.
- Cliente normal no puede crear pedido para otro cliente ni usando `client_id` ajeno ni usando referencias de otro cliente.
- `almacen`, `administracion` y `superadmin` pueden seleccionar cliente y crear pedido para ese cliente.
- `requested_by` conserva la trazabilidad del usuario real que crea el pedido, incluido usuario interno.
- `client_id` guarda el cliente real del pedido.
- No se crearon migraciones: ya existian `client_id` y `requested_by` en `merchandise_requests`.

**Validacion ejecutada:**
- `php artisan test tests/Feature/MerchandiseRequestManagementTest.php`: `34 passed` (165 assertions).
- `php artisan test`: `500 passed` (2278 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `php -l` en controlador y FormRequest modificados: OK.
- `git diff --check`: OK.
- `git diff --stat`: 5 archivos modificados tras actualizar este log.

**Control de alcance:**
- No se toco `.env`.
- No se tocaron secretos, `vendor/` ni `node_modules/`.
- No hubo migraciones nuevas.
- No se uso `migrate:fresh`.
- No se borraron datos.
- No se toco Google Calendar, importacion de stock, facturacion ni otros modulos salvo integracion necesaria del flujo de pedidos.

**Commit / push / despliegue:**
- Commit funcional: `feat: allow internal users to create client orders` (el hash exacto queda en el propio historial de Git tras el commit).
- Push confirmado a `origin/main` con commit funcional `5c87185` (`023cb75..5c87185`).
- Este push puede disparar Forge automaticamente.
- No se da por desplegado en produccion sin verificacion real posterior.

---

## 2026-07-12 - Importacion y visibilidad de stock Friesland por categorias

**Contexto:** Se pidio adaptar la importacion del Excel `STOCK_FRIESLAND.xlsx` para clasificar stock por `GENERAL`, `BOBINAS`, `ETIQUETAS`, `BLOQUEADO`, `OBSOLETO` y `VARIOS`, manteniendo referencias internas `_` solo para usuarios internos y ocultando a clientes los palets, picos, totales logisticos y referencias internas.

**Comprobaciones iniciales obligatorias:**
- `SESSION_LOG.md` leido completo por bloques.
- `git status --short`: limpio.
- `git branch --show-current`: `main`.
- `git remote -v`: `origin https://github.com/maximoslu/WMS_LARAVEL.git`.
- `git log -5 --oneline`: `cd247ba`, `5c87185`, `023cb75`, `ed3d747`, `3f2d5a1`.
- `git pull --ff-only origin main`: `Already up to date`.

**Cambios realizados:**
- Se anadio migracion para `items.stock_category`, `stock_pallets.stock_category` y `stock_pallets.warehouse_pallets`.
- `StockExcelImportService` ahora procesa `GENERAL`, `BOBINAS`, `ETIQUETAS`, `BLOQUEADO`, `OBSOLETO` y `VARIOS`.
- Las filas `***` se ignoran como resumen; las referencias `_` se importan como internas/VARIOS.
- `warehouse_pallets` usa `PALLETS/PALETS + PICOS` directamente y conserva decimales `0,33`, `0,5`, etc.
- En BOBINAS se conserva stock logistico aunque `UNIDADES x PALLET` sea 0 si hay palets/picos declarados.
- La confirmacion sigue reemplazando el stock del cliente en transaccion y conserva trazabilidad por `stock_import_id`.
- Vista de importacion: muestra filas leidas, filas validas, `***` ignoradas, `_` internas, palets almacen, diferencias contra stock actual y totales por categoria.
- Stock interno: muestra filtro/categoria y KPI `Pallets almacen`.
- Stock cliente/exportaciones: oculta referencias VARIOS/_ y no muestra palets, picos, uds/pallet ni totales logisticos.

**Contraste con Excel real local:**
- Archivo localizado: `C:\Users\jorge\Downloads\STOCK_FRIESLAND.xlsx`.
- Hojas detectadas: `GENERAL`, `BOBINAS`, `ETIQUETAS`, `BLOQUEADO`, `OBSOLETO`, `VARIOS`.
- Lineas con referencia no resumen: `291`.
- Filas `***` excluidas: `5`.
- Referencias `_`: `8`; palets internos `_`: `81`.
- Total palets almacen por hoja: `GENERAL 1903`, `BOBINAS 279`, `ETIQUETAS 17`, `BLOQUEADO 21`, `OBSOLETO 41`, `VARIOS 78`.
- Total palets almacen: `2339`; visible cliente tras ocultar `_`: `2258`.

**Cobertura anadida/adaptada:**
- Importacion de hojas Friesland, ETIQUETAS procesada, BLOQUEADO/OBSOLETO clasificados.
- `***` ignorado y `_` importado como VARIOS interno.
- Calculo decimal de `PICOS` como palets almacen.
- BOBINAS con unidades por pallet 0 y palets/picos declarados.
- Cliente ve bloqueado/obsoleto pero no VARIOS/_.
- Cliente no ve palets, picos, uds/pallet ni totales logisticos.
- Exportaciones cliente no incluyen VARIOS/_.
- Stock con solo `warehouse_pallets` queda visible para internos.

**Validacion ejecutada:**
- `php artisan test tests/Feature/StockImportTest.php`: `31 passed` (335 assertions).
- `php artisan test tests/Feature/StockOverviewTest.php`: `32 passed` (155 assertions).
- `php artisan test tests/Feature/StockExportTest.php`: `19 passed` (61 assertions).
- `php artisan test`: `505 passed` (2330 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `php -l` en modelos, servicio, builder y migracion modificados: OK.
- `git diff --check`: OK.
- Diff completo revisado antes de commitear.

**Control de alcance:**
- No se toco `.env`.
- No se tocaron secretos, `vendor/` ni `node_modules/`.
- No se uso `migrate:fresh`.
- No se borraron datos.
- La migracion nueva se justifica porque se necesitaba persistir categoria funcional y palets almacen decimales sin sobrecargar `peaks_count`.
- No se tocaron pedidos, bookings, dispatches ni otros modulos salvo export/consulta de stock vinculados a visibilidad cliente.

**Commit / push / despliegue:**
- Commit funcional: `81e3359 feat: adapt Friesland stock import categories`.
- Commit documental inicial: `d98c0f3 docs: record Friesland stock import update`.
- Push confirmado a `origin/main` (`cd247ba..d98c0f3`).
- Este push a `origin/main` puede disparar Forge automaticamente.
- No se da por desplegado en produccion sin verificacion real posterior.

---

## 2026-07-12 - Correccion pantalla importacion FRIESLAND: legibilidad de errores y calculo de palets

**Contexto:** Al probar la importacion real de `STOCK_FRIESLAND.xlsx` la pantalla mostraba dos problemas: (1) los errores/alertas no se leian (texto oscuro sobre el fondo oscuro del shell) y (2) la previsualizacion sumaba 2340 palets almacen en lugar de 2339. Sesion de solo lectura/inspeccion primero, reproduciendo con el parser real contra el fichero del cliente antes de tocar codigo. No se confirmo ninguna importacion real.

**Diagnostico (reproducido con el parser real contra el Excel real):**
- **Causa raiz comun:** la columna CANTIDAD del Excel Friesland es una formula (`=(F*G)+(picos)`). OpenSpout devuelve el TEXTO de la formula, no su valor. `integerValue()` le extraia los digitos y generaba numeros gigantes (p. ej. `222222222222`), lo que provocaba:
  - el error `SQLSTATE[22003] Out of range value for column quantity_units` (fila BOBINAS `CRYOVAC5` sin uds/pallet, con stock solo en PICO 1);
  - partidas fantasma con cantidad 0 y palets 0 (p. ej. GENERAL `SKU 11`), porque la cantidad bogus "positiva" impedia descartar la fila sin stock.
- **Descuadre +1 (2340 vs 2339):** las filas BOBINAS `LASTOPP248` y `LASTOPP249` tienen `PALLETS = 0,5` (media pallet). `integerValue()` redondeaba `0,5 -> 1`, sumando +0,5 de mas cada una (+1 total). El palet almacen debe usar el valor DECIMAL de PALLETS + PICOS.
- **CRYOVAC5 (BOBINAS fila 9):** stock solo en columna PICO, sin PALLETS ni PICOS operativos ni uds/pallet. Generaba un error bloqueante ilegible; debe ser un aviso claro y no crear partida logistica.
- **Etiqueta "Refs internas _":** mostraba solo las internas con stock (6), no las detectadas (8 lineas `_`, 7 SKU distintos con `_CAJA057` repetido).

**Correccion de calculo (`app/Services/Stock/StockExcelImportService.php`):**
- Nuevo helper `isFormulaString()`: `integerValue()` y `decimalValue()` devuelven `null` ante celdas formula (`=...`). Asi la cantidad se calcula siempre desde el desglose (PALLETS*uds + picos) y nunca desde el texto de la formula.
- `warehouse_pallets` = `decimal(PALLETS) + decimal(PICOS)`, sin redondear medias pallets y sin contar las columnas PICO 1..10 (se quito el fallback `count()` por la regla operativa).
- Filas BOBINAS con stock solo en columnas PICO y sin PALLETS/PICOS operativos ni uds/pallet: se omiten con aviso claro ("stock en picos pero sin pallets ni picos operativos"), sin error SQL y sin partida logistica.
- Nuevo contador `internal_references_detected` (lineas `_` detectadas, con o sin stock); `internal_rows` sigue siendo las internas con stock.

**Correccion visual (`resources/views/stock/import.blade.php` + `resources/css/app.css`):**
- El bloque de error superior pasa de `alert alert-danger` (sin CSS, invisible sobre el fondo oscuro) a `alert alert-error import-alert` (caja rosa clara `#f5dede`, texto rojo oscuro `#8f2020`, padding, titulo).
- Mensajes largos (SQL) con `overflow-wrap: anywhere`, `white-space: pre-wrap` y `overflow-x: auto` (`.import-error-detail`), asi no se salen del layout.
- Tarjetas de "Errores fatales" / "Errores bloqueantes en filas" con borde rojo y encabezado rojo; "Avisos" con borde ambar. Todo scoped a `body.brand-body.app-shell-body`.
- KPIs: se sustituye "Refs internas _" por "Refs internas detectadas" (8) y "Refs internas con stock" (6).
- Verificacion visual con el CSS compilado real: las cajas de error son legibles y el SQL largo hace wrap correctamente.

**Totales verificados con el parser real contra `STOCK_FRIESLAND.xlsx` (antes 2340 / despues 2339):**
- Filas leidas: 572; filas validas: 291; filas resumen `***`: 5.
- Refs internas detectadas `_`: 8; palets internos: 81; refs internas con stock: 6.
- Palets almacen total: **2339** (antes 2340).
- EN USO: 2196; BLOQUEADO: 21; OBSOLETO: 41; VARIOS: 81.
- Partidas con stock positivo: 160; partidas fantasma (0/0): 0.
- Errores bloqueantes: 0 (antes 1); `max(quantity_units)` = 2.505.000 (dentro de rango; antes 222.230.149.035).
- Palets visibles cliente = 2339 - 81 (VARIOS/internos) = 2258 (derivado; la visibilidad cliente ya excluye `_` y VARIOS).

**Base de datos:**
- Se aplico la migracion pendiente `2026_07_12_000001_add_stock_category_and_warehouse_pallets` (aditiva, idempotente, backfill) que el PC de casa tenia sin ejecutar y que el codigo ya usaba (`stock_category`, `warehouse_pallets`). Sin `migrate:fresh`, sin borrar datos.

**Tests/build:**
- Nuevo test `test_friesland_formula_quantity_cells_do_not_break_import_or_create_phantom_rows` (formula ignorada, sin out-of-range, sin fantasma, 0,5 pallet = 0,5 almacen, CRYOVAC5 como aviso, refs internas 8/6).
- `php artisan test`: **506 passed** (2356 assertions).
- `npm run build`: OK (`vite build`, 55 modules).
- `git diff --check`: OK.

**Control de alcance:**
- No se toco `.env`, secretos, `vendor/` ni `node_modules/`.
- No se uso `migrate:fresh` ni se borraron datos.
- No se confirmo ninguna importacion real de Friesland.
- No se toco Google Calendar, facturacion ni el aislamiento de stock por cliente.
- `.claude/` fuera del commit.

---

## 2026-07-12 - Vista de stock del CLIENTE: ocultar referencias internas "_" ademas de VARIOS

**Contexto:** El superadmin ve bien el stock de FRIESLAND (2339 palets almacen, 160 registros, columnas logisticas). El usuario cliente veia una lista distinta y se sospechaba que ocultaba de mas (EN USO / BLOQUEADO / OBSOLETO). Sesion de inspeccion primero, reproduciendo superadmin vs cliente contra la base real antes de tocar codigo. No se toco importacion, ni `.env`, ni datos (el experimento se hizo dentro de una transaccion con rollback).

**Reproduccion (builder real, DB real, ambos roles, en transaccion con rollback):**
Sembrando FRIESLAND con CAJA0030 (EN USO), CRYOVAC6 (EN USO), CAJA0077 (BLOQUEADO), ET0336 (OBSOLETO), _CAJA057 (VARIOS/misc), _FILM0519 (VARIOS/misc) y _LEAK_INUSE (SKU "_" pero mal categorizado como EN USO):
- Superadmin veia los 7.
- Cliente veia 5: CAJA0030, CRYOVAC6, CAJA0077, ET0336 y **_LEAK_INUSE**.
- Ocultos correctamente: _CAJA057, _FILM0519 (misc).

**Causa raiz:** la visibilidad del cliente solo excluia `stock_category = 'misc'` (VARIOS). No excluia las referencias cuyo SKU empieza por `_`. Si una referencia interna `_` quedaba mal categorizada (no como misc), se **filtraba al cliente**. Ademas la regla estaba duplicada en dos queries (partidas y referencias sin stock) sin un unico punto de verdad. Confirmado ademas: BLOQUEADO y OBSOLETO NO se ocultan al cliente (correcto); la columna `stock_category` es NOT NULL con default `in_use`, asi que no hay partidas con categoria nula que se pierdan.

**Correccion (`app/Support/Stock/StockOverviewBuilder.php`):**
- Regla unica reutilizable de "stock interno" (regla de oro): interno = `stock_category = 'misc'` **o** SKU que empieza por `_`. Se aplica de forma identica en:
  - `stockQuery` (listado + tarjeta resumen + export cliente) via `hideInternalStock()`.
  - `withoutStockQuery` (referencias sin stock) via `hideInternalItems()`.
- El filtro de `_` usa `SUBSTR(sku, 1, 1) <> '_'` (portable MySQL/SQLite; se descarto `LIKE '\_%'` porque el escape de backslash no era fiable entre conexiones).
- BLOQUEADO y OBSOLETO siguen visibles para el cliente. El cliente sigue sin ver columnas logisticas (pallets, picos, uds/pallet, totales) por la vista Blade (`@unless($isClient)`), sin cambios.
- El export de stock del cliente usa la misma base (`stockQuery` con `hide_internal`), por lo que aplica la misma visibilidad que la tabla.

**Resultado tras la correccion (mismo experimento):**
- Superadmin ve 7 (incluye `_` y VARIOS).
- Cliente ve 4: CAJA0030, CRYOVAC6, CAJA0077, ET0336.
- Ocultos al cliente: _CAJA057, _FILM0519 y _LEAK_INUSE (las 3 internas por `_`/VARIOS). Sin fugas, sin ocultar ningun EN USO/BLOQUEADO/OBSOLETO.
- El total del cliente = total del superadmin del cliente menos unicamente las partidas internas (VARIOS/`_`).

**Pruebas automaticas anadidas (`tests/Feature/StockOverviewTest.php`):**
- Cliente FRIESLAND ve EN USO, BLOQUEADO y OBSOLETO; no ve VARIOS ni SKU `_` (incluida la referencia `_` mal categorizada); no ve stock de otro cliente.
- Superadmin ve internos (`_`), VARIOS y columnas logisticas.
- Cliente no ve columnas logisticas; superadmin si.
- Export cliente usa la misma visibilidad que la tabla (incluye BLOQUEADO/OBSOLETO, excluye VARIOS y `_`).

**Validacion:**
- `php artisan test tests/Feature/StockOverviewTest.php`: 36 passed.
- `php artisan test`: **510 passed** (2383 assertions).
- `npm run build`: OK.
- `git diff --check`: OK.
- Reproduccion superadmin vs cliente ejecutada contra la base real (rollback), listando los registros ocultados y confirmando que son solo VARIOS / `_`.

**Control de alcance:**
- No se toco `.env`, secretos, `vendor/` ni `node_modules/`.
- No se uso `migrate:fresh` ni se borraron datos.
- No se tocaron las reglas de importacion (el bug estaba en la query de la vista).
- Solo se modificaron el builder de stock y sus tests.
- `.claude/` fuera del commit.

---

## 2026-07-12 - Verificacion stock cliente FRIESLAND (con datos reales) + boton global de notificaciones

**Contexto:** (1) Se reportaba que el cliente FRIESLAND seguia viendo solo parte del stock; (2) se pide un boton para que superadmin marque como leidas todas las notificaciones de todos los usuarios. Sesion de inspeccion primero: se reprodujo superadmin vs cliente con los datos REALES del Excel del cliente.

### PROBLEMA 1: stock cliente FRIESLAND

**Metodo de comprobacion (no "parece que ya esta"):** se cargo el Excel real `STOCK_FRIESLAND.xlsx` (createPreview + confirm) dentro de una transaccion con rollback (nada persistido) y se compararon los conjuntos COMPLETOS (sin paginar) de superadmin y cliente.

**Resultado exacto (datos reales):**
- Superadmin FRIESLAND: 160 partidas con stock, 114 referencias distintas, 2339 palets almacen.
- Cliente FRIESLAND: 155 partidas, 110 referencias distintas, 2261 palets almacen.
- Partidas que superadmin ve y cliente NO ve: **5, todas internas (categoria misc / SKU "_")**:
  - `_CAJA057` (lote 827060010), `_CAJA057` (NO LOTE), `_CERQUILLOS`, `_PALLET AMER`, `_SIN_ID`.
- Registros NO internos ocultos al cliente: **0**. Fugas de internos al cliente: **0**.
- SKUs de prueba (presencia super / cliente): ET0432 SI/SI, CAJA0030 SI/SI, CRYOVAC6 SI/SI, CAJA0077 SI/SI (in_use), ET0336 SI/SI (obsolete), FILM0519 SI/SI (obsolete), _CAJA057 SI/NO.

**Causa raiz:** no habia bug de sobre-filtrado en la query del cliente. La visibilidad ya era correcta tras el fix de la sesion anterior (`6316c4e`, ocultar misc **o** SKU "_"). El cliente ve exactamente el stock de su cliente menos las internas (VARIOS/`_`), manteniendo EN USO, BLOQUEADO y OBSOLETO. Aclaraciones detectadas:
- El fichero del cliente se habia actualizado entre sesiones: la referencia interna `_FILM0519` (antes en BOBINAS, misc) ahora es `FILM0519` en la pestana OBSOLETO (obsolete, sin `_`). Por eso ahora es visible a ambos, lo cual es correcto (OBSOLETO es visible al cliente).
- La tarjeta "110 referencias visibles" es una AGRUPACION (referencias distintas con stock), no un limite de visibilidad: 110 referencias distintas reparten 155 partidas. Confundia al leerse como "solo 110 visibles".

**Correccion aplicada (solo aclaracion de etiqueta; la query no se toca porque ya era correcta):**
- `resources/views/stock/index.blade.php`: la etiqueta de la tarjeta resumen del cliente pasa de "Referencias visibles" a "Referencias distintas con stock", para no confundir agrupacion con visibilidad. El numero (references_with_stock) y su calculo no cambian.
- No se toco la query, ni el import, ni scopes: la comprobacion demostro que el cliente ya ve todo lo no interno.

**Tests (stock):** ademas de los 5 escenarios de la sesion anterior (cliente ve EN USO/BLOQUEADO/OBSOLETO; no ve VARIOS ni `_` incl. mal categorizado; no ve otro cliente; superadmin ve internos/VARIOS/columnas; export = misma visibilidad), se anade que la tarjeta/contador del cliente usa la MISMA visibilidad que la tabla (references_with_stock == filas visibles, sin query distinta).

### PROBLEMA 2: boton global "Marcar todas como leidas" (solo superadmin)

**Implementacion:**
- `routes/web.php`: nueva ruta `POST /notificaciones/marcar-todas-leidas` (name `notifications.read-all`) con `minimum.role:superadmin`.
- `app/Http/Controllers/NotificationController.php`: `markAllAsRead()` con `abort_unless(isSuperAdmin(), 403)`; actualiza todas las notificaciones no leidas de todos los usuarios fijando `read_at` (no borra ni oculta); flash "Se han marcado X notificaciones como leidas." o "No habia notificaciones pendientes.".
- `resources/views/notifications/index.blade.php`: boton visible solo para superadmin, POST con `@csrf`, confirmacion explicita ("...TODAS las notificaciones de TODOS los usuarios..."), texto "Marcar todas como leidas" y aria-label aclaratorio. Nota visible de que la accion afecta a todos los usuarios.

**Tests (notificaciones):** superadmin marca como leidas las de todos los usuarios (mensaje con recuento, contador de no leidas a 0 para los afectados, sin borrar registros); sin pendientes informa "No habia..."; cliente, almacen y administracion reciben 403; el boton y la ruta solo aparecen para superadmin.

### Validacion
- `php artisan test`: **517 passed** (2408 assertions).
- `npm run build`: OK.
- `git diff --check`: OK.
- Reproduccion superadmin-vs-cliente ejecutada contra los datos reales (transaccion con rollback), listando las 5 partidas ocultas y confirmando que son SOLO internas (misc/`_`).

### Control de alcance
- No se toco `.env`, secretos, `vendor/` ni `node_modules/`.
- Sin migraciones (no habia cambios de esquema). Sin `migrate:fresh`. Sin borrar datos.
- No se cambiaron las reglas de importacion (PROBLEMA 1 estaba en percepcion/etiqueta, no en la query).
- Notificaciones: solo se marcan como leidas, nunca se borran ni se ocultan; accion inaccesible a clientes.
- `.claude/` fuera del commit.

---

## 2026-07-12 - Bandeja de notificaciones compacta (tipo email) + borrado global (superadmin)

**Contexto:** El panel de notificaciones era demasiado grande y torpe (cada aviso una tarjeta enorme, casi media pantalla). Se pide rediseñarlo como bandeja tipo email (compacta, escaneable) y añadir botones de administracion para eliminar notificaciones (solo superadmin).

### Rediseño del panel (UX)
- `resources/views/notifications/_card.blade.php`: cada notificacion pasa de una tarjeta `surface-card` apilada (badges + titulo + parrafo + meta en bloques) a una **fila compacta** tipo inbox: punto de estado, titulo (una linea con ellipsis), resumen corto (una linea con ellipsis), fecha, badge de tipo, badge "Nueva" en no leidas y acciones rapidas (Abrir / Marcar leida).
- `resources/views/notifications/index.blade.php`: la lista pasa a un contenedor unico `surface-card ... notification-inbox` con filas separadas por borde (aspecto bandeja), en vez de N tarjetas sueltas.
- `resources/css/app.css`: reescrito el bloque de notificaciones a filas flex compactas; se elimino el padding grande (incluido el override del shell oscuro `body.brand-body.app-shell-body .notification-card` que volvia a inflar la fila) y se ajusto el bloque responsive.
- **Verificacion visual** con el CSS compilado real (markup identico al Blade): altura de fila **~56px** (objetivo 56-76px), `display:flex`, padding 0.5rem 0.9rem, titulos truncados con ellipsis; no leidas con gradiente suave + borde/punto teal + titulo en negrita, leidas en blanco neutro; botones de borrado en rojo.
- Estado leido/no leido, tipo (BOOKING/PEDIDO/SALIDA/STOCK/SISTEMA/ERROR) y colores corporativos se mantienen.

### Botones de borrado global (solo superadmin)
- `routes/web.php`: `DELETE /notificaciones/no-leidas` (`notifications.destroy-unread`) y `DELETE /notificaciones/todas` (`notifications.destroy-all`), ambas con `minimum.role:superadmin`.
- `app/Http/Controllers/NotificationController.php`: `destroyAllUnread()` y `destroyAll()`, cada una con `abort_unless(isSuperAdmin(), 403)`. Borran solo registros de la tabla `notifications` (no usuarios ni datos relacionados). Flash con recuento: "Se han eliminado X notificaciones no leidas." / "Se han eliminado X notificaciones." / "No habia notificaciones para eliminar.".
- Vista: botones "Eliminar no leidas" y "Eliminar todas" (junto a "Marcar todas como leidas"), visibles solo para superadmin, via DELETE con `@method('DELETE')` + `@csrf`, con confirmacion JS explicita ("...todas las notificaciones no leidas de todos los usuarios..." / "...TODAS las notificaciones de TODOS los usuarios. Esta accion no se puede deshacer...").
- No se anadio borrado individual por fila para no ampliar el alcance (las acciones por fila siguen siendo Abrir / Marcar leida).

### Permisos
- Superadmin: marcar todas como leidas, eliminar no leidas, eliminar todas.
- Cliente, almacen y administracion: no ven los botones globales y reciben 403 en las rutas globales.

### Tests (NotificationCenterTest, todos los obligatorios)
- Superadmin ve "Eliminar no leidas" y "Eliminar todas"; cliente/almacen/administracion no los ven.
- Superadmin elimina todas las no leidas de todos (las leidas se conservan) y elimina todas (no queda ninguna), con recuento correcto en el flash.
- Cliente, almacen y administracion reciben 403 en ambas rutas globales y no se borra nada.
- Sin notificaciones, el flash informa "No habia notificaciones para eliminar.".
- La bandeja usa filas compactas (`notification-inbox`, `notification-card-title`, `notification-card-body`).

### Validacion
- `php artisan test`: **524 passed** (2457 assertions).
- `npm run build`: OK.
- `git diff --check`: OK.
- Cambios acotados al modulo de notificaciones (controlador, rutas, vistas, CSS de notificaciones) + sus tests.

### Control de alcance
- No se toco `.env`, secretos, `vendor/` ni `node_modules/`.
- Sin migraciones. Sin `migrate:fresh`. No se borran usuarios ni datos de otros modulos; solo registros de notificaciones al usar los botones.
- Otros roles no pueden ejecutar las rutas globales.
- `.claude/` fuera del commit.
- Pendiente de despliegue en Forge (no verificado en produccion en esta sesion).

---

## 2026-07-12 - Salidas: carga real con pallets completos y pico parcial por partida

**Equipo:** PC de casa.
**Ruta:** `D:\dev\WMS_LARAVEL`.
**Rama:** `main`.
**Commit funcional:** `1c7f723 feat: registrar carga real parcial en salidas`.
**Contexto:** en `Salidas > Pedidos pendientes > SOL-...`, almacen necesita registrar la carga real exacta cuando se cargan pallets completos mas unidades sueltas de pico, escogiendo la partida/lote/ubicacion real. Antes el formulario solo admitia `loaded_quantity` y las lineas de pico quedaban limitadas a 0/1, sin poder expresar "0 pallets + 300 uds".

### Cambios funcionales
- `resources/views/dispatches/request.blade.php`: la tabla "LINEAS DEL PEDIDO Y CARGA REAL" ahora muestra lote/ubicacion, solicitado, stock disponible, selector de partida, inputs separados `loaded_pallets` y `loaded_partial_units`, total cargado en unidades, estado OK/Parcial/Exceso y observacion. Se mantiene `loaded_quantity` oculto por compatibilidad.
- `app/Http/Controllers/GoodsDispatchController.php`: la pantalla interna recibe partidas disponibles agrupadas por articulo del cliente, solo stock activo y disponible.
- `app/Http/Requests/ConfirmGoodsDispatchLoadingRequest.php`: valida pallets y unidades parciales, impide superar lo solicitado en una linea normal, impide superar el stock disponible de la partida seleccionada, bloquea partidas de otro cliente/articulo o no disponibles y conserva compatibilidad con el payload anterior.
- `app/Services/GoodsDispatches/GoodsDispatchWorkflowService.php`: guarda `stock_pallet_id`, `stock_peak_index`, lote y `loaded_partial_units` al confirmar preparacion, manteniendo trazabilidad de usuario/fecha ya existente.
- `app/Services/GoodsDispatches/StockDispatchAllocationService.php`: al enviar/completar descuenta pallets completos y unidades parciales de la partida real. Las unidades parciales consumen picos abiertos primero y, si hace falta, abren un pallet completo dejando el remanente como pico.
- `app/Models/GoodsDispatchLine.php` y `app/Models/GoodsDispatch.php`: helpers para calcular solicitado/cargado en unidades, detectar diferencias y mostrar etiquetas de carga real mixta.
- `resources/css/app.css`: ajustes compactos para la tabla de preparacion, selector de stock, campos numericos y fila de guardado.

### Migracion
- `database/migrations/2026_07_12_000002_add_loaded_partial_units_to_goods_dispatch_lines.php`: se anade `goods_dispatch_lines.loaded_partial_units` nullable.
- Justificacion: `loaded_peaks` ya representa contador de picos/lineas, no unidades reales. Reutilizarlo para "300 uds" romperia semantica existente, albaranes, contadores y compatibilidad con lineas de pico antiguas. La columna nueva es la forma minima de registrar carga real exacta sin reinterpretar datos historicos.

### Tests
- `tests/Feature/GoodsDispatchManagementTest.php`: nuevos casos para:
  - guardar 1 pallet + 300 uds desde una partida concreta sin descontar stock todavia;
  - rechazar carga por encima de lo solicitado;
  - rechazar carga por encima del stock de la partida seleccionada;
  - descontar al enviar de la partida elegida, dejando remanente de pallet como pico;
  - comprobar que la pantalla interna muestra selector de partida e inputs separados.
- Se ajusta el test antiguo que confirmaba mas pallets que los solicitados en una linea normal, porque la nueva regla exige no superar lo pedido salvo lineas extra.

### Validacion
- `php artisan test tests/Feature/GoodsDispatchManagementTest.php`: **40 passed** (219 assertions).
- `php artisan test`: **529 passed** (2490 assertions).
- `npm run build`: OK.
- `git diff --check`: OK.
- Lint PHP de archivos tocados: OK.
- `git status`: arbol limpio tras commit funcional.

### Control de alcance
- No se toco `.env`, secretos, `vendor/` ni `node_modules/`.
- No se uso `migrate:fresh`. No se borraron datos.
- Cambios acotados a preparacion/envio de salidas, pantalla interna de pedido pendiente, CSS asociado, migracion minima y tests.
- Push funcional a `origin/main`: OK (`c336ffa..1c7f723 main -> main`). Puede disparar Forge.
- Despliegue en produccion: no verificado desde esta sesion.

---

## 2026-07-12 - Detalle de pedido/solicitud del cliente: pantalla compacta tipo gestion

**Contexto:** Tras registrar un pedido, la pantalla de detalle (`merchandise-requests/show`) era demasiado voluminosa: hero enorme con el codigo, tarjeta "Datos" grande y poco util, "Seguimiento" como tarjeta alta, "Lineas" con filas gigantes y el aviso de fuera de horario como una linea amarilla/marron poco visual. Se pidio compactarla manteniendo estetica corporativa, sin tocar logica de negocio ni estados.

**Alcance controlado:** las clases `wms-*` (hero, timeline, line-card, kpi-tile) estan compartidas con dispatches (crear/ver salida) y crear pedido, asi que NO se tocaron. El rediseno usa clases nuevas propias (`order-*`) y CSS nuevo scopeado, sin afectar a otras pantallas.

**Rediseno (`resources/views/merchandise-requests/show.blade.php` + `resources/css/app.css`):**
- **Cabecera compacta** (`order-header`, ~102px): una banda con chip de tipo (Pedido cliente/interno), codigo del pedido (1.2rem, no un titulo enorme) y badge de estado, mas una fila de metadatos (Cliente, Solicitante, Fecha, Pallets, Picos, Unidades) en formato `dl` compacto. Se elimina la tarjeta grande "Datos": esos datos pasan a la banda de metadatos.
- **Seguimiento** (`order-track` + `order-steps`, ~74px): stepper horizontal en una sola linea Registrado -> Preparacion -> Enviado -> Completado, con conector, puntos y estados: completado en verde, actual en color corporativo (teal) con halo, pendiente en gris.
- **Lineas** (`order-table`, filas ~42px): tabla de gestion compacta con columnas SKU, Descripcion, Lote, Cantidad, Uds/pallet, Tipo (pill). Para internos se anade columna "Cargado". La seccion de carga real adicional se convierte a la misma tabla compacta.
- **Aviso fuera de horario** (`order-alert order-alert--warning`, ~60px): alerta clara con icono de triangulo, fondo ambar suave solido, borde izquierdo naranja y texto legible; titulo "Pedido fuera de horario operativo" y cuerpo corto. Se distingue del aviso generico de cambio de estado mediante un flag de sesion `scheduleWarning` (el `session('warning')` original se conserva intacto). Otros `session('warning')` (cambios de estado) siguen mostrandose como alerta ambar generica.
- **Mensaje de exito** (`order-alert order-alert--success`, ~39px): compacto y consistente con el resto de alertas, con icono.
- Toda la pantalla (exito + aviso + cabecera + seguimiento + tabla de 4 lineas) cabe en ~571px, frente al scroll largo anterior.
- Prioridad escritorio; se conservan ajustes responsive minimos para movil.

**Controlador (`MerchandiseRequestController@store`):** al registrar fuera de la ventana operativa, ademas del `->with('warning', ...)` existente (intacto, sigue cumpliendo los tests de `assertSessionHas('warning')`), se anade `->with('scheduleWarning', true)` para que la vista muestre el aviso estructurado con titulo fijo. No se cambio logica de negocio, estados, notificaciones ni el texto del servicio de horario.

**Archivos tocados:**
- `resources/views/merchandise-requests/show.blade.php` (rediseno completo con clases `order-*`).
- `resources/css/app.css` (bloque nuevo `order-*`, sin tocar `wms-*` compartidas).
- `app/Http/Controllers/MerchandiseRequestController.php` (flag `scheduleWarning`).
- `tests/Feature/MerchandiseRequestManagementTest.php` (test nuevo).

**Validacion:**
- Verificacion visual con el CSS compilado real (markup identico al Blade): cabecera ~102px, seguimiento ~74px, filas de lineas ~42px, aviso ~60px; puntos del stepper en verde (completado) y teal (actual); aviso ambar con borde naranja e icono.
- Tests de render de la vista siguen pasando (estado en espanol, badges de tipo Pallet completo/Pico, sin mojibake, permisos cliente/interno). Test nuevo: tras un pedido fuera de horario, la vista muestra el aviso estructurado ("Pedido fuera de horario operativo"), el mensaje de exito y las lineas en tabla (`order-table`).
- `php artisan test`: **525 passed** (2462 assertions).
- `npm run build`: OK.
- `git diff --check`: OK.

**Control de alcance:**
- No se toco `.env`, secretos, `vendor/` ni `node_modules/`.
- Sin migraciones. Sin `migrate:fresh`. Sin cambios de logica/estados del pedido ni de notificaciones/correos.
- No se tocaron clases CSS compartidas ni otras pantallas (dispatches, crear pedido).
- `.claude/` fuera del commit.
- Pendiente de despliegue en Forge (no verificado en produccion en esta sesion).

---

## 2026-07-12 - Preparacion de salidas con multiples asignaciones reales por linea

**Equipo:** PC de casa.
**Ruta:** `D:\dev\WMS_LARAVEL`.
**Rama:** `main`.
**Commit funcional:** `f6bc888 feat: preparar salidas con asignaciones reales multiples`.
**Push funcional:** OK a `origin/main` (`a937b82..f6bc888 main -> main`). Puede disparar Forge.
**Despliegue en produccion:** no verificado desde esta sesion.

### Contexto
- La pantalla `Salidas > Pedidos pendientes > SOL-...` ya permitia guardar pallets completos y unidades parciales, pero seguia siendo insuficiente para preparar una carga real como `CAJA0031`: pedido de 3 pallets, carga real de 1 pallet completo + picos existentes de 500 y 390 uds.
- El modelo anterior de `goods_dispatch_lines` solo podia guardar una partida/lote por linea, por lo que no podia auditar varias partidas, ubicaciones o picos seleccionados dentro de la misma linea.

### Alcance funcional
- Se sustituyo la tabla ancha de preparacion por tarjetas compactas por linea, sin scroll horizontal, con resumen de solicitado/cargado/diferencia en unidades.
- Cada linea admite varias asignaciones de stock:
  - selector de partida/lote/ubicacion;
  - pallets completos;
  - picos existentes seleccionables como chips;
  - unidades manuales de pico;
  - total de asignacion y total de linea recalculados en JS.
- El backend valida que:
  - la partida sea del mismo cliente/articulo y este activa/disponible;
  - no se supere lo solicitado salvo lineas extra;
  - no se supere el stock de la partida;
  - no se use el mismo pico dos veces;
  - los picos seleccionados existan y tengan unidades.
- Al enviar/completar una salida, el descuento de stock usa las asignaciones reales guardadas y consume primero los picos seleccionados exactos.
- Se mantiene compatibilidad con payloads anteriores (`loaded_quantity`, `stock_pallet_id`, `loaded_partial_units`) y campos resumen en `goods_dispatch_lines`.

### Migracion
- `database/migrations/2026_07_12_000003_create_goods_dispatch_line_allocations_table.php`.
- Justificacion: era necesaria porque una linea de salida puede cargarse desde varias partidas/lotes/ubicaciones y con varios picos concretos. Reutilizar solo `goods_dispatch_lines.stock_pallet_id` habria perdido trazabilidad de la carga real. La migracion es aditiva, no destructiva, y mantiene los campos existentes como resumen/compatibilidad.

### Archivos principales
- `app/Models/GoodsDispatchLineAllocation.php`: nuevo modelo para asignaciones reales.
- `app/Models/GoodsDispatchLine.php`: relacion `allocations` y calculos de cargado desde asignaciones cuando existen.
- `app/Http/Requests/ConfirmGoodsDispatchLoadingRequest.php`: validacion y resolucion de asignaciones multiples.
- `app/Services/GoodsDispatches/GoodsDispatchWorkflowService.php`: sincronizacion de asignaciones al confirmar preparacion.
- `app/Services/GoodsDispatches/StockDispatchAllocationService.php`: descuento por asignaciones reales al enviar/completar.
- `resources/views/dispatches/request.blade.php`, `resources/css/app.css`, `resources/js/app.js`: nuevo editor de preparacion por tarjetas y recalculo de unidades.
- `tests/Feature/GoodsDispatchManagementTest.php`: cobertura de asignaciones multiples, CAJA0031, duplicado de pico, stock real y compatibilidad.

### Validacion
- `php artisan test tests\Feature\GoodsDispatchManagementTest.php`: **44 passed** (250 assertions).
- `php artisan test`: **533 passed** (2521 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `git diff --check`: OK.
- Lint PHP de archivos tocados: OK.
- Verificacion visual local con fixture temporal y assets compilados:
  - escritorio: `scrollWidth=1280`, `viewportWidth=1280`, sin scroll horizontal;
  - interaccion CAJA0031: 1 pallet + picos 500/390 => total asignacion `1590`, parcial oculto `890`, diferencia `510`, estado `Parcial`;
  - movil 390px: layout en una columna, `scrollWidth=375`, sin scroll horizontal.

### Control de alcance
- No se toco `.env`, secretos, `vendor/` ni `node_modules/`.
- No se uso `migrate:fresh`.
- No se borraron datos. La verificacion visual uso una SQLite temporal y una fixture temporal en `public/`, ambas eliminadas antes del cierre.
- Cambios acotados al flujo de preparacion/envio de salidas y sus tests.
- Pendiente: verificar realmente despliegue en Forge/produccion si Forge ejecuta el deploy tras el push.

---

## 2026-07-13 - Carga real superior a solicitado y espaciado de preparacion (13:12 +02:00)

**Equipo:** PC del trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit inicial tras actualizar:** `ab7771e6 docs: registrar preparacion con asignaciones multiples`.
**Commit funcional final:** `39b49564 fix: allow operational overloading in dispatch preparation`.
**Push funcional:** confirmado a `origin/main` (`ab7771e6..39b49564`).
**Despliegue:** pendiente de verificacion en Forge/produccion; no se modifico produccion directamente.

### Actualizacion y entorno local
- `git pull --ff-only origin main`: fast-forward desde `023cb75d` hasta `ab7771e6`.
- MySQL/XAMPP disponible y puerto `3306` activo.
- `composer install`: sin paquetes que instalar; se mantiene el aviso existente de lock desalineado con `composer.json` y deprecaciones de Google API para PHP 8.4.
- `npm install`: dependencias al dia; npm informa 4 vulnerabilidades existentes (1 low, 1 high, 2 critical). No se ejecuto `npm audit fix` para evitar actualizaciones no controladas.
- Laravel local arrancado en `http://127.0.0.1:8000`; Vite ya estaba activo en `5173`.
- Se aplicaron sin borrar datos las tres migraciones pendientes sincronizadas desde `origin/main`:
  - `2026_07_12_000001_add_stock_category_and_warehouse_pallets`.
  - `2026_07_12_000002_add_loaded_partial_units_to_goods_dispatch_lines`.
  - `2026_07_12_000003_create_goods_dispatch_line_allocations_table`.
- Comprobacion final `php artisan migrate`: `Nothing to migrate`.

### Correccion funcional
- La preparacion ya permite cargar menos, exactamente o mas unidades/pallets/picos que lo solicitado.
- Se elimino exclusivamente el techo contra cantidad solicitada; se conservan validaciones por partida, cliente, articulo, estado, cantidades negativas, stock disponible y reutilizacion imposible del mismo pico.
- Guardar preparacion no descuenta stock. Al enviar/completar, la salida descuenta la carga real mediante las asignaciones guardadas, con bloqueo transaccional y proteccion para no aplicar stock dos veces.
- Una carga superior se muestra como `Carga superior a lo solicitado` y `Exceso operativo`; es un estado permitido, no un error.
- Estados de linea cubiertos: `Sin preparar`, `Parcial`, `Completo` y `Superior`.
- Si la carga supera el stock, el mensaje visible es el motivo real (`La carga real supera el stock disponible...`) y no el aviso generico de linea vacia.

### Correccion visual
- Panel principal de lineas con padding real de `20px`.
- Cabecera, tarjetas de linea, resumen y asignaciones con padding minimo de `16px`; separacion entre lineas de `20px`.
- Timeline con padding `16px 20px`; botones y controles quedan separados de los bordes.
- Se corrigio un `@hidden(...)` que llegaba literalmente al HTML y mostraba el texto basura `id)>`.
- Se mantiene `SOL-xxxxx` como referencia secundaria; las lineas y la carga real siguen inmediatamente debajo de la cabecera compacta.

### Validacion manual local
- Fixture QA local `SOL-000003`, restaurada por completo al terminar; no se envio/completo la salida ni se aplico stock.
- 2 pallets sobre 3 solicitados: `Parcial`.
- 3 pallets sobre 3 solicitados: `Completo`.
- 4 pallets sobre 3 solicitados, con 5 disponibles: `Carga superior a lo solicitado`, permitido.
- 6 pallets con 5 disponibles: bloqueado con mensaje especifico de stock.
- Medicion escritorio: `scrollWidth = viewportWidth = 1265`, sin scroll horizontal; panel principal `20px`, lineas/asignaciones `16px`.
- Cuenta/stock/asignacion/notificaciones QA temporales restaurados o eliminados al cerrar la comprobacion.

### Tests y build
- `php artisan test tests\Feature\GoodsDispatchManagementTest.php`: `47 passed` (283 assertions).
- `php artisan test`: `536 passed` (2554 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `php artisan optimize:clear`: OK.
- `git diff --check`: OK.
- Se hizo determinista un fixture de `StockOverviewTest` fijando `peak_1 = 0`; no cambia codigo funcional de stock.

### Control de alcance
- No se toco `.env`, secretos, importacion de stock, facturacion, Google Calendar ni datos de produccion.
- No se crearon migraciones nuevas en este cambio, no se uso `migrate:fresh`, no se hizo force push y no se borraron datos operativos.
- `.claude/` permanece sin trackear y fuera de los commits.
- Para Forge, verificar que el deploy de `origin/main` ejecuta `php artisan migrate --force`, y despues ejecutar/verificar `php artisan optimize:clear` y `php artisan queue:restart`.

---

## 2026-07-13 - Integridad de stock al enviar salidas (13:50 +02:00)

**Equipo:** PC del trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit funcional:** `5dfe6e1c fix: keep dispatch stock totals in sync`.
**Push funcional:** confirmado a `origin/main` (`182973b8..5dfe6e1c`).
**Produccion:** pendiente de despliegue y validacion en Forge; no se modifico produccion directamente.

### Causa raiz
- El descuento se ejecutaba correctamente desde `GoodsDispatchWorkflowService::changeStatus()` al pasar a `sent` o `completed`, llamando a `StockDispatchAllocationService::apply()` y usando la carga real (`loaded_pallets` / `loaded_partial_units` y asignaciones), no la solicitada.
- Ese servicio reducia `quantity_units`; el observer de `StockPallet` recalculaba `full_pallets` y `peaks_count`.
- La regresion estaba en `warehouse_pallets`: al ser un valor declarado/importado no nulo, el observer lo conservaba. `StockOverviewBuilder` usa precisamente `warehouse_pallets` para el KPI principal, por lo que el total visible podia seguir en 1.026 aunque las unidades y pallets completos ya hubieran bajado.
- No es una regresion causada por permitir carga superior a lo solicitado. Esa funcionalidad entrega correctamente la carga real al servicio; el fallo procedia del KPI logistico incorporado despues.

### Correccion e idempotencia
- Cada pallet completo retirado reduce ahora `warehouse_pallets` en uno, preservando decimales existentes.
- Vaciar un pico reduce una unidad logistica; consumir solo parte del pico no la reduce. Romper un pallet completo para dejar un pico conserva una unidad logistica; consumirlo entero la elimina.
- El descuento sigue bloqueando stock insuficiente, otro cliente y partidas no resueltas, y toda la operacion permanece dentro de una transaccion.
- `changeStatus()` bloquea ahora la fila de salida con `lockForUpdate()` y vuelve a comprobar el estado/marca dentro de la transaccion. `stock_applied_at` impide repetir unidades y la nueva marca `warehouse_stock_applied_at` impide repetir el descuento logistico, incluso ante reintentos concurrentes.
- Nueva migracion aditiva: `database/migrations/2026_07_13_000001_add_warehouse_stock_applied_tracking_to_goods_dispatches.php`.

### Respuestas del diagnostico
1. El punto de descuento es `GoodsDispatchWorkflowService::changeStatus()` al entrar en `sent` (o directamente `completed`), mediante `StockDispatchAllocationService::apply()`.
2. Si: el controlador llama realmente a ese metodo al enviar.
3. La salida queda marcada con `stock_applied_at`; desde este cambio tambien con `warehouse_stock_applied_at`. La salida real de produccion debe verificarse por ID tras desplegar.
4. Las lineas guardan carga real y, cuando corresponde, asignaciones por partida/lote/pico.
5. Si: la causa exacta era que bajaban unidades/full pallets pero no el valor declarado `warehouse_pallets`.
6. Si: el descuento actuaba sobre `StockPallet`, pero el KPI sumaba otro campo del mismo modelo que quedaba obsoleto.
7. No: se descuenta al enviar; completar solo aplica si se llega directamente sin aplicacion previa.
8. No existe una condicion que excluya pedidos de cliente; manuales y pedidos usan el mismo flujo.
9. La carga superior no es la causa y queda cubierta: si se cargan 10 aunque se pidieran 8, se descuentan 10.
10. En la base local hay 0 salidas enviadas/completadas sin `stock_applied_at` y 0 reparaciones logisticas pendientes. Produccion requiere dry-run; no se asumio ni modifico ningun dato remoto.

### Reparacion segura
- Nuevo comando: `php artisan wms:dispatches:apply-missing-stock --dry-run` lista cliente, salida, fecha y detalle de carga real sin modificar datos.
- Para una salida sin `stock_applied_at`: primero `php artisan wms:dispatches:apply-missing-stock --dispatch=ID --dry-run` y, tras revisar, `php artisan wms:dispatches:apply-missing-stock --dispatch=ID`.
- Para el caso historico donde las unidades ya bajaron pero el KPI no: primero `php artisan wms:dispatches:apply-missing-stock --dispatch=ID --repair-warehouse --dry-run` y, tras confirmar el ID/carga, ejecutar sin `--dry-run`.
- La reparacion historica solo descuenta `warehouse_pallets`, no vuelve a tocar `quantity_units`, y deja marca propia. Se rechazan automaticamente reparaciones historicas con picos/unidades parciales porque no pueden reconstruirse con certeza despues del hecho.
- No hay aplicacion masiva automatica ni cambios sin `--dry-run` o un `--dispatch=ID` explicito.

### Validacion
- Caso EDELVIVES reproducido: 1.026 pallets almacen, carga real de 10 sobre 8 solicitados, resultado persistido 1.016; SKU/lote, KPI, pantalla y exportacion reflejan el descuento.
- Repetir `ENVIADO` no modifica de nuevo ni unidades ni pallets almacen.
- Cubiertos: salida manual, salida de pedido cliente, carga real superior, pallets completos, pico completo, pico parcial, multiples asignaciones, stock insuficiente, aislamiento por cliente, overview, pantalla y export.
- Tests dirigidos: `52 passed` (340 assertions).
- `php artisan test`: **541 passed** (2611 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `php artisan optimize:clear`: OK.
- `php artisan migrate`: migracion nueva aplicada localmente; comprobacion posterior `Nothing to migrate`.
- Pint: OK. `git diff --check`: OK.

### Forge pendiente
1. Desplegar `origin/main` y confirmar commit `5dfe6e1c` o posterior.
2. Ejecutar `php artisan migrate --force`.
3. Ejecutar `php artisan optimize:clear` y `php artisan queue:restart`.
4. Ejecutar primero el dry-run global y localizar la salida real de EDELVIVES.
5. Revisar por ID si tiene `stock_applied_at`; aplicar solo la modalidad indicada arriba.
6. Confirmar en `/stock` que EDELVIVES baja de 1.026 a 1.016 y que el SKU/lote concreto y la exportacion coinciden.

### Control de alcance
- No se tocaron vistas, CSS, `.env`, secretos, importacion Friesland/Edelvives, Google Calendar ni facturacion.
- No se uso `migrate:fresh`, no se borraron datos, no se hizo force push y no se parcheo stock a mano.
- `.claude/` permanece sin trackear y fuera de los commits.

---

## 2026-07-13 - Detalle de pedido orientado a carga (16:35 +02:00)

**Equipo:** PC de casa.
**Ruta:** `D:\dev\WMS_LARAVEL`.
**Rama:** `main`.
**Commit funcional:** `73d61185 feat: prioritize request loading workflow`.
**Push funcional:** confirmado a `origin/main` (`72cde1b..73d6118`).
**Produccion:** pendiente de despliegue y verificacion real en Forge; no se modifico produccion directamente.

### Preparacion y sincronizacion
- Se leyo `SESSION_LOG.md` completo antes de tocar archivos.
- Comprobaciones iniciales: rama `main`, remoto `origin https://github.com/maximoslu/WMS_LARAVEL.git`, arbol limpio y ultimos commits revisados.
- `git pull --ff-only origin main`: fast-forward correcto hasta `72cde1b`.
- No procedio ejecutar `composer install` ni `npm install`: el pull no cambio manifiestos ni locks.

### Cambio funcional
- El detalle de solicitud de mercancia prioriza ahora la preparacion: bloque principal `Preparacion del pedido`, lineas visibles primero y CTA operativo `Empezar carga`, `Continuar carga` o `Ver carga`.
- La accion de iniciar carga desde el detalle crea la salida y redirige directamente a la pantalla de carga real (`dispatches.requests.show`) mediante `return_to_request=1`.
- Las acciones secundarias quedan plegadas en `Mas acciones`: cambio de estado, documentos, salida tecnica, albaran si aplica y volver.
- Se elimino el texto operativo confuso `Generar salida` de las vistas de pedido/carga; queda solo cubierto por aserciones negativas en tests.
- El detalle muestra para usuarios internos el estado de carga por linea: `Pendiente de cargar`, `Parcial`, `Completo` o `Exceso`.
- Los clientes siguen viendo sus lineas, pero no ven acciones internas de carga, cambio de estado ni documentos operativos secundarios.
- El controlador carga `dispatch.lines.allocations.stockPallet` para calcular correctamente carga real/asignaciones sin consultas adicionales descontroladas.

### UI
- Se mantuvo una interfaz compacta y corporativa en espanol.
- La preparacion y las lineas aparecen antes de acciones secundarias.
- `resources/css/app.css` incorpora estilos acotados para el bloque de preparacion, CTA principal, panel plegable y responsive movil.

### Tests
- Nuevas coberturas en `MerchandiseRequestManagementTest`:
  - detalle interno prioriza carga y lineas frente a acciones secundarias;
  - iniciar carga desde detalle crea salida y abre pantalla de carga;
  - salida existente muestra `Continuar carga`;
  - cliente no ve acciones internas;
  - `almacen`, `administracion` y `superadmin` pueden iniciar carga desde detalle con trazabilidad `created_by`.
- Tests existentes de `GoodsDispatchManagementTest` adaptados al nuevo lenguaje operativo.
- Targeted: `php artisan test tests\Feature\MerchandiseRequestManagementTest.php tests\Feature\GoodsDispatchManagementTest.php`: `89 passed` (534 assertions).
- Suite completa: `php artisan test`: `546 passed` (2658 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `git diff --check`: OK.

### Control de alcance
- No se tocaron `.env`, secretos, `vendor/`, `node_modules/`, migraciones, importacion de stock, facturacion ni datos.
- No se uso `migrate:fresh`, no se borraron datos, no se hizo force push.
- No se da por desplegado en produccion: falta verificar Forge y la pantalla real tras despliegue.

---

## 2026-07-13 - Cierre directo de carga y albaran desde pedido (16:54 +02:00)

**Equipo:** PC de casa.
**Ruta:** `D:\dev\WMS_LARAVEL`.
**Rama:** `main`.
**Commit funcional:** `0bfa7708 feat: close request dispatch from loading screen`.
**Push funcional:** confirmado a `origin/main` (`1f5c86b..0bfa770`).
**Produccion:** pendiente de despliegue y verificacion real en Forge; no se modifico produccion directamente.

### Cambio funcional
- La pantalla de preparacion del pedido queda como flujo unico para operar: preparar, elegir transporte y cerrar envio sin obligar a entrar en una pantalla de salida separada.
- Se elimina de las vistas de pedido/preparacion el enlace visible `Ver salida tecnica`.
- En la misma pantalla de carga se muestra `Cerrar pedido` con seleccion explicita `Camion externo` / `Camion propio`.
- El boton secundario `Guardar preparacion` conserva el comportamiento anterior: guarda carga real sin descontar stock ni enviar.
- El boton principal `Confirmar envio y abrir albaran` guarda carga real, marca la salida y el pedido como `Enviado`, aplica descuento de stock, guarda el tipo de camion y redirige al albaran.
- Si la salida ya esta enviada o completada, la pantalla muestra acceso directo `Abrir albaran`.
- El campo `camion_propio` solo se actualiza cuando el formulario lo envia, para no alterar otros flujos de confirmacion de carga.

### Tests y build
- Targeted: `php artisan test tests\Feature\GoodsDispatchManagementTest.php tests\Feature\MerchandiseRequestManagementTest.php`: `90 passed` (546 assertions).
- Suite completa: `php artisan test`: `547 passed` (2670 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `php -l` en controlador/request: OK.
- `git diff --check`: OK.
- Busqueda de textos: no queda `Ver salida tecnica` en vistas; solo queda una asercion negativa en tests.

### Control de alcance
- No se tocaron `.env`, secretos, `vendor/`, `node_modules/`, migraciones ni datos.
- No se uso `migrate:fresh`, no se borraron datos y no se hizo force push.
- El push a `main` puede disparar Forge; no se da por desplegado ni validado en produccion sin comprobacion real.

---

## 2026-07-13 - Ubicacion destino y emails de albaranes de salida (17:14 +02:00)

**Equipo:** PC de casa.
**Ruta:** `D:\dev\WMS_LARAVEL`.
**Rama:** `main`.
**Commit funcional:** `90617783 feat: add destination locations and dispatch email recipients`.
**Push funcional:** confirmado a `origin/main` (`f831a8f..9061778`).
**Produccion:** pendiente de despliegue y verificacion real en Forge; no se modifico produccion directamente.

### Cambio funcional
- Los pedidos de mercancia permiten indicar opcionalmente una `Ubicacion destino` por cada referencia/linea.
- La ubicacion destino se guarda en `merchandise_request_lines`, se copia al generar la salida en `goods_dispatch_lines` y se muestra en:
  - detalle del pedido;
  - pantalla de preparacion/carga;
  - hoja PDF de preparacion;
  - albaran PDF de salida.
- En la ficha de cliente se anade una lista independiente `Emails para albaranes de salida`.
- Estos emails adicionales reciben por correo el albaran de salida sin crear usuarios WMS.
- El email del usuario cliente sigue recibiendo el albaran como antes; los emails adicionales se deduplican contra usuarios cliente para evitar envios dobles.

### Migraciones
- `2026_07_13_000002_add_destination_location_to_request_and_dispatch_lines.php`: columnas nullable `destination_location` en lineas de pedido y salida.
- `2026_07_13_000003_create_client_dispatch_email_recipients_table.php`: tabla para destinatarios extra de albaranes de salida por cliente.
- `php artisan migrate`: OK. En esta base local tambien estaban pendientes y quedaron aplicadas las migraciones previas `2026_07_12_000002`, `2026_07_12_000003` y `2026_07_13_000001`.
- Comprobacion posterior `php artisan migrate`: `Nothing to migrate`.

### Tests y build
- Targeted: `php artisan test tests\Feature\ClientManagementTest.php tests\Feature\MerchandiseRequestManagementTest.php tests\Feature\GoodsDispatchManagementTest.php`: `100 passed` (592 assertions).
- Suite completa: `php artisan test`: `551 passed` (2695 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `php -l` en requests/controladores/modelo/servicio/notificacion tocados: OK.
- `git diff --check`: OK.

### Control de alcance
- No se tocaron `.env`, secretos, `vendor/`, `node_modules/`, importacion de stock ni datos de produccion.
- No se uso `migrate:fresh`, no se borraron datos y no se hizo force push.
- Forge debe ejecutar `php artisan migrate --force`; no se da por desplegado ni validado en produccion sin comprobacion real.

---

## 2026-07-14 - Stock cliente compacto y cierre visible de pedidos (11:26 +02:00)

**Equipo:** PC del trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Base inicial:** `95a3e9c9 docs: record destination locations and dispatch emails`.
**Produccion:** no se modifico directamente ni se da por desplegado este cambio.

### Stock cliente
- El KPI cliente muestra `Pales totales`, calculado siempre como `pales completos + picos`; por ejemplo, 7 completos + 1 pico = 8.
- La tabla cliente se compacta a una linea por referencia y lote, sumando partidas/ubicaciones sin perder el desglose disponible al abrir el detalle.
- La fila principal muestra SKU, descripcion, lote, pales totales, cantidad, estado y accion de detalle. Los estados cliente quedan reducidos a `EN USO`, `BLOQUEADO` u `OBSOLETO` con color discreto.
- El detalle muestra unidades por pale, pales completos, picos, unidades de cada pico, ubicacion, categoria, bloqueo y notas cuando existen.
- La misma regla de visibilidad sigue ocultando `VARIOS` y referencias cuyo SKU empieza por `_`, pero conserva bloqueados y obsoletos del cliente.
- Excel, CSV y PDF incorporan una unica columna `PALES TOTALES`; no exportan una columna independiente de picos.

### Pedidos y salidas
- Una salida `Enviada` muestra una accion directa `Marcar como completado` en el detalle de salida y en la pantalla interna de preparacion del pedido.
- El detalle interno del pedido muestra la misma accion cuando su salida asociada esta enviada.
- La accion solo esta disponible para `superadmin`, `administracion` y `almacen`; el cliente no la ve y la ruta sigue devolviendo 403.
- Se reutiliza `GoodsDispatchWorkflowService`: pedido y salida pasan juntos a `Completado`, se guardan fechas y `stock_applied_at` evita un segundo descuento.

### Validacion
- Tests dirigidos de stock, exportacion, salidas y pedidos: **153 passed** (868 assertions).
- Suite completa `php artisan test`: **555 passed** (2761 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `php artisan migrate`: `Nothing to migrate`; este cambio no anade migraciones.
- `php artisan optimize:clear`: OK en config, cache, compiled, events, routes y views.
- Pint: OK. `git diff --check`: OK.
- La inspeccion visual automatizada local no pudo iniciarse por un error del controlador del navegador (`Cannot redefine property: process`); el HTML desktop/movil y los desplegables quedaron cubiertos por tests de renderizado.

### Forge pendiente
1. Desplegar `origin/main` cuando el commit funcional de esta entrada este disponible.
2. Ejecutar `php artisan migrate --force` (debe quedar sin cambios pendientes).
3. Ejecutar `php artisan optimize:clear` y `php artisan queue:restart`.
4. Validar con un cliente que el total superior y cada fila usan completos + picos, que el detalle abre y que `VARIOS`/`_` siguen ocultos.
5. Validar con un usuario interno que una salida enviada se completa desde el boton directo y que el stock no vuelve a descontarse.

### Control de alcance
- No se tocaron `.env`, secretos, `vendor/`, `node_modules/`, migraciones, importacion Friesland/Edelvives, Google Calendar, facturacion ni datos.
- No se uso `migrate:fresh`, no se borraron datos y no se hara force push.
- `.claude/` permanece sin trackear y queda fuera del commit.

---

## 2026-07-14 - Pedidos, transporte e importacion segura de stock (17:37 +02:00)

**Equipo:** PC del trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit inicial:** `66f74a2d feat: improve client stock and dispatch completion`.
**Commit funcional final:** `f98ca47d fix: harden request workflow and stock imports`.
**Push funcional:** confirmado a `origin/main` (`66f74a2d..f98ca47d`).
**Produccion:** pendiente de despliegue y validacion en Forge; no se modifico produccion directamente.

### UTF y email de pedidos
- Se localizaron dos literales con mojibake `Pallet genÃ©rico` en `resources/js/app.js`; quedaron corregidos a `Pallet genérico`.
- Las respuestas JSON de las dos busquedas de variantes emiten Unicode sin escapar y mantienen correctamente tildes en descripcion y resumen.
- El email interno de nuevo pedido usa ahora `Pedido de {CLIENTE} - {CODIGO}`, tomando el codigo del cliente. Quedaron cubiertos `EDELVIVES` y `FRIESLAND` sin perder `SOL-xxxxxx`.

### Transporte y documentos
- Toda nueva salida manual o preparacion generada desde pedido queda por defecto como `Camión propio MAXIMO`; las salidas antiguas no se actualizan.
- Se anadio la migracion `2026_07_14_000001_default_goods_dispatches_to_own_truck.php`, aplicada localmente con `DONE`.
- La UI muestra icono, selector claro `Camión propio MAXIMO` / `Camión externo`, ayuda breve y `Actualizar transporte`; ya no aparece `Guardar camión`.
- El cambio a externo y el retorno a propio persisten y estan cubiertos por tests.
- Confirmar envio guarda la preparacion, descuenta stock y permanece en la pantalla de trabajo. El albaran queda como accion separada `Abrir albarán` en nueva pestaña.
- Los enlaces de albaran, preparacion y exportacion PDF revisados usan `target="_blank"` y `rel="noopener noreferrer"`.

### Dashboard
- Las tarjetas visibles del dashboard cuentan solo notificaciones no leidas y resaltan de forma discreta el modulo asociado.
- Mapeo activo: pedidos a `Pedidos`, accesos/usuarios a `Usuarios`, stock/importaciones a `Stock` y booking/calendario a `Booking`.
- El contador desaparece al marcar la notificacion como leida y nunca expone modulos que el rol no puede ver.

### Importacion EDELVIVES y trazabilidad
- Causa: el importador sustituia la foto actual con `delete from stock_pallets where client_id = ?`, por eso fallaba al encontrar asignaciones historicas con FK `RESTRICT`.
- Referencias localizadas: `merchandise_request_lines.stock_pallet_id` y `goods_dispatch_lines.stock_pallet_id` usan `nullOnDelete`; `goods_dispatch_line_allocations.stock_pallet_id` usa `restrictOnDelete` y no puede borrarse sin romper auditoria.
- `stock_pallets` ya dispone de `active`; la vista/constructor de stock actual filtra partidas activas y cantidades o unidades logisticas positivas.
- La importacion bloquea transaccionalmente las partidas del cliente, desactiva la foto anterior y pone a cero unidades, pallets completos, pallets de almacen y los diez picos. No ejecuta `DELETE`, `TRUNCATE` ni cascade.
- La nueva foto se crea activa. Una segunda importacion vuelve a retirar la foto previa, deja una sola partida activa por fila del Excel y conserva las fotos anteriores inactivas, evitando duplicar stock actual.
- La prueba critica conserva la allocation y su `stock_pallet_id` original, confirma que la partida historica queda inactiva/a cero, que no aparece al cliente y que dos importaciones EDELVIVES consecutivas mantienen el total actual del Excel.

### Validacion
- Linea base focalizada antes de editar: `167 passed` (1134 assertions).
- Suite completa final `php artisan test`: **563 passed** (2811 assertions).
- `npm run build`: OK (`vite build`, 55 modules transformed).
- `php artisan optimize:clear`: OK.
- `php artisan migrate`: migracion `2026_07_14_000001` aplicada, `DONE`.
- `git diff --check`: OK.
- Revision completa del diff realizada; se restauro expresamente el selector visual activo del menu lateral detectado durante la revision.

### Forge pendiente
1. Desplegar `origin/main` y confirmar `f98ca47d` o posterior.
2. Ejecutar `php artisan migrate --force`.
3. Ejecutar `php artisan optimize:clear`.
4. Ejecutar `php artisan queue:restart`.
5. Validar pedido cliente con tildes, asunto de email, transporte por defecto y apertura separada del albaran.
6. Previsualizar e importar EDELVIVES y confirmar que termina sin error FK, que los totales coinciden con el Excel y que el historico de salidas sigue accesible.

### Control de alcance
- No se tocaron `.env`, secretos, Google Calendar ni datos de produccion.
- No se uso `migrate:fresh`, no se borraron allocations ni historial y no se hizo force push.
- `.claude/` permanece sin trackear y fuera de ambos commits.

---

## 2026-07-15 - Ubicaciones, multipicos de entrada y documentos privados (11:58 +02:00)

**Equipo:** PC del trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit inicial:** `70ab9fe4 docs: record request and stock import hardening`.
**Commit funcional:** `66ed2126 fix: harden locations receipts and document access`.
**Push funcional:** confirmado a `origin/main` (`70ab9fe4..66ed2126`).
**Produccion:** pendiente de despliegue, dry-run y validacion en Forge; no se modifico produccion directamente.

### Diagnostico y reparacion de ubicaciones
- Una ubicacion es unica funcionalmente por `warehouse_id + codigo normalizado`; el cliente se hereda del almacen. `name`, `aisle`, `rack`, `level` y `position` son metadatos, no forman la clave.
- La causa era que el indice existente solo protege `warehouse_id + code` literal: `6` y `06` pueden coexistir. Ademas, la busqueda del almacen global EDELVIVES usaba `whereIn(..., null)`, que no encuentra SQL `NULL` y podia crear otra NAVE 38.
- Referencias FK reales localizadas: `stock_pallets.location_id`, `goods_receipt_lines.location_id` e `items.default_location_id`. Las salidas, allocations, pedidos y operaciones diarias no tienen FK `location_id`; sus ubicaciones historicas son texto y el comando las informa con cero reasignaciones.
- El normalizador convierte `6`, `06` y ` 6 ` en `6`, y `A`, `a` y ` A ` en `A`; conserva A-F, FONDO y SIN UBICACION.
- Formularios, modelo e importador EDELVIVES reutilizan el codigo normalizado y no crean duplicados nuevos. El importador tambien reutiliza ubicaciones antiguas como `06` sin crear `6` hasta que se ejecute la consolidacion controlada.
- Nuevo comando seguro:
  - `php artisan wms:locations:deduplicate --dry-run`
  - `php artisan wms:locations:deduplicate --client=EDELVIVES --warehouse="NAVE 38" --dry-run`
  - `php artisan wms:locations:deduplicate --client=EDELVIVES --warehouse="NAVE 38" --apply`
- Sin `--apply` siempre es dry-run. El informe muestra canonica, filas a fusionar y conteos de stock, entradas, articulos y salidas/asignaciones. El apply usa transaccion, reasigna todas las FK, comprueba que no quedan referencias, elimina solo entonces las duplicadas y registra el resultado.
- Criterio canonico: ubicacion activa, preferencia por codigo ya normalizado y despues menor ID.
- Diagnostico local real EDELVIVES/NAVE 38: **0 grupos duplicados**; el dry-run no modifico datos. La cantidad real de duplicados de produccion sigue desconocida hasta ejecutar el dry-run en Forge.

### Entradas con varios picos
- Nueva migracion aditiva `2026_07_15_000001_add_peaks_to_goods_receipt_lines_table.php`: columnas nullable `peak_1` a `peak_10`; `pico_units` se mantiene como suma para compatibilidad historica.
- Cada linea permite 0-10 picos manuales positivos, anadir/quitar inputs y ver el total calculado como `pallets * uds/pallet + suma de picos`.
- El backend valida negativos y valores no numericos, conserva cada pico por separado y aplica esos picos separados a `stock_pallets.peak_1..peak_10`.
- El detalle de entrada y el stock generado muestran todos los picos, no solo el primero.
- `Anadir linea` inserta la nueva linea al principio, enfoca el buscador de articulo y hace scroll suave; los indices siguen siendo unicos y todas las lineas se guardan.

### Documento de entrada y email
- El documento nuevo sigue guardandose en el disco privado `local`.
- El email de usuario cliente incluye una URL firmada temporal de descarga directa con caducidad de 15 dias; no exige sesion si la firma es valida.
- Un usuario autenticado de otro cliente recibe 403, una firma ausente/caducada recibe 403 y un documento inexistente devuelve 404.
- Los destinatarios email adicionales mantienen ademas el adjunto privado y reciben tambien el enlace firmado.
- La ruta autenticada de `ALBARANES` se conserva y sigue aislando documentos por cliente.

### Validacion
- Linea base focalizada antes de editar: `169 passed` (1002 assertions).
- Suite completa final `php artisan test`: **577 passed** (2870 assertions).
- `npm run build`: OK (`vite build`, 55 modulos transformados).
- `php artisan migrate`: migracion `2026_07_15_000001` aplicada localmente, `DONE`; `migrate:status` la muestra `Ran`.
- `php artisan optimize:clear`: OK en config, cache, compiled, events, routes y views.
- Dry-run local EDELVIVES/NAVE 38: sin duplicados y sin modificaciones.
- Pint y `git diff --check`: OK.
- La comprobacion visual automatizada llego al login local; no se alteraron usuarios ni credenciales para atravesarlo. Interaccion, orden, foco y persistencia quedaron cubiertos por tests y build.

### Forge pendiente
1. Desplegar `origin/main` y confirmar `66ed2126` o posterior.
2. Ejecutar `php artisan migrate --force`.
3. Ejecutar `php artisan optimize:clear` y `php artisan queue:restart`.
4. Ejecutar SOLO `php artisan wms:locations:deduplicate --client=EDELVIVES --warehouse="NAVE 38" --dry-run`.
5. Guardar el informe de grupos, IDs y referencias. No ejecutar `--apply` en produccion sin revisarlo y recibir confirmacion explicita.
6. Validar una entrada con 1 pallet + picos 1000 y 1000, confirmar que el stock conserva ambos picos y que una nueva linea aparece arriba.
7. Enviar un albaran de entrada y probar el enlace firmado en incognito, con cliente correcto y con otro cliente autenticado.

### Control de alcance
- No se tocaron `.env`, secretos, Google Calendar, facturacion ni datos de produccion.
- No se uso `migrate:fresh`, no se borro stock/historico, no se hizo force push y no se ejecuto `--apply` fuera de la base efimera de tests.
- `.claude/` permanece sin trackear y fuera del commit funcional.

---

## 2026-07-15 - Cierre definitivo de ubicaciones EDELVIVES / NAVE 38 (15:08 +02:00)

**Equipo:** PC del trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit inicial:** `799945bb docs: record location and receipt hardening`.
**Commit funcional:** `dcfaa45 fix: finalize nave 38 location deduplication`.
**Produccion:** no se modifico ni se ejecuto `--apply`; queda pendiente el dry-run en Forge y la confirmacion explicita del usuario.

### Diagnostico local real
- `php artisan wms:locations:audit --client=EDELVIVES --warehouse="NAVE 38"` se ejecuto en modo de solo lectura.
- Resultado local: **0 grupos duplicados**, **0 ubicaciones faltantes**, **2 extras conservados** (`FONDO` y `SIN UBICACION`) y **178 partidas de stock** mapeadas.
- `php artisan wms:locations:deduplicate --client=EDELVIVES --warehouse="NAVE 38" --dry-run` confirmo: `0 grupo(s) duplicado(s), 0 ubicacion(es) por crear`; no modifico ningun dato.
- La cantidad real de duplicados de produccion sigue siendo desconocida hasta ejecutar el comando audit/dry-run en Forge.
- Las FK reales auditadas son `stock_pallets.location_id`, `goods_receipt_lines.location_id` e `items.default_location_id`. Salidas, allocations, pedidos y operaciones diarias no tienen `location_id` en el esquema actual; conservan ubicaciones historicas en texto.

### Reparacion segura e idempotente
- Nuevo comando de auditoria `wms:locations:audit`, siempre de solo lectura, que muestra grupos por codigo normalizado, IDs, canonica propuesta, referencias, faltantes, extras y mapa de stock con item/SKU, lote, ubicacion, unidades, pallets, picos, pallets de almacen y estado activo.
- `wms:locations:deduplicate` mantiene dry-run por defecto. Con `--apply`, una unica transaccion bloquea ubicaciones, reasigna todas las FK, comprueba referencias, elimina solo duplicadas ya libres y crea las calles esperadas `0-45` y `A-F` que falten.
- Antes y despues del apply se compara una fotografia logica completa de cada partida. Si cambia item, lote, cantidades, desglose, estado, metadatos o ubicacion normalizada, se lanza error y se revierte toda la transaccion.
- La ubicacion canonica se elige priorizando activa, codigo ya normalizado y menor ID. Los extras se informan y se conservan; nunca se eliminan ni desactivan.
- Una segunda ejecucion `--apply` no hace cambios. Las pruebas cubren expresamente `5`, ` 5 ` y `05`, stock en las tres, lote, entradas, articulo por defecto, serie esperada, extras e idempotencia.

### Prevencion y selector
- `LocationCode` centraliza trim, mayusculas y normalizacion numerica; el modelo normaliza antes de guardar y el indice unico existente `warehouse_id + code` actua sobre el valor ya canonico.
- La validacion existente impide crear ` 06 ` cuando ya existe `6`; el importador EDELVIVES sigue cubierto para reutilizar `06` historico sin crear otra ubicacion.
- Entradas y autocomplete muestran solo ubicaciones activas/canonicas, eliminan variantes normalizadas y ordenan naturalmente `0, 1, 2, ... 45, A ... F`.
- La etiqueta de NAVE 38 queda como `NAVE 38 - Calle {codigo}`. El test de interfaz comprueba `1, 2, 10` y que `02` no aparece duplicado.

### Validacion
- `php artisan optimize:clear`: OK en config, cache, compiled, events, routes y views.
- Suite completa `php artisan test`: **580 passed** (2894 assertions).
- Test especifico del importador EDELVIVES con ubicacion `06`: **1 passed** (4 assertions).
- `npm run build`: OK (`vite build`, 55 modulos transformados).
- `php artisan migrate:status`: todas las migraciones locales `Ran`; este cambio no anade migraciones.
- Pint y `git diff --check`: OK.

### Forge pendiente
1. Desplegar `origin/main` y confirmar `dcfaa45` o posterior.
2. Ejecutar `php artisan migrate --force` (este cambio no incorpora migraciones nuevas).
3. Ejecutar `php artisan optimize:clear` y `php artisan queue:restart`.
4. Ejecutar primero `php artisan wms:locations:audit --client=EDELVIVES --warehouse="NAVE 38"` y guardar el informe completo.
5. Ejecutar despues `php artisan wms:locations:deduplicate --client=EDELVIVES --warehouse="NAVE 38" --dry-run` y revisar canonicas, IDs, referencias, faltantes y extras.
6. No ejecutar `--apply` en produccion hasta mostrar ambos resultados y recibir confirmacion explicita.
7. Tras la confirmacion, ejecutar una sola vez `php artisan wms:locations:deduplicate --client=EDELVIVES --warehouse="NAVE 38" --apply`; repetir audit y dry-run para confirmar cero cambios pendientes.
8. Validar manualmente una unica calle 5/6/7, orden natural, una entrada en NAVE 38 y la ubicacion resultante en stock.

### Control de alcance
- No se tocaron `.env`, secretos, migraciones, Google Calendar, facturacion ni datos de produccion.
- No se uso `migrate:fresh`, no se borro stock ni historico, no se hizo force push y no se ejecuto `--apply` fuera de la base efimera de tests.
- `.claude/` permanece sin trackear y fuera del commit.

---

## 2026-07-16 - Consolidacion segura de almacenes EDELVIVES / NAVE 38 (14:47 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** cerrar la reparacion de almacenes duplicados con diagnostico local, comando dry-run/apply, prevencion y validacion completa antes de commit/push.

### Diagnostico local
- Se ejecuto `php artisan wms:warehouses:deduplicate --client=EDELVIVES --warehouse-code=38 --dry-run`.
- Resultado local: solo **1 almacen 38 / NAVE 38**, ID `1`, cliente `GLOBAL`, activo.
- El almacen ID `1` tiene **54 ubicaciones**, **178 referencias de stock**, **0 entradas**, **0 salidas**, **0 articulos por defecto** y **0 bookings**.
- El dry-run confirmo **0 grupos de ubicacion duplicada** y **0 ubicaciones por crear**. No se modifico ningun dato local.

### Cambio aplicado
- Se anadio el comando `wms:warehouses:deduplicate` con opciones `--client`, `--warehouse-code`, `--dry-run` y `--apply`.
- `--dry-run` informa almacenes detectados, IDs, cliente, codigo, nombre, estado, ubicaciones, stock, entradas, salidas, articulos y bookings; tambien lista ubicaciones por almacen y referencias por ubicacion.
- `--apply` trabaja en una transaccion: elige almacen canonico, fusiona ubicaciones equivalentes por codigo normalizado, reasigna stock/entradas/articulos/bookings, crea las ubicaciones esperadas que falten y elimina duplicados solo cuando quedan vacios.
- Antes y despues del apply se verifica un snapshot de stock; si cambia el negocio de stock, la transaccion se revierte.
- Se centralizo la normalizacion de codigo de almacen (`038`, ` 38 ` -> `38`) y el modelo `Warehouse` normaliza antes de guardar.
- Los selectores de booking y ubicaciones usan almacenes activos/canonicos en orden natural.
- El importador EDELVIVES prioriza el almacen activo con mas ubicaciones para evitar crear o reutilizar una NAVE 38 vacia cuando ya existe la operativa.

### Validacion
- `php artisan test`: **583 passed** (2915 assertions).
- `npm run build`: OK (`vite build`, 55 modulos transformados).
- `git diff --check`: OK.
- Tests nuevos cubren dry-run sin cambios, apply con stock/entrada/articulo/booking, idempotencia y normalizacion de codigo de almacen.

### Cierre Git
- Commit previsto: `fix: deduplicate warehouses and canonicalize locations`.
- Push previsto: `origin/main`.
- `.claude/` permanece sin trackear y fuera del commit.

### Forge pendiente
- Queda pendiente desplegar/verificar en Forge cuando el usuario lo indique.
- En produccion, ejecutar primero `php artisan wms:warehouses:deduplicate --client=EDELVIVES --warehouse-code=38 --dry-run` y revisar el informe antes de cualquier `--apply`.
- No ejecutar `--apply` en produccion sin confirmacion explicita tras ver IDs, referencias y canonico propuesto.

---

## 2026-07-16 - Notificaciones propias, orden natural y reduccion de emails (15:10 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit base:** `676fce31 fix: deduplicate warehouses and canonicalize locations`.

### Diagnostico
- Usuario `ALMACEN` veia la bandeja de notificaciones, pero no tenia botones de gestion propia porque las rutas bulk seguian protegidas por `minimum.role:SUPERADMIN` y la vista solo pintaba acciones para superadmin.
- El listado de `Ubicaciones` seguia usando `orderBy('code')`, por eso NAVE 38 podia salir `0, 1, 10, 11...` en vez de orden natural.
- Booking seguia enviando emails internos al crear booking y emails al cliente en cambios de estado. Confirmar carga real enviaba notificacion interna y mail, incluyendo potencialmente al actor.

### Cambios funcionales
- `NotificationController` separa alcance global y alcance propio:
  - superadmin mantiene acciones globales sobre todos los usuarios;
  - almacen, administracion y cliente gestionan solo sus propias notificaciones.
- La vista de notificaciones muestra:
  - superadmin: `Marcar todas como leidas`, `Eliminar no leidas`, `Eliminar todas`;
  - resto de roles: `Marcar mis notificaciones como leidas`, `Eliminar mis no leidas`, `Eliminar mis notificaciones`.
- Se elimino el middleware superadmin de las rutas bulk y el controlador aplica el scope correcto por usuario.
- `LocationCode::applyNaturalOrder()` centraliza orden compatible SQLite/MySQL para codigos numericos antes que alfabeticos.
- Orden natural aplicado en listado de ubicaciones, selectores de articulos, selector de editar stock, servicios de auditoria/dry-run de ubicaciones y almacenes.
- Booking queda como notificacion de base de datos, sin emails por alta o cambios de estado.
- Confirmar carga real queda como notificacion interna de base de datos, sin email y excluyendo al usuario que confirma.
- Se conserva email en eventos importantes: nuevo pedido de cliente, albaran de salida, albaran de entrada/documento, password reset y flujos Brevo de acceso.

### Auditoria de eventos
| Evento | Notificacion interna | Email | Destinatarios |
| --- | --- | --- | --- |
| Nuevo pedido cliente | Si, database | Si | Internos activos almacen/administracion/superadmin con email valido; cliente solicitante recibe confirmacion |
| Cambio intermedio de pedido | Cliente database | No | Usuario cliente propietario |
| Generar/preparar salida | Cliente database de estado | No | Usuario cliente propietario |
| Confirmar carga real | Si, database | No | Internos activos salvo el actor que confirma |
| Cambiar transporte | No | No | Ninguno |
| Booking nuevo | Si, database | No | Internos activos |
| Booking cambio estado | Cliente database | No | Usuario cliente solicitante |
| Albaran de salida enviado | Cliente database | Si | Usuarios cliente validos y emails adicionales no duplicados |
| Albaran de entrada/documento | Cliente database | Si | Usuarios cliente validos y emails adicionales no duplicados |
| Password reset / acceso | Segun flujo existente | Si | Destinatario del flujo Brevo |

### Tests y validacion
- Tests focalizados de notificaciones, ubicaciones, booking, pedidos y salidas: **58 passed**.
- Suite completa `php artisan test`: **586 passed** (2979 assertions).
- `npm run build`: OK (`vite build`, 55 modulos transformados).
- `git diff --check`: OK.

### Control de alcance
- No se tocaron `.env`, secretos, migraciones, datos, Google Calendar, importacion de stock ni facturacion.
- No se uso `migrate:fresh`, no se borraron datos y no se hizo force push.
- `.claude/` permanece sin trackear y fuera del commit.

---

## 2026-07-16 - Albaranes cliente paginados y gestion interna documental (17:55 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit base:** `7f02bf62 fix: improve notification controls and location ordering`.
**Objetivo:** compactar `/mis-albaranes` con paginacion independiente y anadir `GESTION > ALBARANES` para consulta interna controlada por cliente.

### Diagnostico
- La pantalla cliente `ALBARANES` renderizaba todos los albaranes de entrada y salida filtrados en una sola carga visual.
- No existia una pantalla interna especifica para que `SUPERADMIN` y `ADMINISTRACION` buscaran albaranes de todos los clientes sin entrar en cada operacion.
- Las descargas seguras ya existian y se mantuvieron intactas: entrada autenticada, enlace firmado de email y PDF de salida existente.

### Decision de arquitectura
- Se mantuvo `ClientGoodsReceiptDocumentController` para el portal cliente y solo se le anadio paginacion de colecciones ya filtradas.
- Se creo `DeliveryNoteManagementController` como controlador interno especifico para no mezclar portal cliente con gestion administrativa.
- La pantalla interna usa listado unificado de entrada/salida con paginacion unica de 20 documentos, porque evita duplicar dos tablas y deja una busqueda documental mas directa.
- No se creo logica nueva de generacion, sustitucion, borrado ni renombrado documental.

### Rutas y permisos
- Nueva ruta: `GET /gestion/albaranes`.
- Nombre: `delivery-notes.management.index`.
- Middleware: `minimum.role:administracion`.
- Controlador: aborta tambien si el usuario no puede acceder como `ADMINISTRACION`.
- `SUPERADMIN` y `ADMINISTRACION` pueden acceder; `ALMACEN` y `CLIENTE` reciben 403.
- Menu: nueva opcion `GESTION > ALBARANES`.

### Paginacion y filtros
- `/mis-albaranes`:
  - entradas: 10 documentos por pagina con `entradas_page`;
  - salidas: 10 documentos por pagina con `salidas_page`;
  - cada paginador conserva la pagina de la otra seccion y filtros `month`, `supplier_id`, `search`;
  - contadores usan totales filtrados completos, no solo la pagina visible.
- Gestion interna:
  - exige `client_id` antes de cargar resultados;
  - no carga documentos de todos los clientes sin criterio;
  - filtros: cliente, tipo, fecha desde/hasta, proveedor para entradas, estado de salida y busqueda libre;
  - fechas de salida usan `completed_at`, despues `sent_at`, y por ultimo `created_at`;
  - busqueda incluye numeros, proveedor, cliente, pedido, destino y nombre calculado por `DocumentDisplayNamer`;
  - resultados limitados siempre al cliente seleccionado.

### Archivos modificados
- `app/Http/Controllers/ClientGoodsReceiptDocumentController.php`.
- `app/Http/Controllers/DeliveryNoteManagementController.php`.
- `resources/views/client/goods-receipts/index.blade.php`.
- `resources/views/delivery-notes/management/index.blade.php`.
- `routes/web.php`.
- `config/wms.php`.
- `tests/Feature/ClientGoodsReceiptDocumentTest.php`.
- `tests/Feature/DeliveryNoteManagementTest.php`.

### Validacion
- Tests focalizados de albaranes cliente + gestion interna: **50 passed** (182 assertions).
- `php artisan test`: **595 passed** (3050 assertions).
- `npm run build`: OK (`vite build`, 55 modulos transformados).
- Pint: OK.
- `git diff --check`: OK.

### Forge pendiente
- Queda pendiente desplegar `origin/main` cuando el usuario lo indique.
- Este cambio no incorpora migraciones nuevas.
- En Forge bastara con despliegue normal y, si se desea limpieza operativa:
  - `php artisan optimize:clear`
  - `php artisan queue:restart`

### Control de alcance
- No se tocaron `.env`, secretos, migraciones, datos, importadores, stock, facturacion, Google Calendar, notificaciones ni generacion/descarga real de documentos.
- No se uso `migrate:fresh`, no se borraron datos y no se hizo force push.
- `.claude/` permanece sin trackear y fuera del commit.

---

## 2026-07-16 - Trazabilidad, auditoria, actividad y alertas de stock (18:45 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit base:** `73e282f3 feat: add delivery note management and pagination`.
**Objetivo:** incorporar una primera version productiva de `GESTION > TRAZABILIDAD` sin modificar datos de produccion ni ejecutar backfills reales.

### Diagnostico inicial
- `main` y `origin/main` coincidían en `73e282f3`; el arbol rastreado estaba limpio y solo existia `.claude/launch.json` sin trackear.
- La base ya incluia paginacion de albaranes cliente y gestion documental interna para superadmin/administracion.
- Se mapearon los puntos reales que crean, descuentan, corrigen, importan, retiran, trasladan o consolidan stock, junto con login/logout, documentos, usuarios, clientes y maestros.
- No existia un libro mayor historico independiente; `stock_pallets` representaba principalmente el estado actual y no permitia reconstruir por si solo toda la historia.

### Arquitectura y tablas
- La migracion aditiva y reversible `2026_07_16_000001_create_traceability_foundation_tables.php` crea:
  - `audit_logs`: acciones empresariales con snapshots, correlacion y datos sensibles saneados;
  - `user_activity_sessions`: sesiones y tiempo activo estimado;
  - `user_section_metrics`: agregados diarios por usuario, fecha y seccion;
  - `inventory_movements`: libro mayor inmutable e idempotente;
  - `stock_alert_rules`: reglas por cliente y articulo;
  - `stock_alert_events`: historico de alertas y notificaciones;
  - `client_stock_alert_email_recipients`: destinatarios separados para avisos de stock.
- La migracion se ejecuto localmente de forma segura y figura `Ran` en el batch 12. En produccion sigue pendiente `php artisan migrate --force`.
- Los movimientos y logs de auditoria bloquean update/delete desde Eloquent. Las correcciones se expresan mediante nuevos registros; no se anadio hash encadenado en esta version para evitar una complejidad operativa no justificada.

### Integracion operativa y eventos
- Entradas: alta/edicion/cancelacion/eliminacion controlada, documentos, IA, confirmacion, descarga y movimientos de recepcion/reversion dentro de la transaccion de stock.
- Salidas: preparacion, allocations FIFO, cambios de transporte/estado, confirmacion, envio/completado, PDF/descarga y movimientos de salida/correccion idempotentes.
- Stock: ajuste manual, traslado, bloqueo/desbloqueo, importacion, retirada de foto anterior y consolidacion de almacen/ubicacion conservando cantidades.
- Maestros: articulos, usuarios, clientes y destinatarios operativos relevantes quedan auditados con valores anterior/nuevo sin contrasenas ni tokens.
- Cada operacion relacionada comparte `correlation_id`; las claves de idempotencia impiden duplicar movimientos por reintentos.
- La evaluacion de alertas se despacha despues del commit. El email usa un job unico/no solapable, omite eventos ya resueltos y mantiene los fallos fuera de la transaccion de stock.

### Permisos y pantallas
- `SUPERADMIN` y `ADMINISTRACION`: acceso completo a portada, actividad, auditoria, movimientos, lotes, analitica, alertas e informes.
- `ALMACEN`: consulta de portada, movimientos, lotes y alertas; sin gestion administrativa ni exportacion amplia.
- `CLIENTE`: 403 en todo el modulo, aunque manipule URLs o parametros.
- Se incorporaron 14 rutas bajo `/gestion/trazabilidad`, el acceso lateral `GESTION > TRAZABILIDAD` y el enlace `Alertas de stock` desde Stock.
- Los listados son paginados, los filtros se aplican en SQL y las exportaciones CSV exigen cliente/rango, limitan 10.000 filas y quedan auditadas.

### Actividad y privacidad
- Login abre una sesion de actividad y logout la cierra.
- El heartbeat autenticado opera cada 60 segundos solo con la pestana visible, limita cada intervalo a 90 segundos y considera inactividad tras 180 segundos.
- La interfaz usa expresamente `Tiempo activo estimado`; no se registran teclas, formularios, cuerpos de request, contrasenas, tokens, archivos ni polling tecnico.
- Las visitas se agregan por nombre de ruta normalizado, usuario, fecha y seccion para controlar volumen. La IP se anonimiza y el user-agent se resume.

### Libro mayor y lotes
- `InventoryMovementService` conserva unidades, pallets completos, pallets de almacen, picos, ubicacion/almacen anterior y posterior, origen, usuario, snapshots e instante efectivo.
- Tipos cubiertos: saldo inicial, recepcion, salida, ajuste, importacion, retirada de importacion, traslado, consolidacion, bloqueo/desbloqueo, cancelacion, reversion y correccion.
- La trazabilidad de lote exige cliente+lote y nunca mezcla el mismo lote entre clientes o articulos.
- La vista reconstruye proveedor/entrada/documento, stock y ubicaciones, allocations/salida/destino/documento, cronologia y los indicadores `Completa`, `Parcial` o `Inconsistente`, incluyendo un paso atras y un paso adelante.
- No se fabrica historia: relaciones ausentes, salidas sin allocation, documentos ausentes o saldos incompatibles se muestran como lagunas.

### Analitica, prevision y alertas
- La analitica por cliente incluye entradas/salidas, rotacion, inmovilizado, envejecimiento, ABC, lotes, stock bloqueado/obsoleto y ajustes manuales.
- La prevision es determinista y explicable: medias 7/30/90 con pesos 50/30/20, demanda pendiente, lead time, stock de seguridad, cobertura, variabilidad y confianza con motivo cuando faltan datos.
- Las reglas admiten umbrales de unidades, pallets, cobertura y agotamiento, ademas de cooldown, severidad, seguridad y criterios sobre stock bloqueado/obsoleto.
- Los eventos se reconocen, silencian, resuelven o reabren; desactivar una regla resuelve su evento activo. No se repite correo sin cambio o empeoramiento relevante.
- `Avisos de stock` usa una lista normalizada propia en la ficha del cliente y nunca reutiliza implicitamente emails de albaranes de entrada/salida.

### Comandos y scheduler
- `php artisan wms:traceability:backfill --dry-run|--apply [--client=...]`.
- `php artisan wms:stock-alerts:evaluate --dry-run|--apply [--client=...] [--item=...]`.
- Sin `--apply`, ambos comandos son de solo lectura.
- El scheduler ejecuta la reconciliacion de alertas diariamente a las 06:00; Forge debe mantener `schedule:run` cada minuto y un worker de cola activo.
- El backfill apply es transaccional, idempotente, solo crea trazabilidad y no modifica stock, entradas, salidas ni allocations.

### Dry-runs locales
- Antes: `inventory_movements=0`, `stock_alert_events=0`, `audit_logs=0`.
- Backfill: 1 entrada reconstruible con certeza, 0 salidas reconstruibles, 0 entradas parciales, 0 salidas imposibles y 177 saldos iniciales candidatos; 0 movimientos creados en dry-run.
- Alertas: 0 reglas evaluadas, 0 eventos disparados y 0 cambios.
- Despues: `inventory_movements=0`, `stock_alert_events=0`, `audit_logs=0`; se confirmo que ambos dry-runs no escribieron datos.

### Validacion final
- Tests focalizados de trazabilidad: **19 passed** (93 assertions).
- Tests focalizados de pedidos, salidas, perfil e importacion: **134 passed** (1023 assertions).
- `php artisan test --compact`: **615 passed** (3156 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- Pint: OK; solo ordeno imports.
- `git diff --check`: OK.
- `php artisan migrate:status`: todas las migraciones `Ran`, incluida la nueva.
- `php artisan view:cache`, rutas y `php artisan optimize:clear`: OK.
- La compilacion de Blade y las pruebas cubren roles, vistas y responsive. La inspeccion visual autenticada local no se completo porque el navegador quedo en login y no se crearon credenciales temporales.

### Limitaciones historicas reales
- Los hechos posteriores al despliegue quedan registrados con exactitud desde los servicios de dominio.
- La historia anterior depende de relaciones conservadas. El backfill solo reconstruye hechos verificables; el stock actual sin origen demostrable se etiqueta `Saldo inicial al activar trazabilidad` con confianza `opening_balance`.
- No ejecutar `--apply` en produccion hasta revisar y aprobar expresamente el informe dry-run por cliente.
- La primera version no incorpora firma criptografica encadenada ni borrado automatico; auditoria, movimientos y alertas se conservan indefinidamente.

### Forge pendiente, no ejecutado en esta sesion
1. Deploy Now o confirmar autodeploy del commit publicado.
2. Ejecutar `php artisan migrate --force`.
3. Ejecutar `php artisan optimize:clear`.
4. Ejecutar `php artisan queue:restart`.
5. Confirmar scheduler `php artisan schedule:run` cada minuto y worker de cola activo.
6. Ejecutar `php artisan wms:traceability:backfill --dry-run` y guardar/revisar el informe.
7. No ejecutar backfill `--apply` sin aprobacion expresa del propietario.
8. Ejecutar `php artisan wms:stock-alerts:evaluate --dry-run`.
9. Validar manualmente un lote real alimentario, sus documentos y un destinatario controlado de alertas.

### Control de alcance y cierre Git
- No se tocaron `.env`, secretos, datos de produccion, Google Calendar ni configuracion real de Forge.
- No se uso `migrate:fresh`, `db:wipe`, truncado, borrado masivo, force push ni backfill apply en produccion.
- `.claude/launch.json` permanece sin trackear y fuera del commit.
- Commit previsto: `feat: add audit inventory traceability and stock alerts`.
- Push previsto: un unico push normal a `origin/main` tras revisar el diff staged.

---

## 2026-07-17 - Ubicaciones de recogida y albaran de salida compacto (12:10 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Commit base:** `c1062841 feat: add audit inventory traceability and stock alerts`.
**Objetivo:** mostrar al operario la ubicacion fisica real de cada allocation y compactar el PDF de salida sin modificar stock, cantidades, estados ni permisos.

### Diagnostico
- `HEAD` y `origin/main` coincidian en `c1062841`; el arbol rastreado estaba limpio y solo `.claude/` permanecia sin trackear.
- La preparacion solo mostraba `location_text` dentro del selector o desde la partida principal, por lo que una carga repartida entre varias allocations no dejaba claras todas las ubicaciones.
- La hoja interna de preparacion no cargaba allocations y su columna generica `Ubicacion` no diferenciaba recogida de destino.
- El albaran de salida tenia nueve columnas, mezclaba SKU y descripcion y generaba filas muy altas con descripciones largas.

### Ubicacion de recogida
- `StockPallet` resuelve primero `location.displayLabel()` y conserva `location_text` como fallback historico.
- Cada allocation expone su ubicacion y cantidad compacta; una linea puede mostrar varias recogidas, por ejemplo `NAVE 38 - Calle 15 · 3 pallets` y `NAVE 38 - Calle 18 · 3 pallets`.
- La pantalla interna principal muestra `Ubicacion de recogida` en el resumen y un bloque destacado `Recoger en` por partida. El selector identifica de forma explicita lote, ubicacion, stock, picos y unidades.
- El resumen se actualiza en el navegador al cambiar partida, pallets o pico y se adapta a movil.
- Los estados seguros son `Pendiente de asignar ubicacion` y `Sin ubicacion registrada`; no se inventan datos.
- La ubicacion destino, por ejemplo `DIGITAL`, permanece separada e intacta.
- Detalle interno, carga real y PDF de preparacion muestran todas las ubicaciones. La vista cliente no renderiza ubicaciones internas.

### Albaran de salida
- La tabla queda limitada a `SKU`, `DESCRIPCION`, `LOTE`, `PALLETS ENTREGADOS`, `CANTIDAD` y `UBICACION DESTINO`.
- Se eliminaron las columnas de ruido `Origen`, `Tipo`, `Solicitado`, `Detalle` y `Observaciones`.
- SKU y descripcion se presentan por separado; se elimina un SKU repetido al inicio y la descripcion se normaliza/limita a 90 caracteres para un maximo visual de dos lineas.
- `CANTIDAD` muestra unidades reales entregadas. La columna de pallets expresa tambien picos parciales de forma compacta.
- Se conservan cabecera, logo, direccion, estado, firma y totales; se anadio total de unidades y el total de picos cuenta tambien picos parciales cargados en lineas de pallets.
- No se modificaron permisos, rutas firmadas ni auditoria de generacion/descarga.

### Archivos principales
- Controladores de pedidos, salidas y descarga cliente para eager loading de ubicaciones/allocations.
- Modelos `StockPallet`, `GoodsDispatchLineAllocation` y `GoodsDispatchLine` para etiquetas y resumenes de presentacion.
- Vistas internas de pedido/salida, hoja de preparacion y `dispatches/delivery-note-pdf.blade.php`.
- `resources/js/app.js`, `resources/css/app.css` y el parcial interno de ubicaciones.
- `tests/Feature/GoodsDispatchManagementTest.php`.

### Pruebas y validacion
- Tests focalizados de salidas y documentos cliente: **100 passed** (549 assertions).
- Suite completa final `php artisan test --compact`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- Pint sobre todos los PHP modificados: OK.
- Pint global detecta deuda de estilo previa en 23 archivos fuera de alcance; no se modificaron.
- `git diff --check`: OK.
- `php artisan migrate:status`: todas las migraciones locales `Ran`; este hito no incorpora migraciones.
- `php artisan optimize:clear`: OK.
- Validacion visual DomPDF: PDF A4 temporal generado con 18 lineas, descripcion larga y pico; las 18 filas, totales y firma caben en una pagina, sin solapes ni filas gigantes. Los modelos fueron solo en memoria y los temporales se eliminaron.

### Control de alcance
- No se modificaron `.env`, secretos, datos, migraciones, importadores, facturacion, Google Calendar ni logica de stock/trazabilidad.
- No se ejecuto deploy, `migrate:fresh`, backfill apply, borrado de datos ni force push.
- `.claude/` permanece sin trackear y fuera del commit.

### Forge pendiente
1. Deploy Now o confirmar autodeploy del commit publicado.
2. Este hito no requiere migracion nueva. Ejecutar `php artisan migrate --force` solo si sigue pendiente la migracion de trazabilidad del hito `c1062841`.
3. Ejecutar `php artisan optimize:clear`.
4. Ejecutar `php artisan queue:restart`, recomendado porque los albaranes adjuntos tambien se generan desde el worker de notificaciones.
5. Validar un pedido real EDELVIVES: ubicacion de recogida visible y distinta del destino, varias allocations si aplica, albaran compacto y descarga autorizada.
6. Confirmar que el portal cliente no muestra ubicaciones fisicas internas y mantiene aislamiento entre clientes.

### Cierre Git previsto
- Commit: `fix: show picking locations and compact dispatch delivery notes`.
- Push normal a `origin/main`, sin force push y excluyendo `.claude/`.

---

## 2026-07-19 - FASE 2A visual completada (21:15 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** crear una base visual global mas clara, compacta, corporativa y operativa para el WMS, sin tocar logica de negocio.

### Cambios realizados
- Se anadio una capa CSS final en `resources/css/app.css` para transformar el sistema hacia un baseline claro y operativo.
- Se redujo la sensacion dark/glass/decorativa.
- Se compactaron superficies, tarjetas, botones, formularios, tablas, badges, alertas, breadcrumbs, topbar, footer y elementos comunes.
- Se normalizo visualmente la paginacion.
- Se reemplazo la plantilla `vendor/pagination/tailwind.blade.php` por clases semanticas `wms-pagination-*`.
- Se mantuvieron URLs, logica, accesibilidad basica, textos y comportamiento de paginacion.
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, formularios de negocio ni `resources/js/app.js`.
- No se cambiaron `data-*`, actions, metodos HTTP ni nombres de campos.
- No se toco `.env` ni `.claude/`.

### Validaciones ya realizadas
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK.
- `git diff --check`: OK.
- No se hizo commit ni push durante la implementacion inicial.

### Notas
- La revision autenticada queda pendiente para dashboard, stock, entradas, salidas, pedidos cliente, notificaciones y listados paginados.
- La FASE 3 recomendada sigue siendo la pantalla piloto de Listado de pedidos: `resources/views/merchandise-requests/index.blade.php`.
- Nueva regla operativa del propietario: guardar resumen y hacer push por defecto salvo instruccion contraria expresa.

---

## 2026-07-19 - FASE 2B visual completada (21:38 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** pulir la base visual de FASE 2A con navegacion global mas decidida, paginacion mas compacta y mejor alineacion de botones, filtros y KPI, sin tocar logica de negocio.

### Cambios realizados
- Se redibujo visualmente el drawer global como navegacion principal mas plana, corporativa y compacta, con rail azul tinta, secciones mas claras, separadores, marcador de seccion activa y estado activo de modulo mas fuerte.
- Se reorganizo el HTML visual del menu y la topbar en `resources/views/layouts/dashboard.blade.php` usando envoltorios y clases compartidas, manteniendo los mismos enlaces, rutas, textos, formularios, condiciones de visibilidad y `data-drawer-*`.
- Se compacto la topbar y se agruparon mejor acciones, identidad, rol y salida para que la cabecera sea secundaria frente al contenido.
- Se recalculo la paginacion con una barra de controles mas compacta, botones de altura/ancho consistentes y menos separacion entre paginas.
- En stock se evita la duplicidad visual del resumen de paginacion superior conservando el contador propio de la tabla.
- Se ajustaron alturas, gaps y paddings globales de botones, inputs, selects, filtros, acciones de formularios, operaciones diarias, KPI y cards compactas.
- Se reforzaron estados hover, active, disabled y focus visible sin depender solo del color.

### Archivos tocados
- `resources/css/app.css`.
- `resources/views/layouts/dashboard.blade.php`.
- `SESSION_LOG.md`.

### Validaciones
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- Revision navegada local sin sesion: `/login` carga correctamente y las rutas protegidas revisadas redirigen a login. No se creo ni modifico ningun usuario o dato para autenticar.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests ni `resources/js/app.js`.
- No se cambiaron nombres de campos, metodos HTTP, actions de formularios ni estructuras `data-*`.
- No se tocaron `.env`, datos, `public/build` ni `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Cierre Git previsto
- Commit: `style: polish visual navigation and layout details`.
- Push normal a `origin/main`, excluyendo `.claude/`.
- `.claude/` permanece sin trackear y fuera del commit.

---

## 2026-07-19 - HOTFIX FASE 2B.1 drawer/sidebar (21:44 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** corregir la regresion visual del menu/drawer introducida en FASE 2B, sin tocar logica de negocio ni pantallas de modulo.

### Causa detectada
- FASE 2B aumento el ancho visual de `.app-drawer-panel` a `22rem`, mientras el contenedor fijo `.app-drawer` seguia midiendo `19rem`.
- Al cerrar el drawer, el `transform` se calculaba sobre el ancho menor del contenedor y el panel interno podia sobresalir como franja flotante/cortada sobre el lateral izquierdo.

### Cambios realizados
- Se estabilizo el contrato CSS del drawer cerrado/abierto en `resources/css/app.css`.
- El contenedor `.app-drawer` usa ahora el mismo ancho maximo que el panel visual y queda completamente fuera de pantalla cuando esta cerrado.
- El estado cerrado queda sin puntero, sin visibilidad y sin sombra de panel; el estado abierto vuelve a ser overlay predecible.
- Se reforzo el comportamiento responsive movil sin cambiar `data-drawer-*` ni `resources/js/app.js`.

### Archivos modificados
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Validaciones
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- Revision visual sintetica con CSS real en Chrome: drawer cerrado queda fuera de pantalla, sin scroll horizontal; drawer abierto entra visible y sin scroll horizontal. No se autentico ni se tocaron datos.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, formularios de negocio, vistas de modulos ni `resources/js/app.js`.
- No se cambiaron actions, metodos HTTP, nombres de campos ni `data-*`.
- No se tocaron `.env`, datos, `public/build` ni `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Cierre Git previsto
- Commit: `fix: correct sidebar drawer layout regression`.
- Push normal a `origin/main`, excluyendo `.claude/`.
- `.claude/` permanece sin trackear y fuera del commit.

---

## 2026-07-19 - FASE 3 piloto visual - Listado de pedidos (21:55 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente `/solicitudes-mercancia` como pantalla piloto para futuros listados operativos del WMS, sin tocar logica de negocio.

### Cambios realizados
- Se reorganizo `resources/views/merchandise-requests/index.blade.php` en un patron de listado WMS con cabecera operativa, metricas visibles, filtros compactos, resumen de filtros, tabla mas escaneable, acciones por fila y estado vacio.
- Se mantuvieron las mismas rutas, formularios GET, nombres de campos, acciones, condiciones por rol, paginacion y enlaces existentes.
- Se anadio una capa CSS final en `resources/css/app.css` bajo el bloque `Fase 3 piloto: patron operativo para listados WMS`.
- Se definieron clases semanticas `wms-list-*`, `wms-filter-*`, `wms-table-*`, `wms-request-*` y `wms-status-chip-*` para poder reutilizar el patron en futuros listados.
- Se conservo la accesibilidad basica con labels, tabla con `aria-label`, resumen de filtros y totales visibles.

### Archivos modificados
- `resources/views/merchandise-requests/index.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Validaciones
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron `data-*`, metodos HTTP, actions de formularios ni nombres de campos.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Cierre Git previsto
- Commit: `style: redesign merchandise request list pilot`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - HOTFIX FASE 3.1 - Nuevo pedido (22:04 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** corregir margenes, paddings y composicion visual de la pantalla Nuevo pedido, sin tocar logica de negocio.

### Causa detectada
- La FASE 3 piloto introdujo estilos globales sobre clases reutilizadas, especialmente `.wms-empty-state`, y la vista de creacion no tenia un wrapper propio para aislar su ritmo visual.
- El bloque `client-pending-orders` conservaba tratamiento anterior de tabla/panel y podia quedar visualmente pegado al borde frente al nuevo patron operativo.

### Cambios realizados
- Se anadio el wrapper `.wms-request-create` en `resources/views/merchandise-requests/create.blade.php`.
- Se marcaron los paneles principales de Nuevo pedido con clases especificas de formulario y pedidos pendientes.
- Se anadio CSS scoped en `resources/css/app.css` para restaurar padding interno, separacion entre secciones, cabecera de pedidos pendientes, tabla contenida y comportamiento responsive.
- Se mantuvieron los mismos formularios, rutas, metodos HTTP, nombres de campos, `data-*`, textos funcionales y flujo de creacion.

### Rutas revisadas
- `/solicitudes-mercancia/create`.
- `/solicitudes-mercancia/create?client_id=2`.
- `/solicitudes-mercancia` como referencia para no romper el listado piloto.

### Archivos modificados
- `resources/views/merchandise-requests/create.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Validaciones
- `php artisan test --filter=MerchandiseRequestManagementTest`: **42 passed** (250 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron `data-*`, metodos HTTP, actions de formularios ni nombres de campos.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Cierre Git previsto
- Commit: `fix: restore spacing in merchandise request creation`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 3.2 - Detalle de pedido (22:13 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente el detalle/gestion de pedido como patron compacto para futuros detalles WMS, manteniendo intacta la logica de negocio.

### Cambios realizados
- Se reorganizo visualmente la cabecera de `resources/views/merchandise-requests/show.blade.php` con clases `wms-detail-*`, manteniendo el mismo pedido, estado, cliente, solicitante, fecha y totales.
- Se anadio contexto operativo de salida asociada o pendiente sin inventar datos ni cambiar relaciones.
- La accion principal interna queda visible en la cabecera: empezar carga, continuar carga, ver carga o marcar como completado segun el mismo estado existente.
- Las acciones secundarias quedan agrupadas y visibles en el bloque de mas acciones, conservando formularios, documentos, PDF de preparacion, albaran y vuelta al listado.
- Se anadio una capa CSS final en `resources/css/app.css` para compactar cabecera, seguimiento, tabla de lineas, carga adicional, acciones y responsive basico.
- Las lineas del pedido quedan mas densas y legibles, con cantidades alineadas y estados de carga sobrios.

### Archivos modificados
- `resources/views/merchandise-requests/show.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron `data-*`, nombres de rutas, metodos HTTP, actions de formularios, nombres de campos ni query strings.
- No se modifico la generacion de salidas, PDFs, albaranes, seguimiento, estados ni permisos por rol.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=MerchandiseRequestManagementTest`: **42 passed** (250 assertions).
- `php artisan test --filter=GoodsDispatchManagementTest`: **57 passed** (421 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local no se completo; se valido por codigo, tests focalizados, suite completa y build.
- Esta pantalla queda como patron inicial para futuros detalles de entrada, salida, albaran, cliente y administracion.

### Cierre Git previsto
- Commit: `style: redesign merchandise request detail`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 4A - Listado de salidas (22:34 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente el listado `/salidas` con un patron WMS/ERP mas compacto, claro y operativo, sin tocar gestion/carga ni logica de stock.

### Cambios realizados
- Se reorganizo `resources/views/dispatches/index.blade.php` con cabecera WMS, metricas visibles, accion principal compacta y accesos operativos para pedidos pendientes y salida manual.
- Se conservo el listado de pedidos pendientes destacados, ahora con filas compactas, estado visible, pallets y accion alineada.
- Se redisenaron las salidas recientes como tabla operativa con columnas de salida, cliente, origen, pedido asociado, estado, pallets y accion.
- Se mejoraron los estados vacios para pedidos pendientes y salidas, manteniendo los mismos enlaces existentes.
- Se anadio CSS scoped en `resources/css/app.css` para la pagina `dispatch-list-page`, reutilizando `wms-list-*`, `wms-table-*`, `wms-row-actions`, `wms-status-chip` y clases historicas esperadas por tests.
- Se anadio la variante visual `wms-status-chip--draft` para el estado interno de borrador de salida.

### Archivos modificados
- `resources/views/dispatches/index.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron `data-*`, nombres de rutas, metodos HTTP, actions de formularios, nombres de campos ni query strings.
- No se redisenaron ni modificaron la preparacion/carga, confirmacion de carga, asignaciones, descuento de stock, estados funcionales, PDFs ni albaranes.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=GoodsDispatchManagementTest`: **57 passed** (421 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La vista `/salidas` no tenia formulario GET ni filtros funcionales propios; por tanto no se inventaron filtros ni se cambio ningun query string.
- La revision visual autenticada local queda pendiente; se validara por codigo, tests, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.

### Cierre Git previsto
- Commit: `style: redesign dispatch list`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 4B - Listado de entradas (22:50 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente el listado `/entradas` con un patron WMS/ERP mas compacto, claro y operativo, sin tocar detalle, confirmacion, documentos, IA ni logica de stock.

### Cambios realizados
- Se reorganizo `resources/views/goods-receipts/index.blade.php` con cabecera WMS, metricas visibles, accion principal compacta y acceso a proveedores.
- Se mantuvieron los filtros GET existentes de cliente, proveedor, estado, busqueda, fecha desde y fecha hasta, con los mismos nombres de campos, action y query strings.
- Se anadio resumen de filtros aplicados y estado sin filtros para alinear entradas con el patron de pedidos.
- Se rediseno la tabla de entradas como listado operativo con entrada, cliente, proveedor, recepcion, creador, lineas, partidas, documento, estado y acciones.
- Se conservaron las acciones Ver, Editar y Borrar con las mismas rutas, permisos, formularios, `@csrf`, `@method('DELETE')` y mensaje de confirmacion fuerte.
- Se mantuvo y compacto la experiencia movil mediante tarjetas responsive sin cambiar comportamiento.
- Se anadio CSS scoped en `resources/css/app.css` para la pagina `goods-receipts-list-page`, reutilizando `wms-list-*`, `wms-filter-*`, `wms-table-*`, `wms-row-actions`, `wms-status-chip` y clases historicas del modulo.
- Se anadieron variantes visuales WMS para `pending_review` y `confirmed`.

### Archivos modificados
- `resources/views/goods-receipts/index.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron `data-*`, nombres de rutas, metodos HTTP, actions de formularios, nombres de campos ni query strings.
- No se modificaron detalle de entrada, confirmacion, subida de documentos, interpretacion IA, lineas de entrada, stock resultante, PDFs ni albaranes.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=GoodsReceiptManagementTest`: **98 passed** (524 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local queda pendiente; se validara por codigo, tests, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.

### Cierre Git previsto
- Commit: `style: redesign goods receipt list`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 4C - Listados de albaranes/documentos (23:08 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente los listados de albaranes/documentos con un patron WMS/ERP compacto y operativo, sin tocar generacion PDF, descargas, documentos ni permisos.

### Vistas detectadas y modificadas
- `resources/views/delivery-notes/management/index.blade.php`: gestion interna `/gestion/albaranes`.
- `resources/views/client/goods-receipts/index.blade.php`: consulta cliente `/mis-albaranes`.

### Cambios realizados
- Se reorganizo la gestion interna con cabecera WMS, metricas visibles, filtros compactos, resumen de filtros y tabla documental densa.
- Se mantuvo la consulta controlada por cliente en gestion interna para evitar carga masiva sin criterio.
- Se conservaron filtros GET existentes de cliente, tipo, proveedor, estado de salida, fechas y busqueda, con los mismos nombres de campos, action, validaciones y query strings.
- Se reorganizo la vista cliente con cabecera WMS, metricas de entradas/salidas/total, filtros compactos y paneles documentales mas densos.
- Se conservaron los paginadores independientes de cliente para `entradas_page` y `salidas_page`.
- Se mantuvieron enlaces documentales existentes: descargar entrada, descargar salida, abrir origen y pedido asociado.
- Se anadio CSS scoped en `resources/css/app.css` para `delivery-notes-list-page`, reutilizando `wms-list-*`, `wms-filter-*`, `wms-table-*`, `wms-row-actions` y `wms-status-chip`.
- Se anadieron variantes visuales `wms-status-chip--entry` y `wms-status-chip--dispatch`.

### Archivos modificados
- `resources/views/delivery-notes/management/index.blade.php`.
- `resources/views/client/goods-receipts/index.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron `data-*`, nombres de rutas, metodos HTTP, actions de formularios, nombres de campos ni query strings.
- No se modificaron generacion PDF, plantillas PDF, almacenamiento documental, nombres de ficheros, rutas de descarga, logica de impresion ni autorizacion documental.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=DeliveryNoteManagementTest`: **7 passed** (54 assertions).
- `php artisan test --filter=ClientGoodsReceiptDocumentTest`: **43 passed** (128 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local queda pendiente; se validara por codigo, tests, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.

### Cierre Git previsto
- Commit: `style: redesign delivery note lists`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 4D - Booking / calendario de bookings (23:25 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente la pantalla principal `/bookings` con un patron WMS/ERP mas compacto, claro y operativo, sin tocar logica de booking, emails, notificaciones ni Google Calendar.

### Cambios realizados
- Se reorganizo `resources/views/bookings/index.blade.php` con cabecera WMS, metricas visibles, acciones compactas y acceso a agenda/creacion.
- Se conservaron los filtros GET existentes de cliente, fecha desde, fecha hasta, estado, tipo y busqueda, con los mismos nombres de campos, action y query strings.
- Se anadio resumen de filtros activos para mejorar orientacion sin crear consultas nuevas.
- Se rediseno el listado como tabla operativa densa con codigo, cliente, tipo, fecha/hora, pallets, transporte, matricula, estado, Google Calendar y acciones.
- Se mantuvieron enlaces Ver, Editar, Ver agenda y Nueva solicitud con las mismas rutas y condiciones de visibilidad.
- Se normalizaron visualmente estados de booking, tipos de booking y estados de sincronizacion Google Calendar con chips sobrios.
- Se compacto el estado vacio y se mantuvo la accion de nueva solicitud cuando el usuario puede crear.
- Se anadio CSS scoped en `resources/css/app.css` para `wms-booking-page`, reutilizando `wms-list-*`, `wms-filter-*`, `wms-table-*`, `wms-row-actions` y `wms-status-chip`.

### Archivos modificados
- `resources/views/bookings/index.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron `data-*`, nombres de rutas, metodos HTTP, actions de formularios, nombres de campos ni query strings.
- No se modificaron creacion, edicion, cancelacion, cambios de estado, emails, notificaciones, jobs/colas, OAuth ni integracion de Google Calendar.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=BookingManagementTest`: **28 passed** (94 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local queda pendiente; se valido por codigo, tests, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.
- Se confirma que no se toco logica de booking, emails, notificaciones ni Google Calendar.

### Cierre Git previsto
- Commit: `style: redesign booking calendar`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 4E - Dashboard / Panel de control (23:45 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente el dashboard `/dashboard` como panel WMS/ERP mas compacto, claro y orientado a trabajo diario, sin tocar logica de dashboard, notificaciones, permisos ni Google Calendar.

### Cambios realizados
- Se reorganizo `resources/views/dashboard/index.blade.php` con cabecera WMS, metricas ya disponibles de rol, pendientes y agenda semanal.
- Se mantuvieron todos los modulos procedentes de `navigationSections`, sus rutas, iconos, estados, contadores de pendientes y condiciones de visibilidad por rol.
- Se dio mas jerarquia a Operaciones mediante una seccion mas amplia, manteniendo Stock, Gestion y Sistema visibles segun permisos.
- Se hicieron los accesos de modulos mas compactos y escaneables, con resumen, icono discreto, estado y pendiente cerca del enlace correspondiente.
- Se conservo el contador visual de pendientes por modulo y se anadio resumen visual por seccion sin crear consultas nuevas.
- Se compacto la agenda operativa del dashboard en columna semanal, manteniendo `Agenda de BOOKING`, `Agenda operativa WMS`, `Abrir agenda`, bookings, eventos Google de solo lectura y estados.
- Se conservaron enlaces a stock, booking, pedidos, albaranes, entradas, salidas, administracion, notificaciones en topbar/drawer y agenda.
- Se anadio CSS scoped en `resources/css/app.css` para `wms-dashboard-page`, `wms-dashboard-section`, `wms-dashboard-module`, `wms-dashboard-agenda`, `wms-dashboard-day` y `wms-dashboard-booking`.

### Archivos modificados
- `resources/views/dashboard/index.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, permisos, validaciones, tests, `resources/js/app.js`, `config/wms.php`, `.env`, datos ni `public/build`.
- No se cambiaron consultas, contadores, nombres de rutas, condiciones de visibilidad por rol, acciones ni enlaces funcionales.
- No se modificaron notificaciones funcionalmente, jobs/colas, emails, OAuth ni integracion de Google Calendar.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=RoleAccessTest`: **30 passed** (109 assertions).
- `php artisan test --filter=BookingManagementTest`: **28 passed** (94 assertions).
- `php artisan test --filter=NotificationCenterTest`: **27 passed** (173 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local queda pendiente; se valido por codigo, tests, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.
- Se confirma que no se toco logica de dashboard, notificaciones, permisos ni Google Calendar.

### Cierre Git previsto
- Commit: `style: redesign dashboard overview`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 4F - Notificaciones / Centro de avisos (23:58 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente `/notificaciones` como centro de avisos operativo, compacto y claro, sin tocar logica de notificaciones, emails, jobs, eventos ni permisos.

### Cambios realizados
- Se reorganizo `resources/views/notifications/index.blade.php` con cabecera WMS, metricas visibles y toolbar de gestion separada.
- Se conservaron todas las acciones masivas existentes: marcar como leidas, eliminar no leidas y eliminar todas, con las mismas rutas, metodos, `@csrf`, `@method('DELETE')`, confirmaciones y textos por rol.
- Se mantuvo la diferenciacion superadmin frente a acciones propias de cliente, almacen y administracion.
- Se rediseno la bandeja como listado compacto tipo inbox con cabecera de columnas, resumen de paginacion y contador de no leidas visibles en la pagina.
- Se actualizo `resources/views/notifications/_card.blade.php` para reforzar estado leida/no leida, tipo, fecha y acciones por fila, manteniendo enlace Abrir y formulario PATCH de Marcar leida.
- Se mantuvieron las clases historicas cubiertas por tests: `notification-inbox`, `notification-card`, `notification-card-title`, `notification-card-body`, `notification-card-actions`, `notification-kind-badge--*`.
- Se anadio CSS scoped en `resources/css/app.css` para `wms-notification-page`, toolbar, bandeja, filas, estados, badges y responsive.

### Archivos modificados
- `resources/views/notifications/index.blade.php`.
- `resources/views/notifications/_card.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, policies/permisos, jobs, events/listeners, mails, notification classes, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron nombres de campos, metodos HTTP, actions de formularios, nombres de rutas, permisos, consultas ni comportamiento de botones.
- No se ejecutaron acciones reales sobre notificaciones.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=NotificationCenterTest`: **27 passed** (173 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local queda pendiente; se valido por codigo, tests, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.
- Se confirma que no se toco logica de notificaciones, emails, jobs, eventos ni permisos.

### Cierre Git previsto
- Commit: `style: redesign notification center`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 4G - Administracion ligera (23:15 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente las pantallas administrativas ligeras de usuarios, clientes y solicitudes de acceso con un patron WMS/ERP compacto, claro y profesional, sin tocar logica de usuarios, clientes, roles, permisos ni solicitudes.

### Vistas detectadas y modificadas
- `resources/views/users/index.blade.php`: listado `/usuarios`.
- `resources/views/clients/index.blade.php`: listado `/clientes`.
- `resources/views/access-requests/index.blade.php`: listado `/solicitudes-acceso`.
- `resources/views/access-requests/show.blade.php`: detalle/revision de solicitud de acceso, solo presentacion.

### Cambios realizados
- Se reorganizaron las cabeceras con titulo, subtitulo operativo, contador y metricas visibles sin crear consultas nuevas.
- Se compactaron filtros GET manteniendo mismos `name`, `action`, query strings y opciones existentes.
- Se redisenaron tablas/listados con panel WMS, toolbar, filas densas, identidad principal, email/datos secundarios y acciones al final.
- Se reforzaron chips de rol, cliente, codigo y estados activo/inactivo/pendiente/aprobada/rechazada.
- Se mantuvieron acciones existentes de editar, activar/desactivar y ver solicitud con las mismas rutas, formularios, `@csrf`, `@method` y condiciones por rol.
- Se compacto el detalle de solicitud con cabecera, datos recibidos, paneles de aprobacion/rechazo y resolucion sin cambiar formularios PATCH ni campos.
- Se anadio CSS scoped en `resources/css/app.css` para `wms-admin-page`, `wms-admin-table`, `wms-role-chip`, `wms-user-chip` y paneles de solicitudes.

### Archivos modificados
- `resources/views/users/index.blade.php`.
- `resources/views/clients/index.blade.php`.
- `resources/views/access-requests/index.blade.php`.
- `resources/views/access-requests/show.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, policies/permisos, middlewares, validaciones, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambiaron roles, permisos, contrasenas, aprobacion/rechazo de solicitudes, creacion/edicion real de usuarios ni logica de clientes.
- No se cambiaron nombres de rutas, metodos HTTP, actions de formularios, nombres de campos ni query strings.
- No se ejecutaron acciones reales de aprobar, rechazar, activar, desactivar, crear, editar ni borrar.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=UserManagementTest`: **7 passed** (19 assertions).
- `php artisan test --filter=ClientManagementTest`: **7 passed** (36 assertions).
- `php artisan test --filter=AccessRequestManagementTest`: **10 passed** (34 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK, sin errores de whitespace; Git mostro avisos informativos de normalizacion CRLF/LF en vistas Blade tocadas.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local queda pendiente; se validara por codigo, tests focalizados, suite completa, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.
- Se confirma que no se toco logica de usuarios, clientes, roles, permisos ni solicitudes de acceso.

### Cierre Git previsto
- Commit: `style: redesign admin list screens`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-19 - FASE 4H - Stock satelite (23:32 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente las pantallas satelite de stock para articulos, ubicaciones, alertas e importacion con un patron WMS/ERP compacto, claro y operativo, sin tocar logica de stock, importacion, articulos, ubicaciones, alertas ni permisos.

### Vistas detectadas y modificadas
- `resources/views/items/index.blade.php`: listado `/articulos`.
- `resources/views/locations/index.blade.php`: listado `/ubicaciones`.
- `resources/views/traceability/alerts/index.blade.php`: equivalente real de alertas de stock en `/trazabilidad/alertas`.
- `resources/views/stock/import.blade.php`: importacion de stock en `/stock/importar`.

### Cambios realizados
- Se reorganizaron las cabeceras con titulo, subtitulo operativo, contador y metricas visibles calculadas desde los datos ya disponibles en la vista.
- Se compactaron filtros GET manteniendo mismos `name`, `action`, rutas, query strings y opciones existentes.
- Se redisenaron listados de articulos y ubicaciones con panel WMS, toolbar, tablas densas, chips de estado y acciones al final.
- Se mantuvo el conmutador lista/tarjetas de articulos con las mismas URLs y etiquetas cubiertas por tests.
- Se preservo la celda exacta de codigo de ubicacion usada por tests para validar orden natural: `<td><strong>{{ $location->code }}</strong></td>`.
- Se reorganizo la pantalla de alertas de stock manteniendo reglas, eventos, formularios PATCH/PUT/POST, campos ocultos, paginadores y acciones de reconocer, silenciar y resolver.
- Se rediseno la importacion de stock como flujo visual de carga, previsualizacion, validaciones, resumen y confirmacion, preservando `enctype`, nombres de campos, `stock_import_id`, confirmacion y textos funcionales.
- Se anadio CSS scoped en `resources/css/app.css` para `wms-stock-admin-page`, tablas, tarjetas, filtros, alertas, reglas e importacion.

### Archivos modificados
- `resources/views/items/index.blade.php`.
- `resources/views/locations/index.blade.php`.
- `resources/views/traceability/alerts/index.blade.php`.
- `resources/views/stock/import.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, policies/permisos, validaciones, comandos, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambio logica de stock, importaciones, ubicaciones, ordenacion natural, deduplicacion, articulos, alertas, reglas ni eventos.
- No se cambiaron nombres de rutas, metodos HTTP, actions de formularios, nombres de campos, `data-*`, validaciones ni comportamiento de confirmacion.
- No se ejecuto ninguna importacion real, confirmacion real, cambio de stock, accion sobre alertas, alta/edicion real ni activacion/desactivacion.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=ItemManagementTest`: **16 passed** (56 assertions).
- `php artisan test --filter=WarehouseLocationManagementTest`: **10 passed** (46 assertions).
- `php artisan test --filter=TraceabilityModuleTest`: **19 passed** (93 assertions).
- `php artisan test --filter=StockImportTest`: **34 passed** (385 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK; Git mostro un aviso informativo de normalizacion CRLF/LF en `resources/views/items/index.blade.php`.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local queda pendiente; se validara por codigo, tests focalizados, suite completa, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.
- Se confirma que no se toco logica de stock, importaciones, ubicaciones, articulos, alertas ni permisos.

### Cierre Git previsto
- Commit: `style: redesign stock satellite screens`.
- Push normal a `origin/main`, excluyendo `.claude/`.

---

## 2026-07-20 - FASE 4I - Operaciones diarias visual (06:39 +02:00)

**Equipo:** PC trabajo / portatil.
**Ruta:** `C:\DEV\WMS_LARAVEL_PORTATIL`.
**Rama:** `main`.
**Objetivo:** redisenar visualmente `/operaciones-diarias` como pantalla WMS/ERP compacta y clara para revision diaria de almacenaje, movimientos, gestiones de camion, viajes y recalc, sin tocar logica de facturacion diaria ni calculos.

### Vista detectada y modificada
- `resources/views/daily-operations/index.blade.php`: pantalla principal `/operaciones-diarias`.

### Cambios realizados
- Se reorganizo la cabecera con contexto operativo, cliente seleccionado, dia revisado, numero de detalles y base prevista de manana.
- Se recompuso el bloque Cliente / Dia / Ver dia / Recalcular manteniendo el formulario GET y el formulario POST originales con los mismos campos, rutas, metodos y `@csrf`.
- Se integraron mensajes de estado y errores con una presentacion mas compacta.
- Se sustituyeron las tarjetas anteriores por KPIs compactos para pallets facturables, pallets movidos, gestiones de camion y viajes.
- Se anadio una banda de balance con base inicio, facturable hoy, movido hoy y base manana usando valores ya recibidos por la vista.
- Se presento el desglose por seccion a partir de `sectionOptions` y `sectionTotals`, sin recalcular ni consultar datos nuevos.
- Se rediseno la tabla de detalle minimo de facturacion diaria con patron `wms-table-panel` y columnas compactas.
- Se anadio CSS scoped en `resources/css/app.css` para `wms-daily-ops-*`, con responsive basico y botones alineados.

### Archivos modificados
- `resources/views/daily-operations/index.blade.php`.
- `resources/css/app.css`.
- `SESSION_LOG.md`.

### Riesgos evitados
- No se tocaron controladores, modelos, rutas, migraciones, servicios de calculo, comandos, tests, `resources/js/app.js`, `.env`, datos ni `public/build`.
- No se cambio logica de facturacion diaria, calculos, stock, entradas, salidas, gestiones, viajes, recalc ni conservacion de lineas manuales.
- No se cambiaron nombres de rutas, metodos HTTP, actions de formularios, nombres de campos, query strings, validaciones, permisos ni `@csrf`.
- No se ejecuto ninguna accion real de recalculo fuera de tests, ni se crearon, editaron o borraron lineas manuales.
- No se inspecciono, modifico, preparo ni incluyo `.claude/`.
- No se uso `migrate:fresh`, borrado de datos, `git add .` ni force push.

### Validaciones
- `php artisan test --filter=DailyOperationsTest`: **20 passed** (129 assertions).
- `php artisan test`: **619 passed** (3207 assertions).
- `npm run build`: OK (`vite 7.3.5`, 55 modulos transformados).
- `git diff --check`: OK.
- `git status --short --branch`: solo archivos autorizados modificados y `.claude/` sin seguimiento.

### Notas
- La revision visual autenticada local queda pendiente; se validara por codigo, prueba focal, suite completa, build y estado Git.
- `.claude/` permanece fuera de Git y debe seguir ignorada operativamente.
- Se confirma que no se toco logica de facturacion diaria, calculos, stock, entradas, salidas ni lineas manuales.

### Cierre Git previsto
- Commit: `style: redesign daily operations view`.
- Push normal a `origin/main`, excluyendo `.claude/`.
