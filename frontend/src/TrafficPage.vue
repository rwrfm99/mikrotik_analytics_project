<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Bar, Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS, CategoryScale, LinearScale, BarElement,
  ArcElement, Title, Tooltip, Legend
} from 'chart.js'
import { siteLabel } from './siteLabel.js'

ChartJS.register(CategoryScale, LinearScale, BarElement, ArcElement, Title, Tooltip, Legend)

// ── helpers ───────────────────────────────────────────────────────────────────
const mb   = n  => (Number(n || 0) / 1_000_000).toFixed(2)
const date = ms => new Date(Number(ms)).toLocaleString()

const columns = [
  { name: 'ptr',      label: 'Sitio',               field: r => siteLabel(r),               align: 'left'  },
  { name: 'ip',       label: 'IP destino',           field: 'ip',                             align: 'left'  },
  { name: 'download', label: '↓ Descarga MB',        field: r => mb(r.download_bytes),        align: 'right' },
  { name: 'upload',   label: '↑ Subida MB',          field: r => mb(r.upload_bytes),          align: 'right' },
  { name: 'total',    label: 'Total MB',             field: r => mb(r.total_bytes),           align: 'right' },
  { name: 'first',    label: 'Primera actividad',    field: r => date(r.first_ms),            align: 'left'  },
  { name: 'last',     label: 'Última actividad',     field: r => date(r.last_ms),             align: 'left'  },
  { name: 'span',     label: 'Intervalo',            field: r => `${Math.round((r.last_ms - r.first_ms)/1000)}s`, align: 'right' },
  { name: 'flows',    label: 'Flujos',               field: 'flows',                          align: 'right' },
]

// ── state ─────────────────────────────────────────────────────────────────────
const users    = ref([])
const selected = ref(null)
const hours    = ref(1)
const report   = ref(null)
const status   = ref(null)
const error    = ref('')
const loading  = ref(false)
let   generation = 0

const intervalOptions = [
  { label: 'Última hora',      value: 1  },
  { label: 'Últimas 6 horas',  value: 6  },
  { label: 'Últimas 24 horas', value: 24 },
]

async function apiFetch(url) {
  const res  = await fetch(url, { signal: AbortSignal.timeout(25000) })
  const body = await res.json()
  if (!res.ok) throw new Error(body.message || 'No se pudieron consultar los datos.')
  return body
}

async function refresh() {
  const token   = ++generation
  loading.value = true
  error.value   = ''
  report.value  = null
  try {
    status.value = await apiFetch('/api/v1/traffic/status')
    if (selected.value) {
      const to   = new Date()
      const from = new Date(to.getTime() - hours.value * 3_600_000)
      const p    = new URLSearchParams({ from: from.toISOString(), to: to.toISOString() })
      const r    = await apiFetch(`/api/v1/traffic/users/${selected.value}?${p}`)
      if (token === generation) report.value = r
    }
  } catch (e) { if (token === generation) error.value = e.message }
  finally      { if (token === generation) loading.value = false }
}

onMounted(async () => {
  try { users.value = await apiFetch('/api/v1/traffic/users') }
  catch { error.value = 'No se pudo cargar la lista de usuarios.' }
  await refresh()
})
onUnmounted(() => { generation++ })

// ── charts ────────────────────────────────────────────────────────────────────
const COLORS = [
  '#009688','#3F51B5','#E91E63','#FF9800','#4CAF50',
  '#9C27B0','#00BCD4','#FF5722','#795548','#607D8B',
]

// Top 10 destinos por bytes — bar horizontal
const topDestChart = computed(() => {
  const top = (report.value?.destinations || []).slice(0, 10)
  return {
    data: {
      labels: top.map(d => siteLabel(d)),
      datasets: [
        { label: '↓ Descarga MB', data: top.map(d => Number(mb(d.download_bytes))), backgroundColor: 'rgba(0,150,136,0.75)', borderRadius: 3 },
        { label: '↑ Subida MB',   data: top.map(d => Number(mb(d.upload_bytes))),   backgroundColor: 'rgba(63,81,181,0.65)',  borderRadius: 3 },
      ],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top' },
        title:  { display: true, text: 'Top 10 destinos por volumen' },
      },
      scales: { x: { beginAtZero: true } },
    },
  }
})

// Doughnut distribución de bytes top 8
const doughnutChart = computed(() => {
  const top = (report.value?.destinations || []).slice(0, 8)
  return {
    data: {
      labels:   top.map(d => siteLabel(d)),
      datasets: [{ data: top.map(d => Number(mb(d.total_bytes))), backgroundColor: COLORS, borderWidth: 2 }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
        title:  { display: true, text: 'Distribución de tráfico (top 8)' },
      },
    },
  }
})

// Calidad de atribución — doughnut
const qualityChart = computed(() => {
  const q = report.value?.quality
  if (!q) return null
  return {
    data: {
      labels:   ['Atribuido', 'Ambiguo', 'Sin atribuir'],
      datasets: [{
        data:            [Number(mb(q.attributed_bytes)), Number(mb(q.ambiguous_bytes)), Number(mb(q.unknown_bytes))],
        backgroundColor: ['#009688', '#FF9800', '#BDBDBD'],
        borderWidth:     2,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12 } },
        title:  { display: true, text: 'Calidad de atribución del router' },
      },
    },
  }
})
</script>

<template>
  <div>
    <!-- page header -->
    <div class="row items-center justify-between q-mb-lg">
      <div>
        <div class="text-h5 text-weight-bold">Tráfico por usuario</div>
        <div class="text-caption text-grey-6">Fuente: IPFIX · ClickHouse · MB decimales (1 MB = 1.000.000 bytes)</div>
      </div>
    </div>

    <!-- alerts -->
    <q-banner v-if="error" class="bg-orange-1 text-orange-10 q-mb-md rounded-borders" role="alert">
      <template #avatar><q-icon name="warning" color="orange" /></template>
      {{ error }}
    </q-banner>
    <q-banner v-if="Math.abs(status?.clock_offset_seconds || 0) > 120"
      class="bg-deep-orange-1 text-deep-orange-10 q-mb-md rounded-borders" role="alert">
      <template #avatar><q-icon name="schedule" color="deep-orange" /></template>
      Desfasaje de reloj: el exportador difiere ~{{ (Math.abs(status.clock_offset_seconds)/3600).toFixed(2) }}h.
      Corrige el reloj del router. No se ajustan timestamps.
    </q-banner>
    <q-banner v-if="status && (!status.flows || !status.receiver?.last_packet)"
      class="bg-blue-1 text-blue-10 q-mb-md rounded-borders">
      <template #avatar><q-icon name="info" color="blue" /></template>
      {{ status.clickhouse === 'ok' ? 'ClickHouse OK.' : 'ClickHouse no disponible.' }}
      {{ status.receiver?.status === 'listening' ? 'Receptor IPFIX escuchando.' : 'Receptor IPFIX no disponible.' }}
      Aún no hay flujos — configura el destino UDP 2055 en MikroTik.
    </q-banner>
    <q-banner v-if="status?.receiver?.dropped || status?.receiver?.malformed"
      class="bg-orange-1 text-orange-10 q-mb-md rounded-borders">
      <template #avatar><q-icon name="warning" color="orange" /></template>
      Cobertura incompleta: {{ status.receiver.dropped }} descartados,
      {{ status.receiver.malformed }} datagramas inválidos,
      {{ status.receiver.decoder?.missing_template_sets || 0 }} sets sin plantilla.
    </q-banner>

    <!-- global status chips -->
    <div v-if="status?.flows" class="row q-gutter-sm q-mb-lg">
      <q-chip dense icon="storage" color="teal-1" text-color="teal-10">
        {{ Number(status.flows).toLocaleString() }} flujos almacenados
      </q-chip>
      <q-chip dense icon="cloud_download" color="indigo-1" text-color="indigo-10">
        {{ mb(status.bytes) }} MB registrados
      </q-chip>
    </div>

    <!-- filter bar -->
    <q-card flat class="filter-card q-mb-lg">
      <q-card-section class="row q-gutter-md items-center">
        <q-select
          class="col-12 col-sm-5"
          v-model="selected"
          :options="users"
          option-label="username"
          option-value="id"
          emit-value map-options outlined
          label="Usuario Hotspot"
          @update:model-value="refresh"
        />
        <q-select
          class="col-12 col-sm-3"
          v-model="hours"
          :options="intervalOptions"
          emit-value map-options outlined
          label="Intervalo"
          @update:model-value="refresh"
        />
        <q-btn color="teal" icon="search" label="Consultar" :loading="loading" @click="refresh" unelevated />
      </q-card-section>
    </q-card>

    <!-- results -->
    <template v-if="report">

      <!-- KPI row -->
      <div class="row q-col-gutter-md q-mb-lg">
        <div v-for="m in [
          { label:'Descarga',    value: mb(report.download_bytes)+' MB', icon:'cloud_download', color:'teal'   },
          { label:'Subida',      value: mb(report.upload_bytes)+' MB',   icon:'cloud_upload',   color:'indigo' },
          { label:'Total',       value: mb(report.download_bytes + report.upload_bytes)+' MB', icon:'swap_horiz', color:'deep-purple' },
          { label:'Atribución',  value: report.quality.coverage_percent === null ? 'Sin tráfico' : report.quality.coverage_percent+'%', icon:'verified', color: report.quality.coverage_percent >= 80 ? 'teal' : 'orange' },
        ]" :key="m.label" class="col-6 col-md-3">
          <q-card flat class="kpi-card">
            <q-card-section class="q-pa-md">
              <div class="row items-center no-wrap q-gutter-sm">
                <q-icon :name="m.icon" :color="m.color" size="28px" />
                <div>
                  <div class="text-caption text-grey-6">{{ m.label }}</div>
                  <div class="text-h6 text-weight-bold">{{ m.value }}</div>
                </div>
              </div>
            </q-card-section>
          </q-card>
        </div>
      </div>

      <!-- charts row -->
      <div class="row q-col-gutter-md q-mb-lg">
        <div class="col-12 col-lg-6">
          <q-card flat class="chart-card">
            <q-card-section style="height:300px">
              <Bar :data="topDestChart.data" :options="topDestChart.options" />
            </q-card-section>
          </q-card>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <q-card flat class="chart-card">
            <q-card-section style="height:300px">
              <Doughnut :data="doughnutChart.data" :options="doughnutChart.options" />
            </q-card-section>
          </q-card>
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <q-card flat class="chart-card">
            <q-card-section style="height:300px">
              <Doughnut v-if="qualityChart" :data="qualityChart.data" :options="qualityChart.options" />
            </q-card-section>
          </q-card>
        </div>
      </div>

      <!-- quality caption -->
      <p class="text-caption text-grey-6 q-mb-md">
        Router en el intervalo: {{ mb(report.quality.total_bytes) }} MB ·
        {{ mb(report.quality.attributed_bytes) }} MB atribuidos ·
        {{ mb(report.quality.unknown_bytes) }} MB sin atribuir ·
        {{ mb(report.quality.ambiguous_bytes) }} MB ambiguos
      </p>

      <!-- destinations table -->
      <q-card flat class="table-card">
        <q-table
          flat
          :columns="columns"
          :rows="report.destinations"
          row-key="ip"
          :pagination="{ rowsPerPage: 25 }"
          no-data-label="Sin flujos atribuibles a este usuario en el intervalo"
        >
          <template #body-cell-total="{ row }">
            <q-td class="text-right text-weight-medium">{{ mb(row.total_bytes) }}</q-td>
          </template>
        </q-table>
      </q-card>

      <p class="text-caption text-grey-6 q-mt-sm">
        Solo se asignan flujos con un único propietario, timestamps del exportador y sin muestreo.
        El intervalo observado va de primera a última actividad e incluye pausas.
        PTR es DNS inverso — no es la URL visitada. No se inspecciona contenido.
      </p>
    </template>

    <div v-else-if="!selected && !loading" class="text-center q-mt-xl text-grey-5">
      <q-icon name="person_search" size="56px" class="q-mb-md" /><br>
      Selecciona un usuario para consultar sus destinos.
    </div>
  </div>
</template>

<style scoped>
.filter-card, .table-card {
  border: 1px solid rgba(0,0,0,0.07);
  border-radius: 10px;
  background: #fff;
}
.kpi-card {
  border: 1px solid rgba(0,0,0,0.07);
  border-radius: 10px;
  background: #fff;
  transition: box-shadow .2s;
}
.kpi-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.09); }
.chart-card {
  border: 1px solid rgba(0,0,0,0.07);
  border-radius: 10px;
  background: #fff;
  height: 100%;
}
</style>
