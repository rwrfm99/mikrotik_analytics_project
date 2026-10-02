<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Bar, Doughnut, Line } from 'vue-chartjs'
import {
  Chart as ChartJS, CategoryScale, LinearScale, BarElement, ArcElement,
  PointElement, LineElement, Title, Tooltip, Legend, Filler
} from 'chart.js'
import { siteLabel } from './siteLabel.js'

ChartJS.register(CategoryScale, LinearScale, BarElement, ArcElement,
  PointElement, LineElement, Title, Tooltip, Legend, Filler)

// ── helpers ──────────────────────────────────────────────────────────────────
const mb     = n  => (Number(n || 0) / 1_000_000).toFixed(2)
const fmtMb  = n  => `${mb(n)} MB`
const fmtGb  = n  => {
  const bytes = Number(n || 0)
  return bytes >= 1_000_000_000 ? `${(bytes/1_000_000_000).toFixed(2)} GB` : `${mb(bytes)} MB`
}
const fmtNum = n  => Number(n || 0).toLocaleString()
const ago    = ts => {
  if (!ts) return '—'
  const s = Math.round((Date.now() - new Date(ts).getTime()) / 1000)
  if (s < 60)   return `hace ${s}s`
  if (s < 3600) return `hace ${Math.floor(s/60)}m`
  return `hace ${Math.floor(s/3600)}h ${Math.floor(s%3600/60)}m`
}

async function apiFetch(url) {
  const res  = await fetch(url, { signal: AbortSignal.timeout(30000) })
  const body = await res.json()
  if (!res.ok) throw new Error(body.message || 'Error al consultar.')
  return body
}

// ── state ─────────────────────────────────────────────────────────────────────
const hours        = ref(6)
const trafficStatus = ref(null)
const collection   = ref(null)
const sitesData    = ref(null)
const loading      = ref(false)
const error        = ref('')
let   timer        = null
const topUsers     = ref(null)
const intervalOptions = [
  { label: 'Última hora',      value: 1  },
  { label: 'Últimas 6 horas',  value: 6  },
  { label: 'Últimas 24 horas', value: 24 },
]

function buildRange(h) {
  const to   = new Date()
  const from = new Date(to.getTime() - h * 3_600_000)
  return new URLSearchParams({ from: from.toISOString(), to: to.toISOString() })
}

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const [ts, col, sites, tu] = await Promise.all([
      apiFetch('/api/v1/traffic/status'),
      apiFetch('/api/v1/hotspot/collection'),
      apiFetch(`/api/v1/reports/sites?${buildRange(hours.value)}`),
      apiFetch(`/api/v1/reports/top-users?${buildRange(hours.value)}`),
    ])
    trafficStatus.value = ts
    collection.value    = col
    sitesData.value     = sites
    topUsers.value      = tu
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(() => { load(); timer = setInterval(load, 60000) })
onUnmounted(() => clearInterval(timer))

// ── KPI cards ─────────────────────────────────────────────────────────────────
const kpis = computed(() => [
  {
    label: 'Flujos almacenados',
    value: fmtNum(trafficStatus.value?.flows),
    icon:  'swap_horiz',
    color: 'teal',
    sub:   trafficStatus.value?.clickhouse === 'ok' ? 'ClickHouse OK' : 'ClickHouse error',
  },
  {
    label: 'Total registrado',
    value: fmtMb(trafficStatus.value?.bytes),
    icon:  'storage',
    color: 'indigo',
    sub:   `Última recepción ${ago(trafficStatus.value?.last_received)}`,
  },
  {
    label: 'Sesiones abiertas',
    value: fmtNum(collection.value?.open_sessions),
    icon:  'people',
    color: 'green',
    sub:   `${fmtNum(collection.value?.users)} usuarios registrados`,
  },
  {
    label: 'Colector',
    value: collection.value?.status === 'ok' ? 'Activo' : (collection.value?.status || '—'),
    icon:  collection.value?.status === 'ok' ? 'check_circle' : 'error',
    color: collection.value?.status === 'ok' ? 'teal' : 'orange',
    sub:   `Lectura cada ${collection.value?.poll_seconds || 15}s`,
  },
  {
    label: 'Receptor IPFIX',
    value: trafficStatus.value?.receiver?.status === 'listening' ? 'Escuchando' : 'Inactivo',
    icon:  trafficStatus.value?.receiver?.status === 'listening' ? 'sensors' : 'sensors_off',
    color: trafficStatus.value?.receiver?.status === 'listening' ? 'teal' : 'grey',
    sub:   `${fmtNum(trafficStatus.value?.receiver?.dropped || 0)} descartados`,
  },
  {
    label: 'Desfasaje de reloj',
    value: trafficStatus.value
      ? `${Math.round(Math.abs(trafficStatus.value.clock_offset_seconds || 0) / 60)} min`
      : '—',
    icon:  'schedule',
    color: Math.abs(trafficStatus.value?.clock_offset_seconds || 0) > 120 ? 'orange' : 'teal',
    sub:   Math.abs(trafficStatus.value?.clock_offset_seconds || 0) > 120
      ? 'Corrige el reloj del router'
      : 'Sincronizado',
  },
])

// ── Top sitios por bytes (Bar horizontal) ─────────────────────────────────────
const topSitesChart = computed(() => {
  const top = (sitesData.value?.sites || []).slice(0, 15)
  return {
    data: {
      labels: top.map(s => siteLabel(s)),
      datasets: [
        {
          label: 'Descarga MB',
          data:  top.map(s => Number(mb(s.download_bytes))),
          backgroundColor: 'rgba(0,150,136,0.75)',
        },
        {
          label: 'Subida MB',
          data:  top.map(s => Number(mb(s.upload_bytes))),
          backgroundColor: 'rgba(63,81,181,0.65)',
        },
      ],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top' },
        title:  { display: true, text: `Top 15 sitios · últimas ${hours.value}h` },
      },
      scales: { x: { stacked: false }, y: { stacked: false } },
    },
  }
})

// ── Doughnut: distribución de bytes (top 8 sitios) ────────────────────────────
const CHART_COLORS = [
  '#009688','#3F51B5','#E91E63','#FF9800','#4CAF50',
  '#9C27B0','#00BCD4','#FF5722',
]
const doughnutChart = computed(() => {
  const top = (sitesData.value?.sites || []).slice(0, 8)
  return {
    data: {
      labels:   top.map(s => siteLabel(s)),
      datasets: [{
        data:            top.map(s => Number(mb(s.total_bytes))),
        backgroundColor: CHART_COLORS,
        borderWidth: 2,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12 } },
        title:  { display: true, text: 'Distribución de tráfico (top 8)' },
      },
    },
  }
})

// ── Usuarios únicos por sitio (Bar) ───────────────────────────────────────────
const usersPerSiteChart = computed(() => {
  const top = (sitesData.value?.sites || [])
    .slice()
    .sort((a, b) => b.unique_users - a.unique_users)
    .slice(0, 10)
  return {
    data: {
      labels: top.map(s => siteLabel(s)),
      datasets: [{
        label: 'Usuarios únicos',
        data:  top.map(s => s.unique_users),
        backgroundColor: 'rgba(233,30,99,0.7)',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        title:  { display: true, text: 'Sitios con más usuarios distintos' },
      },
      scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
    },
  }
})

// ── Top usuarios por consumo (Bar) ────────────────────────────────────────────
const topUsersChart = computed(() => {
  const list = topUsers.value?.users || []
  return {
    data: {
      labels: list.map(u => u.username),
      datasets: [
        {
          label: '↓ Descarga MB',
          data:  list.map(u => Number(mb(u.download_bytes))),
          backgroundColor: 'rgba(0,150,136,0.75)',
          borderRadius: 4,
        },
        {
          label: '↑ Subida MB',
          data:  list.map(u => Number(mb(u.upload_bytes))),
          backgroundColor: 'rgba(63,81,181,0.65)',
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top' },
        title:  { display: true, text: `Top usuarios por consumo · últimas ${hours.value}h` },
      },
      scales: { x: { stacked: false }, y: { beginAtZero: true } },
    },
  }
})
const flowsChart = computed(() => {
  const top = (sitesData.value?.sites || [])
    .slice()
    .sort((a, b) => b.flows - a.flows)
    .slice(0, 10)
  return {
    data: {
      labels: top.map(s => siteLabel(s)),
      datasets: [{
        label: 'Flujos',
        data:  top.map(s => s.flows),
        backgroundColor: 'rgba(63,81,181,0.7)',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        title:  { display: true, text: 'Sitios con más flujos TCP/UDP' },
      },
      scales: { y: { beginAtZero: true } },
    },
  }
})
</script>

<template>
  <div>
    <!-- header row -->
    <div class="row items-center justify-between q-mb-lg">
      <div>
        <div class="text-h5 text-weight-bold">Dashboard</div>
        <div class="text-caption text-grey-6">Resumen en tiempo real · IPFIX + RouterOS API</div>
      </div>
      <div class="row q-gutter-sm items-center">
        <q-select
          v-model="hours"
          :options="intervalOptions"
          emit-value map-options outlined dense
          label="Intervalo de análisis"
          style="min-width:160px"
          @update:model-value="load"
        />
        <q-btn flat round icon="refresh" color="teal" :loading="loading" @click="load">
          <q-tooltip>Actualizar ahora</q-tooltip>
        </q-btn>
      </div>
    </div>

    <q-banner v-if="error" class="bg-orange-1 text-orange-10 q-mb-md rounded-borders" role="alert">
      <template #avatar><q-icon name="warning" color="orange" /></template>
      {{ error }}
    </q-banner>

    <!-- KPI cards -->
    <div class="row q-col-gutter-md q-mb-lg">
      <div v-for="kpi in kpis" :key="kpi.label" class="col-12 col-sm-6 col-md-4 col-lg-2">
        <q-card flat class="kpi-card full-height">
          <q-card-section class="q-pa-md">
            <div class="row items-center no-wrap q-gutter-sm">
              <q-icon :name="kpi.icon" :color="kpi.color" size="28px" />
              <div class="col">
                <div class="text-caption text-grey-6 ellipsis">{{ kpi.label }}</div>
                <div class="text-h6 text-weight-bold ellipsis">{{ kpi.value }}</div>
                <div class="text-caption text-grey-5 ellipsis">{{ kpi.sub }}</div>
              </div>
            </div>
          </q-card-section>
        </q-card>
      </div>
    </div>

    <!-- charts row 1: bar + doughnut -->
    <div class="row q-col-gutter-md q-mb-lg">
      <div class="col-12 col-lg-7">
        <q-card flat class="chart-card">
          <q-card-section class="q-pb-xs">
            <div class="text-subtitle2 text-weight-medium">Tráfico por sitio</div>
          </q-card-section>
          <q-card-section class="q-pt-xs" style="height:320px">
            <Bar v-if="sitesData" :data="topSitesChart.data" :options="topSitesChart.options" />
            <div v-else-if="loading" class="flex flex-center full-height"><q-spinner color="teal" size="40px"/></div>
            <div v-else class="flex flex-center full-height text-grey-5">Sin datos aún</div>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-lg-5">
        <q-card flat class="chart-card">
          <q-card-section class="q-pb-xs">
            <div class="text-subtitle2 text-weight-medium">Distribución de tráfico</div>
          </q-card-section>
          <q-card-section class="q-pt-xs" style="height:320px">
            <Doughnut v-if="sitesData" :data="doughnutChart.data" :options="doughnutChart.options" />
            <div v-else-if="loading" class="flex flex-center full-height"><q-spinner color="teal" size="40px"/></div>
            <div v-else class="flex flex-center full-height text-grey-5">Sin datos aún</div>
          </q-card-section>
        </q-card>
      </div>
    </div>

    <!-- charts row 2: usuarios + flujos -->
    <div class="row q-col-gutter-md q-mb-lg">
      <div class="col-12 col-md-6">
        <q-card flat class="chart-card">
          <q-card-section class="q-pb-xs">
            <div class="text-subtitle2 text-weight-medium">Usuarios únicos por sitio</div>
          </q-card-section>
          <q-card-section class="q-pt-xs" style="height:280px">
            <Bar v-if="sitesData" :data="usersPerSiteChart.data" :options="usersPerSiteChart.options" />
            <div v-else-if="loading" class="flex flex-center full-height"><q-spinner color="teal" size="40px"/></div>
            <div v-else class="flex flex-center full-height text-grey-5">Sin datos aún</div>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-md-6">
        <q-card flat class="chart-card">
          <q-card-section class="q-pb-xs">
            <div class="text-subtitle2 text-weight-medium">Flujos por sitio</div>
          </q-card-section>
          <q-card-section class="q-pt-xs" style="height:280px">
            <Bar v-if="sitesData" :data="flowsChart.data" :options="flowsChart.options" />
            <div v-else-if="loading" class="flex flex-center full-height"><q-spinner color="teal" size="40px"/></div>
            <div v-else class="flex flex-center full-height text-grey-5">Sin datos aún</div>
          </q-card-section>
        </q-card>
      </div>
    </div>

    <!-- tabla rápida top 10 sitios -->
    <q-card flat class="q-mb-md">
      <q-card-section class="q-pb-xs">
        <div class="text-subtitle2 text-weight-medium">Top 10 sitios · resumen</div>
      </q-card-section>
      <q-table
        flat dense
        :rows="(sitesData?.sites || []).slice(0,10)"
        :columns="[
          { name:'rank',  label:'#',              field: (r,i) => i+1,         align:'right', style:'width:40px' },
          { name:'site',  label:'Sitio',          field: r => siteLabel(r),    align:'left'  },
          { name:'bytes', label:'Total MB',       field: r => mb(r.total_bytes), align:'right'},
          { name:'dl',    label:'↓ MB',           field: r => mb(r.download_bytes), align:'right'},
          { name:'ul',    label:'↑ MB',           field: r => mb(r.upload_bytes),   align:'right'},
          { name:'users', label:'Usuarios',       field: 'unique_users',        align:'right' },
          { name:'flows', label:'Flujos',         field: 'flows',               align:'right' },
        ]"
        row-key="ip"
        :pagination="{ rowsPerPage: 0 }"
        hide-pagination
        no-data-label="Sin datos en el intervalo seleccionado"
      >
        <template #body-cell-rank="{ rowIndex }">
          <q-td class="text-right text-grey-5 text-caption">{{ rowIndex + 1 }}</q-td>
        </template>
        <template #body-cell-bytes="{ row }">
          <q-td class="text-right text-weight-medium">{{ mb(row.total_bytes) }}</q-td>
        </template>
      </q-table>
    </q-card>

    <!-- ═══ top usuarios ════════════════════════════════════════════════════ -->

    <!-- card consumo total -->
    <div class="row q-col-gutter-md q-mb-lg q-mt-md">
      <div class="col-12 col-sm-6 col-md-3">
        <q-card flat class="kpi-card total-card">
          <q-card-section class="q-pa-lg text-center">
            <q-icon name="data_usage" color="teal" size="40px" class="q-mb-sm" />
            <div class="text-caption text-grey-6">Consumo total atribuido</div>
            <div class="text-h4 text-weight-bold text-teal q-mt-xs">
              {{ fmtGb(topUsers?.total_bytes) }}
            </div>
            <div class="text-caption text-grey-5 q-mt-xs">últimas {{ hours }}h · todos los usuarios</div>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-sm-6 col-md-3">
        <q-card flat class="kpi-card">
          <q-card-section class="q-pa-lg text-center">
            <q-icon name="emoji_events" color="orange" size="40px" class="q-mb-sm" />
            <div class="text-caption text-grey-6">Mayor consumidor</div>
            <div class="text-h6 text-weight-bold q-mt-xs ellipsis">
              {{ topUsers?.users?.[0]?.username || '—' }}
            </div>
            <div class="text-caption text-teal q-mt-xs">
              {{ topUsers?.users?.[0] ? fmtGb(topUsers.users[0].total_bytes) : '—' }}
            </div>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-sm-6 col-md-3">
        <q-card flat class="kpi-card">
          <q-card-section class="q-pa-lg text-center">
            <q-icon name="cloud_download" color="indigo" size="40px" class="q-mb-sm" />
            <div class="text-caption text-grey-6">Total descargado</div>
            <div class="text-h5 text-weight-bold text-indigo q-mt-xs">
              {{ fmtGb((topUsers?.users || []).reduce((a,u) => a + u.download_bytes, 0)) }}
            </div>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-sm-6 col-md-3">
        <q-card flat class="kpi-card">
          <q-card-section class="q-pa-lg text-center">
            <q-icon name="cloud_upload" color="pink" size="40px" class="q-mb-sm" />
            <div class="text-caption text-grey-6">Total subido</div>
            <div class="text-h5 text-weight-bold text-pink q-mt-xs">
              {{ fmtGb((topUsers?.users || []).reduce((a,u) => a + u.upload_bytes, 0)) }}
            </div>
          </q-card-section>
        </q-card>
      </div>
    </div>

    <!-- chart + tabla de top usuarios -->
    <div class="row q-col-gutter-md q-mb-lg">
      <div class="col-12 col-lg-7">
        <q-card flat class="chart-card">
          <q-card-section class="q-pb-xs">
            <div class="text-subtitle2 text-weight-medium">Top usuarios por consumo</div>
          </q-card-section>
          <q-card-section class="q-pt-xs" style="height:320px">
            <Bar v-if="topUsers?.users?.length" :data="topUsersChart.data" :options="topUsersChart.options" />
            <div v-else-if="loading" class="flex flex-center full-height"><q-spinner color="teal" size="40px"/></div>
            <div v-else class="flex flex-center full-height text-grey-5">Sin datos aún</div>
          </q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-lg-5">
        <q-card flat class="chart-card">
          <q-card-section class="q-pb-xs">
            <div class="text-subtitle2 text-weight-medium">Ranking de consumo</div>
          </q-card-section>
          <q-table
            flat dense
            :rows="topUsers?.users || []"
            :columns="[
              { name:'pos',      label:'#',      field:(r,i)=>i+1,              align:'right', style:'width:32px' },
              { name:'username', label:'Usuario', field:'username',              align:'left'  },
              { name:'total',    label:'Total',   field:r=>fmtGb(r.total_bytes), align:'right' },
              { name:'dl',       label:'↓',       field:r=>fmtGb(r.download_bytes), align:'right' },
              { name:'ul',       label:'↑',       field:r=>fmtGb(r.upload_bytes),   align:'right' },
              { name:'flows',    label:'Flujos',  field:'flows',                 align:'right' },
            ]"
            row-key="user_id"
            :pagination="{ rowsPerPage: 0 }"
            hide-pagination
            no-data-label="Sin datos en el intervalo seleccionado"
          >
            <template #body-cell-pos="{ rowIndex }">
              <q-td class="text-right">
                <q-badge
                  :color="rowIndex === 0 ? 'orange' : rowIndex === 1 ? 'grey-5' : rowIndex === 2 ? 'brown-4' : 'grey-3'"
                  :text-color="rowIndex < 3 ? 'white' : 'grey-7'"
                  :label="rowIndex + 1"
                />
              </q-td>
            </template>
            <template #body-cell-total="{ row }">
              <q-td class="text-right text-weight-medium text-teal-8">{{ fmtGb(row.total_bytes) }}</q-td>
            </template>
          </q-table>
        </q-card>
      </div>
    </div>
  </div>
</template>

<style scoped>
.kpi-card {
  border: 1px solid rgba(0,0,0,0.08);
  border-radius: 10px;
  transition: box-shadow .2s;
}
.kpi-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.10); }
.total-card {
  background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 100%);
  border: 1px solid #80cbc4 !important;
}
.chart-card {
  border: 1px solid rgba(0,0,0,0.08);
  border-radius: 10px;
  height: 100%;
}
</style>
