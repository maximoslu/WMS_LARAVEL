# Deploy En Forge

## Flujo

`Codex/IDE -> GitHub -> Forge -> servidor`

## Suposiciones iniciales

- Aplicación Laravel desplegada desde este repositorio
- PHP del servidor: 8.4
- Base de datos principal: MySQL 8.4 LTS
- Dominio temporal: `wms_production.on-forge.com`
- Root directory en Forge: `/`
- Web directory en Forge: `/public`

## Proceso recomendado

1. Desarrollar y validar cambios en local.
2. Subir cambios al repositorio GitHub.
3. Conectar la rama objetivo en Forge.
4. Configurar variables de entorno en Forge sin versionarlas en Git.
5. Ejecutar despliegue desde Forge.
6. Ejecutar migraciones de forma controlada cuando existan cambios de esquema.

## Script base esperado en Forge

```bash
cd /home/forge/wms_production.on-forge.com
git pull origin main
composer install --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Notas operativas

- No guardar credenciales reales en el repositorio.
- Gestionar `.env` únicamente desde Forge o desde el servidor.
- No hacer cambios manuales en producción que no pasen por Git.
- Si un despliegue incorpora migraciones sensibles, revisar impacto antes de lanzar `migrate --force`.

## Validación posterior al despliegue

- Confirmar que la aplicación responde desde `public/`
- Confirmar carga de la pantalla inicial
- Revisar logs de Laravel y del servidor si el deploy falla
