/**
 * Small markdown -> safe HTML (the chat's own format: paragraphs, **bold**, `code`, lists, tables, headings, ---).
 * HTML-escaped first, then DOMPurify. Shared by KT-Log and the task activity log (2026-11-22).
 */
import DOMPurify from 'dompurify';

const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const inline = s => esc(s)
  .replace(/`([^`]+)`/g, '<code>$1</code>')
  .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
export function markdown(src) {
  const out = [];
  const stack = []; // open lists: { indent, tag }
  const closeTo = (indent) => {
    while (stack.length && stack[stack.length - 1].indent >= indent) out.push(`</li></${stack.pop().tag}>`);
  };
  let para = [];
  let table = [];
  const flush = () => { if (para.length) { out.push(`<p>${para.map(inline).join('<br>')}</p>`); para = []; } };
  // Tables (| a | b | with a |---| line) and headings (#, ##, ###) -- the chat's own format (2026-11-15).
  const cells = l => l.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(x => x.trim());
  const flushTable = () => {
    if (!table.length) return;
    const rows = table.filter(l => !/^[\s|:-]+$/.test(l));  // drop the |---|---| line
    const [head, ...body] = rows;
    out.push('<table class="md-table"><thead><tr>' + cells(head).map(c => `<th>${inline(c)}</th>`).join('') + '</tr></thead><tbody>'
      + body.map(r => '<tr>' + cells(r).map(c => `<td>${inline(c)}</td>`).join('') + '</tr>').join('') + '</tbody></table>');
    table = [];
  };
  for (const raw of String(src).replace(/\r/g, '').split('\n')) {
    if (/^\s*\|.*\|\s*$/.test(raw)) {
      flush();
      closeTo(0);
      table.push(raw);
      continue;
    }
    flushTable();
    const h = raw.match(/^\s*(#{1,4})\s+(.*)$/);
    if (h) {
      flush();
      closeTo(0);
      out.push(`<h5 class="md-h md-h${h[1].length}">${inline(h[2])}</h5>`);
      continue;
    }
    if (/^\s*(---|\*\*\*)\s*$/.test(raw)) {
      flush();
      closeTo(0);
      out.push('<hr>');
      continue;
    }
    const m = raw.match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
    if (m) {
      flush();
      const indent = m[1].length;
      const tag = /\d/.test(m[2]) ? 'ol' : 'ul';
      const top = stack[stack.length - 1];
      if (!top || indent > top.indent) {
        out.push(`<${tag}><li>`);
        stack.push({ indent, tag });
      } else {
        closeTo(indent + 1);
        const cur = stack[stack.length - 1];
        if (cur && cur.indent === indent && cur.tag === tag) out.push('</li><li>');
        else { closeTo(indent); out.push(`<${tag}><li>`); stack.push({ indent, tag }); }
      }
      out.push(inline(m[3]));
    } else if (!raw.trim()) {
      flush();
      closeTo(0);
    } else if (stack.length && /^\s+/.test(raw)) {
      out.push(`<br>${inline(raw.trim())}`); // a continuation line inside a list item
    } else {
      closeTo(0);
      para.push(raw.trim());
    }
  }
  flushTable();
  flush();
  closeTo(0);
  return DOMPurify.sanitize(out.join(''));
}
