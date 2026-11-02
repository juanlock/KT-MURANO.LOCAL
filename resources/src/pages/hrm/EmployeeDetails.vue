<template>
  <div class="page">
    <PageHeader
      :title="($route.name === 'employee-edit' ? $t('Edit') + ' ' + $t('Employee') : $t('Employee_Details')) + (savedName ? ' — ' + savedName : '')"
      :breadcrumb="[$t('Employee'), $route.name === 'employee-edit' ? $t('Edit') : $t('Employee_Details')]"
    >
      <template #actions>
        <!-- The whole profile on paper / PDF: every tab this role may see, with the photo. -->
        <a-button v-if="!loading && TAB_ORDER.some(canView)" :loading="printing" @click="printProfile">
          <template #icon><PrinterOutlined /></template>
          Print profile
        </a-button>
        <a-button @click="$goBack('/hrm/employees')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-empty v-else-if="!TAB_ORDER.some(canView)" :description="tx(`Your role can't see any tab of the employee details. Ask an admin (Users → Roles → HRM → Employees).`,
      'Su rol no puede ver ninguna pestaña de la ficha del empleado. Pídaselo a un administrador (Usuarios → Roles → RRHH → Empleados).')" />
    <a-tabs v-else v-model:activeKey="tab">
      <!-- ============ Basic information ============ -->
      <!-- Each tab: shown with its View permission, editable with its Edit one (Users -> Roles). -->
      <a-tab-pane v-if="canView('basic')" key="basic" :tab="$t('Basic_Information')">
        <a-card size="small">
          <!-- Employee photo (also used on the booking calendar when the user has none). -->
          <div class="ph">
            <a-image v-if="photoUrl(employee.avatar)" :src="photoUrl(employee.avatar)" :width="96" :height="96" class="ph-img" />
            <a-avatar v-else :size="96" class="ph-empty">{{ initials(employee.firstname, employee.lastname) }}</a-avatar>
            <div v-if="canEdit('basic')" class="ph-actions">
              <a-space>
                <a-upload :show-upload-list="false" accept="image/*" :before-upload="f => uploadPhoto('employee', f)">
                  <a-button size="small" :loading="photoBusy === 'employee'"><template #icon><UploadOutlined /></template> {{ photoUrl(employee.avatar) ? tx('Change photo', 'Cambiar foto') : tx('Upload photo', 'Subir foto') }}</a-button>
                </a-upload>
                <a-popconfirm v-if="photoUrl(employee.avatar)" :title="tx(`Remove the employee's photo?`, '¿Quitar la foto del empleado?')" :ok-text="tx('Yes', 'Sí')" :cancel-text="tx('No', 'No')" @confirm="removePhoto('employee')">
                  <a-button size="small" danger>{{ tx('Remove', 'Quitar') }}</a-button>
                </a-popconfirm>
              </a-space>
              <div class="prod-muted">{{ tx('JPG, PNG or WebP, up to 5 MB — cropped square. Click the photo to view it.', 'JPG, PNG o WebP, hasta 5 MB, recortada en cuadrado. Haga clic en la foto para verla.') }}</div>
            </div>
          </div>
          <a-form ref="basicRef" :model="employee" :rules="basicRules" layout="vertical" :disabled="!canEdit('basic')">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('FirstName') + ' *'" name="firstname">
                  <a-input v-model:value="employee.firstname" :placeholder="$t('Enter_FirstName')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('LastName') + ' *'" name="lastname">
                  <a-input v-model:value="employee.lastname" :placeholder="$t('Enter_LastName')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Gender') + ' *'" name="gender">
                  <a-select
                    v-model:value="employee.gender" :placeholder="$t('Choose_Gender')"
                    :options="GENDERS"
                  />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Family_status')">
                  <a-select
                    v-model:value="employee.marital_status" allow-clear
                    :placeholder="$t('Choose_Family_status')" :options="MARITAL_STATUSES"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Birth_date')">
                  <a-date-picker v-model:value="employee.birth_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Email_Address')">
                  <a-input v-model:value="employee.email" :placeholder="$t('Enter_email_address')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Phone')" name="phone">
                  <a-input v-model:value="employee.phone" :placeholder="$t('Enter_Phone_Number')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Country')" name="country">
                  <a-input v-model:value="employee.country" :placeholder="$t('Enter_Country')" />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('City')">
                  <a-input v-model:value="employee.city" :placeholder="$t('Enter_City')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Province')">
                  <a-input v-model:value="employee.province" :placeholder="$t('Enter_Province')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Zip_code')">
                  <a-input v-model:value="employee.zipcode" :placeholder="$t('Enter_Zip_code')" />
                </a-form-item>
              </a-col>

              <a-col :span="24">
                <a-form-item :label="$t('Adress')">
                  <a-input v-model:value="employee.address" :placeholder="$t('Enter_Address')" />
                </a-form-item>
              </a-col>
            </a-row>

            <a-button v-if="canEdit('basic')" type="primary" :loading="savingBasic" @click="saveBasic">{{ $t('submit') }}</a-button>
          </a-form>
        </a-card>
      </a-tab-pane>

      <!-- ============ Company: where and how they work ============ -->
      <a-tab-pane v-if="canView('company')" key="company" :tab="$t('Company')" force-render>
        <a-card size="small">
          <a-form ref="companyRef" :model="employee" :rules="basicRules" layout="vertical" :disabled="!canEdit('company')">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Employment_type')">
                  <a-select
                    v-model:value="employee.employment_type" allow-clear
                    :placeholder="$t('Select_Employment_type')" :options="EMPLOYMENT_TYPES"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('joining_date')">
                  <a-date-picker v-model:value="employee.joining_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Leaving_Date')">
                  <a-date-picker v-model:value="employee.leaving_date" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Annual_Leave') + ' *'" name="total_leave">
                  <a-input-number v-model:value="employee.total_leave" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Remaining_leave')">
                  <a-input-number :value="employee.remaining_leave" disabled style="width: 100%" />
                </a-form-item>
              </a-col>

              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Company') + ' *'" name="company_id">
                  <a-select
                    v-model:value="employee.company_id" :placeholder="$t('Choose_Company')"
                    :options="sortByLabel(companies.map(c => ({ value: c.id, label: c.name })))"
                    @change="onCompanyChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Department') + ' *'" name="department_id">
                  <a-select
                    v-model:value="employee.department_id" :placeholder="$t('Department')"
                    :options="sortByLabel(departments.map(d => ({ value: d.id, label: d.department })))"
                    @change="onDepartmentChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Designation') + ' *'" name="designation_id">
                  <a-select
                    v-model:value="employee.designation_id" :placeholder="$t('Choose_Designation')"
                    :options="sortByLabel(designations.map(d => ({ value: d.id, label: d.designation })))"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Office_Shift') + ' *'" name="office_shift_id">
                  <a-select
                    v-model:value="employee.office_shift_id" :placeholder="$t('Choose_Office_Shift')"
                    :options="sortByLabel(officeShifts.map(o => ({ value: o.id, label: o.name })))"
                  />
                </a-form-item>
              </a-col>

              <!-- Payroll: weekly run pays hourly staff for the hours on the service
                   jobs they complete, commission staff a % of their sales. -->
              <a-col :xs="24" :md="6">
                <a-form-item :label="tx('Paid by', 'Se le paga por')" :extra="tx('Empty = not on the weekly payroll.', 'Vacío = no entra en la planilla semanal.')">
                  <a-select
                    v-model:value="employee.pay_type" allow-clear :placeholder="tx('Not on payroll', 'Fuera de planilla')"
                    :options="PAY_TYPES"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="tx('Staff type', 'Tipo de personal')" :extra="tx('Pilots and hostesses can be put on trips (Transport).', 'Pilotos y azafatas se asignan a viajes (Transporte).')">
                  <a-select v-model:value="employee.staff_type" allow-clear :options="STAFF_TYPES" />
                </a-form-item>
              </a-col>
              <a-col v-if="employee.pay_type === 'per_trip' || ['pilot', 'hostess'].includes(employee.staff_type)" :xs="24" :md="6">
                <a-form-item :label="tx('Pay per trip', 'Pago por viaje')">
                  <a-input-number v-model:value="employee.trip_rate" :min="0" :step="1" :prefix="auth.currency" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col v-if="employee.pay_type !== 'commission' && employee.pay_type !== 'per_trip'" :xs="24" :md="6">
                <a-form-item :label="$t('Hourly_rate')">
                  <a-input-number v-model:value="employee.hourly_rate" :min="0" :step="0.5" :prefix="auth.currency" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col v-else-if="employee.pay_type === 'commission'" :xs="24" :md="6">
                <a-form-item :label="tx('Commission %', 'Comisión %')">
                  <a-input-number v-model:value="employee.commission_rate" :min="0" :max="100" addon-after="%" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="tx('Pay from', 'Pagar desde')" :extra="tx('Payroll counts from this date.', 'La planilla cuenta desde esta fecha.')">
                  <a-date-picker v-model:value="employee.pay_effective_from" value-format="YYYY-MM-DD" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6">
                <a-form-item :label="$t('Basic_salary')">
                  <a-input-number v-model:value="employee.basic_salary" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>

            <a-button v-if="canEdit('company')" type="primary" :loading="savingBasic" @click="saveBasic">{{ $t('submit') }}</a-button>
          </a-form>
        </a-card>
      </a-tab-pane>

      <!-- ============ Social ============ -->
      <a-tab-pane v-if="canView('social')" key="social" :tab="$t('Social_Media')">
        <a-card size="small">
          <a-form layout="vertical" :disabled="!canEdit('social')">
            <a-row :gutter="16">
              <a-col v-for="s in SOCIAL" :key="s.key" :xs="24" :md="8">
                <a-form-item :label="$t(s.label)">
                  <a-input v-model:value="employee[s.key]" :placeholder="$t(s.placeholder)" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-button v-if="canEdit('social')" type="primary" :loading="savingSocial" @click="saveSocial">{{ $t('submit') }}</a-button>
          </a-form>
        </a-card>
      </a-tab-pane>

      <!-- ============ Experiences ============ -->
      <a-tab-pane v-if="canView('experiences')" key="experiences" :tab="$t('Experiences')">
        <a-card size="small">
          <a-button v-if="canEdit('experiences')" type="primary" style="margin-bottom: 12px" @click="openExperience()">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add') }}
          </a-button>
          <a-table
            :columns="experienceColumns.filter(c => c.key !== 'actions' || canEdit('experiences'))" :data-source="experiences.rows" :loading="experiences.loading"
            :pagination="{ current: experiences.page, pageSize: experiences.limit, total: experiences.total, showSizeChanger: false }"
            size="small" :row-key="r => r.id"
            @change="p => { experiences.page = p.current; fetchExperiences(); }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'actions'">
                <a-space>
                  <a-button type="text" size="small" @click="openExperience(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                  <a-popconfirm :title="$t('Delete_Text')" @confirm="removeExperience(record.id)">
                    <a-button type="text" size="small" danger>
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>

      <!-- ============ Bank accounts ============ -->
      <a-tab-pane v-if="canView('accounts')" key="accounts" :tab="$t('bank_account')">
        <a-card size="small">
          <a-button v-if="canEdit('accounts')" type="primary" style="margin-bottom: 12px" @click="openAccount()">
            <template #icon><PlusOutlined /></template>
            {{ $t('Add') }}
          </a-button>
          <a-table
            :columns="accountColumns.filter(c => c.key !== 'actions' || canEdit('accounts'))" :data-source="accounts.rows" :loading="accounts.loading"
            :pagination="{ current: accounts.page, pageSize: accounts.limit, total: accounts.total, showSizeChanger: false }"
            size="small" :row-key="r => r.id"
            @change="p => { accounts.page = p.current; fetchAccounts(); }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'actions'">
                <a-space>
                  <a-button type="text" size="small" @click="openAccount(record)">
                    <template #icon><EditOutlined style="color: #52c41a" /></template>
                  </a-button>
                  <a-popconfirm :title="$t('Delete_Text')" @confirm="removeAccount(record.id)">
                    <a-button type="text" size="small" danger>
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>

      <!-- ============ User: the login account linked to this employee ============ -->
      <a-tab-pane v-if="canView('user')" key="user" :tab="$t('User')">
        <a-card size="small">
          <a-empty v-if="!linkedUser" :description="tx('No user is linked to this employee. Link one in Users (Add / Edit user → employee) so they can sign in, be booked as staff and appear in Production.',
            'Este empleado no tiene un usuario vinculado. Vincule uno en Usuarios (Agregar / Editar usuario → empleado) para que pueda entrar, recibir reservas y aparecer en Producción.')" />
          <template v-else>
            <!-- The user's avatar (top bar, booking calendar, team pages). -->
            <div class="ph">
              <a-image v-if="photoUrl(linkedUser.avatar)" :src="photoUrl(linkedUser.avatar)" :width="96" :height="96" class="ph-img" />
              <a-avatar v-else :size="96" class="ph-empty">{{ initials(linkedUser.firstname, linkedUser.lastname) }}</a-avatar>
              <div v-if="canEdit('user')" class="ph-actions">
                <a-space>
                  <a-upload :show-upload-list="false" accept="image/*" :before-upload="f => uploadPhoto('user', f)">
                    <a-button size="small" :loading="photoBusy === 'user'"><template #icon><UploadOutlined /></template> {{ photoUrl(linkedUser.avatar) ? tx('Change picture', 'Cambiar imagen') : tx('Upload picture', 'Subir imagen') }}</a-button>
                  </a-upload>
                  <a-popconfirm v-if="photoUrl(linkedUser.avatar)" :title="tx(`Remove the user's picture?`, '¿Quitar la imagen del usuario?')" :ok-text="tx('Yes', 'Sí')" :cancel-text="tx('No', 'No')" @confirm="removePhoto('user')">
                    <a-button size="small" danger>{{ tx('Remove', 'Quitar') }}</a-button>
                  </a-popconfirm>
                </a-space>
                <div class="prod-muted">{{ tx(`The user's profile picture — JPG, PNG or WebP, up to 5 MB.`, 'La imagen de perfil del usuario: JPG, PNG o WebP, hasta 5 MB.') }}</div>
              </div>
            </div>
            <a-descriptions :column="{ xs: 1, md: 2 }" size="small" bordered>
              <a-descriptions-item :label="tx('Username', 'Usuario')">{{ linkedUser.username }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Name', 'Nombre')">{{ [linkedUser.firstname, linkedUser.lastname].filter(Boolean).join(' ') || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Email', 'Correo')">{{ linkedUser.email || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Phone', 'Teléfono')">{{ linkedUser.phone || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tx('User type', 'Tipo de usuario')">{{ userTypeLabel(linkedUser.type) }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Role', 'Rol')">{{ linkedUser.role || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Company', 'Empresa')">{{ linkedUser.company || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Status', 'Estado')">
                <a-tag v-if="linkedUser.deleted_at" color="red">{{ tx('Deleted', 'Eliminado') }}</a-tag>
                <a-tag v-else :color="Number(linkedUser.statut) === 1 ? 'green' : 'default'">{{ Number(linkedUser.statut) === 1 ? tx('Active', 'Activo') : tx('Inactive', 'Inactivo') }}</a-tag>
              </a-descriptions-item>
              <a-descriptions-item :label="tx('Locations', 'Locales')">{{ linkedUser.is_all_warehouses ? tx('All locations', 'Todos los locales') : (linkedUser.warehouses.join(', ') || '—') }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Sees records', 'Ve registros')">{{ linkedUser.record_view ? tx(`Everyone's`, 'De todos') : tx('Only their own', 'Solo los suyos') }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Sales staff', 'Personal de ventas')">{{ linkedUser.is_sales_staff ? tx('Yes', 'Sí') : tx('No', 'No') }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Sales agent', 'Vendedor')">{{ linkedUser.sales_agents.join(', ') || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Bookings as staff', 'Reservas como personal')">{{ linkedUser.bookings_count }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Customer portal', 'Portal del cliente')">{{ linkedUser.portal_enabled ? tx('On', 'Activo') : tx('Off', 'Inactivo') }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Language', 'Idioma')">{{ linkedUser.preferred_locale || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Last sign-in', 'Último ingreso')">{{ linkedUser.last_login ? fmtDateTime(linkedUser.last_login) : tx('Never', 'Nunca') }}</a-descriptions-item>
              <a-descriptions-item :label="tx('Created', 'Creado')">{{ fmtDateTime(linkedUser.created_at) }}</a-descriptions-item>
            </a-descriptions>
            <a-button v-if="auth.can('users_view')" style="margin-top: 12px" @click="$router.push(`/users/${linkedUser.id}/edit`)">
              <template #icon><EditOutlined /></template> {{ tx('Edit user', 'Editar usuario') }}
            </a-button>
          </template>
        </a-card>
      </a-tab-pane>

      <!-- ============ Assets assigned (Assets -> Assignments, to the employee's login) ============ -->
      <a-tab-pane v-if="canView('assets')" key="assets" :tab="`${tx('Assets assigned', 'Activos asignados')}${assetsOpen ? ` (${assetsOpen})` : ''}`">
        <a-card size="small">
          <div class="prod-filter">
            <span class="prod-muted">{{ tx("Equipment and tools in this employee's care (open assignments first).", 'Equipos y herramientas a cargo de este empleado (primero las asignaciones abiertas).') }}</span>
            <PermLink to="/assets/assignments" permission="asset_assignments" style="margin-left: auto">{{ tx('Assets → Assignments', 'Activos → Asignaciones') }}</PermLink>
          </div>
          <a-table
            :data-source="assets.rows" :loading="assets.loading" row-key="id" size="small" :pagination="false"
            :scroll="{ x: 760 }" :locale="{ emptyText: tx('No assets assigned to this employee.', 'Este empleado no tiene activos asignados.') }"
          >
            <a-table-column :title="tx('Asset', 'Activo')" key="asset">
              <template #default="{ record }">
                <b>{{ record.name }}</b>
                <div class="prod-muted">{{ [record.tag, record.category, record.serial_number ? `S/N ${record.serial_number}` : ''].filter(Boolean).join(' · ') }}</div>
              </template>
            </a-table-column>
            <a-table-column :title="tx('Assigned', 'Asignado')" data-index="assigned_on" key="assigned_on" />
            <a-table-column :title="tx('Due back', 'Devolver el')" key="due">
              <template #default="{ record }">
                <span :style="record.overdue ? 'color:#cf1322;font-weight:600' : ''">{{ record.due_back_on || '—' }}</span>
              </template>
            </a-table-column>
            <a-table-column :title="tx('Returned', 'Devuelto')" key="returned">
              <template #default="{ record }">{{ record.returned_on || '—' }}</template>
            </a-table-column>
            <a-table-column :title="tx('Condition', 'Estado físico')" key="cond">
              <template #default="{ record }">{{ [record.condition_out, record.condition_in].filter(Boolean).join(' → ') || '—' }}</template>
            </a-table-column>
            <a-table-column :title="$t('Status')" key="status">
              <template #default="{ record }">
                <a-tag :color="record.overdue ? 'red' : record.status === 'assigned' ? 'blue' : 'default'">{{ record.overdue ? tx('Overdue', 'Vencido') : assetStatus(record.status) }}</a-tag>
              </template>
            </a-table-column>
          </a-table>
        </a-card>
      </a-tab-pane>

      <!-- ============ Production: services done and payments received ============ -->
      <a-tab-pane v-if="canView('production')" key="production" :tab="tx('Production', 'Producción')">
        <a-card size="small">
          <div class="prod-filter">
            <span class="prod-muted">{{ tx('Dates', 'Fechas') }}</span>
            <PresetRangeFilter v-model:value="prodRange" @change="fetchProduction" />
          </div>
          <a-alert v-if="production.summary && !production.summary.has_user" type="info" show-icon style="margin-bottom: 12px"
            :message="tx(`Services come from the bookings / sales of the employee's user — link a user (User tab) to see them. Payments are shown.`,
              'Los servicios salen de las reservas / ventas del usuario del empleado: vincule un usuario (pestaña Usuario) para verlos. Los pagos sí se muestran.')" />
          <a-spin :spinning="production.loading">
            <a-row :gutter="[12, 12]" style="margin-bottom: 16px">
              <a-col :xs="12" :md="6">
                <a-statistic :title="tx('Services', 'Servicios')" :value="production.summary?.services_count || 0" />
              </a-col>
              <a-col :xs="12" :md="6">
                <a-statistic :title="tx('Produced', 'Producido')" :value="money(production.summary?.services_total)" />
              </a-col>
              <a-col :xs="12" :md="6">
                <a-statistic :title="tx('Paid to them', 'Pagado al empleado')" :value="money(production.summary?.payments_total)" />
              </a-col>
              <a-col :xs="12" :md="6">
                <a-statistic :title="tx('Paid / produced', 'Pagado / producido')" :value="production.summary?.payout_pct == null ? '—' : production.summary.payout_pct + '%'" />
              </a-col>
            </a-row>

            <h4 class="prod-h">{{ tx('Services', 'Servicios') }} ({{ production.services.length }})</h4>
            <a-table
              :columns="serviceColumns" :data-source="production.services" size="small" :row-key="r => r.source + r.id"
              :pagination="{ pageSize: 10, showSizeChanger: true, pageSizeOptions: ['10', '25', '50', '100'] }" :scroll="{ x: 800 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'date'">{{ record.date }} <span class="prod-muted">{{ String(record.time || '').slice(0, 5) }}</span></template>
                <template v-else-if="column.key === 'ref'">
                  <a v-if="record.source === 'booking'" @click="$router.push(`/bookings/${record.id}/edit`)">{{ record.ref }}</a>
                  <a v-else @click="$router.push(`/sales/${record.id}`)">{{ record.ref }}</a>
                </template>
                <template v-else-if="column.key === 'sale_ref'">
                  <a v-if="record.sale_id" @click="$router.push(`/sales/${record.sale_id}`)">{{ record.sale_ref }}</a>
                  <span v-else class="prod-muted">—</span>
                </template>
                <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
              </template>
            </a-table>

            <div class="prod-muted" style="margin-top: 8px">
              {{ tx('Payments made to this employee are in the', 'Los pagos hechos a este empleado están en la pestaña') }} <a @click="tab = 'payments'">{{ $t('Payments') }}</a>{{ tx(' tab.', '.') }}
            </div>
          </a-spin>
        </a-card>
      </a-tab-pane>

      <!-- ============ Payments: everything paid to this employee ============ -->
      <a-tab-pane v-if="canView('payments')" key="payments" :tab="$t('Payments')">
        <a-card size="small">
          <div class="prod-filter">
            <span class="prod-muted">{{ tx('Dates', 'Fechas') }}</span>
            <!-- Same dates as Production (no default re-applied when this tab opens). -->
            <PresetRangeFilter v-model:value="prodRange" :apply-default="false" @change="fetchProduction" />
            <a-button v-if="auth.can('payroll')" size="small" style="margin-left: auto" @click="$router.push('/hrm/payrolls')">{{ tx('Open Payroll', 'Abrir planilla') }}</a-button>
          </div>
          <a-spin :spinning="production.loading">
            <a-row :gutter="[12, 12]" style="margin-bottom: 16px">
              <a-col :xs="12" :md="6">
                <a-statistic :title="$t('Payments')" :value="production.payments.length" />
              </a-col>
              <a-col :xs="12" :md="6">
                <a-statistic :title="tx('Total paid', 'Total pagado')" :value="money(production.summary?.payments_total)" />
              </a-col>
              <a-col v-for="c in paymentsByCategory" :key="c.name" :xs="12" :md="6">
                <a-statistic :title="c.name" :value="money(c.total)" />
              </a-col>
            </a-row>
            <a-table
              :columns="paymentColumns" :data-source="production.payments" size="small" :row-key="r => r.source + r.id"
              :pagination="{ pageSize: 10, showSizeChanger: true, pageSizeOptions: ['10', '25', '50', '100'] }" :scroll="{ x: 800 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'source'">
                  <a-tag :color="record.source === 'payroll' ? 'blue' : 'volcano'">{{ record.source === 'payroll' ? tx('Payroll', 'Planilla') : tx('Expense', 'Gasto') }}</a-tag>
                </template>
                <template v-else-if="column.key === 'ref'">
                  <a v-if="record.source === 'expense'" @click="$router.push(`/expenses/${record.id}/edit`)">{{ record.ref }}</a>
                  <span v-else>{{ record.ref }}</span>
                </template>
                <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
              </template>
            </a-table>
          </a-spin>
        </a-card>
      </a-tab-pane>
    </a-tabs>

    <!-- Experience modal -->
    <a-modal
      v-model:open="expModal" :title="expForm.id ? $t('Edit') : $t('Add')"
      :confirm-loading="savingExp" width="720px" @ok="saveExperience"
    >
      <a-form ref="expRef" :model="expForm" :rules="expRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('title') + ' *'" name="title">
              <a-input v-model:value="expForm.title" :placeholder="$t('Enter_title')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Company_Name') + ' *'" name="company_name">
              <a-input v-model:value="expForm.company_name" :placeholder="$t('Enter_Company_Name')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Location')">
              <a-input v-model:value="expForm.location" :placeholder="$t('Enter_location')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Employment_type') + ' *'" name="employment_type">
              <a-select
                v-model:value="expForm.employment_type"
                :placeholder="$t('Select_Employment_type')" :options="EMPLOYMENT_TYPES"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('start_date') + ' *'" name="start_date">
              <a-date-picker v-model:value="expForm.start_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Finish_Date') + ' *'" name="end_date">
              <a-date-picker v-model:value="expForm.end_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')">
              <a-textarea v-model:value="expForm.description" :rows="3" :placeholder="$t('Enter_Description')" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Bank account modal -->
    <a-modal
      v-model:open="accModal" :title="accForm.id ? $t('Edit') : $t('Add')"
      :confirm-loading="savingAcc" width="640px" @ok="saveAccount"
    >
      <a-form ref="accRef" :model="accForm" :rules="accRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Bank_Name') + ' *'" name="bank_name">
              <a-input v-model:value="accForm.bank_name" :placeholder="$t('Enter_Bank_Name')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Bank_Branch') + ' *'" name="bank_branch">
              <a-input v-model:value="accForm.bank_branch" :placeholder="$t('Enter_Bank_Branch')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Bank_Number') + ' *'" name="account_no">
              <a-input v-model:value="accForm.account_no" :placeholder="$t('Enter_Bank_Number')" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Please_provide_any_details')">
              <a-textarea v-model:value="accForm.note" :rows="3" :placeholder="$t('Enter_Description')" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Employee detail — legacy employee_details.vue. Despite the name it is an
 * EDIT page: two forms (basic info, social links) plus CRUD tables for work
 * experience and bank accounts.
 *
 * Bugs from legacy that are not reproduced:
 * - the experiences pager was bound to a field the fetch never wrote, so it
 *   never showed more than page one;
 * - `accounts_bank` was never declared, making the bank table non-reactive;
 * - `remaining_leave` was omitted from the PUT while the server computes the
 *   new balance as `remaining_leave ± (new total − old total)`, so editing an
 *   employee collapsed their remaining leave to the raw delta. It is sent now.
 * - saving either form redirected back to the employee list; both now stay put
 *   and refresh, since this page is where you keep working.
 *
 * The cascading selects (company → department → designation, company → shift)
 * clear their dependents on change, as legacy did.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { LeftOutlined, PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined, PrinterOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PresetRangeFilter from '../../components/PresetRangeFilter.vue';
import http from '../../lib/http';
import { sortByLabel } from '../../lib/filterOptions';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import { GENDERS, MARITAL_STATUSES, EMPLOYMENT_TYPES, PAY_TYPES, STAFF_TYPES } from './hrmVocab';

const { t } = useI18n();
const ui = useUiStore();
const tx = (en, es) => (ui.locale === 'es' ? es : en);
const route = useRoute();
const id = route.params.id;
const auth = useAuthStore();
const { money, dateTime: fmtDateTime } = useFormat();

const loading = ref(true);
// ?tab=production etc. opens that tab.
const tab = ref(['basic', 'company', 'social', 'experiences', 'accounts', 'user', 'assets', 'production', 'payments'].includes(route.query.tab) ? route.query.tab : 'basic');
const employee = ref({});
const linkedUser = ref(null);
// The employee's name in the page header (as saved -- it follows the form only after Submit).
const savedName = ref('');
// View / Edit per tab from the role (server: App\Support\EmployeeTabs -- it enforces them too).
const tabPerms = ref({});
const canView = key => !!tabPerms.value[key]?.view;
const canEdit = key => !!tabPerms.value[key]?.edit;
const TAB_ORDER = ['basic', 'company', 'social', 'experiences', 'accounts', 'user', 'assets', 'production', 'payments'];
const companies = ref([]);
const departments = ref([]);
const designations = ref([]);
const officeShifts = ref([]);

// Gender, marital status, employment and pay types: hrmVocab (in the screen's language).
const userTypeLabel = v => (ui.locale === 'es'
  ? ({ admin: 'Administrador', employee: 'Empleado', customer: 'Cliente', supplier: 'Proveedor', sales_agent: 'Vendedor', SuperAdmin: 'SuperAdmin' }[v] || v || '—')
  : (v || '—'));
const assetStatus = s =>(ui.locale === 'es' ? ({ assigned: 'Asignado', returned: 'Devuelto', lost: 'Perdido', damaged: 'Dañado' }[s] || s) : s);
const SOCIAL = [
  { key: 'skype', label: 'Skype', placeholder: 'Enter_Skype' },
  { key: 'facebook', label: 'Facebook', placeholder: 'Enter_Facebook' },
  { key: 'whatsapp', label: 'WhatsApp', placeholder: 'Enter_WhatsApp' },
  { key: 'linkedin', label: 'LinkedIn', placeholder: 'Enter_LinkedIn' },
  { key: 'twitter', label: 'Twitter', placeholder: 'Enter_Twitter' },
];

const req = () => ({ required: true, message: t('Field_is_required') });
const basicRef = ref();
const companyRef = ref();
const savingBasic = ref(false);
const savingSocial = ref(false);
const basicRules = computed(() => ({
  firstname: [req()], lastname: [req()], gender: [req()], total_leave: [req()],
  company_id: [req()], department_id: [req()], designation_id: [req()], office_shift_id: [req()],
  // The server requires these two even though legacy never validated them.
  country: [req()], phone: [req()],
}));

// ---------------- cascading selects ----------------

async function onCompanyChange(value) {
  employee.value.department_id = undefined;
  employee.value.designation_id = undefined;
  employee.value.office_shift_id = undefined;
  departments.value = [];
  designations.value = [];
  officeShifts.value = [];
  if (!value) return;
  const [d, s] = await Promise.allSettled([
    http.get('core/get_departments_by_company', { id: value }),
    http.get('core/get_office_shift_by_company', { id: value }),
  ]);
  if (d.status === 'fulfilled') departments.value = d.value || [];
  if (s.status === 'fulfilled') officeShifts.value = s.value || [];
}

async function onDepartmentChange(value) {
  employee.value.designation_id = undefined;
  designations.value = [];
  if (!value) return;
  try {
    designations.value = await http.get('core/get_designations_by_department', { id: value }) || [];
  } catch (e) { /* select stays empty */ }
}

// ---------------- saving ----------------

const BASIC_KEYS = [
  'firstname', 'lastname', 'country', 'email', 'gender', 'phone', 'birth_date',
  'company_id', 'department_id', 'designation_id', 'office_shift_id', 'joining_date',
  'leaving_date', 'marital_status', 'employment_type', 'city', 'province', 'address',
  'zipcode', 'hourly_rate', 'basic_salary', 'total_leave', 'pay_type', 'commission_rate', 'pay_effective_from',
  'staff_type', 'trip_rate',
  // Sent so the server's remaining-leave arithmetic has a base to work from.
  'remaining_leave',
];

// Basic information and Company are one record: either Submit saves both tabs
// (each tab's form checks its own required fields).
async function saveBasic() {
  try {
    await basicRef.value?.validate();
  } catch (e) {
    tab.value = 'basic';
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  try {
    await companyRef.value?.validate();
  } catch (e) {
    tab.value = 'company';
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  savingBasic.value = true;
  const body = {};
  BASIC_KEYS.forEach(k => { body[k] = employee.value[k]; });
  try {
    await http.put(`employees/${employee.value.id}`, body);
    message.success(t('Updated_in_successfully'));
    await loadEmployee();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingBasic.value = false;
  }
}

async function saveSocial() {
  savingSocial.value = true;
  const body = {};
  SOCIAL.forEach(s => { body[s.key] = employee.value[s.key]; });
  try {
    await http.put(`update_social_profile/${employee.value.id}`, body);
    message.success(t('Updated_in_successfully'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingSocial.value = false;
  }
}

// ---------------- experiences ----------------

const experiences = reactive({ rows: [], total: 0, page: 1, limit: 10, loading: false });
const expModal = ref(false);
const savingExp = ref(false);
const expRef = ref();
const blankExp = () => ({
  id: null, title: '', company_name: '', employment_type: undefined,
  location: '', start_date: null, end_date: null, description: '',
});
const expForm = ref(blankExp());
const expRules = computed(() => ({
  title: [req()], company_name: [req()], employment_type: [req()],
  start_date: [req()], end_date: [req()],
}));

const experienceColumns = computed(() => [
  { title: t('title'), dataIndex: 'title', key: 'title' },
  { title: t('Company'), dataIndex: 'company_name', key: 'company_name' },
  { title: t('start_date'), dataIndex: 'start_date', key: 'start_date' },
  { title: t('Finish_Date'), dataIndex: 'end_date', key: 'end_date' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function fetchExperiences() {
  experiences.loading = true;
  try {
    const data = await http.get('get_experiences_by_employee', {
      id, page: experiences.page, limit: experiences.limit,
    });
    experiences.rows = data?.experiences || [];
    experiences.total = Number(data?.totalRows) || 0;
  } catch (e) {
    experiences.rows = [];
  } finally {
    experiences.loading = false;
  }
}

/** Cloned, so editing and cancelling does not mutate the table row. */
function openExperience(row) {
  expForm.value = row ? { ...row } : blankExp();
  expModal.value = true;
}

async function saveExperience() {
  try {
    await expRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  savingExp.value = true;
  const body = {
    title: expForm.value.title,
    company_name: expForm.value.company_name,
    employee_id: employee.value.id,
    location: expForm.value.location,
    employment_type: expForm.value.employment_type,
    start_date: expForm.value.start_date,
    end_date: expForm.value.end_date,
    description: expForm.value.description,
  };
  try {
    if (expForm.value.id) {
      await http.put(`work_experience/${expForm.value.id}`, body);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('work_experience', body);
      message.success(t('Created_in_successfully'));
    }
    expModal.value = false;
    await fetchExperiences();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingExp.value = false;
  }
}

async function removeExperience(expId) {
  try {
    await http.delete(`work_experience/${expId}`);
    message.success(t('Deleted_in_successfully'));
    await fetchExperiences();
  } catch (e) {
    message.error(t('Delete_Therewassomethingwronge'));
  }
}

// ---------------- bank accounts ----------------

const accounts = reactive({ rows: [], total: 0, page: 1, limit: 10, loading: false });
const accModal = ref(false);
const savingAcc = ref(false);
const accRef = ref();
const blankAcc = () => ({ id: null, bank_name: '', bank_branch: '', account_no: '', note: '' });
const accForm = ref(blankAcc());
const accRules = computed(() => ({
  bank_name: [req()], bank_branch: [req()], account_no: [req()],
}));

const accountColumns = computed(() => [
  { title: t('Bank_Name'), dataIndex: 'bank_name', key: 'bank_name' },
  { title: t('Bank_Branch'), dataIndex: 'bank_branch', key: 'bank_branch' },
  { title: t('Bank_Number'), dataIndex: 'account_no', key: 'account_no' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

async function fetchAccounts() {
  accounts.loading = true;
  try {
    const data = await http.get('get_accounts_by_employee', {
      id, page: accounts.page, limit: accounts.limit,
    });
    accounts.rows = data?.accounts_bank || [];
    accounts.total = Number(data?.totalRows) || 0;
  } catch (e) {
    accounts.rows = [];
  } finally {
    accounts.loading = false;
  }
}

function openAccount(row) {
  accForm.value = row ? { ...row } : blankAcc();
  accModal.value = true;
}

async function saveAccount() {
  try {
    await accRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  savingAcc.value = true;
  const body = {
    employee_id: employee.value.id,
    bank_name: accForm.value.bank_name,
    bank_branch: accForm.value.bank_branch,
    account_no: accForm.value.account_no,
    note: accForm.value.note,
  };
  try {
    if (accForm.value.id) {
      await http.put(`employee_account/${accForm.value.id}`, body);
      message.success(t('Updated_in_successfully'));
    } else {
      await http.post('employee_account', body);
      message.success(t('Created_in_successfully'));
    }
    accModal.value = false;
    await fetchAccounts();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingAcc.value = false;
  }
}

async function removeAccount(accId) {
  try {
    await http.delete(`employee_account/${accId}`);
    message.success(t('Deleted_in_successfully'));
    await fetchAccounts();
  } catch (e) {
    message.error(t('Delete_Therewassomethingwronge'));
  }
}

// ---------------- photos (employee photo / linked user's picture) ----------------

const photoBusy = ref(null);
const photoUrl = p => (p && !String(p).includes('no_avatar') ? '/' + String(p).replace(/^\/+/, '') : null);
const initials = (f, l) => ((String(f || '').trim()[0] || '') + (String(l || '').trim()[0] || '')).toUpperCase() || '?';
/** a-upload before-upload: send it ourselves, never let the component upload. */
function uploadPhoto(target, file) {
  if (file.size > 5 * 1024 * 1024) {
    message.error('The image is larger than 5 MB.');
    return false;
  }
  const fd = new FormData();
  fd.append('photo', file);
  photoBusy.value = target;
  http.postForm(`employees/${id}/photo?for=${target}`, fd)
    .then(res => {
      if (target === 'user') linkedUser.value = { ...linkedUser.value, avatar: res.avatar };
      else employee.value = { ...employee.value, avatar: res.avatar };
      message.success(target === 'user' ? 'Picture updated.' : 'Photo updated.');
    })
    .catch(e => message.error(e?.data?.errors?.photo?.[0] || e?.data?.message || 'Could not upload the image.'))
    .finally(() => { photoBusy.value = null; });
  return false;
}
async function removePhoto(target) {
  photoBusy.value = target;
  try {
    await http.delete(`employees/${id}/photo`, { for: target });
    if (target === 'user') linkedUser.value = { ...linkedUser.value, avatar: null };
    else employee.value = { ...employee.value, avatar: null };
    message.success('Removed.');
  } catch (e) {
    message.error(e?.data?.message || 'Could not remove it.');
  } finally {
    photoBusy.value = null;
  }
}

// ---------------- production ----------------

const prodRange = ref(null);
const production = reactive({ services: [], payments: [], summary: null, loading: false });
const serviceColumns = computed(() => [
  { title: t('date'), key: 'date', width: 140 },
  { title: tx('Booking / sale', 'Reserva / venta'), key: 'ref', width: 110 },
  { title: tx('Service', 'Servicio'), dataIndex: 'service', key: 'service', width: 200, ellipsis: true },
  { title: t('Customer'), dataIndex: 'customer', key: 'customer', width: 180, ellipsis: true },
  { title: t('Status'), dataIndex: 'status', key: 'status', width: 100 },
  { title: t('Sale'), key: 'sale_ref', width: 100 },
  { title: t('Amount'), key: 'amount', width: 110, align: 'right' },
]);
const paymentColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', width: 110 },
  { title: tx('Type', 'Tipo'), key: 'source', width: 100 },
  { title: t('Reference'), key: 'ref', width: 110 },
  { title: tx('Category', 'Categoría'), dataIndex: 'category', key: 'category', width: 170, ellipsis: true },
  { title: tx('Paid with', 'Pagado con'), dataIndex: 'method', key: 'method', width: 100 },
  { title: tx('Details', 'Detalle'), dataIndex: 'details', key: 'details', ellipsis: true },
  { title: t('Amount'), key: 'amount', width: 110, align: 'right' },
]);
// Payments tab: total per category (e.g. Contract labor (1099), Payroll), biggest first.
const paymentsByCategory = computed(() => {
  const m = new Map();
  production.payments.forEach(p => {
    const k = p.category || 'Other';
    m.set(k, (m.get(k) || 0) + Number(p.amount || 0));
  });
  return [...m.entries()].map(([name, total]) => ({ name, total })).sort((a, b) => b.total - a.total).slice(0, 2);
});
async function fetchProduction() {
  production.loading = true;
  try {
    const [from, to] = prodRange.value || [];
    const data = await http.get(`employees/${id}/production`, { from: from || undefined, to: to || undefined });
    production.services = data?.services || [];
    production.payments = data?.payments || [];
    production.summary = data?.summary || null;
  } catch (e) {
    message.error(e?.data?.message || 'Could not load the production.');
  } finally {
    production.loading = false;
  }
}

// ---------------- Assets assigned ----------------
const assets = reactive({ rows: [], loading: false });
const assetsOpen = computed(() => assets.rows.filter(r => r.status === 'assigned').length);
async function fetchAssets() {
  assets.loading = true;
  try {
    assets.rows = (await http.get(`employees/${id}/assets`))?.assets || [];
  } catch (e) {
    message.error(e?.data?.message || 'Could not load the assets.');
  } finally {
    assets.loading = false;
  }
}

// ---------------- Print profile ----------------
// GET employees/{id}/print: every section this role may see (server-checked),
// laid out on one printable page with the photo; the browser's print dialog
// also saves it as PDF.
const printing = ref(false);
const esc = v => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
function kvTable(obj) {
  const rows = Object.entries(obj || {}).filter(([, v]) => v !== null && v !== '' && v !== undefined);
  return rows.length ? `<table class="kv">${rows.map(([k, v]) => `<tr><th>${esc(k)}</th><td>${esc(v)}</td></tr>`).join('')}</table>` : '<p class="muted">—</p>';
}
function listTable(cols, rows) {
  if (!rows?.length) return `<p class="muted">${tx('None.', 'Ninguno.')}</p>`;
  return `<table class="list"><thead><tr>${cols.map(c => `<th>${esc(c[0])}</th>`).join('')}</tr></thead><tbody>${
    rows.map(r => `<tr>${cols.map(c => `<td>${esc(typeof c[1] === 'function' ? c[1](r) : r[c[1]])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}
async function printProfile() {
  printing.value = true;
  const w = window.open('', '_blank'); // opened on the click, so pop-up blockers allow it
  try {
    const d = await http.get(`employees/${id}/print`);
    // The company's language (the server says which; labels / values come translated from it).
    const L = (en, es) => (d.es ? es : en);
    const sec = (title, html) => (html === null ? '' : `<section><h2>${esc(title)}</h2>${html}</section>`);
    const body = [
      d.basic ? sec(L('Basic information', 'Datos básicos'), kvTable(d.basic)) : '',
      d.company_info ? sec(L('Company', 'Empresa'), kvTable(d.company_info)) : '',
      d.social ? sec(L('Social media', 'Redes sociales'), kvTable(d.social)) : '',
      d.experiences ? sec(L('Experiences', 'Experiencia'), listTable([[L('Title', 'Cargo'), 'title'], [L('Company', 'Empresa'), 'company_name'], [L('Location', 'Lugar'), 'location'],
        [L('Type', 'Tipo'), 'employment_type'], [L('From', 'Desde'), 'start_date'], [L('To', 'Hasta'), 'end_date']], d.experiences)) : '',
      d.accounts ? sec(L('Bank accounts', 'Cuentas bancarias'), listTable([[L('Bank', 'Banco'), 'bank_name'], [L('Branch', 'Sucursal'), 'bank_branch'], [L('Account', 'Cuenta'), 'account_no']], d.accounts)) : '',
      d.user ? sec(L('User (login)', 'Usuario (login)'), kvTable(d.user)) : '',
      d.assets ? sec(L('Assets assigned', 'Activos asignados'), listTable([[L('Asset', 'Activo'), r => [r.name, r.tag].filter(Boolean).join(' · ')], [L('Category', 'Categoría'), 'category'],
        [L('Serial', 'Serie'), 'serial_number'], [L('Assigned', 'Asignado'), 'assigned_on'], [L('Due back', 'Devolver'), 'due_back_on'], [L('Returned', 'Devuelto'), 'returned_on'],
        [L('Status', 'Estado'), r => (r.overdue ? L('Overdue', 'Vencido') : r.status)]], d.assets)) : '',
    ].join('');
    const initials = (d.name || '?').split(/\s+/).map(s => s[0]).slice(0, 2).join('').toUpperCase();
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(d.name)} — ${L('Employee profile', 'Ficha del empleado')}</title><style>
      body{font:13px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#1f2937;margin:28px}
      header{display:flex;gap:20px;align-items:center;border-bottom:2px solid #1f2937;padding-bottom:14px;margin-bottom:6px}
      .photo{width:110px;height:110px;border-radius:12px;object-fit:cover;border:1px solid #ddd}
      .noph{width:110px;height:110px;border-radius:12px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:700;color:#6b7280}
      h1{margin:0 0 4px;font-size:24px} .sub{color:#6b7280} .co{margin-left:auto;text-align:right} .co img{max-height:48px;max-width:160px}
      h2{font-size:14px;text-transform:uppercase;letter-spacing:.05em;margin:18px 0 6px;color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:4px}
      table{border-collapse:collapse;width:100%} .kv th{text-align:left;width:190px;color:#6b7280;font-weight:500;padding:3px 8px 3px 0;vertical-align:top} .kv td{padding:3px 0}
      .list th,.list td{border:1px solid #e5e7eb;padding:4px 6px;text-align:left} .list th{background:#f9fafb}
      section{break-inside:avoid} .muted{color:#9ca3af;margin:0} footer{margin-top:22px;color:#9ca3af;font-size:11px}
      @media print{body{margin:12mm}}
    </style></head><body>
      <header>
        ${d.photo ? `<img class="photo" src="${esc(d.photo)}" alt="">` : `<div class="noph">${esc(initials)}</div>`}
        <div><h1>${esc(d.name)}</h1><div class="sub">${esc([d.company_info?.Designation, d.company_info?.Department].filter(Boolean).join(' · '))}</div></div>
        <div class="co">${d.company.logo ? `<img src="${esc(d.company.logo)}" alt=""><br>` : ''}<b>${esc(d.company.name || '')}</b></div>
      </header>
      ${body}
      <footer>${L('Printed', 'Impreso el')} ${esc(d.printed_at)} ${L('by', 'por')} ${esc(d.printed_by)} · ${L('Confidential employee information', 'Información confidencial del empleado')}</footer>
    </body></html>`);
    w.document.close();
    // Print once the photo / logo have loaded.
    const imgs = Array.from(w.document.images);
    await Promise.all(imgs.map(i => (i.complete ? null : new Promise(res => { i.onload = i.onerror = res; }))));
    w.focus();
    w.print();
  } catch (e) {
    w?.close();
    message.error(e?.data?.message || 'Could not prepare the profile.');
  } finally {
    printing.value = false;
  }
}

// ---------------- load ----------------

async function loadEmployee() {
  const data = await http.get(`employees/${id}`);
  employee.value = data?.employee || {};
  linkedUser.value = data?.user || null;
  tabPerms.value = data?.tabs || {};
  savedName.value = [employee.value.firstname, employee.value.lastname].map(s => String(s || '').trim()).filter(Boolean).join(' ');
  companies.value = data?.companies || [];
  departments.value = data?.departments || [];
  designations.value = data?.designations || [];
  officeShifts.value = data?.office_shifts || [];
}

onMounted(async () => {
  try {
    await loadEmployee();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
  // The asked tab (?tab=) only if this role may see it, else the first one it may.
  if (!canView(tab.value)) tab.value = TAB_ORDER.find(canView) || 'basic';
  if (canView('experiences')) fetchExperiences();
  if (canView('accounts')) fetchAccounts();
  if (canView('assets')) fetchAssets();
  if (canView('production') || canView('payments')) fetchProduction();
});
</script>

<style scoped>
.prod-filter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.prod-muted {
  color: #8c8c8c;
}
.prod-h {
  margin: 16px 0 8px;
}
.ph {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.ph :deep(.ph-img) {
  border-radius: 50%;
  object-fit: cover;
}
.ph-empty {
  font-size: 30px;
  background: #d9d9d9;
}
.ph-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
</style>
