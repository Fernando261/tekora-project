<nav class="trust-links" aria-label="Políticas de Tekora">
  @foreach(\App\Support\LegalPolicies::TITLES as $key => $label)
    <a href="{{ route('legal.show', $key) }}">{{ $label }}</a>
  @endforeach
</nav>
