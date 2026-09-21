{{-- Product size guide modal. Opened by #pdpSizeGuideBtn on the product page. --}}
@if(isset($p) && $p->sizeGuide && $p->sizeGuide->status && $p->sizeGuide->hasChart())
@php
  $sg = $p->sizeGuide;
  $sgColumns = $sg->chart('columns');
  $sgRows = $sg->chart('rows');
  $sgHasTable = $sg->hasTable();
  // A guide is very often just an uploaded chart image with no typed matrix —
  // that image IS the size chart, so show it full width rather than declaring
  // there is no chart while a thumbnail sits in the header.
  $sgHasImage = (bool) $sg->image;
@endphp

<div id="size-guide-modal" class="sg-root hidden" role="dialog" aria-modal="true" aria-label="{{ __('messages.SizeGuide') }}">
  <div class="sg-overlay" data-sg-close></div>
  <div class="sg-card">
    <button type="button" class="sg-close" data-sg-close aria-label="{{ __('messages.Close') }}">&times;</button>
    <div class="sg-head">
      {{-- Only a decorative thumbnail when the table is the chart; otherwise
           the image is shown full width in the body below. --}}
      @if($sgHasImage && $sgHasTable)
        <img class="sg-thumb" src="{{ url('/images/size_guides/'.$sg->image) }}" alt="{{ $sg->name }}">
      @endif
      <h3 class="sg-title">{{ $sg->name }}</h3>
    </div>
    <div class="sg-body">
      @if($sgHasImage && ! $sgHasTable)
        <img class="sg-chart" src="{{ url('/images/size_guides/'.$sg->image) }}" alt="{{ $sg->name }}">
      @endif

      @if($sgHasTable)
        <div class="sg-table-wrap">
          <table class="sg-table">
            <thead>
              <tr>
                @foreach($sgColumns as $col)
                  <th>{{ $col }}</th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @foreach($sgRows as $row)
                <tr>
                  @foreach($sgColumns as $ci => $col)
                    <td>{{ $row[$ci] ?? '' }}</td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  </div>
</div>

<style>
  .sg-root { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; }
  .sg-root.hidden { display: none; }
  .sg-overlay { position: absolute; inset: 0; background: rgba(0,0,0,.5); }
  .sg-card { position: relative; z-index: 1; width: min(680px, 92vw); max-height: 88vh; overflow: auto;
             background: rgb(var(--color-bg-surface)); color: rgb(var(--color-fg-primary));
             border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
  .sg-close { position: absolute; top: 10px; right: 14px; background: none; border: 0; font-size: 26px; line-height: 1;
              cursor: pointer; color: rgb(var(--color-fg-secondary)); }
  .sg-head { display: flex; align-items: center; gap: 14px; padding: 18px 22px; border-bottom: 1px solid rgb(var(--color-line-subtle)); }
  .sg-thumb { width: 56px; height: 56px; object-fit: contain; border-radius: 8px; background: rgb(var(--color-bg-muted)); }
  .sg-title { font-size: 1.15rem; font-weight: 700; margin: 0; }
  .sg-body { padding: 18px 22px; }
  .sg-chart { display: block; width: 100%; height: auto; max-height: 62vh; object-fit: contain; border-radius: 8px; }
  .sg-chart + .sg-table-wrap { margin-top: 16px; }
  .sg-table-wrap { overflow-x: auto; }
  .sg-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
  .sg-table th, .sg-table td { padding: 9px 12px; text-align: center; border: 1px solid rgb(var(--color-line-subtle)); }
  .sg-table thead th { background: rgb(var(--color-accent-500)); color: #fff; font-weight: 600; }
  .sg-table tbody tr:nth-child(even) { background: rgb(var(--color-bg-muted)); }
</style>

<script>
(function(){
  const modal = document.getElementById('size-guide-modal');
  const openBtn = document.getElementById('pdpSizeGuideBtn');
  if (!modal || !openBtn) return;

  function open(){ modal.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
  function close(){ modal.classList.add('hidden'); document.body.style.overflow = ''; }

  openBtn.addEventListener('click', open);
  modal.querySelectorAll('[data-sg-close]').forEach(el => el.addEventListener('click', close));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
})();
</script>
@endif
