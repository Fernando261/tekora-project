<x-tekora-layout :title="$title.' · Tekora'" page="access">
  <article class="account-card trust-policy">
    <h1>{{ $title }}</h1>
    <p>Versión {{ \App\Support\LegalPolicies::VERSION }}. Texto de presentación sujeto a la identificación del responsable.</p>
    <x-flash-messages />
    @switch($policy)
      @case('privacidad')
        <h2>Identidad y contacto del responsable</h2>
        <p>Pendientes de completar antes de la publicación: nombre o razón social del responsable, domicilio, correo para privacidad y canal para solicitudes ARCO.</p>
        <h2>Datos y finalidades</h2>
        <p>Usamos los datos de cuenta y contacto para autenticar, recuperar el acceso y prestar soporte. Usamos los perfiles, solicitudes, conversaciones, cotizaciones, reservas y reseñas para gestionar las contrataciones. Conservamos el historial necesario para seguridad, atención de controversias y obligaciones aplicables.</p>
        <p>El perfil de prestador y sus servicios publicados se muestran en Web y API. El municipio corresponde al lugar donde se ofrece el servicio. La cédula declarada y los certificados se usan para revisión profesional. El número de cédula se publica únicamente tras aprobación y autorización expresa del titular. Los certificados se mantienen privados, con acceso del titular y del personal autorizado.</p>
        <p>Tekora registra una selección simulada de checkout. El sistema no solicita números de tarjeta reales ni procesa cargos. La exposición escolar usa cuentas y documentos sintéticos.</p>
        <h2>Derechos y transferencias</h2>
        <p>El titular podrá solicitar acceso, rectificación, cancelación u oposición (ARCO), revocar su consentimiento o limitar la divulgación. La solicitud deberá identificar al titular, el derecho que desea ejercer y un medio de respuesta. El canal, el procedimiento operativo y los plazos de conservación requieren confirmación del responsable. Cualquier transferencia distinta de las autorizadas por la ley deberá informarse y contar con consentimiento cuando proceda.</p>
        <p>Las modificaciones se informarán en esta página y mediante el mecanismo de aviso aplicable. Marco de referencia: <a href="https://www.diputados.gob.mx/LeyesBiblio/pdf/LFPDPPP.pdf" rel="noopener noreferrer">Ley Federal de Protección de Datos Personales en Posesión de los Particulares</a>.</p>
        @break
      @case('terminos')
        <h2>Cuenta y contratación</h2>
        <p>Tekora facilita el encuentro de clientes y prestadores. Protege tu cuenta, usa información veraz y respeta los derechos de las personas participantes. Los mensajes, acuerdos y reseñas deben corresponder al servicio y respetar las reglas aplicables.</p>
        <p>La cotización aceptada conserva su importe en MXN y muestra la comisión del 15 % incluida. La retención del horario dura hasta 900 segundos. La selección de tarjeta o efectivo de prueba se registra por separado. La reserva se confirma con la acción correspondiente antes de que venza la retención.</p>
        <p>La experiencia de presentación solo registra resultados simulados. Conservas los derechos y recursos que otorgue la legislación aplicable.</p>
        @auth('web')
          @if(\App\Support\TrustSchema::available())
            <form class="account-form" method="POST" action="{{ route('legal.accept') }}">
              @csrf <x-terms-acceptance />
              <button type="submit" class="account-button">Registrar aceptación</button>
            </form>
          @endif
        @endauth
        @break
      @case('cookies')
        <h2>Cookies esenciales</h2>
        <p>Mantienen la sesión, protegen formularios y conservan tu preferencia. Siguen operando si rechazas las cookies opcionales.</p>
        <h2>Preferencias opcionales</h2>
        <p>Esta versión no incorpora rastreadores opcionales. Tu elección se guarda en este navegador durante hasta 180 días y puede cambiarse aquí en cualquier momento.</p>
        <p>Preferencia actual: {{ match (request()->cookie('tekora_preferences_v1')) {'accepted' => 'Opcionales aceptadas', 'rejected' => 'Solo esenciales', default => 'Sin preferencia registrada'} }}.</p>
        <form class="trust-links" method="POST" action="{{ route('legal.cookies') }}">
          @csrf
          <button type="submit" class="account-button" name="optional" value="rejected">Solo esenciales</button>
          <button type="submit" class="account-button secondary" name="optional" value="accepted">Aceptar opcionales</button>
        </form>
        @break
      @case('cancelaciones')
        <h2>Solicitudes, retenciones y reservas</h2>
        <p>Consulta el detalle de la contratación para ver las acciones habilitadas según tu rol, el estado y el horario. Una retención vencida deja de apartar el horario. La cancelación no equivale a una devolución de dinero.</p>
        <p>Impide confusiones antes de reservar. Aclara el alcance y los cambios de horario desde la conversación de la solicitud. El canal formal de atención queda pendiente de identificación por el responsable.</p>
        @break
      @case('reembolsos')
        <h2>Alcance de la presentación</h2>
        <p>El checkout es simulado y no mueve dinero. Su comprobante es interno. No hay cargos que devolver ni gestión automática de reembolsos en esta versión.</p>
        <p>Si en el futuro se incorporan cobros, se comunicarán sus condiciones, medios de atención y procedimientos conforme a las leyes aplicables. Se conservan los derechos legales del consumidor.</p>
        @break
      @case('prestadores')
        <h2>Información profesional y cobertura</h2>
        <p>Describe tu oferta con precisión, indica su modalidad y municipio cuando corresponda y presenta documentación propia y vigente. Declara que no tienes cédula únicamente cuando el servicio no la requiera.</p>
        <p>La insignia Profesional Tekora indica revisión documental por personal autorizado. Puede retirarse o volver a revisión si cambia la documentación. Cada prestador sigue siendo responsable de contar con las autorizaciones y requisitos aplicables a su actividad.</p>
        <p>Responde a solicitudes y mantén al día tus horarios. Registra la recepción del efectivo de prueba únicamente desde una contratación autorizada.</p>
        @break
    @endswitch
  </article>
</x-tekora-layout>
