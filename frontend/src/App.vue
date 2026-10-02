<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import DashboardPage from './DashboardPage.vue'
import TrafficPage   from './TrafficPage.vue'
import ReportsPage   from './ReportsPage.vue'

// ── navigation ────────────────────────────────────────────────────────────────
const section    = ref('dashboard')
const drawerOpen = ref(true)
const miniMode   = ref(false)

const navItems = [
  { id: 'dashboard', label: 'Dashboard',          icon: 'dashboard'   },
  { id: 'sessions',  label: 'Usuarios y sesiones', icon: 'people'      },
  { id: 'traffic',   label: 'Tráfico y destinos',  icon: 'swap_horiz'  },
  { id: 'reports',   label: 'Reportes',            icon: 'bar_chart'   },
  { id: 'health',    label: 'Infraestructura',      icon: 'monitor_heart'},
]

// ── sessions page state ───────────────────────────────────────────────────────
const collection  = ref(null)
const error       = ref('')
const loading     = ref(false)
const view        = ref('active')
const search      = ref('')
const page        = ref(1)
const lastPage    = ref(1)
const total       = ref(0)
const rows        = ref([])
let   timer
let   pendingRefresh = false

const date     = v  => v ? new Date(v).toLocaleString() : '—'
const fmtBytes = v  => {
  const n = Number(v || 0)
  return n >= 1_073_741_824 ? `${(n/1_073_741_824).toFixed(2)} GB` : `${(n/1_048_576).toFixed(2)} MB`
}
const duration = v  => {
  const s = Number(v || 0)
  return `${Math.floor(s/3600)}h ${Math.floor(s%3600/60)}m ${s%60}s`
}

const sessionColumns = [
  { name: 'username',    label: 'Usuario',            field: 'username',                      align: 'left'  },
  { name: 'ip_address',  label: 'IP',                 field: 'ip_address',                    align: 'left'  },
  { name: 'mac_address', label: 'MAC',                field: 'mac_address',                   align: 'left'  },
  { name: 'profile',     label: 'Perfil',             field: r => r.user?.profile || '—',      align: 'left'  },
  { name: 'uptime',      label: 'Uptime',             field: r => duration(r.uptime_seconds),  align: 'left'  },
  { name: 'started',     label: 'Inicio',             field: r => date(r.started_at),          align: 'left'  },
  { name: 'ended',       label: 'Fin',                field: r => date(r.ended_at),            align: 'left'  },
  { name: 'in',          label: '↓ RouterOS',         field: r => fmtBytes(r.mikrotik_bytes_in),  align: 'right' },
  { name: 'out',         label: '↑ RouterOS',         field: r => fmtBytes(r.mikrotik_bytes_out), align: 'right' },
  { name: 'status',      label: 'Estado',
    field: r => r.ended_at ? 'Cerrada'
              : r.missing_polls ? 'Confirmando salida'
              : collection.value?.status === 'ok' ? 'Activa' : 'Sin confirmar',
    align: 'left' },
]

async function getJson(url) {
  const res = await fetch(url, { signal: AbortSignal.timeout(30000) })
  if (!res.ok) throw new Error('No se pudieron cargar los datos.')
  return res.json()
}

async function refresh() {
  if (loading.value) { pendingRefresh = true; return }
  loading.value = true
  const curView = view.value, curPage = page.value, curSearch = search.value || ''
  try {
    const ep     = curView === 'active' ? 'users/active' : 'sessions'
    const params = new URLSearchParams({ page: curPage, limit: 25, search: curSearch })
    const [col, result] = await Promise.all([
      getJson('/api/v1/hotspot/collection'),
      getJson(`/api/v1/hotspot/${ep}?${params}`),
    ])
    collection.value = col
    if (curView === view.value && curPage === page.value && curSearch === (search.value || '')) {
      rows.value     = result.data
      total.value    = result.total
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
function resetAndRefresh() { page.value = 1; rows.value = []; refresh() }

// ── health page state ─────────────────────────────────────────────────────────
const health        = ref(null)
const healthError   = ref('')
const healthLoading = ref(false)

async function checkHealth() {
  healthLoading.value = true
  try {
    const res    = await fetch('/api/v1/health', { signal: AbortSignal.timeout(30000) })
    health.value = await res.json()
    healthError.value = res.ok ? '' : 'Hay servicios que requieren atención.'
  } catch { healthError.value = 'No se pudo consultar el estado de servicios.' }
  finally  { healthLoading.value = false }
}

// ── lifecycle ─────────────────────────────────────────────────────────────────
onMounted(() => { refresh(); timer = setInterval(refresh, 10000) })
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <q-layout view="lHh LpR lFf">

    <!-- ── top bar ─────────────────────────────────────────────────────────── -->
    <q-header class="header-bar" elevated>
      <q-toolbar class="q-px-md">
        <q-btn
          flat round dense
          :icon="drawerOpen ? 'menu_open' : 'menu'"
          color="white"
          @click="drawerOpen = !drawerOpen"
          class="q-mr-sm"
        />
        <q-icon name="router" size="26px" class="q-mr-sm" color="teal-3" />
        <q-toolbar-title class="text-weight-bold" style="letter-spacing:.5px">
          MikroTik Analytics
        </q-toolbar-title>
        <!-- status chips -->
        <q-chip
          v-if="collection"
          dense
          :color="collection.status === 'ok' ? 'teal-8' : 'orange-8'"
          text-color="white"
          :icon="collection.status === 'ok' ? 'check_circle' : 'warning'"
          :label="collection.status === 'ok' ? 'Colector activo' : 'Colector: ' + collection.status"
          class="q-mr-xs"
        />
        <q-chip
          dense color="grey-7" text-color="white"
          icon="people" :label="`${collection?.open_sessions ?? '—'} sesiones`"
        />
      </q-toolbar>
    </q-header>

    <!-- ── side drawer ─────────────────────────────────────────────────────── -->
    <q-drawer
      v-model="drawerOpen"
      :mini="miniMode"
      :width="220"
      :mini-width="56"
      show-if-above
      bordered
      class="side-drawer"
    >
      <!-- mini toggle -->
      <q-list padding>
        <q-item
          v-for="item in navItems"
          :key="item.id"
          clickable
          :active="section === item.id"
          active-class="nav-active"
          class="nav-item rounded-borders q-mb-xs"
          @click="section = item.id"
        >
          <q-item-section avatar>
            <q-icon :name="item.icon" />
          </q-item-section>
          <q-item-section>{{ item.label }}</q-item-section>
          <q-tooltip v-if="miniMode" anchor="center right" self="center left">
            {{ item.label }}
          </q-tooltip>
        </q-item>
      </q-list>

      <!-- bottom: mini toggle -->
      <template #mini-to-overlay>
        <q-btn flat dense round icon="chevron_right" />
      </template>
      <div class="absolute-bottom q-pa-sm">
        <q-btn
          flat dense round
          :icon="miniMode ? 'chevron_right' : 'chevron_left'"
          color="grey-6"
          @click="miniMode = !miniMode"
        >
          <q-tooltip>{{ miniMode ? 'Expandir menú' : 'Minimizar menú' }}</q-tooltip>
        </q-btn>
      </div>
    </q-drawer>

    <!-- ── main content ─────────────────────────────────────────────────────── -->
    <q-page-container>
      <q-page class="q-pa-lg page-content">

        <!-- dashboard -->
        <DashboardPage v-if="section === 'dashboard'" />

        <!-- tráfico -->
        <TrafficPage v-if="section === 'traffic'" />

        <!-- reportes -->
        <ReportsPage v-if="section === 'reports'" />

        <!-- ═══ sessions ════════════════════════════════════════════════════ -->
        <div v-if="section === 'sessions'">
          <div class="row items-center justify-between q-mb-lg">
            <div>
              <div class="text-h5 text-weight-bold">Usuarios y sesiones</div>
              <div class="text-caption text-grey-6">
                RouterOS API · lectura cada {{ collection?.poll_seconds || 15 }}s · panel cada 10s
              </div>
            </div>
            <q-btn outline color="teal" icon="refresh" label="Actualizar" :loading="loading" @click="refresh" />
          </div>

          <q-banner v-if="error" class="bg-orange-1 text-orange-10 q-mb-md rounded-borders" role="alert">
            <template #avatar><q-icon name="warning" color="orange" /></template>
            {{ error }}
          </q-banner>
          <q-banner v-if="collection && collection.status !== 'ok'"
            class="bg-orange-1 text-orange-10 q-mb-md rounded-borders" role="status">
            <template #avatar><q-icon name="error_outline" color="orange" /></template>
            Colector: {{ collection.status }}. {{ collection.error || 'Esperando lectura.' }}
          </q-banner>

          <!-- KPIs -->
          <div v-if="collection" class="row q-col-gutter-md q-mb-lg">
            <div v-for="item in [
              { label:'Usuarios registrados', value: collection.users,        icon:'person',      color:'teal'   },
              { label:'Sesiones abiertas',    value: collection.open_sessions, icon:'wifi',        color:'indigo' },
              { label:'Sesiones guardadas',   value: collection.sessions,      icon:'history',     color:'green'  },
            ]" :key="item.label" class="col-12 col-sm-4">
              <q-card flat class="kpi-card">
                <q-card-section class="q-pa-md">
                  <div class="row items-center no-wrap q-gutter-sm">
                    <q-icon :name="item.icon" :color="item.color" size="30px" />
                    <div>
                      <div class="text-caption text-grey-6">{{ item.label }}</div>
                      <div class="text-h4 text-weight-bold">{{ item.value ?? '—' }}</div>
                    </div>
                  </div>
                </q-card-section>
              </q-card>
            </div>
          </div>

          <!-- table card -->
          <q-card flat class="table-card">
            <q-card-section class="row items-center q-gutter-md q-pb-none">
              <q-tabs v-model="view" active-color="teal" @update:model-value="resetAndRefresh">
                <q-tab name="active"  label="Activos"   icon="wifi"    />
                <q-tab name="history" label="Historial" icon="history" />
              </q-tabs>
              <q-space />
              <q-input
                v-model="search" outlined dense debounce="400"
                label="Buscar usuario, IP o MAC" clearable
                style="min-width:220px"
                @update:model-value="resetAndRefresh"
              />
            </q-card-section>
            <q-table
              flat
              :rows="rows"
              :columns="sessionColumns"
              row-key="id"
              :loading="loading"
              :pagination="{ rowsPerPage: 0 }"
              hide-pagination
              no-data-label="Sin sesiones para esta consulta"
              class="q-mt-sm"
            >
              <template #body-cell-status="{ row }">
                <q-td>
                  <q-badge
                    :color="row.ended_at ? 'grey-5'
                           : row.missing_polls ? 'orange'
                           : collection?.status === 'ok' ? 'teal' : 'grey'"
                    :label="row.ended_at ? 'Cerrada'
                            : row.missing_polls ? 'Confirmando…'
                            : collection?.status === 'ok' ? 'Activa' : 'Sin confirmar'"
                  />
                </q-td>
              </template>
            </q-table>
            <q-card-section class="row items-center justify-between q-pt-sm">
              <span class="text-caption text-grey-6">{{ total }} sesiones · Fuente: MikroTik</span>
              <q-pagination v-model="page" :max="lastPage" :max-pages="5" color="teal" @update:model-value="refresh" />
            </q-card-section>
          </q-card>

          <p class="text-caption text-grey-6 q-mt-md">
            Última lectura correcta: {{ date(collection?.last_success_at) }}.
            Inicio estimado a partir del uptime. Los contadores in/out son de RouterOS.
          </p>
        </div>

        <!-- ═══ health ═══════════════════════════════════════════════════════ -->
        <div v-if="section === 'health'">
          <div class="row items-center justify-between q-mb-lg">
            <div>
              <div class="text-h5 text-weight-bold">Estado de infraestructura</div>
              <div class="text-caption text-grey-6">Servicios del stack MikroTik Analytics</div>
            </div>
            <q-btn color="teal" icon="refresh" label="Comprobar" :loading="healthLoading" @click="checkHealth" />
          </div>

          <q-banner v-if="healthError" class="bg-orange-1 text-orange-10 q-mb-md rounded-borders" role="alert">
            <template #avatar><q-icon name="warning" color="orange" /></template>
            {{ healthError }}
          </q-banner>

          <div v-if="!health" class="text-grey-6 q-mt-lg text-center">
            <q-icon name="monitor_heart" size="48px" class="q-mb-sm" /><br>
            Pulsa "Comprobar" para ver el estado de los servicios.
          </div>

          <div v-else class="row q-col-gutter-md">
            <div v-for="(status, service) in health.services" :key="service" class="col-12 col-sm-6 col-md-4">
              <q-card flat class="kpi-card">
                <q-card-section class="q-pa-md">
                  <div class="row items-center no-wrap q-gutter-sm">
                    <q-icon
                      :name="status === 'ok' ? 'check_circle' : 'error'"
                      :color="status === 'ok' ? 'teal' : 'red'"
                      size="28px"
                    />
                    <div>
                      <div class="text-subtitle2">{{ service }}</div>
                      <q-badge :color="status === 'ok' ? 'teal' : 'red'" :label="status" />
                    </div>
                  </div>
                </q-card-section>
              </q-card>
            </div>
          </div>
        </div>

      </q-page>
    </q-page-container>
  </q-layout>
</template>

<style>
/* global reset for cleaner look */
body { background: #f5f6fa; }
</style>

<style scoped>
.header-bar {
  background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
}
.side-drawer {
  background: #ffffff;
  border-right: 1px solid rgba(0,0,0,0.08);
}
.nav-item {
  margin: 2px 8px;
  border-radius: 8px !important;
  transition: background .15s;
}
.nav-item:hover { background: rgba(0,150,136,0.08); }
.nav-active {
  background: rgba(0,150,136,0.12) !important;
  color: #009688 !important;
  font-weight: 600;
}
.page-content {
  max-width: 1600px;
  margin: 0 auto;
}
.kpi-card {
  border: 1px solid rgba(0,0,0,0.07);
  border-radius: 10px;
  background: #fff;
  transition: box-shadow .2s;
}
.kpi-card:hover { box-shadow: 0 2px 14px rgba(0,0,0,0.09); }
.table-card {
  border: 1px solid rgba(0,0,0,0.07);
  border-radius: 10px;
  background: #fff;
  overflow: hidden;
}
</style>
