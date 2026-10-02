# Proyecto: MikroTik Hotspot Analytics

## 1. Objetivo

Construir una plataforma web propia para obtener, correlacionar y analizar datos de una red MikroTik Hotspot.

La plataforma debe permitir responder preguntas como:

- ¿Qué usuarios están conectados ahora?
- ¿Qué IP y MAC tiene cada usuario?
- ¿Cuánto tráfico consume cada usuario?
- ¿Cuánto descarga y cuánto sube?
- ¿Cuánto tiempo permanece conectado?
- ¿Qué IP tuvo un usuario en una fecha determinada?
- ¿Qué usuario tenía una IP concreta en una hora determinada?
- ¿Cuáles son los principales destinos de tráfico?
- ¿Qué ASN, proveedores, CDN y organizaciones concentran el tráfico?
- ¿Cuál es el consumo diario, semanal y mensual?
- ¿Qué usuarios presentan mayor consumo?
- ¿Cuál es el pico de velocidad por usuario?
- ¿Qué usuarios cambian frecuentemente de IP?
- ¿Qué dispositivos/MAC utiliza cada cuenta?
- ¿Cuándo deja de recibirse IPFIX o deja de responder el MikroTik?

El objetivo final es responder de forma fiable:

```text
QUIÉN
usó
CUÁNTO
tráfico
CUÁNDO
y
HACIA DÓNDE
```

---

## 2. Contexto de infraestructura actual

### MikroTik

Router actual:

```text
Modelo: MikroTik hEX
RouterOS: 6.49.20 long-term
IP LAN/router: 192.168.10.1
Red LAN/Hotspot: 192.168.8.0/22
WAN observada: 192.168.1.29
```

Servicios ya disponibles:

- DHCP activo.
- Hotspot activo.
- RouterOS API habilitada.
- SNMP habilitado.
- Traffic Flow/IPFIX habilitado.
- Firewall ya preparado para permitir el servidor de análisis.
- Los clientes reciben IP dinámica.

### Servidor de análisis

```text
IP: 192.168.8.8
Sistema: Ubuntu
Docker: activo
```

### Akvorado

Akvorado ya está instalado y funcionando.

```text
Web: http://192.168.8.8:8081
Versión observada: v2026.8.1
IPFIX: UDP/2055
Exporter MikroTik: 192.168.10.1
```

El MikroTik ya envía IPFIX hacia:

```text
192.168.8.8:2055/udp
```

Los campos IPFIX relevantes están habilitados:

```text
src-address=yes
dst-address=yes
src-port=yes
dst-port=yes
in-interface=yes
out-interface=yes
protocol=yes
packets=yes
bytes=yes
nat-src-address=yes
nat-dst-address=yes
nat-src-port=yes
nat-dst-port=yes
```

En Akvorado ya fue necesario configurar:

```yaml
core:
  default-sampling-rate: 1
```

porque el MikroTik no anunciaba el sampling rate y Akvorado descartaba los flujos con:

```text
sampling rate missing
```

Actualmente Akvorado ya recibe y procesa flujos.

---

## 3. Problema principal a resolver

Akvorado conoce tráfico, IP, puertos, ASN, interfaces y campos NAT.

MikroTik Hotspot conoce:

```text
usuario
IP
MAC
sesión
uptime
bytes
packets
perfil
```

Pero IPFIX no transporta directamente el nombre de usuario Hotspot.

Además, como DHCP es dinámico, no se puede mantener una relación permanente:

```text
usuario -> IP
```

porque la misma IP puede pertenecer a personas distintas en momentos diferentes.

Por tanto, la correlación correcta debe usar:

```text
usuario + IP + fecha/hora
```

---

## 4. Arquitectura propuesta

```text
                         MikroTik
                     192.168.10.1
                           |
          +----------------+----------------+
          |                |                |
     RouterOS API        SNMP             IPFIX
       TCP 8728          UDP 161         UDP 2055
          |                |                |
          |                |                v
          |                |             Akvorado
          |                |                |
          |                |            ClickHouse
          |                |                |
          +--------+-------+----------------+
                   |
                   v
              Backend Laravel
                   |
        +----------+-----------+
        |                      |
   PostgreSQL              ClickHouse
 usuarios/sesiones      tráfico IPFIX
        |                      |
        +----------+-----------+
                   |
                   v
                REST API
                   |
                   v
            Vue 3 + Quasar
                   |
                   v
            Dashboard Web
```

---

## 5. Stack recomendado

### Backend

- Laravel 12
- PHP 8.3+
- API REST
- Laravel Scheduler
- Laravel Queue
- PostgreSQL
- Redis opcional/recomendado

### Frontend

- Vue 3
- Quasar
- SPA
- Apache ECharts o Chart.js

### Datos

#### PostgreSQL

Usar para:

- usuarios;
- sesiones;
- histórico IP;
- histórico MAC;
- métricas del router;
- alertas;
- configuración;
- agregados propios;
- auditoría.

#### ClickHouse de Akvorado

Usar para:

- flujos IPFIX;
- volumen;
- paquetes;
- destinos;
- ASN;
- campos NAT;
- puertos;
- protocolos;
- series de tráfico.

No duplicar todos los flujos IPFIX en PostgreSQL.

---

## 6. Estructura del proyecto

Ruta recomendada:

```text
/opt/mikrotik-analytics
```

Estructura:

```text
mikrotik-analytics/
├── backend/
├── frontend/
├── docker/
├── docker-compose.yml
├── .env
├── docs/
├── scripts/
└── README.md
```

Puerto web sugerido:

```text
http://192.168.8.8:8091
```

---

## 7. Integración RouterOS API

Debe usarse la API clásica de RouterOS compatible con RouterOS 6.49.20.

No usar REST API de RouterOS 7.

Consultar como mínimo:

```text
/ip/hotspot/active
/ip/hotspot/user
/ip/hotspot/user/profile
/ip/dhcp-server/lease
/interface
/system/resource
```

Opcional:

```text
/ip/arp
/ip/hotspot/host
/ip/firewall/connection
```

---

## 8. Colector de usuarios Hotspot

Crear un worker persistente que consulte:

```text
/ip/hotspot/active
```

Frecuencia inicial:

```text
cada 15 segundos
```

Debe extraer, cuando estén disponibles:

```text
user
address
mac-address
uptime
idle-time
session-time-left
bytes-in
bytes-out
packets-in
packets-out
login-by
server
```

### Algoritmo

Por cada usuario activo:

1. identificar `username`;
2. leer IP;
3. leer MAC;
4. buscar sesión abierta;
5. si existe:
   - actualizar `last_seen_at`;
   - uptime;
   - bytes;
   - packets;
6. si no existe:
   - crear nueva sesión.

Después:

1. detectar sesiones que estaban abiertas;
2. comprobar si siguen presentes;
3. no cerrarlas por un único fallo;
4. cerrar cuando se confirme ausencia durante varios ciclos.

Configuración sugerida:

```text
POLL_INTERVAL=15
MISSING_THRESHOLD=3
```

---

## 9. Modelo de datos PostgreSQL

### routers

```text
id
name
host
api_port
snmp_port
enabled
created_at
updated_at
```

Las credenciales deben almacenarse cifradas o en variables de entorno.

---

### hotspot_users

```text
id
router_id
username
profile
disabled
comment
first_seen_at
last_seen_at
created_at
updated_at
```

Índice único:

```text
router_id + username
```

---

### hotspot_sessions

```text
id
router_id
hotspot_user_id
username
ip_address
mac_address
server_name
login_by
started_at
last_seen_at
ended_at
uptime_seconds
mikrotik_bytes_in
mikrotik_bytes_out
mikrotik_packets_in
mikrotik_packets_out
created_at
updated_at
```

Índices:

```text
router_id + ip_address + started_at
hotspot_user_id + started_at
ended_at
mac_address
```

---

### dhcp_leases

```text
id
router_id
ip_address
mac_address
host_name
server
status
dynamic
expires_at
last_seen_at
created_at
updated_at
```

---

### router_metrics

```text
id
router_id
captured_at
cpu_load
free_memory
total_memory
free_hdd
total_hdd
uptime_seconds
active_hotspot_users
created_at
```

---

### alerts

```text
id
router_id
hotspot_user_id nullable
type
severity
message
payload jsonb
detected_at
acknowledged_at
created_at
```

---

### traffic_aggregates_hourly

```text
id
router_id
hotspot_user_id
hour
download_bytes
upload_bytes
total_bytes
packets
peak_bps
created_at
updated_at
```

---

### traffic_aggregates_daily

```text
id
router_id
hotspot_user_id
date
download_bytes
upload_bytes
total_bytes
packets
peak_bps
created_at
updated_at
```

---

## 10. Integración con ClickHouse/Akvorado

La aplicación debe conectarse al ClickHouse actual de Akvorado en modo lectura.

No asumir nombres de base, tablas ni columnas.

Durante instalación ejecutar introspección:

```sql
SHOW DATABASES;
SHOW TABLES;
DESCRIBE TABLE <tabla>;
```

Buscar campos equivalentes a:

```text
TimeReceived
SrcAddr
DstAddr
SrcPort
DstPort
Proto
Bytes
Packets
SamplingRate
SrcAS
DstAS
SrcCountry
DstCountry
SrcAddrNAT
DstAddrNAT
SrcPortNAT
DstPortNAT
InIf
OutIf
```

Crear servicio Laravel:

```text
AkvoradoFlowRepository
```

Responsabilidades:

- consultas read-only;
- consultas parametrizadas;
- limitar rango temporal;
- evitar `SELECT *`;
- agregar en ClickHouse;
- cachear rankings;
- no modificar tablas de Akvorado.

---

## 11. Resolución de la IP real del cliente

Crear clase:

```text
ClientAddressResolver
```

Red local:

```text
192.168.8.0/22
```

Debe revisar:

```text
SrcAddr
DstAddr
SrcAddrNAT
DstAddrNAT
```

y decidir cuál pertenece al usuario Hotspot.

No asumir que el cliente siempre está en `SrcAddr`.

Caso observado:

```text
DstAddrNAT = 192.168.9.189
SrcAddrNAT = 17.253.13.145
```

El cliente puede ser:

```text
192.168.9.189
```

en tráfico de descarga.

Debe soportar:

```text
upload
download
unknown
```

---

## 12. Correlación tráfico -> usuario

Crear servicio:

```text
TrafficUserCorrelator
```

Entrada:

```text
client_ip
flow_timestamp
```

Debe buscar en `hotspot_sessions`:

```sql
ip_address = client_ip
AND started_at <= flow_timestamp
AND (
    ended_at IS NULL
    OR ended_at >= flow_timestamp
)
```

Resultados:

```text
exact
unknown
ambiguous
```

Nunca atribuir tráfico ambiguo automáticamente.

---

## 13. Cálculo upload/download

Debe existir una regla explícita.

Ejemplo:

```text
cliente -> Internet = upload
Internet -> cliente = download
```

La dirección se determina usando:

- red local;
- NAT;
- interfaces;
- origen/destino.

Crear tests unitarios con tráfico real.

---

## 14. Dashboard principal

Mostrar tarjetas:

```text
Usuarios online
Usuarios vistos hoy
Tráfico total hoy
Descarga hoy
Subida hoy
Mbps actuales
Pico Mbps del día
Sesiones iniciadas hoy
Sesiones cerradas hoy
```

Gráficos:

```text
Tráfico últimas 24 horas
Top usuarios por consumo
Top usuarios por descarga
Top usuarios por subida
Top ASN
Top destinos
Usuarios activos en el tiempo
```

---

## 15. Vista de usuarios activos

Tabla:

```text
Usuario
IP
MAC
Perfil
Login
Uptime
Download
Upload
Total
Mbps actual
Estado
```

Filtros:

```text
username
IP
MAC
profile
estado
```

---

## 16. Detalle de usuario

Cabecera:

```text
username
estado
IP actual
MAC actual
perfil
inicio sesión
uptime
```

Indicadores:

```text
consumo hoy
consumo semana
consumo mes
download
upload
peak Mbps
```

Tabs:

```text
Resumen
Tráfico
Destinos
Sesiones
IPs
Dispositivos
Alertas
```

---

## 17. Histórico de sesiones

Mostrar:

```text
inicio
fin
duración
IP
MAC
download
upload
total
```

Debe conservar sesiones aunque DHCP cambie la IP.

Ejemplo:

```text
juan
2026-10-02 08:15 -> 12:42
192.168.9.189

juan
2026-10-03 07:51 -> 17:03
192.168.8.77
```

---

## 18. Análisis de destinos

Agrupar por:

```text
IP
ASN
organización
país
puerto
protocolo
PTR
```

Ejemplo:

```text
Apple CDN
Meta
Google
Cloudflare
Microsoft
```

No afirmar que ASN/IP equivale necesariamente a una URL exacta.

---

## 19. DNS/PTR

Crear servicio opcional:

```text
ReverseDnsResolver
```

Debe:

- resolver PTR;
- usar cola/background;
- cachear;
- respetar TTL;
- limitar concurrencia;
- no bloquear dashboards.

Tabla:

```text
ip_enrichment
```

Campos:

```text
ip_address
ptr_name
asn
organization
country
last_resolved_at
expires_at
```

---

## 20. Limitaciones que la interfaz debe dejar claras

IPFIX permite analizar tráfico, pero no necesariamente conocer:

```text
URL exacta
contenido HTTPS
página exacta
tiempo real leyendo una página
```

La UI debe diferenciar:

```text
Destino IP
ASN
Organización
PTR
Servicio probable
```

de:

```text
URL visitada
```

No presentar inferencias como hechos.

---

## 21. API REST

Prefijo:

```text
/api/v1
```

Endpoints:

```text
GET /dashboard

GET /routers
GET /routers/{id}/status

GET /hotspot/users
GET /hotspot/users/active
GET /hotspot/users/{id}

GET /hotspot/users/{id}/sessions
GET /hotspot/users/{id}/traffic
GET /hotspot/users/{id}/destinations
GET /hotspot/users/{id}/timeline

GET /hotspot/sessions
GET /hotspot/sessions/{id}

GET /traffic/top-users
GET /traffic/top-destinations
GET /traffic/top-asns
GET /traffic/timeseries

GET /alerts
POST /alerts/{id}/acknowledge
```

Filtros comunes:

```text
from
to
router_id
username
ip
mac
profile
limit
page
```

---

## 22. Tiempo real

Polling inicial:

```text
dashboard: 15 s
usuarios activos: 10 s
detalle usuario: 15 s
```

No consultar ClickHouse cada segundo.

Después se puede migrar a:

```text
SSE
WebSockets
```

---

## 23. Jobs

Crear:

```text
PollMikrotikHotspotActive
SyncHotspotUsers
SyncDhcpLeases
PollRouterMetrics
AggregateHourlyTraffic
AggregateDailyTraffic
ResolveUnknownDestinations
EvaluateAlerts
CleanupOldOperationalData
```

Frecuencias sugeridas:

```text
Hotspot Active:       15 s
DHCP leases:          1 min
Router metrics:       1 min
Hourly aggregates:    5 min
Daily aggregates:     30 min
DNS/PTR:              queue
Alerts:               1 min
```

---

## 24. Alertas iniciales

Soportar reglas como:

```text
usuario > X GB/día
usuario > X GB/mes
usuario > X Mbps
usuario conectado > X horas
nueva MAC para usuario
más de X sesiones
cambio frecuente de IP
CPU MikroTik > X%
RAM libre < X
Akvorado dejó de recibir IPFIX
RouterOS API no responde
SNMP no responde
```

---

## 25. Seguridad

### MikroTik

Usar usuario API dedicado.

Permisos mínimos:

```text
read
api
```

Restringir origen:

```text
192.168.8.8/32
```

Nunca usar cuenta admin del router.

### Aplicación

- secretos en `.env`;
- password cifrado;
- no registrar credenciales;
- autenticación;
- roles;
- rate limiting;
- auditoría;
- CSRF donde aplique;
- consultas ClickHouse read-only.

Roles:

```text
Administrador
Operador
Solo lectura
```

---

## 26. Auditoría

Guardar:

```text
usuario del sistema
acción
recurso
fecha/hora
IP origen
payload reducido
```

Ejemplos:

```text
consultó usuario
exportó reporte
reconoció alerta
modificó configuración
```

---

## 27. Docker Compose

Servicios propios:

```text
nginx
backend
frontend
postgres
redis
queue-worker
scheduler
```

No crear otro ClickHouse.

Usar el ClickHouse existente de Akvorado.

---

## 28. Variables de entorno

Ejemplo:

```env
APP_NAME=MikroTik Analytics
APP_URL=http://192.168.8.8:8091

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=mikrotik_analytics
DB_USERNAME=mikrotik
DB_PASSWORD=

REDIS_HOST=redis

MIKROTIK_HOST=192.168.10.1
MIKROTIK_API_PORT=8728
MIKROTIK_USERNAME=
MIKROTIK_PASSWORD=

MIKROTIK_LOCAL_NETWORK=192.168.8.0/22
MIKROTIK_WAN_ADDRESS=192.168.1.29

HOTSPOT_POLL_SECONDS=15
HOTSPOT_MISSING_THRESHOLD=3

AKVORADO_CLICKHOUSE_HOST=
AKVORADO_CLICKHOUSE_PORT=
AKVORADO_CLICKHOUSE_DATABASE=
AKVORADO_CLICKHOUSE_USERNAME=
AKVORADO_CLICKHOUSE_PASSWORD=
```

---

## 29. Health checks

Crear endpoint:

```text
GET /api/v1/health
```

Debe comprobar:

```text
PostgreSQL
Redis
MikroTik API
ClickHouse/Akvorado
queue
scheduler
```

Respuesta ejemplo:

```json
{
  "status": "ok",
  "services": {
    "postgres": "ok",
    "redis": "ok",
    "mikrotik": "ok",
    "clickhouse": "ok"
  }
}
```

---

## 30. Tests obligatorios

### DHCP reutiliza IP

Caso:

```text
10:00-12:00
usuario A -> 192.168.9.50

12:30-14:00
usuario B -> 192.168.9.50
```

El flujo de 11:00 pertenece a A.

El flujo de 13:00 pertenece a B.

---

### Sesión abierta

```text
started_at <= timestamp
ended_at = null
```

Debe poder correlacionarse si no hay conflicto.

---

### Ambigüedad

Si dos sesiones coinciden con la misma IP y hora:

```text
confidence = ambiguous
```

No atribuir automáticamente.

---

### Resolución cliente

Probar:

```text
SrcAddr local
DstAddr local
SrcAddrNAT local
DstAddrNAT local
sin IP local
```

---

### Upload/download

Validar ambos sentidos.

---

## 31. Rendimiento

No hacer:

```sql
SELECT * FROM flows
```

Usar:

```text
rango temporal
columnas necesarias
GROUP BY
LIMIT
```

ClickHouse debe ejecutar agregaciones pesadas.

PostgreSQL debe almacenar principalmente:

```text
sesiones
configuración
usuarios
alertas
agregados
```

---

## 32. Retención

Definir políticas configurables.

Ejemplo inicial:

```text
hotspot_sessions: sin purga automática
router_metrics raw: 90 días
hourly aggregates: 1 año
daily aggregates: indefinido
audit logs: 1 año
```

Los flujos dependen de la retención de Akvorado.

---

## 33. Exportaciones

Soportar:

```text
CSV
XLSX
PDF
```

Reportes:

```text
consumo por usuario
sesiones
top usuarios
top destinos
consumo diario
consumo mensual
alertas
```

---

## 34. Fases de implementación

### Fase 1 — Base

- repositorio;
- Docker Compose;
- Laravel;
- Vue 3 + Quasar;
- PostgreSQL;
- login;
- health check.

### Fase 2 — MikroTik

- cliente RouterOS API;
- Hotspot Active;
- usuarios;
- sesiones;
- DHCP leases;
- pantalla usuarios activos.

### Fase 3 — Akvorado

- introspección ClickHouse;
- conexión read-only;
- identificar tabla real;
- `ClientAddressResolver`;
- consultas por IP y hora.

### Fase 4 — Correlación

- `TrafficUserCorrelator`;
- consumo por sesión;
- consumo por usuario;
- upload/download;
- dashboard.

### Fase 5 — Analítica

- ASN;
- destinos;
- países;
- PTR;
- rankings;
- timeline;
- picos.

### Fase 6 — Alertas y reportes

- reglas;
- alertas;
- CSV;
- Excel;
- PDF.

---

## 35. Primer entregable que debe construir el agente

NO implementar todo al mismo tiempo.

El primer entregable debe contener:

1. Docker Compose.
2. Laravel 12.
3. PostgreSQL.
4. Vue 3 + Quasar.
5. Endpoint `/health`.
6. Cliente RouterOS API.
7. Colector `/ip/hotspot/active`.
8. Migraciones:
   - routers;
   - hotspot_users;
   - hotspot_sessions.
9. Pantalla de usuarios activos.
10. Histórico de sesiones.

Flujo que debe quedar probado:

```text
MikroTik
   ->
RouterOS API
   ->
Laravel
   ->
PostgreSQL
   ->
Vue/Quasar
```

---

## 36. Criterios de aceptación del primer entregable

Al abrir:

```text
http://192.168.8.8:8091
```

mostrar:

```text
Usuario   IP              MAC                Perfil      Uptime     Estado
juan      192.168.9.189   AA:BB:CC:DD:EE:01 Internet    02:13:10   Online
pedro     192.168.9.238   AA:BB:CC:DD:EE:02 WhatsApp    00:45:21   Online
```

Al desconectarse:

- desaparece de activos;
- la sesión queda en historial;
- `ended_at` se registra.

Si vuelve con otra IP:

- crear nueva sesión;
- mantener intacta la anterior.

---

## 37. Reglas para el agente

1. No modificar configuración productiva del MikroTik sin aprobación explícita.
2. No escribir en ClickHouse de Akvorado.
3. No modificar tablas de Akvorado.
4. No asumir nombres de tablas/columnas de Akvorado.
5. No asumir que `SrcAddr` siempre es el cliente.
6. No correlacionar usuario solo por IP.
7. No perder histórico cuando DHCP cambie IP.
8. No guardar passwords en logs.
9. No capturar payload.
10. No inspeccionar contenido privado.
11. No afirmar URLs exactas basándose solo en IPFIX.
12. Toda métrica debe indicar fuente:
    - MikroTik;
    - Akvorado/IPFIX;
    - calculada.
13. Añadir tests.
14. Añadir README.
15. Añadir healthchecks.
16. Añadir migraciones.
17. Mantener código modular.

---

## 38. Diseño de servicios backend

Crear servicios separados:

```text
MikrotikApiClient
HotspotCollector
DhcpLeaseCollector
RouterMetricsCollector
AkvoradoFlowRepository
ClientAddressResolver
TrafficUserCorrelator
TrafficAggregationService
ReverseDnsResolver
AlertEvaluator
```

Evitar controladores con lógica de negocio pesada.

---

## 39. Observabilidad de la propia aplicación

Añadir logs estructurados para:

```text
API MikroTik conectada/desconectada
cantidad de usuarios activos
sesiones creadas
sesiones cerradas
errores ClickHouse
tiempo consultas
jobs fallidos
alertas generadas
```

Métricas internas futuras:

```text
collector_last_success
collector_duration_ms
clickhouse_query_duration
active_sessions
unattributed_flows
ambiguous_flows
```

---

## 40. Métrica crítica: tráfico no atribuido

Mostrar siempre:

```text
tráfico total
tráfico atribuido
tráfico no atribuido
porcentaje atribuido
```

Ejemplo:

```text
Tráfico total:       100 GB
Atribuido:            94 GB
No atribuido:          6 GB
Cobertura:             94%
```

Esto evita dar una falsa impresión de precisión.

---

## 41. Métrica crítica: confianza

Cuando se relacione un flujo con un usuario:

```text
exact
probable
ambiguous
unknown
```

En el MVP usar solo:

```text
exact
ambiguous
unknown
```

No inventar heurísticas sin documentarlas.

---

## 42. Dashboard de calidad de datos

Crear página administrativa con:

```text
Última consulta MikroTik
Último flujo IPFIX
Usuarios activos
Sesiones abiertas
Flujos no atribuidos
Flujos ambiguos
Errores API
Errores ClickHouse
```

Esto facilita diagnóstico.

---

## 43. Resultado final esperado

El sistema debe permitir seleccionar un usuario y mostrar:

```text
Usuario: juan
Estado: Online
IP actual: 192.168.9.189
MAC: AA:BB:CC:DD:EE:FF
Perfil: Internet
Conectado desde: 08:15

Hoy
-------------------------
Descarga: 12.8 GB
Subida:    1.3 GB
Total:    14.1 GB
Pico:       87 Mbps

Principales destinos
-------------------------
Apple CDN       4.8 GB
Meta            2.3 GB
Google          1.9 GB
Cloudflare      850 MB

Sesiones
-------------------------
08:15 - actual       192.168.9.189
ayer 09:03 - 17:20   192.168.8.77
```

La plataforma debe ser capaz de conservar la atribución histórica incluso cuando DHCP reutilice direcciones IP.

---

# Instrucción final para el agente

Comenzar únicamente por la **Fase 1 y Fase 2**.

Antes de programar la integración con Akvorado:

1. levantar el stack;
2. conectar RouterOS API;
3. leer `/ip/hotspot/active`;
4. guardar correctamente usuarios y sesiones;
5. demostrar en navegador usuarios activos;
6. demostrar que una desconexión cierra la sesión;
7. demostrar que un cambio de IP crea una nueva sesión;
8. añadir tests.

No avanzar a ClickHouse hasta que esta parte esté estable.

Al terminar, entregar:

```text
docker-compose.yml
README.md
.env.example
migraciones
modelos
servicios
jobs
API
frontend
tests
capturas o evidencia de funcionamiento
```
