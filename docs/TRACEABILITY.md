# Trazabilidad, auditoria y alertas de stock

## Alcance

El modulo `GESTION > TRAZABILIDAD` separa cuatro conceptos que no deben confundirse:

1. `user_activity_sessions` y `user_section_metrics`: uso agregado del WMS.
2. `audit_logs`: acciones empresariales y de seguridad.
3. `inventory_movements`: libro mayor inmutable de inventario.
4. `stock_alert_rules` y `stock_alert_events`: configuracion y resultados de alertas.

`SUPERADMIN` y `ADMINISTRACION` acceden al modulo completo. `ALMACEN` solo puede consultar portada, movimientos, lotes y alertas activas. `CLIENTE` no tiene acceso. Los permisos se comprueban en navegacion, middleware, controladores y exportaciones.

## Modelo de actividad

El login abre una sesion de actividad y el logout intenta cerrarla por hash de sesion. Si el identificador rota, se usa como fallback la ultima sesion activa del mismo usuario, IP anonimizada y agente de navegador. Un heartbeat autenticado cada 60 segundos suma tiempo solo cuando la pestana esta visible.

El intervalo contabilizable se limita a 90 segundos y una pausa superior a 180 segundos suma cero. Las visitas se agregan por usuario, fecha y seccion normalizada a partir del nombre de ruta. Se excluyen heartbeat, AJAX, respuestas tecnicas y peticiones que no sean GET navegables.

La interfaz usa siempre el termino **Tiempo activo estimado**. No se guardan pulsaciones, formularios, contrasenas, tokens, cuerpos de peticion ni contenido escrito. La IP se anonimiza y el agente se limita a 255 caracteres.

## Auditoria empresarial

`AuditLogService` conserva snapshots del actor, rol, cliente, entidad, accion, ruta, metodo, correlacion, fecha y cambios relevantes. Las claves cuyo nombre contiene password, secret, token, authorization, cookie o API key se eliminan recursivamente antes de guardar.

La auditoria cubre login/logout, clientes, articulos, usuarios/roles, destinatarios de email, entradas, documentos e IA, salidas, ajustes de stock, importaciones, consolidaciones, reglas/eventos de alertas y exportaciones. Los registros no tienen rutas de modificacion y el modelo impide `update` y `delete`.

## Libro mayor de inventario

`InventoryMovementService` recibe fotografias anterior y posterior de una partida y registra:

- cliente, articulo, SKU, descripcion y lote;
- tipo, origen, linea origen, actor y correlacion;
- almacen y ubicacion anterior/posterior;
- unidades, pallets completos, pallets de almacen y picos antes/delta/despues;
- fecha efectiva, fecha de registro, confianza y metadatos.

Cada movimiento tiene `idempotency_key` unica. Las operaciones criticas lo crean dentro de la misma transaccion que modifica stock. Una correccion o reversion crea otro movimiento; nunca modifica el original. La evaluacion de alertas se despacha con `DB::afterCommit`, por lo que un email fallido no revierte stock.

Integraciones actuales:

- confirmacion, edicion autorizada y eliminacion autorizada de entradas;
- confirmacion/envio de salidas, allocations reales y consumo FIFO;
- ajustes, bloqueos, desbloqueos y traslados manuales de stock;
- importacion y retirada de la fotografia anterior;
- consolidacion de almacenes/ubicaciones cuando cambia la ubicacion de una partida;
- reparaciones controladas de contadores de pallets.

No se ha incorporado una cadena `previous_hash`/`record_hash` en esta primera version. La decision prioriza transacciones, idempotencia, inmutabilidad de aplicacion y snapshots legibles. Un hash encadenado requeriria definir antes canonicalizacion, rotacion, verificacion y procedimiento de recuperacion; queda como endurecimiento futuro, no como una garantia simulada.

## Trazabilidad por lote

La consulta exige cliente y lote; articulo es un filtro adicional. Nunca se considera que un lote sea globalmente unico. `LotTraceabilityService` consulta por `client_id + lot + item_id` y reconstruye:

- un paso atras: proveedor, entrada, documento, fecha y cantidades;
- estado: partidas activas/historicas, stock, almacen y ubicacion;
- un paso adelante: allocations, salida, destino y documento;
- cronologia: entrada, movimientos y salida documental.

El indicador puede ser `Completa`, `Parcial` o `Inconsistente`. Explica entradas sin documento, lotes sin entrada verificable, salidas sin allocation, movimientos sin actor, partidas inactivas con saldo y diferencias entre saldo del ledger y stock actual. Nunca inventa un proveedor, una entrada o un destino.

## Analitica y prevision

La analitica exige cliente y limita el periodo a 366 dias. Calcula entradas, salidas, rotacion, articulos sin movimiento, disponible/bloqueado/obsoleto, lotes activos, ajustes manuales y clasificacion ABC.

`StockForecastService` usa medias moviles de 7, 30 y 90 dias con pesos 50/30/20, demanda pendiente, stock de seguridad y lead time. Expone datos usados, dias de historico, variabilidad, cobertura, fecha estimada y confianza. Con menos de 14 dias o sin salidas devuelve una razon explicita y no presenta una prediccion como fiable.

## Alertas de stock

Una regla es unica por cliente y articulo y requiere al menos uno de estos umbrales: unidades, pallets, cobertura o horizonte de agotamiento. Puede excluir/incluir bloqueado y obsoleto, y define severidad y cooldown.

El evaluador:

- no modifica nada en dry-run;
- crea evento al cruzar umbral, cambiar severidad o empeorar de forma relevante tras cooldown;
- reactiva una critica al terminar su silencio si sigue incumpliendo;
- resuelve automaticamente cuando se recuperan los umbrales;
- bloquea repeticiones mientras no cambie el estado;
- encola email solo despues del commit.

Los destinatarios proceden exclusivamente de `client_stock_alert_email_recipients` (`Avisos de stock`). No se reutilizan automaticamente emails de albaranes de entrada o salida. Los emails se normalizan, validan y deduplican por cliente.

Comando diario:

```bash
php artisan wms:stock-alerts:evaluate --dry-run
php artisan wms:stock-alerts:evaluate --apply
```

El scheduler ejecuta `--apply` diariamente a las 06:00. Forge debe tener activo `php artisan schedule:run` cada minuto y un worker de cola.

## Backfill

```bash
php artisan wms:traceability:backfill --dry-run
php artisan wms:traceability:backfill --client=EDELVIVES --dry-run
php artisan wms:traceability:backfill --client=EDELVIVES --apply
```

Sin `--apply` el comando siempre es de solo lectura. Reconstruye entradas confirmadas y salidas con allocations cuando las relaciones son verificables. Para stock actual sin historia crea, solo con apply aprobado, `opening_balance` etiquetado **Saldo inicial al activar trazabilidad** y `reconstruction_confidence=opening_balance`.

Apply es transaccional e idempotente. Solo crea movimientos de trazabilidad: no modifica stock, entradas, salidas ni allocations. Nunca debe ejecutarse en produccion sin dry-run guardado, revision de inconsistencias y aprobacion expresa del propietario.

## Exportaciones y rendimiento

Los listados estan paginados. Movimientos exige cliente, la portada se limita a 30 dias y la analitica a 366. El CSV exige cliente y fechas, admite filtros y rechaza mas de 10.000 filas. Incluye fecha, usuario y criterios, y registra la descarga en auditoria.

Los indices cubren cliente, usuario, articulo, lote, tipo, fechas, evento, modulo, correlacion, origen, estado y combinaciones de consulta. Auditoria, movimientos y alertas se conservan indefinidamente. No existe borrado automatico en esta version.

## Despliegue y operacion en Forge

1. Desplegar el commit aprobado de `origin/main`.
2. Ejecutar `php artisan migrate --force`.
3. Ejecutar `php artisan optimize:clear`.
4. Ejecutar `php artisan queue:restart`.
5. Confirmar worker de cola y cron `php artisan schedule:run` cada minuto.
6. Ejecutar `php artisan wms:traceability:backfill --dry-run` y guardar el informe.
7. No ejecutar backfill `--apply` sin aprobacion expresa.
8. Ejecutar `php artisan wms:stock-alerts:evaluate --dry-run`.
9. Configurar una regla y un destinatario controlado antes de la primera evaluacion apply.
10. Validar manualmente un lote alimentario real de Friesland: proveedor, documento, ubicacion, allocation, salida y destino.

## Soporte

Para investigar una operacion, buscar primero su `correlation_id` en auditoria y movimientos. Si el lote aparece parcial, revisar antes de corregir: documentos, lineas de entrada, allocations y saldo inicial. Las correcciones se registran como nuevos movimientos; no se edita el historico.
