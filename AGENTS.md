# AGENTS

## Contexto del proyecto

- Stack principal: Laravel + MySQL
- Entorno de despliegue: Laravel Forge sobre VPS
- Proyecto: WMS multicliente para MAXIMO
- Clientes iniciales previstos: Friesland y Edelvives

## Reglas de trabajo para Codex

1. No modificar producción directamente.
2. No incluir secretos, claves, contraseñas ni credenciales reales en el repositorio.
3. Mantener `.env` fuera de control de versiones.
4. Usar migraciones para cualquier cambio de base de datos.
5. Escribir tests al implementar nuevos módulos o reglas de negocio.
6. Mantener el código claro, mantenible y alineado con convenciones de Laravel.
7. Priorizar compatibilidad con despliegue en Forge.
8. Evitar introducir complejidad de negocio antes de la fase correspondiente del roadmap.

## Criterios técnicos

- Base de datos principal: MySQL
- Punto de entrada web: `public/`
- Despliegue esperado: GitHub -> Forge -> servidor
- Las decisiones de arquitectura deben favorecer un monolito modular y evolutivo
