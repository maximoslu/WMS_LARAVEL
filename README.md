# MAXIMO WMS

Base Laravel del proyecto WMS de MAXIMO, preparada para desplegarse en Laravel Forge sobre PHP 8.4 y MySQL 8.4 LTS.

## Objetivo

Este repositorio establece una base limpia y mantenible para evolucionar el WMS por fases, empezando por los clientes Friesland y Edelvives, sin adelantar todavía lógica de negocio compleja.

## Stack

- Laravel 12
- PHP 8.4+ compatible
- MySQL 8.4 LTS como base de datos principal
- Vite para assets frontend
- PHPUnit para tests
- Laravel Forge para despliegue

## Estructura base

La aplicación incluye la estructura estándar de Laravel:

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

6. Levantar la aplicación:

```bash
php artisan serve
```

## Despliegue

- Flujo previsto: Codex/IDE -> GitHub -> Forge -> servidor
- Dominio temporal previsto en Forge: `wms_production.on-forge.com`
- Root directory en Forge: `/`
- Web directory en Forge: `/public`

La guía de despliegue está en [docs/DEPLOY_FORGE.md](/C:/Users/jorge/Mi%20unidad/MAXIMO/WEB/WMS/WMS_LARAVEL/docs/DEPLOY_FORGE.md).

## Documentación

- Arquitectura inicial: [docs/ARCHITECTURE.md](/C:/Users/jorge/Mi%20unidad/MAXIMO/WEB/WMS/WMS_LARAVEL/docs/ARCHITECTURE.md)
- Roadmap funcional: [docs/ROADMAP.md](/C:/Users/jorge/Mi%20unidad/MAXIMO/WEB/WMS/WMS_LARAVEL/docs/ROADMAP.md)
- Instrucciones para agentes: [AGENTS.md](/C:/Users/jorge/Mi%20unidad/MAXIMO/WEB/WMS/WMS_LARAVEL/AGENTS.md)

## Criterios operativos

- No se versionan secretos ni credenciales reales.
- `.env` no debe subirse al repositorio.
- Los cambios de base de datos deben pasar por migraciones.
- La base principal del sistema es MySQL, no SQLite.
- Los módulos del dominio WMS se introducirán por fases, con tests asociados.
