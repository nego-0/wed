#!/usr/bin/env python3
"""Gera as quatro imagens de exemplo do convite.

Seguem a COMPOSICAO das fotografias originais - o casal diante do arco
florido, o momento da alianca, o retrato a preto e branco, o abraco de perto -
mas sao desenhadas: um exemplo nao pode ser o retrato de ninguem.

Determinista: a mesma semente da sempre o mesmo desenho.
"""
import random, math, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from gerar_casal import casal

SAIDA = os.path.dirname(os.path.abspath(__file__))

COR = dict(vestido_sombra='#7E9FBA', fato_sombra='#0F2135',
           pele='#8B5E40', pele_sombra='#6B462F', cabelo='#241A16', joia='#E7D9A8')
COR_PB = dict(vestido_sombra='#9E9E9E', fato_sombra='#1B1B1B',
              pele='#807066', pele_sombra='#64564E', cabelo='#221F1D', joia='#D8D8D8')


def flor(idp, cor, miolo):
    petalas = ''.join(
        f'<ellipse cx="{10*math.cos(math.radians(i*72-90)):.1f}" '
        f'cy="{10*math.sin(math.radians(i*72-90)):.1f}" rx="7.6" ry="5.6" '
        f'transform="rotate({i*72:.0f} {10*math.cos(math.radians(i*72-90)):.1f} '
        f'{10*math.sin(math.radians(i*72-90)):.1f})"/>' for i in range(5))
    return (f'<symbol id="{idp}" viewBox="-20 -20 40 40" overflow="visible">'
            f'<g fill="{cor}">{petalas}</g><circle r="3.4" fill="{miolo}"/></symbol>')


def folha(idp, cor, nervura):
    return (f'<symbol id="{idp}" viewBox="-16 -8 32 16" overflow="visible">'
            f'<path d="M-14 0 C -7 -8 7 -8 14 0 C 7 8 -7 8 -14 0 Z" fill="{cor}"/>'
            f'<path d="M-14 0 H 14" stroke="{nervura}" stroke-width="0.8" opacity="0.5"/>'
            f'</symbol>')


def arco(rnd, cx, topo, rx, ry, base, n, espalha, ids):
    """Pontos ao longo de um arco de casamento: sobe, curva, e desce dos lados."""
    pts = []
    for i in range(n):
        a = math.pi * (1 - i / (n - 1))
        x = cx + rx * math.cos(a)
        y = topo + ry * (1 - math.sin(a))
        pts.append((x + rnd.uniform(-espalha, espalha), y + rnd.uniform(-espalha, espalha)))
    for lado in (-1, 1):
        x0 = cx + lado * rx
        for i in range(n // 2):
            y = topo + ry + (base - topo - ry) * i / max(1, n // 2 - 1)
            pts.append((x0 + rnd.uniform(-espalha, espalha),
                        y + rnd.uniform(-espalha, espalha)))
    # um segundo passe, mais solto, para o arco nao parecer um colar de contas
    pts += [(x + rnd.uniform(-42, 42), y + rnd.uniform(-34, 34)) for (x, y) in pts[:int(n * 0.8)]]
    saida = []
    for (x, y) in pts:
        t = 40 * rnd.uniform(0.62, 1.4)
        saida.append(f'<use href="#{rnd.choice(ids)}" x="{x-t/2:.0f}" y="{y-t/2:.0f}" '
                     f'width="{t:.0f}" height="{t:.0f}" '
                     f'transform="rotate({rnd.uniform(0,360):.0f} {x:.0f} {y:.0f})" '
                     f'opacity="{rnd.uniform(0.7,1):.2f}"/>')
    return '\n    '.join(saida)


def pregas(rnd, W, H, passo, cor, op):
    """O cortinado do fundo, como na fotografia."""
    return (f'<g opacity="{op}" fill="{cor}">'
            + ''.join(f'<rect x="{x}" y="0" width="{rnd.choice([14,22,30])}" height="{H}"/>'
                      for x in range(0, W, passo)) + '</g>')


# ------------------------------------------------------------------ CAPA
def hero():
    rnd = random.Random(7)
    W, H = 1000, 1247
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}"
     role="img" aria-label="Ilustracao de um casal de noivos diante de um arco de flores brancas">
  <!-- Imagem de exemplo da CAPA, na composicao da fotografia original: o casal
       de corpo inteiro diante do arco florido, com a relva e a mesa ao fundo. -->
  <defs>
    <linearGradient id="fundo" x1="0" y1="0" x2="0.3" y2="1">
      <stop offset="0" stop-color="#3A4E63"/><stop offset="0.55" stop-color="#25384B"/>
      <stop offset="1" stop-color="#16222F"/></linearGradient>
    <linearGradient id="gvestido" x1="0.1" y1="0" x2="0.9" y2="1">
      <stop offset="0" stop-color="#EAF3FA"/><stop offset="0.45" stop-color="#C3D8E9"/>
      <stop offset="1" stop-color="#93B3CC"/></linearGradient>
    <linearGradient id="gfato" x1="0.15" y1="0" x2="0.85" y2="1">
      <stop offset="0" stop-color="#3C6E9E"/><stop offset="0.5" stop-color="#1E3D63"/>
      <stop offset="1" stop-color="#12243C"/></linearGradient>
    <linearGradient id="gpele" x1="0" y1="0" x2="0.6" y2="1">
      <stop offset="0" stop-color="#A2734F"/><stop offset="1" stop-color="#7A5138"/></linearGradient>
    <radialGradient id="luz" cx="0.5" cy="0.34" r="0.62">
      <stop offset="0" stop-color="#A9C0D0" stop-opacity="0.34"/>
      <stop offset="1" stop-color="#A9C0D0" stop-opacity="0"/></radialGradient>
    <radialGradient id="vinheta" cx="0.5" cy="0.44" r="0.78">
      <stop offset="0.54" stop-color="#000" stop-opacity="0"/>
      <stop offset="1" stop-color="#000" stop-opacity="0.5"/></radialGradient>
    {flor('fl', '#F5F1E8', '#E0D8C6')}
    {flor('fl2', '#FDFBF6', '#D8CFBB')}
    {folha('fo', '#54705A', '#31462F')}
  </defs>

  <rect width="{W}" height="{H}" fill="url(#fundo)"/>
  {pregas(rnd, W, H, 62, '#DDE6EE', 0.14)}
  <rect width="{W}" height="{H}" fill="url(#luz)"/>

  <!-- relva, e a mesa branca que atravessa o fundo -->
  <rect x="0" y="1004" width="{W}" height="243" fill="#3E5C3C"/>
  <rect x="0" y="1004" width="{W}" height="10" fill="#4E7049" opacity="0.7"/>
  <g fill="#F2EEE4" opacity="0.92">
    <rect x="52" y="944" width="896" height="24" rx="6"/>
    <rect x="116" y="968" width="13" height="86"/><rect x="871" y="968" width="13" height="86"/>
  </g>

  <g>
    {arco(rnd, 500, 120, 302, 258, 986, 40, 24, ('fl', 'fl2', 'fo'))}
  </g>

  <!-- o casal: 420x760 no seu proprio sistema, posto ao centro -->
  <g transform="translate(172 246) scale(1.26)">
    {casal(COR)}
  </g>

  <rect width="{W}" height="{H}" fill="url(#vinheta)"/>
</svg>
'''


# -------------------------------------------------------------- HISTORIA
def historia():
    """O momento da alianca. Sem maos: um par de maos desenhado a esta escala
       nunca fica bem, e o que a fotografia mostra e o brilho ao centro."""
    rnd = random.Random(11)
    W, H = 1200, 750
    bokeh = ''.join(
        f'<circle cx="{rnd.uniform(0, W):.0f}" cy="{rnd.uniform(0, H):.0f}" '
        f'r="{rnd.uniform(10, 46):.0f}" fill="#CFE4F2" opacity="{rnd.uniform(0.05, 0.16):.2f}"/>'
        for _ in range(26))
    lantejoulas = ''.join(
        f'<circle cx="{rnd.uniform(1140, 1300):.0f}" cy="{rnd.uniform(220, 660):.0f}" '
        f'r="{rnd.uniform(2, 6):.1f}" fill="#FFF" opacity="{rnd.uniform(0.35, 0.9):.2f}"/>'
        for _ in range(40))
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}"
     role="img" aria-label="Ilustracao de duas aliancas douradas sobre um fundo azul desfocado">
  <!-- Imagem de exemplo da HISTORIA: o momento da alianca, como na fotografia
       original - o azul do fato a encher o quadro e o brilho ao centro. -->
  <defs>
    <linearGradient id="hfundo" x1="0" y1="0" x2="0.4" y2="1">
      <stop offset="0" stop-color="#3C6E9E"/><stop offset="0.45" stop-color="#1E3D63"/>
      <stop offset="1" stop-color="#0E1E33"/></linearGradient>
    <linearGradient id="haro" x1="0" y1="0" x2="0.6" y2="1">
      <stop offset="0" stop-color="#F0DFAE"/><stop offset="0.5" stop-color="#C9A227"/>
      <stop offset="1" stop-color="#8A6E1B"/></linearGradient>
    <radialGradient id="hbrilho" cx="0.5" cy="0.5" r="0.5">
      <stop offset="0" stop-color="#FFF" stop-opacity="0.95"/>
      <stop offset="1" stop-color="#FFF" stop-opacity="0"/></radialGradient>
    <radialGradient id="hvin" cx="0.46" cy="0.48" r="0.72">
      <stop offset="0.48" stop-color="#000" stop-opacity="0"/>
      <stop offset="1" stop-color="#000" stop-opacity="0.55"/></radialGradient>
    <filter id="desfoque" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="18"/></filter>
    <filter id="desfoque2" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="7"/></filter>
  </defs>

  <rect width="{W}" height="{H}" fill="url(#hfundo)"/>
  <!-- as pregas e as costuras da camisa, desfocadas: e o fundo da fotografia -->
  <g filter="url(#desfoque)" opacity="0.5">
    <path d="M300 0 V 750" stroke="#0E1E33" stroke-width="26" fill="none"/>
    <path d="M170 60 h 130 v 160 h -130 z" stroke="#0E1E33" stroke-width="14" fill="none"/>
    <path d="M520 30 h 140 v 175 h -140 z" stroke="#0E1E33" stroke-width="14" fill="none"/>
    <path d="M70 320 C 140 360 140 470 70 520" stroke="#4C82B4" stroke-width="16" fill="none"/>
    <path d="M860 0 C 900 200 900 520 860 750" stroke="#4C82B4" stroke-width="20" fill="none"/>
  </g>
  <g filter="url(#desfoque2)">{bokeh}</g>

  <!-- a manga bordada dela, desfocada e a sair do quadro: na fotografia esta
       ali, mas so como um brilho fora de foco -->
  <g filter="url(#desfoque)" opacity="0.5">
    <path d="M1300 180 C 1180 220 1130 340 1140 460 C 1148 570 1210 650 1300 690 Z"
          fill="#9FC0DA"/>
  </g>
  <g opacity="0.65">{lantejoulas}</g>

  <!-- as duas aliancas, ao centro e nitidas: e para ali que se olha -->
  <g transform="translate(506 396)">
    <ellipse rx="112" ry="104" fill="none" stroke="url(#haro)" stroke-width="21"/>
    <ellipse rx="112" ry="104" fill="none" stroke="#F6EBC4" stroke-width="4" opacity="0.7"/>
  </g>
  <g transform="translate(650 426)">
    <ellipse rx="96" ry="90" fill="none" stroke="url(#haro)" stroke-width="18" opacity="0.95"/>
    <ellipse rx="96" ry="90" fill="none" stroke="#F6EBC4" stroke-width="3.4" opacity="0.6"/>
    <g transform="translate(0 -90)">
      <path d="M0 -46 L 26 -14 L 0 26 L -26 -14 Z" fill="#F8F3E2"/>
      <path d="M0 -46 L 26 -14 L 0 26 Z" fill="#DACDA9"/>
      <path d="M-26 -14 H 26" stroke="#FFF" stroke-width="2.4" opacity="0.8"/>
      <circle cy="-10" r="86" fill="url(#hbrilho)" opacity="0.55"/>
    </g>
  </g>

  <rect width="{W}" height="{H}" fill="url(#hvin)"/>
</svg>
'''


# ------------------------------------------------------------- INTERLUDIO
def interludio():
    rnd = random.Random(23)
    W, H = 1300, 812
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}"
     role="img" aria-label="Ilustracao a preto e branco de um casal diante de um arco de flores">
  <!-- Imagem de exemplo do INTERLUDIO. A fotografia original e a preto e
       branco, e esta tambem: e o descanso a meio do convite. -->
  <defs>
    <linearGradient id="ifundo" x1="0" y1="0" x2="0.2" y2="1">
      <stop offset="0" stop-color="#616161"/><stop offset="0.5" stop-color="#3B3B3B"/>
      <stop offset="1" stop-color="#1C1C1C"/></linearGradient>
    <linearGradient id="gvestido" x1="0.1" y1="0" x2="0.9" y2="1">
      <stop offset="0" stop-color="#F4F4F4"/><stop offset="0.45" stop-color="#D2D2D2"/>
      <stop offset="1" stop-color="#A0A0A0"/></linearGradient>
    <linearGradient id="gfato" x1="0.15" y1="0" x2="0.85" y2="1">
      <stop offset="0" stop-color="#5E5E5E"/><stop offset="0.5" stop-color="#3A3A3A"/>
      <stop offset="1" stop-color="#242424"/></linearGradient>
    <linearGradient id="gpele" x1="0" y1="0" x2="0.6" y2="1">
      <stop offset="0" stop-color="#94847A"/><stop offset="1" stop-color="#6E6058"/></linearGradient>
    <radialGradient id="iluz" cx="0.5" cy="0.32" r="0.6">
      <stop offset="0" stop-color="#FFF" stop-opacity="0.22"/>
      <stop offset="1" stop-color="#FFF" stop-opacity="0"/></radialGradient>
    <radialGradient id="ivin" cx="0.5" cy="0.46" r="0.74">
      <stop offset="0.5" stop-color="#000" stop-opacity="0"/>
      <stop offset="1" stop-color="#000" stop-opacity="0.58"/></radialGradient>
    {flor('ifl', '#F1F1F1', '#C6C6C6')}
    {flor('ifl2', '#FAFAFA', '#D4D4D4')}
    {folha('ifo', '#8C8C8C', '#5E5E5E')}
  </defs>

  <rect width="{W}" height="{H}" fill="url(#ifundo)"/>
  {pregas(rnd, W, H, 70, '#FFF', 0.12)}
  <rect width="{W}" height="{H}" fill="url(#iluz)"/>

  <g fill="#E6E6E6" opacity="0.85">
    <rect x="150" y="596" width="1000" height="22" rx="6"/>
    <rect x="228" y="618" width="12" height="76"/><rect x="1060" y="618" width="12" height="76"/>
  </g>

  <g>
    {arco(rnd, 650, 20, 620, 236, 760, 44, 26, ('ifl', 'ifl2', 'ifo'))}
  </g>

  <!-- Mais perto do que na capa: o casal cortado pouco abaixo da cintura. -->
  <g transform="translate(258 6) scale(1.5)">
    {casal(COR_PB)}
  </g>

  <rect width="{W}" height="{H}" fill="url(#ivin)"/>
</svg>
'''


# ----------------------------------------------------------------- ACESSO
def acesso():
    """O abraco de perto. A fotografia original e um plano muito fechado sobre
       o mesmo casal, com as flores desfocadas de um lado e a alianca a apanhar
       a luz - e e isso: o mesmo casal, cortado aos ombros.

       Tentei duas vezes desenhar a mao dela sobre o ombro dele, que e o que a
       fotografia mostra em primeiro plano. Uma mao a este tamanho nao me sai
       bem, e uma mao mal desenhada estraga a imagem toda; o plano fechado sobre
       os dois diz o mesmo e nao mente sobre o que aqui se consegue fazer."""
    rnd = random.Random(31)
    W, H = 1300, 812
    fundo_flores = ''.join(
        f'<use href="#afl" x="{rnd.uniform(-60, 300):.0f}" y="{rnd.uniform(-60, 800):.0f}" '
        f'width="{rnd.uniform(100, 190):.0f}" height="{rnd.uniform(100, 190):.0f}" '
        f'opacity="{rnd.uniform(0.22, 0.55):.2f}"/>' for _ in range(24))
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}"
     role="img" aria-label="Ilustracao de um casal de noivos em plano aproximado, com a alianca em destaque">
  <!-- Imagem de exemplo do ACESSO: o plano fechado da fotografia original, com
       as flores desfocadas de um lado e a alianca a apanhar a luz. -->
  <defs>
    <linearGradient id="afundo" x1="0" y1="0" x2="0.3" y2="1">
      <stop offset="0" stop-color="#3B4C5A"/><stop offset="0.5" stop-color="#26323D"/>
      <stop offset="1" stop-color="#141B22"/></linearGradient>
    <linearGradient id="gvestido" x1="0.1" y1="0" x2="0.9" y2="1">
      <stop offset="0" stop-color="#EAF3FA"/><stop offset="0.45" stop-color="#C3D8E9"/>
      <stop offset="1" stop-color="#93B3CC"/></linearGradient>
    <linearGradient id="gfato" x1="0.15" y1="0" x2="0.85" y2="1">
      <stop offset="0" stop-color="#4B84B8"/><stop offset="0.5" stop-color="#1E3D63"/>
      <stop offset="1" stop-color="#0E1D31"/></linearGradient>
    <linearGradient id="gpele" x1="0" y1="0" x2="0.6" y2="1">
      <stop offset="0" stop-color="#A2734F"/><stop offset="1" stop-color="#7A5138"/></linearGradient>
    <radialGradient id="aluz" cx="0.58" cy="0.24" r="0.52">
      <stop offset="0" stop-color="#CBDCE8" stop-opacity="0.3"/>
      <stop offset="1" stop-color="#CBDCE8" stop-opacity="0"/></radialGradient>
    <radialGradient id="avin" cx="0.56" cy="0.42" r="0.7">
      <stop offset="0.4" stop-color="#000" stop-opacity="0"/>
      <stop offset="1" stop-color="#000" stop-opacity="0.66"/></radialGradient>
    <radialGradient id="abrilho" cx="0.5" cy="0.5" r="0.5">
      <stop offset="0" stop-color="#FFF" stop-opacity="0.95"/>
      <stop offset="1" stop-color="#FFF" stop-opacity="0"/></radialGradient>
    <filter id="adesfoque" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="15"/></filter>
    {flor('afl', '#E9E6DC', '#C9C4B4')}
  </defs>

  <rect width="{W}" height="{H}" fill="url(#afundo)"/>
  <rect width="{W}" height="{H}" fill="url(#aluz)"/>
  <g filter="url(#adesfoque)">{fundo_flores}</g>

  <!-- O mesmo casal, num plano medio e encostado a direita: as flores ficam do
       outro lado, como na fotografia. Mais fechado do que isto, o rosto sem
       feicoes cresce demais e passa a incomodar. -->
  <g transform="translate(372 58) scale(1.42)">
    {casal(COR)}
  </g>

  <!-- A alianca, sobre a mao que lhe pousa no peito: o ponto de luz. -->
  <g transform="translate(819 461) rotate(-12)">
    <ellipse rx="22" ry="18" fill="none" stroke="#C9A227" stroke-width="7"/>
    <ellipse rx="22" ry="18" fill="none" stroke="#F1DFA0" stroke-width="2.4"/>
    <path d="M0 -36 l 12 14 l -12 14 l -12 -14 z" fill="#F8F3E2"/>
    <path d="M0 -36 l 12 14 l -12 14 z" fill="#D6C9A6"/>
    <circle cy="-22" r="46" fill="url(#abrilho)" opacity="0.55"/>
  </g>

  <rect width="{W}" height="{H}" fill="url(#avin)"/>
</svg>
'''


if __name__ == '__main__':
    for nome, fn in [('hero', hero), ('historia', historia),
                     ('interludio', interludio), ('acesso', acesso)]:
        caminho = os.path.join(SAIDA, f'generico-{nome}.svg')
        open(caminho, 'w').write(fn())
        print('escrito', caminho, os.path.getsize(caminho), 'bytes')
