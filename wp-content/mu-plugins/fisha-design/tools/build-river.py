#!/usr/bin/env python3
"""
Build web-ready River gallery files from big originals.

  python3 build-river.py <source-folder> <output-folder> [--only N]

- Images (jpg/png/webp/tif) -> <name>-hd.jpg     carousel image, long edge 2400px
                              dzi/<name>.dzi    Deep Zoom tiles at FULL original resolution
- First audio file          -> river.mp3 (128 kbps)
- Writes manifest-hd.json (the HD River page) (order = natural filename order)
Originals are never modified. Re-running skips work that is already done.
"""
import json, math, os, re, subprocess, sys, time
from PIL import Image, ImageOps

Image.MAX_IMAGE_PIXELS = None
IMG_EXT = {'.jpg', '.jpeg', '.png', '.webp', '.tif', '.tiff'}
AUD_EXT = {'.mp3', '.wav', '.m4a', '.aac', '.ogg', '.flac', '.aif', '.aiff'}
VIEW_EDGE = 2400
TILE, OVERLAP, TILE_Q = 510, 1, 82

def natural(s):
    return [int(t) if t.isdigit() else t.lower() for t in re.split(r'(\d+)', s)]

def slugify(name):
    s = re.sub(r'[^A-Za-z0-9]+', '-', os.path.splitext(name)[0]).strip('-').lower()
    return s or 'image'

def pretty(name):
    s = re.sub(r'[_\-]+', ' ', os.path.splitext(name)[0]).strip()
    return s[:1].upper() + s[1:]

def load_rgb(path):
    im = Image.open(path)
    im = ImageOps.exif_transpose(im)
    if im.mode in ('RGBA', 'LA', 'P'):
        im = im.convert('RGBA'); bg = Image.new('RGB', im.size, (255, 255, 255)); bg.paste(im, mask=im.split()[-1]); return bg
    return im.convert('RGB')

def make_dzi(im, dzi_dir, slug):
    W, H = im.size
    files = os.path.join(dzi_dir, slug + '_files')
    max_level = math.ceil(math.log2(max(W, H)))
    level_im = im
    for level in range(max_level, -1, -1):
        scale = 2 ** (max_level - level)
        w, h = max(1, math.ceil(W / scale)), max(1, math.ceil(H / scale))
        if level_im.size != (w, h):
            level_im = level_im.resize((w, h), Image.LANCZOS)
        ldir = os.path.join(files, str(level)); os.makedirs(ldir, exist_ok=True)
        for col in range(math.ceil(w / TILE)):
            for row in range(math.ceil(h / TILE)):
                x0 = max(0, col * TILE - OVERLAP); y0 = max(0, row * TILE - OVERLAP)
                x1 = min(w, (col + 1) * TILE + OVERLAP); y1 = min(h, (row + 1) * TILE + OVERLAP)
                level_im.crop((x0, y0, x1, y1)).save(os.path.join(ldir, f'{col}_{row}.jpg'), 'JPEG', quality=TILE_Q, optimize=True)
    # .dzi descriptor last = marker that this image is complete
    with open(os.path.join(dzi_dir, slug + '.dzi'), 'w') as fh:
        fh.write(f'<?xml version="1.0" encoding="UTF-8"?><Image xmlns="http://schemas.microsoft.com/deepzoom/2008" Format="jpg" Overlap="{OVERLAP}" TileSize="{TILE}"><Size Width="{W}" Height="{H}"/></Image>')

def main(src, out, only=None):
    os.makedirs(out, exist_ok=True)
    dzi_dir = os.path.join(out, 'dzi'); os.makedirs(dzi_dir, exist_ok=True)
    files = sorted((f for f in os.listdir(src) if not f.startswith('.')), key=natural)
    images, music, used, built = [], None, set(), 0
    for f in files:
        p = os.path.join(src, f)
        ext = os.path.splitext(f)[1].lower()
        if ext in IMG_EXT:
            slug = slugify(f); base = slug; n = 2
            while slug in used: slug = f'{base}-{n}'; n += 1
            used.add(slug)
            view = os.path.join(out, slug + '-hd.jpg')
            dzi = os.path.join(dzi_dir, slug + '.dzi')
            mt = os.path.getmtime(p)
            view_ok = os.path.exists(view) and os.path.getmtime(view) >= mt and max(Image.open(view).size) == VIEW_EDGE
            dzi_ok = os.path.exists(dzi) and os.path.getmtime(dzi) >= mt
            with Image.open(p) as probe:
                W, H = ImageOps.exif_transpose(probe).size if probe.getexif().get(274, 1) != 1 else probe.size
            if not (view_ok and dzi_ok) and (only is None or built < only):
                t = time.time()
                im = load_rgb(p); W, H = im.size
                if not view_ok:
                    v = im.copy(); v.thumbnail((VIEW_EDGE, VIEW_EDGE), Image.LANCZOS)
                    v.save(view, 'JPEG', quality=82, optimize=True, progressive=True)
                if not dzi_ok:
                    make_dzi(im, dzi_dir, slug)
                built += 1
                print(f'built {f}  {W}x{H}  {time.time() - t:.1f}s', flush=True)
            elif not (view_ok and dzi_ok):
                print(f'todo  {f}', flush=True)
            else:
                print(f'skip  {f}', flush=True)
            with Image.open(view) if os.path.exists(view) else Image.new('RGB', (1, 1)) as v:
                vw, vh = v.size
            images.append({'view': slug + '-hd.jpg', 'dzi': 'dzi/' + slug + '.dzi', 'w': vw, 'h': vh, 'zw': W, 'zh': H, 'alt': pretty(f),
                           'ready': os.path.exists(dzi)})
        elif ext in AUD_EXT and music is None:
            dst = os.path.join(out, 'river.mp3')
            if not (os.path.exists(dst) and os.path.getmtime(dst) >= os.path.getmtime(p)):
                subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-i', p, '-vn', '-ac', '2', '-b:a', '128k', dst], check=True)
                print('built', f, '-> river.mp3', flush=True)
            music = 'river.mp3'
    manifest = {'images': images, 'music': music}
    with open(os.path.join(out, 'manifest-hd.json'), 'w', encoding='utf-8') as fh:
        json.dump(manifest, fh, ensure_ascii=False, indent=1)
    todo = sum(1 for i in images if not i['ready'])
    print(f'{len(images)} images ({todo} still to build), music: {music}', flush=True)

if __name__ == '__main__':
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    only = None
    if '--only' in sys.argv:
        only = int(sys.argv[sys.argv.index('--only') + 1]); args = [a for a in args if a != str(only)]
    if len(args) != 2:
        sys.exit(__doc__)
    main(args[0], args[1], only)
