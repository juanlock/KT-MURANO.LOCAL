// Names of the complaint groups and of the topics "Otros reclamos" are sorted into (App\Services\TransportClaims,
// 2026-11-09). Shared by the Complaints and Parcels tabs. [English, Spanish].
export const CLAIM_GROUPS = {
  sales: ['Tickets, changes and refunds', 'Pasajes, cambios y devoluciones'],
  bus: ['The bus and its condition', 'El bus y su estado'],
  service: ['Staff attention', 'Atención del personal'],
  luggage: ['Luggage and parcels', 'Equipaje y encomiendas'],
  boarding: ['Boarding and getting off', 'Embarque y desembarque'],
  schedule: ['Times', 'Horarios'],
  safety: ['Safety on board', 'Seguridad a bordo'],
  other: ['Other', 'Otros'],
  unspecified: ['Not specified', 'Sin especificar'],
};

export const CLAIM_TOPICS = {
  test: ['Tests (not real)', 'Pruebas (no reales)'],
  none: ['No clear topic', 'Sin tema claro'],
  pests: ['Bugs on the seats (bedbugs, cockroaches)', 'Plagas en los asientos (chinches, cucarachas)'],
  seat: ['Seat broken / does not recline', 'Asiento malogrado / no reclina'],
  bus_change: ['Different bus from the one bought (Master → Confort)', 'Bus distinto al comprado (Master → Confort)'],
  breakdown: ['Bus broke down on the road', 'Bus malogrado en ruta'],
  clean: ['Toilet, cleaning, air conditioning', 'Baño, limpieza, aire acondicionado'],
  equipment: ['Lights, chargers, leaks', 'Luces, cargadores, filtraciones'],
  parcel: ['Parcel or luggage', 'Encomienda o equipaje'],
  invoice: ['Invoices not sent', 'Facturas no enviadas'],
  promo: ['Promotion (free trip) not given', 'Promoción (viaje gratis) no respetada'],
  money: ['Change / charges', 'Vuelto / cobros'],
  price: ['Price', 'Precio'],
  online: ['Online purchase failed', 'Compra por internet fallida'],
  refund: ['Date change, cancellation, refund', 'Cambio de fecha, cancelación, devolución'],
  missed: ['Bus left without them', 'El bus partió sin el pasajero'],
  delay: ['Late departure', 'Salida con retraso'],
  stop: ['Getting off / stop', 'Desembarque / paradero'],
  staff: ['Staff treatment', 'Trato del personal'],
  safety: ['Alcohol, fights, falls', 'Alcohol, peleas, caídas'],
};

/** A reason as the server sends it ("" / "topic:<key>" / the form's own option) -> its name on screen. */
export function claimDetailName(name, tr) {
  if (name === '') return tr('Not specified', 'Sin especificar');
  if (name.startsWith('topic:')) {
    const t = CLAIM_TOPICS[name.slice(6)];
    return `${t ? tr(...t) : name.slice(6)} ${tr('(other complaints)', '(otros reclamos)')}`;
  }
  return name;
}
