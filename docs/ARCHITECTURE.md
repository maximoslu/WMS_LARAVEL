# Arquitectura Inicial

## Objetivo

Definir una base Laravel limpia y desplegable para evolucionar hacia un WMS multicliente, manteniendo el núcleo inicial simple y extensible.

## Principios

- Monolito Laravel modular
- MySQL como persistencia principal
- Despliegue estándar en Laravel Forge
- Separación clara entre dominio, aplicación e infraestructura
- Evolución por fases sin sobreingeniería temprana

## Componentes iniciales

- `app/Models`: entidades Eloquent cuando empiece el modelado del dominio
- `app/Http/Controllers`: controladores web y API
- `app/Providers`: configuración transversal del framework
- `routes/web.php`: rutas web iniciales
- `routes/console.php`: automatizaciones y tareas de consola
- `database/migrations`: cambios versionados de esquema
- `resources/views`: vistas Blade iniciales

## Enfoque de dominio

En esta fase no se crean módulos WMS complejos. La primera iteración se centra en disponer de una base estable sobre la que introducir:

- autenticación
- gestión multicliente
- almacenes y ubicaciones
- catálogo de artículos
- stock, entradas, salidas y movimientos

## Criterios de diseño

- Cada cambio estructural de base de datos debe implementarse con migraciones.
- Las reglas de negocio deben acompañarse de tests a medida que aparezcan.
- Los módulos deben mantener fronteras claras para facilitar futuras extracciones o reorganización interna.
- La configuración de despliegue debe asumir `public/` como web root efectivo.

## Persistencia

- Motor principal: MySQL 8.4 LTS
- Entorno de pruebas: puede usar configuración efímera aislada
- No se debe tratar SQLite como base principal del proyecto

## Despliegue

- Repositorio GitHub como fuente de verdad
- Laravel Forge como orquestador de despliegues
- Variables de entorno gestionadas fuera del repositorio
