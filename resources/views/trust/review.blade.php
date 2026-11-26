<x-account-layout title="Revisión profesional">
  <x-flash-messages />
  <h1>Revisión de certificaciones profesionales</h1>
  <div class="trust-review-list">
    @forelse($credentials as $credential)
      <section class="account-card">
        <h2>{{ $credential->owner->name }}</h2>
        <p>Estado: {{ \App\Services\ProfessionalCredentials::LABELS[$credential->status] }} · Revisión {{ $credential->revision }}</p>
        @if($credential->license_number)<p>Cédula declarada: {{ $credential->license_number }}</p>@endif
        @if($credential->evidence_path)<p><a href="{{ route('trust.download', $credential->user_id) }}">Descargar documento privado</a></p>@endif
        @if(in_array($credential->status, ['pending', 'verified'], true) && $credential->user_id !== auth('web')->id())
          <form class="account-form" method="POST" action="{{ route('trust.decide', $credential->user_id) }}">
            @csrf
            <input type="hidden" name="revision" value="{{ $credential->revision }}">
            <label for="decision-{{ $credential->user_id }}">Decisión</label>
            <select name="decision" id="decision-{{ $credential->user_id }}">
              @if($credential->status === 'pending')<option value="verified">Aprobar</option><option value="rejected">Rechazar</option>
              @else<option value="revoked">Revocar</option>@endif
            </select>
            <label for="reason-{{ $credential->user_id }}">Motivo privado</label>
            <textarea name="reason" id="reason-{{ $credential->user_id }}" required maxlength="500"></textarea>
            <button class="account-button">Registrar decisión</button>
          </form>
        @endif
      </section>
    @empty <p>No hay certificaciones para revisar.</p>
    @endforelse
  </div>
  <x-catalog-pagination :paginator="$credentials" />
</x-account-layout>
