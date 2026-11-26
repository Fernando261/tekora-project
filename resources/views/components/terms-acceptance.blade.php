<input type="hidden" name="terms_version" value="{{ \App\Support\LegalPolicies::VERSION }}">
<label class="trust-choice">
  <input type="checkbox" name="accept_terms" value="1" required @checked(old('accept_terms'))>
  <span>Acepto los <a href="{{ route('legal.show', 'terminos') }}">términos y condiciones</a> y declaro haber leído el <a href="{{ route('legal.show', 'privacidad') }}">aviso de privacidad</a>.</span>
</label>
@error('accept_terms')<p role="alert">{{ $message }}</p>@enderror
