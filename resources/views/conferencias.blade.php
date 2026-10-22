<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Conferencias - MindBloom</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/conferencias.css">
</head>
<body>
  <header>
    <h2>MindBloom</h2>
    <nav>
        <ul>
            <li><a href="/">Inicio</a></li>
            <li><a href="/comunidad">Comunidad</a></li>
            <li><a href="/conferencias">Conferencias</a></li>
            <li><a href="/citas">Citas</a></li>
            <li><a href="/perfil"><span title="Perfil">👤</span></a></li>
        </ul>
    </nav>
  </header>

  <div class="sidebar">
    <img src="/img/sidebar-banner.png" alt="Imagen lateral" style="width: 100%; border-radius: 8px;">
  </div>

  <main>
    <div class="calendar-container">
      <table>
        <thead>
          <tr>
            <th colspan="7">JUNIO 2025</th>
          </tr>
          <tr>
            <th>L</th><th>M</th><th>M</th><th>J</th><th>V</th><th>S</th><th>D</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>2</td><td>3</td><td>4</td><td>5</td><td>6</td><td>7</td><td>8</td></tr>
          <tr><td>9</td><td>10</td><td>11</td><td>12</td><td class="active">13</td><td>14</td><td>15</td></tr>
          <tr><td>16</td><td>17</td><td>18</td><td>19</td><td>20</td><td>21</td><td>22</td></tr>
          <tr><td>23</td><td>24</td><td>25</td><td>26</td><td>27</td><td>28</td><td>29</td></tr>
          <tr><td>30</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        </tbody>
      </table>
    </div>
  </main>

  <aside>
    <div class="widget">
      <strong>CONFERENCIAS</strong>
      <p>Selecciona una fecha del calendario para ver conferencias disponibles.</p>
    </div>
    <div class="widget">
      <strong>CITAS PENDIENTES</strong>
      <p>No tienes citas programadas para hoy.</p>
    </div>
    <div class="video-box">
      Aquí podría mostrarse un video de presentación o conferencia activa.
    </div>
  </aside>

  <footer>
    <div>
      <strong>COMPANY</strong><br>
      How it Works<br>
      Pricing
    </div>
    <div>
      <strong>RESOURCES</strong><br>
      Blog Post List<br>
      Articles
    </div>
    <div>
      <strong>ABOUT</strong><br>
      Terms & Conditions<br>
      Privacy Policy
    </div>
  </footer>

  <button class="chat-btn" onclick="openChat()">💬</button>

  <script>
    function openChat() {
      alert('Aquí se abriría el chat de asistencia.');
    }
  </script>
</body>
</html>
