# Base técnica

El documento `mikrotik_analytics_project.md` es la especificación funcional.
Esta entrega prepara el entorno de desarrollo; no completa las fases 1 y 2.

## Componentes

- Nginx publica el frontend y enruta `/api/*`, `/health` y `/up` a PHP-FPM.
- Laravel 12 contiene la API, configuración y modelos de dominio.
- Vue 3 + Quasar usa Vite y consulta la API por el mismo origen.
- PostgreSQL conserva datos operativos. Las fechas se manejan en UTC.
- Redis sirve como cache y transporte de colas.
- Worker y scheduler están preparados para tareas futuras.
- `hotspot-collector` ejecuta `mikrotik:collect` en un proceso persistente cada 15 segundos.

El colector habilitado registra automáticamente el router configurado en `.env`.
Los routers creados manualmente conservan el valor deshabilitado por defecto del modelo.
Las credenciales no forman parte de las tablas ni de las respuestas de salud.
Los estados `not_configured` y `not_monitored` no implican conectividad verificada.

## Siguientes pasos

1. Implementar login, roles y protección de las rutas de datos.
2. Implementar sincronización DHCP y métricas del router.
3. Añadir autenticación antes de publicar fuera de localhost.
4. Validar dirección de contadores, luego implementar agregados de consumo.
5. Ampliar validación con eventos operativos reales de desconexión y cambios de IP.
6. Iniciar Akvorado cuando la fase de usuarios/sesiones esté estable.

Ya implementado: reconciliación transaccional, bloqueo Redis por router, validación completa
de instantáneas, detección de reinicio/cambio de IP, ausencias confirmadas, perfiles,
API paginada y panel. Las pruebas usan casos sintéticos de desconexión, reutilización
de IP y sesiones simultáneas, sin manipular clientes reales.

No hay conexión a ClickHouse, captura de payload ni cambios en MikroTik.

La comprobación de salud de MikroTik ya usa `MikrotikApiClient`, de solo lectura,
y `ConnectionMonitor`, que conserva el resultado durante 60 segundos. El comando
`mikrotik:check` fuerza una nueva comprobación. Esto no activa la recolección histórica.

## Contratos para la integración futura

- `MikrotikApiClient`: lectura de la API clásica, timeout y errores sin secretos.
- `HotspotCollector`: reconciliación transaccional de instantáneas completas.
- Identidad temporal: router + IP + intervalo, nunca una asignación permanente por IP.
- Los contadores MikroTik se guardan con sus nombres originales hasta validar el sentido de tráfico.
- Métricas derivadas deberán indicar la fuente y los límites de confianza.
