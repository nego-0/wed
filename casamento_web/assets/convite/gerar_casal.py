#!/usr/bin/env python3
"""O casal, desenhado uma vez e usado nas duas imagens que o levam.

Sistema local: 0..420 de largura, 0..760 de altura, os pés em y=748.
Sem feicoes - e um exemplo, e nao o retrato de alguem.

A ordem importa: o noivo desenha-se PRIMEIRO, meio passo atras, e a noiva por
cima. E assim que o braco dele lhe passa por tras da cintura sem precisar de
uma mao desenhada, que a esta escala nunca fica bem.
"""


def casal(c):
    """c: dict com vestido_sombra, fato_sombra, pele, pele_sombra, cabelo, joia.
       Os gradientes gvestido / gfato / gpele vem de fora."""
    return f'''
  <!-- O NOIVO, meio passo atras -->
  <g>
    <path d="M348 142 h 26 v 44 q -13 9 -26 0 Z" fill="{c['pele_sombra']}"/>
    <!-- calcas: uma anca so, que depois se abre em duas pernas -->
    <path d="M300 400 L 424 400 C 426 470 424 560 420 748 L 372 748
             C 368 620 366 540 362 470 C 358 540 356 620 352 748 L 304 748
             C 300 560 298 470 300 400 Z" fill="url(#gfato)"/>
    <!-- tronco -->
    <path d="M342 188 C 314 200 302 238 300 286 C 298 336 300 378 302 412
             L 422 412 C 424 378 426 336 424 286 C 422 238 410 200 382 188
             C 370 200 354 200 342 188 Z" fill="url(#gfato)"/>
    <path d="M362 194 L 362 408" stroke="{c['fato_sombra']}" stroke-width="2.4" opacity="0.7"/>
    <path d="M342 190 L 362 226 L 382 190" fill="none" stroke="{c['fato_sombra']}"
          stroke-width="2" opacity="0.55"/>
    <path d="M388 244 h 30 v 36 h -30 z" fill="none" stroke="{c['fato_sombra']}"
          stroke-width="1.6" opacity="0.45"/>
    <!-- braco de fora, afilado -->
    <path d="M418 206 C 434 234 442 296 440 350 C 440 364 428 366 425 352
             C 423 302 418 258 406 226 Z" fill="url(#gfato)"/>
    <!-- o braco de dentro nao se desenha: fica por tras dela -->
    <ellipse cx="360" cy="104" rx="37" ry="44" fill="url(#gpele)"/>
    <path d="M324 100 C 323 66 341 48 361 48 C 382 48 398 66 397 102
             C 385 82 372 74 359 74 C 344 74 331 84 324 100 Z" fill="{c['cabelo']}"/>
  </g>

  <!-- A NOIVA, a frente -->
  <g>
    <!-- o cabelo que fica por tras: volume, e uma onda de um lado so -->
    <ellipse cx="197" cy="114" rx="41" ry="49" fill="{c['cabelo']}"/>
    <path d="M232 120 C 250 168 252 232 240 288 C 234 316 222 336 210 346
             C 226 306 232 250 226 196 Z" fill="{c['cabelo']}"/>
    <path d="M162 128 C 150 176 150 232 158 280 C 160 294 152 300 146 288
             C 136 244 138 176 152 132 Z" fill="{c['cabelo']}"/>
    <path d="M185 164 h 25 v 46 q -12 10 -25 0 Z" fill="{c['pele_sombra']}"/>
    <!-- saia -->
    <path d="M162 344 C 148 442 128 566 110 716 C 107 736 114 748 132 748
             L 266 748 C 284 748 291 736 288 716 C 270 566 250 442 236 344 Z"
          fill="url(#gvestido)"/>
    <path d="M174 356 C 162 462 148 578 136 716" stroke="{c['vestido_sombra']}"
          stroke-width="2" fill="none" opacity="0.45"/>
    <path d="M200 356 C 199 462 198 578 197 716" stroke="{c['vestido_sombra']}"
          stroke-width="1.6" fill="none" opacity="0.3"/>
    <path d="M226 356 C 236 462 250 578 262 716" stroke="{c['vestido_sombra']}"
          stroke-width="2" fill="none" opacity="0.45"/>
    <!-- corpete, com o decote em coracao -->
    <path d="M160 226 C 172 216 188 214 199 222 C 210 214 226 216 238 226
             C 244 262 246 306 240 348 C 212 360 186 360 158 348
             C 152 306 154 262 160 226 Z" fill="url(#gvestido)"/>
    <path d="M160 226 C 174 240 189 242 199 234 C 209 242 224 240 238 226"
          fill="none" stroke="#FFF" stroke-width="2.2" opacity="0.6"/>
    <!-- Um braco desce ao lado, o outro pousa-lhe a mao no peito. Dois bracos
         a fechar sobre a cintura desenhavam uma ferradura, e nao um casal. -->
    <g stroke="{c['pele']}" stroke-width="12" fill="none" stroke-linecap="round">
      <path d="M158 242 C 134 274 130 322 138 366"/>
      <path d="M236 240 C 262 252 288 266 308 280"/>
    </g>
    <ellipse cx="139" cy="376" rx="9" ry="13" fill="{c['pele']}"/>
    <ellipse cx="315" cy="284" rx="16" ry="11" fill="{c['pele']}"
             transform="rotate(16 315 284)"/>
    <circle cx="308" cy="279" r="3.2" fill="{c['joia']}"/>
    <!-- cabeca -->
    <ellipse cx="197" cy="124" rx="38" ry="46" fill="url(#gpele)"/>
    <path d="M160 116 C 158 78 178 58 200 58 C 226 58 244 80 242 118
             C 230 94 216 86 197 88 C 178 90 166 100 160 116 Z" fill="{c['cabelo']}"/>
    <circle cx="163" cy="140" r="3.6" fill="{c['joia']}"/>
  </g>
'''
