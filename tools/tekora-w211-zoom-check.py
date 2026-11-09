"""Native Chromium 100/200/100 page zoom in an automatically deleted profile."""
import argparse
import json
import shutil
import tempfile
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--base-url', default='http://127.0.0.1:8017')
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--executable', default=shutil.which('chromium'))
    args = p.parse_args()
    url = urlsplit(args.base_url)
    assert url.scheme == 'http' and url.hostname == '127.0.0.1' and not url.path
    args.output.mkdir(parents=True, exist_ok=False)
    result = {'method':'chrome.settingsPrivate.setDefaultZoom in temporary profile; native browser zoom, no CSS zoom or viewport emulation',
              'checks':[], 'page_errors':[], 'failed_resources':[]}
    try:
        with tempfile.TemporaryDirectory(prefix='tekora-sidebar-zoom-') as profile, sync_playwright() as p:
            context = p.chromium.launch_persistent_context(profile, executable_path=args.executable, headless=True, no_viewport=True,
                record_video_dir=str(args.output/'video-temp'), record_video_size={'width':1440,'height':1000},
                args=['--window-size=1440,1000','--disable-dev-shm-usage'])
            settings=context.pages[0];settings.goto('chrome://settings/appearance');settings.wait_for_timeout(300)
            assert settings.evaluate('typeof chrome.settingsPrivate?.setDefaultZoom')=='function'
            page=context.new_page();page.on('pageerror',lambda e:result['page_errors'].append(str(e)))
            page.on('requestfailed',lambda r:result['failed_resources'].append(r.url))
            for zoom in (1,2,1):
                settings.evaluate('(z)=>new Promise(resolve=>chrome.settingsPrivate.setDefaultZoom(z,resolve))',zoom)
                for name,path in [('provider','/cuenta/prestador/dashboard'),('client','/cuenta/cliente')]:
                    response=page.goto(args.base_url+path,wait_until='networkidle')
                    assert response.header_value('x-tekora-preview')=='synthetic-static-memory-export'
                    page.evaluate('document.fonts.ready');page.wait_for_timeout(300)
                    metrics=page.evaluate('({css_width:innerWidth,outer_width:outerWidth,dpr:devicePixelRatio,scroll_width:document.documentElement.scrollWidth})')
                    assert metrics['css_width']==1440/zoom and metrics['outer_width']==1440 and metrics['dpr']==zoom
                    assert metrics['scroll_width']<=metrics['css_width']+1
                    if not page.locator('[data-account-navigation]').evaluate('e=>e.open'):page.locator('[data-account-navigation]>summary').click()
                    scroller=page.locator('[data-sidebar-scroll]');scroller.evaluate('e=>e.scrollTop=0');page.wait_for_timeout(120)
                    if zoom==2:page.screenshot(path=str(args.output/f'{name}-zoom-200-start.png'),full_page=False)
                    scroller.hover();page.mouse.wheel(0,500);page.wait_for_timeout(300)
                    page.locator('.logout-btn').focus();page.wait_for_timeout(150)
                    assert page.locator('.logout-btn').evaluate('''e=>{const a=e.getBoundingClientRect(),b=e.closest('[data-sidebar-scroll]').getBoundingClientRect();return a.top>=b.top+18&&a.bottom<=b.bottom-18;}''')
                    if zoom==2:
                        page.screenshot(path=str(args.output/f'{name}-zoom-200-end.png'),full_page=False)
                        page.keyboard.press('Escape');assert not page.locator('[data-account-navigation]').evaluate('e=>e.open')
                    result['checks'].append({'zoom_percent':zoom*100,'role':name,**metrics,'last_control_clear':True})
            video=page.video;page.close();video.save_as(str(args.output/'sidebar-native-zoom.webm'));context.close()
        assert not result['page_errors'] and not result['failed_resources']
        print(json.dumps({'checks':len(result['checks']),'native_zoom_200':True}))
    finally:
        (args.output/'native-zoom.json').write_text(json.dumps(result,indent=2)+'\n')


if __name__=='__main__':
    main()
