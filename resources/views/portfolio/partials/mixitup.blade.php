{{-- MixItUp on every gallery of the page; each one only listens to its own filter buttons. --}}
<script src="{{ asset('portfolio-theme/js/mixitup.min.js') }}"></script>
<script>
  (function () {
    if (!window.mixitup) return;
    document.querySelectorAll('.mix-container').forEach(function (el) {
      mixitup(el, {
        selectors: { control: '[data-for="' + el.id + '"] .control' },
        animation: { effects: 'fade scale stagger(50ms)' }
      });
    });
  })();
</script>
