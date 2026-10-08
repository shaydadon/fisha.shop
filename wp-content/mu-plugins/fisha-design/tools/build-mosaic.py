#!/usr/bin/env python3
"""
Build the River sketch mosaic from originals.

  python3 build-mosaic.py <source-folder> <output-folder>

- Each image -> <slug>-tile.jpg  (mosaic tile, max 800px wide)
               <slug>-full.jpg  (viewer, long edge 3200px)
- Writes manifest.json (natural filename order). Originals are never modified; re-runs skip finished files.
"""
import json, os, re, sys, time
from PIL import Image, ImageOps

Image.MAX_IMAGE_PIXELS = None
IMG_EXT = {'.jpg', '.jpeg', '.png', '.webp', '.tif', '.tiff'}
TILE_W, FULL_EDGE = 800, 3200

def natural(s): return [int(t) if t.isdigit() else t.lower() for t in re.split(r'(\d+)', s)]
def slugify(n): return re.sub(r'[^A-Za-z0-9]+', '-', os.path.splitext(n)[0]).strip('-').lower() or 'sketch'

def load_rgb(p):
    im = ImageOps.exif_transpose(Image.open(p))
    if im.mode in ('RGBA', 'LA', 'P'):
        im = im.convert('RGBA'); bg = Image.new('RGB', im.size, (255, 255, 255)); bg.paste(im, mask=im.split()[-1]); return bg
    return im.convert('RGB')

def main(src, out):
    os.makedirs(out, exist_ok=True)
    items, used = [], set()
    for f in sorted((x for x in os.listdir(src) if not x.startswith('.')), key=natural):
        if os.path.splitext(f)[1].lower() not in IMG_EXT: continue
        p = os.path.join(src, f); slug = slugify(f); base = slug; n = 2
        while slug in used: slug = f'{base}-{n}'; n += 1
        used.add(slug)
        tile, full = os.path.join(out, slug + '-tile.jpg'), os.path.join(out, slug + '-full.jpg')
        mt = os.path.getmtime(p)
        if not (os.path.exists(tile) and os.path.exists(full) and os.path.getmtime(tile) >= mt and os.path.getmtime(full) >= mt):
            t = time.time(); im = load_rgb(p)
            fu = im.copy(); fu.thumbnail((FULL_EDGE, FULL_EDGE), Image.LANCZOS); fu.save(full, 'JPEG', quality=85, optimize=True, progressive=True)
            ti = im.copy(); ti.thumbnail((TILE_W, TILE_W * 3), Image.LANCZOS); ti.save(tile, 'JPEG', quality=82, optimize=True, progressive=True)
            print(f'built {f}  {im.size[0]}x{im.size[1]}  {time.time() - t:.1f}s', flush=True)
        with Image.open(tile) as a: tw, th = a.size
        with Image.open(full) as b: fw, fh = b.size
        items.append({'slug': slug, 'tile': slug + '-tile.jpg', 'full': slug + '-full.jpg', 'w': tw, 'h': th, 'fw': fw, 'fh': fh, 'file': f})
    json.dump({'images': items}, open(os.path.join(out, 'manifest.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(len(items), 'sketches', flush=True)

if __name__ == '__main__':
    if len(sys.argv) != 3: sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
