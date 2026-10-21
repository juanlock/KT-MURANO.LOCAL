<template>
  <a-spin :spinning="loading">
    <div v-if="card" class="rc">
      <div class="rc-actions">
        <a-space>
          <a-button :disabled="!card.subjects?.length" :loading="downloading" @click="downloadPdf">
            <template #icon><DownloadOutlined /></template>
            {{ s('Download PDF') }}
          </a-button>
          <a-button type="primary" :disabled="!card.subjects?.length" @click="print">
            <template #icon><PrinterOutlined /></template>
            {{ s('Print') }}
          </a-button>
        </a-space>
      </div>

      <!-- Header: school and student (modelled on MINEDU's "Reporte de notas de estudiante"). -->
      <div class="rc-head">
        <img v-if="card.school.logo_url" :src="card.school.logo_url" class="rc-logo" alt="" />
        <div>
          <div class="rc-school">{{ card.school.name }}</div>
          <div class="rc-muted">{{ [card.school.address, card.school.phone].filter(Boolean).join(' · ') }}</div>
        </div>
        <div class="rc-title">{{ s('REPORT CARD') }}<br /><span>{{ card.year ? s('School year {name}', { name: card.year.name }) : '' }}</span></div>
      </div>
      <div class="rc-info">
        <div><b>{{ s('Student') }}:</b> {{ card.student.name }}</div>
        <div><b>{{ s('Code') }}:</b> {{ card.student.admission_number }}</div>
        <div><b>{{ s('Grade / section') }}:</b> {{ card.enrollment ? `${card.enrollment.class_name || ''} ${card.enrollment.section_name || ''}` : '—' }}</div>
        <div><b>{{ s('Roll') }}:</b> {{ card.enrollment?.roll_number || '—' }}</div>
        <div><b>{{ s('Homeroom teacher') }}:</b> {{ card.enrollment?.tutor || '—' }}</div>
        <div><b>{{ s('Guardian') }}:</b> {{ card.student.guardian_name || '—' }}</div>
      </div>

      <a-empty v-if="!card.subjects?.length" :description="s('No marks for this school year yet')" />
      <template v-else>
        <table class="rc-table">
          <thead>
            <tr>
              <th class="left">{{ s('Subject') }}</th>
              <th v-for="t in card.terms" :key="t.id">{{ termLabel(t) }}</th>
              <th>{{ s('Final avg.') }}</th>
              <th>{{ s('Grade') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sub in card.subjects" :key="sub.subject_id">
              <td class="left">{{ sub.name }} <span class="rc-muted">({{ s('weight') }} {{ sub.credit }})</span></td>
              <td v-for="t in card.terms" :key="t.id" :class="markClass(sub.marks[t.id])">{{ markText(sub.marks[t.id]) }}</td>
              <td :class="markClass(sub.average)" class="bold">{{ sub.average ?? '—' }}</td>
              <td :class="markClass(sub.average)">{{ sub.grade || '—' }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td class="left bold">{{ s('Term average') }}</td>
              <td v-for="t in card.terms" :key="t.id" :class="markClass(t.average)" class="bold">{{ t.average ?? '—' }}</td>
              <td colspan="2"></td>
            </tr>
            <tr>
              <td class="left">{{ s('Absences / late') }}</td>
              <td v-for="t in card.terms" :key="t.id">{{ t.attendance.days ? `${t.attendance.absent} / ${t.attendance.late}` : '—' }}</td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>

        <!-- Weighted average: Σ(subject average × weight) ÷ Σ weights. -->
        <div class="rc-summary">
          <div class="rc-box">
            <div class="rc-box-label">{{ s('Weighted average') }}</div>
            <div class="rc-box-value" :class="markClass(card.summary.weighted_average)">
              {{ card.summary.weighted_average ?? '—' }} <small>{{ card.summary.weighted_grade || '' }}</small>
            </div>
            <div class="rc-muted">{{ s('Σ (subject average × weight) ÷ Σ weights ({w})', { w: card.summary.credits }) }}</div>
          </div>
          <div class="rc-box">
            <div class="rc-box-label">{{ s('Final situation') }}</div>
            <div class="rc-box-value" :class="situationClass">{{ situationText }}</div>
            <div class="rc-muted">{{ s('Pass mark: {p} of {m}', { p: card.scale.pass, m: card.scale.max }) }}</div>
          </div>
        </div>
        <div class="rc-legend">
          <span class="mark-bad">■</span> {{ s('Below the pass mark') }}
          <span class="mark-ok">■</span> {{ s('Pass mark or above') }}
          · AUS = {{ s('absent from the exam') }}
        </div>
      </template>
    </div>
  </a-spin>
</template>

<script setup>
/**
 * Report card ("Libreta", 2026-10-21) for one student and school year: one
 * column per term exam, each subject's average, the term averages, absences,
 * the weighted average and the final situation. Marks below the pass mark in
 * red, from the pass mark up in blue. Print = an A4 page in the style of
 * MINEDU's "Reporte de notas de estudiante".
 * GET school/students/{id}/report-card
 */
import { ref, computed, onMounted, watch } from 'vue';
import { message } from 'ant-design-vue';
import { PrinterOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useSchoolText } from './lang/useSchoolText';
import ES from './lang/ReportCard.es.js';

const props = defineProps({ studentId: { type: [String, Number], required: true } });
const { s } = useSchoolText(ES);

const card = ref(null);
const loading = ref(false);

const ROMAN = { term_1: 'I', term_2: 'II', term_3: 'III', term_4: 'IV' };
function termLabel(t) {
  return ROMAN[t.term] ? s('Term {n}', { n: ROMAN[t.term] }) : t.name;
}
const isNum = v => typeof v === 'number';
function markText(v) {
  return v === null || v === undefined ? '—' : (v === 'AUS' ? 'AUS' : v);
}
function markClass(v) {
  if (v === 'AUS') return 'mark-bad';
  if (!isNum(v) || !card.value) return '';
  return v < card.value.scale.pass ? 'mark-bad' : 'mark-ok';
}
const situationText = computed(() => {
  const sm = card.value?.summary;
  if (!sm) return '—';
  if (sm.situation === 'in_progress') {
    return sm.failed_subjects ? s('In progress — {n} subject(s) below the pass mark', { n: sm.failed_subjects }) : s('In progress');
  }
  return sm.situation === 'passed' ? s('PASSED') : s('FAILED in {n} subject(s)', { n: sm.failed_subjects });
});
const situationClass = computed(() => (card.value?.summary?.failed_subjects ? 'mark-bad' : 'mark-ok'));

async function load() {
  loading.value = true;
  try {
    card.value = await http.get(`school/students/${props.studentId}/report-card`);
  } catch (e) {
    message.error(e?.data?.message || s('Could not load the report card'));
  } finally {
    loading.value = false;
  }
}

const esc = v => String(v ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/** A4 page in a new window, then the browser's print dialog. */
function print() {
  const c = card.value;
  if (!c) return;
  const cls = v => markClass(v);
  const rows = c.subjects.map(sub => `<tr><td class="left">${esc(sub.name)}</td>${
    c.terms.map(t => `<td class="${cls(sub.marks[t.id])}">${esc(markText(sub.marks[t.id]))}</td>`).join('')
  }<td class="${cls(sub.average)} b">${esc(sub.average ?? '—')}</td><td class="${cls(sub.average)}">${esc(sub.grade || '—')}</td></tr>`).join('');
  const issued = new Date().toLocaleString();
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(s('Report card'))} - ${esc(c.student.name)}</title>
<style>
  @page { size: A4; margin: 14mm; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #222; }
  .frame { border: 1px solid #444; padding: 14px 16px; }
  h1 { text-align: center; font-size: 15px; margin: 0 0 10px; letter-spacing: .5px; }
  .head { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
  .head img { height: 52px; }
  .school { font-weight: bold; font-size: 13px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 24px; margin: 8px 0 12px; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th { background: #d9d9d9; font-size: 10px; padding: 5px 4px; border: 1px solid #999; }
  td { border: 1px solid #bbb; padding: 4px; text-align: center; }
  td.left, th.left { text-align: left; }
  .b { font-weight: bold; }
  .bad { color: #d4380d; font-weight: bold; } .ok { color: #1d39c4; font-weight: bold; }
  .sum { display: flex; gap: 24px; margin-top: 12px; }
  .box { border: 1px solid #999; padding: 6px 10px; flex: 1; }
  .foot { margin-top: 18px; font-size: 10px; color: #555; }
  .sign { display: flex; justify-content: space-around; margin-top: 46px; }
  .sign div { border-top: 1px solid #444; width: 38%; text-align: center; padding-top: 4px; }
</style></head><body><div class="frame">
<h1>${esc(s('STUDENT REPORT CARD'))}</h1>
<div class="head">${c.school.logo_url ? `<img src="${esc(c.school.logo_url)}">` : ''}<div><div class="school">${esc(c.school.name)}</div><div>${esc([c.school.address, c.school.phone].filter(Boolean).join(' · '))}</div></div></div>
<div class="grid">
  <div><b>${esc(s('Student'))}:</b> ${esc(c.student.name)}</div><div><b>${esc(s('Code'))}:</b> ${esc(c.student.admission_number)}</div>
  <div><b>${esc(s('Grade / section'))}:</b> ${esc(c.enrollment ? `${c.enrollment.class_name || ''} ${c.enrollment.section_name || ''}` : '—')}</div><div><b>${esc(s('School year'))}:</b> ${esc(c.year?.name || '—')}</div>
  <div><b>${esc(s('Homeroom teacher'))}:</b> ${esc(c.enrollment?.tutor || '—')}</div><div><b>${esc(s('Guardian'))}:</b> ${esc(c.student.guardian_name || '—')}</div>
</div>
<table><thead><tr><th class="left">${esc(s('Subject'))}</th>${c.terms.map(t => `<th>${esc(termLabel(t))}</th>`).join('')}<th>${esc(s('Final avg.'))}</th><th>${esc(s('Grade'))}</th></tr></thead>
<tbody>${rows}</tbody>
<tfoot><tr><td class="left b">${esc(s('Term average'))}</td>${c.terms.map(t => `<td class="${cls(t.average)} b">${esc(t.average ?? '—')}</td>`).join('')}<td colspan="2"></td></tr>
<tr><td class="left">${esc(s('Absences / late'))}</td>${c.terms.map(t => `<td>${t.attendance.days ? `${t.attendance.absent} / ${t.attendance.late}` : '—'}</td>`).join('')}<td colspan="2"></td></tr></tfoot></table>
<div class="sum">
  <div class="box"><b>${esc(s('Weighted average'))}:</b> <span class="${cls(c.summary.weighted_average)}">${esc(c.summary.weighted_average ?? '—')} ${esc(c.summary.weighted_grade || '')}</span><br><small>${esc(s('Σ (subject average × weight) ÷ Σ weights ({w})', { w: c.summary.credits }))}</small></div>
  <div class="box"><b>${esc(s('Final situation'))}:</b> <span class="${c.summary.failed_subjects ? 'bad' : 'ok'}">${esc(situationText.value)}</span><br><small>${esc(s('Pass mark: {p} of {m}', { p: c.scale.pass, m: c.scale.max }))}</small></div>
</div>
<div class="sign"><div>${esc(s('Homeroom teacher'))}</div><div>${esc(s('Principal'))}</div></div>
<div class="foot">${esc(s('Report card issued by {school}', { school: c.school.name }))} · ${esc(s('Issued: {date}', { date: issued }))}</div>
</div><script>window.onload = function () { window.print(); };<\/script></body></html>`;
  const w = window.open('', '_blank');
  if (!w) { message.warning(s('Allow pop-ups to print')); return; }
  w.document.open();
  w.document.write(html.replace(/class="mark-bad/g, 'class="bad').replace(/class="mark-ok/g, 'class="ok'));
  w.document.close();
}

/* Download: the same A4 report card as a PDF file (jsPDF + autotable, as the list exports). */
const downloading = ref(false);
async function downloadPdf() {
  const c = card.value;
  if (!c) return;
  downloading.value = true;
  try {
    const [{ jsPDF }, autoTableMod] = await Promise.all([import('jspdf'), import('jspdf-autotable')]);
    const autoTable = autoTableMod.default || autoTableMod.autoTable;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    // Built-in fonts are cp1252: Spanish accents and × ÷ are fine, Σ is not.
    const txt = v => String(v ?? '').replace(/Σ\s?/g, s('Sum') + ' ');
    const RED = [212, 56, 13];
    const BLUE = [29, 57, 196];
    const W = doc.internal.pageSize.getWidth();

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text(txt(s('STUDENT REPORT CARD')), W / 2, 16, { align: 'center' });
    let x = 14;
    if (c.school.logo_url) {
      try {
        const blob = await (await fetch(c.school.logo_url)).blob();
        const dataUrl = await new Promise(res => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(blob); });
        doc.addImage(dataUrl, 14, 21, 16, 16);
        x = 33;
      } catch (e) { /* no logo */ }
    }
    doc.setFontSize(12);
    doc.text(txt(c.school.name), x, 27);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.text(txt([c.school.address, c.school.phone].filter(Boolean).join(' · ')), x, 32);

    const info = [
      [s('Student'), c.student.name, s('Code'), c.student.admission_number],
      [s('Grade / section'), c.enrollment ? `${c.enrollment.class_name || ''} ${c.enrollment.section_name || ''}` : '—', s('School year'), c.year?.name || '—'],
      [s('Homeroom teacher'), c.enrollment?.tutor || '—', s('Guardian'), c.student.guardian_name || '—'],
    ];
    let y = 41;
    info.forEach(([l1, v1, l2, v2]) => {
      doc.setFont('helvetica', 'bold'); doc.text(txt(l1) + ':', 14, y);
      doc.setFont('helvetica', 'normal'); doc.text(txt(v1), 48, y);
      doc.setFont('helvetica', 'bold'); doc.text(txt(l2) + ':', 112, y);
      doc.setFont('helvetica', 'normal'); doc.text(txt(v2), 140, y);
      y += 5;
    });

    const color = v => (v === 'AUS' || (typeof v === 'number' && v < c.scale.pass) ? RED : (typeof v === 'number' ? BLUE : [60, 60, 60]));
    const body = c.subjects.map(sub => [
      { content: txt(sub.name), styles: { halign: 'left' } },
      ...c.terms.map(t => ({ content: String(markText(sub.marks[t.id])), styles: { textColor: color(sub.marks[t.id]) } })),
      { content: String(sub.average ?? '—'), styles: { textColor: color(sub.average), fontStyle: 'bold' } },
      { content: sub.grade || '—', styles: { textColor: color(sub.average) } },
    ]);
    const foot = [
      [{ content: txt(s('Term average')), styles: { halign: 'left' } }, ...c.terms.map(t => ({ content: String(t.average ?? '—'), styles: { textColor: color(t.average) } })), '', ''],
      [{ content: txt(s('Absences / late')), styles: { halign: 'left', fontStyle: 'normal' } }, ...c.terms.map(t => (t.attendance.days ? `${t.attendance.absent} / ${t.attendance.late}` : '—')), '', ''],
    ];
    autoTable(doc, {
      startY: y + 2,
      head: [[txt(s('Subject')), ...c.terms.map(t => txt(termLabel(t))), txt(s('Final avg.')), txt(s('Grade'))]],
      body,
      foot,
      theme: 'grid',
      styles: { font: 'helvetica', fontSize: 9, halign: 'center', cellPadding: 1.8 },
      headStyles: { fillColor: [217, 217, 217], textColor: 30, fontStyle: 'bold' },
      footStyles: { fillColor: [245, 245, 245], textColor: 30, fontStyle: 'bold', halign: 'center' },
      columnStyles: { 0: { halign: 'left', cellWidth: 58 } },
    });

    y = doc.lastAutoTable.finalY + 8;
    const sm = c.summary;
    doc.setFontSize(10);
    doc.setFont('helvetica', 'bold'); doc.text(txt(s('Weighted average')) + ':', 14, y);
    doc.setTextColor(...color(sm.weighted_average)); doc.text(`${sm.weighted_average ?? '—'} ${sm.weighted_grade || ''}`, 52, y);
    doc.setTextColor(30); doc.text(txt(s('Final situation')) + ':', 112, y);
    doc.setTextColor(...(sm.failed_subjects ? RED : BLUE)); doc.text(txt(situationText.value), 112, y + 5, { maxWidth: 84 });
    doc.setTextColor(90); doc.setFont('helvetica', 'normal'); doc.setFontSize(8);
    doc.text(txt(s('Σ (subject average × weight) ÷ Σ weights ({w})', { w: sm.credits })), 14, y + 5);
    doc.text(txt(s('Pass mark: {p} of {m}', { p: c.scale.pass, m: c.scale.max })), 14, y + 9);

    y += 34;
    doc.setDrawColor(80); doc.setTextColor(30); doc.setFontSize(9);
    doc.line(24, y, 90, y); doc.line(120, y, 186, y);
    doc.text(txt(s('Homeroom teacher')), 57, y + 5, { align: 'center' });
    doc.text(txt(s('Principal')), 153, y + 5, { align: 'center' });
    doc.setFontSize(8); doc.setTextColor(110);
    doc.text(txt(`${s('Report card issued by {school}', { school: c.school.name })} · ${s('Issued: {date}', { date: new Date().toLocaleString() })}`), 14, y + 16);

    const safe = String(c.student.name || 'student').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z0-9]+/g, '_');
    doc.save(`${s('report_card')}_${safe}_${c.year?.name || ''}.pdf`);
  } catch (e) {
    message.error(s('Could not create the PDF'));
  } finally {
    downloading.value = false;
  }
}

onMounted(load);
watch(() => props.studentId, load);
</script>

<style scoped>
.rc-actions { display: flex; justify-content: flex-end; margin-bottom: 8px; }
.rc-head { display: flex; align-items: center; gap: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(128, 128, 128, 0.25); }
.rc-logo { height: 46px; }
.rc-school { font-weight: 700; font-size: 15px; }
.rc-title { margin-inline-start: auto; text-align: right; font-weight: 700; letter-spacing: .5px; }
.rc-title span { font-weight: 400; font-size: 12px; opacity: .7; }
.rc-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 4px 24px; margin: 10px 0 12px; }
.rc-muted { font-size: 12px; opacity: .6; }
.rc-table { width: 100%; border-collapse: collapse; }
.rc-table th { background: rgba(128, 128, 128, 0.12); padding: 6px; font-size: 12px; border: 1px solid rgba(128, 128, 128, 0.25); }
.rc-table td { padding: 5px 6px; text-align: center; border: 1px solid rgba(128, 128, 128, 0.2); }
.rc-table .left { text-align: left; }
.bold { font-weight: 700; }
.mark-bad { color: #d4380d; font-weight: 600; }
.mark-ok { color: #1d39c4; font-weight: 600; }
.rc-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 14px; }
.rc-box { border: 1px solid rgba(128, 128, 128, 0.25); border-radius: 8px; padding: 10px 14px; }
.rc-box-label { font-size: 12px; opacity: .7; }
.rc-box-value { font-size: 20px; font-weight: 700; }
.rc-legend { margin-top: 10px; font-size: 12px; opacity: .8; }
</style>
