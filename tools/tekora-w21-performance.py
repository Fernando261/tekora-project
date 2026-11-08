"""Compare local static memory-fixture exports; these are not production benchmarks."""
import argparse
import json
import shutil
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline-url', required=True)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--executable', default=shutil.which('chromium'))
    args = parser.parse_args()
    for origin in (args.baseline_url, args.base_url):
        parsed = urlsplit(origin)
        assert parsed.scheme == 'http' and parsed.hostname == '127.0.0.1' and not parsed.path
    result = {'method': '3 fresh contexts per role/width/version; initial CLS excludes recent input; 1 second idle rAF sample after load; local static servers, no production claims', 'runs': []}
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=args.executable, args=['--disable-dev-shm-usage'])
        result['browser'] = browser.version
        for version, origin in [('w2', args.baseline_url), ('w21', args.base_url)]:
            for width in (375, 1440):
                for role, path in [('client', '/cuenta/cliente'), ('provider', '/cuenta/prestador/dashboard')]:
                    for run in range(3):
                        context = browser.new_context(viewport={'width': width, 'height': 950})
                        page = context.new_page()
                        errors = []
                        page.on('pageerror', lambda e: errors.append(str(e)))
                        page.on('requestfailed', lambda r: errors.append(r.url))
                        page.add_init_script('''window.w21LayoutShifts = [];
                            new PerformanceObserver(list => { for (const entry of list.getEntries()) window.w21LayoutShifts.push({value:entry.value, recent:entry.hadRecentInput}); }).observe({type:'layout-shift',buffered:true});''')
                        response = page.goto(origin + path, wait_until='networkidle')
                        assert response.header_value('x-tekora-preview') == 'synthetic-static-memory-export'
                        page.wait_for_timeout(900)
                        frames = page.evaluate('''() => new Promise(resolve => {
                            const frames = [], start=performance.now();
                            const tick=now=>{frames.push(now);if(now-start<1000)requestAnimationFrame(tick);else resolve(frames)};
                            requestAnimationFrame(tick);
                        })''')
                        intervals = sorted(b-a for a,b in zip(frames,frames[1:]))
                        metrics = page.evaluate('''() => ({cls:window.w21LayoutShifts.filter(e=>!e.recent).reduce((sum,e)=>sum+e.value,0),
                            requests:performance.getEntriesByType('resource').length,
                            encoded_bytes:performance.getEntriesByType('resource').reduce((sum,e)=>sum+e.encodedBodySize,0),
                            overflow:document.documentElement.scrollWidth>innerWidth+1})''')
                        assert not errors and not metrics['overflow'], (version, width, role, errors)
                        if version == 'w21':
                            assert metrics['cls'] < .1, (version, width, role, metrics['cls'])
                        metrics.update(version=version, width=width, role=role, run=run+1, raf_frames=len(frames), raf_median_ms=intervals[len(intervals)//2], raf_p95_ms=intervals[int((len(intervals)-1)*.95)])
                        result['runs'].append(metrics)
                        context.close()
        browser.close()
    args.output.write_text(json.dumps(result, indent=2)+'\n')
    print(json.dumps({'runs':len(result['runs']),'max_initial_cls':max(row['cls'] for row in result['runs'])}))


if __name__ == '__main__':
    main()
