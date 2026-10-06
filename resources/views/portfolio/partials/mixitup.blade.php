{{-- MixItUp on every gallery of the page; each one only listens to its own filter buttons. --}}
<script src="{{ asset('portfolio-theme/js/mixitup.min.js') }}"></script>
<script>
  (function () {
    if (!window.mixitup) return;
    // ?filter=<category> (a menu item "Category: ...") opens the gallery on that category.
    var wanted = new URLSearchParams(location.search).get('filter');
    document.querySelectorAll('.mix-container').forEach(function (el) {
      var start = wanted && /^[a-z0-9-]+$/.test(wanted) && document.querySelector('[data-for="' + el.id + '"] [data-filter=".cat-' + wanted + '"]') ? '.cat-' + wanted : 'all';
      mixitup(el, {
        selectors: { control: '[data-for="' + el.id + '"] .control' },
        animation: { effects: 'fade scale stagger(50ms)' },
        load: { filter: start }
      });
    });
  })();
</script>
