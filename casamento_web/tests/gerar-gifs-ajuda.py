"""Gera um GIF responsivo para cada passo a partir das capturas da interface real."""
from pathlib import Path
import os
from PIL import Image, ImageDraw, ImageFont, ImageFilter

ROOT = Path(__file__).resolve().parents[1]
SOURCE = Path(os.environ.get("HELP_CAPTURE_OUT", ROOT / "assets" / "ajuda" / "capturas"))
OUT = Path(os.environ.get("HELP_GIF_OUT", ROOT / "assets" / "ajuda" / "passos"))
OUT.mkdir(parents=True, exist_ok=True)

STEPS = {
    ("convidados", 1): ["Abra Novo convite", "Preencha família e pessoas", "Guarde e partilhe"],
    ("convidados", 2): ["Pesquise o nome", "Actualize a resposta", "Confira o resumo"],
    ("mesas", 1): ["Abra Nova mesa", "Defina nome, forma e lotação", "Posicione na planta"],
    ("mesas", 2): ["Escolha a pessoa", "Abra o selector de mesa", "Confira a lotação"],
    ("impresso", 1): ["Escolha a peça", "Edite texto e composição", "Guarde a versão"],
    ("impresso", 2): ["Abra a peça em vigor", "Reveja a prova", "Descarregue o manual"],
    ("digital", 1): ["Entre no editor", "Ajuste capa e secções", "Aplique a versão"],
    ("digital", 2): ["Abra a pré-visualização", "Percorra as secções", "Teste a confirmação"],
    ("porta", 1): ["Pesquise ou leia o código", "Marque quem chegou", "Registe a entrada"],
    ("porta", 2): ["Abra o convite", "Corrija a pessoa", "Confira quem falta"],
    ("bar", 1): ["Crie as categorias", "Adicione as bebidas", "Abra o serviço"],
    ("bar", 2): ["Receba o pedido", "Aceite e prepare", "Confirme a entrega"],
    ("orcamento", 1): ["Abra Nova despesa", "Preencha os valores", "Guarde e pague"],
    ("orcamento", 2): ["Compare os totais", "Use os filtros", "Reveja o que falta pagar"],
}

# Pontos da própria interface que cada GIF realça, em coordenadas proporcionais.
ANCHORS = {
    ("convidados", 1): [(0.50, 0.08), (0.48, 0.43), (0.50, 0.88)],
    ("convidados", 2): [(0.23, 0.53), (0.48, 0.77), (0.43, 0.24)],
    ("mesas", 1): [(0.12, 0.24), (0.48, 0.25), (0.48, 0.68)],
    ("mesas", 2): [(0.82, 0.47), (0.82, 0.58), (0.52, 0.18)],
    ("impresso", 1): [(0.17, 0.11), (0.78, 0.45), (0.83, 0.11)],
    ("impresso", 2): [(0.56, 0.12), (0.54, 0.43), (0.83, 0.08)],
    ("digital", 1): [(0.83, 0.10), (0.82, 0.47), (0.82, 0.13)],
    ("digital", 2): [(0.50, 0.48), (0.50, 0.67), (0.17, 0.94)],
    ("porta", 1): [(0.50, 0.50), (0.50, 0.34), (0.50, 0.29)],
    ("porta", 2): [(0.34, 0.28), (0.65, 0.28), (0.50, 0.36)],
    ("bar", 1): [(0.24, 0.45), (0.76, 0.55), (0.82, 0.17)],
    ("bar", 2): [(0.31, 0.50), (0.31, 0.50), (0.66, 0.27)],
    ("orcamento", 1): [(0.50, 0.10), (0.50, 0.48), (0.66, 0.88)],
    ("orcamento", 2): [(0.50, 0.12), (0.52, 0.48), (0.50, 0.82)],
}


def font(size: int, bold: bool = False):
    name = "segoeuib.ttf" if bold else "segoeui.ttf"
    path = Path("C:/Windows/Fonts") / name
    try:
        return ImageFont.truetype(str(path), size)
    except OSError:
        return ImageFont.load_default()


def fit(im: Image.Image, device: str) -> Image.Image:
    target = (720, 450) if device == "desktop" else (320, 640)
    ratio = max(target[0] / im.width, target[1] / im.height)
    resized = im.resize((round(im.width * ratio), round(im.height * ratio)), Image.Resampling.LANCZOS)
    left = (resized.width - target[0]) // 2
    return resized.crop((left, 0, left + target[0], target[1]))


def zoom(im: Image.Image, anchor: tuple[float, float], amount: float) -> Image.Image:
    w, h = im.size
    nw, nh = round(w / amount), round(h / amount)
    cx, cy = round(anchor[0] * w), round(anchor[1] * h)
    left = max(0, min(w - nw, cx - nw // 2))
    top = max(0, min(h - nh, cy - nh // 2))
    return im.crop((left, top, left + nw, top + nh)).resize((w, h), Image.Resampling.LANCZOS)


def caption(im: Image.Image, step: int, label: str, done: bool = False) -> Image.Image:
    out = im.copy()
    d = ImageDraw.Draw(out, "RGBA")
    w, h = out.size
    bar_h = 58 if w > 400 else 70
    d.rounded_rectangle((12, h - bar_h - 12, w - 12, h - 12), radius=15,
                        fill=(14, 40, 30, 232), outline=(214, 169, 89, 235), width=2)
    circle = (25, h - bar_h + 1, 25 + 34, h - bar_h + 35)
    d.ellipse(circle, fill=(214, 169, 89, 255))
    d.text((36, h - bar_h + 18), "✓" if done else str(step), anchor="mm",
           font=font(20, True), fill=(14, 40, 30, 255))
    d.text((70, h - bar_h + 17), f"PASSO {step}", font=font(11, True), fill=(229, 202, 145, 255))
    d.text((70, h - bar_h + 34), label, font=font(17 if w > 400 else 14, True), fill="white")
    return out


def focus(im: Image.Image, anchor: tuple[float, float], radius: int, phase: int, label: str, step: int) -> Image.Image:
    base = zoom(im, anchor, 1.07 + phase * .025)
    w, h = base.size
    x, y = round(anchor[0] * w), round(anchor[1] * h)
    veil = Image.new("RGBA", base.size, (6, 20, 14, 92))
    mask = Image.new("L", base.size, 92)
    md = ImageDraw.Draw(mask)
    r = radius + phase * 8
    md.ellipse((x-r, y-r, x+r, y+r), fill=0)
    veil.putalpha(mask.filter(ImageFilter.GaussianBlur(12)))
    out = Image.alpha_composite(base.convert("RGBA"), veil)
    d = ImageDraw.Draw(out, "RGBA")
    ripple = radius // 2 + phase * 12
    d.ellipse((x-ripple, y-ripple, x+ripple, y+ripple), outline=(214, 169, 89, 245), width=4)
    # Cursor simples, legível em fundos claros e escuros.
    cursor = [(x+8, y+7), (x+8, y+36), (x+16, y+29), (x+23, y+44), (x+31, y+40), (x+24, y+26), (x+37, y+24)]
    d.polygon(cursor, fill="white", outline=(10, 30, 22, 255))
    return caption(out.convert("RGB"), step, label)


def save_gif(source: Path, module: str, topic: int, device: str, step: int, label: str, anchor):
    original = fit(Image.open(source).convert("RGB"), device)
    radius = 56 if device == "desktop" else 42
    frames = [caption(original, step, label), focus(original, anchor, radius, 0, label, step),
              focus(original, anchor, radius, 1, label, step), caption(original, step, label, True)]
    palette = [f.quantize(colors=32, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.FLOYDSTEINBERG) for f in frames]
    dest = OUT / f"{module}-{topic}-{step}-{device}.gif"
    palette[0].save(dest, save_all=True, append_images=palette[1:], duration=[700, 360, 360, 900],
                    loop=0, optimize=True, disposal=2)


def main():
    total = 0
    for (module, topic), labels in STEPS.items():
        anchors = ANCHORS[(module, topic)]
        for device in ("desktop", "mobile"):
            source = SOURCE / f"{module}-{topic}-{device}.jpg"
            if not source.exists():
                raise FileNotFoundError(source)
            for i, label in enumerate(labels, 1):
                save_gif(source, module, topic, device, i, label, anchors[i - 1])
                total += 1
    print(f"GIFS {total} · {OUT}")


if __name__ == "__main__":
    main()
