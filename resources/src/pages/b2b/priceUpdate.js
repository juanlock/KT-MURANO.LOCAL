/**
 * "¿Actualizar el precio para las próximas facturas?" (user, 2026-11-23, Hordex): after an invoice is saved -- from Guías
 * (Emitir factura) or Facturas -- the lines whose unit price differs from the product's sheet price are listed, each with a
 * checkbox (all ticked), "Producto X cambió de yyy a xxx"; the ticked ones become the product's price
 * (POST b2b/products/prices), so the next invoice proposes it. Nothing is asked when no price changed.
 */
import { h, reactive } from 'vue';
import { Modal, Checkbox, message } from 'ant-design-vue';
import http from '../../lib/http';

const money2 = v => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * lines: [{ product_id, price }], products: the b2b/options products ([{ id, code, name, price }]).
 * Resolves true when prices were updated (the caller reloads its options).
 */
export function askPriceUpdate(lines, products) {
  const byId = new Map((products || []).map(p => [p.id, p]));
  const changes = new Map();
  (lines || []).forEach((l) => {
    const p = byId.get(l.product_id);
    if (!p || l.price === null || l.price === undefined || l.price === '') return;
    const now = Math.round(Number(l.price) * 100) / 100;
    const was = Math.round(Number(p.price || 0) * 100) / 100;
    if (now !== was) changes.set(p.id, { id: p.id, label: `${p.code ? p.code + ' - ' : ''}${p.name}`, was, now, on: true });
  });
  if (!changes.size) return Promise.resolve(false);
  const rows = reactive([...changes.values()]);
  const one = rows.length === 1;

  return new Promise((resolve) => {
    Modal.confirm({
      title: one ? 'El precio del producto cambió' : `Cambiaron los precios de ${rows.length} productos`,
      width: 560,
      okText: 'Actualizar precio',
      cancelText: 'No, dejarlo',
      content: () => h('div', [
        h('p', { style: 'margin-bottom:8px' }, one
          ? `${rows[0].label} cambió de ${money2(rows[0].was)} a ${money2(rows[0].now)}. ¿Desea actualizar el valor para las próximas facturas?`
          : '¿Desea actualizar el valor para las próximas facturas? Marque los productos:'),
        one ? null : h('div', { style: 'max-height:300px;overflow:auto' }, rows.map(r => h('div', { style: 'padding:3px 0;border-bottom:1px solid rgba(128,128,128,.15)' }, [
          h(Checkbox, { checked: r.on, 'onUpdate:checked': (v) => { r.on = v; } }, () => [
            h('b', r.label), ` cambió de ${money2(r.was)} a `, h('b', money2(r.now)),
          ]),
        ]))),
      ]),
      async onOk() {
        const items = rows.filter(r => r.on).map(r => ({ product_id: r.id, price: r.now }));
        if (!items.length) { resolve(false); return; }
        try {
          await http.post('b2b/products/prices', { items });
          message.success(items.length === 1 ? 'Precio actualizado' : `${items.length} precios actualizados`);
          resolve(true);
        } catch (e) {
          message.error(e?.data?.message || 'No se pudo actualizar el precio');
          resolve(false);
        }
      },
      onCancel() { resolve(false); },
    });
  });
}
