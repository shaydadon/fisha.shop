"""Build web media for the Tattoos page.
Sources: <site>/tattoos-source/*.JPG, *.MOV and wp-content/uploads/fisha-tattoos/src/*.jpg (HEIC already converted).
Output: wp-content/uploads/fisha-tattoos/ (-tile.jpg 900px, -full.jpg 2000px, .mp4 720p, -poster.jpg, manifest.json)"""
import os, json, glob, subprocess
from PIL import Image, ImageOps
Image.MAX_IMAGE_PIXELS = None
SITE = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../..'))
SRC = os.path.join(SITE, 'tattoos-source'); OUT = os.path.join(SITE, 'wp-content/uploads/fisha-tattoos')
os.makedirs(OUT, exist_ok=True)
srcs = sorted(glob.glob(SRC + '/*.JPG') + glob.glob(SRC + '/*.jpg') + glob.glob(OUT + '/src/*.jpg'))
imgs = []
for p in srcs:
    slug = os.path.splitext(os.path.basename(p))[0].lower().replace('_', '-')
    im = ImageOps.exif_transpose(Image.open(p)).convert('RGB')
    rec = {'slug': slug, 'type': 'image'}
    for key, size in (('tile', 900), ('full', 2000)):
        c = im.copy(); c.thumbnail((size, size * 2) if key == 'tile' else (size, size), Image.LANCZOS)
        if key == 'tile': c = im.copy(); r = 900 / im.width; c = im.resize((900, round(im.height * r)), Image.LANCZOS) if im.width > 900 else im.copy()
        fn = f'{slug}-{key}.jpg'; c.save(os.path.join(OUT, fn), quality=82, optimize=True, progressive=True)
        rec[key] = fn; rec['w' if key == 'tile' else 'fw'] = c.width; rec['h' if key == 'tile' else 'fh'] = c.height
    imgs.append(rec); print('img', slug, im.size)
vids = []
for p in sorted(glob.glob(SRC + '/*.MOV')):
    slug = os.path.splitext(os.path.basename(p))[0].lower().replace('_', '-')
    mp4 = os.path.join(OUT, slug + '.mp4'); poster = os.path.join(OUT, slug + '-poster.jpg')
    subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-i', p, '-vf', 'scale=-2:1280,fps=30', '-c:v', 'libx264', '-preset', 'slow', '-crf', '24',
                    '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', mp4], check=True)
    subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-ss', '0.5', '-i', mp4, '-frames:v', '1', '-q:v', '3', poster], check=True)
    w, h = Image.open(poster).size
    vids.append({'slug': slug, 'type': 'video', 'src': slug + '.mp4', 'poster': slug + '-poster.jpg', 'w': w, 'h': h})
    print('vid', slug, os.path.getsize(mp4) // 1024, 'KB')
json.dump({'images': imgs, 'videos': vids}, open(os.path.join(OUT, 'manifest.json'), 'w'), indent=1)
