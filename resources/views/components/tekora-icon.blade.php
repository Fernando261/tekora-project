@props(['name' => 'grid'])
@php
  $paths = [
    'grid' => ['M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z'],
    'search' => ['M21 21l-5-5', 'M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0'],
    'briefcase' => ['M3 7h18v14H3z M8 7V3h8v4 M3 12c6 4 12 4 18 0 M10 13h4'],
    'calendar' => ['M4 5h16v16H4z M8 2v6 M16 2v6 M4 10h16 M8 14h2 M14 14h2 M8 18h2'],
    'chat' => ['M21 11a8 8 0 0 1-8 8H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z M7 8h10 M7 12h7'],
    'bell' => ['M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4'],
    'star' => ['m12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z'],
    'arrow' => ['M5 19 19 5 M5 5h14v14'],
    'user' => ['M16 6a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 22v-3a8 8 0 0 1 16 0v3'],
    'clock' => ['M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0 M12 6v6l4 3'],
    'plus' => ['M12 4v16 M4 12h16'],
    'menu' => ['M3 5h18 M3 12h18 M3 19h18'],
    'check' => ['m4 12 5 5 11-11'],
    'shield' => ['m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6z M8 12l3 3 5-6'],
  ];
@endphp
<svg class="tk-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">@foreach($paths[$name] ?? $paths['grid'] as $path)<path d="{{ $path }}" />@endforeach</svg>
