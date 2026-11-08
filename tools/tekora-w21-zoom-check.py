"""Check real Chromium page zoom in a fresh temporary profile, never a user profile."""
import argparse
import json
import shutil
import tempfile
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='http://127.0.0.1:8017')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--executable', default=shutil.which('chromium'))
    args = parser.parse_args()
    origin = urlsplit(args.base_url)
    assert origin.scheme == 'http' and origin.hostname == '127.0.0.1' and not origin.path
    args.output.mkdir(parents=True, exist_ok=False)
    result = {'method': 'Chromium settingsPrivate.setDefaultZoom in an automatically deleted temporary profile; native page zoom, no CSS zoom or viewport emulation', 'headless': True, 'checks': [], 'page_errors': [], 'failed_resources': []}
    with tempfile.TemporaryDirectory(prefix='tekora-w21-zoom-') as profile:
        with sync_playwright() as p:
            context = p.chromium.launch_persistent_context(profile, executable_path=args.executable, headless=True, no_viewport=True, args=['--window-size=1440,1000', '--disable-dev-shm-usage'])
            settings = context.pages[0]
            settings.goto('chrome://settings/appearance', wait_until='domcontentloaded')
            settings.wait_for_timeout(400)
            assert settings.evaluate('typeof chrome.settingsPrivate?.setDefaultZoom') == 'function', 'Native zoom API unavailable in this Chromium build; use manual browser review.'
            page = context.new_page()
            page.on('pageerror', lambda e: result['page_errors'].append(str(e)))
            page.on('requestfailed', lambda r: result['failed_resources'].append(r.url))
            for zoom in (1, 2, 1):
                settings.evaluate('(zoom) => new Promise(resolve => chrome.settingsPrivate.setDefaultZoom(zoom, resolve))', zoom)
                for role, path in [('client', '/cuenta/cliente'), ('provider', '/cuenta/prestador/dashboard')]:
                    response = page.goto(args.base_url + path, wait_until='networkidle')
                    assert response.header_value('x-tekora-preview') == 'synthetic-static-memory-export'
                    page.wait_for_timeout(700)
                    metrics = page.evaluate('({css_width:innerWidth, outer_width:outerWidth, dpr:devicePixelRatio, scroll_width:document.documentElement.scrollWidth})')
                    assert metrics['outer_width'] == 1440 and metrics['css_width'] == 1440 / zoom and metrics['dpr'] == zoom, metrics
                    assert metrics['scroll_width'] <= metrics['css_width'] + 1
                    tabs = page.locator('[role=tab]')
                    for index in (tabs.count()-1, 0):
                        tabs.nth(index).click()
                        page.wait_for_timeout(650)
                        assert page.evaluate('''() => {
                            const track=document.querySelector('[data-dashboard-track]'), pill=track.querySelector('[data-dashboard-pill]');
                            const parts=getComputedStyle(pill).clipPath.split(' round')[0].slice(6).trim().split(/\\s+/).map(parseFloat);
                            const [t,r=t,b=t,l=r]=parts, rect=track.getBoundingClientRect();
                            const tab=track.querySelector('[aria-selected=true]').getBoundingClientRect(), viewport=track.parentElement.getBoundingClientRect();
                            return Math.abs(l-(tab.left-rect.left))<1 && Math.abs(r-(rect.right-tab.right))<1 && tab.left>=viewport.left-1 && tab.right<=viewport.right+1 && document.documentElement.scrollWidth<=innerWidth+1;
                        }''')
                    result['checks'].append({'zoom_percent': zoom*100, 'role': role, **metrics, 'capsule_both_directions_aligned': True})
                    if zoom == 2:
                        page.screenshot(path=str(args.output / f'{role}-native-zoom-200.png'), full_page=True)
            context.close()
    assert not result['page_errors'] and not result['failed_resources']
    (args.output / 'native-zoom.json').write_text(json.dumps(result, indent=2)+'\n')
    print(json.dumps({'native_zoom_200_percent': True, 'checks':len(result['checks'])}))


if __name__ == '__main__':
    main()
