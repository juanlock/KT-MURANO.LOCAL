/**
 * Specialty templates of the specialist EMR (2026-10-26). Each template is a
 * list of sections with fields; their values are saved in
 * patient_visits.specialty_data (JSON) next to the SOAP note. Field types:
 * number (unit), text, textarea, select (options), date, check (options =
 * multiple), computed (fn(data, ctx) -> text). Labels are English keys of the
 * page dictionary (lang/SpecialistConsult.es.js).
 */
const SNELLEN = ['20/20', '20/25', '20/30', '20/40', '20/50', '20/70', '20/100', '20/200', '20/400', 'CD', 'MM', 'PL', 'NPL'];
const SCORE_PHQ = v => (v == null || v === '' ? '' : v >= 20 ? 'Severe' : v >= 15 ? 'Moderately severe' : v >= 10 ? 'Moderate' : v >= 5 ? 'Mild' : 'Minimal');
const SCORE_GAD = v => (v == null || v === '' ? '' : v >= 15 ? 'Severe' : v >= 10 ? 'Moderate' : v >= 5 ? 'Mild' : 'Minimal');

export const TEMPLATES = {
    general: {
        label: 'General medicine',
        sections: [
            { title: 'Examination', fields: [
                { key: 'general_state', label: 'General condition', type: 'select', options: ['Good', 'Fair', 'Poor'] },
                { key: 'bmi', label: 'BMI', type: 'computed', fn: (d, c) => bmi(c.visit) },
                { key: 'systems', label: 'Review of systems', type: 'textarea' },
                { key: 'physical', label: 'Physical examination by systems', type: 'textarea' },
            ] },
        ],
    },
    pediatrics: {
        label: 'Pediatrics',
        growth: true,
        sections: [
            { title: 'Growth', fields: [
                { key: 'head_circumference', label: 'Head circumference', type: 'number', unit: 'cm' },
                { key: 'bmi', label: 'BMI', type: 'computed', fn: (d, c) => bmi(c.visit) },
                { key: 'feeding', label: 'Feeding', type: 'select', options: ['Exclusive breastfeeding', 'Mixed', 'Formula', 'Complementary feeding', 'Family diet'] },
            ] },
            { title: 'Development and vaccines', fields: [
                { key: 'vaccines', label: 'Vaccines up to date', type: 'select', options: ['Yes', 'No', 'Incomplete'] },
                { key: 'vaccines_due', label: 'Vaccines due', type: 'text' },
                { key: 'milestones', label: 'Development milestones', type: 'textarea' },
            ] },
        ],
    },
    ophthalmology: {
        label: 'Ophthalmology',
        sections: [
            { title: 'Visual acuity', fields: [
                { key: 'va_od_sc', label: 'Right eye (OD) without correction', type: 'select', options: SNELLEN },
                { key: 'va_oi_sc', label: 'Left eye (OS) without correction', type: 'select', options: SNELLEN },
                { key: 'va_od_cc', label: 'Right eye (OD) with correction', type: 'select', options: SNELLEN },
                { key: 'va_oi_cc', label: 'Left eye (OS) with correction', type: 'select', options: SNELLEN },
            ] },
            { title: 'Intraocular pressure', fields: [
                { key: 'iop_od', label: 'IOP right eye', type: 'number', unit: 'mmHg', alert: v => v > 21 },
                { key: 'iop_oi', label: 'IOP left eye', type: 'number', unit: 'mmHg', alert: v => v > 21 },
            ] },
            { title: 'Refraction', fields: [
                { key: 'rx_od', label: 'OD sphere / cylinder x axis', type: 'text', placeholder: '-1.25 / -0.50 x 180' },
                { key: 'rx_oi', label: 'OS sphere / cylinder x axis', type: 'text', placeholder: '-1.00 / -0.25 x 175' },
                { key: 'rx_add', label: 'Addition', type: 'text' },
            ] },
            { title: 'Biomicroscopy and fundus', fields: [
                { key: 'slit_lamp', label: 'Slit lamp', type: 'textarea' },
                { key: 'fundus', label: 'Fundus', type: 'textarea' },
            ] },
        ],
    },
    cardiology: {
        label: 'Cardiology',
        bpChart: true,
        sections: [
            { title: 'Symptoms', fields: [
                { key: 'chest_pain', label: 'Chest pain', type: 'select', options: ['None', 'Typical angina', 'Atypical', 'Non-cardiac'] },
                { key: 'nyha', label: 'NYHA class', type: 'select', options: ['I', 'II', 'III', 'IV'] },
                { key: 'palpitations', label: 'Palpitations / syncope', type: 'text' },
            ] },
            { title: 'Risk factors', fields: [
                { key: 'risk', label: 'Risk factors', type: 'check', options: ['Hypertension', 'Diabetes', 'Smoking', 'Dyslipidemia', 'Obesity', 'Family history'] },
                { key: 'chol_total', label: 'Total cholesterol', type: 'number', unit: 'mg/dL', alert: v => v >= 200 },
                { key: 'ldl', label: 'LDL', type: 'number', unit: 'mg/dL', alert: v => v >= 130 },
                { key: 'hdl', label: 'HDL', type: 'number', unit: 'mg/dL' },
            ] },
            { title: 'Studies', fields: [
                { key: 'ecg', label: 'ECG', type: 'textarea' },
                { key: 'ef', label: 'Ejection fraction', type: 'number', unit: '%', alert: v => v < 50 },
                { key: 'echo', label: 'Echocardiogram', type: 'textarea' },
            ] },
        ],
    },
    gynecology: {
        label: 'Gynecology / obstetrics',
        sections: [
            { title: 'Obstetric history', fields: [
                { key: 'g', label: 'Pregnancies (G)', type: 'number' },
                { key: 'p', label: 'Births (P)', type: 'number' },
                { key: 'a', label: 'Abortions (A)', type: 'number' },
                { key: 'c', label: 'C-sections (C)', type: 'number' },
                { key: 'lmp', label: 'Last menstrual period (LMP)', type: 'date' },
                { key: 'pregnant', label: 'Pregnant', type: 'select', options: ['No', 'Yes'] },
                { key: 'ga', label: 'Gestational age', type: 'computed', fn: d => gestation(d.lmp) },
                { key: 'edd', label: 'Estimated due date', type: 'computed', fn: d => dueDate(d.lmp) },
            ] },
            { title: 'Prenatal control', when: d => d.pregnant === 'Yes', fields: [
                { key: 'fundal_height', label: 'Fundal height', type: 'number', unit: 'cm' },
                { key: 'fhr', label: 'Fetal heart rate', type: 'number', unit: 'bpm', alert: v => v && (v < 110 || v > 160) },
                { key: 'fetal_movements', label: 'Fetal movements', type: 'select', options: ['Present', 'Decreased', 'Absent'] },
                { key: 'edema', label: 'Edema', type: 'select', options: ['No', '+', '++', '+++'] },
            ] },
            { title: 'Gynecology', fields: [
                { key: 'contraception', label: 'Contraception', type: 'text' },
                { key: 'last_pap', label: 'Last Pap smear', type: 'date' },
                { key: 'breast_exam', label: 'Breast examination', type: 'textarea' },
                { key: 'pelvic_exam', label: 'Pelvic examination', type: 'textarea' },
            ] },
        ],
    },
    psychology: {
        label: 'Psychology',
        sections: [
            { title: 'Session', fields: [
                { key: 'session_no', label: 'Session number', type: 'number' },
                { key: 'modality', label: 'Modality', type: 'select', options: ['Individual', 'Couple', 'Family', 'Group'] },
                { key: 'mood', label: 'Mood today (0-10)', type: 'number' },
                { key: 'topics', label: 'Topics worked on', type: 'textarea' },
                { key: 'techniques', label: 'Techniques', type: 'text' },
                { key: 'homework', label: 'Agreements / homework', type: 'textarea' },
            ] },
            { title: 'Scales', fields: [
                { key: 'phq9', label: 'PHQ-9 (depression, 0-27)', type: 'number', alert: v => v >= 10 },
                { key: 'phq9_level', label: 'PHQ-9 level', type: 'computed', fn: d => SCORE_PHQ(d.phq9) },
                { key: 'gad7', label: 'GAD-7 (anxiety, 0-21)', type: 'number', alert: v => v >= 10 },
                { key: 'gad7_level', label: 'GAD-7 level', type: 'computed', fn: d => SCORE_GAD(d.gad7) },
                { key: 'risk', label: 'Risk', type: 'select', options: ['None', 'Low', 'Moderate', 'High'], alert: v => v === 'High' },
            ] },
        ],
    },
    psychiatry: {
        label: 'Psychiatry',
        sections: [
            { title: 'Mental status examination', fields: [
                { key: 'appearance', label: 'Appearance and behavior', type: 'text' },
                { key: 'mood_affect', label: 'Mood / affect', type: 'text' },
                { key: 'thought', label: 'Thought (course and content)', type: 'textarea' },
                { key: 'perception', label: 'Perception', type: 'text' },
                { key: 'cognition', label: 'Orientation and cognition', type: 'text' },
                { key: 'insight', label: 'Insight and judgment', type: 'select', options: ['Preserved', 'Partial', 'Absent'] },
            ] },
            { title: 'Scales and risk', fields: [
                { key: 'phq9', label: 'PHQ-9 (depression, 0-27)', type: 'number', alert: v => v >= 10 },
                { key: 'phq9_level', label: 'PHQ-9 level', type: 'computed', fn: d => SCORE_PHQ(d.phq9) },
                { key: 'gad7', label: 'GAD-7 (anxiety, 0-21)', type: 'number', alert: v => v >= 10 },
                { key: 'suicide_risk', label: 'Suicide risk', type: 'select', options: ['None', 'Low', 'Moderate', 'High'], alert: v => v === 'High' || v === 'Moderate' },
                { key: 'psychotropics', label: 'Current psychotropic medication', type: 'textarea' },
                { key: 'adherence', label: 'Adherence', type: 'select', options: ['Good', 'Partial', 'Poor'] },
            ] },
        ],
    },
};

export const TEMPLATE_KEYS = Object.keys(TEMPLATES);

export function bmi(v) {
    const w = Number(v?.weight);
    const h = Number(v?.height) / 100;
    if (!w || !h) return '';
    const b = w / (h * h);
    const cls = b < 18.5 ? 'Underweight' : b < 25 ? 'Normal' : b < 30 ? 'Overweight' : 'Obesity';
    return `${b.toFixed(1)} · ${cls}`;
}

export function gestation(lmp) {
    if (!lmp) return '';
    const days = Math.floor((Date.now() - new Date(lmp).getTime()) / 86400000);
    if (days < 0 || days > 300) return '';
    return `${Math.floor(days / 7)} w ${days % 7} d`;
}

export function dueDate(lmp) {
    if (!lmp) return '';
    const d = new Date(lmp);
    d.setDate(d.getDate() + 280);
    return d.toISOString().slice(0, 10);
}

/**
 * Approximate WHO growth references 0-60 months (P3 / P50 / P97), for the
 * pediatric chart. Months: 0, 3, 6, 9, 12, 18, 24, 36, 48, 60.
 */
export const WHO_MONTHS = [0, 3, 6, 9, 12, 18, 24, 36, 48, 60];
export const WHO = {
    weight: {
        male: { p3: [2.5, 5.1, 6.4, 7.2, 7.8, 8.9, 9.8, 11.4, 12.9, 14.3], p50: [3.3, 6.4, 7.9, 8.9, 9.6, 10.9, 12.2, 14.3, 16.3, 18.3], p97: [4.3, 7.9, 9.7, 10.9, 11.8, 13.5, 15.1, 17.8, 20.5, 23.2] },
        female: { p3: [2.4, 4.6, 5.8, 6.6, 7.2, 8.3, 9.4, 11.2, 12.8, 14.4], p50: [3.2, 5.8, 7.3, 8.2, 8.9, 10.2, 11.5, 13.9, 16.1, 18.2], p97: [4.2, 7.4, 9.4, 10.6, 11.6, 13.4, 15.1, 18.1, 21.1, 24.3] },
    },
    height: {
        male: { p3: [46.3, 57.6, 63.6, 67.7, 71.3, 77.2, 82.1, 89.1, 95.4, 101.2], p50: [49.9, 61.4, 67.6, 72.0, 75.7, 82.3, 87.1, 96.1, 103.3, 110.0], p97: [53.4, 65.3, 71.6, 76.2, 80.2, 87.3, 93.6, 103.1, 111.2, 118.7] },
        female: { p3: [45.6, 55.8, 61.5, 65.6, 69.2, 75.2, 80.3, 87.9, 94.6, 100.7], p50: [49.1, 59.8, 65.7, 70.1, 74.0, 80.7, 86.4, 95.1, 102.7, 109.4], p97: [52.7, 63.8, 70.0, 74.7, 78.9, 86.2, 92.5, 102.2, 110.7, 118.1] },
    },
};
