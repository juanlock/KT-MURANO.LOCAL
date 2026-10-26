/**
 * Hospital navigation by buttons (user's analysis, 2026-10-25): in a hospital
 * company the side menu starts hidden and every screen shows a bar with the
 * hospital forms the user's role can open (HospitalQuickNav); Hospital ->
 * Home shows the same as big buttons, grouped by the four stages of a stay.
 * perms: any of them opens it (the route's meta.permission).
 */
export const HOSPITAL_NAV = [
    {
        stage: 'Admission and registration',
        color: '#2563eb',
        items: [
            { path: '/hospital/admission-desk', label: 'Admission desk', icon: 'LoginOutlined', perms: ['hms_admission_desk', 'hms_visitors'] },
            { path: '/hospital/triage', label: 'Triage', icon: 'FormOutlined', perms: ['hms_triage'] },
            { path: '/hospital/er', label: 'Emergency Room', icon: 'AlertOutlined', perms: ['hms_er'] },
            { path: '/hospital/patients', label: 'Patients', icon: 'TeamOutlined', perms: ['hms_patients_view'] },
            { path: '/hospital/appointments', label: 'Appointments', icon: 'CalendarOutlined', perms: ['hms_appointments'] },
            { path: '/hospital/invoices', label: 'Billing', icon: 'DollarOutlined', perms: ['hms_billing'] },
        ],
    },
    {
        stage: 'Stay and care',
        color: '#16a34a',
        items: [
            { path: '/hospital/rounds', label: 'My round', icon: 'NodeIndexOutlined', perms: ['hms_my_patients', 'hms_shifts', 'hms_treatments', 'hms_admissions'] },
            { path: '/hospital/my-patients', label: 'My patients', icon: 'HeartOutlined', perms: ['hms_my_patients', 'hms_shifts'] },
            { path: '/hospital/admissions', label: 'Inpatients', icon: 'HomeOutlined', perms: ['hms_admissions'] },
            { path: '/hospital/visits', label: 'Consultations', icon: 'SolutionOutlined', perms: ['hms_visits'] },
            { path: '/hospital/treatments', label: 'Treatments', icon: 'MedicineBoxOutlined', perms: ['hms_treatments', 'hms_treatments_administer'] },
            { path: '/hospital/surgery', label: 'Operating room', icon: 'ScissorOutlined', perms: ['hms_surgery'] },
            { path: '/hospital/lab-orders', label: 'Laboratory', icon: 'ExperimentOutlined', perms: ['hms_lab'] },
            { path: '/hospital/pharmacy', label: 'Pharmacy', icon: 'ShopOutlined', perms: ['hms_pharmacy', 'hms_pharmacy_dispense'] },
        ],
    },
    {
        stage: 'Beds and staff',
        color: '#7c3aed',
        items: [
            { path: '/hospital/control-tower', label: 'Control tower', icon: 'RadarChartOutlined', perms: ['hms_wards', 'hms_admission_desk', 'hms_admissions', 'hms_setup'] },
            { path: '/hospital/wards', label: 'Wards & beds', icon: 'AppstoreOutlined', perms: ['hms_wards'] },
            { path: '/hospital/shifts', label: 'Staff shifts', icon: 'ScheduleOutlined', perms: ['hms_shifts', 'hms_my_patients'] },
            { path: '/hospital/doctors', label: 'Doctors', icon: 'UserOutlined', perms: ['hms_doctors'] },
        ],
    },
    {
        stage: 'Management',
        color: '#d97706',
        items: [
            { path: '/hospital/dashboard', label: 'Dashboard', icon: 'DashboardOutlined', perms: ['hms_dashboard'] },
            { path: '/hospital/reports', label: 'Reports', icon: 'BarChartOutlined', perms: ['hms_reports'] },
            { path: '/hospital/departments', label: 'Departments', icon: 'ApartmentOutlined', perms: ['hms_departments'] },
            { path: '/hospital/setup', label: 'Configuration', icon: 'SettingOutlined', perms: ['hms_setup'] },
        ],
    },
];

/**
 * A specialist clinic (Health Specialist, 2026-10-26): the outpatient flow in
 * its four phases -- before the appointment, arrival, consultation, closing.
 */
export const SPECIALIST_NAV = [
    {
        stage: 'Booking and arrival',
        color: '#2563eb',
        items: [
            { path: '/hospital/agenda', label: 'Reception and schedule', icon: 'ScheduleOutlined', perms: ['hms_reception'] },
            { path: '/hospital/nursing', label: 'Nursing station', icon: 'HeartOutlined', perms: ['hms_nursing_station'] },
            { path: '/hospital/patients', label: 'Patients', icon: 'TeamOutlined', perms: ['hms_patients_view'] },
        ],
    },
    {
        stage: 'Consultation',
        color: '#16a34a',
        items: [
            { path: '/hospital/specialist', label: 'My consultation', icon: 'SolutionOutlined', perms: ['hms_specialist'] },
            { path: '/hospital/visits', label: 'Consultations', icon: 'MedicineBoxOutlined', perms: ['hms_visits'] },
            { path: '/hospital/lab-orders', label: 'Laboratory', icon: 'ExperimentOutlined', perms: ['hms_lab'] },
        ],
    },
    {
        stage: 'Closing and management',
        color: '#d97706',
        items: [
            { path: '/hospital/invoices', label: 'Billing', icon: 'DollarOutlined', perms: ['hms_billing'] },
            { path: '/hospital/clinic-reports', label: 'Clinic reports', icon: 'BarChartOutlined', perms: ['hms_clinic_reports'] },
            { path: '/hospital/doctors', label: 'Doctors', icon: 'UserOutlined', perms: ['hms_doctors'] },
            { path: '/hospital/pharmacy', label: 'Pharmacy', icon: 'ShopOutlined', perms: ['hms_pharmacy'] },
        ],
    },
];

/** Hospital screens a specialist clinic does not use (side menu + buttons). */
export const NOT_IN_CLINIC = ['/hospital/admission-desk', '/hospital/triage', '/hospital/er', '/hospital/rounds', '/hospital/my-patients',
    '/hospital/admissions', '/hospital/treatments', '/hospital/surgery', '/hospital/control-tower', '/hospital/wards', '/hospital/shifts', '/hospital/appointments'];

/** The groups with only the items the user can open (auth.can). */
export function allowedNav(auth) {
    const can = p => auth.user?.type === 'SuperAdmin' || auth.can(p);
    const nav = auth.user?.business_type === 'health_specialist' ? SPECIALIST_NAV : HOSPITAL_NAV;
    return nav.map(g => ({ ...g, items: g.items.filter(i => i.perms.some(can)) })).filter(g => g.items.length);
}
