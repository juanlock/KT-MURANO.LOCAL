<template>
  <!-- Scan this network (2026-11-28, from HTD-IT): Murano is in the cloud and cannot see the office network, so a PC IN the office runs
       the script (or one cmd line) and uploads what it found; nothing changes until the scan is reviewed in Scan imports. -->
  <a-modal :open="open" :title="L('Scan this network', 'Escanear esta red') + (network ? ' · ' + network.name + ' (' + network.network_address + '/' + network.cidr + ')' : '')"
    width="760px" :footer="null" destroy-on-close @cancel="close">
    <a-spin :spinning="starting">
      <template v-if="scan">
        <a-alert v-if="status.status === 'uploaded'" type="success" show-icon style="margin-bottom: 12px"
          :message="L(`Received: ${status.found} devices${status.computer ? ' from ' + status.computer : ''}.`, `Recibido: ${status.found} equipos${status.computer ? ' desde ' + status.computer : ''}.`)">
          <template #description><a-button type="primary" size="small" @click="review">{{ L('Review the scan', 'Revisar el escaneo') }}</a-button></template>
        </a-alert>
        <a-alert v-else-if="status.expired" type="warning" show-icon style="margin-bottom: 12px" :message="L('This scan expired (2 hours): start it again.', 'Este escaneo venció (2 horas): vuelve a iniciarlo.')" />
        <template v-else>
          <p class="sm-lead">{{ L('Run it on a Windows PC that is IN that office (over VPN the devices are not seen). It only reads: pings the IPs, reads the ARP table and asks the names; it changes nothing on the PC or the devices.',
            'Ejecútalo en una PC con Windows que esté EN esa oficina (por VPN no se ven los equipos). Solo lee: hace ping a las IPs, lee la tabla ARP y pregunta los nombres; no cambia nada en la PC ni en los equipos.') }}</p>
          <a-tabs v-model:activeKey="tab" size="small">
            <a-tab-pane key="script" :tab="L('1 · Script (uploads by itself)', '1 · Script (sube solo)')">
              <ol class="sm-steps">
                <li><a-button type="primary" size="small" @click="download"><DownloadOutlined /> {{ L('Download the script', 'Descargar el script') }}</a-button></li>
                <li>{{ L('Open PowerShell in the folder where it was saved and run:', 'Abre PowerShell en la carpeta donde se guardó y ejecuta:') }}
                  <div class="sm-code"><code>powershell -ExecutionPolicy Bypass -File .\{{ fileName }}</code><a-button size="small" type="text" @click="copy(`powershell -ExecutionPolicy Bypass -File .\\${fileName}`)"><CopyOutlined /></a-button></div>
                </li>
                <li>{{ L('When it finishes this window shows the result by itself.', 'Cuando termine, esta ventana muestra el resultado sola.') }}</li>
              </ol>
            </a-tab-pane>
            <a-tab-pane v-if="scan.arp_command" key="cmd" :tab="L('2 · Without script (cmd)', '2 · Sin script (cmd)')">
              <p class="sm-lead">{{ L('For a PC that blocks scripts: paste this line in cmd (or PowerShell). It opens its own window and closes by itself; then upload the file it saved in C:\\temp.',
                'Para una PC que bloquea scripts: pega esta línea en cmd (o PowerShell). Abre su propia ventana y se cierra sola; luego sube el archivo que guardó en C:\\temp.') }}</p>
              <div class="sm-code"><code class="sm-long">{{ scan.arp_command }}</code><a-button size="small" type="text" @click="copy(scan.arp_command)"><CopyOutlined /></a-button></div>
            </a-tab-pane>
          </a-tabs>
          <div class="sm-upload">
            <span>{{ L('Upload by hand (the .scan file of the script or the text file of the cmd line):', 'Subir a mano (el archivo .scan del script o el de texto de la línea cmd):') }}</span>
            <a-upload :show-upload-list="false" :before-upload="upload" accept=".scan,.txt,.json"><a-button size="small" :loading="uploading"><UploadOutlined /> {{ L('Upload file', 'Subir archivo') }}</a-button></a-upload>
          </div>
          <div class="sm-wait"><a-spin size="small" /> {{ L('Waiting for the upload…', 'Esperando la subida…') }} <span class="sm-muted">{{ L('valid until', 'válido hasta') }} {{ dateTime(scan.expires_at) }}</span></div>
        </template>
      </template>
    </a-spin>
  </a-modal>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { CopyOutlined, DownloadOutlined, UploadOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { getActingCompanyId } from '../../lib/actingCompany';
import { useFormat } from '../../composables/useFormat';
import { useTr } from '../../lib/useTr';

const props = defineProps({ open: Boolean, network: { type: Object, default: null } });
const emit = defineEmits(['update:open', 'done']);
const L = useTr();
const { dateTime } = useFormat();
const router = useRouter();
const errorOf = e => { const errs = e?.data?.errors; return (errs && Object.values(errs)[0]?.[0]) || e?.data?.message || e.message; };

const scan = ref(null);
const status = ref({});
const starting = ref(false);
const uploading = ref(false);
const tab = ref('script');
let timer = null;
const fileName = computed(() => `scan-${scan.value?.file_tag || 'network'}.ps1`);
const cq = () => { const c = getActingCompanyId(); return c ? `?company_id=${c}` : ''; };

watch(() => props.open, async o => {
  stop();
  if (!o || !props.network) return;
  scan.value = null;
  status.value = {};
  starting.value = true;
  try {
    scan.value = await http.post(`itadmin/networks/${props.network.id}/scan`);
    timer = setInterval(poll, 4000);
  } catch (e) {
    message.error(errorOf(e));
    close();
  } finally { starting.value = false; }
});
async function poll() {
  if (!scan.value) return;
  try {
    status.value = await http.get(`itadmin/scans/${scan.value.scan_id}/status`);
    if (status.value.status !== 'waiting' || status.value.expired) { stop(); emit('done'); }
  } catch (e) { /* keep waiting */ }
}
function stop() { if (timer) { clearInterval(timer); timer = null; } }
function close() { stop(); emit('update:open', false); }
async function download() {
  try { await http.download(`itadmin/scans/${scan.value.scan_id}/script${cq()}`, fileName.value); } catch (e) { message.error(L('The script could not be downloaded.', 'No se pudo descargar el script.')); }
}
async function upload(file) {
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    await http.postForm(`itadmin/scans/${scan.value.scan_id}/upload-file`, fd);
    await poll();
  } catch (e) { message.error(errorOf(e)); } finally { uploading.value = false; }
  return false;
}
function copy(t) { navigator.clipboard?.writeText(t).then(() => message.success(L('Copied', 'Copiado'))); }
function review() { const id = scan.value.scan_id; close(); router.push(`/itadmin/scans/${id}`); }
onUnmounted(stop);
</script>

<style scoped>
.sm-lead { color: #4b5563; font-size: 13px; }
.sm-steps { padding-left: 18px; }
.sm-steps li { margin-bottom: 8px; }
.sm-code { display: flex; align-items: flex-start; gap: 4px; background: #0f172a; color: #e2e8f0; border-radius: 6px; padding: 6px 8px; margin-top: 4px; }
.sm-code code { flex: 1; font-size: 12px; word-break: break-all; white-space: pre-wrap; }
.sm-code .ant-btn { color: #e2e8f0; }
.sm-long { max-height: 140px; overflow-y: auto; }
.sm-upload { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; font-size: 12px; color: #4b5563; }
.sm-wait { margin-top: 12px; display: flex; gap: 8px; align-items: center; }
.sm-muted { color: #9ca3af; font-size: 12px; }
</style>
