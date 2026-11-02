// UserPromptSubmit hook (user's rule, 2026-11-01): on every message, remind
// Claude of the standing conventions so they are never forgotten mid-session.
const reminder = [
  'CONVENCIONES (recordatorio automático):',
  '1. Responde SIEMPRE en el idioma del ÚLTIMO mensaje del usuario: español -> todo en español, incluido el resumen final y los textos entre herramientas; inglés -> inglés.',
  '2. Registra cada pedido terminado en kt_logs (producción) de inmediato.',
  '3. Multicompany: SuperAdmin elige compañía; un Admin solo ve la suya.',
  '4. Nunca escribir datos de compañías en producción (is_live) desde dev; deploy solo con el sí del usuario (salvo Pa Llevar).',
].join('\n');

process.stdout.write(JSON.stringify({
  hookSpecificOutput: { hookEventName: 'UserPromptSubmit', additionalContext: reminder },
}));
