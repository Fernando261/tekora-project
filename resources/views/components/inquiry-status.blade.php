@props(['status'])
<span class="inquiry-status inquiry-{{ $status->value }}">{{ $status->label() }}</span>
