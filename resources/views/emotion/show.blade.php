@php
  use Illuminate\Support\Facades\Storage;
@endphp

<h1>{{ $emotion->name }}</h1>
<img src="{{ Storage::url($emotion->image_path) }}" style="max-width:420px;border-radius:16px;" />
<p>{{ $emotion->description }}</p>