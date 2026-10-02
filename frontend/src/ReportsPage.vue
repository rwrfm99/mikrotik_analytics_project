<script setup>
import { ref, onMounted } from 'vue'
import { siteLabel } from './siteLabel.js'

// ── helpers ─────────────────────────────────────────────────────────────────
const mb    = n  => (Number(n || 0) / 1_000_000).toFixed(2)
const date  = ms => new Date(Number(ms)).toLocaleString()
const dur   = ms => {
  const s = Math.round(Number(ms || 0) / 1000)
  if (s < 60)   return `${s} s`
  if (s < 3600) return `${Math.floor(s/60)}m ${s%60}s`
  return `${Math.floor(s/3600)}h ${Math.floor(s%3600/60)}m`
}
const ptrLabel = r => siteLabel(r)

async function apiFetch(url) {
  const res  = await fetch(url, { signal: AbortSignal.timeout(30000) })
  const body = await res.json()
  if (!res.ok) throw new Error(body.message || 'Error al consultar el servidor.')
  return body
}

// ── shared state ─────────────────────────────────────────────────────────────
const tab    = ref('user')       // 'user' | 'sites'
const hours  = ref(1)
const users  = ref([])
const error  = ref('')

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
  try {
    users.value = await apiFetch('/api/v1/traffic/users')
  } catch {
    error.value = 'No se pudo cargar la lista de usuarios.'
  }
})

// ── reporte por usuario ──────────────────────────────────────────────────────
const selectedUser  = ref(null)
const userReport    = ref(null)
const userLoading   = ref(false)
const userError     = ref('')

const userColumns = [
  { name: 'ptr',      label: 'Sitio (PTR)',         field: ptrLabel,                          align: 'left'  },
  { name: 'ip',       label: 'IP destino',           field: 'ip',                              align: 'left'  },
  { name: 'download', label: 'Descarga · MB',        field: r => mb(r.download_bytes),         align: 'right' },
  { name: 'upload',   label: 'Subida · MB',          field: r => mb(r.upload_bytes),           align: 'right' },
  { name: 'total',    label: 'Total · MB',           field: r => mb(r.total_bytes),            align: 'right' },
  { name: 'duration', label: 'Tiempo activo',        field: r => dur(r.duration_ms),           align: 'right' },
  { name: 'flows',    label: 'Flujos',               field: 'flows',                           align: 'right' },
  { name: 'first',    label: 'Primera actividad',    field: r => date(r.first_ms),             align: 'left'  },
  { name: 'last',     label: 'Última actividad',     field: r => date(r.last_ms),              align: 'left'  },
]

async function fetchUserReport() {
  if (!selectedUser.value) return
  userLoading.value = true
  userError.value   = ''
  userReport.value  = null
  try {
    userReport.value = await apiFetch(
      `/api/v1/reports/users/${selectedUser.value}?${buildRange(hours.value)}`
    )
  } catch (e) {
    userError.value = e.message
  } finally {
    userLoading.value = false
  }
}

// ── reporte de sitios globales ────────────────────────────────────────────────
const sitesReport   = ref(null)
const sitesLoading  = ref(false)
const sitesError    = ref('')

const sitesColumns = [
  { name: 'rank',     label: '#',                   field: (_, i) => i + 1,                   align: 'right' },
  { name: 'ptr',      label: 'Sitio (PTR)',         field: ptrLabel,                          align: 'left'  },
  { name: 'ip',       label: 'IP destino',           field: 'ip',                              align: 'left'  },
  { name: 'total',    label: 'Total · MB',           field: r => mb(r.total_bytes),            align: 'right' },
  { name: 'download', label: 'Descarga · MB',        field: r => mb(r.download_bytes),         align: 'right' },
  { name: 'upload',   label: 'Subida · MB',          field: r => mb(r.upload_bytes),           align: 'right' },
  { name: 'users',    label: 'Usuarios únicos',      field: 'unique_users',                    align: 'right' },
  { name: 'duration', label: 'Tiempo acumulado',     field: r => dur(r.duration_ms),           align: 'right' },
  { name: 'flows',    label: 'Flujos',               field: 'flows',                           align: 'right' },
]

async function fetchSitesReport() {
  sitesLoading.value = true
  sitesError.value   = ''
  sitesReport.value  = null
  try {
    sitesReport.value = await apiFetch(
      `/api/v1/reports/sites?${buildRange(hours.value)}`
    )
  } catch (e) {
    sitesError.value = e.message
  } finally {
    sitesLoading.value = false
  }
}
</script>

<template>
  <section>
    <h1 class="text-h4 q-mt-md">Reportes</h1>
    <p class="text-grey-8">
      Análisis basado en flujos IPFIX atribuidos. MB decimales (1 MB = 1.000.000 bytes).
      El tiempo activo suma duraciones de flujos con timestamps del exportador; flujos sin
      timestamps precisos no suman tiempo pero sí bytes.
    </p>

    <q-banner v-if="error" class="bg-orange-1 text-orange-10 q-mb-md" role="alert">
      {{ error }}
    </q-banner>

    <!-- intervalo compartido -->
    <q-card flat bordered class="q-mb-md">
      <q-card-section class="row q-gutter-md items-center">
        <q-select
          class="col-12 col-sm-3"
          v-model="hours"
          :options="intervalOptions"
          emit-value map-options outlined dense
          label="Intervalo"
        />
        <span class="text-grey-7 text-caption">
          Aplica a ambos reportes al consultar.
        </span>
      </q-card-section>
    </q-card>

    <!-- tabs internos -->
    <q-tabs v-model="tab" align="left" active-color="teal" class="q-mb-md">
      <q-tab name="user"  label="Por usuario"  icon="person"  />
      <q-tab name="sites" label="Sitios globales" icon="public" />
    </q-tabs>

    <!-- ══════════════════ REPORTE POR USUARIO ══════════════════ -->
    <div v-if="tab === 'user'">
      <q-card flat bordered class="q-mb-md">
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
            color="teal" label="Generar reporte"
            :loading="userLoading" :disable="!selectedUser"
            @click="fetchUserReport"
          />
        </q-card-section>
      </q-card>

      <q-banner v-if="userError" class="bg-orange-1 text-orange-10 q-mb-md" role="alert">
        {{ userError }}
      </q-banner>

      <template v-if="userReport">
        <!-- resumen -->
        <div class="row q-col-gutter-md q-mb-md">
          <div
            v-for="m in [
              { label: 'Total descargado',  value: mb(userReport.download_bytes) + ' MB' },
              { label: 'Total subido',      value: mb(userReport.upload_bytes)   + ' MB' },
              { label: 'Total transferido', value: mb(userReport.total_bytes)    + ' MB' },
            ]"
            :key="m.label"
            class="col-12 col-sm-4"
          >
            <q-card flat bordered>
              <q-card-section>
                <div class="text-grey-7">{{ m.label }}</div>
                <div class="text-h5">{{ m.value }}</div>
              </q-card-section>
            </q-card>
          </div>
        </div>

        <!-- tabla de destinos -->
        <q-table
          flat bordered
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

        <p class="text-caption text-grey-7 q-mt-sm">
          Top 500 destinos ordenados por bytes totales. Solo se incluyen flujos con un único
          propietario, timestamps del exportador y sin muestreo. El tiempo activo es la suma
          de duraciones individuales de cada flujo — puede tener huecos y no equivale al tiempo
          continuo navegando.
        </p>
      </template>

      <p v-else-if="!selectedUser && !userLoading" class="text-grey-7">
        Selecciona un usuario y pulsa "Generar reporte".
      </p>
    </div>

    <!-- ══════════════════ REPORTE SITIOS GLOBALES ══════════════════ -->
    <div v-if="tab === 'sites'">
      <q-card flat bordered class="q-mb-md">
        <q-card-section class="row q-gutter-md items-center">
          <q-btn
            color="teal" label="Generar reporte de sitios"
            :loading="sitesLoading"
            @click="fetchSitesReport"
          />
        </q-card-section>
      </q-card>

      <q-banner v-if="sitesError" class="bg-orange-1 text-orange-10 q-mb-md" role="alert">
        {{ sitesError }}
      </q-banner>

      <template v-if="sitesReport">
        <p class="text-grey-8 q-mb-md">
          {{ sitesReport.sites.length }} sitios · intervalo
          {{ new Date(sitesReport.from).toLocaleString() }} —
          {{ new Date(sitesReport.to).toLocaleString() }}
        </p>

        <q-table
          flat bordered
          :columns="sitesColumns"
          :rows="sitesReport.sites"
          row-key="ip"
          :pagination="{ rowsPerPage: 25 }"
          no-data-label="Sin flujos atribuidos en el intervalo"
        >
          <template #body-cell-rank="{ rowIndex }">
            <q-td class="text-right text-grey-6">{{ rowIndex + 1 }}</q-td>
          </template>
          <template #body-cell-total="{ row }">
            <q-td class="text-right text-weight-medium">{{ mb(row.total_bytes) }}</q-td>
          </template>
        </q-table>

        <p class="text-caption text-grey-7 q-mt-sm">
          Top 200 sitios ordenados por bytes totales. Solo flujos con atribución exacta
          (un único propietario, timestamps del exportador, sin muestreo). "Usuarios únicos"
          es el número de cuentas hotspot distintas que accedieron al sitio en el intervalo.
          "Tiempo acumulado" suma las duraciones de todos los flujos de todos los usuarios
          hacia ese destino.
        </p>
      </template>

      <p v-else-if="!sitesLoading" class="text-grey-7">
        Pulsa "Generar reporte de sitios" para ver los destinos más visitados.
      </p>
    </div>
  </section>
</template>
