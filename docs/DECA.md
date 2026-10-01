# DECA manual

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
- No se implementan aún rectificaciones, agrupación de envíos, firma contractual avanzada ni integración con salidas. No volver a generar silenciosamente un PDF emitido: si falta o falla su hash, recuperar el original desde backup.

## Verificación

`php artisan test --filter="DecaIssuanceTest|DecaModuleTest|NavigationRenderingTest"`

Comprobar emisión, permisos, campos condicionales, descarga anónima idéntica, conservación frente a cambios de maestros, duplicados y fallos de PDF. Revisar visualmente muestras de una y varias páginas y decodificar el QR del PDF renderizado. Las muestras se generan solo en local con datos ficticios.
