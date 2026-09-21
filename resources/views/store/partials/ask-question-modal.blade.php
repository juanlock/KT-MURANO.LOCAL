{{-- "Ask About This Item" form. Opened by #pdpAskBtn (and the button inside the
     Questions tab). Only signed-in customers may ask; guests get a sign-in
     prompt instead of a form. --}}
@php $askAuthed = (bool) Auth::guard('store')->user(); @endphp

<div id="ask-question-modal" class="aq-root hidden" role="dialog" aria-modal="true"
     aria-labelledby="aq-title">
  <div class="aq-overlay" data-aq-close></div>
  <div class="aq-card">
    <button type="button" class="aq-close" data-aq-close aria-label="{{ __('messages.Close') }}">&times;</button>

    <div class="aq-head">
      <h3 class="aq-title" id="aq-title">{{ __('messages.AskAboutThisItem') }}</h3>
      <p class="aq-sub">{{ $p->name }}</p>
    </div>

    <div class="aq-body">
      @if($askAuthed)
        <label class="form-label" for="aq-text">{{ __('messages.YourQuestion') }}</label>
        <textarea id="aq-text" class="input" rows="4" maxlength="1000"
                  placeholder="{{ __('messages.QuestionPlaceholder') }}"></textarea>
        <div class="aq-hint">{{ __('messages.QuestionModerationNote') }}</div>
        <div id="aq-msg" class="aq-msg hidden"></div>
      @else
        <div class="alert alert-warning m-0">{{ __('messages.MustBeSignedInToAsk') }}</div>
      @endif
    </div>

    <div class="aq-foot">
      <button type="button" class="btn btn-outline" data-aq-close>{{ __('messages.Close') }}</button>
      @if($askAuthed)
        <button type="button" id="aq-submit" class="btn btn-primary">
          <x-store.icon name="send" class="w-4 h-4" />{{ __('messages.SendQuestion') }}
        </button>
      @else
        <a href="{{ route('store.login.show', ['redirect' => url()->current()]) }}" class="btn btn-primary">
          {{ __('messages.SignIn') }}
        </a>
      @endif
    </div>
  </div>
</div>

<style>
  .aq-root { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; }
  .aq-root.hidden { display: none; }
  .aq-overlay { position: absolute; inset: 0; background: rgba(0,0,0,.5); }
  .aq-card { position: relative; z-index: 1; width: min(560px, 92vw); max-height: 88vh; overflow: auto;
             background: rgb(var(--color-bg-surface)); color: rgb(var(--color-fg-primary));
             border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
  .aq-close { position: absolute; top: 10px; right: 14px; background: none; border: 0; font-size: 26px; line-height: 1;
              cursor: pointer; color: rgb(var(--color-fg-secondary)); }
  .aq-head { padding: 18px 22px 12px; border-bottom: 1px solid rgb(var(--color-line-subtle)); }
  .aq-title { font-size: 1.15rem; font-weight: 700; margin: 0; }
  .aq-sub { font-size: .875rem; color: rgb(var(--color-fg-muted)); margin: 4px 0 0; }
  .aq-body { padding: 18px 22px; }
  .aq-hint { font-size: .78rem; color: rgb(var(--color-fg-muted)); margin-top: 8px; }
  .aq-msg { margin-top: 12px; font-size: .875rem; border-radius: 8px; padding: 8px 10px; }
  .aq-msg.is-ok { background: rgb(var(--color-accent-500) / .12); color: rgb(var(--color-accent-500)); }
  .aq-msg.is-err { background: rgba(220,38,38,.12); color: #dc2626; }
  .aq-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 0 22px 18px; }
</style>

<script>
(function(){
  var modal = document.getElementById('ask-question-modal');
  if (!modal) return;

  function open(){ modal.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
  function close(){ modal.classList.add('hidden'); document.body.style.overflow = ''; }

  var openBtn = document.getElementById('pdpAskBtn');
  if (openBtn) openBtn.addEventListener('click', open);
  document.querySelectorAll('.js-ask-open').forEach(function(b){ b.addEventListener('click', open); });
  modal.querySelectorAll('[data-aq-close]').forEach(function(el){ el.addEventListener('click', close); });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) close();
  });

  var submit = document.getElementById('aq-submit');
  if (!submit) return;

  submit.addEventListener('click', function(){
    var box = document.getElementById('aq-text');
    var msg = document.getElementById('aq-msg');
    var text = (box.value || '').trim();

    function say(kind, s){
      msg.textContent = s;
      msg.className = 'aq-msg is-' + kind;
    }

    if (text.length < 5) {
      say('err', @json(__('messages.QuestionTooShort')));
      return;
    }

    submit.disabled = true;
    fetch(@json(url('/'.store_path_to('products'))) + '/{{ $p->id }}/questions', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
      },
      credentials: 'same-origin',
      body: JSON.stringify({ question: text })
    })
      .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, d: d }; }); })
      .then(function(res){
        if (res.ok) {
          say('ok', res.d.message || @json(__('messages.QuestionSubmitted')));
          box.value = '';
          setTimeout(close, 1800);
        } else {
          say('err', res.d.message || (res.d.errors && res.d.errors.question && res.d.errors.question[0])
                     || @json(__('messages.Failed')));
        }
      })
      .catch(function(){ say('err', @json(__('messages.Failed'))); })
      .finally(function(){ submit.disabled = false; });
  });
})();
</script>
