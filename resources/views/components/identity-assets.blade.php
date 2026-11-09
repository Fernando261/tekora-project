@props(['scripts' => true])
@unless(app()->isLocal())<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'">@endunless
@if($scripts)@vite(['resources/css/tekora/identity.css', 'resources/js/tekora/identity.js'])@else @vite(['resources/css/tekora/identity.css'])@endif
