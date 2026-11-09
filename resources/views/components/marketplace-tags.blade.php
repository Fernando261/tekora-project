@props(['service'])
<div class="catalog-tags"><span><x-tekora-icon name="grid" />{{ $service->category->name }}</span><span><x-tekora-icon :name="$service->modality->value === 'remote' ? 'chat' : 'user'" />{{ $service->modality->label() }}</span></div>
