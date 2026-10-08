"""Turn Procreate screen recordings into transparent, seamlessly looping animated WebPs.
One animation cycle is cut from each clip (period found by frame matching), the flat background
connected to the frame edge is keyed out with soft, de-fringed edges, everything is cropped to the
union of the drawing's bounds and repeated frames are merged into longer frame durations."""
import subprocess, json, os, sys
import numpy as np
from scipy import ndimage
from PIL import Image

SRC = sys.argv[1]; OUT = sys.argv[2]; os.makedirs(OUT, exist_ok=True)
FPS = 30
CLIPS = [  # file, slug, period (frames @30fps), start (s), max size, alt
    ('RPReplay_Final1751904522.mp4', 'loop-egg', 40, 1.0, 420, 'Hand-drawn Fisha egg sitting on a dark stone, gently moving'),
    ('RPReplay_Final1751999825.MP4', 'loop-stone', 15, 1.0, 460, 'Little Fisha fish peeking out around a river stone'),
    ('RPReplay_Final1752079321.mp4', 'loop-pebble', 45, 1.0, 420, 'A grey pebble creature wiggling its tiny legs'),
    ('RPReplay_Final1752508030.mov', 'loop-fisha', 30, 1.0, 460, 'Fisha, the round orange fish, blinking and smiling'),
    ('RPReplay_Final1762368301.mp4', 'loop-jump', 65, 1.0, 520, 'Fisha jumping out of a wavy orange river line'),
]

def frames(path, start, n):
    w, h = map(int, subprocess.check_output(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', path]).decode().split(','))
    raw = subprocess.check_output(['ffmpeg', '-v', 'error', '-ss', str(start), '-i', path, '-vf', f'fps={FPS}', '-frames:v', str(n), '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'])
    return np.frombuffer(raw, np.uint8).reshape(-1, h, w, 3)

def key(fr, bg, hard=10.0, soft=42.0):
    """alpha for one frame: background region = pixels near bg colour that touch the border."""
    f = fr.astype(np.float32)
    dist = np.sqrt(((f - bg) ** 2).sum(-1))
    near = dist < soft
    lab, _ = ndimage.label(near)
    edge = np.unique(np.concatenate([lab[0], lab[-1], lab[:, 0], lab[:, -1]]))
    edge = edge[edge > 0]
    region = np.isin(lab, edge)
    a = np.ones(dist.shape, np.float32)
    a[region] = np.clip((dist[region] - hard) / (soft - hard), 0, 1)
    # de-fringe: recover the drawing colour from pixels blended with the background
    m = (a > 0) & (a < 1)
    out = f.copy()
    out[m] = np.clip((f[m] - (1 - a[m, None]) * bg) / a[m, None], 0, 255)
    return np.dstack([out, a * 255]).astype(np.uint8)

manifest = []
for fn, slug, period, start, size, alt in CLIPS:
    fr = frames(os.path.join(SRC, fn), start, period)
    bg = np.median(np.concatenate([fr[:, :8, :8].reshape(-1, 3), fr[:, -8:, -8:].reshape(-1, 3), fr[:, :8, -8:].reshape(-1, 3), fr[:, -8:, :8].reshape(-1, 3)]), 0).astype(np.float32)
    rgba = [key(f, bg) for f in fr]
    al = np.max([r[..., 3] for r in rgba], 0)
    ys, xs = np.where(al > 8)
    pad = 12
    y0, y1 = max(ys.min() - pad, 0), min(ys.max() + pad, al.shape[0])
    x0, x1 = max(xs.min() - pad, 0), min(xs.max() + pad, al.shape[1])
    imgs = [Image.fromarray(r[y0:y1, x0:x1], 'RGBA') for r in rgba]
    s = min(1.0, size / max(imgs[0].size))
    tw, th = round(imgs[0].width * s), round(imgs[0].height * s)
    imgs = [i.resize((tw, th), Image.LANCZOS) for i in imgs]
    # merge repeated frames (Procreate animations hold frames)
    seq, durs = [], []
    for i in imgs:
        if seq and np.abs(np.asarray(i, np.int16) - np.asarray(seq[-1], np.int16)).mean() < 0.6:
            durs[-1] += 1000 / FPS
        else:
            seq.append(i); durs.append(1000 / FPS)
    durs = [round(d) for d in durs]
    out = os.path.join(OUT, slug + '.webp')
    seq[0].save(out, save_all=True, append_images=seq[1:], duration=durs, loop=0, quality=82, method=6, lossless=False, alpha_quality=90)
    # static first frame (PNG→webp) for reduced motion
    seq[0].save(os.path.join(OUT, slug + '-still.webp'), quality=85, method=6)
    manifest.append({'slug': slug, 'file': slug + '.webp', 'still': slug + '-still.webp', 'w': tw, 'h': th, 'alt': alt, 'frames': len(seq), 'ms': sum(durs), 'bg': [int(v) for v in bg]})
    print(slug, (tw, th), len(seq), 'frames', sum(durs), 'ms', os.path.getsize(out) // 1024, 'KB', 'bg', bg.astype(int))
json.dump(manifest, open(os.path.join(OUT, 'manifest.json'), 'w'), indent=1)
