<x-account-layout :operations="true" :title="$service->exists ? 'Editar servicio' : 'Crear servicio'">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/catalog.css') }}">@endpush
  <a class="catalog-back" href="{{ route('provider-services.index') }}">← Mis servicios</a>
  <x-ops-heading variant="studio"><span class="account-eyebrow">Experiencia de prestador</span><h1>{{ $service->exists ? 'Editar servicio' : 'Crear servicio' }}</h1><p>Describe lo que ofreces. Puedes guardarlo como borrador antes de publicarlo.</p></x-ops-heading>
  <section class="account-card account-narrow">
    <h2>Información de la publicación</h2>
    @if($service->exists)<p class="service-status status-{{ $service->status->value }}">{{ $service->status->label() }}</p>@endif
    @if($categories->isEmpty())<p class="account-alert">No hay categorías disponibles en este momento. Vuelve más tarde.</p>@endif
    @if($service->exists && !$categories->contains('id', $service->service_category_id))<p class="account-alert">La categoría anterior está inactiva. Elige una categoría disponible para guardar o publicar el servicio. Puedes pausarlo desde Mis servicios.</p>@endif
    @php
      $title = old('title', $service->title);
      $description = old('description', $service->description);
    @endphp
    <form class="account-form" method="POST" action="{{ $service->exists ? route('provider-services.update', $service) : route('provider-services.store') }}">
      @csrf @if($service->exists) @method('PUT') @endif
      <label for="title">Título del servicio</label><input id="title" name="title" value="{{ is_string($title) ? $title : '' }}" maxlength="160" required placeholder="Por ejemplo: mantenimiento de computadoras" @error('title') aria-invalid="true" @enderror>
      <label for="description">Descripción</label><textarea id="description" name="description" rows="7" maxlength="5000" required @error('description') aria-invalid="true" @enderror>{{ is_string($description) ? $description : '' }}</textarea>
      <label for="service_category_id">Categoría</label><select id="service_category_id" name="service_category_id" required><option value="">Selecciona una categoría</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('service_category_id', $service->service_category_id) == $category->id)>{{ $category->name }}</option>@endforeach</select>
      <label for="modality">Modalidad</label><select id="modality" name="modality" required><option value="">Selecciona una modalidad</option>@foreach(\App\Enums\ServiceModality::cases() as $modality)<option value="{{ $modality->value }}" @selected(old('modality', $service->modality?->value) === $modality->value)>{{ $modality->label() }}</option>@endforeach</select>
      @if(\App\Support\TrustSchema::available())
        <label for="municipality">Municipio (servicios presenciales o mixtos)</label>
        <select id="municipality" name="municipality">
          <option value="">Seleccionar municipio o cobertura remota</option>
          @foreach(\App\Support\Municipalities::NAMES as $slug => $name)
            <option value="{{ $slug }}" @selected(old('municipality', $service->location?->municipality_slug) === $slug)>{{ $name }}</option>
          @endforeach
        </select>
        @error('municipality')<p role="alert">{{ $message }}</p>@enderror
      @endif
      <label for="status">Estado</label><select id="status" name="status" required>@foreach(\App\Enums\ServiceStatus::cases() as $status)<option value="{{ $status->value }}" @selected(old('status', $service->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>@endforeach</select>
      @if(!$service->exists)
        <h3>Precio del servicio</h3><p class="account-help">MXN por 60 minutos. Opcional: si lo dejas vacío se mostrará «Precio no publicado».</p>
        <input type="hidden" name="creation_key" value="{{ is_string(old('creation_key')) && \Illuminate\Support\Str::isUuid(old('creation_key')) ? old('creation_key') : (string) \Illuminate\Support\Str::uuid() }}">
        <label for="price_mxn">Precio en MXN</label><input id="price_mxn" name="price_mxn" type="text" inputmode="decimal" value="{{ is_string(old('price_mxn')) ? old('price_mxn') : '' }}" placeholder="1000.00" aria-describedby="price-help" maxlength="11">
        <p class="account-help" id="price-help">Sin separadores ni símbolo $. Hasta dos decimales. La comisión Tekora del 15 % está incluida; no se realizan cobros.</p>
      @endif
      <p class="account-help">Al publicar, tu nombre público, titular y biografía estarán disponibles en la ficha de prestador. Evita incluir información privada. Los borradores y servicios pausados solo están disponibles en tu cuenta.</p>
      <p class="account-help">Publicar no acredita verificación profesional. Las propuestas de horario de Tekora se gestionan desde solicitudes aceptadas; la agenda histórica permanece separada.</p>
      <div class="catalog-actions"><button class="account-button" type="submit" @disabled($categories->isEmpty())>Guardar servicio</button><a href="{{ route('provider-services.index') }}">Volver a Mis servicios</a></div>
    </form>
  </section>
  @if($service->exists)
  <section class="account-card account-narrow price-edit-form" aria-labelledby="price-title"><h2 id="price-title">Precio del servicio</h2>
    <x-published-price :cents="$price?->amount->totalCents" />
    <p class="account-help">MXN por 60 minutos. Cada actualización crea una versión nueva; las cotizaciones emitidas conservan su importe.</p>
    <form class="account-form" method="POST" action="{{ route('provider-services.price', $service) }}">@csrf
      <input type="hidden" name="expected_version" value="{{ is_scalar(old('expected_version')) ? old('expected_version') : ($price?->version ?? 0) }}"><input type="hidden" name="idempotency_key" value="{{ is_string(old('idempotency_key')) && \Illuminate\Support\Str::isUuid(old('idempotency_key')) ? old('idempotency_key') : (string) \Illuminate\Support\Str::uuid() }}">
      <label for="price_mxn">Precio en MXN</label><input id="price_mxn" name="price_mxn" type="text" inputmode="decimal" value="{{ is_string(old('price_mxn')) ? old('price_mxn') : ($price ? \App\Payments\WebMoney::decimal($price->amount->totalCents) : '') }}" placeholder="1000.00" maxlength="11" required aria-describedby="price-edit-help">
      <p id="price-edit-help" class="account-help">Sin separadores ni símbolo $. Hasta dos decimales. Comisión Tekora del 15 % incluida; prestador recibe el complemento. Sin cobros.</p><button class="account-button" type="submit">Publicar precio</button>
    </form>
  </section>
  @endif
</x-account-layout>
