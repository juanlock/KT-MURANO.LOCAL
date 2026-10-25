/*!
 * Murano Forms -- embed script (Forms module, 2026-10-24).
 * Put on any website:
 *   <div data-murano-form="KEY"></div>
 *   <script src="https://YOUR-MURANO/embed/murano-forms.js" async></script>
 * It replaces each placeholder with an iframe of /f/KEY/embed and keeps the
 * iframe as tall as the form (the form page posts its height).
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
    }

    window.addEventListener('message', function (e) {
        var d = e.data;
        if (!d || d.type !== 'murano-form' || !frames[d.key]) return;
        frames[d.key].forEach(function (f) {
            if (f.contentWindow !== e.source) return;
            if (d.height) { f.style.height = d.height + 'px'; f.style.minHeight = '0'; }
            if (d.submitted) {
                var top = f.getBoundingClientRect().top + window.pageYOffset - 20;
                if (window.pageYOffset > top) window.scrollTo({ top: top, behavior: 'smooth' });
            }
            if (d.redirect && /^https?:\/\//i.test(d.redirect)) window.location.href = d.redirect;
        });
    });

    window.MuranoForms = { mount: mountAll };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountAll); else mountAll();
})();
