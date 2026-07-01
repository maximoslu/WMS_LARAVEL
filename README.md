# MAXIMO WMS

Base Laravel del proyecto WMS de MAXIMO, preparada para desplegarse en Laravel Forge sobre PHP 8.4 y MySQL 8.4 LTS.

## Objetivo

Este repositorio establece una base limpia y mantenible para evolucionar el WMS por fases, empezando por los clientes Friesland y Edelvives, sin adelantar todavia logica de negocio compleja.

## Stack

- Laravel 12
- PHP 8.4+ compatible
- MySQL 8.4 LTS como base de datos principal
- Vite para assets frontend
- PHPUnit para tests
- Laravel Forge para despliegue

## Estructura base

La aplicacion incluye la estructura estandar de Laravel:

- `app/`
- `bootstrap/`
- `config/`
- `database/`
- `public/`
- `resources/`
- `routes/`
- `storage/`
- `tests/`
- `artisan`
- `composer.json`

## Puesta en marcha local

1. Instalar dependencias PHP:

```bash
composer install
```

2. Crear el entorno local:

```bash
cp .env.example .env
php artisan key:generate
```

3. Configurar credenciales MySQL locales en `.env`.

4. Ejecutar migraciones:

```bash
php artisan migrate
```

5. Instalar y compilar assets:

```bash
npm install
npm run build
```

6. Levantar la aplicacion:

```bash
php artisan serve
```

## Google Calendar OAuth read-only

La integracion actual de Google Calendar es solo de lectura y se usa como capa visual en el dashboard operativo.

1. Activar Google Calendar API en Google Cloud.
2. Crear un OAuth Client de tipo "Aplicacion web".
3. Configurar como redirect URIs locales:
   - `http://127.0.0.1:8000/google-calendar/oauth/callback`
   - `http://localhost:8000/google-calendar/oauth/callback`
4. Configurar como redirect URI de produccion:
   - `https://wms.maximosl.com/google-calendar/oauth/callback`
5. Configurar en `.env`:

```bash
GOOGLE_CALENDAR_ENABLED=true
GOOGLE_CALENDAR_AUTH_MODE=oauth
GOOGLE_CALENDAR_ID=
GOOGLE_CALENDAR_CLIENT_ID=
GOOGLE_CALENDAR_CLIENT_SECRET=
GOOGLE_CALENDAR_REDIRECT_URI=http://127.0.0.1:8000/google-calendar/oauth/callback
GOOGLE_CALENDAR_TOKEN_PATH=storage/app/google/calendar-token.json
```

5. `GOOGLE_CALENDAR_ID` se obtiene en Google Calendar > Configuracion del calendario > Integrar calendario > ID del calendario.
6. Ir a `/google-calendar/oauth/redirect` con un usuario `superadmin` o `administracion` para conectar.
7. El token OAuth se guarda solo en local en `storage/app/google/calendar-token.json`.

Notas:

- La integracion actual solo lee eventos.
- No crea, no modifica y no elimina eventos en Google Calendar.
- Futura fase: booking aprobado -> crear evento Google.
- No se deben versionar secretos, tokens ni JSON reales descargados de Google.
- Como el secreto OAuth ya se ha visto durante la configuracion inicial, antes de produccion conviene regenerarlo en Google Cloud y actualizar Forge con el secreto definitivo.

### Forge y produccion

En Forge conviene dejar la capa desactivada hasta que el OAuth quede configurado de punta a punta:

```bash
GOOGLE_CALENDAR_ENABLED=false
GOOGLE_CALENDAR_AUTH_MODE=oauth
GOOGLE_CALENDAR_ID=
GOOGLE_CALENDAR_CLIENT_ID=
GOOGLE_CALENDAR_CLIENT_SECRET=
GOOGLE_CALENDAR_REDIRECT_URI=https://wms.maximosl.com/google-calendar/oauth/callback
GOOGLE_CALENDAR_TOKEN_PATH=storage/app/google/calendar-token.json
```

Notas de despliegue para esta capa:

- El token OAuth no se sube desde local: se genera en el propio servidor al completar el login contra Google.
- El fichero esperado en produccion es `storage/app/google/calendar-token.json`.
- Antes de activar `GOOGLE_CALENDAR_ENABLED=true` en Forge, hay que validar que la redirect URI anterior existe tambien en Google Cloud.
- El secreto OAuth definitivo de produccion debe regenerarse en Google Cloud y guardarse solo en Forge.

## Despliegue

- Flujo previsto: Codex/IDE -> GitHub -> Forge -> servidor
- Dominio temporal previsto en Forge: `wms_production.on-forge.com`
- Root directory en Forge: `/`
- Web directory en Forge: `/public`

La guia de despliegue esta en [docs/DEPLOY_FORGE.md](/C:/Users/jorge/Mi%20unidad/MAXIMO/WEB/WMS/WMS_LARAVEL/docs/DEPLOY_FORGE.md).

## Documentacion

- Arquitectura inicial: [docs/ARCHITECTURE.md](/C:/Users/jorge/Mi%20unidad/MAXIMO/WEB/WMS/WMS_LARAVEL/docs/ARCHITECTURE.md)
- Roadmap funcional: [docs/ROADMAP.md](/C:/Users/jorge/Mi%20unidad/MAXIMO/WEB/WMS/WMS_LARAVEL/docs/ROADMAP.md)
- Instrucciones para agentes: [AGENTS.md](/C:/Users/jorge/Mi%20unidad/MAXIMO/WEB/WMS/WMS_LARAVEL/AGENTS.md)

## Criterios operativos

- No se versionan secretos ni credenciales reales.
- `.env` no debe subirse al repositorio.
- Los cambios de base de datos deben pasar por migraciones.
- La base principal del sistema es MySQL, no SQLite.
- Los modulos del dominio WMS se introduciran por fases, con tests asociados.
