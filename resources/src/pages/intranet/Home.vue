<template>
  <div class="page intranet-home">
    <PageHeader :title="$t('Intranet')" :subtitle="$t('Intranet_Welcome')">
      <template #extra>
        <router-link :to="{ name: 'intranet-calendar' }"><a-button><template #icon><CalendarOutlined /></template>{{ $t('Intranet_Calendar') }}</a-button></router-link>
        <router-link v-if="data.can_manage" :to="{ name: 'intranet-admin-news' }">
          <a-button type="primary"><template #icon><SettingOutlined /></template>{{ $t('Intranet_Management') }}</a-button>
        </router-link>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-row :gutter="[16, 16]">
        <!-- news -->
        <a-col :xs="24" :lg="16">
          <a-card :title="$t('Intranet_Latest_News')" size="small">
            <template #extra><router-link :to="{ name: 'intranet-news' }">{{ $t('Intranet_All_News') }}</router-link></template>
            <a-empty v-if="!data.news.length" :description="$t('Intranet_Nothing_Yet')" />
            <div v-else class="news-list">
              <router-link v-for="p in data.news" :key="p.id" :to="{ name: 'intranet-news-show', params: { id: p.id } }" class="news-item">
                <div v-if="p.cover_url" class="news-cover"><img :src="p.cover_url" :alt="p.title" loading="lazy" /></div>
                <div class="news-body">
                  <div class="news-meta">
                    <a-tag v-if="p.pinned" color="red"><PushpinFilled /> {{ $t('Intranet_Pinned') }}</a-tag>
                    <a-tag :color="categoryColor(p.category)">{{ categoryLabel(p.category) }}</a-tag>
                    <span class="muted">{{ fmtDate(p.published_at || p.created_at) }}<template v-if="p.author"> · {{ personName(p.author) }}</template></span>
                  </div>
                  <div class="news-title">{{ p.title }}</div>
                  <div class="news-summary">{{ p.summary }}</div>
                </div>
              </router-link>
            </div>
          </a-card>
        </a-col>

        <!-- side widgets -->
        <a-col :xs="24" :lg="8">
          <a-space direction="vertical" :size="16" style="width: 100%">
            <a-card size="small">
              <template #title><GiftOutlined /> {{ $t('Intranet_Birthdays_Month') }}</template>
              <a-empty v-if="!data.birthdays_month.length" :image="simpleImage" :description="$t('Intranet_Nothing_Yet')" />
              <div v-for="b in data.birthdays_month" :key="b.id" class="bday" :class="{ today: b.is_today }">
                <a-avatar :style="{ background: b.is_today ? '#fa541c' : '#1677ff' }">{{ b.initials }}</a-avatar>
                <span class="bday-name">{{ b.name }}</span>
                <a-tag v-if="b.is_today" color="volcano">{{ $t('Intranet_Today') }}</a-tag>
                <span v-else class="muted">{{ dayLabel(b.date) }}</span>
              </div>
              <template v-if="nextMonthBirthdays.length">
                <a-divider style="margin: 8px 0" orientation="left" plain>{{ $t('Intranet_Upcoming_Birthdays') }}</a-divider>
                <div v-for="b in nextMonthBirthdays" :key="'u' + b.id" class="bday">
                  <a-avatar size="small">{{ b.initials }}</a-avatar>
                  <span class="bday-name">{{ b.name }}</span>
                  <span class="muted">{{ dayLabel(b.date) }}</span>
                </div>
              </template>
            </a-card>

            <a-card size="small">
              <template #title><CalendarOutlined /> {{ $t('Intranet_Upcoming_Events') }}</template>
              <template #extra><router-link :to="{ name: 'intranet-calendar' }">{{ $t('Intranet_Calendar') }}</router-link></template>
              <a-empty v-if="!data.events.length" :image="simpleImage" :description="$t('Intranet_Nothing_Yet')" />
              <div v-for="ev in data.events" :key="ev.id" class="event">
                <div class="event-date" :style="{ borderColor: ev.color || '#1677ff' }">
                  <div class="d">{{ dayjs(ev.starts_at).format('D') }}</div>
                  <div class="m">{{ dayjs(ev.starts_at).format('MMM') }}</div>
                </div>
                <div class="event-info">
                  <div class="event-title">{{ ev.title }}</div>
                  <div class="muted">{{ eventWhen(ev) }}</div>
                  <div v-if="ev.location" class="muted"><EnvironmentOutlined /> {{ ev.location }}</div>
                </div>
              </div>
            </a-card>

            <a-card v-if="data.pages.length" size="small">
              <template #title><FileTextOutlined /> {{ $t('Intranet_Company_Pages') }}</template>
              <div v-for="pg in data.pages" :key="pg.id" class="page-link">
                <router-link :to="{ name: 'intranet-page', params: { slug: pg.slug } }">{{ pg.title }}</router-link>
              </div>
            </a-card>
          </a-space>
        </a-col>

        <!-- files -->
        <a-col :xs="24" :lg="12">
          <a-card size="small">
            <template #title><FolderOpenOutlined /> {{ $t('Intranet_Recent_Files') }}</template>
            <template #extra><router-link :to="{ name: 'intranet-files' }">{{ $t('Intranet_Files') }}</router-link></template>
            <a-empty v-if="!data.files.length" :image="simpleImage" :description="$t('Intranet_Nothing_Yet')" />
            <div v-for="f in data.files" :key="f.id" class="file-row">
              <span class="ext" :style="{ background: fileIconColor(f.extension) }">{{ (f.extension || '?').toUpperCase() }}</span>
              <div class="file-info">
                <div class="file-title">{{ f.title }}</div>
                <div class="muted">{{ f.folder ? f.folder + ' · ' : '' }}{{ formatBytes(f.size) }} · {{ fmtDate(f.created_at) }}</div>
              </div>
              <a-button size="small" @click="download(f)"><template #icon><DownloadOutlined /></template></a-button>
            </div>
          </a-card>
        </a-col>

        <!-- gallery -->
        <a-col :xs="24" :lg="12">
          <a-card size="small">
            <template #title><PictureOutlined /> {{ $t('Intranet_Gallery_Highlights') }}</template>
            <template #extra><router-link :to="{ name: 'intranet-gallery' }">{{ $t('Intranet_Albums') }}</router-link></template>
            <a-empty v-if="!data.photos.length" :image="simpleImage" :description="$t('Intranet_Nothing_Yet')" />
            <div v-else class="photo-grid">
              <router-link v-for="ph in data.photos" :key="ph.id" :to="{ name: 'intranet-album', params: { id: ph.album_id } }" class="photo">
                <img :src="ph.image_url" :alt="ph.title || ''" loading="lazy" />
              </router-link>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Intranet home (intranet_view): latest news (pinned first), birthdays this
 * month + upcoming, upcoming events, company pages, recent files and the
 * latest gallery photos -- all for the acting company only.
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { Empty, message } from 'ant-design-vue';
import {
  CalendarOutlined, SettingOutlined, PushpinFilled, GiftOutlined, EnvironmentOutlined, FileTextOutlined,
  FolderOpenOutlined, DownloadOutlined, PictureOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import {
  categoryLabel, categoryColor, fmtDate, eventWhen, personName, formatBytes, fileIconColor, downloadFile, apiError,
} from './intranetShared';

const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const loading = ref(true);
const data = ref({ news: [], events: [], birthdays_month: [], upcoming_birthdays: [], files: [], albums: [], photos: [], pages: [], can_manage: false });

// Upcoming (next 30 days) that are not already listed under "this month".
const nextMonthBirthdays = computed(() => {
  const month = dayjs().month() + 1;
  return data.value.upcoming_birthdays.filter(b => b.month !== month).slice(0, 5);
});
const dayLabel = d => dayjs(d).format('ddd D MMM');

async function load() {
  loading.value = true;
  try {
    data.value = { ...data.value, ...(await http.get('intranet/home')) };
  } catch (e) {
    message.error(apiError(e));
  } finally {
    loading.value = false;
  }
}

async function download(f) {
  try { await downloadFile(f); } catch (e) { message.error(apiError(e)); }
}

onMounted(load);
</script>

<style scoped>
.muted { color: var(--ant-color-text-tertiary, #8c8c8c); font-size: 12px; }
.news-list { display: flex; flex-direction: column; }
.news-item { display: flex; gap: 12px; padding: 12px 4px; border-bottom: 1px solid rgba(5, 5, 5, 0.06); color: inherit; }
.news-item:last-child { border-bottom: 0; }
.news-item:hover .news-title { color: var(--ant-color-primary, #1677ff); }
.news-cover { flex: 0 0 140px; height: 90px; border-radius: 6px; overflow: hidden; background: #f5f5f5; }
.news-cover img { width: 100%; height: 100%; object-fit: cover; }
.news-body { flex: 1; min-width: 0; }
.news-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin-bottom: 4px; }
.news-title { font-weight: 600; font-size: 15px; }
.news-summary { color: var(--ant-color-text-secondary, #595959); font-size: 13px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.bday { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
.bday.today .bday-name { font-weight: 600; }
.bday-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.event { display: flex; gap: 10px; padding: 6px 0; }
.event-date { flex: 0 0 44px; text-align: center; border-left: 3px solid; padding-left: 6px; }
.event-date .d { font-size: 18px; font-weight: 700; line-height: 1.1; }
.event-date .m { font-size: 11px; text-transform: uppercase; }
.event-info { min-width: 0; }
.event-title { font-weight: 500; }
.page-link { padding: 3px 0; }
.file-row { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px solid rgba(5, 5, 5, 0.04); }
.file-row:last-child { border-bottom: 0; }
.ext { flex: 0 0 44px; height: 30px; border-radius: 4px; color: #fff; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.file-info { flex: 1; min-width: 0; }
.file-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.photo-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.photo { aspect-ratio: 1; border-radius: 6px; overflow: hidden; background: #f5f5f5; display: block; }
.photo img { width: 100%; height: 100%; object-fit: cover; transition: transform .2s; }
.photo:hover img { transform: scale(1.05); }
@media (max-width: 575px) {
  .news-cover { flex-basis: 90px; height: 64px; }
  .photo-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>
