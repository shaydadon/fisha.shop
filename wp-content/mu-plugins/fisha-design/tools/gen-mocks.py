"""Vector product mockups for the Fisha Wearables demo catalogue.
Every product = an SVG garment drawn in the brand palette with the real Fisha line-art,
rendered by Chromium to a 1200x1200 JPG (main) + a close-up of the print (gallery/hover)."""
import base64, json, os, asyncio
from playwright.async_api import async_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
M = os.path.join(HERE, 'mocks')
OUT = os.path.join(HERE, 'mock-out'); os.makedirs(OUT, exist_ok=True)
INK = '#17232d'; ORANGE = '#E48734'; CREAM = '#f6efe2'

def uri(name):
    return 'data:image/png;base64,' + base64.b64encode(open(os.path.join(M, name + '.png'), 'rb').read()).decode()

FISH = {c: uri('fish-' + c) for c in ('ink', 'white', 'orange', 'cream')}
WORD = {c: uri('word-' + c) for c in ('ink', 'white', 'orange', 'cream')}
FISH_RATIO = 462 / 656; WORD_RATIO = 120 / 707

def shade(hexc, f):
    h = hexc.lstrip('#'); r, g, b = (int(h[i:i + 2], 16) for i in (0, 2, 4))
    if f < 0: r, g, b = (int(v * (1 + f)) for v in (r, g, b))
    else: r, g, b = (int(v + (255 - v) * f) for v in (r, g, b))
    return '#%02x%02x%02x' % (r, g, b)

def fish(x, y, w, col, rot=0, op=1):
    h = w * FISH_RATIO
    return f'<image href="{FISH[col]}" x="{x - w/2:.1f}" y="{y - h/2:.1f}" width="{w}" height="{h:.1f}" opacity="{op}" transform="rotate({rot} {x} {y})"/>'

def word(x, y, w, col):
    h = w * WORD_RATIO
    return f'<image href="{WORD[col]}" x="{x - w/2:.1f}" y="{y - h/2:.1f}" width="{w}" height="{h:.1f}"/>'

def defs(base, pattern=None):
    p = ''
    if pattern == 'fish':
        p = f'''<pattern id="pat" width="150" height="130" patternUnits="userSpaceOnUse" patternTransform="rotate(-12)">
            {fish(40, 35, 62, PCOL, 0)}{fish(115, 100, 62, PCOL, 180)}</pattern>'''
    elif pattern == 'spots':
        p = f'''<pattern id="pat" width="70" height="70" patternUnits="userSpaceOnUse" patternTransform="rotate(20)">
            <circle cx="18" cy="18" r="7" fill="{PCOL}"/><circle cx="53" cy="50" r="5" fill="{PCOL}"/><circle cx="50" cy="16" r="3" fill="{PCOL}"/></pattern>'''
    elif pattern == 'stripes':
        p = f'''<pattern id="pat" width="60" height="60" patternUnits="userSpaceOnUse"><rect width="60" height="30" fill="{PCOL}"/></pattern>'''
    return f'''<defs>
      <linearGradient id="lit" x1="0" x2="1" y1="0" y2="0">
        <stop offset="0" stop-color="#000" stop-opacity=".16"/><stop offset=".22" stop-color="#000" stop-opacity="0"/>
        <stop offset=".55" stop-color="#fff" stop-opacity=".07"/><stop offset=".8" stop-color="#000" stop-opacity="0"/>
        <stop offset="1" stop-color="#000" stop-opacity=".18"/></linearGradient>
      <linearGradient id="top" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".08"/><stop offset="1" stop-color="#000" stop-opacity=".08"/></linearGradient>
      <filter id="soft" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="14"/></filter>
      <filter id="blur3"><feGaussianBlur stdDeviation="3"/></filter>
      <filter id="emb"><feDropShadow dx="0" dy="2" stdDeviation="1.2" flood-color="#000" flood-opacity=".35"/></filter>
      {p}</defs>'''

PCOL = INK

def garment(path, base, extra_inside='', pattern=None, details=''):
    """fill + pattern + print clipped to the silhouette, then light/shade overlays and seams."""
    pat = '<path d="{0}" fill="url(#pat)"/>'.format(path) if pattern else ''
    return f'''
    <ellipse cx="500" cy="905" rx="330" ry="34" fill="#000" opacity=".16" filter="url(#soft)"/>
    <clipPath id="clip"><path d="{path}"/></clipPath>
    <path d="{path}" fill="{base}"/>
    <g clip-path="url(#clip)">{pat}{extra_inside}
      <rect width="1000" height="1000" fill="url(#lit)"/><rect width="1000" height="1000" fill="url(#top)"/>
    </g>
    {details}'''

# ---------- garments ----------
TEE = 'M392 150 C430 210 570 210 608 150 L742 182 C800 205 850 265 885 338 L790 402 C770 385 742 360 718 344 C716 520 718 700 722 858 C580 878 420 878 278 858 C282 700 284 520 282 344 C258 360 230 385 210 402 L115 338 C150 265 200 205 258 182 Z'
def tee(base, art='', pattern=None):
    d = shade(base, -0.22)
    details = f'''
      <path d="M392 150 C430 178 570 178 608 150 C570 210 430 210 392 150Z" fill="{shade(base, -0.35)}"/>
      <path d="M392 150 C430 212 570 212 608 150" fill="none" stroke="{d}" stroke-width="16" stroke-linecap="round"/>
      <path d="M258 182 C268 240 278 300 282 344 M742 182 C732 240 722 300 718 344" fill="none" stroke="{d}" stroke-width="3" opacity=".7"/>
      <path d="M133 352 L222 392 M867 352 L778 392" stroke="{d}" stroke-width="3" opacity=".6"/>
      <path d="M282 836 C420 852 580 852 718 836" fill="none" stroke="{d}" stroke-width="3" opacity=".55"/>
      <path d="M330 420 C360 560 350 700 380 820 M640 380 C610 500 650 640 620 800" fill="none" stroke="#000" stroke-opacity=".018" stroke-width="30" filter="url(#blur3)"/>'''
    return garment(TEE, base, art, pattern, details)

LONG = 'M392 150 C430 210 570 210 608 150 L742 182 C820 210 860 300 880 420 L925 790 L830 812 L768 470 C760 430 735 400 720 380 C718 560 718 720 722 858 C580 878 420 878 278 858 C282 720 282 560 280 380 C265 400 240 430 232 470 L170 812 L75 790 L120 420 C140 300 180 210 258 182 Z'
def crew(base, art=''):
    d = shade(base, -0.24)
    details = f'''
      <path d="M392 150 C430 178 570 178 608 150 C570 210 430 210 392 150Z" fill="{shade(base, -0.35)}"/>
      <path d="M392 150 C430 214 570 214 608 150" fill="none" stroke="{d}" stroke-width="24" stroke-linecap="round"/>
      <path d="M278 826 C420 846 580 846 722 826 L722 858 C580 878 420 878 278 858Z" fill="{d}" opacity=".55"/>
      <path d="M170 812 L75 790 L82 750 L178 772Z M830 812 L925 790 L918 750 L822 772Z" fill="{d}" opacity=".55"/>
      <path d="M258 182 C268 260 276 330 280 380 M742 182 C732 260 724 330 720 380" fill="none" stroke="{d}" stroke-width="3" opacity=".7"/>'''
    return garment(LONG, base, art, None, details)

def hoodie(base, art=''):
    d = shade(base, -0.25)
    hood = f'''<path d="M360 160 C330 60 670 60 640 160 C620 225 380 225 360 160Z" fill="{shade(base, -0.12)}"/>
      <path d="M392 165 C420 230 580 230 608 165 C590 120 410 120 392 165Z" fill="{shade(base, -0.42)}"/>'''
    details = f'''
      <path d="M392 168 C430 236 570 236 608 168" fill="none" stroke="{d}" stroke-width="10" stroke-linecap="round"/>
      <path d="M470 222 C468 280 462 320 466 360 M530 222 C532 280 538 320 534 360" stroke="{CREAM}" stroke-width="7" stroke-linecap="round" fill="none"/>
      <path d="M350 640 L650 640 L690 800 L310 800 Z" fill="{shade(base, -0.06)}" stroke="{d}" stroke-width="4"/>
      <path d="M278 826 C420 846 580 846 722 826 L722 858 C580 878 420 878 278 858Z" fill="{d}" opacity=".55"/>
      <path d="M170 812 L75 790 L82 750 L178 772Z M830 812 L925 790 L918 750 L822 772Z" fill="{d}" opacity=".55"/>
      <path d="M258 182 C268 260 276 330 280 380 M742 182 C732 260 724 330 720 380" fill="none" stroke="{d}" stroke-width="3" opacity=".7"/>'''
    return hood + garment(LONG, base, art, None, details)

CAP = 'M215 600 C205 360 340 250 500 250 C640 250 735 340 745 600 Z'
def cap(base, art=''):
    d = shade(base, -0.25)
    under = f'''<path d="M590 560 C700 520 860 540 950 610 C930 655 860 668 770 650 C700 636 630 610 590 560Z" fill="{shade(base, -0.38)}"/>'''
    brim = f'''<path d="M560 590 C690 540 870 560 955 625 C905 690 760 700 640 668 C600 655 572 630 560 590Z" fill="{shade(base, -0.05)}"/>
      <path d="M560 590 C690 540 870 560 955 625 C905 690 760 700 640 668 C600 655 572 630 560 590Z" fill="url(#lit)"/>
      <path d="M585 615 C700 575 850 590 925 632" fill="none" stroke="{d}" stroke-width="3" stroke-dasharray="10 8" opacity=".7"/>
      <path d="M598 640 C700 610 840 618 910 650" fill="none" stroke="{d}" stroke-width="3" stroke-dasharray="10 8" opacity=".5"/>'''
    details = f'''
      <path d="M500 256 C470 330 455 460 470 598 M500 256 C590 320 650 450 668 598" fill="none" stroke="{d}" stroke-width="4"/>
      <path d="M500 256 C420 300 300 380 240 520" fill="none" stroke="{d}" stroke-width="3" opacity=".5"/>
      <circle cx="500" cy="254" r="15" fill="{shade(base, -0.15)}" stroke="{d}" stroke-width="3"/>
      <path d="M220 585 C380 600 600 600 742 580" fill="none" stroke="{d}" stroke-width="3" stroke-dasharray="9 8" opacity=".6"/>
      <ellipse cx="300" cy="420" rx="9" ry="9" fill="{d}" opacity=".6"/>'''
    return '<ellipse cx="560" cy="740" rx="360" ry="34" fill="#000" opacity=".16" filter="url(#soft)"/>' + '<g transform="translate(-10 -28) rotate(-3 600 600)">' + under + '</g>' + garment(CAP, base, art, None, details).replace('<ellipse cx="500" cy="905"', '<ellipse cx="500" cy="-500"') + '<g transform="translate(-10 -28) rotate(-3 600 600)">' + brim + '</g>'

BEANIE = 'M262 650 C252 380 370 250 500 250 C630 250 748 380 738 650 Z'
def beanie(base, art=''):
    d = shade(base, -0.25)
    ribs = ''.join(f'<path d="M{x} 300 C{x - (x - 500) * 0.05} 450 {x} 560 {x} 650" fill="none" stroke="{d}" stroke-width="3" opacity=".35"/>' for x in range(300, 720, 28))
    cuff = f'''<rect x="238" y="560" width="524" height="200" rx="40" fill="{shade(base, -0.06)}"/>'''
    cribs = ''.join(f'<line x1="{x}" y1="572" x2="{x}" y2="748" stroke="{d}" stroke-width="5" opacity=".35"/>' for x in range(262, 750, 22))
    pom = f'<circle cx="500" cy="232" r="70" fill="{shade(base, 0.1)}"/><circle cx="500" cy="232" r="70" fill="url(#lit)"/>'
    shadow = '<ellipse cx="500" cy="800" rx="300" ry="30" fill="#000" opacity=".16" filter="url(#soft)"/>'
    return shadow + pom + garment(BEANIE, base, ribs, None, '').replace('<ellipse cx="500" cy="905"', '<ellipse cx="500" cy="-500"') + f'<g>{cuff}{cribs}<rect x="238" y="560" width="524" height="200" rx="40" fill="url(#lit)"/></g>' + art

BUCKET_CROWN = 'M330 330 Q500 290 670 330 L705 575 Q500 610 295 575 Z'
BUCKET_BRIM = 'M300 545 Q500 590 700 545 L835 668 Q500 790 165 668 Z'
def bucket(base):
    d = shade(base, -0.22)
    return f'''<ellipse cx="500" cy="800" rx="340" ry="32" fill="#000" opacity=".16" filter="url(#soft)"/>
      <clipPath id="cb"><path d="{BUCKET_BRIM}"/></clipPath><clipPath id="cc"><path d="{BUCKET_CROWN}"/></clipPath>
      <path d="{BUCKET_BRIM}" fill="{shade(base, -0.04)}"/><g clip-path="url(#cb)"><rect width="1000" height="1000" fill="url(#pat)"/><rect width="1000" height="1000" fill="url(#lit)"/></g>
      <path d="M190 660 Q500 770 810 660" fill="none" stroke="{d}" stroke-width="3" stroke-dasharray="10 8"/>
      <path d="{BUCKET_CROWN}" fill="{base}"/><g clip-path="url(#cc)"><rect width="1000" height="1000" fill="url(#pat)"/><rect width="1000" height="1000" fill="url(#lit)"/><rect width="1000" height="1000" fill="url(#top)"/></g>
      <path d="M298 548 Q500 585 702 548" fill="none" stroke="{d}" stroke-width="10" opacity=".6"/>
      <path d="M332 336 Q500 300 668 336" fill="none" stroke="{d}" stroke-width="3" stroke-dasharray="9 7"/>'''

SOCK = 'M0 0 L150 0 L150 390 C150 430 175 455 215 475 L330 532 C380 558 372 625 318 635 C300 640 280 636 262 628 L72 548 C20 526 0 490 0 440 Z'
def socks(base, pattern=None, accent=ORANGE, art_col='ink'):
    d = shade(base, -0.22)
    def one(tx, ty, rot, idx):
        cid = f'sk{idx}'
        return f'''<g transform="translate({tx} {ty}) rotate({rot})">
          <clipPath id="{cid}"><path d="{SOCK}"/></clipPath>
          <path d="{SOCK}" fill="{base}"/>
          <g clip-path="url(#{cid})">{'<rect x="-50" y="-50" width="500" height="800" fill="url(#pat)"/>' if pattern else ''}
            <rect x="-10" y="-10" width="200" height="80" fill="{shade(base, -0.06) if not pattern else base}"/>
            {''.join(f'<line x1="{x}" y1="0" x2="{x}" y2="70" stroke="{d}" stroke-width="4" opacity=".4"/>' for x in range(10, 150, 16))}
            <path d="M150 380 C150 440 190 470 240 490 L250 470 C200 450 175 420 175 380Z" fill="{accent}"/>
            <circle cx="330" cy="585" r="62" fill="{accent}"/>
            {fish(75, 200, 105, art_col, -90) if not pattern else ''}
            <rect x="-10" y="-10" width="400" height="700" fill="url(#lit)"/></g></g>'''
    return '<ellipse cx="500" cy="860" rx="330" ry="30" fill="#000" opacity=".14" filter="url(#soft)"/>' + one(250, 170, -8, 1) + one(470, 230, 6, 2)

# ---------- catalogue ----------
P = []
def add(slug, name, cat, price, bg, svg, focus, colour, short, pat=None, pcol=INK):
    P.append(dict(slug=slug, name=name, cat=cat, price=price, bg=bg, svg=svg, focus=focus, colour=colour, short=short, pattern=pat, pcol=pcol))

add('big-fish-tee-black', 'Big Fish Tee — Black', 'tshirts', 119, '#efe7da',
    lambda: tee('#1f262c', fish(500, 470, 340, 'white')), (500, 470, 420), 'Black',
    'A soft black tee with Fisha drawn big across the chest in white line-art.')
add('pocket-fish-tee-sand', 'Pocket Fish Tee — Sand', 'tshirts', 109, '#dfe9e7',
    lambda: tee('#e8dcc3', fish(612, 300, 110, 'ink', -6)), (612, 300, 230), 'Sand',
    'A sand-coloured everyday tee with a tiny Fisha swimming over the heart.')
add('fish-school-tee-ocean', 'Fish School Tee — Ocean Blue', 'tshirts', 129, '#f3ece0',
    lambda: tee('#3b6e8f', '', 'fish'), (500, 480, 380), 'Ocean blue',
    'An all-over school of little cream Fisha fish on an ocean-blue tee.', 'fish', 'cream')
add('wordmark-tee-orange', 'Wordmark Tee — Fisha Orange', 'tshirts', 115, '#f6efe4',
    lambda: tee('#E48734', word(500, 330, 230, 'cream') + fish(500, 460, 170, 'cream', 4)), (500, 400, 360), 'Orange',
    'The hand-lettered Fisha wordmark with a little fish underneath, on Fisha orange.')

add('fisha-dad-cap-navy', 'Fisha Dad Cap — Navy', 'hats', 89, '#efe7da',
    lambda: cap('#22344a', f'<g filter="url(#emb)">{fish(560, 440, 190, "cream", -8)}</g>'), (570, 450, 360), 'Navy',
    'A relaxed six-panel cap with an embroidered cream Fisha on the front.')
add('fisha-dad-cap-cream', 'Fisha Dad Cap — Cream', 'hats', 89, '#dfe9e7',
    lambda: cap('#efe4cf', f'<g filter="url(#emb)">{fish(560, 440, 190, "orange", -8)}</g>'), (570, 450, 360), 'Cream',
    'Cream cotton dad cap with an orange embroidered Fisha.')
add('fisha-patch-beanie-orange', 'Patch Beanie — Orange', 'hats', 79, '#f3ece0',
    lambda: beanie('#E48734', f'<rect x="405" y="595" width="190" height="130" rx="18" fill="{CREAM}" stroke="{INK}" stroke-width="5"/>' + fish(500, 660, 140, 'ink')), (500, 640, 330), 'Orange',
    'A chunky rib-knit beanie with a woven Fisha patch on the cuff.')
add('fish-pattern-bucket-hat', 'Fish Pattern Bucket Hat', 'hats', 99, '#e3ebef',
    lambda: bucket('#f2e8d5'), (500, 520, 360), 'Cream / ink',
    'A cotton bucket hat printed all over with tiny swimming Fisha fish.', 'fish', 'ink')

add('big-fish-hoodie-forest', 'Big Fish Hoodie — Forest Green', 'hoodies', 229, '#efe7da',
    lambda: hoodie('#2f4a3a', fish(500, 470, 300, 'cream')), (500, 470, 400), 'Forest green',
    'A cosy brushed-fleece hoodie with a big cream Fisha on the chest.')
add('fisha-crewneck-grey', 'Fisha Crewneck — Heather Grey', 'hoodies', 199, '#f3ece0',
    lambda: crew('#b8bbbd', word(500, 330, 200, 'ink') + fish(500, 440, 150, 'ink', -4)), (500, 390, 340), 'Heather grey',
    'A classic heather-grey sweatshirt with the Fisha wordmark and fish.')

add('spotted-fisha-socks', 'Spotted Fisha Socks', 'socks', 39, '#dfe9e7',
    lambda: socks('#f2e8d5', 'spots', ORANGE), (420, 400, 420), 'Cream / orange',
    'Cream socks with orange Fisha spots, an orange heel and toe.', 'spots', ORANGE)
add('fisha-stripe-socks', 'Fisha Stripe Socks', 'socks', 39, '#f6efe4',
    lambda: socks('#22344a', None, ORANGE, 'cream'), (360, 380, 380), 'Navy / orange',
    'Navy socks with a cream Fisha on the ankle and orange heel and toe.')

PAGE = '<html><body style="margin:0;background:{bg}">{svg}</body></html>'
def svg_doc(p, vb='0 0 1000 1000', bg=None):
    global PCOL
    PCOL = p['pcol']
    body = p['svg']()
    return f'<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1200" viewBox="{vb}" style="display:block"><rect x="-2000" y="-2000" width="5000" height="5000" fill="{bg or p["bg"]}"/>{defs(None, p["pattern"])}{body}</svg>'

async def main():
    async with async_playwright() as pw:
        b = await pw.chromium.launch()
        pg = await b.new_page(viewport={'width': 1200, 'height': 1200})
        for p in P:
            await pg.set_content(PAGE.format(bg=p['bg'], svg=svg_doc(p)))
            await pg.screenshot(path=os.path.join(OUT, p['slug'] + '.jpg'), type='jpeg', quality=88)
            fx, fy, fs = p['focus']
            await pg.set_content(PAGE.format(bg=p['bg'], svg=svg_doc(p, f'{fx - fs/2} {fy - fs/2} {fs} {fs}', shade(p['bg'], -0.05))))
            await pg.screenshot(path=os.path.join(OUT, p['slug'] + '-detail.jpg'), type='jpeg', quality=88)
            print(p['slug'])
        await b.close()
    json.dump([{k: v for k, v in p.items() if k not in ('svg', 'focus')} for p in P], open(os.path.join(OUT, 'products.json'), 'w'), indent=1, ensure_ascii=False)

asyncio.run(main())
