@props(['rating'])
<span class="review-stars" role="img" aria-label="{{ number_format((float) $rating, 1, '.', '') }} de 5 estrellas"><span aria-hidden="true">★★★★★</span><span class="review-stars-fill" style="width:{{ min(100, max(0, (float) $rating * 20)) }}%" aria-hidden="true">★★★★★</span></span>
