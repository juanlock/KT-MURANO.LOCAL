<template>
  <!-- One service job task, full details + actions (Tasks page: Day view and the side panel). -->
  <a-card size="small" class="tc" :class="`tc--${task.status}`">
    <div class="tc-head">
      <span class="tc-time">{{ timeLabel(task) }}</span>
      <a-tag :color="statusColor(task.status)" style="margin: 0">{{ statusLabel(task.status) }}</a-tag>
    </div>
    <!-- Customer first and big: who the crew is going to. -->
    <div class="tc-customer"><UserOutlined /> {{ task.client || 'No customer' }}</div>
    <div class="tc-title">{{ task.service_item || 'Service job' }}</div>
    <div class="tc-meta">
      <span>{{ task.Ref }}</span>
      <span v-if="task.job_type">· {{ task.job_type }}</span>
      <span v-if="task.location">· {{ task.location }}</span>
    </div>

    <div class="tc-client">
      <a v-if="address(task)" :href="`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address(task))}`" target="_blank" rel="noopener">
        <EnvironmentOutlined /> {{ address(task) }}
      </a>
      <a v-if="task.client_phone" :href="`tel:${task.client_phone}`"><PhoneOutlined /> {{ task.client_phone }}</a>
    </div>

    <div v-if="task.reported_issue" class="tc-text"><strong>Work:</strong> {{ task.reported_issue }}</div>
    <div v-if="task.notes" class="tc-notes">{{ task.notes }}</div>

    <!-- Operator: supervisors assign / reassign; others just see it. -->
    <div class="tc-tech">
      <ToolOutlined />
      <a-select
        v-if="canAssign" :value="task.assign_all ? 'all' : (task.technician_id || undefined)" size="small" allow-clear style="min-width: 200px"
        placeholder="Not assigned" :options="assignOptions" @change="v => emit('assign', task, v)"
      />
      <span v-else>{{ task.assign_all ? 'All operators (crew job)' : (task.technician || 'Not assigned') }}</span>
      <a-tag v-if="task.assign_all" color="geekblue" style="margin: 0">All operators</a-tag>
    </div>

    <div class="tc-actions">
      <a-button v-if="['pending', 'intake', 'approved'].includes(task.status)" @click="emit('start', task)">
        <template #icon><PlayCircleOutlined /></template> Start
      </a-button>
      <a-button v-if="task.status !== 'completed'" type="primary" @click="emit('complete', task)">
        <template #icon><CheckOutlined /></template> Complete
      </a-button>
      <a-button v-else @click="emit('reopen', task)">Reopen</a-button>
      <a-button @click="emit('note', task)"><template #icon><EditOutlined /></template> Note</a-button>
      <a-button v-if="canOpenJob" type="link" @click="$router.push(`/service/jobs/details/${task.id}`)">Open job</a-button>
    </div>
  </a-card>
</template>

<script setup>
import { UserOutlined, EnvironmentOutlined, PhoneOutlined, ToolOutlined, PlayCircleOutlined, CheckOutlined, EditOutlined } from '@ant-design/icons-vue';
import { statusColor, statusLabel, timeLabel, address } from '../lib/taskFormat';

defineProps({
  task: { type: Object, required: true },
  canAssign: { type: Boolean, default: false },
  canOpenJob: { type: Boolean, default: false },
  assignOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(['start', 'complete', 'reopen', 'note', 'assign']);
</script>

<style scoped>
.tc { border-left: 4px solid #faad14; }
.tc--in_progress { border-left-color: #1677ff; }
.tc--completed { border-left-color: #52c41a; opacity: 0.85; }
.tc-head { display: flex; justify-content: space-between; align-items: center; }
.tc-time { font-weight: 600; }
.tc-customer { font-size: 19px; font-weight: 700; margin-top: 6px; line-height: 1.25; }
.tc-title { font-size: 14px; font-weight: 600; margin-top: 2px; }
.tc-meta { font-size: 12px; opacity: 0.7; display: flex; gap: 4px; flex-wrap: wrap; }
.tc-client { margin-top: 8px; display: flex; flex-direction: column; gap: 2px; }
.tc-text { margin-top: 8px; }
.tc-notes { margin-top: 6px; font-size: 12px; white-space: pre-line; opacity: 0.75; }
.tc-tech { margin-top: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.tc-actions { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
</style>
