"""Upload EzChat screenshots, attach the newest VALID build, submit for review.

Runs on the relay (the ASC key lives there):
    python3 asc-ezchat-submit.py <cred dir> <shots root> [--submit]
<shots root> holds ezchat-iphone69/ and ezchat-ipad13/ (*.png, name order).
Without --submit it stops after uploads + build attach.
"""
import hashlib, importlib.util, json, sys, time, urllib.error, urllib.request
from pathlib import Path

HERE = Path(__file__).parent
spec = importlib.util.spec_from_file_location('abs', HERE / 'asc-build-states.py')
abs_ = importlib.util.module_from_spec(spec); spec.loader.exec_module(abs_)

API = 'https://api.appstoreconnect.apple.com/v1'
APP = '6839349951'  # EzChat by EzBizi
SETS = {'ezchat-iphone69': 'APP_IPHONE_67', 'ezchat-ipad13': 'APP_IPAD_PRO_3GEN_129'}
cred, root, submit = Path(sys.argv[1]), Path(sys.argv[2]), '--submit' in sys.argv
JWT = abs_.token(cred)


def call(method, path, body=None):
    url = path if path.startswith('http') else API + path
    req = urllib.request.Request(url, method=method, data=json.dumps(body).encode() if body else None,
                                 headers={'Authorization': 'Bearer ' + JWT, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            raw = r.read()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        sys.exit('%s %s -> %s\n%s' % (method, path, e.code, e.read().decode('utf-8', 'replace')[:4000]))


ver = [v for v in call('GET', '/apps/%s/appStoreVersions?filter[platform]=IOS' % APP)['data']
       if v['attributes']['appStoreState'] in ('PREPARE_FOR_SUBMISSION', 'DEVELOPER_REJECTED', 'REJECTED')]
assert ver, 'no editable iOS version'
ver = ver[0]; vid = ver['id']
print('version', ver['attributes']['versionString'], ver['attributes']['appStoreState'])

for loc in call('GET', '/appStoreVersions/%s/appStoreVersionLocalizations' % vid)['data']:
    sets = {s['attributes']['screenshotDisplayType']: s['id'] for s in
            call('GET', '/appStoreVersionLocalizations/%s/appScreenshotSets' % loc['id'])['data']}
    for folder, dtype in SETS.items():
        files = sorted((root / folder).glob('*.png'))
        assert files, 'no frames in %s' % folder
        sid = sets.get(dtype) or call('POST', '/appScreenshotSets', {'data': {'type': 'appScreenshotSets',
              'attributes': {'screenshotDisplayType': dtype}, 'relationships': {'appStoreVersionLocalization':
              {'data': {'type': 'appStoreVersionLocalizations', 'id': loc['id']}}}}})['data']['id']
        for old in call('GET', '/appScreenshotSets/%s/appScreenshots' % sid)['data']:
            call('DELETE', '/appScreenshots/%s' % old['id'])
        for f in files:
            data = f.read_bytes()
            shot = call('POST', '/appScreenshots', {'data': {'type': 'appScreenshots', 'attributes':
                   {'fileName': f.name, 'fileSize': len(data)}, 'relationships': {'appScreenshotSet':
                   {'data': {'type': 'appScreenshotSets', 'id': sid}}}}})['data']
            for op in shot['attributes']['uploadOperations']:
                part = data[op['offset']:op['offset'] + op['length']]
                req = urllib.request.Request(op['url'], data=part, method=op['method'],
                                             headers={h['name']: h['value'] for h in op['requestHeaders']})
                urllib.request.urlopen(req, timeout=120).read()
            call('PATCH', '/appScreenshots/%s' % shot['id'], {'data': {'type': 'appScreenshots', 'id': shot['id'],
                 'attributes': {'uploaded': True, 'sourceFileChecksum': hashlib.md5(data).hexdigest()}}})
        # Apple processes asynchronously; wait until every frame is COMPLETE.
        for _ in range(60):
            st = [s['attributes']['assetDeliveryState']['state'] for s in
                  call('GET', '/appScreenshotSets/%s/appScreenshots' % sid)['data']]
            if all(s == 'COMPLETE' for s in st) or any(s == 'FAILED' for s in st):
                break
            time.sleep(5)
        print(loc['attributes']['locale'], dtype, st)
        assert st == ['COMPLETE'] * len(files), 'screenshot processing failed'

b = [x for x in call('GET', '/builds?filter[app]=%s&sort=-uploadedDate&limit=20' % APP)['data']
     if x['attributes']['processingState'] == 'VALID' and not x['attributes']['expired']][0]
call('PATCH', '/appStoreVersions/%s/relationships/build' % vid, {'data': {'type': 'builds', 'id': b['id']}})
print('build', b['attributes']['version'], 'attached')

if submit:
    call('PATCH', '/apps/%s' % APP, {'data': {'type': 'apps', 'id': APP,
         'attributes': {'contentRightsDeclaration': 'DOES_NOT_USE_THIRD_PARTY_CONTENT'}}})
    # Free, base territory USA (Apple refuses review until a price exists).
    if True:  # POST replaces the schedule, so re-running is harmless
        free = [p for p in call('GET', '/apps/%s/appPricePoints?filter[territory]=USA&limit=200' % APP)['data']
                if float(p['attributes']['customerPrice']) == 0][0]
        call('POST', '/appPriceSchedules', {'data': {'type': 'appPriceSchedules', 'relationships': {
             'app': {'data': {'type': 'apps', 'id': APP}},
             'baseTerritory': {'data': {'type': 'territories', 'id': 'USA'}},
             'manualPrices': {'data': [{'type': 'appPrices', 'id': '${p0}'}]}}},
             'included': [{'type': 'appPrices', 'id': '${p0}', 'attributes': {'startDate': None},
                           'relationships': {'appPricePoint': {'data': {'type': 'appPricePoints', 'id': free['id']}}}}]})
    open_ = [r for r in call('GET', '/reviewSubmissions?filter[app]=%s&filter[platform]=IOS' % APP)['data']
             if r['attributes']['state'] == 'READY_FOR_REVIEW']
    # A failed earlier attempt leaves a draft; reuse it (Apple won't cancel it).
    rs = open_[0] if open_ else call('POST', '/reviewSubmissions', {'data': {'type': 'reviewSubmissions',
         'attributes': {'platform': 'IOS'}, 'relationships': {'app': {'data': {'type': 'apps', 'id': APP}}}}})['data']
    items = call('GET', '/reviewSubmissions/%s/items' % rs['id'])['data']
    if not items:
        call('POST', '/reviewSubmissionItems', {'data': {'type': 'reviewSubmissionItems', 'relationships': {
         'reviewSubmission': {'data': {'type': 'reviewSubmissions', 'id': rs['id']}},
         'appStoreVersion': {'data': {'type': 'appStoreVersions', 'id': vid}}}}})
    r = call('PATCH', '/reviewSubmissions/%s' % rs['id'], {'data': {'type': 'reviewSubmissions', 'id': rs['id'],
             'attributes': {'submitted': True}}})
    print('submission', r['data']['attributes']['state'])
