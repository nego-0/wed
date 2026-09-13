/* ============================================================
   icones.js — O alfabeto de sinais da casa

   Uma casa que desenha convites não pode servir bebidas com emojis. Um emoji
   não é um ícone: muda de desenho conforme o telemóvel, não obedece à cor do
   tema, não tem espessura de traço, e num ecrã escuro às onze da noite aparece
   com o seu próprio fundo colorido a gritar. É a assinatura mais rápida de um
   projecto amador.

   A convenção é a que o painel já usava (index.php, const IC), agora num
   ficheiro que todas as páginas partilham:

     • caixa 24×24, sem preenchimento, traço 1.6 e pontas redondas;
     • a cor é sempre `currentColor` — o ícone veste-se do texto ao lado, e
       portanto atravessa os quatro temas e o escuro do salão sem uma linha
       de CSS a mais;
     • geometria sóbria: nada de detalhe que desapareça a 16px, que é onde
       metade destes vive.

   Os copos são desenhados de propósito e não tirados de uma biblioteca: uma
   flute, uma taça de vinho, uma caneca, um copo baixo e um copo alto com
   palhinha dizem a gaveta a que a bebida pertence antes de se ler o nome. É o
   que substitui aquela inicial gigante em cima de um quadrado de cor.
   ============================================================ */
(function (raiz) {
  'use strict';

  // O invólucro: só o miolo de cada ícone é que muda.
  function svg(miolo, opc) {
    opc = opc || {};
    return '<svg class="ico ' + (opc.classe || '') + '" viewBox="0 0 24 24" '
      + 'fill="none" stroke="currentColor" stroke-width="' + (opc.traco || 1.6) + '" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
      + 'focusable="false">' + miolo + '</svg>';
  }

  // ---- os copos, uma por gaveta -------------------------------
  var D = {
    // Flute: bojo estreito e alto. Espumantes.
    flute: '<path d="M9.6 3h4.8l-.55 8.1a1.9 1.9 0 0 1-3.7 0z"/>'
         + '<path d="M12 13.2V20"/><path d="M9.3 20.6h5.4"/>',
    // Taça de vinho: bojo largo, pé curto.
    taca: '<path d="M7.6 3.2h8.8l-.5 5.1a4.4 4.4 0 0 1-7.8 0z"/>'
        + '<path d="M12 12.9V20"/><path d="M8.8 20.6h6.4"/>',
    // Caneca: a espuma sai POR CIMA do bordo, senão lê-se como uma tampa.
    // Cervejas.
    caneca: '<path d="M5.2 9.6h9v9.2a2.2 2.2 0 0 1-2.2 2.2H7.4a2.2 2.2 0 0 1-2.2-2.2z"/>'
          + '<path d="M14.2 11.4h2.3a2.7 2.7 0 0 1 0 5.4h-2.3"/>'
          + '<path d="M5.2 9.6a2 2 0 0 1 1.6-2.4 2.2 2.2 0 0 1 3.6-1.5 2.2 2.2 0 0 1 3.8 1.5 2 2 0 0 1 .8 2.4"/>',
    // Copo baixo: afunila mesmo, senão lê-se como uma caixa. O traço de dentro
    // é o nível do líquido. Destilados.
    baixo: '<path d="M6.4 7.2h11.2l-1.5 11.9a2.2 2.2 0 0 1-2.2 1.9h-3.8a2.2 2.2 0 0 1-2.2-1.9z"/>'
         + '<path d="M7.7 13.2h8.6"/>',
    // Copo alto, com a palhinha bem fora do bordo. Sem álcool.
    alto: '<path d="M7.8 5.4h8.4l-1.3 13.8a2 2 0 0 1-2 1.8h-1.8a2 2 0 0 1-2-1.8z"/>'
        + '<path d="M17.6 2.4 13.8 12.6"/><path d="M11 15.4h.01"/>',
    // Chávena com pires e fumo. Café.
    chavena: '<path d="M4.4 8.4h10.4v5.4a4 4 0 0 1-4 4H8.4a4 4 0 0 1-4-4z"/>'
           + '<path d="M14.8 9.9h1.9a2.4 2.4 0 0 1 0 4.8h-1.9"/>'
           + '<path d="M3.4 21h13"/>'
           + '<path d="M8 3.4c-.7.9-.7 1.7 0 2.6M11.4 3.4c-.7.9-.7 1.7 0 2.6"/>'
  };

  /**
   * O copo de uma gaveta, adivinhado pelo nome dela.
   *
   * A casa não obriga ninguém a escolher um ícone ao criar uma gaveta — seria
   * mais um passo num sítio onde se quer escrever «Cervejas» e seguir. Lê-se o
   * nome e escolhe-se o copo; o que não se reconhecer fica com a taça, que é o
   * copo genérico de uma festa.
   */
  var PISTAS = [
    [/espumante|champ|prosecco|cava|brinde/i, 'flute'],
    [/cerveja|beer|imperial|caneca|fino/i,    'caneca'],
    [/destil|whisky|gin|vodka|rum|licor|shot|cocktai|caipir|aguardente/i, 'baixo'],
    [/caf[ée]|ch[áa]\b|infus/i,               'chavena'],
    [/sem [áa]lcool|refriger|sumo|[áa]gua|soft|natural/i, 'alto'],
    [/vinho|tinto|branco|ros[ée]|sangria|moscatel|porto/i, 'taca']
  ];
  /**
   * Aceita um ou dois nomes, e o PRIMEIRO que reconhecer manda.
   *
   * Chama-se com (bebida, gaveta): um café está na gaveta «Sem álcool», e com
   * a gaveta a mandar saía-lhe um copo alto com palhinha. O nome da bebida é
   * mais específico do que o da gaveta, e por isso vai à frente; a gaveta é a
   * rede que apanha o resto.
   */
  function copoDe(nome, tambem) {
    var lista = [nome, tambem];
    for (var n = 0; n < lista.length; n++) {
      var s = String(lista[n] || '');
      if (!s) continue;
      for (var i = 0; i < PISTAS.length; i++) if (PISTAS[i][0].test(s)) return PISTAS[i][1];
    }
    return 'taca';
  }

  // ---- o resto: interface --------------------------------------
  var I = {
    procurar: '<circle cx="10.8" cy="10.8" r="6.6"/><path d="m20 20-4.6-4.6"/>',
    // Deslizadores, e não um funil: é o sinal que a casa usa para «afinar o que
    // se vê», e lê-se melhor a 16px do que um funil, que a essa medida fica um
    // triângulo indistinto.
    filtro: '<path d="M4 7h5M13 7h7M4 12h11M19 12h1M4 17h3M11 17h9"/>'
          + '<circle cx="11" cy="7" r="2"/><circle cx="17" cy="12" r="2"/><circle cx="9" cy="17" r="2"/>',
    mais: '<path d="M12 5v14M5 12h14"/>',
    menos: '<path d="M5 12h14"/>',
    visto: '<path d="M20 6.5 9.2 17.3 4 12.1"/>',
    xis: '<path d="M18 6 6 18M6 6l12 12"/>',
    relogio: '<circle cx="12" cy="12" r="8.6"/><path d="M12 6.8V12l3.4 2"/>',
    // Meia-lua a encher: o pedido que está a ser pensado.
    analise: '<circle cx="12" cy="12" r="8.6"/><path d="M12 3.4a8.6 8.6 0 0 1 0 17.2z" fill="currentColor" stroke="none"/>',
    seta: '<path d="M4.5 12h14"/><path d="m13 6.5 5.5 5.5-5.5 5.5"/>',
    volta: '<path d="M9 5 4 10l5 5"/><path d="M4 10h10a6 6 0 0 1 0 12h-3"/>',
    traco: '<path d="M6 12h12"/>',
    aviso: '<path d="M12 4.2 2.8 20h18.4z"/><path d="M12 10v4"/><path d="M12 17.2h.01"/>',
    lapis: '<path d="M16.5 3.9a2.1 2.1 0 0 1 3 3L8.4 18l-4 1 1-4z"/><path d="M14.5 5.9l3 3"/>',
    maquina: '<path d="M3 8.5h3.6l1.6-2.4h7.6l1.6 2.4H21v10a1.6 1.6 0 0 1-1.6 1.6H4.6A1.6 1.6 0 0 1 3 18.5z"/>'
           + '<circle cx="12" cy="13.4" r="3.4"/>',
    caixa: '<path d="M3.4 7.6 12 3.4l8.6 4.2v8.8L12 20.6l-8.6-4.2z"/>'
         + '<path d="M3.4 7.6 12 11.8l8.6-4.2"/><path d="M12 11.8v8.8"/>',
    lixo: '<path d="M4.4 6.6h15.2"/><path d="M9.4 6.6V4.8h5.2v1.8"/>'
        + '<path d="M6.2 6.6 7 19.4a1.6 1.6 0 0 0 1.6 1.5h6.8a1.6 1.6 0 0 0 1.6-1.5l.8-12.8"/>'
        + '<path d="M10.4 10.4v6.4M13.6 10.4v6.4"/>',
    qr: '<rect x="3.4" y="3.4" width="7" height="7" rx="1.2"/>'
      + '<rect x="13.6" y="3.4" width="7" height="7" rx="1.2"/>'
      + '<rect x="3.4" y="13.6" width="7" height="7" rx="1.2"/>'
      + '<path d="M13.6 13.6h3v3h-3zM20.6 13.6v3M17.6 20.6h3M13.6 20.6h.01"/>',
    pessoas: '<circle cx="9" cy="8" r="3.6"/><path d="M2.6 20.4a6.4 6.4 0 0 1 12.8 0"/>'
           + '<path d="M16.4 4.8a3.6 3.6 0 0 1 0 6.9"/><path d="M18 14.6a6.4 6.4 0 0 1 3.4 5.8"/>',
    pessoa: '<circle cx="12" cy="8" r="3.8"/><path d="M4.8 20.4a7.2 7.2 0 0 1 14.4 0"/>',
    mesa: '<ellipse cx="12" cy="8.6" rx="8.2" ry="3.4"/><path d="M6.6 11.2 5.4 20M17.4 11.2 18.6 20"/>',
    baixoSeta: '<path d="m6.5 9.5 5.5 5.5 5.5-5.5"/>',
    olho: '<path d="M2.4 12S6 5.6 12 5.6 21.6 12 21.6 12 18 18.4 12 18.4 2.4 12 2.4 12z"/>'
        + '<circle cx="12" cy="12" r="3"/>',
    olhoFechado: '<path d="M4 4.6 20 19.4"/>'
               + '<path d="M9.6 6.1A9.7 9.7 0 0 1 12 5.6c6 0 9.6 6.4 9.6 6.4a17 17 0 0 1-3 3.7"/>'
               + '<path d="M6.4 8.2A17.6 17.6 0 0 0 2.4 12S6 18.4 12 18.4c1 0 1.9-.2 2.7-.5"/>'
               + '<path d="M10 10.1a3 3 0 0 0 4 4.2"/>',
    raio: '<path d="M13.4 2.8 5.6 13.4h5.4l-.4 7.8 7.8-10.6h-5.4z"/>',
    grafico: '<path d="M4 20V9.6M10 20V4.8M16 20v-7M22 20H2"/>',
    porta: '<path d="M14.6 3.4H6.4a1.4 1.4 0 0 0-1.4 1.4v14.4a1.4 1.4 0 0 0 1.4 1.4h8.2"/>'
         + '<path d="m17 8.6 3.6 3.4-3.6 3.4"/><path d="M20.2 12H10.6"/>',
    tabuleiro: '<path d="M3.4 9.4h17.2l-1.5 9.2a2 2 0 0 1-2 1.7H6.9a2 2 0 0 1-2-1.7z"/>'
             + '<path d="M8.4 9.4V6.6a3.6 3.6 0 0 1 7.2 0v2.8"/>',
    sino: '<path d="M18 9a6 6 0 1 0-12 0c0 5-2 6.4-2 6.4h16S18 14 18 9z"/>'
        + '<path d="M13.7 19.4a2 2 0 0 1-3.4 0"/>',
    nota: '<path d="M6 3.4h8.6L19 7.8V19a1.6 1.6 0 0 1-1.6 1.6H6A1.6 1.6 0 0 1 4.4 19V5A1.6 1.6 0 0 1 6 3.4z"/>'
        + '<path d="M14 3.6v4.6h4.6"/><path d="M8.2 13h7M8.2 16.6h4.6"/>',
    trancado: '<rect x="4.6" y="10.4" width="14.8" height="10.2" rx="2"/>'
            + '<path d="M8.4 10.4V7.6a3.6 3.6 0 0 1 7.2 0v2.8"/>',
    aberto: '<rect x="4.6" y="10.4" width="14.8" height="10.2" rx="2"/>'
          + '<path d="M8.4 10.4V7.6a3.6 3.6 0 0 1 6.9-1.2"/>',
    gota: '<path d="M12 3.2s6 6.6 6 10.6a6 6 0 0 1-12 0c0-4 6-10.6 6-10.6z"/>',
    palete: '<path d="M12 3.4a8.6 8.6 0 0 0 0 17.2c1.2 0 1.8-.8 1.8-1.7 0-1.6 1-2 2.2-2h1.4a3.2 3.2 0 0 0 3.2-3.3C20.6 7.9 16.8 3.4 12 3.4z"/>'
           + '<circle cx="8" cy="9.4" r="1.1" fill="currentColor" stroke="none"/>'
           + '<circle cx="12" cy="7.4" r="1.1" fill="currentColor" stroke="none"/>'
           + '<circle cx="7" cy="14" r="1.1" fill="currentColor" stroke="none"/>',
    mala: '<rect x="3" y="7.6" width="18" height="12.4" rx="2"/>'
        + '<path d="M8.6 7.6V5.8a1.8 1.8 0 0 1 1.8-1.8h3.2a1.8 1.8 0 0 1 1.8 1.8v1.8"/>'
        + '<path d="M3 12.6h18"/>',
    mao: '<path d="M8.4 11.4V5.6a1.7 1.7 0 0 1 3.4 0v5.2"/>'
       + '<path d="M11.8 10.6V4.8a1.7 1.7 0 0 1 3.4 0v6"/>'
       + '<path d="M15.2 11V7.4a1.7 1.7 0 0 1 3.4 0v7.2a6 6 0 0 1-6 6h-1.4a5 5 0 0 1-3.6-1.6l-3-3.4a1.7 1.7 0 0 1 2.5-2.3l2.3 2"/>',
    mudar: '<path d="M4 8h13l-3-3"/><path d="M20 16H7l3 3"/>',

    /* ---- os que o resto da casa escrevia com emojis ------------
       Cada um destes substitui um carácter que mudava de desenho conforme o
       aparelho: 🗑 📄 📥 🔑 🎁 💬 🖼 ⛪ ✉ ☎ 🔒 ⏸ ⏳ ♂ ♀ ⭐ 💰 📱 📦 ⛔ 🎨 🕊.
       Mesma caixa de 24, mesmo traço, mesma cor do texto ao lado. */
    documento: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>'
             + '<path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/>',
    descarregar: '<path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/>'
               + '<path d="M5 20h14"/>',
    enviar: '<path d="M12 20V9"/><path d="m7.5 13.5 4.5-4.5 4.5 4.5"/><path d="M5 4h14"/>',
    chave: '<circle cx="8" cy="12" r="4"/><path d="M12 12h8"/><path d="M17 12v3.5"/>'
         + '<path d="M20 12v2.5"/>',
    presente: '<path d="M4 11h16v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"/>'
            + '<path d="M3 7.5h18V11H3z"/><path d="M12 7.5V21"/>'
            + '<path d="M12 7.5c-3.5 0-4.5-1-4.5-2.2S8.5 3 9.7 3.4C11 3.9 12 5.6 12 7.5z"/>'
            + '<path d="M12 7.5c3.5 0 4.5-1 4.5-2.2S15.5 3 14.3 3.4C13 3.9 12 5.6 12 7.5z"/>',
    conversa: '<path d="M20 14.5a2.5 2.5 0 0 1-2.5 2.5H9l-4 3.5V6.5A2.5 2.5 0 0 1 7.5 4h10A2.5 2.5 0 0 1 20 6.5z"/>'
            + '<path d="M9 9h7"/><path d="M9 12.5h4.5"/>',
    imagem: '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/>'
          + '<circle cx="9" cy="10" r="1.6"/><path d="m4.5 17 4.2-4.2 3 3 3.4-3.4 4.4 4.4"/>',
    igreja: '<path d="M12 2.5v5"/><path d="M10 4.5h4"/>'
          + '<path d="M12 7.5 6.5 12v9h11v-9z"/><path d="M10.5 21v-4.5h3V21"/>',
    carta: '<rect x="3" y="5.5" width="18" height="13" rx="2"/>'
         + '<path d="m3.8 7 7.1 5.3a2 2 0 0 0 2.2 0L20.2 7"/>',
    telefone: '<path d="M6.5 3.5h3l1.5 4-2 1.5a12 12 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2.2 2A17 17 0 0 1 4.5 5.7a2 2 0 0 1 2-2.2z"/>',
    cadeado: '<rect x="4.5" y="10.5" width="15" height="10" rx="2.2"/>'
           + '<path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/><path d="M12 14.5v2.5"/>',
    pausa: '<rect x="8" y="5" width="3" height="14" rx="1"/>'
         + '<rect x="13" y="5" width="3" height="14" rx="1"/>',
    ampulheta: '<path d="M7 3h10"/><path d="M7 21h10"/>'
             + '<path d="M7.5 3v3.2c0 2 4.5 3.6 4.5 5.8s-4.5 3.8-4.5 5.8V21"/>'
             + '<path d="M16.5 3v3.2c0 2-4.5 3.6-4.5 5.8s4.5 3.8 4.5 5.8V21"/>',
    homem: '<circle cx="10" cy="14" r="5.5"/><path d="m14.5 9.5 5-5"/><path d="M15 4.5h4.5V9"/>',
    mulher: '<circle cx="12" cy="9" r="5.5"/><path d="M12 14.5V21"/><path d="M9 18h6"/>',
    estrela: '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1.1 5.9-5.3-2.9-5.3 2.9 1.1-5.9L3.5 9.7l5.9-.8z"/>',
    moeda: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v10"/>'
         + '<path d="M14.5 9.3c-.6-.8-1.5-1.1-2.6-1.1-1.5 0-2.6.8-2.6 1.9 0 2.6 5.4 1.3 5.4 3.9 0 1.2-1.2 2-2.8 2-1.2 0-2.2-.4-2.8-1.2"/>',
    telemovel: '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
    caixaFechada: '<path d="M3.5 7.5 12 3.5l8.5 4v9L12 20.5l-8.5-4z"/>'
                + '<path d="M3.5 7.5 12 11.5l8.5-4"/><path d="M12 11.5v9"/>',
    proibido: '<circle cx="12" cy="12" r="8.5"/><path d="m6 6 12 12"/>',
    paleta: '<path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.2 0 1.8-.8 1.8-1.6 0-.9-.7-1.4-.7-2.2 0-.8.7-1.4 1.6-1.4h1.6A4.2 4.2 0 0 0 20.5 11c0-4.2-3.8-7.5-8.5-7.5z"/>'
          + '<circle cx="8" cy="10" r="1.1"/><circle cx="12" cy="7.5" r="1.1"/><circle cx="15.8" cy="10" r="1.1"/>',
    pomba: '<path d="M4 13.5c3.5 0 5.5-1.5 7-4 1.3-2.2 3-3.5 5.5-3.5 1.9 0 3.5 1.3 3.5 3 0 3.5-2.5 8.5-8 8.5-3.5 0-6-1.5-8-4z"/>'
         + '<path d="M17.5 8.2h.01"/><path d="M12 17.5 10.5 21"/>',
    bilhete: '<path d="M3.5 8.5A2 2 0 0 1 5.5 6.5h13a2 2 0 0 1 2 2v1.8a2 2 0 0 0 0 3.4v1.8a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2v-1.8a2 2 0 0 0 0-3.4z"/>'
           + '<path d="M13 6.5v11"/>',
    folha: '<path d="M20 4c0 8-4.5 12.5-11 12.5H5.5"/>'
         + '<path d="M4.5 20c1-6 5-9.5 10.5-11"/>',
    etiqueta: '<path d="M11.5 3.5H20v8.5l-8.7 8.7a1.6 1.6 0 0 1-2.3 0l-6.2-6.2a1.6 1.6 0 0 1 0-2.3z"/>'
            + '<circle cx="16.3" cy="7.7" r="1.4"/>',
    coracao: '<path d="M12 20.5s-7.5-4.6-7.5-9.6A4.4 4.4 0 0 1 12 7.6a4.4 4.4 0 0 1 7.5 3.3c0 5-7.5 9.6-7.5 9.6z"/>',
    festa: '<path d="m4 20.5 5.2-11.7 6.5 6.5z"/><path d="M9.2 8.8 15.7 15.3"/>'
         + '<path d="M14 3.5v2"/><path d="M18.5 5.5 17 7"/><path d="M20.5 10h-2"/>',
    alvo: '<circle cx="12" cy="12" r="7.5"/><circle cx="12" cy="12" r="2.2"/>'
        + '<path d="M12 2v2.5"/><path d="M12 19.5V22"/><path d="M2 12h2.5"/><path d="M19.5 12H22"/>',
    anel: '<circle cx="8.5" cy="14" r="5"/><circle cx="15.5" cy="14" r="5"/>'
        + '<path d="M12 9V6"/>',

    /* ---- as setas e os sinais dos editores ----------------------
       Substituem ‹ › ▸ ▾ ← ↑ ↓ ↶ ↷ ↺ ↻ ✎ ⤢ ✥ ◆ ◈ ✦ ♪ ⚠ ●, que eram
       caracteres de tipografia a fazer de botão: cada tipo de letra
       desenha-os a seu gosto, alguns não os têm de todo (e o browser põe
       o quadrado), e nenhum deles alinha com o traço do resto da casa. */
    direita: '<path d="m9.5 5.5 6.5 6.5-6.5 6.5"/>',
    esquerda: '<path d="M14.5 5.5 8 12l6.5 6.5"/>',
    cima: '<path d="m6.5 14.5 5.5-5.5 5.5 5.5"/>',
    setaCima: '<path d="M12 19.5V5"/><path d="m6.5 10.5 5.5-5.5 5.5 5.5"/>',
    setaBaixo: '<path d="M12 4.5V19"/><path d="m6.5 13.5 5.5 5.5 5.5-5.5"/>',
    setaEsquerda: '<path d="M19.5 12h-14"/><path d="m11 5.5-5.5 6.5 5.5 6.5"/>',
    desfazer: '<path d="M4 9h10.5a5 5 0 0 1 0 10H9"/><path d="m8 4.5-4 4.5 4 4.5"/>',
    refazer: '<path d="M20 9H9.5a5 5 0 0 0 0 10H15"/><path d="m16 4.5 4 4.5-4 4.5"/>',
    rodar: '<path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20.5 3.5V8H16"/>',
    mover: '<path d="M12 3v18M3 12h18"/><path d="m9 6 3-3 3 3"/><path d="m9 18 3 3 3-3"/>'
         + '<path d="m6 9-3 3 3 3"/><path d="m18 9 3 3-3 3"/>',
    expandir: '<path d="M14.5 4h5.5v5.5"/><path d="M9.5 20H4v-5.5"/>'
            + '<path d="M20 4l-7 7"/><path d="M4 20l7-7"/>',
    losango: '<path d="m12 3.2 8.8 8.8-8.8 8.8L3.2 12z"/>',
    brilho: '<path d="M12 2.5c0 5.2 1.8 7 7 7-5.2 0-7 1.8-7 7 0-5.2-1.8-7-7-7 5.2 0 7-1.8 7-7z"/>',
    musica: '<path d="M9 18V5.5l10-2V16"/><circle cx="6.5" cy="18" r="2.5"/>'
          + '<circle cx="16.5" cy="16" r="2.5"/>',
    ponto: '<circle cx="12" cy="12" r="4.5"/>'
  };

  // ---- a saída ------------------------------------------------
  function ico(nome, opc) {
    var m = I[nome] || D[nome];
    return m ? svg(m, opc) : '';
  }
  /** O copo certo para uma gaveta, pelo nome dela. */
  function copo(nome, tambem, opc) {
    if (tambem && typeof tambem === 'object') { opc = tambem; tambem = null; }
    return svg(D[copoDe(nome, tambem)], opc);
  }

  /* ---- os sinais escritos em HTML ------------------------------
     As páginas em PHP não podem chamar esta função — ela vive no browser. Em
     vez de haver uma segunda cópia dos desenhos em PHP (duas cópias do mesmo
     traço são duas cópias que divergem, e este projecto já pagou isso caro),
     o HTML escreve só o NOME:

         <span data-ico="lixo"></span>

     e isto desenha-o. Corre ao abrir a página e volta a correr sobre o que
     nascer depois, que é como os painéis desta casa se escrevem. */
  function vestir(raiz) {
    var onde = (raiz && raiz.querySelectorAll) ? raiz : document;
    onde.querySelectorAll('[data-ico]').forEach(function (el) {
      if (el.dataset.icoPosto === el.dataset.ico) return;
      var m = ico(el.dataset.ico, { classe: el.dataset.icoClasse || '' });
      if (!m) return;
      el.innerHTML = m;
      el.dataset.icoPosto = el.dataset.ico;
    });
  }

  if (typeof document !== 'undefined') {
    var marcado = false;
    var emBreve = function () {
      if (marcado) return;
      marcado = true;
      requestAnimationFrame(function () { marcado = false; vestir(document); });
    };
    var arrancar = function () {
      vestir(document);
      new MutationObserver(function (mut) {
        for (var i = 0; i < mut.length; i++) {
          var ns = mut[i].addedNodes;
          for (var j = 0; j < ns.length; j++) {
            var n = ns[j];
            if (n.nodeType !== 1) continue;
            if (n.hasAttribute('data-ico') || (n.querySelector && n.querySelector('[data-ico]'))) {
              emBreve(); return;
            }
          }
        }
      }).observe(document.documentElement, { childList: true, subtree: true });
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', arrancar);
    } else arrancar();
  }

  raiz.ICO = { ico: ico, copo: copo, copoDe: copoDe, svg: svg, vestir: vestir,
               glifos: I, copos: D };
  // Atalho, que é como se lê melhor no meio de uma cadeia de HTML.
  raiz.ico = ico;
})(typeof window !== 'undefined' ? window : this);
