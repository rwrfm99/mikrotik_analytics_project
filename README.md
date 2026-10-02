# MikroTik Analytics

Base de desarrollo de la plataforma descrita en [la especificación](mikrotik_analytics_project.md).

Incluye Laravel 12 / PHP 8.3, Vue 3 + Quasar, PostgreSQL, Redis, Docker Compose,
API de salud y migraciones/modelos de routers, usuarios y sesiones.
Los archivos de bloqueo fijan las dependencias instaladas.

Incluye recolección persistente de usuarios y sesiones Hotspot, sincronización de perfiles,
API paginada y pantallas de activos e historial. Login, roles, DHCP, métricas del router
y Akvorado siguen pendientes. Véase [arquitectura y próximos pasos](docs/architecture.md).

## Inicio con Docker

Requisitos: Docker Engine/Desktop con Compose v2. Ejecutar desde esta carpeta.

En Windows, iniciar Docker Desktop con el motor de contenedores Linux. Si `docker`
no aparece en la terminal, usar `./scripts/docker.ps1` en su lugar; el script localiza
también las instalaciones por usuario. Ejemplo: `./scripts/docker.ps1 compose ps`.

1. Copiar `.env.example` a `.env` (`Copy-Item .env.example .env` en PowerShell o `cp .env.example .env` en Linux).
2. Cambiar `DB_PASSWORD` en `.env`.
3. Construir e instalar:

```sh
docker compose build
docker compose run --rm backend composer install --no-interaction
docker compose run --rm backend php artisan key:generate --show
```

4. Copiar la clave generada a `APP_KEY` en el `.env` de la raíz.
5. Preparar tablas e iniciar:

```sh
docker compose run --rm backend php artisan migrate
docker compose up -d
```

Abrir http://localhost:8091. El frontend usa el servidor Vite para desarrollo.
La primera ejecución instala sus dependencias con `npm ci` y puede tardar.
El puerto se publica solo en localhost; aún no hay autenticación.
No es una configuración de despliegue productivo.

```sh
docker compose ps
docker compose logs --tail=100 backend frontend queue-worker scheduler
docker compose exec backend php artisan test
docker compose exec frontend npm run build
docker compose down
```

`docker compose down` conserva los volúmenes. No añadir `-v` si se desean conservar los datos.
Tras modificar `composer.lock`, ejecutar nuevamente `docker compose run --rm backend composer install`.
En Linux, asegurar que `backend/storage` y `backend/bootstrap/cache` sean escribibles por
el usuario `www-data` del contenedor (UID 33); por ejemplo, mediante un grupo o ACL.

## Desarrollo sin Docker

Requiere PHP 8.3+, Composer, Node 24, PostgreSQL y Redis. Extensiones PHP:
`mbstring`, `fileinfo`, `pdo_pgsql`, `redis`, `zip`, `dom`, `xml` y `pdo_sqlite` para pruebas.

```sh
cd backend
composer install
```

Copiar el `.env.example` de la raíz a `backend/.env` y adaptar `DB_HOST` y
`REDIS_HOST` a `127.0.0.1`. Crear la base y el usuario PostgreSQL configurados.

```sh
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

En otra terminal:

```sh
cd frontend
npm ci
npm run dev
```

Abrir http://localhost:5173. Vite redirige `/api` al backend local.
Para ejecutar pruebas de backend: `php artisan test` desde `backend`.
Las pruebas usan SQLite en memoria y mocks de Redis; no necesitan un router real.

## Salud

- `/up`: liveness de Laravel.
- `/api/v1/health` y `/health`: consulta PostgreSQL y Redis; HTTP 503 si fallan.
- RouterOS verifica autenticación y lectura de `/ip/hotspot/active` si hay credenciales,
  con caché de 60 segundos. Devuelve estado, fecha de comprobación y cantidad de sesiones;
  no expone nombres, IP ni MAC. Si falla devuelve HTTP 503 y un diagnóstico sin secretos.
- ClickHouse figura como `not_configured` y workers como `not_monitored`.
  Un HTTP 200 no verifica las integraciones pendientes ni el colector.

La configuración Docker se toma del `.env` de la raíz; `backend/.env` se usa en ejecución local.
No guardar credenciales en Git. El colector se habilita con `MIKROTIK_COLLECTOR_ENABLED=true`.
Las comprobaciones de salud pueden consultar el router aunque el colector esté deshabilitado.

## Comprobar MikroTik

Rellenar `MIKROTIK_HOST`, `MIKROTIK_API_PORT`, `MIKROTIK_USERNAME` y
`MIKROTIK_PASSWORD` en `.env` de la raíz. Usar un usuario dedicado con permisos `read,api`.
La contraseña sigue la sintaxis de Docker Compose: si contiene `$`, encerrarla entre
comillas simples para evitar interpolación. No pegar las credenciales en comandos o capturas.

```powershell
.\scripts\docker.ps1 compose up -d backend queue-worker scheduler
.\scripts\docker.ps1 compose exec -T backend php artisan config:clear
.\scripts\docker.ps1 compose exec -T backend php artisan mikrotik:check
```

El cliente usa exclusivamente login y lectura de Hotspot Active y usuarios, según la
[API clásica oficial de MikroTik](https://help.mikrotik.com/docs/spaces/ROS/pages/47579160/API).
Es compatible con el login de RouterOS 6.43+ (incluido 6.49.20).
`MIKROTIK_API_TLS=true` permite API-SSL con un certificado válido y verificación de nombre;
configurar también el puerto correspondiente. No se cambia ningún servicio del router.

Diagnósticos: `connection_failed` indica fallo de conexión TCP/TLS,
`authentication_failed` rechazo de login, `request_rejected` rechazo de la consulta,
y `timeout` ausencia de respuesta a tiempo. El cliente no escribe en el router.

## Recolección persistente

En `.env` de la raíz configurar:

```env
MIKROTIK_COLLECTOR_ENABLED=true
HOTSPOT_POLL_SECONDS=15
HOTSPOT_MISSING_THRESHOLD=3
```

Después de cambios de configuración:

```powershell
.\scripts\docker.ps1 compose exec -T backend php artisan migrate --no-interaction
.\scripts\docker.ps1 compose up -d backend hotspot-collector
.\scripts\docker.ps1 compose logs --tail=20 hotspot-collector
```

El servicio `hotspot-collector` ejecuta un proceso persistente; no depende de la cola ni
del scheduler. En ejecución sin Docker, usar `php artisan mikrotik:collect`.
Para una lectura manual: `php artisan mikrotik:collect --once`. El bloqueo Redis evita
que dos procesos recolecten simultáneamente el mismo router.

- Activos cada 15 s: usuario, IP, MAC, servidor, método de login, uptime, bytes y paquetes.
- Usuarios/perfiles cada minuto. No se leen contraseñas de cuentas Hotspot.
- Tres respuestas completas consecutivas sin una sesión confirman su cierre. Los errores
  de red y las respuestas inválidas no incrementan las ausencias ni cierran sesiones.
- Cambiar IP, identidad RouterOS o reiniciar uptime crea otra sesión y conserva la anterior.
- El primer inicio se estima con uptime; nuevas identidades con historial se registran
  desde la observación. El fin se guarda como última presencia confirmada, no como hora
  exacta de desconexión. El sondeo puede perder sesiones completas entre dos consultas.
- Contadores por sesión, tal como los entrega RouterOS. Todavía no se calculan Mbps,
  agregados diarios ni atribución IPFIX. Los datos no se purgan automáticamente.

API local:

- `GET /api/v1/hotspot/collection`: última lectura, estado, error y cantidades guardadas.
- `GET /api/v1/hotspot/users/active`: sesiones abiertas, incluyendo ausencias pendientes de confirmar.
- `GET /api/v1/hotspot/sessions`: historial, con `page`, `limit` (máximo 100), `search` y `router_id`.

El panel actualiza cada 10 s y marca datos sin confirmar cuando falla o se detiene el
colector. El servicio HTTP permanece restringido a localhost hasta implementar login y roles.
Para detener solo la recolección: `./scripts/docker.ps1 compose stop hotspot-collector`.

## Referencias

- [Laravel 12](https://laravel.com/docs/12.x)
- [Integración oficial de Quasar con Vite](https://quasar.dev/start/vite-plugin/)
