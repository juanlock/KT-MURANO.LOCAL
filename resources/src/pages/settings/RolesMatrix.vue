<template>
  <div class="page">
    <PageHeader :title="es ? 'Roles por compañía' : 'Roles by company'" :breadcrumb="[$t('Settings'), es ? 'Roles por compañía' : 'Roles by company']" />

    <a-card size="small">
      <div class="rm-tools">
        <a-input-search id="rm-search" v-model:value="search" allow-clear :placeholder="es ? 'Buscar compañía o rol' : 'Search company or role'" style="max-width: 280px" />
        <a-checkbox id="rm-used" v-model:checked="onlyUsed">{{ es ? 'Solo roles con usuarios' : 'Only roles with users' }}</a-checkbox>
        <div class="rm-legend">
          <span><b class="rm-n">3</b> {{ es ? 'usuarios con ese rol' : 'users with that role' }}</span>
          <span><span class="rm-e">•</span> {{ es ? 'existe, sin usuarios' : 'exists, no users' }}</span>
          <span><a-tag color="orange" :bordered="false">{{ es ? 'En producción' : 'Live' }}</a-tag></span>
        </div>
      </div>

      <a-spin :spinning="loading">
        <div class="rm-scroll">
          <table class="rm-table">
            <thead>
              <tr class="rm-groups">
                <th class="rm-corner" rowspan="2">{{ es ? 'Compañía' : 'Company' }}</th>
                <th v-for="g in groups" :key="g.key" :colspan="g.roles.length" :class="g.key">{{ es ? g.es : g.en }}</th>
              </tr>
              <tr class="rm-names">
                <template v-for="g in groups" :key="g.key + '-n'">
                  <th v-for="r in g.roles" :key="r" :class="g.key" :title="r">
                    <span class="rm-vname">{{ r }}</span>
                    <span class="rm-vcount">{{ companiesWith(r) }}</span>
                  </th>
                </template>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in rows" :key="c.id">
                <th>
                  <div class="rm-co">{{ c.name }} <a-tag v-if="c.live" color="orange" :bordered="false">{{ es ? 'En producción' : 'Live' }}</a-tag></div>
                  <div class="rm-type">{{ c.type || (es ? 'Plantilla del sistema' : 'System template') }} · #{{ c.id }}</div>
                </th>
                <template v-for="g in groups" :key="c.id + g.key">
                  <td v-for="r in g.roles" :key="r" :title="tip(c, r)">
                    <b v-if="c.roles[r] && c.roles[r].users" class="rm-n">{{ c.roles[r].users }}</b>
                    <span v-else-if="c.roles[r]" class="rm-e">•</span>
                  </td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>
      </a-spin>
      <p class="rm-note">{{ es
        ? 'Estándar: toda compañía los recibe al crearse. Automáticos: se crean cuando hay un login de ese tipo. Por tipo de negocio: hospital, clínica y colegio. Propios: hechos para una empresa. Pase el cursor sobre una celda para ver los permisos.'
        : 'Standard: every company gets them when created. Automatic: created when a login of that type exists. By business type: hospital, clinic and school. Own: made for one company. Hover a cell to see its permissions.' }}</p>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Settings -> Roles by company (superadmin/AdminSystem, 2026-11-02): every company
 * (rows) x every role of the install (columns, grouped by where they come from),
 * with how many users hold each one. GET roles-matrix (RolesMatrixController).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';

const ui = useUiStore();
const es = computed(() => ui.locale === 'es');

const loading = ref(false);
const companies = ref([]);
const roleNames = ref([]);
const search = ref('');
const onlyUsed = ref(false);

// Column groups (the order roles come from); a role not listed lands in "Own".
const GROUPS = [
  { key: 'g-std', en: 'Standard', es: 'Estándar', roles: ['Admin Compañía', 'Manager', 'Helpdesk', 'Website', 'Info', 'Employee', 'Customer'] },
  { key: 'g-auto', en: 'Automatic', es: 'Automáticos', roles: ['Supplier', 'Sales Agent', 'Online Store'] },
  { key: 'g-sys', en: 'System', es: 'Sistema', roles: ['SuperAdmin'] },
  { key: 'g-hosp', en: 'Hospital', es: 'Hospital', roles: ['Admission', 'Triage', 'Emergency', 'Doctor', 'Nurse', 'Head Nurse', 'Pharmacy', 'Laboratory', 'Operating Room', 'Billing'] },
  { key: 'g-clin', en: 'Clinic', es: 'Clínica', roles: ['Reception', 'Specialist', 'Clinic Nurse'] },
  { key: 'g-school', en: 'School', es: 'Colegio', roles: ['Rector', 'Secretaria', 'Profesor', 'Padre de familia', 'Alumno'] },
  { key: 'g-own', en: 'Own', es: 'Propios', roles: [] },
];

const usedAnywhere = r => companies.value.some(c => c.roles[r] && c.roles[r].users);
const companiesWith = r => companies.value.filter(c => c.roles[r]).length;

const groups = computed(() => {
  const placed = new Set(GROUPS.flatMap(g => g.roles));
  const own = roleNames.value.filter(r => !placed.has(r)).sort((a, b) => a.localeCompare(b));
  return GROUPS.map(g => ({ ...g, roles: (g.key === 'g-own' ? own : g.roles) }))
    .map(g => ({ ...g, roles: g.roles.filter(r => roleNames.value.includes(r) && (!onlyUsed.value || usedAnywhere(r))) }))
    .filter(g => g.roles.length);
});

// The search narrows companies by name; when no company matches, it narrows roles instead.
const rows = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return companies.value;
  const hit = companies.value.filter(c => c.name.toLowerCase().includes(q));
  return hit.length ? hit : companies.value.filter(c => Object.keys(c.roles).some(r => r.toLowerCase().includes(q)));
});

function tip(c, r) {
  const x = c.roles[r];
  if (!x) return es.value ? `${c.name} no tiene el rol ${r}` : `${c.name} has no ${r} role`;
  return es.value ? `${c.name} · ${r}: ${x.users} usuario(s), ${x.perms} permisos` : `${c.name} · ${r}: ${x.users} user(s), ${x.perms} permissions`;
}

onMounted(async () => {
  loading.value = true;
  try {
    const data = await http.get('roles-matrix');
    companies.value = data.companies || [];
    roleNames.value = data.role_names || [];
  } catch (e) {
    message.error(e?.data?.message || e?.message || (es.value ? 'No se pudo cargar' : 'Could not load'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.rm-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 20px; margin-bottom: 12px; }
.rm-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; align-items: center; font-size: 12px; opacity: .8; margin-left: auto; }
.rm-scroll { overflow: auto; max-height: 72vh; border: 1px solid rgba(128, 128, 128, .25); border-radius: 6px; }
.rm-table { border-collapse: separate; border-spacing: 0; font-variant-numeric: tabular-nums; }
.rm-table th, .rm-table td { border-bottom: 1px solid rgba(128, 128, 128, .2); }
.rm-table thead th { position: sticky; z-index: 2; background: var(--ant-color-bg-container, #fff); }
.rm-groups th { top: 0; height: 28px; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; opacity: .85; text-align: left; padding: 0 8px; border-left: 1px solid rgba(128, 128, 128, .2); white-space: nowrap; }
.rm-names th { top: 29px; height: 140px; vertical-align: bottom; padding: 0 0 6px; border-left: 1px solid rgba(128, 128, 128, .2); }
.rm-vname { writing-mode: vertical-rl; transform: rotate(180deg); font-size: 12px; font-weight: 500; white-space: nowrap; display: block; margin: 0 auto; }
.rm-vcount { display: block; font-size: 10px; opacity: .6; text-align: center; margin-top: 4px; }
.rm-corner { left: 0; z-index: 3 !important; text-align: left; vertical-align: bottom; padding: 8px 12px; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; min-width: 210px; }
.rm-table tbody th { position: sticky; left: 0; z-index: 1; background: var(--ant-color-bg-container, #fff); text-align: left; padding: 6px 12px; font-weight: 500; white-space: nowrap; border-right: 1px solid rgba(128, 128, 128, .25); }
.rm-co { display: flex; align-items: center; gap: 6px; }
.rm-type { font-size: 11px; opacity: .6; font-weight: 400; }
.rm-table td { width: 32px; min-width: 32px; height: 36px; text-align: center; border-left: 1px solid rgba(128, 128, 128, .2); font-size: 12px; }
.rm-n { display: inline-grid; place-items: center; min-width: 22px; height: 22px; padding: 0 4px; border-radius: 4px; background: rgba(22, 119, 255, .14); color: #1677ff; font-weight: 600; }
.rm-e { opacity: .45; }
.rm-table tbody tr:hover td, .rm-table tbody tr:hover th { background: rgba(22, 119, 255, .06); }
/* A tint over the solid header background, so sticky headers never let rows show through. */
.g-std { background-image: linear-gradient(rgba(22, 119, 255, .08), rgba(22, 119, 255, .08)); }
.g-auto { background-image: linear-gradient(rgba(82, 196, 26, .09), rgba(82, 196, 26, .09)); }
.g-sys { background-image: linear-gradient(rgba(114, 46, 209, .09), rgba(114, 46, 209, .09)); }
.g-hosp { background-image: linear-gradient(rgba(245, 34, 45, .08), rgba(245, 34, 45, .08)); }
.g-clin { background-image: linear-gradient(rgba(250, 140, 22, .09), rgba(250, 140, 22, .09)); }
.g-school { background-image: linear-gradient(rgba(47, 84, 235, .09), rgba(47, 84, 235, .09)); }
.g-own { background-image: linear-gradient(rgba(140, 140, 140, .12), rgba(140, 140, 140, .12)); }
.rm-note { margin: 12px 0 0; font-size: 12px; opacity: .7; max-width: 90ch; }
</style>
