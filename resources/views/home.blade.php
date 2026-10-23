<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MindBloom - Inicio</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/home.css">
</head>

<body>
  <div class="home-layout">
    <aside class="sidebar" id="sidebar">
      <div class="brand">
        <div class="brand-logo"><img class="brand-logo" src="img/logo.png" alt=""></div>
        <div class="brand-text">
          <span class="brand-title">MindBloom</span>
          <span class="brand-sub">Bienestar</span>
        </div>
      </div>

      <nav class="menu">
        <a class="menu-item active" href="/">
          <span class="icon">▦</span>
          <span>Inicio</span>
        </a>

        <button class="menu-item has-sub" type="button" data-sub="createSub">
          <span class="icon">✎</span>
          <span>Explorar</span>
          <span class="chev">▾</span>
        </button>

        <div class="submenu" id="createSub">
          <a href="/comunidad" class="submenu-item">Comunidad</a>
          <a href="/conferencias" class="submenu-item">Conferencias</a>
        </div>

        <button class="menu-item has-sub" type="button" data-sub="accountSub">
          <span class="icon">👤</span>
          <span>Cuenta</span>
          <span class="chev">▾</span>
        </button>

        <div class="submenu" id="accountSub">
          <a href="/perfil" class="submenu-item">Perfil</a>
          <a href="/citas" class="submenu-item">Mis citas</a>
        </div>

        <a class="menu-item" href="/profesionistas">
          <span class="icon">🩺</span>
          <span>Profesionistas</span>
        </a>

        <a class="menu-item" href="/citas">
          <span class="icon">🗓</span>
          <span>Agendar cita</span>
        </a>

        <form method="POST" action="{{ route('logout') }}" class="logout-form">
          @csrf
          <button type="submit" class="menu-item logout-btn" style="width:100%;">
            <span class="icon">⎋</span>
            <span>Cerrar sesión</span>
          </button>
        </form>
      </nav>

      <div class="sidebar-footer">
        <div class="hint">
          <strong>Tip MindBloom</strong>
          <p>Haz una pausa, respira y vuelve a tu centro 🌿</p>
        </div>
      </div>
    </aside>

    <main class="main-content">
      <div class="content-head">
        <button class="toggle" id="toggleSidebar" type="button" title="Menú">☰</button>
      </div>

      <div class="hero">
        <h1>Bienvenido a MindBloom</h1>
        <p>Promovemos la salud mental a través de herramientas digitales accesibles, seguras y amigables.</p>
      </div>

      <section>
        <h2>¿Quiénes somos?</h2>
        <p>MindBloom es una plataforma dedicada a crear espacios de acompañamiento, reflexión y apoyo para todas las personas que buscan mejorar su bienestar emocional. Combinamos tecnología, diseño accesible y psicología para transformar la experiencia de salud mental en línea.</p>
        <div class="team">
          <div class="team-card">
            <img src="img/alex.jpg" alt="Alex Fernando Flores Carrillo">
            <h3>Alex Fernando Flores Carrillo</h3>
            <p>Desarrollador Backend</p>
          </div>
          <div class="team-card">
            <img src="img/roque.jpg" alt="Roque Joseph Guerrero Ponce">
            <h3>Roque Joseph Guerrero Ponce</h3>
            <p>Desarrollador Frontend</p>
          </div>
        </div>
      </section>

      <section>
        <h2>Estadísticas sobre salud mental</h2>
        <div class="estadisticas">
          <div class="card">
            <h3 class="contador" data-target="25">0%</h3>
            <p>de los adultos mexicanos ha experimentado algún trastorno mental.</p>
          </div>
          <div class="card">
            <h3 class="contador" data-target="3">0%</h3>
            <p>es la proporción de quienes reciben atención médica profesional.</p>
          </div>
          <div class="card">
            <h3 class="contador" data-target="8237">0</h3>
            <p>casos de suicidio registrados en 2022 en México según INEGI.</p>
          </div>
        </div>
      </section>

      <section class="profesionistas-preview">
        <div class="preview-container">
          <h2>¿Buscas apoyo profesional?</h2>
          <p>
            En MindBloom contamos con profesionistas aliados capacitados para acompañarte
            en tu proceso de bienestar emocional.
          </p>
          <a href="/profesionistas" class="preview-btn">
            Conocer profesionistas aliados
          </a>
        </div>
      </section>

      @php
        use Illuminate\Support\Facades\Storage;
      @endphp

      <section class="mb-emotion-gallery">
        <h2>Galería emocional</h2>
        <p>Explora emociones con una experiencia MindBloom: gira, elige y descubre.</p>

        <div class="mb-stage" aria-label="Galería emocional">
          <div class="mb-carousel-wrap">
            <div id="mbCoverflow" class="mb-coverflow">
              @forelse($emotions as $i => $e)
                <article
                  class="mb-card"
                  data-index="{{ $i }}"
                  data-slug="{{ $e->slug }}"
                  data-title="{{ $e->name }}"
                  data-subtitle="{{ $e->subtitle ?? 'Explora esta emoción con suavidad.' }}"
                  data-img="{{ Storage::url($e->image_path) }}"
                  style="--accent: {{ $e->accent ?? '#B79CFF' }};"
                  role="group"
                  aria-label="{{ $e->name }}"
                  tabindex="0"
                >
                  <img src="{{ Storage::url($e->image_path) }}" alt="Emoción: {{ $e->name }}" loading="lazy" />
                  <div class="mb-meta">
                    <div class="mb-pill">MindBloom • emoción</div>
                    <h3>{{ $e->name }}</h3>
                    <p>{{ $e->subtitle ?? 'Explora esta emoción con suavidad.' }}</p>
                  </div>
                </article>
              @empty
                <div class="mb-empty">
                  <strong>No hay emociones aún</strong>
                  <p>Agrega registros en la tabla <code>emotions</code> para ver el carrusel.</p>
                </div>
              @endforelse
            </div>
          </div>

          <div class="mb-controls">
            <div class="mb-btns">
              <button class="mb-ctrl" id="mbPrev" type="button" aria-label="Anterior">‹</button>
              <button class="mb-ctrl" id="mbNext" type="button" aria-label="Siguiente">›</button>
            </div>
            <div class="mb-hint">Arrastra para girar • Click para abrir</div>
          </div>
        </div>
      </section>

      <a href="{{ route('pdf.emotions.cards', ['per_page' => 6]) }}" class="preview-btn">
  Descargar tarjetas PDF (6 por hoja)
</a>

<a href="{{ route('pdf.emotions.cards', ['per_page' => 9]) }}" class="preview-btn" style="margin-left:10px;">
  Descargar tarjetas PDF (9 por hoja)
</a>

      <div class="mb-modal" id="mbModal" aria-hidden="true">
        <div class="mb-modal-backdrop" data-close="1"></div>

        <div class="mb-modal-panel" role="dialog" aria-modal="true" aria-labelledby="mbModalTitle">
          <button class="mb-modal-close" type="button" aria-label="Cerrar" data-close="1">✕</button>

          <div class="mb-modal-media">
            <img id="mbModalImg" src="" alt="" />
          </div>

          <div class="mb-modal-body">
            <div class="mb-pill">MindBloom • emoción</div>
            <h3 id="mbModalTitle"></h3>
            <p id="mbModalText"></p>

            <div class="mb-modal-actions">
              <button class="mb-modal-btn" type="button" data-close="1">Cerrar</button>
              <a class="mb-modal-link" id="mbModalLink" href="#" style="display:none;">Ver más</a>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <footer class="footer-links">
    <div><strong>COMPANY</strong><br>How it Works<br>Pricing</div>
    <div><strong>RESOURCES</strong><br>Blog Post Name<br>Articles</div>
    <div><strong>ABOUT</strong><br>Terms & Conditions<br>Privacy Policy</div>
  </footer>

  <script>
    document.querySelectorAll('.menu-item.has-sub').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.sub;
        const box = document.getElementById(id);
        const opened = box.classList.toggle('open');
        btn.classList.toggle('open', opened);
      });
    });

    const toggle = document.getElementById('toggleSidebar');
    toggle.addEventListener('click', () => {
      document.body.classList.toggle('sidebar-open');
    });

    const counters = document.querySelectorAll('.contador');
    const speed = 100;

    counters.forEach(counter => {
      const updateCount = () => {
        const target = +counter.getAttribute('data-target');
        const count = +counter.innerText.replace(/\D/g, '');
        const inc = Math.ceil(target / speed);

        if (count < target) {
          counter.innerText = (count + inc) + (counter.innerText.includes('%') ? '%' : '');
          setTimeout(updateCount, 40);
        } else {
          counter.innerText = target + (counter.innerText.includes('%') ? '%' : '');
        }
      };
      updateCount();
    });

    (function(){
      const modal = document.getElementById('mbModal');
      if (!modal) return;

      const img = document.getElementById('mbModalImg');
      const title = document.getElementById('mbModalTitle');
      const text = document.getElementById('mbModalText');
      const link = document.getElementById('mbModalLink');

      let lastFocus = null;

      function openEmotionModal(card){
        lastFocus = document.activeElement;

        const t = card.dataset.title || '';
        const s = card.dataset.subtitle || '';
        const src = card.dataset.img || '';

        img.src = src;
        img.alt = `Emoción: ${t}`;
        title.textContent = t;
        text.textContent = s;

        link.style.display = 'none';

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');

        const closeBtn = modal.querySelector('.mb-modal-close');
        if (closeBtn) closeBtn.focus();
      }

      function closeModal(){
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        img.src = '';

        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
      }

      modal.addEventListener('click', (e) => {
        const el = e.target;
        if (el && el.dataset && el.dataset.close === '1') closeModal();
      });

      window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
      });

      window.openEmotionModal = openEmotionModal;
    })();

    (function(){
      const wrap = document.getElementById('mbCoverflow');
      if (!wrap) return;

      const cards = Array.from(wrap.querySelectorAll('.mb-card'));
      const prevBtn = document.getElementById('mbPrev');
      const nextBtn = document.getElementById('mbNext');

      const n = cards.length;
      if (!n) return;

      let index = 0;
      let autoplay = null;

      const norm = (i) => (i % n + n) % n;

      function setClasses(){
        cards.forEach((card, i) => {
          card.classList.remove('is-active','is-left-1','is-right-1','is-left-2','is-right-2','is-hidden');

          const dist = ((i - index) % n + n) % n;
          const signed = dist > n/2 ? dist - n : dist;

          if (signed === 0) card.classList.add('is-active');
          else if (signed === -1) card.classList.add('is-left-1');
          else if (signed === 1) card.classList.add('is-right-1');
          else if (signed === -2) card.classList.add('is-left-2');
          else if (signed === 2) card.classList.add('is-right-2');
          else card.classList.add('is-hidden');
        });
      }

      function next(){ index = norm(index + 1); setClasses(); }
      function prev(){ index = norm(index - 1); setClasses(); }

      if (prevBtn) prevBtn.addEventListener('click', prev);
      if (nextBtn) nextBtn.addEventListener('click', next);

      let dragging = false;
      let startX = 0;

      function down(e){
        dragging = true;
        startX = (e.touches ? e.touches[0].clientX : e.clientX);
      }

      function up(e){
        if (!dragging) return;
        dragging = false;

        const endX = (e.changedTouches ? e.changedTouches[0].clientX : e.clientX);
        const dx = endX - startX;

        if (Math.abs(dx) < 45) return;
        if (dx < 0) next();
        else prev();
      }

      wrap.addEventListener('mousedown', down);
      window.addEventListener('mouseup', up);

      wrap.addEventListener('touchstart', down, { passive: true });
      window.addEventListener('touchend', up, { passive: true });

      cards.forEach((card, i) => {
        const activateOrOpen = () => {
          if (i !== index) {
            index = i;
            setClasses();
            return;
          }
          if (typeof window.openEmotionModal === 'function') window.openEmotionModal(card);
        };

        card.addEventListener('click', activateOrOpen);
        card.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            activateOrOpen();
          }
        });
      });

      function startAutoplay(){
        stopAutoplay();
        autoplay = setInterval(next, 2600);
      }

      function stopAutoplay(){
        if (autoplay) clearInterval(autoplay);
        autoplay = null;
      }

      wrap.addEventListener('mouseenter', stopAutoplay);
      wrap.addEventListener('mouseleave', startAutoplay);
      wrap.addEventListener('focusin', stopAutoplay);
      wrap.addEventListener('focusout', startAutoplay);

      setClasses();
      startAutoplay();
    })();

    (function(){
      const wrap = document.getElementById('mbCoverflow');
      if (!wrap) return;

      const cards = Array.from(wrap.querySelectorAll('.mb-card'));
      cards.forEach(card => {
        const img = card.querySelector('img');
        if (!img) return;

        const apply = () => {
          const ratio = img.naturalWidth / img.naturalHeight;
          if (ratio > 1.25 || ratio < 0.85) card.classList.add('fit-contain');
        };

        if (img.complete) apply();
        else img.addEventListener('load', apply, { once: true });
      });
    })();
  </script>
</body>
</html>