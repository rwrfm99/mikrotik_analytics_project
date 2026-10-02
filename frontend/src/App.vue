<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import TrafficPage from './TrafficPage.vue'

const section = ref('sessions')

const health = ref(null)
const collection = ref(null)
const error = ref('')
const healthError = ref('')
const loading = ref(false)
const healthLoading = ref(false)
const view = ref('active')
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const rows = ref([])
let timer
let pendingRefresh = false

const date = value => value ? new Date(value).toLocaleString() : '—'
const bytes = value => {
  const n = Number(value || 0)
  return n >= 1073741824 ? `${(n / 1073741824).toFixed(2)} GB` : `${(n / 1048576).toFixed(2)} MB`
}
const duration = value => {
  const seconds = Number(value || 0)
  return `${Math.floor(seconds / 3600)}h ${Math.floor(seconds % 3600 / 60)}m ${seconds % 60}s`
}
const columns = [
  { name: 'username', label: 'Usuario', field: 'username', align: 'left' },
  { name: 'ip_address', label: 'IP', field: 'ip_address', align: 'left' },
  { name: 'mac_address', label: 'MAC', field: 'mac_address', align: 'left' },
  { name: 'profile', label: 'Perfil', field: row => row.user?.profile || '—', align: 'left' },
  { name: 'uptime', label: 'Uptime observado', field: row => duration(row.uptime_seconds), align: 'left' },
  { name: 'started', label: 'Inicio estimado', field: row => date(row.started_at), align: 'left' },
  { name: 'ended', label: 'Fin registrado', field: row => date(row.ended_at), align: 'left' },
  { name: 'in', label: 'Bytes in · RouterOS', field: row => bytes(row.mikrotik_bytes_in), align: 'right' },
  { name: 'out', label: 'Bytes out · RouterOS', field: row => bytes(row.mikrotik_bytes_out), align: 'right' },
  { name: 'status', label: 'Estado', field: row => row.ended_at ? 'Cerrada' : row.missing_polls ? 'Confirmando salida' : collection.value?.status === 'ok' ? 'Activa' : 'Sin confirmar', align: 'left' },
]

async function getJson(url) {
  const response = await fetch(url, { signal: AbortSignal.timeout(30000) })
  if (!response.ok) throw new Error('No se pudieron cargar los datos. Comprueba el estado de los servicios.')
  return response.json()
}
async function refresh() {
  if (loading.value) { pendingRefresh = true; return }
  loading.value = true
  const currentView = view.value
  const currentPage = page.value
  const currentSearch = search.value || ''
  try {
    const endpoint = currentView === 'active' ? 'users/active' : 'sessions'
    const params = new URLSearchParams({ page: currentPage, limit: 25, search: currentSearch })
    const [status, result] = await Promise.all([getJson('/api/v1/hotspot/collection'), getJson(`/api/v1/hotspot/${endpoint}?${params}`)])
    collection.value = status
    if (currentView === view.value && currentPage === page.value && currentSearch === (search.value || '')) {
      rows.value = result.data
      total.value = result.total
      lastPage.value = result.last_page
      if (page.value > lastPage.value) { page.value = lastPage.value; pendingRefresh = true }
    }
    error.value = ''
  } catch (e) { error.value = e.message }
  finally {
    loading.value = false
    if (pendingRefresh) { pendingRefresh = false; refresh() }
  }
}
async function checkHealth() {
  healthLoading.value = true
  try {
    const response = await fetch('/api/v1/health', { signal: AbortSignal.timeout(30000) })
    health.value = await response.json()
    healthError.value = response.ok ? '' : 'Hay servicios que requieren atención.'
  } catch { healthError.value = 'No se pudo consultar el estado de servicios.' }
  finally { healthLoading.value = false }
}
function resetAndRefresh() { page.value = 1; rows.value = []; refresh() }
onMounted(() => { refresh(); checkHealth(); timer = setInterval(refresh, 10000) })
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <q-layout view="hHh lpR fFf">
    <q-header class="bg-dark"><q-toolbar class="q-px-lg"><q-icon name="router" size="28px" class="q-mr-md" /><q-toolbar-title>MikroTik Analytics</q-toolbar-title><q-badge color="teal" label="Hotspot" /></q-toolbar></q-header>
    <q-page-container>
      <q-page class="q-pa-lg" style="max-width: 1500px; margin: auto">
        <q-tabs v-model="section" align="left" active-color="teal" class="q-mb-md"><q-tab name="sessions" label="Usuarios y sesiones" /><q-tab name="traffic" label="Tráfico y destinos" /></q-tabs>
        <TrafficPage v-if="section === 'traffic'" />
        <div v-show="section === 'sessions'">
        <div class="text-overline text-teal-8">RECOLECCIÓN · ROUTEROS API</div>
        <h1 class="text-h4 q-mt-sm">Usuarios y sesiones</h1>
        <p class="text-grey-8">Lectura del router cada {{ collection?.poll_seconds || 15 }} segundos. Actualización del panel cada 10 segundos.</p>
        <q-banner v-if="error" class="bg-orange-1 text-orange-10 q-mb-md" role="alert">{{ error }} Los datos anteriores pueden estar desactualizados.</q-banner>
        <q-banner v-if="collection && collection.status !== 'ok'" class="bg-orange-1 text-orange-10 q-mb-md" role="status">Colector: {{ collection.status }}. {{ collection.error || 'Esperando una lectura reciente.' }} El estado de las sesiones no está confirmado.</q-banner>
        <div v-if="collection" class="row q-col-gutter-md q-mb-lg">
          <div v-for="item in [{ label: 'Usuarios registrados', value: collection.users }, { label: 'Sesiones abiertas', value: collection.open_sessions }, { label: 'Sesiones guardadas', value: collection.sessions }]" :key="item.label" class="col-12 col-sm-4">
            <q-card flat bordered><q-card-section><div class="text-grey-7">{{ item.label }}</div><div class="text-h4">{{ item.value }}</div></q-card-section></q-card>
          </div>
        </div>
        <q-card flat bordered>
          <q-card-section class="row items-center q-gutter-md">
            <q-tabs v-model="view" active-color="teal" @update:model-value="resetAndRefresh"><q-tab name="active" label="Activos" /><q-tab name="history" label="Historial" /></q-tabs>
            <q-space /><q-input v-model="search" outlined dense debounce="400" label="Buscar usuario, IP o MAC" clearable @update:model-value="resetAndRefresh" /><q-btn outline color="teal" label="Actualizar" :loading="loading" @click="refresh" />
          </q-card-section>
          <q-table flat :rows="rows" :columns="columns" row-key="id" :loading="loading" :pagination="{ rowsPerPage: 0 }" hide-pagination no-data-label="Todavía no hay sesiones para esta consulta" />
          <q-card-section class="row items-center justify-between"><span>{{ total }} sesiones · Fuente: MikroTik</span><q-pagination v-model="page" :max="lastPage" :max-pages="5" color="teal" @update:model-value="refresh" /></q-card-section>
        </q-card>
        <p class="text-caption text-grey-7 q-mt-md">Última lectura correcta: {{ date(collection?.last_success_at) }}. Inicio estimado a partir del uptime; los cambios de identidad se registran desde su primera observación. El fin corresponde a la última presencia confirmada. Los contadores in/out conservan el sentido original de RouterOS.</p>
        <q-expansion-item icon="monitor_heart" label="Estado de infraestructura" class="q-mt-lg">
          <q-card flat bordered><q-card-section><q-btn outline color="teal" label="Comprobar servicios" :loading="healthLoading" @click="checkHealth" /><p v-if="healthError" role="alert">{{ healthError }}</p></q-card-section>
            <q-list separator v-if="health"><q-item v-for="(status, service) in health.services" :key="service"><q-item-section>{{ service }}</q-item-section><q-item-section side><q-badge :color="status === 'ok' ? 'teal' : 'grey'">{{ status }}</q-badge></q-item-section></q-item></q-list>
          </q-card>
        </q-expansion-item>
        </div>
      </q-page>
    </q-page-container>
  </q-layout>
</template>
