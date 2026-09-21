<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parcial-cabecalho.php';
require_once __DIR__ . '/personalizacao.php';
exigirAdmin();
// O painel é a lista de convidados: sem esse módulo não há nada para mostrar.
exigirModulo('convidados');
$DEFS = defsAtuais($conn);
$CAS  = casalDaFicha($conn);
$dataExt = dataExtensa($DEFS['evento.data']);
$totalConvites  = (int)$conn->query("SELECT COUNT(*) FROM {$P}convites c WHERE " . doCasamento('c') . " AND ".soVivos($conn,'c')."")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/mesa-icone.css') ?>" rel="stylesheet">
<script src="<?= asset('assets/qrious.min.js') ?>"></script>
<style>
  .barra-acoes{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.25rem; }
  .barra-acoes .cresce{ flex:1 1 200px; }
  /* Uma pessoa = uma linha. Os pormenores (género, mesa, papel, brinde) são
     pedidos na mesma, mas só quando se abrem: seis controlos lado a lado não
     cabiam na largura do modal e quebravam para uma segunda linha, o que fazia
     uma família de quatro parecer um formulário de dezasseis campos. */
  /* Uma pessoa = duas linhas de colunas alinhadas. Em cima o nome (metade),
     a mesa e os brindes (um quarto cada); em baixo as quatro pastilhas, um
     quarto cada, a caírem debaixo dos campos de cima. Tudo à vista: nada de
     abrir para ver o que já está preenchido. */
  .membro-linha{ display:grid; grid-template-columns:2fr 1fr 1fr auto;
                 gap:.4rem .5rem; align-items:center;
                 margin-bottom:.55rem; padding-bottom:.55rem; border-bottom:1px solid var(--line); }
  .membro-linha:last-child{ border-bottom:0; }
  .membro-linha input[type=text]{ min-width:0; margin:0; }
  /* A segunda linha ocupa as três colunas dos campos — não a do ✕ —, para as
     pastilhas ficarem debaixo deles e não desalinhadas por 34px. */
  .m-extras{ grid-column:1 / 4; display:grid; grid-template-columns:1fr 1fr; gap:.4rem; align-items:center; }
  .m-extras select{ min-width:0; font-size:var(--t-denso); padding:.4rem .5rem; margin:0; }
  .membro-linha .m-mesa{ min-width:0; font-size:var(--t-denso); padding:.42rem .5rem; margin:0; }
  .membro-linha .m-brinde{ display:inline-flex; align-items:center; justify-content:center; gap:.3rem;
                           font-size:var(--t-etiqueta); font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#6d746c;
                           white-space:nowrap; cursor:pointer; min-width:0; }
  .membro-linha .m-brinde input{ width:16px; height:16px; accent-color:var(--gold); cursor:pointer; flex:none; }
  /* Género e papel: dois botões cada, em vez de duas caixas de escolha. São
     duas perguntas de duas respostas — numa lista dessas, o menu que abre e
     fecha esconde metade da resposta e obriga a um clique a mais para ver a
     outra. Aqui está tudo à vista, e vê-se o que está escolhido sem abrir nada. */
  .m-extras .seg{ display:grid; grid-template-columns:1fr 1fr; min-width:0; background:var(--card);
                  border:1px solid var(--line); border-radius:9px; overflow:hidden; }
  .m-extras .seg button{ min-width:0; border:0; border-right:1px solid var(--line);
                         background:transparent; font:inherit; font-size:var(--t-apoio); color:#6d746c;
                         padding:.42rem .3rem; cursor:pointer; white-space:nowrap;
                         overflow:hidden; text-overflow:ellipsis; }
  .m-extras .seg button:last-child{ border-right:0; }
  .m-extras .seg button:hover:not(:disabled){ background:var(--cream); }
  .m-extras .seg button.on{ background:var(--gold-pale); color:var(--gold-texto); font-weight:600;
                            box-shadow:inset 0 0 0 1px var(--gold-soft); }
  .m-extras .seg button:disabled{ opacity:.45; cursor:not-allowed; }
  /* Num ecrã estreito, quatro pastilhas de 25% deixam de caber sem cortar as
     palavras: passam a duas por linha, cada uma com metade. */
  @media (max-width:560px){
    .membro-linha{ grid-template-columns:1fr 1fr auto; }
    .membro-linha input[type=text]{ grid-column:1 / 4; }
    .m-extras{ grid-column:1 / 4; grid-template-columns:1fr; }
  }
  /* Ícones de género / brinde nas pastilhas */
  /* Eram os caracteres ♂ ♀ 🎁, e isso obrigava a pedir uma fonte de símbolos
     — 'Segoe UI Symbol', 'Noto Sans Symbols' — porque as fontes de texto ou
     não os têm (e sai o quadrado) ou desenham-nos finos de mais para se verem
     a este tamanho. Agora são desenhos da casa: acompanham o tamanho da letra
     ao lado, herdam a cor, e são o mesmo traço em qualquer aparelho. */
  .gi{ line-height:0; font-size:1.15em; }
  .gi-m{ color:#2f5568; } .gi-f{ color:#9c4256; } .gi-b{ font-weight:400; font-size:1em; }
  .sugestoes{ display:flex; gap:.4rem; flex-wrap:wrap; margin:.4rem 0 .2rem; }
  .sugestao{ background:var(--cream); border:1px solid var(--line); border-radius:50px; padding:.25rem .7rem; font-size:var(--t-apoio); cursor:pointer; }
  .sugestao:hover{ background:var(--gold-pale); border-color:var(--gold-soft); }
  .previa{ background:var(--forest-deep); color:var(--gold-pale); font-family:var(--serif); font-size:var(--t-sub); padding:.7rem 1rem; border-radius:10px; text-align:center; margin:.3rem 0 1rem; }

  /* ---- Formulário do convite: secções com marco visível ----
     Era uma lista plana de treze campos sem hierarquia. Os mesmos campos,
     arrumados por assunto, dão ao olho pontos de referência. */
  .modal-convite{ max-width:720px; }
  /* Dentro do formulário, os seletores são escolhas rápidas, não cartazes:
     ícone e texto na mesma linha poupam três alturas de botão no ecrã. */
  .modal-convite .pk{ flex-direction:row; justify-content:center; gap:.4rem; padding:.5rem .4rem; }
  .modal-convite .pk .pk-ic svg{ width:16px; height:16px; }
  /* A prévia mostra como o nome sai no convite — é uma confirmação, não o
     protagonista do ecrã. */
  .modal-convite .previa{ font-size:var(--t-corpo); padding:.5rem .9rem; margin:.45rem 0 0; text-align:left;
                          display:flex; align-items:baseline; gap:.6rem; }
  .modal-convite .previa::before{ content:'No convite'; font-family:var(--sans); font-size:var(--t-etiqueta); font-weight:600;
                                  text-transform:uppercase; letter-spacing:.08em; color:#8a9a8d; flex:none; }
  .fset{ border-top:1px solid var(--line); margin-top:1.25rem; padding-top:1.1rem; }
  .fset:first-of-type{ border-top:0; margin-top:0; padding-top:0; }
  .fset-t{ font-family:var(--serif); font-size:var(--t-sub); font-weight:600; color:var(--ink);
           margin:0 0 .75rem; display:flex; align-items:baseline; gap:.5rem; }
  .fset-t .cont{ font-family:var(--sans); font-size:var(--t-apoio); font-weight:400; color:var(--ink-fraco);
                 text-transform:none; letter-spacing:0; }
  /* Duas colunas para os campos curtos: menos rolo, mesma informação. */
  .lf2{ display:grid; grid-template-columns:1fr 1fr; gap:.9rem; margin-top:.9rem; }
  @media (max-width:620px){
    .lf2{ grid-template-columns:1fr; }
    /* No telemóvel, quatro botões lado a lado não cabem: passam a duas filas
       em vez de rebentarem a largura do modal. */
    .modal-convite .picker{ flex-wrap:wrap; }
    .modal-convite .pk{ flex:1 1 calc(50% - .3rem); }
  }
  .lf2 > div{ min-width:0; }
  .lf2 label{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  /* "opcional" não precisa de gritar em maiúsculas ao lado da etiqueta. */
  label .opt{ text-transform:none; letter-spacing:0; font-weight:400; color:var(--ink-fraco);
             /* absoluto, e não .92em: o rótulo que o contém está nos 11px, e um em
                em cima disso dava 10,1px a uma frase inteira. */
             font-size:var(--t-apoio); }
  /* O campo "Lugares" saiu; esta linha diz de onde passam a vir. */
  .dica-lugares{ font-size:var(--t-apoio); color:var(--ink-fraco); margin-top:.5rem; }
  .link-box{ display:flex; gap:.4rem; align-items:center; background:var(--cream); border:1px solid var(--line); border-radius:10px; padding:.4rem .4rem .4rem .8rem; font-size:var(--t-apoio); }
  .link-box input{ border:none; background:transparent; padding:.2rem 0; font-size:var(--t-apoio); }
  .link-box input:focus{ box-shadow:none; }
  .qr-holder{ text-align:center; padding:1rem; }
  .qr-holder canvas{ border:8px solid #fff; border-radius:10px; box-shadow:var(--shadow); }
  .mesa-item{ display:flex; align-items:center; gap:.6rem; padding:.6rem .2rem; border-bottom:1px solid var(--line); }
  .mesa-item .info{ flex:1; }
  .mesa-item .ocup{ font-size:var(--t-apoio); color:var(--ink-fraco); }
  .barra-ocup{ height:6px; background:var(--cream); border-radius:50px; overflow:hidden; margin-top:.3rem; }
  .barra-ocup span{ display:block; height:100%; background:var(--gold); }
  .barra-ocup span.cheio{ background:var(--danger); }
  svg.ic{ width:16px; height:16px; vertical-align:-2px; }

  /* Cartões de filtro com ícones (painel coeso: cada cartão filtra a lista) */
  .grelha-stats{ display:grid; grid-template-columns:repeat(auto-fit,minmax(112px,1fr)); gap:.7rem; }
  .stat-f{ background:var(--card); border:1px solid var(--line); border-radius:14px; padding:.85rem .6rem; cursor:pointer;
    display:flex; flex-direction:column; align-items:center; gap:.25rem; text-align:center; transition:.16s;
    font-family:inherit; color:var(--text); }
  .stat-f:hover{ border-color:var(--gold-soft); box-shadow:0 6px 16px rgba(180,134,74,.12); transform:translateY(-2px); }
  .stat-f .si{ display:flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:50%;
    /* O ÍCONE do cartão. Em --forest sobre --cream, no tema escuro, é
       #0E1B25 sobre #1E2A33 — o desenho fica lá, e não se vê nenhum. */
    background:var(--cream); color:var(--gold-texto); margin-bottom:.1rem; }
  .stat-f .si svg{ width:18px; height:18px; }
  .stat-f .si i[data-ico]{ width:18px; height:18px; display:block; }
  .stat-f .si i[data-ico] svg{ width:100%; height:100%; }
  .stat-f .sn{ font-family:var(--serif); font-size:var(--t-seccao); font-weight:700; color:var(--ink); line-height:1; }
  .stat-f .sl{ font-size:var(--t-etiqueta); font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--ink-fraco); }
  .stat-f.ativo{ border-color:var(--forest); background:var(--forest); }
  .stat-f.ativo .sn{ color:var(--ivory); } .stat-f.ativo .sl{ color:var(--topo-txt); }
  /* O cartão escolhido tem fundo --forest, que é escuro nos QUATRO temas.
     --gold-pale é um creme nos claros e uma SUPERFÍCIE escura no escuro:
     ali dentro dava 1,55:1 e o ícone do cartão aberto desaparecia. A tinta
     de uma superfície que não vira é a que também não vira. */
  .stat-f.ativo .si{ background:rgba(255,255,255,.15); color:var(--topo-txt); }
  /* Verde e rosa estavam cravados à mão: o mesmo tom nos quatro temas, e no
     escuro o rosa media 2,75:1. --ok e --danger são os mesmos sinais, a
     virar com o tema. */
  .stat-f.verde .si{ color:var(--ok); } .stat-f.ouro .si{ color:var(--gold); } .stat-f.rosa .si{ color:var(--danger); }
  /* Os cartões extra fazem parte da mesma grelha (display:contents) — assim
     alinham com os outros em vez de formarem uma segunda grelha desencontrada. */
  .stats-extra{ display:contents; }
  .btn-stats-mais{ display:none; }
  .esp-stats{ height:0; }   /* respiro entre os cartões e a barra de ações */

  /* O cartão que filtra E tem página: o filtro é o cartão todo, e o canto leva
     à página. São irmãos, não um dentro do outro — um <a> dentro de um
     <button> não é HTML válido e o teclado não sabe o que fazer com ele. */
  .stat-cx{ position:relative; display:grid; }
  .stat-cx > .stat-f{ width:100%; }
  /* Discreta, não invisível. Com opacity:.55 media 2,2:1 contra o cartão nos
     três temas claros — medido nos PIXÉIS PINTADOS, não na folha de estilo —, e
     3:1 é o mínimo de um comando que se carrega. É a única saída do cartão
     para a página do número: apagá-la é escondê-la. Fica a --ink-fraco por
     inteiro, que já é a tinta do secundário, e o tamanho é que a faz discreta. */
  .stat-ir{ position:absolute; top:.3rem; right:.3rem; width:22px; height:22px;
    display:flex; align-items:center; justify-content:center; border-radius:7px;
    color:var(--ink-fraco); transition:.14s; }
  .stat-ir:hover{ background:var(--cream); color:var(--gold-texto); }
  .stat-ir i{ width:13px; height:13px; display:block; }
  .stat-cx > .stat-f.ativo ~ .stat-ir{ color:var(--topo-txt); opacity:.8; }
  a.stat-f{ text-decoration:none; }

  /* A arrumar: as setas que mexem o cartão de lugar. Só aparecem no modo, para
     não porem dois alvos em cada cartão no uso normal. */
  .stat-arr{ position:relative; display:grid; }
  /* O cartão abre espaço em baixo enquanto se arruma: sem isto as setas
     assentavam por cima do rótulo e da linha de baixo, e ficava-se a escolher
     a ordem de cartões cujo nome deixava de se ler. */
  /* Pelo #stats: a regra do telemóvel repõe `padding` inteiro mais abaixo e
     levava este fundo à frente. */
  /* O fundo reservado tem de dar para a ALTURA REAL das setas, que no
     telemóvel são alvos de 44px e não os 24px que o desenho pedia — medido, e
     não suposto: com 35px de fundo a linha de baixo do cartão ficava debaixo
     delas e liam-se cartões sem saber de que números eram. */
  #stats .stat-arr .stat-f{ padding-bottom:3.6rem; }
  .stat-setas{ position:absolute; left:0; right:0; bottom:.4rem; display:flex; justify-content:center; gap:.3rem; }
  .stat-setas button{ width:26px; height:24px; border-radius:7px; border:1px solid var(--line);
    background:var(--card); color:var(--gold-texto); font-size:var(--t-denso); line-height:1;
    cursor:pointer; font-family:inherit; padding:0; }
  .stat-setas button:hover{ border-color:var(--gold-soft); }
  .stats-baixo{ display:flex; gap:.5rem; align-items:center; }
  .stats-baixo .btn-stats-mais{ flex:1; }
  .bt-arrumar{ flex:none; width:38px; height:38px; display:flex; align-items:center; justify-content:center;
    background:var(--card); border:1px solid var(--line); border-radius:12px; cursor:pointer;
    color:var(--ink-fraco); transition:.14s; }
  .bt-arrumar:hover{ border-color:var(--gold-soft); color:var(--gold-texto); }
  .bt-arrumar.on{ background:var(--gold-pale); border-color:var(--gold-soft); color:var(--gold-texto); }
  .bt-arrumar i{ width:17px; height:17px; display:block; }

  /* Fim da lista: "mostrar mais" e a contagem do que já se vê */
  .btn-mais-lista{ display:flex; flex-direction:column; align-items:center; gap:.15rem; width:100%; margin-top:.4rem;
    background:var(--card); border:1px solid var(--line); border-radius:14px; padding:.8rem; cursor:pointer;
    font-family:inherit; font-size:var(--t-corpo); color:var(--gold-texto); transition:.16s; }
  .btn-mais-lista:hover{ border-color:var(--gold-soft); box-shadow:0 6px 16px rgba(180,134,74,.12); }
  .btn-mais-lista small{ color:var(--ink-fraco); font-size:var(--t-apoio); }
  .btn-mais-lista .conta-extra{ display:inline-block; min-width:18px; padding:0 .35rem; border-radius:50px;
    background:var(--cream); color:var(--ink-fraco); font-size:var(--t-apoio); }
  .fim-lista{ text-align:center; color:var(--ink-fraco); font-size:var(--t-apoio); margin:.9rem 0 0; }
  @media (max-width:720px){
    /* Colunas fixas: com auto-fit os 4 cartões de base ficavam 3+1, com um
       cartão órfão na última linha. Assim formam um bloco certinho. */
    .grelha-stats{ grid-template-columns:repeat(2,1fr); gap:.5rem; }
    .stat-f{ padding:.6rem .4rem; }
    .stat-f .si{ width:28px; height:28px; }
    .stat-f .sn{ font-size:var(--t-titulo); }
    .stats-extra:not(.aberto){ display:none; }
    .btn-stats-mais{ display:block; width:100%; margin:.6rem 0 0; background:var(--card); border:1px solid var(--line);
      /* --forest é ENCHIMENTO: sobre --card, no escuro, «Mais filtros» ficava
         a 1,2:1 — lia-se a palavra por se saber que ela lá estava. */
      border-radius:12px; padding:.55rem; font-family:inherit; font-size:var(--t-denso); color:var(--gold-texto); cursor:pointer; }
    .btn-stats-mais:hover{ border-color:var(--gold-soft); }
    .conta-extra{ display:inline-block; min-width:18px; padding:0 .3rem; margin-left:.25rem; border-radius:50px;
      background:var(--cream); color:var(--ink-fraco); font-size:var(--t-apoio); }
  }
  .stat-f.ativo.verde,.stat-f.ativo.rosa,.stat-f.ativo.ouro{ background:var(--forest); }

  /* Ícones na linha do convite */
  .selo-tipo svg{ width:18px; height:18px; }
  .lado-ic{ display:inline-flex; align-items:center; gap:.25rem; color:var(--gold); }
  .lado-ic svg{ width:15px; height:15px; }
  .stat-f .ss{ font-size:var(--t-apoio); color:var(--ink-fraco); margin-top:.1rem; }
  .stat-f .ss .gi{ font-size:var(--t-denso); }
  .stat-f.ativo .ss{ color:rgba(239,227,203,.7); }
  /* Cartão selecionado (fundo verde): os sinais de género herdam o tom claro. */
  .stat-f.ativo .ss .gi{ color:inherit; }

  /* O tecto da licença: o aviso e o botão que se fecha. */
  .pc-aviso{ margin-top:.6rem; font-size:var(--t-apoio); line-height:1.5; color:var(--text);
    background:var(--warn-bg); border-left:3px solid var(--warn); border-radius:8px;
    padding:.55rem .75rem; }
  .pc-aviso.cheio{ background:var(--danger-bg); border-left-color:var(--danger); }
  .pc-aviso a{ color:var(--gold-deep); font-weight:600; text-decoration:underline; }
  .pc-nums .v-falta{ color:var(--danger); }
  .pc-barra.cheia .pc-conv{ background:var(--danger); }
  .btn.travado, .btn:disabled{ opacity:.45; cursor:not-allowed; }
  .btn.travado:hover{ transform:none; box-shadow:none; }

  /* Barra de progresso de capacidade */
  .progresso-cap{ background:var(--card); border:1px solid var(--line); border-radius:14px; padding:1rem 1.15rem; }
  .pc-topo{ display:flex; justify-content:space-between; align-items:baseline; gap:.6rem; flex-wrap:wrap; margin-bottom:.6rem; }
  .pc-tit{ font-family:var(--serif); font-size:var(--t-sub); font-weight:600; color:var(--ink); }
  .pc-nums{ font-size:var(--t-apoio); color:var(--ink-fraco); } .pc-nums b{ color:var(--ink); font-weight:600; }
  /* Verde cravado à mão: no tema escuro ficava a 2,98:1 sobre o cartão.
     --ok é o mesmo verde, mas o que vira com o tema. */
  .pc-nums .v-conf{ color:var(--ok); }
  .pc-barra{ position:relative; height:14px; background:var(--cream); border-radius:50px; overflow:hidden; }
  .pc-conv{ position:absolute; left:0; top:0; height:100%; background:var(--gold-soft); border-radius:50px; transition:width .5s ease; }
  .pc-conf{ position:absolute; left:0; top:0; height:100%; background:linear-gradient(90deg,var(--forest),#1f7a3d); border-radius:50px; transition:width .5s ease; }

  /* Chips de mesa (filtro por ícones/etiquetas) */
  .chips-mesa{ display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; }
  .chips-mesa:empty{ display:none; }
  .chips-lbl{ font-size:var(--t-etiqueta); font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--ink-fraco); margin-right:.2rem; }
  .chip-m{ display:inline-flex; align-items:center; gap:.35rem; background:var(--card); border:1px solid var(--line); border-radius:50px; padding:.3rem .8rem; font-size:var(--t-apoio); cursor:pointer; color:var(--text); font-family:inherit; }
  .chip-m:hover{ border-color:var(--gold-soft); }
  .chip-m.on{ background:var(--forest); color:#fff; border-color:var(--forest); }
  /* --forest é um ENCHIMENTO, não uma tinta: no tema escuro é #0E1B25, e
     sobre --cream (#1E2A33) dava 1,19:1 — a contagem do chip não se via. */
  .chip-n{ background:var(--cream); color:var(--text); border-radius:50px; padding:0 .4rem; font-size:var(--t-apoio); }
  .chip-m.on .chip-n{ background:rgba(255,255,255,.2); color:#fff; }

  /* Seletores de ícones no modal (Tipo / Lado) */
  .picker{ display:flex; gap:.5rem; }
  .pk{ flex:1; display:flex; flex-direction:column; align-items:center; gap:.25rem; padding:.6rem .3rem; border:1.5px solid var(--line); border-radius:12px; background:var(--card); cursor:pointer; font-size:var(--t-apoio); color:var(--text); font-family:inherit; }
  .pk:hover{ border-color:var(--gold-soft); }
  .pk .pk-ic{ display:inline-flex; color:var(--gold); } .pk .pk-ic svg{ width:20px; height:20px; }
  .pk.on{ border-color:var(--forest); background:var(--cream); color:var(--ink); font-weight:500; }
  .pk.on .pk-ic{ color:var(--gold-texto); }

  /* Seleção múltipla e ações em massa */
  .sel-conv{ display:flex; align-items:center; padding-right:.2rem; cursor:pointer; }
  .sel-conv input{ width:17px; height:17px; accent-color:var(--forest); cursor:pointer; }
  .convite-row.selecionada{ background:var(--gold-pale); border-color:var(--gold-soft); }
  .barra-selecao{ display:none; position:sticky; top:.5rem; z-index:30; gap:.45rem; flex-wrap:wrap;
    align-items:center; background:var(--forest); color:#fff; border-radius:12px;
    padding:.55rem .8rem; margin-bottom:.7rem; box-shadow:0 8px 24px rgba(32,52,42,.22); }
  .barra-selecao.on{ display:flex; }
  .barra-selecao .cont{ font-size:var(--t-denso); margin-right:.3rem; }
  .barra-selecao .cont b{ font-family:var(--serif); font-size:var(--t-corpo); }
  .barra-selecao .cresce{ flex:1; }
  .barra-selecao .btn-ico{ background:rgba(255,255,255,.12); border-color:rgba(255,255,255,.25); color:#fff; }
  .barra-selecao .btn-ico:hover{ background:rgba(255,255,255,.22); }

  /* Ação de WhatsApp e menu "mais ações" */
  .bt-wa{ display:inline-flex; align-items:center; gap:.3rem; }
  .bt-wa svg{ width:14px; height:14px; color:#25D366; }
  .bt-wa:hover{ border-color:#25D366; }
  .menu-mais{ display:inline-block; }
  /* O botão dos três pontos leva o SVG .ico-mais (ver estilo.css): o glifo "⋯"
     desenhava-se caído para o fundo da linha. Centra-se o ícone na sua caixa. */
  .bt-mais{ justify-content:center; }
  .pop-mais{ position:fixed; z-index:70; width:190px; background:var(--card); border:1px solid var(--line);
    border-radius:12px; box-shadow:0 12px 32px rgba(32,52,42,.18); padding:.3rem; }
  .pop-mais button, .pop-mais a{ display:block; width:100%; text-align:left; background:none; border:0; cursor:pointer;
    padding:.5rem .6rem; border-radius:8px; font-family:inherit; font-size:var(--t-denso); color:var(--text);
    text-decoration:none; }
  .pop-mais button:hover, .pop-mais a:hover{ background:var(--cream); }
  .pop-mais button.perigo{ color:var(--danger); }
  .pop-mais button.perigo:hover{ background:var(--danger-bg); }
  /* Aberto para cima, a sombra vem de baixo. */
  .pop-mais.acima{ box-shadow:0 -12px 32px rgba(32,52,42,.18); }

  /* Indicador e lista de mensagens */
  .tem-msg{ display:inline-flex; align-items:center; color:var(--gold); cursor:pointer; }
  .tem-msg svg{ width:15px; height:15px; }
  .msg-conta{ font-size:var(--t-etiqueta); font-weight:600; color:var(--ink-fraco); text-transform:uppercase; letter-spacing:.5px; margin:0 0 .6rem; }
  .msg-item{ border:1px solid var(--line); border-radius:12px; padding:.8rem 1rem; margin-bottom:.6rem; background:var(--card); }
  .msg-topo{ display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem; }
  .msg-topo strong{ font-family:var(--serif); font-size:var(--t-corpo); color:var(--ink); }
  .msg-txt{ margin:0; font-style:italic; color:var(--text); line-height:1.55; }
  /* É um <label>, e o estilo global põe as etiquetas em maiúsculas — uma frase
     inteira assim lia-se como um aviso gritado. Aqui é uma opção: fala normal. */
  .opcao-check{ display:flex; align-items:flex-start; gap:.6rem; margin-top:.8rem; cursor:pointer;
                font-size:var(--t-denso); color:var(--text); text-transform:none; letter-spacing:0; font-weight:400; }
  .opcao-check input{ width:18px; height:18px; margin-top:.1rem; accent-color:var(--forest); flex:none; }
  .opcao-check small{ color:var(--ink-fraco); }
  .membro-linha .m-vai{ display:none; align-items:center; justify-content:center; }
  #membros.parcial .membro-linha .m-vai{ display:inline-flex; }
  .membro-linha .m-vai input{ width:18px; height:18px; accent-color:#1f7a3d; cursor:pointer; }
  /* Na confirmação parcial entra uma coluna à cabeça (a caixa do "vem"). As
     colunas dos campos mantêm-se, e a segunda linha acompanha o desvio. */
  #membros.parcial .membro-linha{ background:var(--ok-bg); border-radius:10px; padding:.3rem .4rem;
                                  grid-template-columns:auto 2fr 1fr 1fr auto; }
  #membros.parcial .m-extras{ grid-column:2 / 5; }
  @media (max-width:560px){
    #membros.parcial .membro-linha{ grid-template-columns:auto 1fr 1fr auto; }
    #membros.parcial .membro-linha input[type=text]{ grid-column:2 / 5; }
    #membros.parcial .m-extras{ grid-column:1 / 5; }
  }
  .entradas-topo-dash{ text-align:center; color:var(--ink-fraco); font-size:var(--t-denso); margin-bottom:.9rem; }
  .entradas-topo-dash b{ color:#1f7a3d; font-family:var(--serif); font-size:var(--t-sub); }
  .entrada-d{ border:1px solid var(--line); border-left:3px solid #1f7a3d; border-radius:12px; padding:.75rem 1rem; margin-bottom:.6rem; background:var(--card); }
  .ent-d-topo{ display:flex; justify-content:space-between; align-items:baseline; gap:.5rem; }
  .ent-d-topo strong{ font-family:var(--serif); font-size:var(--t-sub); color:var(--ink); }
  .ent-d-hora{ font-size:var(--t-apoio); color:var(--ink-fraco); white-space:nowrap; }
  .ent-d-meta{ font-size:var(--t-apoio); color:var(--ink-fraco); margin-top:.15rem; }
  .ent-d-pessoas{ font-size:var(--t-denso); color:var(--text); margin-top:.35rem; }

  /* Histórico: reciclagem e registo de atividade */
  .abas-hist{ display:flex; gap:.4rem; border-bottom:1px solid var(--line); margin-bottom:.9rem; }
  .aba-h{ background:none; border:0; border-bottom:2px solid transparent; cursor:pointer; font-family:inherit;
    font-size:var(--t-denso); color:var(--ink-fraco); padding:.5rem .8rem; margin-bottom:-1px; }
  /* A aba ESCOLHIDA tem de ser a que mais se vê. Em --forest ficava ao
     contrário no tema escuro — quase da cor do fundo —, e a aba que se via
     bem era a outra, a que não estava aberta. */
  .aba-h.ativa{ color:var(--ink); border-bottom-color:var(--gold); font-weight:600; }
  .lixo-item{ display:flex; align-items:center; gap:.7rem; border:1px solid var(--line); border-radius:12px;
    padding:.7rem .9rem; margin-bottom:.55rem; background:var(--card); }
  .lixo-item .cresce{ min-width:0; }
  .lixo-item strong{ font-family:var(--serif); font-size:var(--t-corpo); color:var(--ink); display:block;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .lixo-item small{ color:var(--ink-fraco); font-size:var(--t-apoio); }
  /* Cada ação abre. Fechada é uma linha; aberta conta tudo o que dela se
     sabe — quem, com que papel, o que fez ao certo, sobre o quê, quando e de
     onde. Um <details> e não um clique nosso: abre com o teclado, imprime
     aberto, e não precisa de JavaScript nenhum para o fazer. */
  .reg-linha{ border-bottom:1px solid var(--line); font-size:var(--t-denso); }
  .reg-linha:last-child{ border-bottom:0; }
  /* Quatro coisas lado a lado cabem num ecrã largo; num telemóvel, não.
     A 313px, a hora (92px fixos), a conta e a pastilha da família deixavam à
     frase uns quarenta pixéis, e ela partia-se em seis linhas: CADA ação
     ocupava 190px, e o histórico dava quatro por ecrã. Por isso é uma grelha
     e não uma fila — no telemóvel a frase leva a primeira linha inteira e a
     hora e a família passam para baixo, em letra pequena, que é a ordem por
     que se lê: primeiro o que foi feito, depois quando. */
  .reg-linha > summary{ display:grid; gap:.1rem .6rem; align-items:baseline; padding:.45rem .2rem;
                        cursor:pointer; list-style:none; border-radius:8px;
                        grid-template-columns:92px minmax(0,1fr) auto;
                        grid-template-areas:"quando frase fam"; }
  .reg-linha > summary::-webkit-details-marker{ display:none; }
  .reg-linha > summary:hover{ background:var(--cream); }
  .reg-linha[open] > summary{ background:var(--cream); }
  .reg-quando{ grid-area:quando; color:var(--ink-fraco); font-size:var(--t-apoio); white-space:nowrap; }
  .reg-que{ grid-area:frase; color:var(--text); min-width:0; overflow-wrap:anywhere; }
  /* A conta que fez a coisa vai DENTRO da frase — «a Ana criou um convite» —
     em vez de numa coluna ao lado. Lê-se de uma vez, e não rouba largura.
     Em --forest, no tema escuro, era #0E1B25 sobre #17232C: o nome da conta —
     a resposta a «por qual conta», que é metade do que se vem cá perguntar —
     não se via. --gold-texto é a cor de acento que VIRA com o tema. */
  .reg-quem{ color:var(--gold-texto); font-weight:600; }
  .reg-que .reg-alvo{ color:var(--ink); font-weight:600; }
  .reg-que .reg-det{ color:var(--ink-fraco); }
  /* Fechada, a frase fica em duas linhas: a seguir a isso já não se está a ler,
     está-se a procurar. Aberta, diz tudo. */
  .reg-linha:not([open]) .reg-que{ display:-webkit-box; -webkit-line-clamp:2;
                                   -webkit-box-orient:vertical; overflow:hidden; }
  @media (max-width:640px){
    .reg-linha > summary{ grid-template-columns:minmax(0,1fr) auto;
                          grid-template-areas:"frase frase" "quando fam"; }
  }
  /* A família da ação, numa pastilha: dá a ler o assunto antes da frase. */
  .reg-fam{ grid-area:fam; justify-self:end; font-size:var(--t-etiqueta); font-weight:600; text-transform:uppercase; letter-spacing:.06em;
            padding:.1rem .45rem; border-radius:50px; background:var(--cream); color:var(--ink-fraco);
            border:1px solid var(--line); }
  /* As pastilhas tinham duas cores emprestadas de sítios errados: «peças» era
     um lilás escrito à mão, que no tema escuro ficava a ser a única mancha
     clara da lista; «contas» e «casamento» eram --forest sobre --sand, e no
     escuro isso é #0E1B25 sobre #28353E — uma pastilha com a palavra apagada
     lá dentro. Todas passam a sair de fundos e tintas que viram com o tema. */
  .reg-fam.convites{ background:var(--gold-pale); color:var(--gold-texto); border-color:var(--gold-soft); }
  .reg-fam.pecas{ background:var(--cream); color:var(--text); border-color:var(--line); }
  .reg-fam.orcamento{ background:var(--ok-bg); color:var(--ok); border-color:transparent; }
  .reg-fam.licenca{ background:var(--warn-bg); color:var(--warn); border-color:transparent; }
  .reg-fam.contas, .reg-fam.casamento{ background:var(--sand); color:var(--text); border-color:transparent; }
  .reg-detalhe{ padding:.15rem .2rem .8rem 92px; }
  @media (max-width:640px){ .reg-detalhe{ padding-left:.2rem; } }
  .reg-detalhe dl{ display:grid; grid-template-columns:auto 1fr; gap:.3rem .8rem; margin:0; }
  .reg-detalhe dt{ font-size:var(--t-etiqueta); font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-fraco);
                   white-space:nowrap; }
  .reg-detalhe dd{ margin:0; font-size:var(--t-apoio); color:var(--text); overflow-wrap:anywhere; }
  .reg-detalhe dd b{ color:var(--ink); }
  .reg-detalhe code{ font-family:ui-monospace,Menlo,Consolas,monospace; font-size:var(--t-apoio);
                     background:var(--cream); border-radius:5px; padding:.05rem .35rem; color:var(--ink-fraco); }
  /* O email de quem fez. Monoespaçado porque é um endereço e não uma frase:
     lê-se carácter a carácter quando se compara com outro. */
  .reg-email{ display:inline-block; font-family:ui-monospace,Menlo,Consolas,monospace;
              font-size:var(--t-apoio); overflow-wrap:anywhere; }
  .reg-email a{ color:var(--gold-texto); text-decoration:none; }
  .reg-email a:hover{ text-decoration:underline; }
  .reg-email-vazio{ color:var(--ink-fraco); font-family:inherit; font-style:italic; }
  .vazio-hist{ color:var(--ink-fraco); text-align:center; padding:1.4rem; }

  /* O momento de chegada (docs/auditoria-ui-ux.md, EMO-002).
     Dourado e sóbrio: isto é uma boa notícia, não um alarme — e a página em
     que ela aparece é a mesma onde se trabalha todos os dias. */
  /* Sem esta linha, a tira NUNCA se esconde. O `hidden` do HTML vale por uma
     regra [hidden]{display:none} do browser, e um `display` escrito numa
     classe ganha-lhe — pelo que a tira ficava lá, vazia: sem ícone, sem texto,
     só a moldura e o fundo verde, uma barra oca por cima dos convidados.
     Esconder o conteúdo não é esconder a caixa. */
  .chegada[hidden]{ display:none; }
  .chegada{ display:flex; align-items:center; gap:.8rem;
            background:linear-gradient(135deg, var(--gold-pale), var(--cream));
            border:1px solid var(--gold-soft); border-radius:14px; padding:.9rem 1.15rem;
            color:var(--ink); font-size:var(--t-denso); line-height:1.5; }
  .chegada b{ font-family:var(--serif); font-size:var(--t-sub); font-weight:400;
              display:block; margin-bottom:.1rem; }
  .ch-ico{ width:26px; height:26px; flex:none; color:var(--gold); }
  .ch-ico svg{ width:26px; height:26px; }
  /* A festa é uma vez por pessoa: entra devagar e fica. Nada de piscar — o que
     pisca lê-se como avaria, e isto é o contrário de uma avaria. */
  .chegada.festa{ animation:ch-entra .7s cubic-bezier(.2,.8,.2,1) both; }
  .chegada.festa .ch-ico{ animation:ch-brilha 1.6s ease-out .3s 2; }
  @keyframes ch-entra{ from{ opacity:0; transform:translateY(-8px) } to{ opacity:1; transform:none } }
  @keyframes ch-brilha{ 0%,100%{ transform:none } 30%{ transform:scale(1.18) rotate(-6deg) } }
  @media (prefers-reduced-motion:reduce){
    .chegada.festa, .chegada.festa .ch-ico{ animation:none; }
  }

  /* Onde vai cada módulo (docs/auditoria-ui-ux.md, UX-010).
     Isto era uma tira de cartões só dela (.tm-*), aqui em baixo. Os números
     dela passaram para os cartões do painel — ver .stat-f e subProg() — porque
     metade deles já lá estava em cima com outro nome e outro número. Sem tira,
     as suas cem linhas de folha de estilo deixaram de servir para nada; ficam
     aqui em nota para quem vier à procura delas pelo nome. */

  /* Quem ainda não respondeu (docs/auditoria-ui-ux.md, RSVP-002).
     Quem já foi avisado fica mais apagado e desce na lista: o que se procura
     aqui é a próxima pessoa a quem tocar, e não a lista toda outra vez. */
  .lb-prazo{ display:flex; align-items:center; gap:.7rem; flex-wrap:wrap; margin-bottom:.5rem; }
  .lb-prazo label{ margin:0; }
  .lb-prazo input{ width:auto; margin:0; }
  .lb-prazo .dica{ margin:0; }
  .lb-tarde{ color:var(--danger); font-weight:600; }
  .lb-topo{ display:flex; justify-content:space-between; align-items:center; gap:.7rem;
            flex-wrap:wrap; background:var(--cream); border-radius:12px;
            padding:.6rem .9rem; margin:.8rem 0 .6rem; font-size:var(--t-denso); }
  .lb-linha{ display:flex; justify-content:space-between; align-items:center; gap:.7rem;
             flex-wrap:wrap; border-bottom:1px solid var(--line); padding:.6rem .2rem; }
  .lb-linha:last-child{ border-bottom:0; }
  .lb-linha.ja{ opacity:.6; }
  .lb-quem b{ display:block; font-weight:600; }
  .lb-sub{ font-size:var(--t-apoio); color:var(--ink-fraco); }
  .lb-acoes{ display:flex; align-items:center; gap:.6rem; }
  .lb-marca{ font-size:var(--t-etiqueta); font-weight:600; text-transform:uppercase;
             letter-spacing:.06em; color:var(--ok); }
  .lb-sem{ font-size:var(--t-apoio); color:var(--warn); }

  /* As perguntas da confirmação (docs/auditoria-ui-ux.md, RSVP-001).
     Um cartão por pergunta, e a pergunta em cima com o tamanho de uma
     pergunta: numa lista de campos todos iguais não se via o que era a
     pergunta e o que era a maquinaria dela. */
  .pg-cartao{ border:1px solid var(--line); border-radius:14px; padding:.9rem 1rem;
              margin-bottom:.7rem; background:var(--card); }
  .pg-cab{ display:flex; gap:.5rem; align-items:center; }
  .pg-cab .pg-rotulo{ flex:1; min-width:0; font-family:var(--serif); font-size:var(--t-sub);
                      margin:0; padding:.45rem .6rem; }
  .pg-ajuda{ margin:.45rem 0 0; font-size:var(--t-apoio); }
  .pg-linha{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
             gap:.5rem; margin-top:.6rem; }
  .pg-linha label, .pg-ops{ display:block; font-size:var(--t-etiqueta); font-weight:600;
             text-transform:uppercase; letter-spacing:.06em; color:var(--ink-fraco); }
  .pg-linha input, .pg-linha select{ margin-top:.25rem; }
  .pg-ops{ margin-top:.6rem; }
  .pg-ops textarea{ margin-top:.25rem; font-family:var(--sans); font-size:var(--t-denso);
                    text-transform:none; letter-spacing:0; font-weight:400; }
  .pg-obrig{ display:flex; align-items:center; gap:.5rem; margin-top:.6rem;
             font-size:var(--t-apoio); color:var(--ink-fraco); text-transform:none;
             letter-spacing:0; font-weight:400; }
  .pg-obrig input{ width:17px; height:17px; accent-color:var(--gold); margin:0; }

  /* O resumo: é isto que se dá ao catering. Por isso diz também quantos
     faltam — uma conta de metade da lista entregue como se fosse toda é pior
     do que conta nenhuma. */
  .pg-resumo{ background:var(--cream); border-radius:14px; padding:.9rem 1rem; margin-bottom:1rem; }
  .pg-resumo-vazio{ background:var(--cream); border-radius:14px; padding:.9rem 1rem;
                    margin-bottom:1rem; color:var(--ink-fraco); font-size:var(--t-apoio); }
  .pg-r-bloco + .pg-r-bloco{ margin-top:.8rem; border-top:1px solid var(--line); padding-top:.8rem; }
  .pg-r-tit{ font-size:var(--t-etiqueta); font-weight:600; text-transform:uppercase;
             letter-spacing:.06em; color:var(--ink-fraco); margin-bottom:.4rem;
             display:flex; gap:.6rem; flex-wrap:wrap; align-items:baseline; }
  .pg-falta{ color:var(--warn); text-transform:none; letter-spacing:0; font-weight:400; }
  .pg-r-linhas{ display:flex; gap:.5rem; flex-wrap:wrap; }
  .pg-conta{ background:var(--card); border:1px solid var(--line); border-radius:999px;
             padding:.25rem .7rem; font-size:var(--t-denso); }
  .pg-conta b{ font-family:var(--serif); font-size:var(--t-sub); color:var(--ink);
               font-weight:400; margin-right:.25rem; }
  .pg-txt{ background:var(--card); border:1px solid var(--line); border-radius:10px;
           padding:.25rem .6rem; font-size:var(--t-denso); }
</style>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/estados.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/mesa-icone.js') ?>"></script>
</head>
<body>
<?php cabecalho('Gestão de Convidados', 'Quem vem, com quem, e por onde entra', 'painel'); ?>

<main id="conteudo">
<div class="container">

  <?php if (podeModulo('bar')): ?>
  <!-- O BAR, NO DIA -->
  <!-- Uma tira e não um cartão de estatística: os cartões ali em baixo são
       todos filtros da lista de convidados, e um que não filtrasse nada seria
       uma promessa falsa. Só aparece quando o bar está mesmo a trabalhar —
       nos meses antes da festa não há nada para dizer. -->
  <a class="tira-bar" id="tira-bar" href="copa.php" hidden></a>
  <?php endif; ?>

  <!-- PROGRESSO DE CAPACIDADE -->
  <!-- O momento em que o último convite responde (EMO-002). Fica enquanto for
       verdade: é o estado, e um estado que desaparece obriga a ir confirmá-lo
       a outro lado. -->
  <div id="chegada" class="chegada mb-4" hidden></div>

  <div id="progresso" class="progresso-cap mb-4"></div>


  <!-- ESTATÍSTICAS -->
  <div class="grelha-stats" id="stats"></div>
  <div class="stats-baixo mb-4">
    <button class="btn-stats-mais" id="stats-mais" onclick="alternarStats()">Mais filtros</button>
    <!-- Discreto de propósito: escolher a ordem dos cartões é coisa que se faz
         uma vez e não se volta a mexer. Fica FORA do «Mais filtros» porque é
         precisamente sem abrir os «Mais filtros» que se quer pôr à frente o
         cartão que se usa todos os dias. -->
    <button class="bt-arrumar" id="stats-arrumar" onclick="arrumarCartoes()"
            aria-pressed="false" title="Escolher os cartões à vista">
      <i data-ico="mover" aria-hidden="true"></i></button>
  </div>
  <div class="esp-stats mb-4"></div>

  <!-- AÇÕES -->
  <div class="barra-acoes">
    <div class="cresce">
      <input type="search" id="busca" placeholder="Procurar convite, código ou pessoa…" oninput="debounceCarregar()">
    </div>
    <!-- UMA primária por contexto (docs/auditoria-ui-ux.md, CTA-001).
         Estavam aqui oito acções em fila, com «+ Novo convite» — a que se faz
         dezenas de vezes — em oitavo lugar, depois de quatro que se fazem uma
         vez por casamento. A que manda vem primeiro; a segunda fica secundária;
         as outras quatro passam para trás do «⋯», que é onde vivem as acções
         de uma vez só.
         data-escrita: abrir a janela não escreve nada, mas só serve para criar.
         Numa visita de leitura, deixá-la abrir era convidar a preencher um
         formulário inteiro para o Guardar dizer que não. -->
    <button class="btn btn-ouro" data-escrita="1" onclick="novoConvite()">+ Novo convite</button>
    <button class="btn btn-fantasma" onclick="abrirMesas()">Mesas</button>
    <button class="btn btn-fantasma btn-mais-acoes" onclick="abrirAccoes(event)"
            aria-haspopup="true">
      <svg class="ico-mais" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.4" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="12.6" cy="8" r="1.5"/></svg>
      Mais acções</button>
  </div>


  <!-- ONDE VAI CADA MÓDULO (docs/auditoria-ui-ux.md, UX-010).
       O painel dizia muito sobre os convidados e nada sobre o resto: quem
       tinha a planta, o orçamento e o bar na licença não tinha em sítio nenhum
       uma resposta à pergunta com que se abre o portátil — «o que falta
       fazer?».

       Isto era uma TIRA à parte, aqui em baixo, com sete cartões seus. O
       problema é que quatro desses cartões falavam do mesmo que os cartões de
       cima — «Confirmações» ao lado de «Confirmados», «Impressos» ao lado de
       «Impressos» — com números diferentes, porque contavam unidades
       diferentes sem o dizerem. Duas tiras, dois números, um rótulo: lia-se
       como um erro, e era.

       Agora é tudo a mesma grelha, lá em cima: o progresso de cada módulo é a
       linha de baixo do cartão que fala da mesma coisa, e os que não tinham
       cartão nenhum (Sentados, Entradas, Despesas, Bar) passaram a ter um,
       com o caminho para a sua página. Um número, um sítio.

       Nota de layout que continua a valer: esta grelha vem ANTES da barra de
       ações, e a tira de baixo tinha 154px que punham a caixa de procura a
       892px num ecrã de 844. Ao juntar, a conta é outra — os cartões de módulo
       entram nos «Mais filtros» e não empurram nada. A e2e_mobile.js guarda
       exactamente isto. -->
  <!-- FILTRO DE MESAS (chips) -->
  <div id="filtro-mesas" class="chips-mesa mb-4"></div>

  <!-- LISTA -->
  <div class="barra-selecao" id="barra-selecao"></div>
  <div class="lista" id="lista"></div>
</div>

<!-- ===== MODAL CONVITE ===== -->
<div class="overlay" id="ov-convite">
  <div class="modal modal-convite">
    <div class="modal-topo"><h3 id="modal-titulo">Novo convite</h3><button class="fechar" onclick="fechar('ov-convite')">&times;</button></div>
    <div class="modal-corpo">
      <input type="hidden" id="c-id">

      <!-- 1. Quem vem -->
      <section class="fset">
        <h4 class="fset-t">Quem vem <span class="cont" id="cont-pessoas"></span></h4>
        <div id="membros"></div>
        <button class="btn btn-fantasma btn-sm" type="button" onclick="addMembro()">+ Adicionar pessoa</button>
        <div class="dica-lugares">Cada pessoa é um lugar — os lugares do convite contam-se por esta lista.</div>
      </section>

      <!-- 2. O convite: como se apresenta e como chega -->
      <section class="fset">
        <h4 class="fset-t">O convite</h4>
        <label>Nome a exibir</label>
        <div class="sugestoes" id="sugestoes"></div>
        <input type="text" id="c-nome" placeholder="Ex: Família Agostinho, Sr. João e Sra. Maria…" oninput="NOME_AUTO=false;atualizarPrevia()">
        <div class="previa" id="previa">Família Agostinho</div>

        <div class="lf2">
          <div><label>Como é entregue</label>
            <input type="hidden" id="c-tipo" value="digital">
            <div class="picker" data-target="c-tipo"></div></div>
          <div><label>Telefone <span class="opt">· opcional</span></label><input type="text" id="c-telefone" placeholder="+244…"></div>
        </div>

        <div style="margin-top:.9rem;"><label>Mensagem pessoal <span class="opt">· opcional, só no convite digital</span></label>
          <textarea id="c-msg" rows="2" placeholder="Ex: Mal podemos esperar por vos receber!"></textarea></div>

        <label class="opcao-check">
          <input type="checkbox" id="c-mostrar-num-mesa" checked>
          <span>Mostrar os lugares por mesa no convite <small>ex.: Mesa A (1 lugar) e B (4 lugares)</small></span>
        </label>
      </section>

      <!-- 3. Organização: o que serve a gestão, não o convidado -->
      <section class="fset">
        <h4 class="fset-t">Organização</h4>
        <div class="lf2">
          <div><label>Convidado de</label>
            <input type="hidden" id="c-lado" value="noivo">
            <div class="picker" data-target="c-lado"></div></div>
          <div><label>Mesa</label><input type="text" id="c-mesa" list="lista-mesas" placeholder="Nome da mesa"><datalist id="lista-mesas"></datalist></div>
        </div>

        <div style="margin-top:.9rem;">
          <label>Presença</label>
          <input type="hidden" id="c-presenca" value="pendente">
          <div class="picker" data-target="c-presenca"></div>
        </div>

        <div style="margin-top:.9rem;"><label>Observações <span class="opt">· opcional</span></label><textarea id="c-obs" rows="2"></textarea></div>
      </section>

      <div id="bloco-link" style="display:none; margin-top:1.2rem;">
        <label>Link de confirmação / QR</label>
        <div class="link-box">
          <input type="text" id="c-link" readonly>
          <button class="btn-ico" title="Copiar" onclick="copiarLink()">Copiar</button>
          <button class="btn-ico" title="QR" onclick="mostrarQRatual()">QR</button>
        </div>
      </div>

      <div style="display:flex; gap:.6rem; margin-top:1.4rem; justify-content:flex-end;">
        <button class="btn btn-fantasma" onclick="fechar('ov-convite')">Cancelar</button>
        <button class="btn btn-ouro" onclick="guardarConvite()">Guardar convite</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== MODAL QR ===== -->
<div class="overlay" id="ov-qr">
  <div class="modal" style="max-width:380px;">
    <div class="modal-topo"><h3 id="qr-titulo">Código QR</h3><button class="fechar" onclick="fechar('ov-qr')">&times;</button></div>
    <div class="modal-corpo qr-holder">
      <canvas id="qr-canvas"></canvas>
      <p style="font-size:var(--t-denso); color:var(--ink-fraco); margin:.6rem 0;">Apresente este código à entrada do evento.</p>
      <button class="btn btn-ouro" onclick="descarregarQR()">Descarregar imagem</button>
    </div>
  </div>
</div>

<!-- ===== MODAL MESAS ===== -->
<div class="overlay" id="ov-mesas">
  <div class="modal">
    <div class="modal-topo"><h3>Mesas</h3><button class="fechar" onclick="fechar('ov-mesas')">&times;</button></div>
    <div class="modal-corpo">
      <div class="linha-form" style="align-items:end;">
        <div><label>Nome da mesa</label><input type="text" id="m-nome" placeholder="Ex: Mesa 1, Família, Honra…"></div>
        <div><label>Capacidade</label><input type="number" id="m-cap" min="1" placeholder="opcional"></div>
        <div><button class="btn btn-ouro" style="width:100%; justify-content:center;" onclick="guardarMesa()">Adicionar</button></div>
      </div>
      <input type="hidden" id="m-id">
      <div id="lista-mesas-gestao" style="margin-top:1rem;"></div>
    </div>
  </div>
</div>

<!-- ===== MODAL MENSAGENS ===== -->
<div class="overlay" id="ov-mensagens">
  <div class="modal">
    <div class="modal-topo"><h3>Mensagens dos convidados</h3><button class="fechar" onclick="fechar('ov-mensagens')">&times;</button></div>
    <div class="modal-corpo">
      <div id="lista-mensagens"></div>
    </div>
  </div>
</div>

<!-- ===== MODAL ENTRADAS (quem já deu entrada) ===== -->
<div class="overlay" id="ov-entradas">
  <div class="modal">
    <div class="modal-topo"><h3>Quem já deu entrada</h3><button class="fechar" onclick="fechar('ov-entradas')">&times;</button></div>
    <div class="modal-corpo">
      <div id="entradas-topo-dash" class="entradas-topo-dash"></div>
      <div id="lista-entradas-dash"></div>
    </div>
  </div>
</div>

<!-- ===== MODAL HISTÓRICO (reciclagem + registo de atividade) ===== -->
<?php // ---- quem ainda não respondeu, e o prazo para responder (RSVP-002) ---- ?>
<div class="overlay" id="ov-lembretes">
  <div class="modal">
    <div class="modal-topo"><h3>Quem ainda não respondeu</h3>
      <button class="fechar" onclick="fechar('ov-lembretes')">&times;</button></div>
    <div class="modal-corpo">
      <div class="lb-prazo">
        <label for="lb-data">Responder até</label>
        <input type="date" id="lb-data" onchange="guardarPrazo()">
        <span class="dica" id="lb-prazo-txt"></span>
      </div>
      <p class="dica">Um convite sem prazo é respondido «depois», e «depois» é o dia em
        que o catering já fechou a conta. O prazo aparece no convite.</p>
      <div id="lb-lista"></div>
    </div>
  </div>
</div>

<?php // ---- as perguntas da confirmação, e o que elas já responderam (RSVP-001) ---- ?>
<div class="overlay" id="ov-perguntas">
  <div class="modal">
    <div class="modal-topo"><h3>Perguntas da confirmação</h3>
      <button class="fechar" onclick="fechar('ov-perguntas')">&times;</button></div>
    <div class="modal-corpo">
      <p class="dica">Tudo o que perguntar aqui aparece no convite, depois de a pessoa
        dizer que vai. As respostas somam-se sozinhas na tabela em baixo — é ela que se
        dá ao catering, em vez de duzentos telefonemas.</p>
      <div id="pg-resumo"></div>
      <div id="pg-lista"></div>
      <div style="display:flex; gap:.6rem; flex-wrap:wrap; margin-top:1rem">
        <button class="btn btn-fantasma" data-escrita="1" onclick="novaPergunta()">+ Nova pergunta</button>
        <button class="btn btn-ouro" data-escrita="1" onclick="guardarPerguntas()">Guardar perguntas</button>
      </div>
    </div>
  </div>
</div>

<div class="overlay" id="ov-historico">
  <div class="modal">
    <div class="modal-topo"><h3>Histórico</h3><button class="fechar" onclick="fechar('ov-historico')">&times;</button></div>
    <div class="modal-corpo">
      <div class="abas-hist">
        <button class="aba-h ativa" id="aba-lixo" onclick="abaHistorico('lixo')">Reciclagem</button>
        <button class="aba-h" id="aba-registo" onclick="abaHistorico('registo')">Atividade</button>
      </div>
      <div id="hist-lixo"></div>
      <div id="hist-registo" hidden></div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const BASE = <?= json_encode(enderecoPublico()) ?>;
const CASAL = <?= json_encode($CAS['casal']) ?>;
const DATA_EXT = <?= json_encode($dataExt) ?>;
window.CSRF = <?= json_encode(csrfToken()) ?>;
// Qual é o casamento aberto. O momento de chegada (EMO-002) guarda-se por
// casamento: quem responde pela casa entra em vários, e o momento de um não
// pode ficar dado como visto no outro.
window.CASAMENTO_ID = <?= (int)casamentoAtual() ?>;
// E como se chama. No histórico, as decisões da casa trazem o nome do
// casamento no alvo — «suspendeu um casamento · Isabel & Abednego» —, que aqui
// dentro é dizer duas vezes a mesma coisa: este histórico é só deste casamento.
window.CASAMENTO_NOME = <?= json_encode(nomeDoCasamento()) ?>;
// O endereço do bar DESTA festa. O cartão «Bar» apontava para a bebidas.php
// sem endereço nenhum, e a bebidas.php sem endereço não é uma página: é o
// recado a dizer que o endereço não serve. Quem carregasse no cartão do seu
// próprio bar ia parar a um erro.
window.BAR_LINK = <?= json_encode(podeModulo('bar') ? barLinkDaFesta($conn) : '') ?>;
// Quantas linhas a primeira página vai ter. O servidor já sabe a conta ($12),
// por isso o esqueleto pode ter o número CERTO de barras em vez de um palpite:
// com um palpite, um casamento de dois convites via 368px de barras a encolher
// para 114px assim que a resposta chegava — um salto a fingir-se de cortesia.
// Tecto de oito: um esqueleto de sessenta linhas é uma página inteira a tremer.
const ESQ_LINHAS = <?= min(8, min((int)$totalConvites, (int)LISTA_POR_PAGINA)) ?>;
const CAP = <?= (int)MAX_LUGARES_TOTAL ?>;
// O tecto da LICENÇA, que é outra coisa da «capacidade» do salão: aquela é uma
// intenção do casal, esta é um limite que o servidor faz cumprir. 0 = sem
// limite; -1 = nem um (o módulo não está na licença, mas então esta página nem
// abre). É ele que fecha o botão de novo convite quando não cabe mais ninguém.
const LIC_LIMITE = <?= (int)limiteConvidados() ?>;
let CONVITES = [], MESAS = [], STATS = {}, timer = null;
// O nome a exibir ainda é o proposto automaticamente (ninguém lhe mexeu à mão).
let NOME_AUTO = true;
let filtroTipo='', filtroLado='', filtroEstado='', filtroMesa='', filtroImpresso='', filtroGenero='', filtroBrinde='';
const SEM_MESA = '__SEM_MESA__'; // valor especial do filtro "sem mesa"

// ---------- utilidades ----------
const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
let tToast=null;
function toast(msg, erro=false){ const t=$('toast'); clearTimeout(tToast);
  t.textContent=msg; t.className='toast mostrar'+(erro?' erro':''); tToast=setTimeout(()=>t.className='toast',2600); }
/** Toast com um botão "Anular" — dá tempo (7s) de desfazer a ação. */
function toastAnular(msg, aoAnular){
  const t=$('toast'); clearTimeout(tToast);
  t.innerHTML=`<span>${esc(msg)}</span><button type="button" class="anular">Anular</button>`;
  t.className='toast mostrar accao';
  t.querySelector('.anular').onclick=()=>{ clearTimeout(tToast); t.className='toast'; aoAnular(); };
  tToast=setTimeout(()=>t.className='toast',7000);
}
function agora(){ const d=new Date(),p=n=>String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); }
// api() vem de assets/api.js (trata sessão expirada, falha de rede e erros do servidor)
function debounceCarregar(){ clearTimeout(timer); timer=setTimeout(carregar,300); }

// ---------- carregar ----------
// A lista vem por pedaços: mudar de filtro recomeça na primeira página,
// "Mostrar mais" acrescenta a seguinte ao que já está no ecrã.
let PAGINA = 1, TOTAL = 0, HA_MAIS = false;

async function carregar(mais=false){
  PAGINA = mais ? PAGINA + 1 : 1;
  const q = new URLSearchParams({
    tipo:filtroTipo, lado:filtroLado, estado:filtroEstado,
    mesa:filtroMesa, busca:$('busca').value, impresso:filtroImpresso,
    genero:filtroGenero, brinde:filtroBrinde, pagina:PAGINA
  });
  // O esqueleto só quando não há nada no lugar (docs/auditoria-ui-ux.md,
  // EST-001). Esta lista ficava em branco à chegada e aparecia toda de uma
  // vez: um branco não diz se está a pensar ou se avariou. Mas com a lista já
  // cheia, trocá-la por barras a cada tecla da procura seria pior do que
  // deixar as linhas de antes quietas durante os 150ms que a resposta demora.
  if(!mais && !CONVITES.length && ESQ_LINHAS) $('lista').innerHTML = EST.esqueleto(ESQ_LINHAS, 54);
  const d = await api('convite_list&'+q.toString());
  if(!d.success){ if(mais) PAGINA--; return toast('Erro ao carregar.', true); }
  CONVITES = mais ? CONVITES.concat(d.convites) : d.convites;
  MESAS=d.mesas; STATS=d.stats||{}; TOTAL=+d.total||CONVITES.length; HA_MAIS=!!d.ha_mais;
  ULTIMO_STATS = d.stats;
  renderStats(d.stats); renderConvites(); renderFiltroMesas(); renderDatalistMesas();
  tiraDoBar();
}

// Conjunto de ícones (SVG inline, traço fino)
const IC = {
  whatsapp:'<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.21 8.21 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.14.16-.29.18-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.12-.15.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.1-.23-.17-.48-.29z"/></svg>',
  todos:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
  telemovel:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2" width="10" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg>',
  envelope:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
  impressora:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>',
  check:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
  relogio:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  xis:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
  noivo:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8.6 14 L8 4.9 Q8 4.3 8.6 4.3 L15.4 4.3 Q16 4.3 16 4.9 L15.4 14"/><ellipse cx="12" cy="14" rx="8.6" ry="1.9"/><path d="M8.2 11.4 H15.8"/></svg>',
  noiva:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 16.4 Q12 13.4 20.5 16.4"/><path d="M4.8 16 L8 9.2 L10.4 13 L12 6.4 L13.6 13 L16 9.2 L19.2 16"/><circle cx="12" cy="5.6" r="1" fill="currentColor" stroke="none"/><circle cx="8" cy="8.4" r=".8" fill="currentColor" stroke="none"/><circle cx="16" cy="8.4" r=".8" fill="currentColor" stroke="none"/></svg>',
  balao:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5z"/></svg>',
  meio:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" stroke="none"/></svg>',
  masculino:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="14" r="5"/><path d="M14.2 9.8 L20 4"/><path d="M15 4 H20 V9"/></svg>',
  feminino:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="5"/><path d="M12 14 V21"/><path d="M9 18 H15"/></svg>',
  brinde:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="8" width="17" height="4" rx="1"/><path d="M5.2 12 V20 H18.8 V12"/><path d="M12 8 V20"/><path d="M12 8 Q8 8 8 5.5 Q8 4 9.5 4 Q12 4.2 12 8 Q12 4.2 14.5 4 Q16 4 16 5.5 Q16 8 12 8"/></svg>'
};

// Todos os cartões dizem o mesmo: número grande = PESSOAS, linha de baixo = a
// quantos convites pertencem. Sem esta regra lia-se "6 · 2 convites" ao lado de
// "0 · convidados" e não se percebia o que cada número contava.
//
// A linha de baixo passou a dizer a UNIDADE, e é isso que desfaz a contradição
// que havia no painel: «Impressos 7» aqui em cima e «Impressos 5 de 13» na tira
// lá de baixo. Os dois números estavam certos — um contava PESSOAS em convites
// impressos, o outro contava CONVITES impressos dos que são físicos —, mas
// nenhum dizia a que pergunta respondia, e o mesmo rótulo com dois números no
// mesmo ecrã lê-se como um erro. Agora cada linha nomeia o que conta.
//
// E o cartão passa a poder levar a PÁGINA a que o número pertence. Quem tem
// «Sentados» quer ir à planta; quem tem «Impressos» quer ir aos impressos. Era
// para isso que servia a tira de baixo, que deixa de ser precisa.
function statCard(o){
  const p = (+o.n===1?'1 pessoa':(+o.n||0)+' pessoas');
  const tit = o.titulo || `${o.rot}: ${o.sub ? p + ' · ' + String(o.sub).replace(/<[^>]*>/g,'') : p}`;
  const miolo = `<span class="si">${o.ic}</span><span class="sn">${o.num!=null?o.num:(+o.n||0)}</span>`
              + `<span class="sl">${o.rot}</span><span class="ss">${o.sub||''}</span>`;
  const cls = `stat-f ${o.cls||''}${o.ativo?' ativo':''}`;
  // Sem filtro, o cartão É o link: um destino só, e o cartão inteiro leva lá.
  if (!o.onclick && o.onde)
    return `<a class="${cls}" href="${o.onde}" title="${esc(tit)}">${miolo}</a>`;
  const bt = `<button class="${cls}" onclick="${o.onclick||''}" title="${esc(tit)}">${miolo}</button>`;
  if (!o.onde) return bt;
  // Com as duas coisas, o cartão filtra a lista (que é o que se faz sem sair
  // daqui) e leva um canto discreto para a página dona do número. Dois
  // elementos irmãos, e não um dentro do outro: um <a> dentro de um <button>
  // não é HTML, e o teclado tropeça nele.
  return `<span class="stat-cx">${bt}<a class="stat-ir" href="${o.onde}"
            title="Abrir ${esc(o.rot)}" aria-label="Abrir ${esc(o.rot)}"
            onclick="event.stopPropagation()"><i data-ico="direita" aria-hidden="true"></i></a></span>`;
}

/** «5 de 13 impressos» — a sub-linha que nomeia o que conta. */
function subProg(chave, nome){
  const m = (MODULOS_PROG||[]).find(x => x.chave === chave);
  if (!m) return '';
  if (m.total <= 0) return esc(m.vazio || '');
  const f = m.unidade === 'dinheiro' ? fmtKz(m.feito) : m.feito;
  const t = m.unidade === 'dinheiro' ? fmtKz(m.total) : m.total;
  return `${f} de ${t} ${esc(nome)}`;
}

// Sub-linha do cartão de brindes: quantos recebem por género.
function brindeSub(s){
  const m=+s.pes_brinde_m||0, f=+s.pes_brinde_f||0, sg=+s.pes_brinde_sg||0;
  return `<i class="gi gi-m" data-ico="homem"></i> ${m} · <i class="gi gi-f" data-ico="mulher"></i> ${f}`
       + (sg?` · ${sg}?`:'');
}
function brindeTitulo(s){
  const m=+s.pes_brinde_m||0, f=+s.pes_brinde_f||0, sg=+s.pes_brinde_sg||0;
  return `Brindes: ${m} a homens · ${f} a mulheres`
       + (sg?` · ${sg} sem género definido`:'') + ` (${(+s.pes_brinde||0)} no total)`;
}

// Quantos cartões ficam à vista antes do «Mais filtros». Os outros escondem-se
// no telemóvel para a lista não fugir do ecrã.
const CARTOES_BASE = 4;

// A ordem é do casal, e guarda-se por casamento: quem anda a imprimir quer os
// «Impressos» à frente; no dia da festa o que interessa é «Entradas». Sem isto,
// pôr à frente o cartão que se usa obrigava a abrir os «Mais filtros» de cada
// vez — que é o que o casal pediu para não ter de fazer.
function chaveOrdem(){ return 'painel.cartoes.' + (window.CASAMENTO_ID || 0); }
function ordemGuardada(){
  try { return JSON.parse(localStorage.getItem(chaveOrdem()) || '[]'); } catch (e) { return []; }
}
function guardarOrdem(cs){
  try { localStorage.setItem(chaveOrdem(), JSON.stringify(cs)); } catch (e) {}
}
/** Põe os cartões pela ordem escolhida; os que não estão lá ficam atrás. */
function porOrdem(cartoes){
  const o = ordemGuardada();
  if (!o.length) return cartoes;
  const por = new Map(cartoes.map(c => [c.chave, c]));
  const out = [];
  o.forEach(k => { if (por.has(k)) { out.push(por.get(k)); por.delete(k); } });
  return out.concat([...por.values()]);
}
let ARRUMAR = false;

function renderStats(s){
  renderProgresso(s);
  momentoDeChegada(s);
  const e=filtroEstado, t=filtroTipo, l=filtroLado;
  const limpo = !e && !t && !l && !filtroImpresso && !filtroMesa && !filtroGenero && !filtroBrinde && !$('busca').value;
  const nConv = n => (+n===1 ? '1 convite' : (+n||0) + ' convites');

  // Os números que estavam em DOIS sítios passam a estar num só. A tira de
  // baixo dizia «Confirmações 16 de 19», «Enviados 5 de 13», «Impressos 5 de
  // 13» e «Sentados» — e três desses rótulos já cá estavam em cima com outro
  // número. Cada um foi para a linha de baixo do cartão que fala da mesma
  // coisa, com a unidade dita, e o cartão ganhou o caminho para a página.
  const cartoes = [
    { chave:'todos', h: statCard({ ic:IC.todos, n:s.lugares, rot:'Todos',
        sub:nConv(s.convites), onclick:"limparFiltros()", ativo:limpo }) },
    { chave:'confirmados', h: statCard({ ic:IC.check, n:s.pes_confirmados, rot:'Confirmados',
        sub:subProg('convidados','responderam') || nConv(s.confirmados),
        onclick:"filtrarEstado('confirmado')", ativo:e==='confirmado', cls:'verde' }) },
    { chave:'pendentes', h: statCard({ ic:IC.relogio, n:s.pes_pendentes, rot:'Pendentes',
        sub:nConv(s.pendentes), onclick:"filtrarEstado('pendente')", ativo:e==='pendente', cls:'ouro' }) },
    { chave:'recusados', h: statCard({ ic:IC.xis, n:s.pes_recusados, rot:'Recusados',
        sub:nConv(s.recusados), onclick:"filtrarEstado('recusado')", ativo:e==='recusado', cls:'rosa' }) },
    { chave:'digitais', h: statCard({ ic:IC.telemovel, n:s.pes_digitais, rot:'Digitais',
        sub:subProg('digital','enviados') || nConv(s.digitais),
        onclick:"filtrarTipo('digital')", ativo:t==='digital', onde:temMod('digital')?'digital.php':'' }) },
    { chave:'fisicos', h: statCard({ ic:IC.envelope, n:s.pes_fisicos, rot:'Físicos',
        sub:subProg('impresso','impressos') || nConv(s.fisicos),
        onclick:"filtrarTipo('fisico')", ativo:t==='fisico', onde:temMod('impresso')?'impressos.php':'' }) },
    { chave:'impressos', h: statCard({ ic:IC.impressora, n:s.pes_impressos, rot:'Impressos',
        sub:nConv(s.impressos), onclick:"filtrarImpresso()", ativo:filtroImpresso==='1',
        onde:temMod('impresso')?'impressos.php':'' }) },
    { chave:'noivo', h: statCard({ ic:IC.noivo, n:s.pes_noivos, rot:'Noivo',
        sub:nConv(s.noivos), onclick:"filtrarLado('noivo')", ativo:l==='noivo' }) },
    { chave:'noiva', h: statCard({ ic:IC.noiva, n:s.pes_noivas, rot:'Noiva',
        sub:nConv(s.noivas), onclick:"filtrarLado('noiva')", ativo:l==='noiva' }) },
    { chave:'masculino', h: statCard({ ic:IC.masculino, n:s.pes_masculino, rot:'Masculino',
        sub:nConv(s.conv_masculino), onclick:"filtrarGenero('m')", ativo:filtroGenero==='m' }) },
    { chave:'feminino', h: statCard({ ic:IC.feminino, n:s.pes_feminino, rot:'Feminino',
        sub:nConv(s.conv_feminino), onclick:"filtrarGenero('f')", ativo:filtroGenero==='f', cls:'rosa' }) },
    { chave:'brindes', h: statCard({ ic:IC.brinde, n:s.pes_brinde, rot:'Brindes',
        sub:brindeSub(s), titulo:brindeTitulo(s), onclick:"filtrarBrinde()",
        ativo:filtroBrinde==='1', cls:'ouro' }) },
  ].concat(cartoesDeModulo());

  // Um filtro ativo entre os "extra" obriga a mostrá-los: senão o painel diria
  // que está filtrado sem se ver por quê. A arrumar, mostram-se todos — não se
  // escolhe a ordem do que não se vê.
  if (filtroTipo || filtroImpresso || filtroLado || filtroGenero || filtroBrinde) STATS_ABERTO = true;
  const ord = porOrdem(cartoes);
  const html = c => ARRUMAR ? envolverArrumar(c) : c.h;
  const aberto = STATS_ABERTO || ARRUMAR;
  $('stats').innerHTML =
    ord.slice(0, CARTOES_BASE).map(html).join('') +
    `<div class="stats-extra${aberto?' aberto':''}">${ord.slice(CARTOES_BASE).map(html).join('')}</div>`;
  $('stats-mais').innerHTML = STATS_ABERTO
    ? 'Menos filtros'
    : `Mais filtros <span class="conta-extra">${ord.length-CARTOES_BASE}</span>`;
  const bt = $('stats-arrumar');
  if (bt) { bt.classList.toggle('on', ARRUMAR);
            bt.setAttribute('aria-pressed', ARRUMAR ? 'true' : 'false');
            bt.title = ARRUMAR ? 'Terminar de arrumar os cartões' : 'Escolher os cartões à vista'; }
  ORD_ATUAL = ord.map(c => c.chave);
}
let STATS_ABERTO = false;
let ORD_ATUAL = [];

/** Os cartões cujo número vive noutra página e que aqui não tinham filtro. */
function cartoesDeModulo(){
  // O ícone vem do servidor («mesa», «porta», «moeda», «alto») e desenha-se
  // pelo data-ico, como na tira antiga: o mapa IC daqui só tem os dos filtros.
  const mapa = [
    { chave:'mesas',     rot:'Sentados', unidade:'confirmados', onde:'mesas.php' },
    { chave:'porta',     rot:'Entradas', unidade:'confirmados', onde:'porteiro.php' },
    { chave:'orcamento', rot:'Despesas', unidade:'pagas',       onde:'orcamento.php' },
    // O bar leva ao ENDEREÇO desta festa, e não à página sem endereço — essa
    // só sabe dizer que o endereço não serve.
    { chave:'bar',       rot:'Bar',      unidade:'com foto',
      onde: window.BAR_LINK || 'bar.php' },
  ];
  // Entre si, vem primeiro o que mais falta — é isso que se vem aqui
  // perguntar (UX-010). A tira antiga ordenava-se toda assim; agora os
  // filtros têm ordem própria (a do casal), por isso a regra vale dentro do
  // grupo dos módulos, que é onde ela sempre quis dizer alguma coisa.
  const falta = k => { const m = MODULOS_PROG.find(y => y.chave === k);
                       return m ? Math.max(0, m.total - m.feito) : -1; };
  // «ENTRADAS» é do DIA, como a tira de chegada — conta quem já entrou pela
  // porta. A 89 dias da festa, ninguém entrou nem vai entrar, e o cartão só
  // ocupa lugar a dizer «0 de 12». Aparece à hora da festa e não antes, pela
  // mesma razão por que a tira aparece: é a conta de uma noite, não de um
  // planeamento.
  const doDia = new Set(['porta']);
  return mapa.filter(x => temMod(x.chave) && (!doDia.has(x.chave) || horaDaFesta()))
             .sort((a, b) => falta(b.chave) - falta(a.chave)).map(x => {
    const m = MODULOS_PROG.find(y => y.chave === x.chave) || {};
    const vazio = !(m.total > 0);
    const num = m.unidade === 'dinheiro' ? fmtKz(m.feito) : (m.feito || 0);
    return { chave: x.chave, h: statCard({
      ic: `<i data-ico="${esc(m.ico||'todos')}" aria-hidden="true"></i>`,
      n: m.feito || 0, num: vazio ? '—' : num, rot: x.rot,
      sub: vazio ? esc(m.vazio || '') : subProg(x.chave, x.unidade),
      titulo: vazio ? (m.dica || m.vazio || x.rot) : `${x.rot}: ${m.feito} de ${m.total}`,
      onde: x.onde }) };
  });
}
function temMod(k){ return (MODULOS_PROG||[]).some(m => m.chave === k); }

/** No modo de arrumar, cada cartão ganha as setas que o mexem de lugar. */
function envolverArrumar(c){
  return `<span class="stat-arr">${c.h}
    <span class="stat-setas">
      <button type="button" onclick="moverCartao('${c.chave}',-1)" aria-label="Mover para trás">‹</button>
      <button type="button" onclick="moverCartao('${c.chave}',1)" aria-label="Mover para a frente">›</button>
    </span></span>`;
}
function moverCartao(chave, d){
  const o = ORD_ATUAL.slice();
  const i = o.indexOf(chave);
  const j = i + d;
  if (i < 0 || j < 0 || j >= o.length) return;
  o.splice(j, 0, o.splice(i, 1)[0]);
  guardarOrdem(o);
  // A nova ordem vale JÁ, e não só quando o próximo desenho acontecer: dois
  // toques seguidos na mesma seta liam os dois a mesma ordem velha e o cartão
  // só andava uma casa. E repinta-se do que já está em memória — reordenar
  // cartões não é razão para ir buscar a lista de convites ao servidor.
  ORD_ATUAL = o;
  if (ULTIMO_STATS) renderStats(ULTIMO_STATS); else carregar();
}
function arrumarCartoes(){
  ARRUMAR = !ARRUMAR;
  if (ULTIMO_STATS) renderStats(ULTIMO_STATS); else carregar();
}
function reporCartoes(){
  guardarOrdem([]); ARRUMAR = false;
  if (ULTIMO_STATS) renderStats(ULTIMO_STATS); else carregar();
}

// ---- O bar, no dia ------------------------------------------
// Só se mostra com o bar aberto ou com pedidos por decidir: um zero a zero
// numa terça-feira de Outubro é ruído. Quem gere a festa quer saber duas
// coisas de relance — se há alguém à espera, e quanto já saiu.
async function tiraDoBar(){
  const el = $('tira-bar');
  if (!el) return;
  const d = await api('bar_estado', { silencioso: true, semAviso: true });
  if (!d || !d.success) return;
  const e = d.estado || {};
  const porFazer = (e.em_analise||0) + (e.aprovados||0) + (e.a_caminho||0);
  if (!e.aberto && !porFazer) { el.hidden = true; return; }
  el.hidden = false;
  el.innerHTML = `<b>O bar está ${e.aberto ? 'aberto' : 'fechado'}</b>`
    + (e.em_analise ? ` · <span class="urg">${e.em_analise} por decidir</span>` : '')
    + (e.aprovados||e.a_caminho ? ` · ${(e.aprovados||0)+(e.a_caminho||0)} por entregar` : '')
    + ` · ${e.bebidas_entregues||0} servidas`
    + `<span class="ir">ir à copa <i data-ico="seta"></i></span>`;
}
function alternarStats(){ STATS_ABERTO = !STATS_ABERTO; carregar(); }

// Barra de progresso: preenchimento do número de convidados face à capacidade
let ULTIMAS_STATS = null;
/**
 * O momento em que o último convite responde (docs/auditoria-ui-ux.md, EMO-002).
 *
 * Meses de trabalho — escrever a lista, mandar os convites, lembrar quem não
 * respondeu — acabavam com um contador de pendentes a passar de 1 para 0. Sem
 * nada. O sítio onde isto se faz é uma ferramenta de trabalho, e faz bem em
 * sê-lo, mas há um punhado de momentos num casamento que merecem ser ditos, e
 * este é o maior deles: a lista está fechada.
 *
 * A tira fica enquanto for verdade — é o estado, e um estado que desaparece
 * obriga a ir confirmá-lo a outro lado. A festa (a animação e o anúncio) é uma
 * vez por pessoa, guardada no browser de cada uma: repetida a cada visita
 * deixava de ser um momento e passava a ser um enfeite.
 */
/**
 * A HORA DA FESTA — e a tira só existe dentro dela.
 *
 * A tira aparecia assim que o último convite respondesse, e isso pode ser em
 * Março para um casamento em Dezembro: ficava nove meses no cimo do painel, a
 * dar a notícia do dia em que foi dada. Uma tira que está sempre lá deixa de
 * se ver, e esta ocupa o lugar de onde se trabalha todos os dias.
 *
 * O dia e a hora saem do cabeçalho (#topo-contagem, data-dia/data-hora), que é
 * quem já os tem — pedi-los outra vez ao servidor era arranjar uma segunda
 * verdade para a mesma coisa. Sem data marcada não há hora nenhuma, e a tira
 * não aparece.
 *
 * A festa acaba às 6 da manhã do dia seguinte, e não à meia-noite: às duas da
 * manhã ainda se está na festa, e é a essa hora que alguém abre o painel para
 * ver quem falta chegar.
 */
function horaDaFesta(){
  const el = document.getElementById('topo-contagem');
  if (!el) return null;
  const dia  = el.getAttribute('data-dia')  || '';
  const hora = el.getAttribute('data-hora') || '';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dia)) return null;
  const inicio = new Date(dia + 'T' + (/^\d{2}:\d{2}$/.test(hora) ? hora : '00:00') + ':00');
  if (isNaN(inicio)) return null;
  const fim = new Date(dia + 'T00:00:00');
  fim.setDate(fim.getDate() + 1);
  fim.setHours(6, 0, 0, 0);
  const agora = AGORA_FESTA || new Date();
  return (agora >= inicio && agora < fim) ? { inicio, fim } : null;
}
// Só a prova lhe mexe: um relógio que se pode adiantar é a única maneira de
// verificar uma tira que depende da hora sem esperar pelo dia do casamento.
let AGORA_FESTA = null;

function momentoDeChegada(s){
  const el = $('chegada'); if (!el) return;
  if (!horaDaFesta()){ el.hidden = true; el.classList.remove('festa'); return; }

  const total = +s.convites || 0;
  const espera = +s.pes_confirmados || 0;
  const chegaram = +s.presentes || 0;
  const pendentes = +s.pendentes || 0;

  // Na festa, o que se pergunta ao painel é quem já chegou — e não quantos
  // convites responderam, que é conversa de Setembro.
  let texto;
  if (espera > 0 && chegaram >= espera) {
    texto = '<b>Estão todos cá.</b> ' + (espera === 1 ? 'Chegou 1 pessoa' : 'Chegaram as ' + espera + ' pessoas')
          + ' que confirmaram.';
  } else if (chegaram > 0) {
    texto = '<b>A festa começou.</b> Já chegaram <b>' + chegaram + '</b>'
          + (espera ? ' de ' + espera : '') + '.';
  } else if (espera > 0) {
    texto = '<b>É hoje.</b> ' + (espera === 1 ? 'Espera-se 1 pessoa' : 'Esperam-se ' + espera + ' pessoas')
          + (pendentes ? ', e ' + pendentes + ' convite(s) ainda sem resposta' : '')
          + '.';
  } else {
    texto = '<b>É hoje.</b> Ainda ninguém confirmou presença.';
  }
  el.innerHTML = '<span class="ch-ico" data-ico="brilho"></span><span>' + texto + '</span>';
  el.hidden = false;

  // A festa (a animação e o anúncio) é uma vez por pessoa e por casamento: é um
  // momento, e repetido a cada visita passava a ser um enfeite. A chave leva o
  // DIA, para o casamento seguinte da mesma casa ser um momento novo.
  const chave = 'chegada.' + (window.CASAMENTO_ID || 0) + '.' + (total || 0);
  let visto = true;
  try { visto = localStorage.getItem(chave) === '1'; } catch (e) {}
  if (visto) return;
  try { localStorage.setItem(chave, '1'); } catch (e) {}
  if (espera > 0) el.classList.add('festa');
  if (window.anunciar) anunciar(el.textContent.trim());
}

// A página fica aberta a tarde inteira, e a hora da festa chega sozinha. Sem
// isto, quem abriu o painel às cinco só via a tira ao recarregar — e a tira
// que só aparece se alguém a for buscar não serve para o que serve.
setInterval(() => { if (ULTIMAS_STATS) momentoDeChegada(ULTIMAS_STATS); }, 60000);

function renderProgresso(s){
  ULTIMAS_STATS = s;
  const cap=+s.capacidade||CAP||0;
  const conv=+s.lugares||0, conf=+s.pes_confirmados||0;
  // Quando a licença tem tecto, é ELE que a barra mede: é o número que trava.
  // A «capacidade» do salão continua a ver-se, mas como intenção — não é ela
  // que impede ninguém de entrar na lista.
  const temTeto = LIC_LIMITE > 0;
  const base = temTeto ? LIC_LIMITE : cap;
  const pConv=base?Math.min(100,Math.round(conv/base*100)):0;
  const pConf=base?Math.min(100,Math.round(conf/base*100)):0;
  const restam = temTeto ? Math.max(0, LIC_LIMITE-conv) : null;

  const nums = temTeto
    ? `<b>${conv}</b> de <b>${LIC_LIMITE}</b> convidados da licença · `
      + (restam>0 ? `<b class="${restam<=5?'v-falta':''}">${restam}</b> por usar`
                  : '<b class="v-falta">sem lugares livres</b>')
      + ` · <b class="v-conf">${conf}</b> confirmados`
    : `<b>${conv}</b> convidados · <b class="v-conf">${conf}</b> confirmados · capacidade <b>${cap}</b>`;

  $('progresso').innerHTML = `
    <div class="pc-topo">
      <span class="pc-tit">Convidados</span>
      <span class="pc-nums">${nums}</span>
    </div>
    <div class="pc-barra${temTeto&&restam===0?' cheia':''}" title="${conv} de ${base} lugares (${pConv}%)">
      <div class="pc-conv" style="width:${pConv}%"></div>
      <div class="pc-conf" style="width:${pConf}%"></div>
    </div>` + (temTeto && restam<=5 ? avisoTeto(restam) : '');
  travarNovoConvite(restam);
}

// ---------- onde vai cada módulo (docs/auditoria-ui-ux.md, UX-010) ----------
// Só aparecem os módulos que a licença abre: uma tira com barras de coisas que
// não se podem usar é uma montra disfarçada de progresso. E ordena-se pelo que
// FALTA, porque é isso que se vem aqui perguntar — não o que já está feito.
// O progresso de cada módulo já não tem tira própria: entra nos CARTÕES, na
// linha de baixo daquele que fala da mesma coisa (ver statCard e subProg). Isto
// só vai buscar os números e manda repintar.
async function carregarTiraModulos(){
  const d = await api('painel_progresso', { silencioso:true });
  if (!d || !d.success) return;
  MODULOS_PROG = (d.modulos || []).map(m => {
    const total = +m.total || 0, feito = +m.feito || 0;
    return Object.assign({}, m, { total, feito, falta: Math.max(0, total - feito),
                                  pct: total > 0 ? Math.round(feito / total * 100) : 0 });
  });
  // Os números chegam depois dos cartões já desenhados: sem isto, as linhas de
  // baixo ficavam a dizer só «N convites» até ao carregamento seguinte.
  if (ULTIMO_STATS) renderStats(ULTIMO_STATS);
}
let MODULOS_PROG = [];
let ULTIMO_STATS = null;

function fmtKz(v){
  return new Intl.NumberFormat('pt-PT', { maximumFractionDigits:0 }).format(v || 0);
}

/** O aviso de que o tecto está à vista — com a saída, que é reforçar a licença. */
function avisoTeto(restam){
  return `<div class="pc-aviso${restam===0?' cheio':''}">`
    + (restam===0
        ? 'Chegou ao limite de convidados da sua licença. Para convidar mais gente, '
        : `Faltam-lhe ${restam} lugar(es) na licença. Quando acabarem, `)
    + '<a href="licenca.php?quero=convidados">reforce a licença</a> — '
    + 'nada do que já fez se perde.</div>';
}

/**
 * Fecha o botão de novo convite quando a licença não dá para mais ninguém.
 *
 * Deixá-lo aberto para o servidor recusar a seguir é fazer o casal escrever um
 * convite inteiro para lho recusarem no fim. A porta fecha-se antes, e diz
 * porquê. (Editar os convites que já existem continua a poder fazer-se: não
 * gasta lugar nenhum.)
 */
function travarNovoConvite(restam){
  const b = document.querySelector('[onclick="novoConvite()"]');
  if (!b) return;
  const cheio = restam === 0;
  b.disabled = cheio;
  b.classList.toggle('travado', cheio);
  b.title = cheio
    ? 'A sua licença chegou ao limite de convidados. Reforce-a para convidar mais gente.'
    : '';
}

// Filtros a partir dos cartões (alternam ligado/desligado e recarregam)
function filtrarEstado(v){ filtroEstado = (filtroEstado===v?'':v); carregar(); }
function filtrarTipo(v){ if(filtroImpresso && v==='digital') filtroImpresso=''; filtroTipo = (filtroTipo===v?'':v); carregar(); }
function filtrarLado(v){ filtroLado = (filtroLado===v?'':v); carregar(); }
function filtrarImpresso(){ filtroImpresso = filtroImpresso==='1'?'':'1'; if(filtroImpresso==='1') filtroTipo=''; carregar(); }
function filtrarMesa(v){ filtroMesa = (filtroMesa===v?'':v); carregar(); }
function filtrarGenero(v){ filtroGenero = (filtroGenero===v?'':v); carregar(); }
function filtrarBrinde(){ filtroBrinde = filtroBrinde==='1'?'':'1'; carregar(); }
function limparFiltros(){ filtroTipo=''; filtroLado=''; filtroEstado=''; filtroMesa=''; filtroImpresso=''; filtroGenero=''; filtroBrinde=''; $('busca').value=''; carregar(); }

// Seletores de ícones do modal (substituem os selects)
const PICK = {
  'c-tipo':[['digital','Digital',IC.telemovel],['fisico','Físico',IC.envelope],['ambos','Ambos',IC.telemovel+IC.envelope]],
  'c-lado':[['noivo','Noivo',IC.noivo],['noiva','Noiva',IC.noiva],['ambos','Ambos',IC.noivo+IC.noiva]],
  'c-presenca':[['pendente','Pendente',IC.relogio],['confirmado','Confirmado',IC.check],['parcial','Parcial',IC.meio],['recusado','Recusado',IC.xis]]
};
function montarPickers(){
  document.querySelectorAll('.picker').forEach(box=>{
    const tid=box.dataset.target, opts=PICK[tid]||[];
    box.innerHTML=opts.map(([v,l,ic])=>`<button type="button" class="pk" data-v="${v}" onclick="pickVal('${tid}','${v}')"><span class="pk-ic">${ic}</span>${l}</button>`).join('');
  });
}
function pickVal(tid,v){
  $(tid).value=v;
  document.querySelectorAll('.picker[data-target="'+tid+'"] .pk').forEach(b=>b.classList.toggle('on', b.dataset.v===v));
  if(tid==='c-presenca') sincroPresencaMembros(v);
}

function tagEstado(e){
  const map={confirmado:['ok','Confirmado'],pendente:['pend','Pendente'],recusado:['rec','Recusado'],parcial:['parc','Parcial']};
  const [c,t]=map[e]||['neutra',e]; return `<span class="tag ${c}">${t}</span>`;
}
function iconeTipo(t){ return t==='fisico'?IC.envelope:(t==='ambos'?(IC.telemovel+IC.envelope):IC.telemovel); }
function iconeLado(l){ return l==='noiva'?IC.noiva:(l==='ambos'?(IC.noivo+IC.noiva):IC.noivo); }
// Ícones sugestivos de género (e brinde) para as pastilhas com nomes.
const genIco=g=> g==='m'?'<i class="gi gi-m" data-ico="homem" title="Masculino"></i> '
               :g==='f'?'<i class="gi gi-f" data-ico="mulher" title="Feminino"></i> ':'';
const brindeIco=b=> +b?' <i class="gi gi-b" data-ico="presente" title="Recebe brinde"></i>':'';
/** Primeiro nome — é o que se procura ao varrer a lista; o resto está no título. */
const primeiroNome=n=> String(n||'').trim().split(/\s+/)[0] || '';

// ---------- seleção múltipla / ações em massa ----------
const SELEC = new Set();
// Convites cuja lista de pessoas o utilizador abriu. Guarda-se à parte porque a
// lista é redesenhada por inteiro a cada ação — sem isto, fechava-se sozinha.
const PESSOAS_ABERTAS = new Set();
function alternarSelecao(id, on){ on ? SELEC.add(id) : SELEC.delete(id); renderBarraSelecao(); pintarSelecao(); }
function pintarSelecao(){
  document.querySelectorAll('.convite-row').forEach((row,i)=>{
    const c = CONVITES[i]; if(!c) return;
    row.classList.toggle('selecionada', SELEC.has(c.id));
    const cx = row.querySelector('.sel-conv input'); if(cx) cx.checked = SELEC.has(c.id);
  });
}
function selecionarTodos(on){
  SELEC.clear();
  if(on) CONVITES.forEach(c=>SELEC.add(c.id));
  renderBarraSelecao(); pintarSelecao();
}
function limparSelecao(){ SELEC.clear(); renderBarraSelecao(); pintarSelecao(); }
function renderBarraSelecao(){
  const b = $('barra-selecao'); if(!b) return;
  const n = SELEC.size;
  b.classList.toggle('on', n > 0);
  if(!n) return;
  b.innerHTML = `<span class="cont"><b>${n}</b> ${n===1?'convite selecionado':'convites selecionados'}</span>
    <button class="btn-ico" onclick="massaFlag('impresso',1)">Marcar impressos</button>
    <button class="btn-ico" onclick="massaFlag('impresso',0)">Desmarcar impressos</button>
    <button class="btn-ico" onclick="massaFlag('enviado',1)">Marcar enviados</button>
    <button class="btn-ico" onclick="massaFlag('enviado',0)">Desmarcar enviados</button>
    <button class="btn-ico" onclick="massaMesa()">Atribuir mesa…</button>
    <div class="cresce"></div>
    <button class="btn-ico" onclick="selecionarTodos(true)" title="${HA_MAIS?'Só os que já estão na lista; use "Mostrar mais" para trazer os restantes':''}">Selecionar ${HA_MAIS?'os visíveis':'todos'} (${CONVITES.length})</button>
    <button class="btn-ico" onclick="limparSelecao()">Limpar</button>`;
}
// Aplica uma marcação a todos os selecionados, um pedido de cada vez.
async function massaFlag(campo, valor){
  const ids = [...SELEC]; if(!ids.length) return;
  let feitos = 0;
  for(const id of ids){
    const d = await api(`convite_flag&id=${id}&campo=${campo}&valor=${valor}`, {silencioso:true});
    if(d && d.success) feitos++;
  }
  toast(`${feitos} de ${ids.length} convite(s) atualizados.`);
  limparSelecao(); carregar();
}
function massaMesa(){
  const ids = [...SELEC]; if(!ids.length) return;
  const livres = (MESAS||[]).filter(m=>m.especial!=='noivos');
  if(!livres.length) return toast('Ainda não há mesas criadas.', true);

  // Escolher de uma lista, e não escrever o nome à mão.
  //
  // O prompt() dava a lista das mesas no seu próprio texto e depois pedia que
  // se copiasse uma delas — de cor, sem a poder ver enquanto se escrevia, e
  // com um engano numa letra a valer «não existe uma mesa com esse nome».
  // Uma escolha entre coisas que já existem não é uma pergunta aberta.
  licFormulario({
    titulo: 'Atribuir ' + ids.length + ' convite(s) a uma mesa',
    dica: 'Os convites escolhidos passam todos para a mesma mesa.',
    guardar: 'Atribuir',
    campos: [{ id:'mesa', rot:'Mesa', tipo:'escolha', largura:3, valor:'',
               opcoes: [{ v:'', r:'— Retirar da mesa —' }]
                 .concat(livres.map(m => ({ v:String(m.id),
                   r: m.nome + (m.lugares ? ' · ' + m.lugares + ' lugares' : '') }))) }],
    aoGuardar: async (v) => {
      const mesa = livres.find(m => String(m.id) === String(v.mesa));
      let feitos = 0;
      for(const id of ids){
        const d = await api('convite_mesa', {method:'POST', silencioso:true,
          body: JSON.stringify({id, mesa_id: mesa ? mesa.id : ''})});
        if(d && d.success) feitos++;
      }
      toast(`${feitos} de ${ids.length} convite(s) ${mesa ? 'atribuídos a '+mesa.nome : 'retirados da mesa'}.`);
      limparSelecao(); carregar();
    }
  });
}

function renderConvites(){
  const el=$('lista');
  if(!CONVITES.length){
    // Um vazio por causa de um filtro tem de o confessar: senão lê-se como
    // «não há convites nenhuns» e a pessoa vai criar o que já lá está.
    const filtrado = !!(filtroTipo||filtroLado||filtroEstado||filtroMesa||filtroImpresso
                        ||filtroGenero||filtroBrinde||$('busca').value.trim());
    el.innerHTML = filtrado
      ? EST.vazio('procurar', 'Nenhum convite com estes filtros',
          'Há convites na lista, mas nenhum corresponde ao que está escolhido agora.',
          '<button class="btn btn-fantasma" onclick="limparFiltros()">Limpar filtros</button>')
      : EST.vazio('brilho', 'Ainda não há convites',
          'É por aqui que se começa: crie o primeiro convite, ou importe a lista que já tem numa folha de cálculo.',
          '<button class="btn btn-ouro" data-escrita="1" onclick="novoConvite()">+ Novo convite</button>');
    return;
  }
  // Convites que saíram da lista (por filtro) deixam de contar para a seleção
  [...SELEC].forEach(id => { if(!CONVITES.some(c=>c.id==id)) SELEC.delete(id); });
  renderBarraSelecao();
  el.innerHTML = CONVITES.map(c=>{
    // A tira de pessoas é um resumo para varrer com o olho, não a lista toda:
    // com seis convidados as pastilhas quebravam para uma segunda linha e o
    // cartão passava de 53px para 104px. Mostram-se os primeiros nomes de
    // alguns e conta-se o resto; o nome completo fica no título e na edição.
    const pessoas = (c.membros_det&&c.membros_det.length ? c.membros_det : (c.membros||[]).map(n=>({nome:n,genero:'',brinde:0})));
    const MAX_CHIPS = 2;
    const aberto = PESSOAS_ABERTAS.has(c.id);
    const escondidos = pessoas.length - MAX_CHIPS;
    // Aberto mostra toda a gente pelo nome completo; fechado, só os primeiros
    // nomes de dois e a conta do resto — que é um botão, não um rótulo.
    const chip = m => `<span class="membro-chip" title="${esc(m.nome)}">`
      + `${genIco(m.genero)}${esc(aberto ? m.nome : primeiroNome(m.nome))}${brindeIco(m.brinde)}</span>`;
    const membros = (aberto ? pessoas : pessoas.slice(0, MAX_CHIPS)).map(chip).join('')
      + (escondidos > 0
          ? `<button type="button" class="membro-chip mais" aria-expanded="${aberto}"
               title="${aberto ? 'Mostrar menos' : 'Ver os outros '+escondidos+' convidados'}"
               onclick="alternarPessoas(${c.id})">${aberto ? '− menos' : '+'+escondidos}</button>`
          : '');
    const confTxt = c.rsvp_confirmados!=null && c.rsvp_estado!=='pendente' ? ` · ${c.rsvp_confirmados}/${c.lugares} confirmados` : '';
    const presTxt = c.checkin_presentes>0 ? ` · <span style="color:var(--ok)">${c.checkin_presentes} no local</span>` : '';
    return `<div class="convite-row${SELEC.has(c.id)?' selecionada':''}${aberto?' pessoas-abertas':''}">
      <label class="sel-conv" title="Selecionar para ações em massa">
        <input type="checkbox" ${SELEC.has(c.id)?'checked':''} onchange="alternarSelecao(${c.id},this.checked)">
      </label>
      <div class="selo-tipo ${c.tipo}" title="${c.tipo}">${iconeTipo(c.tipo)}</div>
      <div class="convite-corpo">
        <div class="convite-nome" title="${esc(c.nome_final)}">${esc(c.nome_final)}</div>
        <div class="convite-meta">
          <span>${tagEstado(c.rsvp_estado)}</span>
          <span>${(+c.mesas_distintas>1)?('Dividido · '+c.mesas_distintas+' mesas'):(c.mesa_efetiva_nome?('Mesa: '+esc(c.mesa_efetiva_nome)):'Sem mesa')}</span>
          <span>Cód: <strong>${c.codigo}</strong></span>
          ${c.telefone?`<span>${esc(c.telefone)}</span>`:''}
          ${(c.rsvp_mensagem&&c.rsvp_mensagem.trim())?`<span class="tem-msg" title="Mensagem: ${esc(c.rsvp_mensagem)}" onclick="abrirMensagens()">${IC.balao}</span>`:''}
          <span class="lado-ic" title="Lado: ${c.lado}">${iconeLado(c.lado)}</span>
          <span style="color:#b7bbb5">${(confTxt+presTxt).replace(/^ · /,'')}</span>
        </div>
        <div class="membros-mini">${membros}</div>
      </div>
      <div class="acoes">
        ${c.telefone
          ? `<button class="btn-ico bt-wa" title="Enviar o convite por WhatsApp para ${esc(c.telefone)}" onclick="enviarWhatsApp(${c.id})">${IC.whatsapp} Enviar</button>`
          : `<button class="btn-ico" title="Sem telefone — adicione-o para poder enviar" onclick="editar(${c.id})" style="opacity:.55">${IC.whatsapp} Sem nº</button>`}
        <button class="btn-ico" title="Editar" onclick="editar(${c.id})">Editar</button>
        ${c.tipo!=='fisico'?`<button class="btn-ico" title="Marcar como enviado" onclick="flag(${c.id},'enviado',${c.enviado?0:1})" style="${c.enviado?'background:var(--ok-bg);color:var(--ok)':''}">${c.enviado?'Enviado <i data-ico="visto"></i>':'Enviado'}</button>`:''}
        ${c.tipo!=='digital'?`<button class="btn-ico" title="Marcar como impresso" onclick="flag(${c.id},'impresso',${c.impresso?0:1})" style="${c.impresso?'background:var(--ok-bg);color:var(--ok)':''}">${c.impresso?'Impresso <i data-ico="visto"></i>':'Impresso'}</button>`:''}
        <div class="menu-mais">
          <button class="btn-ico bt-mais" title="Mais ações" aria-haspopup="true" data-escrita="0" onclick="abrirMais(event,${c.id})"><svg class="ico-mais" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.4" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="12.6" cy="8" r="1.5"/></svg></button>
        </div>
      </div>
    </div>`;
  }).join('') + rodapeLista();
  marcarMetaCortada();
}

/** Abre ou fecha a lista de pessoas de um convite, no próprio cartão. */
function alternarPessoas(id){
  if(PESSOAS_ABERTAS.has(id)) PESSOAS_ABERTAS.delete(id); else PESSOAS_ABERTAS.add(id);
  renderConvites();
}

/**
 * Marca as linhas de estado que não couberam, para o esbatido aparecer só onde
 * há mesmo texto cortado. Só o CSS não chega: é preciso medir.
 */
function marcarMetaCortada(){
  document.querySelectorAll('.convite-row .convite-meta').forEach(m=>{
    m.classList.toggle('cortado', m.scrollWidth > m.clientWidth + 1);
  });
}
addEventListener('resize', ()=>{ clearTimeout(window._tMeta); window._tMeta=setTimeout(marcarMetaCortada,150); });

/** Rodapé da lista: quantos se veem, quantos há e o botão para trazer mais. */
function rodapeLista(){
  if(!HA_MAIS) return TOTAL > CONVITES.length ? '' :
    (TOTAL > 10 ? `<p class="fim-lista">${TOTAL} convite(s) — está tudo aqui.</p>` : '');
  const faltam = TOTAL - CONVITES.length;
  return `<button class="btn-mais-lista" onclick="carregar(true)">
      Mostrar mais <span class="conta-extra">${faltam}</span>
      <small>a ver ${CONVITES.length} de ${TOTAL}</small>
    </button>`;
}

function renderFiltroMesas(){
  const box=$('filtro-mesas'); if(!box) return;
  if(!MESAS.length){ box.innerHTML=''; return; }
  const chip=(v,l,on)=>`<button class="chip-m${on?' on':''}" onclick="filtrarMesa('${esc(v)}')">${l}</button>`;
  // Pessoas por sentar = total de lugares menos os já sentados nas mesas
  const sentados=MESAS.reduce((s,m)=>s+(+m.ocupacao||0),0);
  const semMesa=Math.max(0,(+STATS.lugares||0)-sentados);
  box.innerHTML = `<span class="chips-lbl">Mesa</span>`
    + chip('', 'Todas', !filtroMesa)
    + chip(SEM_MESA, 'Sem mesa'+`<span class="chip-n">${semMesa}</span>`, filtroMesa===SEM_MESA)
    + MESAS.map(m=>chip(m.nome, esc(m.nome)+`<span class="chip-n">${m.ocupacao||0}</span>`, filtroMesa===m.nome)).join('');
}
function renderDatalistMesas(){ $('lista-mesas').innerHTML=MESAS.map(m=>`<option value="${esc(m.nome)}">`).join(''); }

// ---------- modal convite ----------
function novoConvite(){
  // Segunda fechadura: o botão pode estar desactivado, mas a função continua
  // a poder ser chamada (pelo teclado, por um atalho, pela consola). Aqui
  // fecha-se de vez, e diz-se onde se resolve.
  const s = ULTIMAS_STATS || {};
  if (LIC_LIMITE > 0 && (+s.lugares || 0) >= LIC_LIMITE) {
    toast('A sua licença chega a ' + LIC_LIMITE + ' convidados e já os tem todos. '
        + 'Reforce a licença para convidar mais gente.', true);
    return;
  }
  abrirConvite(null);
}
async function editar(id){ const d=await api('convite_get&id='+id); if(d.success) abrirConvite(d.convite); }

function abrirConvite(c){
  $('modal-titulo').textContent = c?'Editar convite':'Novo convite';
  // Num convite novo, o nome a exibir é proposto a partir dos nomes das pessoas.
  // Num já existente, o nome é escolha do utilizador — não se lhe mexe.
  NOME_AUTO = !c;
  $('c-id').value = c?c.id:'';
  $('c-nome').value = c?c.nome_exibicao:'';
  $('c-mostrar-num-mesa').checked = c ? (String(c.mostrar_num_mesa)!=='0') : true;
  pickVal('c-tipo', c?c.tipo:'digital');
  pickVal('c-lado', c?c.lado:'noivo');
  pickVal('c-presenca', c?(c.rsvp_estado||'pendente'):'pendente');
  $('c-mesa').value = c?(c.mesa_nome||''):'';
  $('c-telefone').value = c?(c.telefone||''):'';
  $('c-obs').value = c?(c.observacoes||''):'';
  $('c-msg').value = c?(c.msg_pessoal||''):'';
  $('membros').innerHTML='';
  const ms = c&&c.membros&&c.membros.length ? c.membros : [{nome:'',rsvp:'confirmado'}];
  ms.forEach(m=>addMembro(m.nome||'', m.rsvp ? m.rsvp==='confirmado' : true, m.mesa_id||'', m.papel||'', m.genero||'', !!(+m.brinde)));
  sincroPresencaMembros($('c-presenca').value);
  if(c){ $('bloco-link').style.display='block'; $('c-link').value=BASE+'/convite-digital.php?c='+c.codigo; $('c-link').dataset.codigo=c.codigo; }
  else { $('bloco-link').style.display='none'; }
  atualizarPrevia(); renderSugestoes();
  abrir('ov-convite');
}
function opcoesMesaMembro(selId){
  let o='<option value="">Mesa do convite</option>';
  // A mesa dos noivos não é selecionável: só a integram padrinhos e madrinhas (pelo papel).
  (MESAS||[]).filter(m=>m.especial!=='noivos').forEach(m=>{ o+=`<option value="${m.id}" ${String(selId)===String(m.id)?'selected':''}>${esc(m.nome)}</option>`; });
  return o;
}
/**
 * Um par de botões que guarda a escolha em data-v. Serve o género e o papel:
 * duas perguntas de duas respostas, e nas duas se quer ver a resposta sem
 * abrir nada.
 */
function segMembro(classe, v, botoes){
  return `<div class="seg ${classe}" data-v="${esc(v)}">`
    + botoes.map(([val, rot, tit]) =>
        `<button type="button" data-v="${esc(val)}" class="${v===val?'on':''}"
                 title="${esc(tit||rot)}">${rot}</button>`).join('')
    + `</div>`;
}
/** O que a segunda pastilha do papel diz — e se se pode sequer carregar nela. */
const PM_ROTULO = { m:'Padrinho', f:'Madrinha', '':'Padrinho · Madrinha' };
function addMembro(valor='', vai=true, mesaId='', papel='', genero='', brinde=false){
  const div=document.createElement('div'); div.className='membro-linha';
  // Duas linhas de colunas alinhadas, e nada escondido: em cima o nome (metade
  // da largura), a mesa e os brindes (um quarto cada); em baixo as quatro
  // pastilhas do género e do papel, um quarto cada, debaixo dos campos de
  // cima. Estavam atrás de um "⋯" para caberem; alinhadas em grelha cabem, e
  // uma pessoa lê-se toda de uma vez.
  div.innerHTML=`<label class="m-vai" title="Esta pessoa confirma presença"><input type="checkbox" ${vai?'checked':''}></label>
    <input type="text" placeholder="Nome completo" value="${esc(valor)}" oninput="renderSugestoes()">
    <select class="m-mesa" title="Mesa desta pessoa (por omissão, a do convite)"
            onchange="sincroMesaPapel(this.closest('.membro-linha'))">${opcoesMesaMembro(mesaId)}</select>
    <label class="m-brinde" title="Esta pessoa recebe brinde">Brindes? <input type="checkbox" ${brinde?'checked':''}> <i data-ico="presente"></i></label>
    <button class="btn-ico" type="button" title="Retirar esta pessoa"
            onclick="this.closest('.membro-linha').remove();renderSugestoes();atualizarPrevia();contarPessoas()"><i data-ico="xis"></i></button>
    <div class="m-extras" onclick="cliqueSeg(event)">
      ${segMembro('m-genero', genero, [
        ['m', '<i data-ico="homem"></i> Masculino', 'Masculino'],
        ['f', '<i data-ico="mulher"></i> Feminino',  'Feminino']])}
      ${segMembro('m-papel', papel, [
        ['',   'Convidado', 'Convidado(a) — o papel de origem'],
        ['pm', PM_ROTULO[genero] || PM_ROTULO[''],
               'Padrinhos e madrinhas sentam-se nas alas da mesa dos noivos']])}
    </div>`;
  $('membros').appendChild(div);
  // Num casamento com trinta mesas, o <select> de fábrica é uma lista para
  // rolar às cegas: escreve-se «padrinhos» e acha-se. Abaixo de nove mesas
  // fica o <select> nativo, que no telemóvel abre a roda do sistema e é
  // melhor do que qualquer coisa que se desenhe.
  if (window.licSelUpgrade) {
    licSelUpgrade(div.querySelector('.m-mesa'),
                  { rotulo: 'Mesa desta pessoa', dicaProcura: 'Nome da mesa' });
  }
  sincroPapelGenero(div);
  sincroMesaPapel(div);
  contarPessoas();
}

/** Valor escolhido num par de pastilhas. */
function segValor(row, classe){ return row.querySelector('.'+classe)?.dataset.v || ''; }

/**
 * Clique numa das pastilhas de género ou de papel.
 *
 * O género alterna: carregar na que já está escolhida limpa-a (o género é
 * opcional, como sempre foi). O papel não — "Convidado" é o de origem e há de
 * estar sempre um dos dois aceso.
 */
function cliqueSeg(ev){
  const bt = ev.target.closest('.seg button'); if (!bt) return;
  const seg = bt.closest('.seg'), row = bt.closest('.membro-linha');
  if (seg.classList.contains('m-genero')) {
    seg.dataset.v = (seg.dataset.v === bt.dataset.v) ? '' : bt.dataset.v;
  } else {
    if (bt.disabled) return;
    // A pastilha guarda "pm"; o que se grava é a palavra que o género dita.
    seg.dataset.v = bt.dataset.v === 'pm'
      ? (segValor(row, 'm-genero') === 'f' ? 'madrinha' : 'padrinho') : '';
  }
  sincroPapelGenero(row);
  sincroMesaPapel(row);
  atualizarPrevia();
}

/**
 * O papel é gendrado: chama-se padrinho ou madrinha conforme quem o tem. Por
 * isso a segunda pastilha só se pode escrever depois de o género estar dito —
 * até lá anuncia as duas hipóteses e não se deixa carregar. Escolhido o
 * género, o rótulo passa a ser a palavra certa, e quem já era padrinho passa a
 * madrinha (ou o contrário) sem ter de o dizer outra vez.
 */
function sincroPapelGenero(row){
  if (!row) return;
  const g = segValor(row, 'm-genero');
  const seg = row.querySelector('.m-papel'); if (!seg) return;
  // Sem género não há palavra: o papel volta a Convidado.
  if (!g && seg.dataset.v) seg.dataset.v = '';
  if (g && seg.dataset.v) seg.dataset.v = (g === 'f' ? 'madrinha' : 'padrinho');

  row.querySelectorAll('.m-genero button').forEach(b =>
    b.classList.toggle('on', b.dataset.v === g));

  const [btConv, btPm] = seg.querySelectorAll('button');
  btPm.textContent = PM_ROTULO[g] || PM_ROTULO[''];
  btPm.disabled = !g;
  btPm.title = g ? 'Padrinhos e madrinhas sentam-se nas alas da mesa dos noivos'
                 : 'Escolha primeiro o género — este papel chama-se padrinho ou madrinha conforme.';
  btConv.classList.toggle('on', !seg.dataset.v);
  btPm.classList.toggle('on', !!seg.dataset.v);
}
/** Quantas pessoas nomeadas, ao lado do título da secção. */
function contarPessoas(){
  const el=$('cont-pessoas'); if(!el) return;
  const n=nomesMembros().length;
  el.textContent = n ? (n===1 ? '1 pessoa' : n+' pessoas') : '';
}
// Padrinhos/madrinhas pertencem à mesa dos noivos (pelo papel); nesse caso a mesa
// individual não se aplica — mostra "Mesa dos noivos" e desativa o seletor.
function sincroMesaPapel(row){
  if(!row) return;
  const mesaSel=row.querySelector('.m-mesa');
  if(!row.querySelector('.m-papel')||!mesaSel) return;
  // A caixa com procura, se ela existir, tem de acompanhar o <select> — que
  // continua a ser quem manda. É por isso que ela se pendura POR CIMA de um
  // select a sério e não o substitui: este código não teve de mudar.
  const cxSel = mesaSel.closest('.lic-sel');
  const ehPapel = !!segValor(row, 'm-papel');
  let opt=mesaSel.querySelector('option[data-noivos]');
  if(ehPapel){
    if(!opt){ opt=document.createElement('option'); opt.dataset.noivos='1'; opt.value=''; opt.textContent='Mesa dos noivos'; mesaSel.insertBefore(opt, mesaSel.firstChild); }
    mesaSel.value=''; mesaSel.selectedIndex=[...mesaSel.options].indexOf(opt);
    mesaSel.disabled=true;
  } else {
    if(opt) opt.remove();
    mesaSel.disabled=false;
  }
  if(cxSel && window.licSelRefrescar) licSelRefrescar(cxSel);
}
function nomesMembros(){ return [...$('membros').querySelectorAll('input[type=text]')].map(i=>i.value.trim()).filter(Boolean); }
function membrosComPresenca(){
  return [...$('membros').querySelectorAll('.membro-linha')].map(row=>({
    nome: row.querySelector('input[type=text]').value.trim(),
    vai:  row.querySelector('.m-vai input')?.checked ?? true,
    mesa_id: row.querySelector('.m-mesa') ? row.querySelector('.m-mesa').value : '',
    papel: segValor(row, 'm-papel'),
    genero: segValor(row, 'm-genero'),
    brinde: row.querySelector('.m-brinde input')?.checked ? 1 : 0
  })).filter(m=>m.nome);
}
// Mostra/oculta as marcações por pessoa conforme a presença escolhida
function sincroPresencaMembros(v){
  const box=$('membros'); if(!box) return;
  box.classList.toggle('parcial', v==='parcial');
  const checks=box.querySelectorAll('.m-vai input');
  if(v==='confirmado') checks.forEach(c=>c.checked=true);
  else if(v==='recusado') checks.forEach(c=>c.checked=false);
  // parcial e pendente: mantêm as marcações atuais (editáveis quando parcial)
}

function renderSugestoes(){
  const nomes=nomesMembros(); const box=$('sugestoes'); const sug=new Set();
  if(nomes.length===1){ sug.add(nomes[0]); const p=nomes[0].split(' '); if(p.length>1) sug.add('Sr./Sra. '+p[p.length-1]); }
  if(nomes.length>=2){
    const apel=nomes.map(n=>n.trim().split(' ').pop());
    const comum=apel.every(a=>a.toLowerCase()===apel[0].toLowerCase());
    if(comum) sug.add('Família '+apel[0]);
    const primeiros=nomes.map(n=>n.split(' ')[0]);
    if(primeiros.length===2) sug.add(primeiros[0]+' e '+primeiros[1]);
    else if(primeiros.length>2) sug.add(primeiros.slice(0,-1).join(', ')+' e '+primeiros.slice(-1));
  }
  box.innerHTML=[...sug].map(s=>`<span class="sugestao" onclick="NOME_AUTO=false;$('c-nome').value=this.textContent;atualizarPrevia()">${esc(s)}</span>`).join('');
  // A melhor sugestão entra já no campo, em vez de esperar por um clique. Deixa
  // de ser uma decisão a tomar e passa a ser uma proposta a corrigir — mas só
  // enquanto ninguém lhe tiver mexido à mão.
  if(NOME_AUTO){
    const primeira=[...sug][0]||'';
    if($('c-nome').value!==primeira){ $('c-nome').value=primeira; atualizarPrevia(); }
  }
  contarPessoas();
}
function atualizarPrevia(){
  $('previa').textContent = $('c-nome').value.trim() || '(nome do convite)';
}

async function guardarConvite(){
  const nome=$('c-nome').value.trim();
  if(!nome) return toast('Indique o nome a exibir no convite.', true);
  const payload={
    // Sem 'lugares': o servidor conta-os pelos nomes das pessoas.
    id:$('c-id').value||0, nome_exibicao:nome,
    mostrar_num_mesa:$('c-mostrar-num-mesa').checked?1:0,
    tipo:$('c-tipo').value, lado:$('c-lado').value,
    mesa:$('c-mesa').value, telefone:$('c-telefone').value, observacoes:$('c-obs').value,
    msg_pessoal:$('c-msg').value,
    presenca:$('c-presenca').value,
    membros:membrosComPresenca()
  };
  const d=await api('convite_save',{method:'POST',body:JSON.stringify(payload)});
  if(!d.success) return toast(d.message||'Erro ao guardar.', true);
  toast('Convite guardado.'); fechar('ov-convite'); carregar();
}

// ---------- ações de linha ----------
async function flag(id,campo,valor){ const d=await api(`convite_flag&id=${id}&campo=${campo}&valor=${valor}`); if(d.success){toast('Atualizado.');carregar();} }
async function eliminar(id){ const c=CONVITES.find(x=>x.id==id); const nome=c?c.nome_final:'este convite';
  const r = await licConfirmar({
    titulo: 'Eliminar o convite «' + licEsc(nome) + '»?',
    icone: 'lixo', confirmar: 'Eliminar convite',
    texto: 'Vai para a <b>reciclagem</b>, e as pessoas dele vão com ele.<br><br>'
         + 'Pode <b>repô-lo</b> a qualquer momento, em Histórico.'
  });
  if (!r.sim) return;
  const d=await api('convite_delete&id='+id);
  if(d.success){ toastAnular(`"${nome}" foi para a reciclagem.`, ()=>repor(id)); carregar(); } }

/** Repõe um convite que estava na reciclagem. */
async function repor(id){
  const d=await api('convite_restaurar&id='+id);
  if(d.success){ toast('Convite reposto.'); carregar(); if($('ov-historico').classList.contains('aberto')) abaHistorico('lixo'); }
}

function linkConvite(codigo){ return BASE+'/convite-digital.php?c='+codigo; }

// ---------- enviar por WhatsApp ----------
// Abre a conversa com o convidado, já com a mensagem e o link do convite.
function telefoneWa(t){
  const d = (t||'').replace(/\D/g,'');
  if(!d) return '';
  // Sem indicativo: assume Angola (244). Com 9 dígitos a começar por 9.
  if(d.length === 9 && d[0] === '9') return '244'+d;
  return d;
}
function mensagemWhatsApp(c){
  const nome = (c.nome_final || c.nome_exibicao || '').trim();
  const l = linkConvite(c.codigo);
  return `Olá ${nome}!\n\n${CASAL} têm o prazer de vos convidar para o seu casamento, no dia ${DATA_EXT}.\n\nO convite está aqui:\n${l}\n\nAgradecemos que confirme a presença por esse link. Até lá!`;
}
function enviarWhatsApp(id){
  const c = CONVITES.find(x=>x.id==id); if(!c) return;
  const tel = telefoneWa(c.telefone);
  if(!tel) return toast('Este convite não tem telefone. Edite-o para o adicionar.', true);
  window.open('https://wa.me/'+tel+'?text='+encodeURIComponent(mensagemWhatsApp(c)), '_blank', 'noopener');
  // Marca como enviado, se ainda não estiver (é o objetivo da ação).
  if(!(+c.enviado)) flag(id,'enviado',1);
}

// ---------- menus "⋯" ----------
// Desenhar e posicionar vive num sítio só. São dois menus com o mesmo feitio:
// o de cada linha de convite e o da barra de ações. Escrevê-los duas vezes já
// custou uma: a barra de ações chegou a chamar um `abrirMais` de outro ficheiro
// com outra assinatura, e o menu não abria sem dar erro nenhum — a versão desta
// página sai calada quando não encontra o convite que lhe pedem.
function fecharMais(){ const m=document.getElementById('pop-mais'); if(m) m.remove(); }
function mostrarPop(ev, itens){
  ev.stopPropagation(); fecharMais();
  const pop = document.createElement('div');
  pop.id = 'pop-mais'; pop.className = 'pop-mais';
  pop.innerHTML = itens.map(([r,acao,cls]) => cls === 'link'
    ? `<a href="${acao}" onclick="fecharMais()">${r}</a>`
    : `<button class="${cls||''}" onclick="fecharMais();${acao}">${r}</button>`).join('');
  document.body.appendChild(pop);
  const r = ev.currentTarget.getBoundingClientRect();
  const larg = 190;
  pop.style.left = Math.max(8, Math.min(window.innerWidth - larg - 8, r.right - larg)) + 'px';
  // Abre para baixo, mas se não couber abre para cima: nos últimos convites da
  // lista o menu ficava cortado pela borda de baixo e as ações lá do fundo —
  // eliminar, entre elas — nem se viam.
  const alt = pop.offsetHeight;
  const folgaBaixo = window.innerHeight - r.bottom - 6;
  const paraCima = folgaBaixo < alt && r.top - 6 > folgaBaixo;
  pop.classList.toggle('acima', paraCima);
  pop.style.top = paraCima
    ? Math.max(8, r.top - alt - 6) + 'px'
    : Math.min(r.bottom + 6, window.innerHeight - alt - 8) + 'px';
  setTimeout(()=>document.addEventListener('click', fecharMais, {once:true}), 0);
}
function abrirMais(ev, id){
  const c = CONVITES.find(x=>x.id==id); if(!c) return;
  mostrarPop(ev, [
    ['Copiar link',              `copiarLinkDireto('${c.codigo}')`],
    ['Ver convite digital',      `verConvite('${c.codigo}')`],
    ['Descarregar (offline)',    `baixarConvite('${c.codigo}')`],
    ['Mostrar QR',               `mostrarQR('${c.codigo}')`],
    ['Eliminar convite',         `eliminar(${c.id})`, 'perigo'],
  ]);
}
// As acções de uma vez por casamento (docs/auditoria-ui-ux.md, CTA-001).
// «Exportar CSV» fica uma ligação de verdade: é uma descarga, e um botão que
// atribui `location` tira-lhe o botão do meio e o «guardar como».
function abrirAccoes(ev){
  mostrarPop(ev, [
    ['Mensagens dos convidados', 'abrirMensagens()'],
    ['Quem ainda não respondeu', 'abrirLembretes()'],
    ['Perguntas da confirmação', 'abrirPerguntas()'],
    ['Entradas à porta',         'abrirEntradas()'],
    ['Histórico',                'abrirHistorico()'],
    ['Exportar CSV',             'api.php?action=export', 'link'],
  ]);
}
document.addEventListener('keydown', e => { if(e.key==='Escape') fecharMais(); });
function copiarLinkDireto(codigo){ copiarTexto(linkConvite(codigo)); }
function copiarLink(){ copiarTexto($('c-link').value); }
function verConvite(codigo){ window.open(linkConvite(codigo),'_blank','noopener'); }
function baixarConvite(codigo){ window.location.href = linkConvite(codigo)+'&download=1'; }
function copiarTexto(t){
  const feito=()=>toast('Link do convite copiado.');
  if(navigator.clipboard && window.isSecureContext){
    navigator.clipboard.writeText(t).then(feito).catch(()=>copiaFallback(t,feito));
  } else { copiaFallback(t,feito); }
}
function copiaFallback(t,feito){
  const ta=document.createElement('textarea'); ta.value=t;
  ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta);
  ta.focus(); ta.select();
  let ok=false; try{ ok=document.execCommand('copy'); }catch(e){}
  document.body.removeChild(ta);
  ok?feito():toast('Copie manualmente: '+t, true);
}

// ---------- QR ----------
let qrAtual=null;
function desenharQR(codigo, titulo){
  qrAtual={codigo,titulo};
  $('qr-titulo').textContent=titulo||'Código QR';
  new QRious({ element:$('qr-canvas'), value:BASE+'/convite.php?c='+codigo, size:280, level:'M',
               foreground:'#20342A', background:'#ffffff' });
  abrir('ov-qr');
}
function mostrarQR(codigo){ const c=CONVITES.find(x=>x.codigo===codigo); desenharQR(codigo, c?c.nome_final:'Código QR'); }
function mostrarQRatual(){ desenharQR($('c-link').dataset.codigo, $('c-nome').value); }
function descarregarQR(){
  const a=document.createElement('a'); a.download='qr_'+(qrAtual?qrAtual.codigo:'convite')+'.png';
  a.href=$('qr-canvas').toDataURL('image/png'); a.click();
}

// ---------- mesas ----------
async function abrirMesas(){ await renderMesasGestao(); abrir('ov-mesas'); }
async function renderMesasGestao(){
  const d=await api('mesa_list'); MESAS=d.mesas;
  $('lista-mesas-gestao').innerHTML = MESAS.length? MESAS.map(m=>{
    const cap=m.capacidade||0; const perc=cap?Math.min(100,Math.round(m.ocupacao/cap*100)):0;
    // O ícone diz, sem se ler, o que a linha diz por palavras: a forma da
    // mesa, quantas cadeiras tem, e se já está cheia.
    return `<div class="mesa-item">
      ${mesaIcone(m, {tam:42})}
      <div class="info"><strong>${esc(m.nome)}</strong>
        <div class="ocup">${m.ocupacao} lugar(es)${cap?(' de '+cap):''} · ${m.convites} convite(s)</div>
        ${cap?`<div class="barra-ocup"><span class="${perc>=100?'cheio':''}" style="width:${perc}%"></span></div>`:''}
      </div>
      <button class="btn-ico" onclick="editarMesa(${m.id})">Editar</button>
      <button class="btn-ico" onclick="eliminarMesa(${m.id})"><i data-ico="xis"></i></button>
    </div>`;
  }).join('') : '<p style="color:var(--ink-fraco)">Ainda não há mesas.</p>';
}
function editarMesa(id){ const m=MESAS.find(x=>x.id==id); if(!m)return; $('m-id').value=m.id; $('m-nome').value=m.nome; $('m-cap').value=m.capacidade||''; }
async function guardarMesa(){
  const nome=$('m-nome').value.trim(); if(!nome)return toast('Indique o nome da mesa.',true);
  const d=await api('mesa_save',{method:'POST',body:JSON.stringify({id:$('m-id').value||0,nome,capacidade:$('m-cap').value})});
  if(!d.success)return toast(d.message,true);
  $('m-id').value='';$('m-nome').value='';$('m-cap').value=''; MESAS=d.mesas; renderMesasGestao(); renderFiltroMesas(); renderDatalistMesas(); toast('Mesa guardada.');
}
async function eliminarMesa(id){ const m=MESAS.find(x=>x.id==id); const nome=m?m.nome:'esta mesa';
  const sentados = (CONVITES||[]).filter(c => String(c.mesa_id||'') === String(id)).length;
  const r = await licConfirmar({
    titulo: 'Eliminar a mesa «' + licEsc(nome) + '»?',
    icone: 'mesa', perigo: true, confirmar: 'Eliminar mesa',
    texto: (sentados
        ? '<b>' + sentados + '</b> convite(s) estão sentados nesta mesa e <b>ficam sem mesa</b>. '
        : 'Não há convites sentados nesta mesa. ')
         + 'Ninguém é apagado — só perdem o lugar.<br><br>'
         + 'A mesa em si não se recupera: para a ter de volta, cria-se outra.'
  });
  if (!r.sim) return;
  const d=await api('mesa_delete&id='+id); if(d.success){MESAS=d.mesas;renderMesasGestao();renderFiltroMesas();carregar();toast('Mesa eliminada.');} }

// ---------- modais base ----------
function abrir(id){ $(id).classList.add('aberto'); }
function fechar(id){ $(id).classList.remove('aberto'); }
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{ if(e.target===o)o.classList.remove('aberto'); }));

async function abrirMensagens(){
  const d=await api('convite_list'); // sem filtros: todas as mensagens
  const com=(d.convites||[]).filter(c=>c.rsvp_mensagem && c.rsvp_mensagem.trim());
  const el=$('lista-mensagens');
  if(!com.length){
    el.innerHTML='<p style="color:var(--ink-fraco);text-align:center;padding:1.4rem">Ainda não há mensagens deixadas pelos convidados.</p>';
  } else {
    el.innerHTML = `<p class="msg-conta">${com.length} mensagem${com.length>1?'s':''}</p>` + com.map(c=>`
      <div class="msg-item">
        <div class="msg-topo"><strong>${esc(c.nome_final)}</strong> ${tagEstado(c.rsvp_estado)}</div>
        <p class="msg-txt">“${esc(c.rsvp_mensagem)}”</p>
      </div>`).join('');
  }
  abrir('ov-mensagens');
}

function fmtHora(sql){
  if(!sql) return '';
  const d=new Date((''+sql).replace(' ','T'));
  return isNaN(d)?'':d.toLocaleString('pt-PT',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
}
async function abrirEntradas(){
  const el=$('lista-entradas-dash'), topo=$('entradas-topo-dash');
  el.innerHTML='<p style="color:var(--ink-fraco);text-align:center;padding:1rem">A carregar…</p>'; topo.innerHTML='';
  const d=await api('porta_entradas');
  const es=(d && d.entradas)||[];
  topo.innerHTML=`<b>${(d&&d.presentes)||0}</b> pessoas no local · ${es.length} convite(s) com entrada`;
  if(!es.length){
    el.innerHTML='<p style="color:var(--ink-fraco);text-align:center;padding:1.2rem">Ainda ninguém deu entrada.</p>';
  } else {
    el.innerHTML=es.map(c=>{
      const presentes=(c.membros||[]).filter(m=>+m.presente).map(m=>esc(m.nome));
      const hora=fmtHora(c.checkin_em);
      return `<div class="entrada-d">
        <div class="ent-d-topo"><strong>${esc(c.nome_final)}</strong>${hora?`<span class="ent-d-hora">${hora}</span>`:''}</div>
        <div class="ent-d-meta">${c.checkin_presentes} de ${c.lugares} · ${c.mesa_nome?('Mesa '+esc(c.mesa_nome)+' · '):''}Cód. ${c.codigo}</div>
        ${presentes.length?`<div class="ent-d-pessoas">${presentes.join(', ')}</div>`:''}
      </div>`;
    }).join('');
  }
  abrir('ov-entradas');
}

// ---------- histórico: reciclagem + registo de atividade ----------
// ---------- quem ainda não respondeu (docs/auditoria-ui-ux.md, RSVP-002) ----------
// Esta casa não envia correio: os convites vão pela mão do casal, pelo
// WhatsApp, e o lembrete vai pelo mesmo caminho. O que faltava não era um
// motor de envio — era saber a QUEM falta e a quem já se tocou. Sem essa
// marca, ao fim de duas voltas metade da lista recebe o mesmo recado duas
// vezes e a outra metade não recebe nenhum.
let LEMBRETES = [], PRAZO = '';

async function abrirLembretes(){
  abrir('ov-lembretes');
  $('lb-lista').innerHTML = EST.esqueleto(4, 56);
  const d = await api('rsvp_lembretes');
  if (!d || !d.success) return;
  LEMBRETES = d.convites || []; PRAZO = d.prazo || '';
  $('lb-data').value = PRAZO;
  pintarPrazo();
  pintarLembretes();
}

function pintarPrazo(){
  const el = $('lb-prazo-txt');
  if (!PRAZO){ el.textContent = 'sem prazo'; el.classList.remove('lb-tarde'); return; }
  const dias = Math.ceil((new Date(PRAZO + 'T23:59:59') - Date.now()) / 86400000);
  el.textContent = dias > 1  ? 'faltam ' + dias + ' dias'
                 : dias === 1 ? 'é amanhã'
                 : dias === 0 ? 'é hoje'
                 : 'passou há ' + (-dias) + (dias === -1 ? ' dia' : ' dias');
  el.classList.toggle('lb-tarde', dias < 0);
}

async function guardarPrazo(){
  PRAZO = $('lb-data').value || '';
  const d = await api('defs_save', { method:'POST',
    body: JSON.stringify({ defs: { 'rsvp.prazo': PRAZO } }) });
  if (!d || !d.success) return;
  pintarPrazo();
  toast(PRAZO ? 'Prazo guardado. Já aparece nos convites.' : 'Prazo retirado.');
}

function pintarLembretes(){
  const el = $('lb-lista');
  if (!LEMBRETES.length){
    el.innerHTML = EST.vazio('visto', 'Está tudo respondido',
      'Não há convites à espera de resposta. É raro, e é bom.');
    return;
  }
  const semTel = LEMBRETES.filter(c => !telefoneWa(c.telefone)).length;
  const porTocar = LEMBRETES.filter(c => !c.rsvp_lembrete_em);
  el.innerHTML =
    `<div class="lb-topo">
       <span><b>${LEMBRETES.length}</b> convite(s) por responder${
         semTel ? ` · <span class="lb-sem">${semTel} sem telefone</span>` : ''}</span>
       ${porTocar.length ? `<button class="btn btn-fantasma btn-sm" data-escrita="1"
          onclick="marcarLembretes(${JSON.stringify(porTocar.map(c=>c.id)).replace(/"/g,'&quot;')})"
          >Marcar os ${porTocar.length} como avisados</button>` : ''}
     </div>` +
    LEMBRETES.map(c => {
      const tel = telefoneWa(c.telefone);
      const jaFoi = !!c.rsvp_lembrete_em;
      const parcial = c.rsvp_estado === 'parcial';
      return `<div class="lb-linha${jaFoi ? ' ja' : ''}">
        <div class="lb-quem">
          <b>${esc(c.nome_exibicao)}</b>
          <span class="lb-sub">${parcial
            ? `respondeu por ${c.rsvp_confirmados} de ${c.lugares}`
            : `${c.lugares} lugar(es) · ${c.enviado ? 'convite enviado' : 'convite por enviar'}`}</span>
        </div>
        <div class="lb-acoes">
          ${jaFoi ? `<span class="lb-marca" title="${esc(c.rsvp_lembrete_em)}">avisado</span>` : ''}
          ${tel ? `<button class="btn btn-fantasma btn-sm" data-escrita="1"
                     onclick="lembrar(${c.id})">Lembrar</button>`
                : `<span class="lb-sem">sem telefone</span>`}
        </div>
      </div>`;
    }).join('');
}

// O recado leva o prazo quando há um: «até 12 de Maio» é um pedido, «quando
// puder» é um adiamento.
function mensagemLembrete(c){
  const l = linkConvite(c.codigo);
  const nome = (c.nome_exibicao || '').split(/\s*[eE&]\s*/)[0].trim() || 'Olá';
  const ate = PRAZO ? ` até ${dataCurta(PRAZO)}` : '';
  return `Olá ${nome}! Um lembrete com carinho: ainda estamos à espera da sua confirmação `
       + `para o casamento de ${CASAL}, no dia ${DATA_EXT}.\n\n`
       + `É por aqui, e leva um minuto${ate}:\n${l}\n\nObrigado!`;
}

function dataCurta(iso){
  const m = ['janeiro','fevereiro','março','abril','maio','junho',
             'julho','agosto','setembro','outubro','novembro','dezembro'];
  const d = new Date(iso + 'T12:00:00');
  return d.getDate() + ' de ' + m[d.getMonth()];
}

async function lembrar(id){
  const c = LEMBRETES.find(x => x.id == id); if (!c) return;
  const tel = telefoneWa(c.telefone);
  if (!tel) return toast('Este convite não tem telefone.', true);
  window.open('https://wa.me/' + tel + '?text=' + encodeURIComponent(mensagemLembrete(c)),
              '_blank', 'noopener');
  await marcarLembretes([id], true);
}

async function marcarLembretes(ids, calado){
  const d = await api('rsvp_lembrete_marcar', { method:'POST',
    body: JSON.stringify({ ids: ids }), silencioso: !!calado });
  if (!d || !d.success) return;
  const agora = new Date().toISOString().slice(0, 19).replace('T', ' ');
  LEMBRETES.forEach(c => { if (ids.includes(c.id)) c.rsvp_lembrete_em = agora; });
  pintarLembretes();
  if (!calado) toast(d.n + ' convite(s) marcados como avisados.');
}

// ---------- as perguntas da confirmação (docs/auditoria-ui-ux.md, RSVP-001) ----------
// A resposta a um convite trazia «vem / não vem», quantos, e um recado. O
// prato, as alergias e a boleia ficavam para telefonemas um a um: numa festa
// de duzentas pessoas isso são duzentos telefonemas e nenhum número que se
// possa dar ao catering.
let PERGUNTAS = [];

async function abrirPerguntas(){
  abrir('ov-perguntas');
  $('pg-lista').innerHTML = EST.esqueleto(2, 120);
  $('pg-resumo').innerHTML = '';
  const d = await api('rsvp_perguntas');
  PERGUNTAS = (d && d.perguntas) || [];
  pintarPerguntas();
  pintarResumoRsvp();
}

function novaPergunta(){
  PERGUNTAS.push({ chave:'', rotulo:'', ajuda:'', tipo:'escolha',
                   opcoes:[], obrigatoria:0, por_pessoa:1, ativa:1, nova:true });
  pintarPerguntas();
}

function pintarPerguntas(){
  const el = $('pg-lista');
  if (!PERGUNTAS.length){
    el.innerHTML = EST.vazio('conversa', 'Ainda não pergunta nada',
      'A confirmação pergunta só se a pessoa vem. Acrescente o que precisa de saber '
      + 'antes do dia — o prato, as alergias, quem precisa de boleia.');
    return;
  }
  el.innerHTML = PERGUNTAS.map((p, i) => {
    // A chave é o nome com que a resposta fica guardada: muda-se enquanto a
    // pergunta é nova, e prende-se assim que ela existe. Mudá-la depois
    // deixava para trás as respostas já dadas, sem aviso nenhum.
    const presa = !p.nova && p.chave;
    return `<div class="pg-cartao" data-i="${i}">
      <div class="pg-cab">
        <input type="text" class="pg-rotulo" placeholder="A pergunta, como o convidado a lê"
               value="${esc(p.rotulo||'')}" oninput="mudarPergunta(${i},'rotulo',this.value)">
        <button class="btn-ico" title="Retirar esta pergunta" data-escrita="1"
                onclick="tirarPergunta(${i})"><span data-ico="lixo"></span></button>
      </div>
      <input type="text" class="pg-ajuda" placeholder="Uma linha de ajuda (opcional)"
             value="${esc(p.ajuda||'')}" oninput="mudarPergunta(${i},'ajuda',this.value)">
      <div class="pg-linha">
        <label>Nome guardado
          <input type="text" value="${esc(p.chave||'')}" ${presa ? 'disabled' : ''}
                 placeholder="prato" oninput="mudarPergunta(${i},'chave',this.value)">
        </label>
        <label>Tipo de resposta
          <select onchange="mudarPergunta(${i},'tipo',this.value)">
            <option value="escolha"${p.tipo==='escolha'?' selected':''}>Uma de várias</option>
            <option value="texto"${p.tipo==='texto'?' selected':''}>Texto livre</option>
            <option value="sim_nao"${p.tipo==='sim_nao'?' selected':''}>Sim ou não</option>
          </select>
        </label>
        <label>A quem
          <select onchange="mudarPergunta(${i},'por_pessoa',+this.value)">
            <option value="1"${p.por_pessoa?' selected':''}>A cada pessoa</option>
            <option value="0"${!p.por_pessoa?' selected':''}>Ao convite todo</option>
          </select>
        </label>
      </div>
      ${p.tipo==='escolha' ? `<label class="pg-ops">As respostas possíveis, uma por linha
        <textarea rows="3" placeholder="Carne&#10;Peixe&#10;Vegetariano"
          oninput="mudarPergunta(${i},'opcoes',this.value.split('\\n'))">${esc((p.opcoes||[]).join('\n'))}</textarea></label>` : ''}
      <label class="pg-obrig"><input type="checkbox" ${p.obrigatoria?'checked':''}
        onchange="mudarPergunta(${i},'obrigatoria',this.checked?1:0)"> Sem isto não se confirma</label>
    </div>`;
  }).join('');
}

function mudarPergunta(i, campo, valor){
  if (!PERGUNTAS[i]) return;
  // A chave é o nome de uma coluna, não uma frase: minúsculas, letras, números
  // e traço baixo. Limpa-se aqui para ninguém guardar «Prato principal!» e
  // depois não perceber porque é que a pergunta não aparece.
  if (campo === 'chave') valor = String(valor).toLowerCase().replace(/[^a-z0-9_]/g, '');
  PERGUNTAS[i][campo] = valor;
  // O tipo muda a forma do cartão; o resto escreve-se sem o redesenhar, senão
  // o cursor saltava para o fim a cada letra.
  if (campo === 'tipo') pintarPerguntas();
}

function tirarPergunta(i){
  const p = PERGUNTAS[i]; if (!p) return;
  PERGUNTAS.splice(i, 1);
  pintarPerguntas();
  toast('Pergunta retirada. Guarde para valer.');
}

async function guardarPerguntas(){
  // A chave falta-lhe muitas vezes: escreve-se o rótulo e esquece-se o resto.
  // Em vez de recusar, deriva-se do rótulo — e só se pede quando não dá.
  PERGUNTAS.forEach(p => {
    if (!p.chave) p.chave = (p.rotulo||'').toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
      .replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'').slice(0, 40);
  });
  const semNome = PERGUNTAS.find(p => !p.chave || !p.rotulo);
  if (semNome){ toast('Falta escrever a pergunta.', true); return; }
  const semOps = PERGUNTAS.find(p => p.tipo === 'escolha'
    && !(p.opcoes||[]).filter(o => String(o).trim()).length);
  if (semOps){ toast('«' + semOps.rotulo + '» é uma escolha e não tem respostas possíveis.', true); return; }

  const d = await api('rsvp_perguntas_guardar', { method:'POST',
    body: JSON.stringify({ perguntas: PERGUNTAS }) });
  if (!d || !d.success) return;
  PERGUNTAS = d.perguntas || [];
  pintarPerguntas();
  pintarResumoRsvp();
  toast('Perguntas guardadas. Já aparecem nos convites.');
}

/**
 * O resumo agregado: «43 carne · 12 peixe · 5 vegetariano».
 *
 * É este número que se dá ao catering, e por isso diz também quantos FALTAM:
 * uma conta de metade da lista entregue como se fosse toda é pior do que conta
 * nenhuma. As respostas de texto livre não se somam — contam-se e lêem-se uma
 * a uma, que é o que uma alergia pede.
 */
async function pintarResumoRsvp(){
  const cx = $('pg-resumo');
  const d = await api('rsvp_resumo', { silencioso:true });
  if (!d || !d.success || !(d.perguntas||[]).length){ cx.innerHTML = ''; return; }
  const comResposta = d.perguntas.filter(p => p.respondeu > 0);
  if (!comResposta.length){
    cx.innerHTML = '<div class="pg-resumo-vazio">Ainda ninguém respondeu. '
      + 'As contas aparecem aqui à medida que as confirmações chegam.</div>';
    return;
  }
  cx.innerHTML = '<div class="pg-resumo">' + comResposta.map(p => {
    const linhas = p.tipo === 'texto'
      ? p.linhas.map(l => `<span class="pg-txt">${esc(l.valor)}${l.n>1?' ×'+l.n:''}</span>`).join('')
      : p.linhas.map(l => `<span class="pg-conta"><b>${l.n}</b> ${esc(l.valor)}</span>`).join('');
    const falta = p.faltam > 0
      ? `<span class="pg-falta">faltam ${p.faltam} de ${d.confirmadas}</span>` : '';
    return `<div class="pg-r-bloco"><div class="pg-r-tit">${esc(p.rotulo)}${falta}</div>
            <div class="pg-r-linhas">${linhas}</div></div>`;
  }).join('') + '</div>';
}

function abrirHistorico(){ abrir('ov-historico'); abaHistorico('lixo'); }

async function abaHistorico(qual){
  const lixo = qual==='lixo';
  $('aba-lixo').classList.toggle('ativa', lixo);
  $('aba-registo').classList.toggle('ativa', !lixo);
  $('hist-lixo').hidden = !lixo;
  $('hist-registo').hidden = lixo;
  lixo ? carregarLixo() : carregarRegisto();
}

async function carregarLixo(){
  const el=$('hist-lixo');
  el.innerHTML='<p class="vazio-hist">A carregar…</p>';
  const d=await api('reciclagem');
  const cs=(d && d.convites)||[];
  const dias=(d && d.dias)||30;
  if(!cs.length){ el.innerHTML=`<p class="vazio-hist">A reciclagem está vazia.<br><small>Os convites eliminados ficam aqui ${dias} dias antes de desaparecerem.</small></p>`; return; }
  el.innerHTML=`<p class="msg-conta">${cs.length} convite(s) na reciclagem · apagam-se sozinhos ao fim de ${dias} dias</p>`
    + cs.map(c=>`<div class="lixo-item">
      <div class="cresce">
        <strong>${esc(c.nome_exibicao)}</strong>
        <small>${c.lugares} lugar(es) · Cód. ${esc(c.codigo)} · eliminado ${fmtHora(c.eliminado_em)}</small>
      </div>
      <button class="btn btn-fantasma" onclick="repor(${c.id})">Repor</button>
      <button class="btn-ico" title="Apagar definitivamente" onclick="apagarDeVez(${c.id}, ${JSON.stringify(c.nome_exibicao)})">&times;</button>
    </div>`).join('');
}

async function apagarDeVez(id, nome){
  const r = await licConfirmar({
    titulo: 'Apagar «' + licEsc(nome) + '» de vez?',
    icone: 'lixo', perigo: true, confirmar: 'Apagar de vez',
    texto: 'Sai da reciclagem e <b>deixa de poder ser reposto</b>. As pessoas deste convite '
         + 'vão com ele.<br><br><b>Isto não se desfaz.</b>'
  });
  if (!r.sim) return;
  const d=await api('convite_delete&definitivo=1&id='+id);
  if(d.success){ toast('Convite apagado definitivamente.'); carregarLixo(); carregar(); }
}

// O nome de cada ação por extenso vem do servidor (nomesDeAcao(), em db.php):
// são setenta e tal, e mantê-las aqui em duplicado era garantir que uma das
// duas listas ficava para trás.
let REGISTOS=[], REG_PAGINA=1, REG_MAIS=false, REG_TOTAL=0;

/** Quanto tempo faz. É a leitura que o olho quer primeiro; a data exacta fica ao lado. */
function haQuanto(sql){
  const d=new Date((''+sql).replace(' ','T'));
  if(isNaN(d)) return '';
  const seg=Math.max(0,(Date.now()-d.getTime())/1000);
  if(seg<90)   return 'agora mesmo';
  if(seg<5400) return 'há ' + Math.round(seg/60) + ' min';
  const h=Math.round(seg/3600);
  if(h<36)     return 'há ' + h + ' hora' + (h===1?'':'s');
  const dias=Math.round(seg/86400);
  if(dias<45)  return 'há ' + dias + ' dia' + (dias===1?'':'s');
  const m=Math.round(dias/30);
  return 'há ' + m + ' mês' + (m===1?'':'es');
}
/** A data por inteiro, para quando «há 3 dias» não chega. */
function dataInteira(sql){
  const d=new Date((''+sql).replace(' ','T'));
  return isNaN(d)?'':d.toLocaleString('pt-PT',
    {weekday:'long',day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'});
}

// Quem, ao certo. O nome é para ler de relance; o email é para responder à
// pergunta a sério — «quem é que apagou isto?» —, e é por isso que aparece na
// linha ABERTA e não na fechada: lá em cima estorvava a frase, aqui dentro é
// a única coisa que identifica a pessoa sem margem para dúvida.
//
// Em branco quando a linha é anterior a esta mudança e a migração não
// conseguiu atribuí-la sem adivinhar (dois nomes iguais, uma conta apagada).
// Diz-se que não se sabe, em vez de se calar: um campo que desaparece parece
// um esquecimento, e isto foi uma decisão.
function quemAoCerto(r){
  const e = (r.email||'').trim();
  return e ? ` <span class="reg-email"><a href="mailto:${esc(e)}">${esc(e)}</a></span>`
           : ` <span class="reg-email reg-email-vazio">(email não registado)</span>`;
}

async function carregarRegisto(mais=false){
  const el=$('hist-registo');
  REG_PAGINA = mais ? REG_PAGINA+1 : 1;
  if(!mais) el.innerHTML='<p class="vazio-hist">A carregar…</p>';
  const d=await api('registo_lista&pagina='+REG_PAGINA);
  if(!d.success){ if(mais) REG_PAGINA--; return; }
  REGISTOS = mais ? REGISTOS.concat(d.registos||[]) : (d.registos||[]);
  REG_MAIS = !!d.ha_mais; REG_TOTAL = +d.total||REGISTOS.length;
  const rs=REGISTOS;
  if(!rs.length){ el.innerHTML='<p class="vazio-hist">Ainda não há atividade registada.</p>'; return; }
  el.innerHTML=`<p class="msg-conta">${REG_TOTAL} ação(ões) registadas · a ver as ${rs.length} mais recentes</p>`
    + rs.map(r=>{
    // Fechada, a linha é uma FRASE — «a Ana criou um convite Maria Silva» — e
    // não quatro campos a que o leitor tem de dar sentido. Aberta, diz tudo o
    // que se sabe dela, que é onde se responde a «com que papel, e de onde».
    //
    // O detalhe é escrito para quem lá chegar a perguntar alguma coisa, e às
    // vezes traz o número interno da linha («id 42»). Isso não diz nada a
    // ninguém aqui: fica guardado para o painel aberto, onde faz sentido.
    const proprio = r.alvo && r.alvo === window.CASAMENTO_NOME;
    const alvo=(r.alvo && !proprio)?` <span class="reg-alvo">${esc(r.alvo)}</span>`:'';
    const soId=/^id \d+$/.test((r.detalhe||'').trim());
    const det=(r.detalhe && !soId)?` <span class="reg-det">· ${esc(r.detalhe)}</span>`:'';
    const campo=(rot,val)=> val ? `<dt>${rot}</dt><dd>${val}</dd>` : '';
    return `<details class="reg-linha">
      <summary>
        <span class="reg-quando" title="${esc(dataInteira(r.criado_em))}">${fmtHora(r.criado_em)}</span>
        <span class="reg-que"><span class="reg-quem">${esc(r.utilizador||'—')}</span>
          ${esc(r.frase||r.accao)}${alvo}${det}</span>
        <span class="reg-fam ${esc(r.familia||'outra')}">${esc(r.familia||'outra')}</span>
      </summary>
      <div class="reg-detalhe"><dl>
        ${campo('Quem', `<b>${esc(r.utilizador||'—')}</b>`
                        + (r.papel?` <span style="color:var(--ink-fraco)">(${esc(r.papel)})</span>`:'')
                        + quemAoCerto(r))}
        ${campo('O que fez', esc(r.frase||r.accao) + ` <code>${esc(r.accao)}</code>`)}
        ${campo('Sobre', esc(r.alvo))}
        ${campo('Ao certo', esc(r.detalhe))}
        ${campo('Quando', esc(dataInteira(r.criado_em)) + ` <span style="color:var(--ink-fraco)">· ${esc(haQuanto(r.criado_em))}</span>`)}
        ${campo('De onde', r.ip ? `<code>${esc(r.ip)}</code>` : '')}
      </dl></div>
    </details>`;
  }).join('')
    + (REG_MAIS ? `<button class="btn-mais-lista" onclick="carregarRegisto(true)">
        Mostrar mais <span class="conta-extra">${REG_TOTAL-rs.length}</span></button>` : '');
}

montarPickers();
carregar();
carregarTiraModulos();
</script>
</main>
</body>
</html>
