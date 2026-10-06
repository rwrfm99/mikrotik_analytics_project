# Validación de la base

## Memoria de consultas ClickHouse

El perfil `traffic_readonly` conserva el límite de 512 MiB por consulta y utiliza
agregación externa desde 128 MiB, ordenación externa desde 64 MiB y dos hilos.
Los datos temporales se escriben en el disco del servidor; los informes grandes
pueden tardar más y siguen sujetos al límite de ejecución de 15 segundos.

El archivo se monta mediante Compose en `users.d/traffic.xml`. ClickHouse recarga
los perfiles automáticamente. Si el servicio no recoge el cambio, ejecutar
`docker compose restart clickhouse` y repetir el informe que falló.

Para comprobar el perfil, ejecutar con el usuario `traffic_reader`:

```sql
SELECT name, value FROM system.settings
WHERE name IN ('max_memory_usage', 'max_bytes_before_external_group_by',
               'max_bytes_before_external_sort', 'max_threads');
```

La configuración XML se validó localmente. No se pudo verificar la consulta real
en este entorno porque el ejecutable de Docker no está disponible.

Comprobaciones ejecutadas el 2 de octubre de 2026:

- Frontend: `npm run build` completado con Vite; 65 módulos transformados.
- Backend: PHPUnit completado, 6 pruebas y 21 aserciones.
- Casos propios: salud disponible, fallo de dependencias sin filtración de errores,
  conservación de sesiones con cambio de IP y unicidad de usuario por router.
- Laravel Pint aplicado al código PHP.
- Rutas de salud registradas en Laravel.
- Dependencias instaladas y archivos `composer.lock` / `package-lock.json` generados.

Entorno de comprobación: Windows, PHP 8.4.25 y Node 24.20.0.
Composer resuelve para PHP 8.3, utilizado por el Dockerfile.
Las pruebas de persistencia utilizan SQLite en memoria y Redis se simula.

## Validación con Docker Desktop en Windows

- Docker Desktop disponible con motor Linux; configuración de Compose validada.
- Imágenes del backend, worker y scheduler construidas correctamente.
- Los siete servicios se iniciaron.
- Todas las migraciones ejecutadas correctamente en PostgreSQL 17.
- PHPUnit dentro del contenedor: 6 pruebas aprobadas y 21 aserciones.
  SQLite en memoria se fuerza en PHPUnit para evitar que los tests utilicen PostgreSQL del entorno.
- `http://localhost:8091`: HTTP 200.
- `/up`: HTTP 200.
- `/api/v1/health`: HTTP 200, API, PostgreSQL y Redis en estado `ok`.
- Configuración local generada en `.env` de la raíz, excluida de Git.

## Conexión MikroTik

- Configuración de MikroTik presente en `.env` de la raíz y aplicada a Docker.
- `php artisan mikrotik:check`: autenticación y lectura correctas; 50 sesiones activas
  observadas en la comprobación (el número cambia con la actividad del router).
- No se modificó configuración de RouterOS ni se guardaron datos de clientes.
- Suite ampliada: 16 pruebas aprobadas y 49 aserciones.
- Frontend actualizado y compilado correctamente.

## Recolección activada

- Servicio Docker `hotspot-collector` iniciado con intervalo de 15 segundos.
- Primera lectura persistida: 73 cuentas y 53 sesiones abiertas.
- Ciclos siguientes: 53 observadas, 0 creadas y 0 cerradas; actualización sin duplicados.
- API de activos responde con paginación y perfiles desde PostgreSQL.
- `/api/v1/hotspot/collection`: estado `ok`, fecha de lectura reciente y sin errores.
- Al finalizar: 73 usuarios, 53 sesiones abiertas y 55 sesiones totales; el historial
  ya conserva dos sesiones cerradas por la actividad natural del router.
- Frontend con tablas de activos e historial compilado correctamente (136 módulos).
- Suite: 29 pruebas y 139 aserciones. Incluye cierre tras tres ausencias, fallos de API,
  cambio de IP, reinicio de uptime, dispositivos simultáneos, IP reutilizada, instantáneas
  inválidas, bloqueo concurrente y endpoints paginados.
- Se reforzó el aislamiento de tests en `Tests/TestCase.php`: Docker exporta variables
  también por `$_SERVER`; la conexión de pruebas se configura explícitamente a SQLite
  en memoria antes de ejecutar las migraciones de prueba.

Las desconexiones/cambios de IP se probaron con fixtures sintéticos; no se desconectó
a ningún cliente real. Pendiente revisión visual en navegador (no hay navegador conectado),
autenticación, DHCP/métricas adicionales e integración Akvorado.
