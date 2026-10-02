<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const users = ref([]), selected = ref(null), hours = ref(1), report = ref(null), status = ref(null)
const error = ref(''), loading = ref(false)
let timer, generation = 0
const mb = n => (Number(n || 0) / 1000000).toFixed(2)
const date = ms => new Date(Number(ms)).toLocaleString()
const columns = [
  { name: 'ip', label: 'IP destino', field: 'ip', align: 'left' },
  { name: 'ptr', label: 'Nombre DNS (PTR)', field: r => r.ptr || ({ pending: 'Pendiente', not_found: 'Sin registro PTR', error: 'No disponible', private: 'IP privada' }[r.dns_status] || '—'), align: 'left' },
  { name: 'download', label: 'Descarga · MB', field: r => mb(r.download_bytes), align: 'right' },
  { name: 'upload', label: 'Subida · MB', field: r => mb(r.upload_bytes), align: 'right' },
  { name: 'total', label: 'Total · MB', field: r => mb(r.total_bytes), align: 'right' },
  { name: 'first', label: 'Primera actividad', field: r => date(r.first_ms), align: 'left' },
  { name: 'last', label: 'Última actividad', field: r => date(r.last_ms), align: 'left' },
  { name: 'span', label: 'Intervalo observado', field: r => `${Math.round((r.last_ms - r.first_ms) / 1000)} s`, align: 'right' },
  { name: 'flows', label: 'Flujos', field: 'flows', align: 'right' },
]

async function json(url) {
  const response = await fetch(url, { signal: AbortSignal.timeout(25000) })
  const body = await response.json()
  if (!response.ok) throw new Error(body.message || 'No se pudieron consultar los datos.')
  return body
}
async function refresh() {
  const token = ++generation
  loading.value = true
  error.value = ''
  report.value = null
  try {
    status.value = await json('/api/v1/traffic/status')
    if (selected.value) {
      const to = new Date(), from = new Date(to.getTime() - hours.value * 3600000)
      const params = new URLSearchParams({ from: from.toISOString(), to: to.toISOString() })
      const result = await json(`/api/v1/traffic/users/${selected.value}?${params}`)
      if (token === generation) report.value = result
    }
  } catch (e) { if (token === generation) error.value = e.message }
  finally { if (token === generation) loading.value = false }
}
onMounted(async () => {
  try { users.value = await json('/api/v1/traffic/users') } catch { error.value = 'No se pudo cargar la lista de usuarios.' }
  await refresh()
  timer = setInterval(() => { if (!loading.value) refresh() }, 30000)
})
onUnmounted(() => { generation++; clearInterval(timer) })
</script>

<template>
  <section>
    <h1 class="text-h4 q-mt-md">Tráfico por usuario</h1>
    <p class="text-grey-8">Fuente: IPFIX y ClickHouse propios · MB decimales (1 MB = 1.000.000 bytes).</p>
    <q-banner v-if="error" class="bg-orange-1 text-orange-10 q-mb-md" role="alert">{{ error }}</q-banner>
    <q-banner v-if="Math.abs(status?.clock_offset_seconds || 0) > 120" class="bg-orange-1 text-orange-10 q-mb-md" role="alert">
      El reloj del exportador difiere de la PC aproximadamente {{ (Math.abs(status.clock_offset_seconds) / 3600).toFixed(2) }} horas.
      Corrige la fecha y hora del router para correlacionar sus flujos con las sesiones. No se ajustan timestamps ni se inventan atribuciones.
    </q-banner>
    <p v-if="status?.flows" class="text-grey-8">{{ status.flows }} flujos almacenados · {{ mb(status.bytes) }} MB registrados desde el inicio de la recepción.</p>
    <q-banner v-if="status && (!status.flows || !status.receiver?.last_packet)" class="bg-blue-1 text-blue-10 q-mb-md">
      {{ status.clickhouse === 'ok' ? 'ClickHouse preparado.' : 'ClickHouse no disponible.' }}
      {{ status.receiver?.status === 'listening' ? 'Receptor IPFIX escuchando.' : 'Receptor IPFIX no disponible.' }}
      Aún no hay flujos recibidos. Configura el destino UDP 2055 de esta PC en MikroTik.
    </q-banner>
    <q-banner v-if="status?.receiver?.storage_error || status?.receiver?.dropped || status?.receiver?.malformed || status?.receiver?.decoder?.missing_template_sets" class="bg-orange-1 text-orange-10 q-mb-md">
      Cobertura incompleta del receptor: {{ status.receiver.dropped }} descartados,
      {{ status.receiver.malformed }} datagramas inválidos y {{ status.receiver.decoder?.missing_template_sets || 0 }} conjuntos sin plantilla.
    </q-banner>
    <q-card flat bordered class="q-mb-lg"><q-card-section class="row q-gutter-md items-center">
      <q-select class="col-12 col-sm-5" v-model="selected" :options="users" option-label="username" option-value="id" emit-value map-options outlined label="Usuario Hotspot" @update:model-value="refresh" />
      <q-select class="col-12 col-sm-3" v-model="hours" :options="[{label:'Última hora',value:1},{label:'Últimas 6 horas',value:6},{label:'Últimas 24 horas',value:24}]" emit-value map-options outlined label="Intervalo" @update:model-value="refresh" />
      <q-btn color="teal" label="Consultar" :loading="loading" @click="refresh" />
    </q-card-section></q-card>
    <template v-if="report">
      <div class="row q-col-gutter-md q-mb-md">
        <div v-for="metric in [{label:'Descarga del usuario', value:mb(report.download_bytes)+' MB'},{label:'Subida del usuario',value:mb(report.upload_bytes)+' MB'},{label:'Cobertura de atribución del router',value:report.quality.coverage_percent === null ? 'Sin tráfico' : report.quality.coverage_percent+'%'}]" :key="metric.label" class="col-12 col-md-4">
          <q-card flat bordered><q-card-section><div class="text-grey-7">{{ metric.label }}</div><div class="text-h5">{{ metric.value }}</div></q-card-section></q-card>
        </div>
      </div>
      <p class="text-caption">Router en el intervalo: {{ mb(report.quality.total_bytes) }} MB registrados · {{ mb(report.quality.attributed_bytes) }} MB atribuidos · {{ mb(report.quality.unknown_bytes) }} MB sin atribuir · {{ mb(report.quality.ambiguous_bytes) }} MB ambiguos.</p>
      <q-table flat bordered :columns="columns" :rows="report.destinations" row-key="ip" :pagination="{rowsPerPage:25}" no-data-label="Sin flujos atribuibles a este usuario en el intervalo" />
    </template>
    <p v-else-if="!selected" class="text-grey-7">Selecciona un usuario para consultar sus destinos.</p>
    <p class="text-caption text-grey-7 q-mt-lg">Se cuentan los flujos finalizados en el intervalo. El tiempo mostrado va de la primera a la última actividad: puede incluir pausas y no equivale al tiempo navegando. PTR es el nombre DNS inverso de una IP, no una URL visitada. Solo se asignan flujos con un único propietario durante todo el intervalo y timestamps válidos. Los flujos ambiguos, muestreados o sin tiempo fiable quedan sin atribuir. No se inspecciona contenido.</p>
  </section>
</template>
