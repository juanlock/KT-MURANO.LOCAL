<template>
  <div class="page">
    <PageHeader
      :title="$t('Direct_Network_Printing') || 'Direct Network Printing'"
      :breadcrumb="[$t('Settings'), 'Direct Network Printing']"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" style="margin-bottom: 16px">
        <a-alert
          type="info" show-icon style="margin-bottom: 16px"
          message="Send receipts directly to a network thermal printer over RAW/JetDirect (default port 9100). Leave this OFF to keep using the existing browser/OS print flow."
        />
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Enable Direct Network Printing"
              extra="When disabled, existing printing behavior is unchanged."
            >
              <a-switch v-model:checked="s.direct_network_printing" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Network Printer IP Address"
              extra="IPv4/IPv6 address or hostname of the printer on your local network."
            >
              <a-input
                v-model:value="s.network_printer_ip"
                placeholder="192.168.1.50"
                :disabled="!s.direct_network_printing"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              label="Network Printer Port"
              extra="Default RAW / JetDirect port is 9100."
            >
              <a-input-number
                v-model:value="s.network_printer_port"
                style="width: 100%" :min="1" :max="65535"
                :disabled="!s.direct_network_printing"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <!-- Label printer (raw TSPL, Print Barcode page) -->
      <a-card size="small" style="margin-bottom: 16px" :title="$t('Label_Printer')">
        <a-alert
          type="info" show-icon style="margin-bottom: 16px"
          :message="$t('Label_Printer_Hint')"
        />
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Enable_Direct_Label_Printing')">
              <a-switch v-model:checked="s.label_printer_enabled" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Label_Printer_Connection')">
              <a-select
                v-model:value="s.label_printer_connection"
                :disabled="!s.label_printer_enabled"
                :options="[
                  { value: 'windows', label: $t('Windows_USB_Printer') },
                  { value: 'network', label: $t('Network_IP_Printer') },
                  { value: 'qz', label: $t('QZ_Tray_Printer') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8" v-if="s.label_printer_connection !== 'network'">
            <a-form-item
              :label="$t('Windows_Printer_Name')"
              :extra="s.label_printer_connection === 'qz' ? $t('QZ_Printer_Name_Hint') : $t('Windows_Printer_Name_Hint')"
            >
              <a-input
                v-if="s.label_printer_connection !== 'qz'"
                v-model:value="s.label_printer_name"
                placeholder="4BARCODE 4B-2054L"
                :disabled="!s.label_printer_enabled"
              />
              <a-input-group v-else compact style="display: flex">
                <a-auto-complete
                  v-model:value="s.label_printer_name"
                  style="flex: 1"
                  :options="qzPrinters"
                  placeholder="4BARCODE 4B-2054L"
                  :disabled="!s.label_printer_enabled"
                />
                <a-button
                  :loading="detecting" :disabled="!s.label_printer_enabled"
                  :title="$t('Detect_Printers')" @click="detectQzPrinters"
                >⟳</a-button>
              </a-input-group>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Label_Render_Mode')" :extra="$t('Label_Render_Mode_Hint')">
              <a-select
                v-model:value="s.label_printer_render_mode"
                :disabled="!s.label_printer_enabled"
                :options="[
                  { value: 'native', label: $t('Render_Native') },
                  { value: 'raster', label: $t('Render_Raster') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" v-if="s.label_printer_connection === 'qz'">
            <a-alert type="info" show-icon style="margin-bottom: 16px">
              <template #message>{{ $t('QZ_Setup_Title') }}</template>
              <template #description>
                <ol class="qz-steps">
                  <li>{{ $t('QZ_Step_Install') }}</li>
                  <li>{{ $t('QZ_Step_Connect') }}</li>
                  <li>{{ $t('QZ_Step_Detect') }}</li>
                  <li>
                    {{ $t('QZ_Step_Cert') }}
                    <div style="margin-top: 6px">
                      <a-button size="small" @click="downloadQzCert">{{ $t('QZ_Download_Cert') }}</a-button>
                    </div>
                  </li>
                  <li>{{ $t('QZ_Step_Test') }}</li>
                  <li>{{ $t('QZ_Step_Save') }}</li>
                </ol>
                <p class="qz-note">{{ $t('QZ_Setup_Note') }}</p>
              </template>
            </a-alert>
          </a-col>
          <template v-if="s.label_printer_connection === 'network'">
            <a-col :xs="24" :md="5">
              <a-form-item label="IP">
                <a-input
                  v-model:value="s.label_printer_ip" placeholder="192.168.1.60"
                  :disabled="!s.label_printer_enabled"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="3">
              <a-form-item label="Port">
                <a-input-number
                  v-model:value="s.label_printer_port" style="width: 100%"
                  :min="1" :max="65535" :disabled="!s.label_printer_enabled"
                />
              </a-form-item>
            </a-col>
          </template>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Label_Printer_Dpi')" :extra="$t('Label_Printer_Dpi_Hint')">
              <a-select
                v-model:value="s.label_printer_dpi"
                :disabled="!s.label_printer_enabled"
                :options="[
                  { value: 203, label: '203 dpi' },
                  { value: 300, label: '300 dpi' },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Label_Width_Mm')" :extra="$t('Label_Size_Hint')">
              <a-input-number
                v-model:value="s.label_printer_width_mm" style="width: 100%"
                :min="20" :max="200" :step="0.5" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Label_Height_Mm')">
              <a-input-number
                v-model:value="s.label_printer_height_mm" style="width: 100%"
                :min="10" :max="200" :step="0.5" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Label_Gap')">
              <a-input-number
                v-model:value="s.label_printer_gap_mm" style="width: 100%"
                :min="0" :max="10" :step="0.5" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Print_Darkness')" :extra="'0–15'">
              <a-input-number
                v-model:value="s.label_printer_density" style="width: 100%"
                :min="0" :max="15" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Print_Speed')" :extra="'ips'">
              <a-input-number
                v-model:value="s.label_printer_speed" style="width: 100%"
                :min="1" :max="8" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Label_Offset_X')" :extra="$t('Label_Offset_X_Hint')">
              <a-input-number
                v-model:value="s.label_printer_offset_x_mm" style="width: 100%"
                :min="-10" :max="10" :step="0.5" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Label_Offset_Y')" :extra="$t('Label_Offset_Y_Hint')">
              <a-input-number
                v-model:value="s.label_printer_offset_y_mm" style="width: 100%"
                :min="-10" :max="10" :step="0.5" :disabled="!s.label_printer_enabled"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Feed_To_Tear')" :extra="$t('Feed_To_Tear_Hint')">
              <a-switch v-model:checked="s.label_printer_tear" :disabled="!s.label_printer_enabled" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Print_Direction')">
              <a-select
                v-model:value="s.label_printer_direction"
                :disabled="!s.label_printer_enabled"
                :options="[{ value: 0, label: '0' }, { value: 1, label: '1 (180°)' }]"
              />
            </a-form-item>
          </a-col>
        </a-row>
        <a-space>
          <a-button :loading="testing" :disabled="!s.label_printer_enabled" @click="testLabelPrint">
            {{ $t('Test_Print') }}
          </a-button>
          <a-button
            :loading="calibrating" :disabled="!s.label_printer_enabled"
            :title="$t('Calibrate_Labels_Hint')" @click="calibrateLabels"
          >
            {{ $t('Calibrate_Labels') }}
          </a-button>
          <a-button
            :loading="dpiTesting" :disabled="!s.label_printer_enabled"
            :title="$t('Find_Resolution_Hint')" @click="dpiTest"
          >
            {{ $t('Find_Resolution') }}
          </a-button>
        </a-space>
      </a-card>

      <a-button type="primary" size="large" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Direct Network Printing — own tab like legacy. Reads/writes only its three
 * pos_settings fields (update_pos_settings applies just the posted fields, so
 * this page can't clobber anything owned by POS Settings / POS Receipt).
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { qzPrintRaw, qzListPrinters, isQzConnectError } from '../../lib/qzPrint';
import { renderLabelRaster } from '../../lib/labelRaster';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const calibrating = ref(false);
const dpiTesting = ref(false);
const detecting = ref(false);
const qzPrinters = ref([]);
const s = ref({});

function labelPrinterPayload() {
  return {
    label_printer_connection: ['network', 'qz'].includes(s.value.label_printer_connection)
      ? s.value.label_printer_connection : 'windows',
    label_printer_name: (s.value.label_printer_name || '').toString().trim() || null,
    label_printer_ip: (s.value.label_printer_ip || '').toString().trim() || null,
    label_printer_port: s.value.label_printer_port ? Number(s.value.label_printer_port) : null,
    label_printer_render_mode: s.value.label_printer_render_mode === 'raster' ? 'raster' : 'native',
    label_printer_dpi: Number(s.value.label_printer_dpi) === 300 ? 300 : 203,
    label_printer_width_mm: Number(s.value.label_printer_width_mm) || 50,
    label_printer_height_mm: Number(s.value.label_printer_height_mm) || 30,
    label_printer_gap_mm: Number(s.value.label_printer_gap_mm ?? 2),
    label_printer_tear: s.value.label_printer_tear ? 1 : 0,
    label_printer_offset_x_mm: Number(s.value.label_printer_offset_x_mm) || 0,
    label_printer_offset_y_mm: Number(s.value.label_printer_offset_y_mm) || 0,
    label_printer_density: Number(s.value.label_printer_density ?? 8),
    label_printer_speed: Number(s.value.label_printer_speed ?? 3),
    label_printer_direction: Number(s.value.label_printer_direction ?? 0),
  };
}

async function save() {
  saving.value = true;
  try {
    // Same payload shape as legacy.
    await http.put(`pos_settings/${s.value.id}`, {
      direct_network_printing: s.value.direct_network_printing ? 1 : 0,
      network_printer_ip: (s.value.network_printer_ip || '').toString().trim() || null,
      network_printer_port: s.value.network_printer_port ? Number(s.value.network_printer_port) : null,
      label_printer_enabled: s.value.label_printer_enabled ? 1 : 0,
      ...labelPrinterPayload(),
    });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

// Prints a sample label using the current (possibly unsaved) form values.
// For the QZ Tray connection the server returns the raw TSPL payload and the
// browser delivers it to the local printer itself.
async function testLabelPrint() {
  testing.value = true;
  try {
    const payload = labelPrinterPayload();
    // Raster mode: draw the sample label here, at the same design and sticker
    // size the server would have used, and send the image instead.
    if (payload.label_printer_render_mode === 'raster') {
      const sample = await http.get('label_printer_test_design', {
        width_mm: payload.label_printer_width_mm,
        height_mm: payload.label_printer_height_mm,
        printer_dpi: payload.label_printer_dpi,
        offset_x_mm: payload.label_printer_offset_x_mm,
        offset_y_mm: payload.label_printer_offset_y_mm,
      });
      payload.rasters = [renderLabelRaster(sample.label, sample.design)];
    }
    const res = await http.post('label_printer_test', payload);
    if (res && res.qz) {
      await qzPrintRaw(res.printer, res.payload_base64);
      message.success(t('Sent_to_label_printer'));
    } else {
      message.success((res && res.message) || t('Sent_to_label_printer'));
    }
  } catch (e) {
    message.error(
      isQzConnectError(e) ? t('QZ_Not_Running')
        : (e?.data?.message || e?.message || t('Label_print_failed'))
    );
  } finally {
    testing.value = false;
  }
}

// Save the signing certificate as override.crt — installed into the QZ Tray
// folder on the client machine, it makes printing fully silent (no prompt).
async function downloadQzCert() {
  try {
    const res = await http.get('qz/certificate');
    if (!res || !res.certificate) {
      message.error(res?.message || t('InvalidData'));
      return;
    }
    const blob = new Blob([res.certificate], { type: 'application/x-x509-ca-cert' });
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = 'override.crt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(href);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

// Auto-calibrate gap sensing: the printer feeds a few labels and measures
// them. Run once after loading a roll, or whenever positions drift.
async function calibrateLabels() {
  calibrating.value = true;
  try {
    const res = await http.post('label_printer_calibrate', labelPrinterPayload());
    if (res && res.qz) {
      await qzPrintRaw(res.printer, res.payload_base64);
    }
    message.success(t('Calibration_Sent'));
  } catch (e) {
    message.error(
      isQzConnectError(e) ? t('QZ_Not_Running')
        : (e?.data?.message || e?.message || t('Label_print_failed'))
    );
  } finally {
    calibrating.value = false;
  }
}

// Prints two frames, marked 203 and 300 — the one matching the sticker edges
// is the printhead's true resolution. Removes all guesswork about dpi.
async function dpiTest() {
  dpiTesting.value = true;
  try {
    const res = await http.post('label_printer_dpi_test', labelPrinterPayload());
    if (res && res.qz) {
      await qzPrintRaw(res.printer, res.payload_base64);
    }
    message.success(t('Dpi_Test_Sent'), 8);
  } catch (e) {
    message.error(
      isQzConnectError(e) ? t('QZ_Not_Running')
        : (e?.data?.message || e?.message || t('Label_print_failed'))
    );
  } finally {
    dpiTesting.value = false;
  }
}

// Fill the printer-name autocomplete from the QZ Tray on this computer.
async function detectQzPrinters() {
  detecting.value = true;
  try {
    qzPrinters.value = (await qzListPrinters()).map((p) => ({ value: p }));
  } catch (e) {
    message.error(isQzConnectError(e) ? t('QZ_Not_Running') : (e?.message || t('Label_print_failed')));
  } finally {
    detecting.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_pos_Settings_api');
    const ps = data.pos_settings || {};
    ps.direct_network_printing = ps.direct_network_printing === true || !!Number(ps.direct_network_printing ?? 0);
    ps.network_printer_port = ps.network_printer_port || 9100;
    ps.label_printer_enabled = ps.label_printer_enabled === true || !!Number(ps.label_printer_enabled ?? 0);
    ps.label_printer_connection = ['network', 'qz'].includes(ps.label_printer_connection)
      ? ps.label_printer_connection : 'windows';
    ps.label_printer_render_mode = ps.label_printer_render_mode === 'raster' ? 'raster' : 'native';
    ps.label_printer_dpi = Number(ps.label_printer_dpi) === 300 ? 300 : 203;
    ps.label_printer_width_mm = Number(ps.label_printer_width_mm) || 50;
    ps.label_printer_height_mm = Number(ps.label_printer_height_mm) || 30;
    ps.label_printer_port = ps.label_printer_port || 9100;
    ps.label_printer_gap_mm = Number(ps.label_printer_gap_mm ?? 2);
    ps.label_printer_tear = ps.label_printer_tear === true || !!Number(ps.label_printer_tear ?? 0);
    ps.label_printer_offset_x_mm = Number(ps.label_printer_offset_x_mm) || 0;
    ps.label_printer_offset_y_mm = Number(ps.label_printer_offset_y_mm) || 0;
    ps.label_printer_density = Number(ps.label_printer_density ?? 8);
    ps.label_printer_speed = Number(ps.label_printer_speed ?? 3);
    ps.label_printer_direction = Number(ps.label_printer_direction ?? 0);
    s.value = ps;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
/* Colors are inherited from the alert so both themes stay correct.
   Numbering lives in the translated strings — antd's reset removes list
   markers, and keeping it in the text also survives RTL locales. */
.qz-steps {
  list-style: none;
  margin: 0;
  padding-left: 0;
}
.qz-steps li {
  margin-bottom: 6px;
}
.qz-note {
  margin: 10px 0 0;
  opacity: 0.75;
}
</style>
