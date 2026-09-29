<template>
  <div class="page">
    <PageHeader :title="$t('Intranet_Calendar')" :breadcrumb="[$t('Intranet'), $t('Intranet_Calendar')]">
      <template #extra>
        <a-checkbox v-model:checked="show.events"><span class="dot ev"></span>{{ $t('Intranet_Show_Events') }}</a-checkbox>
        <a-checkbox v-model:checked="show.birthdays"><span class="dot bd"></span>{{ $t('Intranet_Show_Birthdays') }}</a-checkbox>
        <a-checkbox v-model:checked="show.holidays"><span class="dot ho"></span>{{ $t('Intranet_Show_Holidays') }}</a-checkbox>
      </template>
    </PageHeader>

    <a-row :gutter="16">
      <a-col :xs="24" :xl="17">
        <a-card size="small">
          <a-spin :spinning="loading">
            <a-calendar v-model:value="current" @panel-change="onPanelChange" @select="onSelect">
              <template #dateCellRender="{ current: day }">
                <ul class="cell-items">
                  <li v-for="it in itemsOn(day).slice(0, 3)" :key="it.key" :class="it.type" :style="it.color ? { borderLeftColor: it.color } : null" :title="it.title">
                    {{ it.label }}
                  </li>
                  <li v-if="itemsOn(day).length > 3" class="more">+{{ itemsOn(day).length - 3 }}</li>
                </ul>
              </template>
            </a-calendar>
          </a-spin>
        </a-card>
      </a-col>

      <a-col :xs="24" :xl="7">
        <a-space direction="vertical" :size="16" style="width: 100%">
          <a-card size="small" :title="current.format('dddd, D MMMM YYYY')">
            <a-empty v-if="!selectedItems.length" :description="$t('Intranet_Nothing_Yet')" />
            <div v-for="it in selectedItems" :key="'s' + it.key" class="day-item" :class="it.type">
              <div class="day-title"><GiftOutlined v-if="it.type === 'birthday'" /><FlagOutlined v-else-if="it.type === 'holiday'" /><CalendarOutlined v-else /> {{ it.title }}</div>
              <div v-if="it.when" class="muted">{{ it.when }}</div>
              <div v-if="it.location" class="muted"><EnvironmentOutlined /> {{ it.location }}</div>
              <div v-if="it.description" class="desc">{{ it.description }}</div>
            </div>
          </a-card>

          <a-card size="small">
            <template #title><GiftOutlined /> {{ $t('Intranet_Upcoming_Birthdays') }}</template>
            <a-empty v-if="!upcoming.length" :description="$t('Intranet_Nothing_Yet')" />
            <div v-for="b in upcoming" :key="b.id" class="bday" :class="{ today: b.is_today }">
              <a-avatar size="small" :style="{ background: b.is_today ? '#fa541c' : '#eb2f96' }">{{ b.initials }}</a-avatar>
              <span class="bday-name">{{ b.name }}</span>
              <a-tag v-if="b.is_today" color="volcano">{{ $t('Intranet_Today') }}</a-tag>
              <span v-else class="muted">{{ dayjs(b.date).format('ddd D MMM') }}</span>
            </div>
          </a-card>
        </a-space>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Intranet -> calendar (intranet_view): month view of the company's events,
 * active employees' birthdays and HRM holidays. GET intranet/calendar?month=
 * returns the month plus a margin of the neighbouring weeks the grid shows.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { GiftOutlined, FlagOutlined, CalendarOutlined, EnvironmentOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { eventWhen, apiError } from './intranetShared';

const { t } = useI18n();
const current = ref(dayjs());
const loading = ref(false);
const loadedMonth = ref('');
const byDate = ref({});
const upcoming = ref([]);
const show = reactive({ events: true, birthdays: true, holidays: true });

function addTo(map, date, item) {
  (map[date] = map[date] || []).push(item);
}

function eachDay(from, to, fn, min, max) {
  let d = dayjs(from).startOf('day');
  const end = dayjs(to || from).startOf('day');
  let guard = 0;
  while (!d.isAfter(end) && guard++ < 400) {
    const s = d.format('YYYY-MM-DD');
    if (s >= min && s <= max) fn(s);
    d = d.add(1, 'day');
  }
}

async function loadMonth(month) {
  if (month === loadedMonth.value) return;
  loading.value = true;
  try {
    const r = await http.get('intranet/calendar', { month });
    const map = {};
    (r.holidays || []).forEach(h => eachDay(h.start_date, h.end_date, s => addTo(map, s, {
      key: `h${h.id}-${s}`, type: 'holiday', label: h.title, title: `${t('Intranet_Holiday')}: ${h.title}`, description: h.description,
    }), r.from, r.to));
    (r.birthdays || []).forEach(b => addTo(map, b.date, {
      key: `b${b.id}-${b.date}`, type: 'birthday', label: `🎂 ${b.name}`, title: t('Intranet_Birthday_Of', { name: b.name }),
    }));
    (r.events || []).forEach(ev => eachDay(ev.starts_at, ev.ends_at, s => addTo(map, s, {
      key: `e${ev.id}-${s}`, type: 'event', color: ev.color,
      label: ev.all_day ? ev.title : `${dayjs(ev.starts_at).format('HH:mm')} ${ev.title}`,
      title: ev.title, when: eventWhen(ev), location: ev.location, description: ev.description,
    }), r.from, r.to));
    byDate.value = map;
    loadedMonth.value = month;
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
}

const visible = it => (it.type === 'event' && show.events) || (it.type === 'birthday' && show.birthdays) || (it.type === 'holiday' && show.holidays);
const itemsOn = day => (byDate.value[day.format('YYYY-MM-DD')] || []).filter(visible);
const selectedItems = computed(() => itemsOn(current.value));

function onPanelChange(value) {
  loadMonth(value.format('YYYY-MM'));
}
function onSelect(value) {
  const m = value.format('YYYY-MM');
  if (m !== loadedMonth.value) loadMonth(m);
}

onMounted(async () => {
  loadMonth(current.value.format('YYYY-MM'));
  try { upcoming.value = (await http.get('intranet/birthdays', { days: 60 })).upcoming || []; } catch (e) { upcoming.value = []; }
});
</script>

<style scoped>
.cell-items { list-style: none; margin: 0; padding: 0; }
.cell-items li { font-size: 11px; line-height: 16px; margin-bottom: 2px; padding: 0 4px; border-left: 3px solid; border-radius: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cell-items li.event { border-left-color: #1677ff; background: rgba(22, 119, 255, .08); }
.cell-items li.birthday { border-left-color: #eb2f96; background: rgba(235, 47, 150, .08); }
.cell-items li.holiday { border-left-color: #52c41a; background: rgba(82, 196, 26, .1); }
.cell-items li.more { border: 0; color: #8c8c8c; }
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: -1px; }
.dot.ev { background: #1677ff; }
.dot.bd { background: #eb2f96; }
.dot.ho { background: #52c41a; }
.day-item { padding: 8px 0 8px 10px; border-left: 3px solid #1677ff; margin-bottom: 8px; }
.day-item.birthday { border-left-color: #eb2f96; }
.day-item.holiday { border-left-color: #52c41a; }
.day-title { font-weight: 600; }
.desc { font-size: 13px; white-space: pre-line; margin-top: 4px; }
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; }
.bday { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
.bday.today .bday-name { font-weight: 600; }
.bday-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
