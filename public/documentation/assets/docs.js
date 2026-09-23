/* =========================================================================
   Stocky documentation — sidebar filter, current-section highlight, mobile
   drawer. No dependencies: this file is opened off a disk, not a CDN.
   ========================================================================= */
(function () {
    'use strict';

    var rail = document.querySelector('.doc__rail');
    var burger = document.querySelector('.doc__burger');
    var search = document.getElementById('doc-search');
    var links = Array.prototype.slice.call(document.querySelectorAll('.doc__nav a'));
    var groups = Array.prototype.slice.call(document.querySelectorAll('.doc__nav-group'));
    var empty = document.querySelector('.doc__nav-empty');

    /* --- Mobile drawer ---------------------------------------------------- */

    if (burger && rail) {
        burger.addEventListener('click', function () {
            rail.classList.toggle('is-open');
        });

        // Following a link on a phone should close the drawer behind you.
        rail.addEventListener('click', function (event) {
            if (event.target.closest('a')) rail.classList.remove('is-open');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') rail.classList.remove('is-open');
        });
    }

    /* --- Filter the sidebar ----------------------------------------------- */

    if (search) {
        search.addEventListener('input', function () {
            var term = search.value.trim().toLowerCase();
            var shown = 0;

            links.forEach(function (link) {
                var hit = !term || link.textContent.toLowerCase().indexOf(term) !== -1;
                link.hidden = !hit;
                if (hit) shown++;
            });

            // A group heading with nothing under it is noise.
            groups.forEach(function (group) {
                var visible = false;
                var node = group.nextElementSibling;

                while (node && !node.classList.contains('doc__nav-group')) {
                    if (node.tagName === 'A' && !node.hidden) visible = true;
                    node = node.nextElementSibling;
                }

                group.hidden = !visible;
            });

            if (empty) empty.hidden = shown !== 0;
        });

        // "/" focuses the filter, as long as you are not already typing.
        document.addEventListener('keydown', function (event) {
            if (event.key !== '/' || /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
                return;
            }

            event.preventDefault();
            search.focus();
            search.select();
        });
    }

    /* --- Highlight the section being read --------------------------------- */

    var sections = Array.prototype.slice.call(document.querySelectorAll('main h2[id]'));

    if (sections.length && 'IntersectionObserver' in window) {
        var byId = {};
        links.forEach(function (link) {
            byId[link.getAttribute('href')] = link;
        });

        var current = null;

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;

                    var link = byId['#' + entry.target.id];
                    if (!link || link === current) return;

                    if (current) current.classList.remove('is-current');
                    link.classList.add('is-current');
                    current = link;

                    // Keep the marker in view on a long contents list.
                    if (link.scrollIntoView) {
                        var box = link.getBoundingClientRect();
                        var railBox = rail.getBoundingClientRect();
                        if (box.top < railBox.top || box.bottom > railBox.bottom) {
                            link.scrollIntoView({ block: 'nearest' });
                        }
                    }
                });
            },
            // Fires as a heading crosses the upper third, which is where the
            // eye actually is when reading.
            { rootMargin: '0px 0px -66% 0px', threshold: 0 }
        );

        sections.forEach(function (section) {
            observer.observe(section);
        });
    }

    /* --- Screenshots load lazily; a broken one should not leave a hole ---- */

    Array.prototype.slice.call(document.querySelectorAll('.shot img')).forEach(function (img) {
        img.addEventListener('error', function () {
            var fig = img.closest('.shot');
            if (fig) fig.hidden = true;
        });
    });
})();
