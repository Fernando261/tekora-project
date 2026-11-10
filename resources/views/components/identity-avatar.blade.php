@props(['user'])
<div class="tk-identity-avatar">
  @if($user->profile_photo_path)
    <img src="{{ \Illuminate\Support\Facades\Storage::url($user->profile_photo_path) }}" alt="Tu foto de perfil actual" width="104" height="104">
  @else
    <span aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span><small>Sin foto</small>
  @endif
</div>
