<template>
  <a-spin :spinning="loading">
    <a-row :gutter="16">
      <a-col :xs="24" :lg="16">
        <a-card size="small" :title="tr('People in this project', 'Personas del proyecto')">
          <template #extra>
            <a-button type="primary" size="small" @click="addOpen = true">
              <template #icon><UserAddOutlined /></template>
              {{ tr('Add members', 'Agregar miembros') }}
            </a-button>
          </template>
          <a-empty v-if="!members.length" :description="tr('Nobody yet.', 'Nadie todavía.')" />
          <div v-for="side in sides" :key="side.key">
            <template v-if="side.rows.length">
              <div class="side-head"><BankOutlined /> {{ side.label }}</div>
              <div v-for="m in side.rows" :key="m.id" class="mem">
                <a-avatar size="small" :class="['av', m.side]">{{ (m.name || '?').charAt(0).toUpperCase() }}</a-avatar>
                <div class="who">
                  <div class="nm">
                    {{ m.name }}
                    <a-tag v-if="m.is_manager" color="gold" class="tight"><CrownOutlined /> {{ tr('Project manager', 'Jefe de proyecto') }}</a-tag>
                  </div>
                  <div class="em">{{ m.email || '' }}</div>
                  <!-- Login and password (2026-11-22): the standard one is shown, a changed one is not readable. -->
                  <div v-if="m.can_see_login" class="cred">
                    <template v-if="m.has_login">
                      <span class="cr"><UserOutlined /> <code>{{ m.login }}</code>
                        <a-button type="text" size="small" class="cp" @click="copy(m.login)"><template #icon><CopyOutlined /></template></a-button></span>
                      <span class="cr"><KeyOutlined />
                        <template v-if="m.password"><code>{{ m.password }}</code>
                          <a-button type="text" size="small" class="cp" @click="copy(m.password)"><template #icon><CopyOutlined /></template></a-button></template>
                        <span v-else class="muted">{{ tr('Custom (not readable)', 'Personalizada (no se puede leer)') }}</span>
                      </span>
                      <a-tag v-if="!m.login_active" color="default" class="tight">{{ tr('Login disabled', 'Login desactivado') }}</a-tag>
                    </template>
                    <span v-else class="muted">{{ tr('No login', 'Sin login') }}</span>
                  </div>
                </div>
                <a-auto-complete
                  :value="m.role || ''" :options="roleOptions" class="role" size="small"
                  :placeholder="tr('Role in the project', 'Rol en el proyecto')" :filter-option="filterRole"
                  @change="v => (m.role = v)" @blur="saveRole(m)" @select="v => { m.role = v; saveRole(m); }"
                />
                <a-tooltip :title="m.is_manager ? tr('Remove as manager', 'Quitar como jefe') : tr('Make project manager', 'Hacer jefe de proyecto')">
                  <a-button type="text" size="small" @click="setManager(m)">
                    <template #icon><CrownFilled v-if="m.is_manager" style="color: #d97706" /><CrownOutlined v-else /></template>
                  </a-button>
                </a-tooltip>
                <a-tooltip :title="tr('Remove from the project', 'Quitar del proyecto')">
                  <a-button type="text" size="small" danger @click="remove(m)"><template #icon><DeleteOutlined /></template></a-button>
                </a-tooltip>
              </div>
            </template>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="8">
        <a-card size="small" :title="tr('Companies', 'Empresas')">
          <div class="co"><span class="lbl">{{ tr('Runs the project', 'Ejecuta el proyecto') }}</span> {{ info.company?.name || '—' }}</div>
          <div class="co">
            <span class="lbl">{{ tr('Client company in Murano', 'Empresa cliente en Murano') }}</span>
            <template v-if="info.can_pick_client_company">
              <a-select
                v-model:value="clientCompany" allow-clear show-search option-filter-prop="label" style="width: 100%"
                :options="companyOptions" :placeholder="tr('None', 'Ninguna')" @change="saveClientCompany"
              />
            </template>
            <span v-else>{{ info.client_company?.name || tr('None', 'Ninguna') }}</span>
          </div>
          <div class="hint">
            <InfoCircleOutlined />
            {{ tr('With the client company set, its employees can be added as members. Only the SuperAdmin sets it.', 'Con la empresa cliente definida, sus empleados pueden agregarse como miembros. Solo el SuperAdmin la define.') }}
          </div>
          <div class="hint">
            <CrownOutlined />
            {{ tr('New tasks go to the project manager by default.', 'Las tareas nuevas se asignan por defecto al jefe de proyecto.') }}
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-modal
      :open="addOpen" :title="tr('Add members', 'Agregar miembros')" :width="560" :confirm-loading="saving"
      :ok-text="tr('Add', 'Agregar')" :cancel-text="$t('Cancel')" :ok-button-props="{ disabled: !pick.length }"
      @ok="add" @cancel="addOpen = false"
    >
      <a-form layout="vertical">
        <a-form-item :label="tr('People', 'Personas')">
          <a-select
            v-model:value="pick" mode="multiple" show-search option-filter-prop="label" style="width: 100%"
            :options="candidateOptions" :placeholder="tr('Employees of both companies', 'Empleados de ambas empresas')"
          />
        </a-form-item>
        <a-form-item :label="tr('Role in the project', 'Rol en el proyecto')">
          <a-auto-complete v-model:value="pickRole" :options="roleOptions" :filter-option="filterRole" style="width: 100%" />
        </a-form-item>
        <a-checkbox v-if="pick.length === 1" v-model:checked="pickManager">{{ tr('Project manager', 'Jefe de proyecto') }}</a-checkbox>
      </a-form>
    </a-modal>
  </a-spin>
</template>

<script setup>
/**
 * Members tab (user, 2026-11-21): the people involved in the project -- employees of the company running it and of the
 * client's own Murano company -- each with a role (matched to the templates' roles) and one project manager.
 */
import { ref, computed, onMounted, watch } from 'vue';
import { Modal, message } from 'ant-design-vue';
import {
  UserAddOutlined, BankOutlined, CrownOutlined, CrownFilled, DeleteOutlined, InfoCircleOutlined, UserOutlined, KeyOutlined, CopyOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useTr } from '../../lib/useTr';
import { sortByLabel } from '../../lib/filterOptions';

const props = defineProps({ projectId: { type: Number, required: true } });
const emit = defineEmits(['changed']);
const tr = useTr();

const loading = ref(false);
const saving = ref(false);
const info = ref({});
const members = computed(() => info.value.members || []);
const clientCompany = ref(undefined);

const sides = computed(() => [
  { key: 'own', label: info.value.company?.name || tr('Our company', 'Nuestra empresa'), rows: members.value.filter(m => m.side === 'own') },
  { key: 'client', label: info.value.client_company?.name || tr('Client', 'Cliente'), rows: members.value.filter(m => m.side !== 'own') },
]);
const roleOptions = computed(() => (info.value.roles || []).map(r => ({ value: r })));
const filterRole = (input, opt) => String(opt.value).toLowerCase().includes(String(input).toLowerCase());
const companyOptions = computed(() => sortByLabel((info.value.companies || [])
  .filter(c => c.id !== info.value.company?.id).map(c => ({ value: c.id, label: c.name }))));
const candidateOptions = computed(() => {
  const name = side => (side === 'own' ? info.value.company?.name : info.value.client_company?.name) || '';
  return sortByLabel((info.value.candidates || []).map(c => ({ value: c.id, label: `${c.name} · ${name(c.side)}` })));
});

async function copy(text) {
  try { await navigator.clipboard.writeText(text); message.success(tr('Copied', 'Copiado')); } catch (e) { /* clipboard blocked */ }
}

async function load() {
  loading.value = !info.value.members;
  try {
    info.value = await http.get(`projects/${props.projectId}/members`);
    clientCompany.value = info.value.client_company?.id || undefined;
  } finally { loading.value = false; }
}

const addOpen = ref(false);
const pick = ref([]);
const pickRole = ref('');
const pickManager = ref(false);
watch(addOpen, v => { if (v) { pick.value = []; pickRole.value = ''; pickManager.value = false; } });

async function add() {
  saving.value = true;
  try {
    await http.post(`projects/${props.projectId}/members`, { employee_ids: pick.value, role: pickRole.value, is_manager: pickManager.value });
    addOpen.value = false;
    await load();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || tr('Could not add.', 'No se pudo agregar.'));
  } finally { saving.value = false; }
}

async function saveRole(m) {
  try { await http.put(`projects/${props.projectId}/members/${m.id}`, { role: m.role || '' }); } catch (e) { message.error(tr('Could not save.', 'No se pudo guardar.')); }
}
async function setManager(m) {
  await http.put(`projects/${props.projectId}/members/${m.id}`, { is_manager: !m.is_manager });
  await load();
  emit('changed');
}
function remove(m) {
  Modal.confirm({
    title: tr('Remove from the project?', '¿Quitar del proyecto?'),
    content: `${m.name}. ${tr('The tasks already assigned stay with them.', 'Las tareas ya asignadas se le mantienen.')}`,
    okType: 'danger', okText: tr('Remove', 'Quitar'), cancelText: tr('Cancel', 'Cancelar'),
    async onOk() { await http.delete(`projects/${props.projectId}/members/${m.id}`); await load(); emit('changed'); },
  });
}
async function saveClientCompany(v) {
  try {
    await http.post(`projects/${props.projectId}/client-company`, { client_company_id: v || null });
    await load();
  } catch (e) { message.error(e?.data?.message || tr('Could not save.', 'No se pudo guardar.')); }
}

onMounted(load);
</script>

<style scoped>
.side-head { font-size: 12px; font-weight: 600; text-transform: uppercase; opacity: 0.6; margin: 10px 0 4px; letter-spacing: 0.3px; }
.mem { display: flex; align-items: center; gap: 10px; padding: 6px 4px; border-bottom: 1px solid rgba(128, 128, 128, 0.1); }
.mem:hover { background: rgba(128, 128, 128, 0.05); }
.av { background: #6366f1; flex: none; }
.av.client { background: #0d9488; }
.who { flex: 1; min-width: 0; }
.nm { font-weight: 500; }
.em { font-size: 11.5px; opacity: 0.55; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.role { width: 210px; }
.cred { display: flex; flex-wrap: wrap; gap: 2px 12px; align-items: center; font-size: 12px; margin-top: 2px; }
.cr { display: inline-flex; align-items: center; gap: 4px; }
.cr code { font-size: 11.5px; background: rgba(128, 128, 128, 0.12); padding: 0 4px; border-radius: 3px; }
.cp { height: 20px; width: 20px; min-width: 20px; }
.muted { opacity: 0.55; }
.tight { margin-inline-start: 6px; }
.co { margin-bottom: 10px; }
.lbl { display: block; font-size: 12px; opacity: 0.6; }
.hint { font-size: 12px; opacity: 0.7; margin-top: 8px; }
@media (max-width: 768px) { .role { width: 130px; } }
</style>
