<script setup>
import { ref, computed, onMounted } from 'vue'
import { Bar, Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS, CategoryScale, LinearScale, BarElement,
  ArcElement, Title, Tooltip, Legend
} from 'chart.js'
import { siteLabel } from './siteLabel.js'

ChartJS.register(CategoryScale, LinearScale, BarElement, ArcElement, Title, Tooltip, Legend)

// ── helpers ───────────────────────────────────────────────────────────────────
const mb  = n  => (Number(n || 0) / 1_000_000).toFixed(2)
const dur = ms => {
  const s = Math.round(Number(ms || 0) / 1000)
  if (s < 60)   return `${s}s`
  if (s < 3600) return `${Math.floor(s/60)}m ${s%60}s`
  return `${Math.floor(s/3600)}h ${Math.floor(s%3600/60)}m`
}
const date = ms => new Date(Number(ms)).toLocaleString()

const COLORS = [
  '#009688','#3F51B5','#E91E63','#FF9800','#4CAF50',
  '#9C27B0','#00BCD4','#FF5722','#795548','#607D8B',
]

async function apiFetch(url) {
  const res  = await fetch(url, { signal: AbortSignal.timeout(30000) })
  const body = await res.json()
  if (!res.ok) throw new Error(body.message || 'Error al consultar el servidor.')
  return body
}

// ── shared ────────────────────────────────────────────────────────────────────
const tab   = ref('user')
const hours = ref(1)
const users = ref([])
const error = ref('')

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

onMounted(async () => {
  try { users.value = await apiFetch('/api/v1/traffic/users') }
  catch { error.value = 'No se pudo cargar la lista de usuarios.' }
})

// ── reporte por usuario ───────────────────────────────────────────────────────
const selectedUser = ref(null)
const userReport   = ref(null)
const userLoading  = ref(false)
const userError    = ref('')

const userColumns = [
  { name: 'ptr',      label: 'Sitio',            field: r => siteLabel(r),        align: 'left'  },
  { name: 'ip',       label: 'IP',               field: 'ip',                     align: 'left'  },
  { name: 'download', label: '↓ MB',             field: r => mb(r.download_bytes), align: 'right' },
  { name: 'upload',   label: '↑ MB',             field: r => mb(r.upload_bytes),   align: 'right' },
  { name: 'total',    label: 'Total MB',          field: r => mb(r.total_bytes),    align: 'right' },
  { name: 'duration', label: 'Tiempo activo',     field: r => dur(r.duration_ms),   align: 'right' },
  { name: 'flows',    label: 'Flujos',            field: 'flows',                  align: 'right' },
  { name: 'first',    label: 'Primera actividad', field: r => date(r.first_ms),    align: 'left'  },
  { name: 'last',     label: 'Última actividad',  field: r => date(r.last_ms),     align: 'left'  },
]

async function fetchUserReport() {
  if (!selectedUser.value) return
  userLoading.value = true
  userError.value   = ''
  userReport.value  = null
  try {
    userReport.value = await apiFetch(`/api/v1/reports/users/${selectedUser.value}?${buildRange(hours.value)}`)
  } catch (e) { userError.value = e.message }
  finally      { userLoading.value = false }
}

// charts — reporte por usuario
const userBarChart = computed(() => {
  const top = (userReport.value?.destinations || []).slice(0, 12)
  return {
    data: {
      labels: top.map(d => siteLabel(d)),
      datasets: [
        { label: '↓ MB', data: top.map(d => Number(mb(d.download_bytes))), backgroundColor: 'rgba(0,150,136,0.75)',  borderRadius: 3 },
        { label: '↑ MB', data: top.map(d => Number(mb(d.upload_bytes))),   backgroundColor: 'rgba(63,81,181,0.65)',  borderRadius: 3 },
      ],
    },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top' }, title: { display: true, text: 'Top 12 sitios por volumen' } },
      scales: { x: { beginAtZero: true } },
    },
  }
})

const userTimeChart = computed(() => {
  const top = (userReport.value?.destinations || [])
    .filter(d => d.duration_ms > 0)
    .sort((a, b) => b.duration_ms - a.duration_ms)
    .slice(0, 10)
  return {
    data: {
      labels: top.map(d => siteLabel(d)),
      datasets: [{
        label: 'Tiempo activo (s)',
        data:  top.map(d => Math.round(d.duration_ms / 1000)),
        backgroundColor: 'rgba(233,30,99,0.7)',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, title: { display: true, text: 'Top 10 sitios por tiempo activo' } },
      scales: { y: { beginAtZero: true } },
    },
  }
})

const userDoughnut = computed(() => {
  const top = (userReport.value?.destinations || []).slice(0, 8)
  return {
    data: {
      labels: top.map(d => siteLabel(d)),
      datasets: [{ data: top.map(d => Number(mb(d.total_bytes))), backgroundColor: COLORS, borderWidth: 2 }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 11, font: { size: 11 } } },
        title:  { display: true, text: 'Distribución (top 8)' },
      },
    },
  }
})

// ── reporte sitios globales ───────────────────────────────────────────────────
const sitesReport  = ref(null)
const sitesLoading = ref(false)
const sitesError   = ref('')

const sitesColumns = [
  { name: 'rank',     label: '#',             field: (r,i) => i+1,              align: 'right', style:'width:40px' },
  { name: 'ptr',      label: 'Sitio',         field: r => siteLabel(r),         align: 'left'  },
  { name: 'ip',       label: 'IP',            field: 'ip',                      align: 'left'  },
  { name: 'total',    label: 'Total MB',      field: r => mb(r.total_bytes),    align: 'right' },
  { name: 'download', label: '↓ MB',          field: r => mb(r.download_bytes), align: 'right' },
  { name: 'upload',   label: '↑ MB',          field: r => mb(r.upload_bytes),   align: 'right' },
  { name: 'users',    label: 'Usuarios',      field: 'unique_users',            align: 'right' },
  { name: 'duration', label: 'Tiempo acum.',  field: r => dur(r.duration_ms),   align: 'right' },
  { name: 'flows',    label: 'Flujos',        field: 'flows',                   align: 'right' },
]

async function fetchSitesReport() {
  sitesLoading.value = true
  sitesError.value   = ''
  sitesReport.value  = null
  try {
    sitesReport.value = await apiFetch(`/api/v1/reports/sites?${buildRange(hours.value)}`)
  } catch (e) { sitesError.value = e.message }
  finally      { sitesLoading.value = false }
}

// charts — sitios globales
const sitesBarChart = computed(() => {
  const top = (sitesReport.value?.sites || []).slice(0, 15)
  return {
    data: {
      labels: top.map(s => siteLabel(s)),
      datasets: [
        { label: '↓ MB', data: top.map(s => Number(mb(s.download_bytes))), backgroundColor: 'rgba(0,150,136,0.75)', borderRadius: 3 },
        { label: '↑ MB', data: top.map(s => Number(mb(s.upload_bytes))),   backgroundColor: 'rgba(63,81,181,0.65)', borderRadius: 3 },
      ],
    },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top' }, title: { display: true, text: 'Top 15 sitios globales por volumen' } },
      scales: { x: { beginAtZero: true } },
    },
  }
})

const sitesUsersChart = computed(() => {
  const top = (sitesReport.value?.sites || [])
    .slice().sort((a,b) => b.unique_users - a.unique_users).slice(0, 10)
  return {
    data: {
      labels: top.map(s => siteLabel(s)),
      datasets: [{
        label: 'Usuarios únicos',
        data:  top.map(s => s.unique_users),
        backgroundColor: COLORS,
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, title: { display: true, text: 'Sitios con más usuarios distintos' } },
      scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
    },
  }
})

const sitesDoughnut = computed(() => {
  const top = (sitesReport.value?.sites || []).slice(0, 8)
  return {
    data: {
      labels: top.map(s => siteLabel(s)),
      datasets: [{ data: top.map(s => Number(mb(s.total_bytes))), backgroundColor: COLORS, borderWidth: 2 }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 11, font: { size: 11 } } },
        title:  { display: true, text: 'Distribución global (top 8)' },
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
        <div class="text-h5 text-weight-bold">Reportes</div>
        <div class="text-caption text-grey-6">
          Flujos IPFIX atribuidos · MB decimales · tiempo activo solo con timestamps del exportador
        </div>
      </div>
    </div>

    <q-banner v-if="error" class="bg-orange-1 text-orange-10 q-mb-md rounded-borders" role="alert">
      <template #avatar><q-icon name="warning" color="orange" /></template>
      {{ error }}
    </q-banner>

    <!-- interval + tabs -->
    <q-card flat class="filter-card q-mb-lg">
      <q-card-section class="row q-gutter-md items-center">
        <q-tabs v-model="tab" active-color="teal" dense class="col-auto">
          <q-tab name="user"  label="Por usuario"     icon="person"  />
          <q-tab name="sites" label="Sitios globales" icon="public"  />
        </q-tabs>
        <q-space />
        <q-select
          v-model="hours"
          :options="intervalOptions"
          emit-value map-options outlined dense
          label="Intervalo"
          style="min-width:150px"
        />
      </q-card-section>
    </q-card>

    <!-- ═══════════════════ REPORTE POR USUARIO ═══════════════════════════════ -->
    <div v-if="tab === 'user'">

      <!-- user selector -->
      <q-card flat class="filter-card q-mb-lg">
        <q-card-section class="row q-gutter-md items-center">
          <q-select
            class="col-12 col-sm-5"
            v-model="selectedUser"
            :options="users"
            option-label="username"
            option-value="id"
            emit-value map-options outlined
            label="Usuario Hotspot"
          />
          <q-btn
            color="teal" icon="bar_chart" label="Generar reporte" unelevated
            :loading="userLoading" :disable="!selectedUser"
            @click="fetchUserReport"
          />
        </q-card-section>
      </q-card>

      <q-banner v-if="userError" class="bg-orange-1 text-orange-10 q-mb-md rounded-borders" role="alert">
        <template #avatar><q-icon name="warning" color="orange" /></template>
        {{ userError }}
      </q-banner>

      <template v-if="userReport">
        <!-- KPIs -->
        <div class="row q-col-gutter-md q-mb-lg">
          <div v-for="m in [
            { label:'Total descargado',  value: mb(userReport.download_bytes)+' MB', icon:'cloud_download', color:'teal'        },
            { label:'Total subido',      value: mb(userReport.upload_bytes)+' MB',   icon:'cloud_upload',   color:'indigo'      },
            { label:'Total transferido', value: mb(userReport.total_bytes)+' MB',    icon:'swap_horiz',     color:'deep-purple' },
            { label:'Sitios visitados',  value: userReport.destinations.length,      icon:'language',       color:'pink'        },
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

        <!-- charts -->
        <div class="row q-col-gutter-md q-mb-lg">
          <div class="col-12 col-lg-6">
            <q-card flat class="chart-card">
              <q-card-section style="height:320px">
                <Bar :data="userBarChart.data" :options="userBarChart.options" />
              </q-card-section>
            </q-card>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <q-card flat class="chart-card">
              <q-card-section style="height:320px">
                <Doughnut :data="userDoughnut.data" :options="userDoughnut.options" />
              </q-card-section>
            </q-card>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <q-card flat class="chart-card">
              <q-card-section style="height:320px">
                <Bar
                  v-if="userTimeChart.data.labels.length"
                  :data="userTimeChart.data" :options="userTimeChart.options"
                />
                <div v-else class="flex flex-center full-height text-grey-5 text-caption">
                  Sin datos de tiempo<br>(timestamps del exportador necesarios)
                </div>
              </q-card-section>
            </q-card>
          </div>
        </div>

        <!-- table -->
        <q-card flat class="table-card">
          <q-table
            flat dense
            :columns="userColumns"
            :rows="userReport.destinations"
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
          Top 500 destinos ordenados por bytes. Solo flujos con un único propietario,
          timestamps del exportador y sin muestreo. El tiempo activo suma duraciones
          individuales — puede tener pausas.
        </p>
      </template>

      <div v-else-if="!selectedUser && !userLoading" class="text-center q-mt-xl text-grey-5">
        <q-icon name="person_search" size="56px" class="q-mb-md" /><br>
        Selecciona un usuario y pulsa "Generar reporte".
      </div>
    </div>

    <!-- ═══════════════════ SITIOS GLOBALES ══════════════════════════════════ -->
    <div v-if="tab === 'sites'">

      <q-card flat class="filter-card q-mb-lg">
        <q-card-section class="row q-gutter-md items-center">
          <q-btn
            color="teal" icon="public" label="Generar reporte de sitios" unelevated
            :loading="sitesLoading"
            @click="fetchSitesReport"
          />
          <span v-if="sitesReport" class="text-caption text-grey-6">
            {{ sitesReport.sites.length }} sitios encontrados
          </span>
        </q-card-section>
      </q-card>

      <q-banner v-if="sitesError" class="bg-orange-1 text-orange-10 q-mb-md rounded-borders" role="alert">
        <template #avatar><q-icon name="warning" color="orange" /></template>
        {{ sitesError }}
      </q-banner>

      <template v-if="sitesReport">
        <!-- KPIs -->
        <div class="row q-col-gutter-md q-mb-lg">
          <div v-for="m in [
            { label:'Sitios únicos',   value: sitesReport.sites.length,                                                            icon:'language',   color:'teal'   },
            { label:'Total tráfico',   value: mb(sitesReport.sites.reduce((a,s)=>a+s.total_bytes,0))+' MB',                        icon:'storage',    color:'indigo' },
            { label:'Total flujos',    value: sitesReport.sites.reduce((a,s)=>a+s.flows,0).toLocaleString(),                       icon:'swap_horiz', color:'purple' },
            { label:'Sitio más usado', value: sitesReport.sites[0] ? siteLabel(sitesReport.sites[0]) : '—',                        icon:'star',       color:'orange' },
          ]" :key="m.label" class="col-6 col-md-3">
            <q-card flat class="kpi-card">
              <q-card-section class="q-pa-md">
                <div class="row items-center no-wrap q-gutter-sm">
                  <q-icon :name="m.icon" :color="m.color" size="28px" />
                  <div class="col ellipsis">
                    <div class="text-caption text-grey-6">{{ m.label }}</div>
                    <div class="text-h6 text-weight-bold ellipsis">{{ m.value }}</div>
                  </div>
                </div>
              </q-card-section>
            </q-card>
          </div>
        </div>

        <!-- charts -->
        <div class="row q-col-gutter-md q-mb-lg">
          <div class="col-12 col-lg-6">
            <q-card flat class="chart-card">
              <q-card-section style="height:360px">
                <Bar :data="sitesBarChart.data" :options="sitesBarChart.options" />
              </q-card-section>
            </q-card>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <q-card flat class="chart-card">
              <q-card-section style="height:360px">
                <Doughnut :data="sitesDoughnut.data" :options="sitesDoughnut.options" />
              </q-card-section>
            </q-card>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <q-card flat class="chart-card">
              <q-card-section style="height:360px">
                <Bar :data="sitesUsersChart.data" :options="sitesUsersChart.options" />
              </q-card-section>
            </q-card>
          </div>
        </div>

        <!-- table -->
        <q-card flat class="table-card">
          <q-table
            flat dense
            :columns="sitesColumns"
            :rows="sitesReport.sites"
            row-key="ip"
            :pagination="{ rowsPerPage: 25 }"
            no-data-label="Sin flujos atribuidos en el intervalo"
          >
            <template #body-cell-rank="{ rowIndex }">
              <q-td class="text-right text-caption text-grey-5">{{ rowIndex + 1 }}</q-td>
            </template>
            <template #body-cell-total="{ row }">
              <q-td class="text-right text-weight-medium">{{ mb(row.total_bytes) }}</q-td>
            </template>
          </q-table>
        </q-card>
        <p class="text-caption text-grey-6 q-mt-sm">
          Top 200 sitios. Solo flujos con atribución exacta (un propietario,
          timestamps del exportador, sin muestreo). "Usuarios únicos" = cuentas
          hotspot distintas que accedieron al sitio.
        </p>
      </template>

      <div v-else-if="!sitesLoading" class="text-center q-mt-xl text-grey-5">
        <q-icon name="public" size="56px" class="q-mb-md" /><br>
        Pulsa "Generar reporte de sitios" para ver los destinos más visitados.
      </div>
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
