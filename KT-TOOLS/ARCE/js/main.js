(function () {
  'use strict';

  // ----- Header: solid after the hero, mobile menu -----
  var header = document.querySelector('.site-header');
  var toggle = document.querySelector('.menu-toggle');
  var menu = document.getElementById('menu');
  function onScroll() { header.classList.toggle('is-solid', window.scrollY > 40); }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  toggle.addEventListener('click', function () {
    var open = menu.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  menu.addEventListener('click', function (e) {
    if (e.target.tagName === 'A') { menu.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); }
  });

  // ----- WhatsApp: set the number here when the client confirms it (e.g. '51987654321') -----
  var WHATSAPP = '';
  if (WHATSAPP) {
    document.querySelectorAll('[data-whatsapp]').forEach(function (a) {
      a.href = 'https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent('Hola Dr. David Arce, quisiera información sobre sus servicios médicos.');
      a.target = '_blank';
      a.rel = 'noopener';
    });
  }

  // ----- Publicaciones -----
  var list = document.getElementById('posts');
  var empty = document.getElementById('posts-empty');
  // In the order of js/publicaciones.js (the client decides it there). Every post
  // shows its source link; one without a valid link is left out.
  var posts = (window.PUBLICACIONES || []).filter(function (p) { return /^https?:\/\//i.test(p.enlace || ''); });

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text) n.textContent = text;
    return n;
  }
  function safeUrl(u) { return /^https?:\/\//i.test(u || '') ? u : '#'; }
  // "facebook.com/chulucanasnoticias", "encuentroalsur.com"...
  function host(u) {
    try {
      var x = new URL(u);
      var h = x.hostname.replace(/^www\./, '');
      var first = x.pathname.split('/').filter(Boolean)[0];
      return /facebook\.com$/.test(h) && first && !/\.php$/.test(first) && first !== 'plugins' ? h + '/' + first : h;
    } catch (e) { return u; }
  }
  function fecha(f) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(f || '')) return f || '';
    var d = new Date(f + 'T12:00:00');
    return d.toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' });
  }

  function card(p) {
    var tipo = p.tipo === 'facebook' ? 'facebook' : 'web';
    var art = el('article', 'post' + (tipo === 'facebook' ? ' post-fb' : ''));
    art.dataset.tipo = tipo;
    var embed = p.insertar || p.enlace;
    if (tipo === 'facebook' && /facebook\.com/i.test(embed || '')) {
      var f = document.createElement('iframe');
      f.src = p.video
        ? 'https://www.facebook.com/plugins/video.php?width=560&show_text=false&href=' + encodeURIComponent(embed)
        : 'https://www.facebook.com/plugins/post.php?width=500&show_text=true&href=' + encodeURIComponent(embed);
      if (p.video) { f.className = 'post-video'; f.setAttribute('allowfullscreen', 'true'); f.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; web-share'); }
      f.loading = 'lazy';
      f.title = p.titulo || 'Publicación de Facebook';
      if (!p.video) f.setAttribute('allow', 'encrypted-media');
      art.appendChild(f);
    } else if (p.imagen) {
      var img = el('img', 'post-img');
      img.src = p.imagen; img.alt = p.titulo || ''; img.loading = 'lazy';
      art.appendChild(img);
    }
    var body = el('div', 'post-body');
    var meta = el('div', 'post-meta');
    meta.appendChild(el('span', 'post-source ' + tipo, tipo === 'facebook' ? 'Facebook' : 'Web'));
    if (p.fuente) meta.appendChild(el('span', '', p.fuente));
    if (p.fecha) meta.appendChild(el('span', '', '· ' + fecha(p.fecha)));
    body.appendChild(meta);
    body.appendChild(el('h3', '', p.titulo || ''));
    if (p.autor) body.appendChild(el('span', 'post-author', p.autor));
    if (p.resumen) body.appendChild(el('p', '', p.resumen));
    // The source, always visible: its site and the link to the original.
    var origin = el('p', 'post-origin');
    origin.appendChild(document.createTextNode('Origen: '));
    var o = el('a', '', host(p.enlace));
    o.href = safeUrl(p.enlace); o.target = '_blank'; o.rel = 'noopener'; o.title = p.enlace;
    origin.appendChild(o);
    body.appendChild(origin);
    var a = el('a', 'post-link', 'Ver publicación original →');
    a.href = safeUrl(p.enlace); a.target = '_blank'; a.rel = 'noopener';
    body.appendChild(a);
    art.appendChild(body);
    return art;
  }

  posts.forEach(function (p) { list.appendChild(card(p)); });

  function filter(kind) {
    var shown = 0;
    list.querySelectorAll('.post').forEach(function (n) {
      var on = kind === 'all' || n.dataset.tipo === kind;
      n.hidden = !on;
      if (on) shown++;
    });
    empty.hidden = shown > 0;
  }
  document.querySelectorAll('.chip').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.chip').forEach(function (x) { x.classList.remove('is-active'); x.setAttribute('aria-selected', 'false'); });
      b.classList.add('is-active'); b.setAttribute('aria-selected', 'true');
      filter(b.dataset.filter);
    });
  });
  filter('all');

  // ----- Reveal on scroll -----
  var targets = document.querySelectorAll('.fact, .feature, .gallery figure, .card, .note, .doctor, .post, .contact > div');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
    }, { threshold: .12 });
    targets.forEach(function (t) { t.classList.add('reveal'); io.observe(t); });
  }

  document.getElementById('year').textContent = new Date().getFullYear();
})();
