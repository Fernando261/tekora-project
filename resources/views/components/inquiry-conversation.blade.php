@props(['inquiry', 'messages'])
<section class="account-card inquiry-conversation" id="conversacion" aria-labelledby="conversation-title">
  <h2 id="conversation-title">Conversación privada</h2>
  <p class="account-help">Mensajes posteriores a la necesidad inicial. Solo participan el cliente y el prestador de esta solicitud. Fechas en horario de Ciudad de México.</p>
  @if($messages->hasPages())
    <nav class="conversation-pagination" aria-label="Páginas de la conversación">
      @if($messages->hasMorePages())<a href="{{ $messages->nextPageUrl() }}">Mensajes anteriores</a>@else<span>Inicio de la conversación</span>@endif
      @if($messages->previousCursor())<a href="{{ $messages->previousPageUrl() }}">Mensajes más recientes</a>@else<span>Mensajes más recientes</span>@endif
    </nav>
  @endif
  <ol class="conversation-messages" aria-label="Mensajes en orden cronológico">
    @forelse($messages->getCollection()->reverse() as $message)
      <li class="conversation-message {{ $message->author_user_id === auth('web')->id() ? 'is-own' : '' }}">
        <div class="conversation-meta"><strong>{{ $message->author_user_id === $inquiry->client_user_id ? 'Cliente' : 'Prestador' }} · {{ $message->author->name }}{{ $message->author_user_id === auth('web')->id() ? ' (tú)' : '' }}</strong><x-inquiry-time :at="$message->created_at" /></div>
        <p class="inquiry-message">{{ $message->body }}</p>
      </li>
    @empty
      <li class="conversation-empty"><h3>Todavía no hay mensajes</h3><p>La necesidad inicial está arriba. La conversación posterior aparecerá aquí.</p></li>
    @endforelse
  </ol>
  @if(in_array($inquiry->status, [\App\Enums\InquiryStatus::Pending, \App\Enums\InquiryStatus::Accepted], true))
    @can('sendMessage', $inquiry)
      <form method="POST" action="{{ route('inquiries.messages.store', $inquiry) }}" class="account-form">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <label for="message-body">Tu mensaje</label>
        <textarea id="message-body" name="body" rows="4" maxlength="2000" required aria-describedby="message-help" @if($errors->has('body')) aria-invalid="true" @endif>{{ old('body') }}</textarea>
        <p class="account-help" id="message-help">Hasta 2000 caracteres de texto. No hay archivos adjuntos ni avisos automáticos por mensaje. Recarga para consultar novedades.</p>
        @error('body')<p class="account-help" role="alert">{{ $message }}</p>@enderror
        @error('idempotency_key')<p class="account-help" role="alert">{{ $message }}</p>@enderror
        <button class="account-button" type="submit">Enviar mensaje</button>
      </form>
    @endcan
  @else
    <p class="account-note">La conversación está en modo solo lectura porque la solicitud está {{ mb_strtolower($inquiry->status->label()) }}.</p>
  @endif
</section>
