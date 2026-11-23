from PIL import Image, ImageFilter, ImageDraw
def soft_blur(im, box, strength=0.30):
    """Professional plate blur: soft gaussian with feathered edges (no pixel blocks, no hard box).
    Keeps the plate's colour and shape so it reads as a plate, but not legible."""
    x0, y0, x1, y1 = box; w, h = x1 - x0, y1 - y0
    px, py = int(w * .12), int(h * .25)
    X0, Y0, X1, Y1 = max(0, x0 - px), max(0, y0 - py), min(im.width, x1 + px), min(im.height, y1 + py)
    reg = im.crop((X0, Y0, X1, Y1))
    r = max(3, h * strength)
    bl = reg.filter(ImageFilter.GaussianBlur(r)).filter(ImageFilter.GaussianBlur(r * .6))
    m = Image.new('L', reg.size, 0); d = ImageDraw.Draw(m)
    d.rounded_rectangle((px * .5, py * .5, reg.width - px * .5, reg.height - py * .5), radius=int(h * .3), fill=255)
    m = m.filter(ImageFilter.GaussianBlur(max(2, min(px, py) * .6)))
    im.paste(Image.composite(bl, reg, m), (X0, Y0))
    return im
