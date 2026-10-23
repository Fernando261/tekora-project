<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Comunidad - MindBloom</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/comunidad.css') }}">
</head>

<body>

  @php
    use Illuminate\Support\Facades\Storage;

    $defaultAvatar = asset('img/avatar.png');

    $avatarUrl = function ($user) use ($defaultAvatar) {
      if (!$user) return $defaultAvatar;

      $p = $user->profile_photo_path ?? null;
      if (is_string($p) && trim($p) !== '') return Storage::url($p);

      return $defaultAvatar;
    };

    $safeUrl = function ($path) {
      if (is_string($path) && trim($path) !== '') return Storage::url($path);
      return null;
    };
  @endphp

  <div class="home-layout">

    <aside class="sidebar" id="sidebar">
      <div class="brand">
        <div class="brand-logo">
          <img class="brand-logo" src="{{ asset('img/logo.png') }}" alt="">
        </div>

        <div class="brand-text">
          <span class="brand-title">MindBloom</span>
          <span class="brand-sub">Bienestar</span>
        </div>
      </div>

      <nav class="menu">
        <a class="menu-item" href="/">
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

        <a class="menu-item" href="/profesionistas">
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

      <div class="hero hero-mini">
        <h1>Comunidad</h1>
        <p>Comparte, comenta y apóyate con otras personas dentro de MindBloom.</p>
      </div>

      <section class="community-wrap">

        <div class="search-bar">
          <button class="filter-btn" type="button">Filtro</button>
          <input type="text" placeholder="Buscar publicaciones...">
        </div>

        <div class="create-post">
          <h3>Crear publicación</h3>

          <form method="POST" action="{{ route('comunidad.store') }}" class="form-comunidad" enctype="multipart/form-data">
            @csrf

            <textarea name="content" rows="4" placeholder="¿Qué estás pensando hoy?" class="form-textarea"></textarea>

            {{-- ✅ Uploader MindBloom (solo estilo, misma funcionalidad) --}}
            <div class="mb-uploader" data-uploader>
              <input
                type="file"
                name="media"
                id="postMedia"
                class="mb-file"
                accept="image/*,video/mp4,video/webm,video/ogg,video/quicktime"
              >

              <label class="mb-pick" for="postMedia">
                <span class="mb-pick-ico">🖼️</span>
                <span class="mb-pick-text">Adjuntar media</span>
                <span class="mb-pick-sub">imagen o video</span>
              </label>

              <div class="mb-file-meta">
                <span class="mb-file-chip" data-filechip>Sin archivo</span>
                <button type="button" class="mb-file-clear" data-clear style="display:none;">Quitar</button>
              </div>

              <div class="mb-preview" data-preview style="display:none;"></div>
            </div>

            <button type="submit" class="form-btn">Publicar</button>

            @error('media')
              <p style="margin-top:10px;color:#b00020;font-weight:700;">{{ $message }}</p>
            @enderror

            @error('content')
              <p style="margin-top:10px;color:#b00020;font-weight:700;">{{ $message }}</p>
            @enderror

          </form>

          @if($errors->any())
            <div style="margin-top:10px; font-weight:700;">
              @foreach($errors->all() as $error)
                <div style="margin-top:4px;">{{ $error }}</div>
              @endforeach
            </div>
          @endif
        </div>

        @foreach($posts as $post)
          @php $postMediaUrl = $safeUrl($post->media_path); @endphp

          <div class="post">
            <div class="header">
              <img class="profile-pic" src="{{ $avatarUrl($post->user) }}" alt="Foto de {{ $post->user->name }}">
              <strong>{{ $post->user->name }}</strong>
              <span class="timestamp">{{ $post->created_at->format('d M Y H:i') }}</span>
            </div>

            @if(is_string($post->content) && trim($post->content) !== '')
              <div class="content">{{ $post->content }}</div>
            @endif

            {{-- ⚠️ NO se toca: render actual de media del post --}}
            @if($post->media_path)
              @if($post->media_type === 'image')
                <img src="/{{ $post->media_path }}" style="width:100%;max-width:680px;border-radius:18px;margin-top:10px;" alt="media">
              @else
                <video controls style="width:100%;max-width:680px;border-radius:18px;margin-top:10px;">
                  <source src="/{{ $post->media_path }}">
                </video>
              @endif
            @endif

            @if(auth()->id() === $post->user_id)
              <button class="delete-button" type="button" onclick="openModal({{ $post->id }})">
                <svg class="delete-svgIcon" viewBox="0 0 448 512">
                  <path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"></path>
                </svg>
              </button>
            @endif

            <div class="post-actions">
              <form method="POST" action="/posts/{{ $post->id }}/like">
                @csrf
                <button type="submit" class="like-btn">
                  ❤️ {{ $post->likes->count() }}
                </button>
              </form>
            </div>

            <div class="comments-section">
              @foreach($post->comments as $comment)
                @php $commentMediaUrl = $safeUrl($comment->media_path); @endphp

                <div class="comment">
                  <div class="comment-head">
                    <img class="profile-pic" src="{{ $avatarUrl($comment->user) }}" alt="Foto de {{ $comment->user->name }}">
                    <div class="comment-meta">
                      <strong>{{ $comment->user->name }}</strong>
                      <span class="timestamp">{{ $comment->created_at->format('d M Y H:i') }}</span>
                    </div>

                    @if(auth()->id() === $comment->user_id)
                      <button class="delete-button" type="button" onclick="openCommentModal({{ $comment->id }})">
                        <svg class="delete-svgIcon" viewBox="0 0 448 512">
                          <path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"></path>
                        </svg>
                      </button>
                    @endif
                  </div>

                  @if(is_string($comment->content) && trim($comment->content) !== '')
                    <p class="comment-text">{{ $comment->content }}</p>
                  @endif

                  <div class="comment-actions">
                    <form method="POST" action="/comments/{{ $comment->id }}/like">
                      @csrf
                      <button type="submit" class="like-btn">
                        ❤️ {{ $comment->likes->count() }}
                      </button>
                    </form>
                  </div>

                  @if($post->media_path)
                    @if($post->media_type === 'image')
                      <img src="/{{ $post->media_path }}" style="width:100%;max-width:680px;border-radius:18px;margin-top:10px;" alt="media">
                    @else
                      <video controls style="width:100%;max-width:680px;border-radius:18px;margin-top:10px;">
                        <source src="/{{ $post->media_path }}">
                      </video>
                    @endif
                  @endif

                </div>
              @endforeach

              @php $commentMediaId = 'commentMedia_'.$post->id; @endphp
              <form method="POST" action="/comments" class="comment-form" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="post_id" value="{{ $post->id }}">

                <input type="text" name="content" placeholder="Escribe un comentario..." class="comment-input">

                <div class="mb-uploader mb-uploader--mini" data-uploader>
                  <input
                    type="file"
                    name="media"
                    id="{{ $commentMediaId }}"
                    class="mb-file"
                    accept="image/*,video/mp4,video/webm,video/ogg,video/quicktime"
                  >

                  <label class="mb-pick mb-pick--mini" for="{{ $commentMediaId }}">
                    <span class="mb-pick-ico">📎</span>
                    <span class="mb-pick-text">Adjuntar</span>
                    <span class="mb-pick-sub">opcional</span>
                  </label>

                  <div class="mb-file-meta">
                    <span class="mb-file-chip" data-filechip>Sin archivo</span>
                    <button type="button" class="mb-file-clear" data-clear style="display:none;">Quitar</button>
                  </div>

                  <div class="mb-preview" data-preview style="display:none;"></div>
                </div>

                <button type="submit" class="form-btn">Comentar</button>

                @error('media')
                  <p style="margin-top:10px;color:#b00020;font-weight:700;">{{ $message }}</p>
                @enderror
              </form>
            </div>

          </div>
        @endforeach

      </section>
    </main>
  </div>

  <footer class="footer-links">
    <div><strong>COMPANY</strong><br>How it Works<br>Pricing</div>
    <div><strong>RESOURCES</strong><br>Blog Post Name<br>Articles</div>
    <div><strong>ABOUT</strong><br>Terms & Conditions<br>Privacy Policy</div>
  </footer>

  <button class="chat-btn" onclick="openChat()">💬</button>

  <script>
    function openChat() {
      alert("Aquí se abriría el chat de asistencia o chatbot.");
    }

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

    function openModal(postId) {
      document.getElementById('deleteModal').style.display = 'flex';
      document.getElementById('deleteForm').action = '/posts/' + postId;
    }
    function closeModal() {
      document.getElementById('deleteModal').style.display = 'none';
    }

    function openCommentModal(commentId) {
      document.getElementById('deleteCommentModal').style.display = 'flex';
      document.getElementById('deleteCommentForm').action = '/comments/' + commentId;
    }
    function closeCommentModal() {
      document.getElementById('deleteCommentModal').style.display = 'none';
    }

    // ✅ Uploaders MindBloom: nombre de archivo + preview + quitar (solo UI)
    function wireUploaders() {
      document.querySelectorAll('[data-uploader]').forEach(uploader => {
        const input = uploader.querySelector('.mb-file');
        const chip = uploader.querySelector('[data-filechip]');
        const clearBtn = uploader.querySelector('[data-clear]');
        const preview = uploader.querySelector('[data-preview]');

        if (!input || !chip || !clearBtn || !preview) return;

        const reset = () => {
          input.value = '';
          chip.textContent = 'Sin archivo';
          clearBtn.style.display = 'none';
          preview.style.display = 'none';
          preview.innerHTML = '';
        };

        const showPreview = (file) => {
          preview.innerHTML = '';
          const url = URL.createObjectURL(file);

          if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = url;
            img.onload = () => URL.revokeObjectURL(url);
            preview.appendChild(img);
            preview.style.display = 'block';
            return;
          }

          if (file.type.startsWith('video/')) {
            const vid = document.createElement('video');
            vid.controls = true;
            vid.preload = 'metadata';
            vid.src = url;
            vid.onloadedmetadata = () => URL.revokeObjectURL(url);
            preview.appendChild(vid);
            preview.style.display = 'block';
            return;
          }

          preview.style.display = 'none';
        };

        input.addEventListener('change', () => {
          const file = input.files && input.files[0];
          if (!file) return reset();

          chip.textContent = file.name;
          clearBtn.style.display = 'inline-flex';
          showPreview(file);
        });

        clearBtn.addEventListener('click', reset);
      });
    }

    document.addEventListener('DOMContentLoaded', wireUploaders);
  </script>

  <div id="deleteModal" class="modal">
    <div class="modal-content">
      <h3>¿Eliminar publicación?</h3>
      <p>Esta acción no se puede deshacer.</p>

      <form id="deleteForm" method="POST">
        @csrf
        @method('DELETE')
        <button type="submit" class="confirm-delete">Sí, eliminar</button>
        <button type="button" class="cancel-delete" onclick="closeModal()">Cancelar</button>
      </form>
    </div>
  </div>

  <div id="deleteCommentModal" class="modal">
    <div class="modal-content">
      <h3>¿Eliminar comentario?</h3>
      <p>Esta acción no se puede deshacer.</p>

      <form id="deleteCommentForm" method="POST">
        @csrf
        @method('DELETE')
        <button type="submit" class="confirm-delete">Sí, eliminar</button>
        <button type="button" class="cancel-delete" onclick="closeCommentModal()">Cancelar</button>
      </form>
    </div>
  </div>

</body>
</html>