{{-- Vehicle Fitment: selector bar + Make/Model/Year modal + My Garage.
     Rendered only while the feature toggle (System Settings → Features) is on.
     The active vehicle lives in the session; picking one reloads the page so
     every listing is server-filtered to compatible parts. --}}
@inject('fitmentSvc', 'App\Services\FitmentService')
@if($fitmentSvc->enabled())
  @php($storeVehicle = $fitmentSvc->currentVehicle())

  <div class="bg-bg-surface border-b border-line-subtle" x-data="vehicleSelector()"
       @open-vehicle-selector.window="openModal()">
    <div class="container flex items-center gap-3 h-11 text-sm">
      <svg class="w-5 h-5 text-accent-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11"/><path d="M4 16v-3a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3"/><circle cx="7.5" cy="16" r="1.5"/><circle cx="16.5" cy="16" r="1.5"/></svg>

      @if($storeVehicle)
        <span class="text-fg-muted hidden sm:inline">{{ __('messages.ShowingPartsFor') }}:</span>
        <span class="font-medium text-fg-primary truncate">{{ $storeVehicle['label'] ?? '' }}</span>
        <button type="button" class="text-accent-500 hover:underline shrink-0" @click="openModal()">
          {{ __('messages.ChangeVehicle') }}
        </button>
        <button type="button" class="text-fg-muted hover:text-danger shrink-0" @click="clearVehicle()">
          {{ __('messages.ClearVehicle') }}
        </button>
      @else
        <button type="button" class="font-medium text-accent-500 hover:underline" @click="openModal()">
          {{ __('messages.SelectYourVehicle') }}
        </button>
        <span class="text-fg-muted hidden md:inline">— {{ __('messages.SelectVehicleToCheckFit') }}</span>
      @endif
    </div>

    {{-- Modal --}}
    <template x-teleport="body">
      <div x-show="open" x-cloak class="fixed inset-0 z-[90] flex items-start justify-center p-4 pt-[10vh]">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/50" @click="open = false"></div>
        <div x-show="open" x-transition
             class="relative w-full max-w-md bg-bg-elevated border border-line-subtle rounded-xl shadow-xl p-5"
             @keydown.escape.window="open = false">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-lg">{{ __('messages.SelectYourVehicle') }}</h3>
            <button type="button" class="btn btn-ghost btn-icon" @click="open = false" aria-label="{{ __('messages.Close') }}">
              <x-store.icon name="x" class="w-5 h-5" />
            </button>
          </div>

          <div x-show="loading" class="py-8 flex justify-center">
            <div class="w-8 h-8 border-2 border-line-subtle border-t-accent-500 rounded-full animate-spin"></div>
          </div>

          <div x-show="!loading">
            {{-- My Garage (logged-in customers) --}}
            <template x-if="garage.length">
              <div class="mb-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-fg-muted mb-2">{{ __('messages.MyGarage') }}</div>
                <div class="space-y-1 max-h-40 overflow-y-auto">
                  <template x-for="g in garage" :key="g.id">
                    <div class="flex items-center gap-2">
                      <button type="button"
                              class="flex-1 text-start px-3 py-2 rounded-lg border border-line-subtle hover:border-accent-500 hover:bg-bg-muted text-sm transition-colors"
                              @click="pickGarage(g)">
                        <span x-text="g.label"></span>
                        <span x-show="g.is_default" class="chip chip-info ms-1 text-[10px]">{{ __('messages.Default') }}</span>
                      </button>
                      <button type="button" class="btn btn-ghost btn-icon text-fg-muted hover:text-danger"
                              @click="removeGarage(g)" aria-label="{{ __('messages.Del') }}">
                        <x-store.icon name="x" class="w-4 h-4" />
                      </button>
                    </div>
                  </template>
                </div>
                <div class="border-t border-line-subtle my-3"></div>
              </div>
            </template>

            {{-- Cascade --}}
            <div class="space-y-3">
              <div>
                <label class="block text-sm text-fg-muted mb-1">{{ __('messages.Make') }}</label>
                <select class="input" x-model.number="makeId" @change="modelId = ''">
                  <option value="">—</option>
                  <template x-for="m in makes" :key="m.id">
                    <option :value="m.id" x-text="m.name"></option>
                  </template>
                </select>
              </div>
              <div>
                <label class="block text-sm text-fg-muted mb-1">{{ __('messages.Model') }}</label>
                <select class="input" x-model.number="modelId" :disabled="!makeId">
                  <option value="">—</option>
                  <template x-for="m in modelsForMake" :key="m.id">
                    <option :value="m.id" x-text="m.name"></option>
                  </template>
                </select>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm text-fg-muted mb-1">{{ __('messages.Year') }}</label>
                  <select class="input" x-model.number="year">
                    <option value="">—</option>
                    <template x-for="y in years" :key="y">
                      <option :value="y" x-text="y"></option>
                    </template>
                  </select>
                </div>
                <div>
                  <label class="block text-sm text-fg-muted mb-1">{{ __('messages.EngineOptional') }}</label>
                  <input type="text" class="input" x-model="engine" maxlength="100" placeholder="1.6L">
                </div>
              </div>

              <p x-show="error" x-text="error" class="text-sm text-danger"></p>

              <button type="button" class="btn btn-primary btn-block" :disabled="!makeId || !modelId || busy"
                      @click="apply()">
                <span x-show="!busy">{{ __('messages.SelectYourVehicle') }}</span>
                <span x-show="busy">…</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>

  <script>
    function vehicleSelector() {
      var CSRF = document.querySelector('meta[name="csrf-token"]').content;
      var GARAGE_SELECT_URL = @json(route('store.garage.select', ['id' => 'GID']));
      var GARAGE_DESTROY_URL = @json(route('store.garage.destroy', ['id' => 'GID']));
      function post(url, payload) {
        return fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify(payload || {}),
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); });
      }

      return {
        open: false, loading: false, busy: false, loaded: false, error: '',
        makes: [], models: [], garage: [],
        makeId: '', modelId: '', year: '', engine: '',
        years: (function () {
          var out = [], y = new Date().getFullYear() + 1;
          for (; y >= 1980; y--) out.push(y);
          return out;
        })(),
        get modelsForMake() {
          var mid = Number(this.makeId || 0);
          return this.models.filter(function (m) { return Number(m.vehicle_make_id) === mid; });
        },
        openModal: function () {
          this.open = true;
          if (this.loaded) return;
          var self = this;
          self.loading = true;
          fetch(@json(route('store.vehicle.options')), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              self.makes = d.makes || [];
              self.models = d.models || [];
              self.garage = d.garage || [];
              if (d.current) {
                self.makeId = d.current.make_id || '';
                self.modelId = d.current.model_id || '';
                self.year = d.current.year || '';
                self.engine = d.current.engine || '';
              }
              self.loaded = true;
            })
            .finally(function () { self.loading = false; });
        },
        apply: function () {
          var self = this;
          self.busy = true; self.error = '';
          post(@json(route('store.vehicle.select')), {
            make_id: self.makeId, model_id: self.modelId,
            year: self.year || null, engine: self.engine || null,
          }).then(function (res) {
            if (res.ok) { window.location.reload(); }
            else { self.error = (res.data && res.data.error) || 'Error'; self.busy = false; }
          }).catch(function () { self.busy = false; });
        },
        pickGarage: function (g) {
          var self = this;
          self.busy = true;
          post(GARAGE_SELECT_URL.replace('GID', String(g.id)), {})
            .then(function (res) {
              if (res.ok) { window.location.reload(); }
              else { self.busy = false; }
            });
        },
        removeGarage: function (g) {
          var self = this;
          fetch(GARAGE_DESTROY_URL.replace('GID', String(g.id)), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          }).then(function () {
            self.garage = self.garage.filter(function (x) { return x.id !== g.id; });
          });
        },
        clearVehicle: function () {
          post(@json(route('store.vehicle.clear')), {}).then(function () { window.location.reload(); });
        },
      };
    }
  </script>
@endif
