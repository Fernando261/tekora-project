@props(['at'])
<time datetime="{{ $at->toIso8601String() }}">{{ $at->copy()->timezone('America/Mexico_City')->format('d/m/Y H:i') }}</time>
