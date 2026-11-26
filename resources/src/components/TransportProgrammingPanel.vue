<template>
  <!-- Programación (2026-11-26): the week like Operaciones' Excel -- a column per day, Lima's side then the north's, a card
       per departure with Jelaf's sold seats, the forecast and what to do (App\Services\TransportProgramming). -->
  <div class="pg">
    <div class="pg-top">
      <div class="pg-nav">
        <a-button size="small" @click="move(-7)"><LeftOutlined /></a-button>
        <b class="pg-week">{{ weekLabel }}</b>
        <a-button size="small" @click="move(7)"><RightOutlined /></a-button>
        <a-button size="small" @click="go(today)">{{ L('This week', 'Esta semana') }}</a-button>
        <!-- What the colours mean (the user, 2026-11-26: as a helper, not on the board). -->
        <a-popover placement="bottomLeft" trigger="hover" :overlay-style="{ maxWidth: '440px' }">
          <template #content>
            <div class="pg-legend">
              <h4>{{ L('How to read a row', 'Cómo leer una fila') }}</h4>
              <p>{{ L('Example "11→39/46": 11 tickets sold so far, 39 expected when the bus leaves, 46 seats.', 'Ejemplo «11→39/46»: 11 pasajes vendidos hasta ahora, 39 que se esperan cuando salga el bus, 46 asientos.') }}</p>
              <div><i style="background: #27507d" /> {{ L('Dark part of the bar: sold', 'Parte oscura de la barra: vendido') }}</div>
              <div><i style="background: #c9d5e4" /> {{ L('Light part: still expected (forecast)', 'Parte clara: lo que aún se espera (pronóstico)') }}</div>
              <h4>{{ L('Colours', 'Colores') }}</h4>
              <p>{{ L('Past days, today and tomorrow: sold ÷ seats. From the day after tomorrow: expected ÷ seats.', 'Días pasados, hoy y mañana: vendidos ÷ asientos. Desde pasado mañana: esperados ÷ asientos.') }}</p>
              <div><i style="background: #d92d20" /> {{ L('Red: under 80 %', 'Rojo: menos del 80 %') }}</div>
              <div><i style="background: #2e9e5b" /> {{ L('Green: 80 to 89 %', 'Verde: 80 a 89 %') }}</div>
              <div><i style="background: #f28c13" /> {{ L('Orange: 90 % or more, reinforce', 'Naranja: 90 % o más, reforzar') }}</div>
              <div><i style="background: transparent; border: 1px solid #d92d20" /> {{ L('Time in red: the bus is in two places', 'Hora en rojo: el bus está en dos lugares') }}</div>
              <h4>{{ L('How the forecast is made', 'Cómo se calcula el pronóstico') }}</h4>
              <p>{{ L('Expected = sold + what usually still comes.', 'Esperados = vendidos + lo que todavía suele venderse.') }}</p>
              <p>{{ L('1) Usual passengers: the average this departure carried (same direction, weekday and hour) over the last 8 weeks.', '1) Pasajeros habituales: el promedio que llevó esta salida (mismo sentido, día de la semana y hora) en las últimas 8 semanas.') }}</p>
              <p>{{ L('2) How much is usually sold already: in 2026, the day before a departure about 40 % of its tickets were sold; 4 days before, 17 %.', '2) Cuánto suele estar vendido ya: en 2026, un día antes de la salida estaba vendido cerca del 40 % de sus pasajes; 4 días antes, el 17 %.') }}</p>
              <p>{{ L('Example: Friday, 1 day before: 11 sold + 46 usual × 60 % still to come ≈ 39 expected of 46 seats. Its colour goes by the 11 sold (24 %) → red.', 'Ejemplo: viernes, a 1 día: 11 vendidos + 46 habituales × 60 % que aún falta ≈ 39 esperados de 46 asientos. Su color va por los 11 vendidos (24 %) → rojo.') }}</p>
              <p>{{ L('Rest the mouse on a bar to see its own numbers.', 'Pase el mouse sobre una barra para ver sus propios números.') }}</p>
            </div>
          </template>
          <span class="pg-legend-i"><InfoCircleOutlined /> {{ L('How to read it', 'Cómo leerlo') }}</span>
        </a-popover>
      </div>
      <div class="pg-chips">
        <span class="pg-chip c-hi" :title="L('Forecast at 90 % of the seats or more', 'Pronóstico de 90 % de los asientos o más')"><b>{{ s.reinforce }}</b> {{ L('reinforce', 'reforzar') }}</span>
        <span class="pg-chip c-bad" :title="L('Under 80 % of the seats (sold for past days, forecast for the next ones)', 'Bajo 80 % de los asientos (vendido en días pasados, pronóstico en los próximos)')"><b>{{ s.under || 0 }}</b> {{ L('under 80 %', 'bajo 80 %') }}</span>
        <span class="pg-chip" :class="s.conflicts ? 'c-bad' : 'c-ok'"><b>{{ s.conflicts }}</b> {{ L('bus clashes', 'choques') }}</span>
        <span class="pg-chip c-plain" :title="L('In the plan, nothing sold / not open in Jelaf yet', 'En el plan, sin ventas / aún no abierta en Jelaf')"><b>{{ s.plan_only }}</b> {{ L('plan only', 'solo plan') }}</span>
        <span class="pg-chip c-plain" :title="L('Open in Jelaf, not in the plan', 'Abierta en Jelaf, no está en el plan')"><b>{{ s.jelaf_only }}</b> {{ L('Jelaf only', 'fuera de plan') }}</span>
      </div>
      <div class="pg-tools">
        <span class="pg-at" :title="L('Seats sold ahead come from Jelaf each night at 22:00', 'Los pasajes vendidos por adelantado llegan de Jelaf cada noche a las 22:00')">
          {{ L('Sold as of', 'Vendidos al') }} {{ data.ahead_at ? fmt.dateTime(data.ahead_at) : '—' }}
        </span>
        <a-button size="small" :loading="exporting" @click="exportWeek"><FileExcelOutlined /> Excel</a-button>
      </div>
    </div>

    <a-spin :spinning="loading">
      <!-- Two bands (user, 2026-11-26): LIMA → CHICLAYO on top, CHICLAYO → LIMA below, the days as columns. Each day is the
           Excel's grid: the service / bus on the left, the time under the terminal it leaves from (CAVASSA, PZ NORTE,
           OLIVOS -- CIX, LAMBAYEQUE), and under it a thin bar of the seats sold (that date, that hour). -->
      <div class="pg-wrap">
        <div class="pg-grid pg-heads">
          <div v-for="d in data.days" :key="d.date" class="pg-dh" :class="{ past: d.past, today: d.today }">
            <div class="pg-dname">{{ d.label }}<span v-if="d.today" class="pg-tag-today">{{ L('today', 'hoy') }}</span></div>
            <div class="pg-dsub">
              <template v-if="d.past">{{ d.sold }} pax · {{ pct(d.sold, d.seats) }}</template>
              <template v-else>{{ d.sold }} {{ L('sold', 'vend.') }} → {{ d.forecast }} · {{ pct(d.forecast, d.seats) }}</template>
            </div>
          </div>
        </div>
        <template v-for="side in SIDES" :key="side.key">
          <div class="pg-band"><span>{{ L(side.en, side.es) }}</span></div>
          <div class="pg-grid pg-lists">
            <div v-for="d in data.days" :key="d.date + side.key" class="pg-col" :class="{ past: d.past, today: d.today }">
              <div class="pg-tr pg-th" :style="cols(side.key)">
                <span />
                <span v-for="t in tcols(side.key)" :key="t" :title="t">{{ short(t) }}</span>
              </div>
              <div
                v-for="x in d.departures.filter(x => x.side === side.key)" :key="x.key" class="pg-row-d"
                :class="[cls(x, d), { removed: x.removed, planonly: x.source === 'plan' }]" @click="open(x, d)"
              >
                <div class="pg-tr" :style="cols(side.key)">
                  <span class="pg-who" :title="(x.bus || '') + (x.tag ? ' · ' + x.tag : '')">
                    <b :class="{ tentative: x.bus_from === 'base', none: !x.bus }">{{ x.bus ? busNo(x.bus) : '—' }}</b>
                    <small v-if="x.tag">{{ x.tag }}</small>
                  </span>
                  <span v-for="t in tcols(side.key)" :key="t" class="pg-t" :class="{ on: stopTime(x, t, side.key) }">
                    {{ stopTime(x, t, side.key) ? hm12(stopTime(x, t, side.key)) : '' }}
                  </span>
                </div>
                <div class="pg-l2">
                  <!-- Hovering the bar: sold (by channel), the forecast and how it is made -- apart from the click panel. -->
                  <a-popover placement="right" :mouse-enter-delay="0.25" :overlay-style="{ maxWidth: '340px' }" :title="`${ampm(x.time)} · ${x.bus || ''} ${x.tag || ''}`">
                    <template #content>
                      <div class="pg-hov">
                        <div class="pg-hov-r"><span>{{ L('Sold', 'Vendidos') }}</span><b>{{ x.sold }}<template v-if="x.seats"> / {{ x.seats }} ({{ pct(x.sold, x.seats) }})</template></b></div>
                        <div v-if="Object.keys(x.channels || {}).length" class="pg-hov-ch">
                          {{ Object.entries(x.channels).sort((a, b) => b[1].n - a[1].n).map(([k, v]) => `${chName(k)} ${v.n}`).join(' · ') }}
                        </div>
                        <template v-if="!d.past">
                          <div class="pg-hov-r"><span>{{ L('Forecast', 'Pronóstico') }}</span><b>{{ x.forecast }}<template v-if="x.seats"> / {{ x.seats }} ({{ pct(x.forecast, x.seats) }})</template></b></div>
                          <div v-if="x.fc_usual !== null" class="pg-hov-ex">
                            {{ L(`${x.sold} sold + ${Math.round(x.fc_usual)} usual × ${Math.round((1 - x.fc_r) * 100)} % still to come ≈ ${x.forecast}.`,
                                 `${x.sold} vendidos + ${Math.round(x.fc_usual)} habituales × ${Math.round((1 - x.fc_r) * 100)} % que aún falta ≈ ${x.forecast}.`) }}
                            <small>{{ L(`${d.days_left} day(s) before; usually ${Math.round(x.fc_r * 100)} % is sold by now; it carried ${Math.round(x.fc_usual)} on average (same direction, weekday and hour, last 8 weeks).`,
                              `A ${d.days_left} día(s); a esta altura suele estar vendido el ${Math.round(x.fc_r * 100)} %; llevó ${Math.round(x.fc_usual)} en promedio (mismo sentido, día y hora, últimas 8 semanas).`) }}</small>
                          </div>
                        </template>
                        <div class="pg-hov-c">{{ bySold(d) ? L('Colour by sold', 'Color por lo vendido') : L('Colour by forecast', 'Color por el pronóstico') }} · {{ L('source: Jelaf', 'fuente: Jelaf') }}</div>
                      </div>
                    </template>
                    <div class="pg-bar"><i class="fc" :style="{ width: w(x.forecast, x.seats) }" /><i class="sd" :style="{ width: w(x.sold, x.seats) }" /></div>
                  </a-popover>
                  <span class="pg-num">{{ x.sold }}<template v-if="!d.past && x.forecast !== x.sold">→<b>{{ x.forecast }}</b></template><small v-if="x.seats">/{{ x.seats }}</small></span>
                  <WarningFilled v-if="x.alerts.length" class="pg-warn" />
                  <span v-if="x.added" class="pg-flag">{{ L('Reinf.', 'Ref.') }}</span>
                  <span v-else-if="x.changed" class="pg-flag">{{ L('Chg.', 'Cambio') }}</span>
                </div>
              </div>
              <a v-if="canAdd(d)" class="pg-add" @click="openAdd(d, side.key)"><PlusOutlined /> {{ L('departure', 'salida') }}</a>
            </div>
          </div>
        </template>
        <!-- The price helper under each day, as in the Excel: the plan's prices and what Jelaf's tickets were sold at. -->
        <div class="pg-grid pg-prices">
          <div v-for="d in data.days" :key="d.date + 'p'" class="pg-col" :class="{ today: d.today }">
            <template v-if="Object.keys(d.prices || {}).length">
              <div v-for="(cls2, rk) in d.prices" :key="rk" class="pg-price">
                <b>{{ rk }}</b> <span v-for="(v, c) in cls2" :key="c">{{ c === 'SOFACAMA' ? 'Sofa' : c === 'SEMICAMA' ? 'Semi' : c }} {{ v !== null && v !== '' ? 'S/' + v : '—' }}</span>
              </div>
            </template>
            <div v-else class="pg-price muted">{{ L('No plan price', 'Sin precio en el plan') }}</div>
            <div class="pg-price paid" :title="L('Average price of the tickets sold for that day (Jelaf)', 'Precio promedio de los pasajes vendidos para ese día (Jelaf)')">
              {{ L('Sold at', 'Cobrado') }}: <span>LIM {{ d.paid?.lima ? 'S/' + Math.round(d.paid.lima) : '—' }}</span> <span>CIX {{ d.paid?.chiclayo ? 'S/' + Math.round(d.paid.chiclayo) : '—' }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- The buses, to help pick one (user, 2026-11-26): use, where each one is, what keeps it off the road. -->
      <div class="pg-buses">
        <div class="pg-band pg-band-b"><span>{{ L('BUSES', 'BUSES') }}</span>
          <a-segmented v-model:value="busSort" size="small" :options="busSortOptions" class="pg-bsort" />
        </div>
        <table class="pg-btable">
          <thead>
            <tr>
              <th>Bus</th><th class="r">{{ L('Seats', 'Asientos') }}</th><th>{{ L('Where it is', 'Dónde está') }}</th><th>{{ L('Last trip', 'Último viaje') }}</th>
              <th class="r">{{ L('Trips 30 d', 'Viajes 30 d') }}</th><th>{{ L('Occupancy 30 d', 'Ocupación 30 d') }}</th><th class="r">{{ L('This week', 'Esta semana') }}</th><th>{{ L('Status', 'Estado') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in buses" :key="b.id" :class="{ bad: !b.ok }">
              <td><b>{{ b.name }}</b> <small>{{ b.plate }}</small></td>
              <td class="r">{{ b.seats || '—' }}</td>
              <td>{{ b.where ? (b.where === 'LIMA' ? 'Lima' : b.where.charAt(0) + b.where.slice(1).toLowerCase()) : '—' }}<small v-if="b.idle_days !== null"> · {{ b.idle_days === 0 ? L('today', 'hoy') : L(`${b.idle_days} d ago`, `hace ${b.idle_days} d`) }}</small></td>
              <td><small>{{ b.last ? fmt.dateTime(b.last) : '—' }} {{ b.last_route || '' }}</small></td>
              <td class="r">{{ b.trips }}</td>
              <td><div class="pg-occ"><div class="pg-bar"><i class="sd" :style="{ width: b.occupancy !== null ? Math.min(100, Math.round(b.occupancy * 100)) + '%' : '0%' }" /></div><span>{{ b.occupancy !== null ? Math.round(b.occupancy * 100) + ' %' : '—' }}</span></div></td>
              <td class="r">{{ b.week_sure }}<small v-if="b.week_usual" :title="L('in the base timetable', 'en el horario base')"> +{{ b.week_usual }}</small></td>
              <td>
                <span v-if="!b.alerts.length" class="pg-ok">{{ L('Available', 'Disponible') }}</span>
                <span v-for="(a, i) in b.alerts" :key="i" class="pg-al" :class="a[0]">{{ a[1] }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </a-spin>

    <!-- One departure (2026-11-26, the user's order): what to do, the data, programming in its own bordered box, the sales
         as a table (the agency's cashiers in a helper), the bus's photo, then source and note at the end. -->
    <a-drawer :open="!!sel" :width="560" :title="sel ? `${selDay.label} · ${ampm(sel.time)} · ${L(sideName(sel.side).en, sideName(sel.side).es)}` : ''" @close="sel = null">
      <template v-if="sel">
        <div v-if="sel.suggest" class="pg-sug" :class="sel.suggest">
          <b>{{ sel.suggest === 'reinforce' ? L('Reinforce', 'Reforzar') : L('Low occupancy', 'Baja ocupación') }}</b>
          — {{ sel.suggest === 'reinforce'
            ? L(`about ${sel.forecast} passengers expected for ${sel.seats} seats: add a bus or move it to a bigger one.`, `se esperan unos ${sel.forecast} pasajeros para ${sel.seats} asientos: agregar un bus o pasar a uno más grande.`)
            : L(`about ${sel.forecast} passengers expected for ${sel.seats} seats: merge with a near departure or use a smaller bus.`, `se esperan unos ${sel.forecast} pasajeros para ${sel.seats} asientos: juntar con una salida cercana o usar un bus más chico.`) }}
        </div>
        <a-alert v-for="(a, i) in sel.alerts" :key="i" type="error" show-icon :message="a" style="margin-bottom: 8px; padding: 4px 10px" />

        <dl class="pg-dl">
          <dt>{{ L('Bus', 'Bus') }}</dt>
          <dd>{{ sel.bus || L('not assigned yet', 'aún sin asignar') }}<small v-if="sel.plate"> · {{ sel.plate }}</small>
            <small v-if="sel.bus_from === 'base'"> · {{ L('usual bus of the base timetable', 'bus habitual del horario base') }}</small>
            <small v-else-if="sel.bus_from === 'jelaf'"> · Jelaf</small></dd>
          <dt>{{ L('Service', 'Servicio') }}</dt><dd>{{ sel.tag || '—' }}</dd>
          <dt>{{ L('Leaves from', 'Sale de') }}</dt><dd>{{ sel.terminal || '—' }}<small v-if="sel.boardings?.length"> · {{ sel.boardings.map(b => `${b.terminal} ${ampm(b.time)}`).join(', ') }}</small></dd>
          <dt>{{ L('Route', 'Ruta') }}</dt><dd>{{ sel.route || '—' }}</dd>
          <dt>{{ L('Sold', 'Vendidos') }}</dt><dd><b>{{ sel.sold }}</b><template v-if="sel.seats"> / {{ sel.seats }} {{ L('seats', 'asientos') }} ({{ pct(sel.sold, sel.seats) }})</template><template v-if="selDay.past"> · {{ sel.boarded }} {{ L('boarded', 'embarcaron') }}</template></dd>
          <template v-if="!selDay.past"><dt>{{ L('Forecast', 'Pronóstico') }}</dt><dd><b>{{ sel.forecast }}</b> {{ L('expected', 'esperados') }}<template v-if="sel.seats"> ({{ pct(sel.forecast, sel.seats) }} {{ L('of the seats', 'de los asientos') }})</template>
            <div v-if="sel.fc_usual !== null" class="pg-fc">
              {{ L(`${sel.sold} sold + ${Math.round(sel.fc_usual)} usual × ${Math.round((1 - sel.fc_r) * 100)} % still to come ≈ ${sel.forecast}`,
                   `${sel.sold} vendidos + ${Math.round(sel.fc_usual)} habituales × ${Math.round((1 - sel.fc_r) * 100)} % que aún falta ≈ ${sel.forecast}`) }}
              <small>{{ L(`${selDay.days_left} day(s) before; this departure carried ${Math.round(sel.fc_usual)} on average (same direction, weekday and hour, last 8 weeks); usually ${Math.round(sel.fc_r * 100)} % is already sold at this point.`,
                `A ${selDay.days_left} día(s) de la salida; esta salida llevó ${Math.round(sel.fc_usual)} en promedio (mismo sentido, día y hora, últimas 8 semanas); a esta altura suele estar vendido el ${Math.round(sel.fc_r * 100)} %.`) }}</small>
            </div></dd></template>
        </dl>

        <!-- Programming this date: its own bordered box. -->
        <div v-if="data.can_edit && !selDay.past" class="pg-box">
          <div class="pg-box-t">{{ L('Program this date', 'Programar esta fecha') }}</div>
          <a-form layout="vertical">
            <div class="pg-row">
              <a-form-item :label="L('Bus', 'Bus')" style="flex: 1"><a-select v-model:value="edit.vehicle_id" :options="busOptions" allow-clear show-search option-filter-prop="label" /></a-form-item>
              <a-form-item :label="L('Time', 'Hora')" style="width: 100px"><a-input v-model:value="edit.time" placeholder="18:30" /></a-form-item>
            </div>
            <div class="pg-row">
              <a-form-item :label="L('Leaves from', 'Sale de')" style="flex: 1"><a-select v-model:value="edit.terminal" :options="terminalOptions(sel.side)" /></a-form-item>
              <a-form-item :label="L('Service', 'Servicio')" style="width: 150px"><a-select v-model:value="edit.tag" :options="tagOptions" allow-clear /></a-form-item>
            </div>
            <a-form-item :label="L('Note (why)', 'Nota (por qué)')"><a-input v-model:value="edit.note" :maxlength="250" /></a-form-item>
            <div class="pg-actions">
              <a-button v-if="sel.slot_id" type="primary" :loading="saving" @click="save('change')">{{ L('Save change', 'Guardar cambio') }}</a-button>
              <a-button :loading="saving" @click="reinforce">{{ L('Add reinforcement', 'Agregar refuerzo') }}</a-button>
              <a-popconfirm v-if="sel.slot_id && !sel.removed" :title="L('Remove this departure on this date?', '¿Quitar esta salida en esta fecha?')" @confirm="save('remove')">
                <a-button danger :loading="saving">{{ L('Remove for the day', 'Quitar del día') }}</a-button>
              </a-popconfirm>
              <a-button v-if="sel.change_id" :loading="saving" @click="undo(sel.change_id)">{{ L('Undo change', 'Deshacer cambio') }}</a-button>
            </div>
            <div v-if="!sel.slot_id && !sel.added" class="pg-hint">{{ L('Not in the plan: add it to the plan in Programación de buses to change it.', 'No está en el plan: agréguela en Programación de buses para cambiarla.') }}</div>
          </a-form>
        </div>

        <!-- The bar explained: the tickets sold by channel, as a table. -->
        <div class="pg-h">{{ L('Tickets sold by channel', 'Pasajes vendidos por canal') }}</div>
        <table v-if="channelRows.length" class="pg-stable">
          <thead><tr><th>{{ L('Channel', 'Canal') }}</th><th class="r">{{ L('Tickets', 'Pasajes') }}</th><th class="r">%</th><th class="r">{{ L('Amount', 'Monto') }}</th></tr></thead>
          <tbody>
            <tr v-for="c in channelRows" :key="c.key">
              <td><span class="dot" :style="{ background: c.color }" />{{ c.label }}
                <a-tooltip v-if="c.key === 'Agencia' && Object.keys(sel.sellers || {}).length" :title="Object.entries(sel.sellers).map(([k, v]) => `${k}: ${v}`).join(' · ')">
                  <InfoCircleOutlined class="pg-i" />
                </a-tooltip>
              </td>
              <td class="r">{{ c.n }}</td><td class="r">{{ pct(c.n, sel.sold) }}</td><td class="r">S/ {{ money(c.amount) }}</td>
            </tr>
          </tbody>
          <tfoot><tr><td>Total</td><td class="r">{{ sel.sold }}</td><td class="r">100 %</td><td class="r">S/ {{ money(sel.amount) }}</td></tr></tfoot>
        </table>
        <div v-else class="pg-muted">{{ L('Nothing sold yet in Jelaf.', 'Aún sin ventas en Jelaf.') }}</div>
        <div v-if="Object.keys(sel.by_point || {}).length" class="pg-muted"><b>{{ L('Boarding at', 'Embarcan en') }}:</b> {{ Object.entries(sel.by_point).map(([k, v]) => `${k} ${v}`).join(' · ') }}</div>

        <!-- The bus (the one being picked in the box, else the departure's), for a visual hint. -->
        <div class="pg-h">{{ L('The bus', 'El bus') }}</div>
        <div class="pg-photo">
          <img v-if="photoBus?.image" :src="photoBus.image" :alt="photoBus.name" />
          <div v-else class="pg-nophoto"><CarOutlined /><span>{{ photoBus ? L('No photo of this bus', 'Sin foto de este bus') : L('No bus assigned', 'Sin bus asignado') }}</span></div>
          <div v-if="photoBus" class="pg-photo-cap"><b>{{ photoBus.name }}</b> · {{ photoBus.plate }}<template v-if="photoBus.seats"> · {{ photoBus.seats }} {{ L('seats', 'asientos') }}</template><template v-if="photoBus.model"> · {{ photoBus.model }}</template></div>
        </div>

        <dl class="pg-dl pg-end">
          <dt>{{ L('Source', 'Origen') }}</dt>
          <dd>{{ sel.source === 'both' ? L('Plan + Jelaf', 'Plan + Jelaf') : sel.source === 'plan' ? L('Plan only (not open in Jelaf / nothing sold)', 'Solo plan (sin abrir en Jelaf / sin ventas)') : L('Jelaf only (not in the plan)', 'Solo Jelaf (no está en el plan)') }}
            <small v-if="sel.programacion"> · {{ L('programming', 'programación') }} {{ sel.programacion }}</small>
            <small> · {{ L('sales: Jelaf, Control de Embarque', 'ventas: Jelaf, Control de Embarque') }}</small></dd>
          <template v-if="sel.note"><dt>{{ L('Note', 'Nota') }}</dt><dd>{{ sel.note }}</dd></template>
        </dl>
      </template>
    </a-drawer>

    <!-- A new departure (reinforcement) on a date and side. -->
    <a-modal :open="!!adding" :title="adding ? `${L('New departure', 'Nueva salida')} · ${adding.day.label} · ${L(sideName(adding.side).en, sideName(adding.side).es)}` : ''"
      :confirm-loading="saving" :ok-text="L('Add', 'Agregar')" @ok="saveAdd" @cancel="adding = null">
      <a-form v-if="adding" layout="vertical" size="small">
        <div class="pg-row">
          <a-form-item :label="L('Time', 'Hora')" style="width: 100px"><a-input v-model:value="adding.time" placeholder="18:30" /></a-form-item>
          <a-form-item :label="L('Terminal', 'Terminal')" style="flex: 1"><a-select v-model:value="adding.terminal" :options="terminalOptions(adding.side)" /></a-form-item>
          <a-form-item :label="L('Service', 'Servicio')" style="width: 120px"><a-select v-model:value="adding.tag" :options="tagOptions" allow-clear /></a-form-item>
        </div>
        <a-form-item :label="L('Bus', 'Bus')"><a-select v-model:value="adding.vehicle_id" :options="busOptions" allow-clear show-search option-filter-prop="label" /></a-form-item>
        <a-form-item :label="L('Note (why)', 'Nota (por qué)')"><a-input v-model:value="adding.note" :maxlength="250" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { LeftOutlined, RightOutlined, FileExcelOutlined, PlusOutlined, WarningFilled, InfoCircleOutlined, CarOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { getActingCompanyId } from '../lib/actingCompany';
import { useFormat } from '../composables/useFormat';
import { useUiStore } from '../stores/ui';
import { sortByLabel } from '../lib/filterOptions';

const ui = useUiStore();
const es = computed(() => String(ui.locale || 'en').startsWith('es'));
const L = (e, s) => (es.value ? s : e);
const fmt = useFormat();
const SIDES = [{ key: 'lima', en: 'LIMA → CHICLAYO', es: 'LIMA → CHICLAYO' }, { key: 'chiclayo', en: 'CHICLAYO → LIMA', es: 'CHICLAYO → LIMA' }];
const sideName = k => SIDES.find(x => x.key === k) || SIDES[0];

const today = dayjs().format('YYYY-MM-DD');
const start = ref(today);
const data = ref({ days: [], summary: {}, vehicles: [], tags: [], terminals: {} });
const loading = ref(false);
const s = computed(() => data.value.summary || {});
const weekLabel = computed(() => (data.value.from ? `${dayjs(data.value.from).format('DD/MM')} – ${dayjs(data.value.to).format('DD/MM/YYYY')}` : ''));

async function load() {
  loading.value = true;
  try { data.value = await http.get('transport/programming', { start: start.value }); } catch (e) { message.error(e?.data?.message || L('Could not load.', 'No se pudo cargar.')); }
  loading.value = false;
}
function go(d) { start.value = d; load(); }
function move(n) { go(dayjs(data.value.from || start.value).add(n, 'day').format('YYYY-MM-DD')); }
onMounted(load);

const ampm = t => (t ? dayjs(`2000-01-01 ${t}`).format('h:mm A') : '');
const busNo = b => String(b).replace(/^Bus\s+/i, '');
const pct = (a, b) => (b ? `${Math.round((a / b) * 100)} %` : '—');
const w = (a, b) => (b ? `${Math.min(100, Math.round((a / b) * 100))}%` : '0%');
const money = v => Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
// Colour = the share of the seats: sold for a past day / today, the forecast for one to come (the user, 2026-11-26):
// under 80 % red, 80-89 % green, 90 % or more orange (reinforce); a bus clash marks the time red.
// Today and tomorrow by what is sold; from the day after tomorrow by the forecast (the user's choice, 2026-11-26).
const bySold = d => d.past || (d.days_left ?? 0) <= 1;
const occ = (x, d) => (x.seats ? (bySold(d) ? x.sold : x.forecast) / x.seats : null);
function cls(x, d) {
  const o = occ(x, d);
  const lvl = o === null ? 'st-na' : o >= (data.value.thresholds?.high ?? 0.9) ? 'st-hi' : o >= (data.value.thresholds?.red ?? 0.8) ? 'st-ok' : 'st-red';
  return x.alerts.length ? `${lvl} st-bad` : lvl;
}const canAdd = d => data.value.can_edit && !d.past;

const busOptions = computed(() => sortByLabel((data.value.vehicles || []).map(v => ({ value: v.id, label: `${v.name}${v.seats ? ` · ${v.seats} as.` : ''}${v.plate ? ` · ${v.plate}` : ''}` }))));
const tagOptions = computed(() => (data.value.tags || []).map(t => ({ value: t, label: t })));
const terminalOptions = side => (data.value.terminals?.[side] || []).map(t => ({ value: t, label: t }));

// The terminal columns of a side, as the Excel has them.
const tcols = side => data.value.terminal_cols?.[side] || [];
// A departure's time per column; one leaving from a terminal without a column shows its time in the first one.
const stopTime = (x, t, side) => x.stops?.[t]?.time || (t === tcols(side)[0] && !tcols(side).some(c => x.stops?.[c]?.time) ? x.time : null);
const cols = side => ({ gridTemplateColumns: `minmax(0, 1.25fr) repeat(${tcols(side).length}, minmax(0, 1fr))` });
const SHORT = { CAVASSA: 'CAV', 'PZ NORTE': 'PZN', OLIVOS: 'OLI', CIX: 'CIX', LAMBAYEQUE: 'LAMB' };
const short = t => SHORT[t] || t.charAt(0) + t.slice(1, 6).toLowerCase();
// "18:30" -> "6:30" (evening departures; a morning one keeps its "a").
const hm12 = t => { const [h, m] = t.split(':').map(Number); return `${h % 12 || 12}:${String(m).padStart(2, '0')}${h < 12 ? 'a' : ''}`; };

// ---- the buses
const busSort = ref('most');
const busSortOptions = computed(() => [{ value: 'most', label: L('Most used', 'Más usados') }, { value: 'least', label: L('Least used', 'Menos usados') },
  { value: 'where', label: L('By place', 'Por lugar') }, { value: 'status', label: L('Status', 'Estado') }]);
const buses = computed(() => {
  const list = [...(data.value.buses || [])];
  const by = { most: (a, b) => b.trips - a.trips, least: (a, b) => a.trips - b.trips, where: (a, b) => String(a.where).localeCompare(String(b.where)) || b.trips - a.trips,
    status: (a, b) => Number(a.ok) - Number(b.ok) || b.alerts.length - a.alerts.length };
  return list.sort(by[busSort.value]);
});
// Sales channels of the open departure, the agency first.
const CHANNELS = { Agencia: ['Agency', 'Agencia', '#152d4a'], Web: ['Web', 'Web', '#f28c13'], RedBus: ['RedBus', 'RedBus', '#d92d20'],
  PeruBus: ['PeruBus', 'PeruBus', '#7a5af8'], Kupos: ['Kupos Peru', 'Kupos Perú', '#0ba5ec'], Kiosco: ['Kiosk', 'Kiosco', '#2e9e5b'], Carritoc: ['Carritoc', 'Carritoc', '#98a2b3'] };
const channelRows = computed(() => Object.entries(sel.value?.channels || {})
  .map(([key, v]) => ({ key, n: v.n, amount: v.amount, label: L((CHANNELS[key] || [key, key])[0], (CHANNELS[key] || [key, key])[1]), color: (CHANNELS[key] || [0, 0, '#98a2b3'])[2] }))
  .sort((a, b) => (a.key === 'Agencia' ? -1 : b.key === 'Agencia' ? 1 : b.n - a.n)));

// The bus shown in the panel: the one being picked, else the departure's.
const photoBus = computed(() => (data.value.vehicles || []).find(v => v.id === (edit.vehicle_id || sel.value?.vehicle_id)) || null);

const chName = k => L((CHANNELS[k] || [k, k])[0], (CHANNELS[k] || [k, k])[1]);

// ---- one departure
const sel = ref(null);
const selDay = ref(null);
const edit = reactive({ vehicle_id: null, time: '', tag: null, terminal: null, note: '' });
const saving = ref(false);
function open(x, d) {
  sel.value = x; selDay.value = d;
  Object.assign(edit, { vehicle_id: x.vehicle_id || null, time: x.time || '', tag: x.tag || null, terminal: x.terminal || null, note: x.note || '' });
}
async function post(body) {
  saving.value = true;
  try { await http.post('transport/programming/changes', body); message.success(L('Saved.', 'Guardado.')); sel.value = null; adding.value = null; await load(); } catch (e) { message.error(e?.data?.message || L('Could not save.', 'No se pudo guardar.')); }
  saving.value = false;
}
function save(action) {
  post({ day: selDay.value.date, action, slot_id: sel.value.slot_id, side: sel.value.side, vehicle_id: edit.vehicle_id, time: edit.time, tag: edit.tag, terminal: edit.terminal, note: edit.note });
}
function reinforce() {
  // A reinforcement next to this departure: same side, terminal and service, a quarter of an hour later.
  const t = dayjs(`2000-01-01 ${sel.value.time}`).add(15, 'minute').format('HH:mm');
  adding.value = { day: selDay.value, side: sel.value.side, time: t, terminal: sel.value.terminal || terminalOptions(sel.value.side)[0]?.value, tag: sel.value.tag, vehicle_id: null,
    note: L(`Reinforcement of the ${ampm(sel.value.time)}`, `Refuerzo de la de ${ampm(sel.value.time)}`) };
  sel.value = null;
}
async function undo(id) {
  saving.value = true;
  try { await http.delete(`transport/programming/changes/${id}`); message.success(L('Undone.', 'Deshecho.')); sel.value = null; await load(); } catch (e) { message.error(e?.data?.message || 'Error'); }
  saving.value = false;
}

// ---- new departure
const adding = ref(null);
function openAdd(d, side) {
  adding.value = { day: d, side, time: '', terminal: terminalOptions(side)[0]?.value, tag: null, vehicle_id: null, note: '' };
}
function saveAdd() {
  const a = adding.value;
  post({ day: a.day.date, action: 'add', side: a.side, terminal: a.terminal, time: a.time, tag: a.tag, vehicle_id: a.vehicle_id, note: a.note });
}

const exporting = ref(false);
async function exportWeek() {
  exporting.value = true;
  try {
    const cid = getActingCompanyId();
    const q = new URLSearchParams({ start: data.value.from || start.value, ...(cid ? { company_id: cid } : {}) });
    await http.download(`transport/programming/export?${q}`, `Programacion ${dayjs(data.value.from).format('DD-MM-YYYY')}.xlsx`);
  } catch (e) { message.error(L('The file could not be made.', 'No se pudo generar el archivo.')); }
  exporting.value = false;
}
</script>

<style scoped>
/* Navy and orange, the board bar's palette (2026-11-26). */
.pg { --navy: #152d4a; --navy2: #27507d; --orange: #f28c13; --orange2: #f9a83a; --line: #e3e8ef; }
.pg-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
.pg-nav { display: flex; align-items: center; gap: 6px; }
.pg-week { color: var(--navy); font-size: 14px; min-width: 140px; text-align: center; }
.pg-chips { display: flex; gap: 5px; flex-wrap: wrap; flex: 1; justify-content: center; min-width: 0; }
.pg-chip { font-size: 11.5px; padding: 2px 7px; border-radius: 999px; background: #f2f5f9; color: #475467; white-space: nowrap; }
.pg-chip b { font-size: 13px; }
.pg-chip.c-hi { background: #fff1e0; color: #b45f06; }
.pg-chip.c-lo { background: #eaf0f7; color: #344e6f; }
.pg-chip.c-bad { background: #fde8e8; color: #b42318; }
.pg-chip.c-ok { background: #e8f6ee; color: #1f7a45; }
.pg-tools { display: flex; align-items: center; gap: 8px; }
.pg-at { font-size: 11.5px; color: #667085; }

/* Light layout (2026-11-26, "muy cargada"): no boxes nor side lines -- day heads, two route bands, rows split by a hairline. */
.pg-wrap { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: #fff; }
.pg-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
.pg-heads { background: linear-gradient(100deg, var(--navy), var(--navy2)); }
.pg-dh { color: #fff; padding: 6px 10px 7px; border-left: 1px solid rgba(255, 255, 255, .12); }
.pg-dh:first-child { border-left: none; }
.pg-dh.today { background: linear-gradient(180deg, var(--orange2), var(--orange)); }
.pg-dh.past { opacity: .75; }
.pg-dname { font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 6px; }
.pg-tag-today { font-size: 10px; text-transform: uppercase; background: rgba(255, 255, 255, .25); border-radius: 4px; padding: 0 4px; }
.pg-dsub { font-size: 11px; opacity: .9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pg-band { display: flex; align-items: center; gap: 10px; padding: 5px 12px; background: #f6f8fb; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.pg-band span { font-size: 12px; font-weight: 800; letter-spacing: .12em; color: var(--navy); }
.pg-band::after { content: ''; flex: 1; height: 2px; background: linear-gradient(90deg, var(--orange), transparent); border-radius: 2px; }
.pg-lists { max-height: calc(50vh - 120px); min-height: 160px; overflow-y: auto; }
.pg-col { border-left: 1px solid #f0f2f5; padding: 2px 0 4px; min-width: 0; }
.pg-col:first-child { border-left: none; }
.pg-col.today { background: #fffaf2; }
.pg-col.past { opacity: .72; }
.pg-row-d { padding: 4px 9px 5px; cursor: pointer; border-bottom: 1px solid #f2f4f7; transition: background-color .15s; }
.pg-row-d:hover { background: #eef3fa; }
.pg-row-d.planonly .pg-time { color: #98a2b3; }
.pg-row-d.removed { opacity: .45; text-decoration: line-through; }
.pg-l1 { display: flex; align-items: center; gap: 5px; font-size: 11.5px; white-space: nowrap; overflow: hidden; }
.pg-time { color: var(--navy); font-size: 12px; }
.pg-bus { font-weight: 600; color: #344054; }
.pg-bus.tentative { font-weight: 500; color: #8a94a6; font-style: italic; }
.pg-bus.none { color: #c0c6d0; }
.pg-svc { font-size: 9.5px; color: #7b8798; letter-spacing: .03em; overflow: hidden; text-overflow: ellipsis; }
.pg-warn { color: #d92d20; font-size: 11px; }
.pg-flag { font-size: 9.5px; background: var(--orange); color: #fff; border-radius: 3px; padding: 0 3px; }
.pg-l2 { display: flex; align-items: center; gap: 6px; margin-top: 2px; }
.pg-bar { position: relative; flex: 1; height: 4px; background: #eef1f5; border-radius: 2px; overflow: hidden; }
.pg-bar i { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 2px; }
.pg-bar .fc { background: #c9d5e4; }
.pg-bar .sd { background: var(--navy2); }
.pg-num { font-size: 11px; color: #667085; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pg-num small { color: #b0b8c4; }
.pg-num b { color: #344054; }
/* What to do, in colour only: orange = reinforce, slate = low, red = bus clash. */
.st-hi .pg-bar .fc { background: rgba(242, 140, 19, .45); }
.st-hi .pg-bar .sd { background: var(--orange); }
.st-hi .pg-num b { color: #c46a06; }
.st-lo .pg-bar .sd { background: #9fb0c6; }
.st-lo .pg-num b { color: #7b8ba3; }
.st-bad .pg-time { color: #d92d20; }
.pg-add { display: block; padding: 3px 9px; font-size: 11px; color: #98a2b3; }
.pg-add:hover { color: var(--orange); }
/* The day's grid like the Excel: service / bus, then a column per terminal. */
.pg-tr { display: grid; align-items: center; column-gap: 2px; }
.pg-th { padding: 3px 9px 2px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #98a2b3; border-bottom: 1px solid #eef1f5; }
.pg-th span { text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pg-who { display: flex; flex-direction: column; line-height: 1.1; min-width: 0; }
.pg-who b { font-size: 11.5px; color: #344054; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pg-who b.tentative { font-weight: 500; color: #8a94a6; font-style: italic; }
.pg-who b.none { color: #c0c6d0; }
.pg-who small { font-size: 9px; color: #8a94a6; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pg-t { text-align: center; font-size: 11.5px; color: transparent; font-variant-numeric: tabular-nums; }
.pg-t.on { color: var(--navy); font-weight: 600; }
.st-bad .pg-t.on { color: #d92d20; }
.pg-row-d.planonly .pg-t.on { color: #98a2b3; }
.pg-prices { border-top: 1px solid var(--line); background: #fbfcfe; }
.pg-prices .pg-col { padding: 4px 9px 5px; }
.pg-price { font-size: 10.5px; color: #475467; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pg-price b { color: var(--navy); }
.pg-price span + span { margin-left: 4px; }
.pg-price.muted { color: #b0b8c4; }
.pg-price.paid { color: #c46a06; }
.pg-buses { margin-top: 10px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: #fff; }
.pg-band-b { border-top: none; }
.pg-bsort { margin-left: auto; order: 3; }
.pg-band-b::after { order: 2; }
.pg-btable { width: 100%; border-collapse: collapse; font-size: 12px; }
.pg-btable th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: #98a2b3; padding: 5px 10px; border-bottom: 1px solid var(--line); font-weight: 700; }
.pg-btable td { padding: 4px 10px; border-bottom: 1px solid #f2f4f7; color: #344054; }
.pg-btable td small { color: #98a2b3; }
.pg-btable .r { text-align: right; font-variant-numeric: tabular-nums; }
.pg-btable tr.bad td { background: #fff8f7; }
.pg-btable tr:hover td { background: #f5f8fc; }
.pg-occ { display: flex; align-items: center; gap: 6px; max-width: 160px; }
.pg-occ span { font-size: 11px; color: #667085; min-width: 34px; }
.pg-ok { font-size: 11px; color: #1f7a45; }
.pg-al { font-size: 10.5px; border-radius: 4px; padding: 0 5px; margin-right: 4px; }
.pg-al.bad { background: #fde8e8; color: #b42318; }
.pg-al.warn { background: #fff1e0; color: #b45f06; }
/* Bar colours by the share of the seats. */
.st-ok .pg-bar .sd { background: #2e9e5b; }
.st-ok .pg-bar .fc { background: rgba(46, 158, 91, .3); }
.st-ok .pg-num b { color: #1f7a45; }
.st-red .pg-bar .sd { background: #d92d20; }
.st-red .pg-bar .fc { background: rgba(217, 45, 32, .25); }
.st-red .pg-num b { color: #b42318; }
.pg-ch { margin: 6px 0 10px; }
.pg-chbar { display: flex; height: 10px; border-radius: 5px; overflow: hidden; background: #eef1f5; margin-bottom: 6px; }
.pg-chbar i { display: block; height: 100%; }
.pg-chrow { display: grid; grid-template-columns: 12px 1fr 50px 80px; gap: 6px; align-items: center; font-size: 12.5px; padding: 2px 0; }
.pg-chrow .dot { width: 10px; height: 10px; border-radius: 3px; }
.pg-chrow .r { text-align: right; font-variant-numeric: tabular-nums; }
.pg-sellers { font-size: 11.5px; color: #667085; margin: 2px 0 0 18px; }
.pg-h { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #98a2b3; margin: 10px 0 4px; }
/* The departure panel (2026-11-26): roomier, programming in a bordered box, sales as a table, the bus's photo. */
.pg-dl { grid-template-columns: 120px 1fr !important; gap: 6px 12px !important; font-size: 13.5px !important; margin-bottom: 14px !important; }
.pg-box { border: 1px solid #d0d9e6; border-radius: 10px; padding: 12px 14px 10px; margin: 4px 0 16px; background: #fbfcfe; }
.pg-box-t { font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--navy); margin-bottom: 8px; }
.pg-box :deep(.ant-form-item) { margin-bottom: 10px; }
.pg-stable { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 6px; }
.pg-stable th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #98a2b3; padding: 5px 8px; border-bottom: 1px solid var(--line); }
.pg-stable td { padding: 6px 8px; border-bottom: 1px solid #f2f4f7; }
.pg-stable .r { text-align: right; font-variant-numeric: tabular-nums; }
.pg-stable tfoot td { font-weight: 700; border-bottom: none; border-top: 1px solid var(--line); }
.pg-stable .dot { display: inline-block; width: 9px; height: 9px; border-radius: 3px; margin-right: 7px; }
.pg-i { color: #98a2b3; margin-left: 5px; cursor: help; }
.pg-muted { font-size: 12px; color: #667085; margin-bottom: 6px; }
.pg-photo { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; margin-bottom: 14px; }
.pg-photo img { display: block; width: 100%; max-height: 240px; object-fit: cover; background: #f2f5f9; }
.pg-nophoto { height: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; color: #b0b8c4; background: #f6f8fb; font-size: 12px; }
.pg-nophoto :deep(.anticon) { font-size: 32px; }
.pg-photo-cap { padding: 6px 10px; font-size: 12.5px; color: #475467; border-top: 1px solid var(--line); }
.pg-end { margin-top: 4px; padding-top: 10px; border-top: 1px dashed var(--line); font-size: 12.5px !important; }
.pg-legend-i { color: #27507d; font-size: 12px; cursor: help; margin-left: 4px; white-space: nowrap; }
.pg-legend p { margin: 0 0 6px; font-size: 12.5px; color: #344054; }
.pg-legend h4 { margin: 8px 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #f28c13; }
.pg-legend h4:first-child { margin-top: 0; }
.pg-fc { margin-top: 4px; padding: 6px 8px; background: #f6f8fb; border-radius: 6px; font-size: 12.5px; }
.pg-fc small { display: block; color: #667085; margin-top: 2px; }
.pg-legend div { display: flex; align-items: center; gap: 7px; font-size: 12.5px; line-height: 21px; color: #344054; }
.pg-legend i { display: inline-block; width: 12px; height: 8px; border-radius: 2px; }
.pg-hov { font-size: 12.5px; color: #344054; }
.pg-hov-r { display: flex; justify-content: space-between; gap: 12px; }
.pg-hov-r span { color: #667085; }
.pg-hov-ch { font-size: 11.5px; color: #667085; margin: 0 0 6px; }
.pg-hov-ex { margin: 4px 0; padding: 6px 8px; background: #f6f8fb; border-radius: 6px; }
.pg-hov-ex small { display: block; color: #667085; margin-top: 2px; }
.pg-hov-c { font-size: 11px; color: #98a2b3; margin-top: 4px; }
.pg-sug { border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; font-size: 13px; }
.pg-sug.reinforce { background: #fff1e0; color: #7a3e00; }
.pg-sug.low { background: #eaf0f7; color: #233b57; }
.pg-dl { display: grid; grid-template-columns: 110px 1fr; gap: 4px 10px; margin: 0; font-size: 13px; }
.pg-dl dt { color: #667085; }
.pg-dl dd { margin: 0; }
.pg-row { display: flex; gap: 8px; }
.pg-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.pg-hint { margin-top: 8px; font-size: 12px; color: #667085; }
@media (max-width: 900px) { .pg-grid { grid-template-columns: 1fr; } .pg-lists { max-height: none; } }
</style>
