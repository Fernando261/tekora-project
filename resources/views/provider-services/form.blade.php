<x-account-layout :title="$service->exists ? 'Editar servicio' : 'Crear servicio'">
  @push('styles')<link rel="stylesheet" href="{{ asset('css/catalog.css') }}">@endpush
  <a class="catalog-back" href="{{ route('provider-services.index') }}">← Mis servicios</a>
  <header class="account-heading"><span class="account-eyebrow">Experiencia de prestador</span><h1>{{ $service->exists ? 'Editar servicio' : 'Crear servicio' }}</h1><p>Describe lo que ofreces. Puedes guardarlo como borrador antes de publicarlo.</p></header>
  <section class="account-card account-narrow">
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
      <label for="status">Estado</label><select id="status" name="status" required>@foreach(\App\Enums\ServiceStatus::cases() as $status)<option value="{{ $status->value }}" @selected(old('status', $service->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>@endforeach</select>
      <p class="account-help">Al publicar, tu nombre público, titular y biografía estarán disponibles en la ficha de prestador. Evita incluir información privada. Los borradores y servicios pausados solo están disponibles en tu cuenta.</p>
      <p class="account-help">Publicar no acredita verificación profesional. Este catálogo no recibe reservas ni citas de MindBloom.</p>
      <div class="catalog-actions"><button class="account-button" type="submit" @disabled($categories->isEmpty())>Guardar servicio</button><a href="{{ route('provider-services.index') }}">Volver a Mis servicios</a></div>
    </form>
  </section>
</x-account-layout>
