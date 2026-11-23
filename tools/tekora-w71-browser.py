"""W7.1: real Chromium web journey on a NEW private B3.2 fixture only.
Requires Python Playwright, Chromium and the project's locked dependencies/build.
No proxy, forwarding, production data, system-clock changes or .env files.
"""

import argparse
import base64
import asyncio
import datetime
import json
import os
import pathlib
import re
import sqlite3
import secrets
import socket
import subprocess
import tempfile
from zoneinfo import ZoneInfo
from playwright.async_api import async_playwright

ROOT = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--php", default="php")
parser.add_argument("--chromium", default="/usr/bin/chromium")
parser.add_argument("--evidence", required=True, type=pathlib.Path)
args = parser.parse_args()
out = args.evidence.resolve()
out.mkdir(parents=True, exist_ok=False)
private = pathlib.Path(tempfile.mkdtemp(prefix="tekora-w71-test-"))
os.chmod(private, 0o700)
if (ROOT / ".env").exists() or (ROOT / "bootstrap/cache/config.php").exists():
    parser.error("Use una copia nueva sin .env ni caché de configuración.")
if (
    not (ROOT / "public/build/manifest.json").is_file()
    or (ROOT / "public/hot").exists()
):
    parser.error("Ejecuta el build Vite en una copia aislada sin servidor de assets.")
php_temp = pathlib.Path(
    subprocess.check_output(
        [args.php, "-r", "echo realpath(sys_get_temp_dir());"], text=True
    )
).resolve()
env = os.environ.copy()
env.pop("PHP_CLI_SERVER_WORKERS", None)
env.update(
    APP_ENV="testing",
    APP_DEBUG="false",
    APP_KEY="base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
    DB_CONNECTION="sqlite",
    DB_DATABASE=":memory:",
    DB_URL="",
    CACHE_STORE="array",
    SESSION_DRIVER="file",
    SESSION_LIFETIME="10080",
    QUEUE_CONNECTION="sync",
    MAIL_MAILER="array",
    APP_CONFIG_CACHE="bootstrap/cache/w71-" + secrets.token_hex(12) + ".php",
)
fixture = json.loads(
    subprocess.check_output(
        [args.php, str(ROOT / "tools/tekora-b32-fixture.php")],
        cwd=ROOT,
        env=env,
        text=True,
    )
)
db = pathlib.Path(fixture["database"]).resolve()
if (
    db.parent.parent != php_temp
    or not re.fullmatch(r"tekora-b32-[0-9a-f]{24}", db.parent.name)
    or db.name != "tekora.sqlite"
    or not (db.parent / ".tekora-b32-synthetic").is_file()
):
    raise RuntimeError("El exportador no devolvió una fixture privada válida.")
now = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(seconds=1)


def clock(value):
    (private / "clock-next.json").write_text(json.dumps({"now": value}))
    (private / "clock-next.json").replace(private / "clock.json")


clock(now.isoformat())
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
base = f"http://127.0.0.1:{port}"
env.update(
    APP_ENV="local",
    APP_URL=base,
    DB_DATABASE=str(db),
    W71_PRIVATE=str(private),
    W71_CHECKOUT=str(ROOT),
)
router = private / "router.php"
router.write_text("""<?php
$root=realpath(getenv('W71_CHECKOUT') ?: '');$private=realpath(getenv('W71_PRIVATE') ?: '');
if(PHP_SAPI!=='cli-server'||!$root||!$private||!str_starts_with(basename($private),'tekora-w71-test-')||getenv('APP_ENV')!=='local'||getenv('DB_CONNECTION')!=='sqlite'||getenv('DB_URL')||is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')||!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(503);exit('BLOCKED');}
$db=realpath(getenv('DB_DATABASE')?:'');$temp=realpath(sys_get_temp_dir());
if(!$db||dirname(dirname($db))!==$temp||!str_starts_with(basename(dirname($db)),'tekora-b32-')||!is_file(dirname($db).'/.tekora-b32-synthetic')){http_response_code(503);exit('BLOCKED');}
$path=urldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/');
$file=realpath($root.'/public'.$path);if($path!=='/'&&$file&&str_starts_with($file,$root.'/public/')&&is_file($file))return false;
require $root.'/vendor/autoload.php';$now=json_decode(file_get_contents($private.'/clock.json'),true,flags:JSON_THROW_ON_ERROR)['now'];
Illuminate\\Support\\Carbon::setTestNow($now);Carbon\\CarbonImmutable::setTestNow($now);
require $root.'/public/index.php';
""")
log = (private / "server-private.log").open("w")
server = subprocess.Popen(
    [args.php, "-S", f"127.0.0.1:{port}", "-t", str(ROOT / "public"), str(router)],
    cwd=ROOT,
    env=env,
    stdout=log,
    stderr=log,
)


async def run():
    errors = []
    console_errors = []
    http = []
    shots = []
    facts = {}
    flow = []
    async with async_playwright() as p:
        browser = await p.chromium.launch(
            executable_path=args.chromium, headless=True, args=["--no-sandbox"]
        )
        contexts = [
            await browser.new_context(
                viewport={"width": 1440, "height": 1000}, reduced_motion="reduce"
            )
            for _ in range(2)
        ]
        cp, pp = [await c.new_page() for c in contexts]

        def observe(page):
            page.set_default_timeout(20000)
            page.on("pageerror", lambda e: errors.append(str(e)))
            page.on(
                "console",
                lambda msg: (
                    console_errors.append(msg.text) if msg.type == "error" else None
                ),
            )
            page.on(
                "response",
                lambda r: (
                    http.append({"status": r.status, "path": r.url.split("?")[0]})
                    if r.status >= 400
                    else None
                ),
            )

        for page in [cp, pp]:
            observe(page)

        async def nav(page, path):
            response = await page.goto(path if path.startswith("http") else base + path)
            assert response.status == 200, (path, response.status)
            await page.wait_for_load_state("networkidle")

        async def press(page, name):
            await page.get_by_role("button", name=name, exact=True).click()
            await page.wait_for_load_state("networkidle")

        async def login(page, email, password):
            await nav(page, "/login")
            await page.locator("[name=email]").fill(email)
            await page.locator("[name=password]").fill(password)
            await press(page, "Entrar a mi cuenta")

        async def shot(page, name, width):
            await page.set_viewport_size({"width": width, "height": 1000})
            await page.wait_for_timeout(100)
            await page.evaluate("window.scrollTo(0, 0)")
            dimensions = await page.evaluate(
                "({width:innerWidth,scroll:document.documentElement.scrollWidth})"
            )
            assert dimensions["scroll"] <= width, (name, dimensions)
            await page.screenshot(
                path=str(out / f"{name}-{width}.png"),
                full_page=True,
                animations="disabled",
            )
            shots.append({"name": name, **dimensions})

        async def widths(page, name):
            for width in [320, 375, 430, 768, 1024, 1440, 2048]:
                await shot(page, name, width)

        async def publish_and_request(title, capture_catalog=False):
            await nav(pp, "/cuenta/servicios/crear")
            await pp.locator("#title").fill(title)
            await pp.locator("#description").fill(
                "Asesoría digital ficticia para demostrar contratación, acuerdo y seguimiento, sin pagos reales."
            )
            await pp.locator("#service_category_id").select_option(index=1)
            await pp.locator("#modality").select_option("remote")
            await pp.locator("#status").select_option("published")
            await pp.locator("#price_mxn").fill("1000.00")
            await press(pp, "Guardar servicio")
            await nav(cp, "/servicios")
            if capture_catalog:
                await widths(cp, "01-catalogo")
            await cp.get_by_role("link", name=title, exact=True).click()
            await cp.get_by_role("link", name="Solicitar servicio", exact=False).click()
            await cp.locator("#message").fill(
                "Necesito una sesión ficticia para revisar mi proyecto de demostración."
            )
            await press(cp, "Confirmar y enviar solicitud")
            return cp.url.split("#")[0]

        async def propose_and_hold(inquiry, time):
            await cp.locator(".cj-next-action a.account-button").click()
            await cp.locator("#reservation-quote").select_option(index=1)
            await cp.locator("#reservation-date").fill(date)
            await cp.locator("#reservation-time").fill(time)
            await press(cp, "Enviar propuesta de horario")
            assert cp.url.split("#")[0] == inquiry, await cp.locator("main").inner_text()
            await nav(pp, inquiry)
            await press(pp, "Aceptar propuesta y retener 15 minutos")

        provider_email = "prestador-w71@example.test"
        provider_password = secrets.token_urlsafe(24)
        await nav(pp, "/registro")
        await widths(pp, "00-registro")
        await pp.locator("#name").fill("Alex · Prestador W7.1")
        await pp.locator("#age").fill("30")
        await pp.locator("#gender").select_option("Prefiero no decirlo")
        await pp.locator('[name=intent][value=client]').check()
        await pp.locator("#email").fill(provider_email)
        await pp.locator("#password").fill(provider_password)
        await pp.locator("#password_confirmation").fill("confirmacion-distinta")
        await press(pp, "Crear mi cuenta única")
        await pp.locator("#password_confirmation-error").wait_for()
        await shot(pp, "00-registro-validacion", 375)
        await pp.locator("#password").fill(provider_password)
        await pp.locator("#password_confirmation").fill(provider_password)
        await press(pp, "Crear mi cuenta única")
        assert pp.url.split("?")[0] == base + "/login"
        await login(pp, provider_email, provider_password)
        await pp.get_by_role("link", name="Convertirme en prestador", exact=True).click()
        await press(pp, "Activar Prestador y completar mi perfil")
        await pp.locator("#display_name").fill("Estudio Digital W7.1")
        await pp.locator("#headline").fill("Asesoría y soporte digital")
        await pp.locator("#bio").fill("Perfil sintético para el recorrido de presentación.")
        await press(pp, "Crear mi perfil")
        await pp.get_by_role("button", name="Guardar cambios").wait_for()
        await widths(pp, "00-perfil-profesional")
        await pp.get_by_role("link", name="Panel del prestador", exact=True).click()
        await pp.get_by_role("heading", name="Estudio Digital W7.1").wait_for()
        await nav(pp, "/cuenta/disponibilidad")
        for day in await pp.locator(".availability-day").all():
            await day.locator('input[type=time]').nth(0).fill("08:00")
            await day.locator('input[type=time]').nth(1).fill("18:00")
        await press(pp, "Guardar disponibilidad")
        await pp.get_by_text("Disponibilidad informativa guardada.", exact=True).wait_for()
        await widths(pp, "00-disponibilidad")
        await login(cp, fixture["client_email"], fixture["client_password"])
        facts["registration_activation_profile_and_availability"] = True
        date = (
            (now + datetime.timedelta(days=4))
            .astimezone(ZoneInfo("America/Mexico_City"))
            .date()
            .isoformat()
        )

        inquiry = await publish_and_request("Sesión digital W7.1 · datos sintéticos", capture_catalog=True)
        await widths(cp, "02-solicitud-cliente")
        await nav(pp, inquiry)
        await widths(pp, "03-solicitud-prestador")
        await cp.locator("#message-body").fill("Mensaje de prueba del cliente.")
        await press(cp, "Enviar mensaje")
        await pp.locator("#message-body").fill("Respuesta de prueba del prestador.")
        await press(pp, "Enviar mensaje")
        await press(pp, "Aceptar solicitud")
        await nav(cp, inquiry)
        await press(cp, "Consultar nueva cotización")
        await cp.locator("[name=acknowledge]").check()
        await widths(cp, "04-cotizacion")
        await press(cp, "Aceptar cotización")
        await propose_and_hold(inquiry, "12:00")
        await widths(pp, "05-hold-prestador")
        await nav(cp, inquiry)
        await widths(cp, "06-hold-cliente")
        facts["next_label_before_choice"] = await cp.locator(
            "#journey-next-title"
        ).inner_text()
        facts["next_target_before_choice"] = await cp.locator(
            ".cj-next-action a.account-button"
        ).get_attribute("href")
        assert facts["next_target_before_choice"].endswith("/checkout")
        await cp.locator(".cj-next-action a.account-button").click()
        await cp.get_by_role("radio", name="Efectivo").check()
        assert await cp.locator("#b32-number").is_disabled()
        assert not await cp.locator("[data-b32-cash-fallback]").is_visible()
        await cp.get_by_role("radio", name="Tarjeta").check()
        await widths(cp, "07-checkout-inicial")
        # Two negative outcomes and recovery use ordinary server forms, not JS success.
        for number, expected in [
            ("4000000000000002", "Tarjeta de prueba rechazada"),
            ("4000000000000119", "Fallo temporal de la tarjeta de prueba"),
        ]:
            await cp.locator("#b32-number").select_option(number)
            await cp.locator("[data-b32-submit]").click()
            await cp.wait_for_load_state("networkidle")
            assert await cp.get_by_text(expected, exact=True).count() > 0
            await shot(
                cp, "08-" + ("rechazo" if number.endswith("2") else "temporal"), 375
            )
        await cp.locator("#b32-number").select_option("4242424242424242")
        await cp.locator("[data-b32-submit]").click()
        await cp.wait_for_load_state("networkidle")
        facts["result_title"] = await cp.locator("#b32-result-title").inner_text()
        await widths(cp, "09-tarjeta-aprobada")
        assert facts["result_title"] == "Tarjeta de prueba aprobada"
        assert (
            await cp.locator("[data-b32-booking-status]").inner_text()
            == "Reserva pendiente de confirmación"
        )
        await cp.locator("#b32-result-title:focus").wait_for()
        (out / "checkout-observation.json").write_text(
            json.dumps(facts, ensure_ascii=False, indent=2)
        )
        await cp.reload()
        assert (
            await cp.locator("#b32-result-title").inner_text() == facts["result_title"]
        )
        await nav(cp, inquiry)
        assert await cp.locator("[data-hold-confirm]").count() == 1
        await press(cp, "Confirmar definitivamente mi reserva")
        await widths(cp, "10-reserva-confirmada")
        assert await cp.get_by_text("Reserva confirmada", exact=True).count() > 0
        await nav(pp, inquiry)
        facts["no_early_completion"] = (
            await pp.get_by_role(
                "button", name="Marcar como realizado", exact=True
            ).count()
            == 0
        )
        assert facts["no_early_completion"]
        assert (
            await pp.get_by_role(
                "button", name="Finalizar solicitud", exact=True
            ).count()
            == 0
        )
        cash_inquiry = await publish_and_request("Segunda sesión · efectivo de prueba")
        await nav(pp, cash_inquiry)
        await press(pp, "Aceptar solicitud")
        await nav(cp, cash_inquiry)
        await press(cp, "Consultar nueva cotización")
        await cp.locator("[name=acknowledge]").check()
        await press(cp, "Aceptar cotización")
        await propose_and_hold(cash_inquiry, "15:00")
        await nav(cp, cash_inquiry)
        cash_url = await cp.locator(".cj-next-action a.account-button").get_attribute("href")
        assert cash_url.endswith("/checkout")

        # Reuse both signed-in identities in separate contexts with scripts disabled.
        no_js = [
            await browser.new_context(
                storage_state=await ctx.storage_state(),
                java_script_enabled=False,
                viewport={"width": 375, "height": 1000},
            )
            for ctx in contexts
        ]
        contexts.extend(no_js)
        cash_client, cash_provider = [await ctx.new_page() for ctx in no_js]
        observe(cash_client)
        observe(cash_provider)
        await nav(cash_client, cash_url)
        assert not await cash_client.get_by_role("radio", name="Efectivo").count()
        await press(cash_client, "Registrar efectivo de prueba")
        await cash_client.get_by_role(
            "heading", name="Efectivo pendiente de recepción"
        ).wait_for()
        await shot(cash_client, "13-efectivo-pendiente", 375)
        await nav(cash_provider, cash_inquiry)
        await cash_provider.get_by_role("link", name="Consultar recepción de efectivo").click()
        await cash_provider.get_by_role("heading", name="Pendiente de recepción").wait_for()
        await widths(cash_provider, "14-efectivo-prestador")
        await press(cash_provider, "Registrar recepción de efectivo")
        await cash_provider.get_by_role(
            "heading", name="Recepción de prueba registrada"
        ).wait_for()
        await nav(cp, cash_url)
        assert (
            await cp.get_by_text(
                "Efectivo recibido por el prestador", exact=False
            ).count()
            > 0
        )
        await shot(cp, "15-efectivo-recibido", 375)
        facts["cash_pending_and_received_without_javascript"] = True
        # Only this explicit browser-test process reads this synthetic test clock.
        ends = (
            datetime.datetime.fromisoformat(date + "T13:00:00")
            .replace(tzinfo=ZoneInfo("America/Mexico_City"))
            .astimezone(datetime.timezone.utc)
        )
        clock((now + datetime.timedelta(seconds=901)).isoformat())
        await nav(cp, cash_url)
        assert (
            await cp.locator("[data-b32-booking-status]").inner_text()
            == "Retención vencida"
        )
        assert await cp.locator("[data-b32-form]").count() == 0
        await shot(cp, "16-retencion-vencida", 375)
        facts["expired_checkout_keeps_history"] = True
        clock((ends + datetime.timedelta(seconds=1)).isoformat())
        await nav(pp, inquiry)
        if "/login" in pp.url:
            await login(pp, provider_email, provider_password)
            await nav(pp, inquiry)
        await nav(cp, inquiry)
        if "/login" in cp.url:
            await login(cp, fixture["client_email"], fixture["client_password"])
            await nav(cp, inquiry)
        if not await pp.get_by_role(
            "button", name="Marcar como realizado", exact=True
        ).count():
            (out / "completion-diagnostic.txt").write_text(
                await pp.locator("main").inner_text()
            )
            raise AssertionError(
                "The provider completion button was not available after the synthetic end time."
            )
        await press(pp, "Marcar como realizado")
        await press(pp, "Finalizar solicitud")
        await nav(cp, inquiry)
        await cp.locator(".cj-next-action a.account-button").click()
        await cp.locator('[name=rating][value="5"]').check()
        await cp.locator("#review-comment").fill(
            "Recorrido sintético W7.1 completado; no se realizaron cobros."
        )
        await press(cp, "Publicar reseña")
        await nav(cp, inquiry)
        await widths(cp, "11-cierre-resena-cliente")
        await nav(pp, inquiry)
        await widths(pp, "12-cierre-prestador")
        assert (
            await cp.get_by_text("Mensaje de prueba del cliente.", exact=True).count()
            > 0
        )
        assert (
            await pp.get_by_text(
                "Respuesta de prueba del prestador.", exact=True
            ).count()
            > 0
        )
        assert (
            await cp.get_by_role(
                "link", name="Consulta tu reseña publicada", exact=False
            ).count()
            > 0
        )
        await cp.keyboard.press("Tab")
        facts["keyboard_focus"] = await cp.evaluate(
            'document.activeElement.tagName!=="BODY"'
        )
        facts["reduced_motion"] = await cp.evaluate(
            'matchMedia("(prefers-reduced-motion: reduce)").matches'
        )
        await nav(pp, "/cuenta/prestador/dashboard")
        await pp.get_by_text("1 completadas en 30 días.", exact=False).wait_for()
        await widths(pp, "17-dashboard-prestador")
        await nav(cp, "/cuenta/cliente")
        await widths(cp, "18-dashboard-cliente")
        await nav(cash_provider, "/cuenta/prestador/dashboard")
        if "/login" in cash_provider.url:
            await login(cash_provider, provider_email, provider_password)
            await nav(cash_provider, "/cuenta/prestador/dashboard")
        await cash_provider.locator("#dashboard-period").select_option("7")
        await press(cash_provider, "Aplicar")
        await cash_provider.get_by_text("1 completadas en 7 días.", exact=False).wait_for()
        await cash_provider.get_by_text("Consultar datos por día", exact=True).click()
        await cash_provider.get_by_role("table", name="Reservas completadas por fecha local").wait_for()
        await shot(cash_provider, "19-dashboard-sin-javascript", 375)
        facts["dashboard_updated_and_filter_without_javascript"] = True
        flow = [
            "registro, login, activacion, perfil y disponibilidad",
            "publicacion web",
            "catalogo",
            "solicitud nueva",
            "mensajes de ambos actores",
            "aceptacion prestador",
            "cotizacion aceptada",
            "propuesta horario",
            "hold",
            "rechazo",
            "fallo temporal",
            "tarjeta prueba aprobada",
            "confirmacion B2 explicita",
            "realizacion explicita tras el horario",
            "cierre",
            "resena",
            "historial de ambos roles",
        ]
        (out / "results.json").write_text(
            json.dumps(
                {
                    "facts": facts,
                    "flow": flow,
                    "screenshots": shots,
                    "page_errors": errors,
                    "console_errors": console_errors,
                    "unexpected_http": http,
                    "separate_browser_contexts": True,
                    "synthetic_clock_in_test_router_only": True,
                },
                ensure_ascii=False,
                indent=2,
            )
        )
        assert facts["keyboard_focus"] and facts["reduced_motion"]
        assert not errors and not http and not console_errors, {
            "page_errors": errors,
            "console_errors": console_errors,
            "http": http,
        }
        for c in contexts:
            await c.close()
        await browser.close()
    # Observe persisted results through a separate SQLite reader after HTTP writes.
    connection = sqlite3.connect("file:" + db.as_posix() + "?mode=ro", uri=True)
    connection.execute("PRAGMA foreign_keys=ON")
    integrity = connection.execute("PRAGMA integrity_check").fetchone()[0]
    fk = connection.execute("PRAGMA foreign_key_check").fetchall()
    complete = connection.execute("""
        SELECT r.status, i.status, q.total_cents, q.commission_cents, q.provider_cents,
               h.expires_at_us - h.accepted_at_us, a.status, v.rating
        FROM service_reservations r
        JOIN service_inquiries i ON i.id=r.service_inquiry_id
        JOIN service_quotes q ON q.id=r.quote_id
        JOIN reservation_holds h ON h.service_reservation_id=r.id
        JOIN synthetic_checkout_attempts a ON a.reservation_id=r.id
        JOIN service_reviews v ON v.service_reservation_id=r.id
    """).fetchall()
    assert integrity == "ok" and fk == []
    assert complete == [
        ("completed", "completed", 100000, 15000, 85000, 900000000, "test_approved", 5)
    ], complete
    (out / "sqlite.json").write_text(
        json.dumps(
            {
                "integrity_check": integrity,
                "foreign_key_check": fk,
                "foreign_keys": connection.execute("PRAGMA foreign_keys").fetchone()[0],
                "final_reservation_and_inquiry": "completed",
                "agreed_amount_cents": 100000,
                "commission_cents": 15000,
                "provider_cents": 85000,
                "hold_seconds": 900,
                "review_rating": 5,
                "synthetic_card_outcome": "test_approved",
            },
            indent=2,
        )
    )
    connection.close()


try:
    asyncio.run(run())
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
    # Remove explicit files in the two new directories, after the server exits.
    for name in (
        "tekora.sqlite",
        "tekora.sqlite-wal",
        "tekora.sqlite-shm",
        "tekora.sqlite-journal",
        ".tekora-b32-synthetic",
    ):
        (db.parent / name).unlink(missing_ok=True)
    db.parent.rmdir()
    for name in ("clock.json", "clock-next.json", "router.php", "server-private.log"):
        (private / name).unlink(missing_ok=True)
    private.rmdir()
    print("Evidence:", out, "| loopback server stopped and private fixture removed.")
