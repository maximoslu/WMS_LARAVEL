# DECA manual y automático en salidas

## DECA de salidas

Al confirmar un envío nuevo (estado enviado o completado desde preparación), el WMS emite un DECA con las cantidades realmente cargadas. Cada carga parcial tiene su propio documento. El albarán de almacén, del portal cliente y del correo comparte la plantilla con número DECA, matrícula, peso y QR. Abrir o reimprimir el albarán no emite documentos nuevos.

El cargador contractual es MÁXIMO SERVICIOS LOGÍSTICOS S.L.U. y el origen/domicilio es Calle Buenos Aires 23, polígono industrial Centrovía, 50198 La Muela, Zaragoza, España. El NIF se toma de `DECA_MAXIMO_TAX_ID`; nunca del formulario ni del repositorio. El destino es la dirección efectiva de la salida, incluyendo la alternativa cuando exista.

Almacén indica peso en kg, matrícula y fecha. El transportista predeterminado es Monge, con los datos privados existentes. Si selecciona externo, debe introducir razón social y NIF. Para vehículos articulados se exige matrícula del remolque; si requiere autorización especial se exige su número. La preparación puede guardarse incompleta; confirmar envío exige los datos completos. El tipo de transporte propio/externo se deriva de esta selección.

La migración `2026_10_09_120000_add_deca_to_goods_dispatches` añade `transport_data` y la relación única `deca_document_id`, ambos anulables para conservar el histórico. El bloqueo transaccional de la salida evita duplicados por confirmaciones concurrentes. Estado, stock y relación documental se confirman juntos; un error de emisión revierte esos cambios. Los PDF se conservan en almacenamiento privado y se verifica su hash al descargarlos. Si falla la transacción tras generar el archivo, se elimina el archivo de esa emisión fallida.

Una salida con DECA emitido no admite modificaciones de carga, transporte ni dirección, tampoco por superadmin. Las rectificaciones requieren un futuro flujo documental explícito. Los albaranes históricos enviados antes de esta integración no reciben un DECA retroactivo al reimprimir o completar la salida.

`DECA_DISPATCH_ENABLED` permite desactivar la integración; su valor por defecto es `true`. Desactivar no elimina documentos ni sus QR. En PHPUnit la opción se desactiva para las pruebas del comportamiento histórico y `DispatchDecaTest` la activa expresamente para probar el flujo nuevo, los errores, permisos, reintentos y cargas parciales.

Despliegue: GitHub → Forge, migraciones y reconstrucción de Vite/cachés. Verificar NIF de MAXIMO y Monge, HTTPS, formulario en una salida pendiente y un albarán histórico. No confirmar transportes ficticios en producción para probar la emisión; las pruebas de emisión y fallo usan datos sintéticos y almacenamiento aislado.

## Acceso compartido para chóferes

`/deca-choferes` ofrece PIN compartido de cuatro dígitos y sesión independiente de la autenticación WMS. Administración configura el PIN en `/deca/acceso-choferes`; inicialmente el acceso está desactivado hasta guardar una. Solo se conserva su hash. Cambiarla revoca sesiones anteriores; cada sesión dura como máximo 12 horas y se aplica además la caducidad habitual de Laravel. Login limitado a cinco intentos por minuto/IP.

El portal permite exclusivamente los servicios rápidos, el documento recién emitido en esa sesión y su PDF/QR. No inicia sesión como usuario del WMS, no muestra el historial general ni enlaza al formulario manual. Las emisiones registran `access_channel=shared_driver_portal` y una huella de sesión; `created_by` identifica al administrador responsable que configuró el acceso, no al conductor individual. Una contraseña compartida no permite atribución individual. La migración `2026_10_02_150000_create_deca_driver_access_table` crea la configuración privada; incluir esta tabla en las copias completas de MySQL.

Acceso del equipo interno: `/deca/crear` (almacén o superior). Los usuarios cliente no acceden al listado ni al formulario. El equipo comparte los documentos de las dos empresas transportistas propias.

## Configuración privada en Forge

Configurar sin versionar valores fiscales:

```dotenv
DECA_MONGE_TAX_ID=
DECA_MONGE_ADDRESS=
DECA_MAXIMO_TAX_ID=
DECA_MAXIMO_ADDRESS=
DECA_PUBLIC_BASE_URL=https://wms.maximosl.com
```

Los nombres y las dos opciones de transportista se definen en `config/deca.php`. El servidor toma el NIF desde la configuración, nunca del formulario. Actualizar la caché de configuración mediante el despliegue habitual.

## Despliegue

Ejecutar la migración `2026_10_01_120000_create_deca_documents_table` mediante Forge. Es aditiva y no altera tablas de negocio existentes. Composer instala BaconQrCode (SVG, sin servicio externo); Vite compila el formulario móvil. El PDF utiliza Dompdf, ya disponible.

## Conservación y acceso

- Se guarda una instantánea de los datos y un PDF nativo en el disco privado `local`, carpeta `deca/AAAA/MM`. Forge debe mantener su directorio storage compartido entre releases.
- La emisión registra operador, hora, tamaño y SHA-256. Los registros emitidos son inmutables; no hay rutas de edición ni borrado en esta fase.
- Un identificador de formulario y bloqueo transaccional por operador evitan duplicados por reenvío.
- El QR usa un token aleatorio de 256 bits y descarga directamente el mismo PDF por HTTPS. Es un enlace portador: compartirlo permite leer ese documento sin login, nunca consultar el historial.
- La URL no caduca en esta fase. No existe purga automática. `retain_until` es un mínimo de un año desde la fecha prevista de transporte, no una autorización de borrado ni una fecha de finalización real del servicio.
- Las copias deben incluir tanto `deca_documents` como los archivos privados `deca/`. Una copia solo de MySQL no conserva los PDF. La exportación de operaciones incluye la tabla.
- No se implementan aún rectificaciones, agrupación de envíos ni firma contractual avanzada. No volver a generar silenciosamente un PDF emitido: si falta o falla su hash, recuperar el original desde backup.

## Verificación

`php artisan test --filter="DecaIssuanceTest|DecaModuleTest|NavigationRenderingTest"`

Comprobar emisión, permisos, campos condicionales, descarga anónima idéntica, conservación frente a cambios de maestros, duplicados y fallos de PDF. Revisar visualmente muestras de una y varias páginas y decodificar el QR del PDF renderizado. Las muestras se generan solo en local con datos ficticios.
