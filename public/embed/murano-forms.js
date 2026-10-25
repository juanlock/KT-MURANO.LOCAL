/*!
 * Murano Forms -- embed script (Forms module, 2026-10-24).
 * Put on any website:
 *   <div data-murano-form="KEY"></div>
 *   <script src="https://YOUR-MURANO/embed/murano-forms.js" async></script>
 * It replaces each placeholder with an iframe of /f/KEY/embed and keeps the
 * iframe as tall as the form (the form page posts its height).
 * Popup: any element with data-murano-popup="KEY" (a button, a link) opens the
 * form in a window over the page (Esc / the X / a click outside closes it).
 */
(function () {
    'use strict';
    var script = document.currentScript || (function () {
        var s = document.getElementsByTagName('script');
        for (var i = s.length - 1; i >= 0; i--) if (/murano-forms\.js/.test(s[i].src)) return s[i];
        return null;
    })();
    var base = script ? script.src.replace(/\/embed\/murano-forms\.js.*$/, '') : '';
    var frames = {};

    function mount(el) {
        if (el.getAttribute('data-murano-mounted')) return;
        var key = (el.getAttribute('data-murano-form') || '').replace(/[^a-z0-9]/gi, '');
        if (!key) return;
        el.setAttribute('data-murano-mounted', '1');
        var src = (el.getAttribute('data-base') || base) + '/f/' + key + '/embed?page=' + encodeURIComponent(location.href);
        var f = document.createElement('iframe');
        f.src = src;
        f.title = el.getAttribute('data-title') || 'Form';
        f.loading = 'lazy';
        f.setAttribute('scrolling', 'no');
        f.style.cssText = 'width:100%;border:0;overflow:hidden;display:block;min-height:' + (el.getAttribute('data-height') || '300') + 'px';
        el.innerHTML = '';
        el.appendChild(f);
        (frames[key] = frames[key] || []).push(f);
    }

    function mountAll() {
        var els = document.querySelectorAll('[data-murano-form]');
        for (var i = 0; i < els.length; i++) mount(els[i]);
        var pops = document.querySelectorAll('[data-murano-popup]');
        for (var j = 0; j < pops.length; j++) bindPopup(pops[j]);
    }

    // ---- popup window
    var overlay = null;
    function closePopup() {
        if (!overlay) return;
        var key = overlay.getAttribute('data-key');
        frames[key] = (frames[key] || []).filter(function (f) { return !overlay.contains(f); });
        document.body.removeChild(overlay);
        document.body.style.overflow = overlay.getAttribute('data-overflow') || '';
        overlay = null;
        document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') closePopup(); }
    function openPopup(key, base2, title) {
        closePopup();
        overlay = document.createElement('div');
        overlay.setAttribute('data-key', key);
        overlay.setAttribute('data-overflow', document.body.style.overflow || '');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.55);display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:5vh 12px';
        var box = document.createElement('div');
        box.style.cssText = 'position:relative;background:#fff;border-radius:12px;width:100%;max-width:720px;box-shadow:0 20px 60px rgba(0,0,0,.3);padding:8px 8px 4px';
        var x = document.createElement('button');
        x.type = 'button';
        x.setAttribute('aria-label', 'Close');
        x.innerHTML = '&times;';
        x.style.cssText = 'position:absolute;top:4px;right:8px;border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer;color:#555;z-index:1';
        x.onclick = closePopup;
        var f = document.createElement('iframe');
        f.src = base2 + '/f/' + key + '/embed?page=' + encodeURIComponent(location.href);
        f.title = title || 'Form';
        f.setAttribute('scrolling', 'no');
        f.style.cssText = 'width:100%;border:0;display:block;min-height:320px';
        box.appendChild(x);
        box.appendChild(f);
        overlay.appendChild(box);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closePopup(); });
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKey);
        (frames[key] = frames[key] || []).push(f);
    }
    function bindPopup(el) {
        if (el.getAttribute('data-murano-bound')) return;
        el.setAttribute('data-murano-bound', '1');
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var key = (el.getAttribute('data-murano-popup') || '').replace(/[^a-z0-9]/gi, '');
            if (key) openPopup(key, el.getAttribute('data-base') || base, el.getAttribute('data-title'));
        });
    }

    window.addEventListener('message', function (e) {
        var d = e.data;
        if (!d || d.type !== 'murano-form' || !frames[d.key]) return;
        frames[d.key].forEach(function (f) {
            if (f.contentWindow !== e.source) return;
            if (d.height) { f.style.height = d.height + 'px'; f.style.minHeight = '0'; }
            if (d.submitted && !(overlay && overlay.contains(f))) {
                var top = f.getBoundingClientRect().top + window.pageYOffset - 20;
                if (window.pageYOffset > top) window.scrollTo({ top: top, behavior: 'smooth' });
            }
            if (d.redirect && /^https?:\/\//i.test(d.redirect)) window.location.href = d.redirect;
        });
    });

    window.MuranoForms = { mount: mountAll, open: function (key) { openPopup(String(key).replace(/[^a-z0-9]/gi, ''), base); }, close: closePopup };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll); else mountAll();
})();
