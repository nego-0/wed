/* ============================================================
   bar-copa.js — O posto do copeiro (copa.php)

   Uma leitura só (bar_estado) traz a fila, o stock, os motivos e as contas; o
   ecrã refresca-se sozinho de oito em oito segundos, porque quem está na copa
   não pode estar a carregar em «recarregar» com as mãos molhadas.

   A decisão é de duas portas: aprovar (e a bebida fica prometida) ou recusar
   (e diz-se porquê). Nunca há uma terceira que deixe o pedido no limbo.
   ============================================================ */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var PODE = !window.SO_VER_UI;
  var EST = null;
  var filtro = 'analise';
  var desvio = 0;            // relógio do servidor menos o do browser, em ms
  var relogio = null, tique = null;

  function esc(s) {
    return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  }
  function toast(m, mau) {
    var t = $('toast'); if (!t) return;
    t.textContent = m; t.className = 'toast mostrar' + (mau ? ' erro' : '');
    setTimeout(function () { t.className = 'toast'; }, 2600);
  }
  window.toast = toast;

  function foto(i) {
    if (i.foto) return '<div class="b-foto"><img src="' + esc(i.foto) + '" alt="" loading="lazy"></div>';
    return '<div class="b-foto"><span class="letra">'
      + esc((i.nome || '?').trim().charAt(0).toUpperCase()) + '</span></div>';
  }

  /** Há quanto tempo, em português curto. É a conta que ordena a noite. */
  function ha(quando) {
    if (!quando) return '';
    var t = Date.parse(String(quando).replace(' ', 'T'));
    if (isNaN(t)) return '';
    var s = Math.max(0, Math.round((Date.now() + desvio - t) / 1000));
    if (s < 60) return 'agora mesmo';
    var m = Math.round(s / 60);
    if (m < 60) return 'há ' + m + ' min';
    return 'há ' + Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0');
  }
  function minutos(quando) {
    var t = Date.parse(String(quando || '').replace(' ', 'T'));
    return isNaN(t) ? 0 : (Date.now() + desvio - t) / 60000;
  }

  // ---- carregar ----------------------------------------------
  async function carregar(silencioso) {
    var d = await window.api('bar_estado', { method: 'GET', silencioso: !!silencioso });
    var sinal = $('b-sinal');
    if (!d || !d.success) {
      sinal.className = 'b-sinal';
      sinal.textContent = 'sem ligação';
      return;
    }
    if (d.agora) {
      // O relógio do tablet da copa pode estar errado — e um «há 40 min» falso
      // faz aprovar à pressa o que não é urgente. Vale o do servidor.
      var t = Date.parse(d.agora);
      if (!isNaN(t)) desvio = t - Date.now();
    }
    EST = d;
    sinal.className = 'b-sinal on';
    sinal.textContent = 'ligado';
    pintarBarra();
    pintarFila();
    pintarStock();
    pintarBandeiras();
  }

  /** Telemóveis que valem uma segunda vista. Não acusam ninguém — a copa
      conhece a sala e decide; isto limita-se a apontar (§5.3). */
  function pintarBandeiras() {
    var cx = $('b-bandeiras');
    if (!cx) return;
    var bs = EST.bandeiras || [];
    cx.hidden = !bs.length;
    if (!bs.length) return;
    cx.innerHTML = '<div class="b-tit">A olhar duas vezes <small>não é acusação: '
      + 'é o que dá nas vistas</small></div>'
      + bs.map(function (x) {
          return '<div class="b-cartao"><b>' + esc(x.nome) + '</b><br>'
            + '<span class="onde">' + esc(x.texto) + '</span></div>';
        }).join('');
  }

  function pintarBarra() {
    var e = EST.estado || {};
    $('k-analise').textContent  = e.em_analise || 0;
    $('k-aprovados').textContent = e.aprovados || 0;
    $('k-caminho').textContent  = e.a_caminho || 0;
    $('k-bebidas').textContent  = e.bebidas_entregues || 0;
    var bt = $('b-chave-bt');
    bt.textContent = e.aberto ? 'Fechar o bar' : 'Abrir o bar';
    bt.className = 'btn ' + (e.aberto ? 'btn-fantasma' : 'btn-ouro');
    bt.disabled = !PODE;
    $('c-analise').textContent = e.em_analise ? '(' + e.em_analise + ')' : '';
    var espera = (e.aprovados || 0) + (e.a_caminho || 0);
    $('c-espera').textContent = espera ? '(' + espera + ')' : '';
  }

  window.copaFiltro = function (qual) {
    filtro = qual;
    ['analise', 'espera', 'fim'].forEach(function (k) {
      $('fa-' + k).classList.toggle('on', k === qual);
      $('fa-' + k).setAttribute('aria-selected', k === qual ? 'true' : 'false');
    });
    pintarFila();
  };

  function pedidosDoFiltro() {
    var fila = EST.fila || [];
    if (filtro === 'analise') {
      return fila.filter(function (p) { return p.estado === 'em_analise'; });
    }
    if (filtro === 'espera') {
      return fila.filter(function (p) { return p.estado !== 'em_analise'; });
    }
    return EST.resolvidos || [];
  }

  function pintarFila() {
    var cx = $('b-fila');
    var ps = pedidosDoFiltro();
    if (!ps.length) {
      cx.innerHTML = '<div class="b-cartao b-vazio"><span class="ico">'
        + (filtro === 'analise' ? '☕' : '🍸') + '</span>'
        + (filtro === 'analise' ? 'Nada por decidir. A copa está em dia.'
           : filtro === 'espera' ? 'Nada por entregar.'
           : 'Ainda não há nada resolvido esta noite.') + '</div>';
      return;
    }
    cx.innerHTML = ps.map(cartao).join('');
  }

  function cartao(p) {
    // A cor da moldura conta o tempo: um pedido de dez minutos por decidir já
    // é um convidado a olhar para o telemóvel.
    var idade = p.estado === 'em_analise' ? minutos(p.criado_em) : 0;
    var classe = idade > 10 ? ' muito-velho' : (idade > 5 ? ' velho' : '');
    var linhas = (p.itens || []).map(function (l) {
      return '<span class="b-linha">' + foto(l) + '<b>' + l.quantidade + '×</b> '
        + esc(l.nome) + '</span>';
    }).join('');

    // Onde entregar, e — se for diferente — de onde o pedido veio. Uma pessoa
    // que pediu na mesa 3 para a mesa 7 mudou de lugar, e quem entrega
    // precisa de saber as duas coisas.
    var onde = p.mesa ? 'Mesa ' + esc(p.mesa) : 'Sem mesa indicada';
    if (p.mesa_qr && p.mesa && p.mesa_qr !== p.mesa) onde += ' · pediu na ' + esc(p.mesa_qr);
    if (p.criado_por) onde += ' · lançado por ' + esc(p.criado_por);

    // Um pedido que deixou de caber numa regra posta depois de ele entrar.
    // Não se recusa sozinho: assinala-se, e a copa decide.
    var fora = (EST.fora || []).filter(function (x) { return x.id === p.id; })[0];

    return '<div class="b-cartao b-ped' + classe + (fora ? ' fora' : '') + '">'
      + '<div class="b-ped-topo">'
      +   '<span class="cod">' + esc(p.codigo) + '</span>'
      +   (p.convidado_id
            ? '<button type="button" class="quem quem-bt" onclick="copaFicha('
              + p.convidado_id + ')" title="Ficha e regras desta pessoa">'
              + esc(p.convidado || 'Sem nome') + '</button>'
            : '<span class="quem">Sem nome</span>')
      +   '<span class="b-est ' + esc(p.estado) + '">' + esc(p.estado_nome) + '</span>'
      +   '<span class="ha">' + esc(ha(p.criado_em)) + '</span>'
      + '</div>'
      + '<div class="onde">' + onde + '</div>'
      + '<div class="b-linhas">' + linhas + '</div>'
      + (p.motivo ? '<div class="onde">Motivo: ' + esc(p.motivo) + '</div>' : '')
      + (fora ? '<div class="b-bandeira">⚠ ' + esc(fora.porque) + '</div>' : '')
      + (PODE ? acoes(p) : '')
      + '</div>';
  }

  function acoes(p) {
    if (p.estado === 'em_analise') {
      return '<div class="b-acoes">'
        + '<button class="btn btn-ouro" onclick="copaAprovar(' + p.id + ')">Aprovar</button>'
        + '<button class="btn btn-fantasma" onclick="copaRecusar(' + p.id + ')">Recusar</button>'
        + '</div>';
    }
    if (p.estado === 'aprovado' || p.estado === 'a_caminho' || p.estado === 'falhou') {
      return '<div class="b-acoes">'
        + '<button class="btn btn-fantasma" onclick="copaCancelar(' + p.id + ')">Cancelar</button>'
        + '</div>';
    }
    return '';
  }

  // ---- decidir ------------------------------------------------
  window.copaAprovar = async function (id) {
    var d = await window.api('bar_decidir', { method: 'POST',
      body: JSON.stringify({ id: id, decisao: 'aprovar' }) });
    if (!d || !d.success) return;
    toast('Aprovado. Fica prometido até sair.');
    await carregar(true);
  };

  window.copaRecusar = function (id) {
    // Recusar sem dizer porquê é o que faz um convidado pedir outra vez. Os
    // motivos da lista são um toque; escrever é para o caso raro.
    var motivos = (EST.motivos || []).map(function (m) {
      return { v: String(m.id), r: m.texto };
    });
    motivos.push({ v: '0', r: 'Outro — escrevo eu' });
    licFormulario({
      titulo: 'Recusar o pedido',
      guardar: 'Recusar',
      perigo: true,
      dica: 'O convidado vê o motivo no telemóvel. Diga-o como o diria em pessoa.',
      campos: [
        { id: 'motivo_id', rot: 'Motivo', tipo: 'escolha',
          valor: motivos.length > 1 ? motivos[0].v : '0', opcoes: motivos },
        { id: 'motivo_texto', rot: 'Ou escreva um', tipo: 'area', linhas: 2, valor: '' }
      ],
      aoGuardar: async function (v) {
        var mid = parseInt(v.motivo_id, 10) || 0;
        if (!mid && !v.motivo_texto) {
          licJanelaErro('Escolha um motivo da lista ou escreva um.');
          return false;
        }
        var d = await window.api('bar_decidir', { method: 'POST',
          body: JSON.stringify({ id: id, decisao: 'recusar',
                                 motivo_id: mid, motivo_texto: v.motivo_texto }) });
        if (!d || !d.success) return false;
        toast('Recusado, com o motivo.');
        await carregar(true);
        return true;
      }
    });
  };

  window.copaCancelar = async function (id) {
    var r = await licConfirmar({ titulo: 'Cancelar o pedido', icone: '🍹', perigo: true,
      texto: 'O que estava prometido volta ao stock disponível. Use isto quando o '
           + 'pedido já não faz sentido — a pessoa foi-se embora, ou desistiu em voz alta.',
      confirmar: 'Cancelar o pedido', cancelar: 'Deixar estar' });
    if (!r.sim) return;
    var d = await window.api('bar_cancelar_copa', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('Cancelado.');
    await carregar(true);
  };

  // ---- o interruptor ------------------------------------------
  window.copaChave = async function () {
    var aberto = !!(EST && EST.estado && EST.estado.aberto);
    if (aberto) {
      var r = await licConfirmar({ titulo: 'Fechar o bar', icone: '🍹',
        texto: 'Ninguém consegue pedir enquanto estiver fechado. Os pedidos que já '
             + 'estão na fila mantêm-se — fechar não é apagar.',
        confirmar: 'Fechar', cancelar: 'Continuar aberto' });
      if (!r.sim) return;
    }
    var d = await window.api(aberto ? 'bar_fechar' : 'bar_abrir', { method: 'POST', body: '{}' });
    if (!d || !d.success) return;
    toast(aberto ? 'Bar fechado.' : 'Bar aberto. Já podem pedir.');
    await carregar(true);
  };

  // ---- o stock -------------------------------------------------
  function pintarStock() {
    var itens = (EST.itens || []).filter(function (i) { return i.estado === 'ativo'; });
    var cx = $('b-stock');
    if (!itens.length) {
      cx.innerHTML = '<div class="b-vazio">O menu está vazio.</div>';
      $('b-stock-nota').textContent = '';
      return;
    }
    var acabar = itens.filter(function (i) { return i.disponivel <= 5; }).length;
    $('b-stock-nota').textContent = acabar ? acabar + ' a acabar' : 'tudo com folga';
    cx.innerHTML = itens.map(function (i) {
      // Três cores e nada mais: com folga, a acabar, acabou. Um número
      // sozinho não diz se 8 é muito ou pouco.
      var luz = i.disponivel <= 0 ? 'mau' : (i.disponivel <= 5 ? 'meio' : 'bom');
      return '<div class="b-item"' + (PODE ? ' role="button" tabindex="0"'
        + ' onclick="copaAcerto(' + i.id + ')"'
        + ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();copaAcerto('
        + i.id + ')}" title="Acertar o stock de ' + esc(i.nome) + '"' : '') + '>'
        + foto(i)
        + '<div><div class="nm"><span class="b-semaforo ' + luz + '"></span>' + esc(i.nome) + '</div>'
        +   '<div class="sub">' + i.stock + ' na copa'
        +   (i.reservado ? ' · ' + i.reservado + ' prometidas' : '') + '</div></div>'
        + '<div class="qt">' + i.disponivel + '</div>'
        + '</div>';
    }).join('');
  }

  window.copaAcerto = function (id) {
    var i = (EST.itens || []).filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    licFormulario({
      titulo: esc(i.nome),
      guardar: 'Guardar',
      dica: 'Há <b>' + i.stock + '</b> lançadas, <b>' + i.reservado + '</b> prometidas. '
          + 'Some o que chegou, ou acerte para o que realmente há na copa.',
      campos: [
        { id: 'entrou', rot: 'Chegaram agora', tipo: 'numero', valor: 0, min: 0,
          dica: 'Somam-se ao que já lá estava.' },
        { id: 'stock', rot: 'Ou: contei e há', tipo: 'numero', valor: i.stock, min: 0,
          dica: 'Substitui o número. Precisa de nota.' },
        { id: 'nota', rot: 'Nota', tipo: 'text', valor: '', largura: 2,
          dica: 'Fica no livro do stock: «caiu uma caixa», «vieram 12 e não 24».' }
      ],
      aoGuardar: async function (v) {
        var entrou = parseInt(v.entrou, 10) || 0;
        var novo   = parseInt(v.stock, 10);
        var d;
        if (entrou) {
          d = await window.api('bar_stock_repor', { method: 'POST',
            body: JSON.stringify({ item_id: id, quantidade: entrou, nota: v.nota }) });
        } else if (!isNaN(novo) && novo !== i.stock) {
          if (!v.nota) { licJanelaErro('Um acerto explica-se: escreva a nota.'); return false; }
          d = await window.api('bar_stock_acerto', { method: 'POST',
            body: JSON.stringify({ item_id: id, stock: novo, nota: v.nota }) });
        } else {
          licJanelaErro('Diga quantas chegaram, ou quantas contou.');
          return false;
        }
        if (!d || !d.success) return false;
        toast('Stock actualizado.');
        await carregar(true);
        return true;
      }
    });
  };

  // ---- os motivos de recusa ------------------------------------
  window.copaMotivos = function () {
    var lista = (EST.motivos || []).map(function (m) {
      return '<div style="display:flex;align-items:center;gap:.5rem;padding:.35rem 0;'
        + 'border-bottom:1px solid var(--line)"><span style="flex:1">' + esc(m.texto) + '</span>'
        + '<button type="button" class="j-bt j-bt-nao" style="min-height:36px;padding:.2rem .7rem"'
        + ' onclick="copaMotivoApagar(' + m.id + ')">Tirar</button></div>';
    }).join('') || '<p class="dica">Ainda não há motivos guardados.</p>';
    licFormulario({
      titulo: 'Motivos de recusa',
      guardar: 'Acrescentar',
      dica: 'Os motivos da lista poupam a escrita a meio da noite. '
          + 'Escreva-os como os diria a quem pediu.',
      campos: [{ id: 'texto', rot: 'Motivo novo', tipo: 'text', valor: '',
                 dica: 'Ex.: «Acabou o espumante — temos vinho branco fresco.»' }],
      extra: '<div style="margin-top:1rem">' + lista + '</div>',
      aoGuardar: async function (v) {
        if (!v.texto) { licJanelaErro('Escreva o motivo.'); return false; }
        var d = await window.api('bar_motivo_guardar', { method: 'POST',
          body: JSON.stringify({ texto: v.texto }) });
        if (!d || !d.success) return false;
        await carregar(true);
        copaMotivos();
        return false;   // a janela fica, para se acrescentar outro
      }
    });
  };

  window.copaMotivoApagar = async function (id) {
    var d = await window.api('bar_motivo_apagar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    await carregar(true);
    licFecharJanela();
    copaMotivos();
  };

  // ---- a ficha de um convidado, com as regras dele ---------------
  // É uma coisa que se faz a correr, no meio da festa, com a pessoa à frente:
  // «este senhor já vai no quinto whisky», «esta senhora está grávida».
  window.copaFicha = async function (id) {
    var d = await window.api('bar_ficha&convidado=' + id, { method: 'GET' });
    if (!d || !d.success) return;
    // «2× whisky (1 por servir)» — para quem está a decidir, a diferença entre
    // o que já bebeu e o que ainda está na fila é a informação toda.
    var levou = d.levou.length
      ? d.levou.map(function (l) {
          return l.n + '× ' + esc(l.nome)
            + (l.por_servir ? ' <small>(' + l.por_servir + ' por servir)</small>' : '');
        }).join(' · ')
      : 'ainda não pediu nada';
    var regras = d.regras.length
      ? d.regras.map(function (r) {
          return '<div class="b-regra"><span>' + esc(r.frase) + '</span>'
            + (r.nota ? '<small>' + esc(r.nota) + '</small>' : '')
            + '<button type="button" class="j-bt j-bt-nao" onclick="copaRegraFora('
            + r.id + ',' + id + ')">✕</button></div>';
        }).join('')
      : '<p class="dica">Sem regras próprias — valem-lhe as da casa.</p>';

    licJanela('Ficha de ' + esc(d.convidado.nome),
      '<div class="dica">' + esc(d.convidado.convite) + '</div>'
      + '<p style="margin:.6rem 0"><b>Já levou:</b> ' + levou + '</p>'
      + '<div class="b-tit" style="font-size:.95rem;margin:.9rem 0 .4rem">Regras desta pessoa</div>'
      + regras
      + '<div style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">'
      +   '<button type="button" class="j-bt j-bt-sim" onclick="copaRegraNova(' + id + ')">'
      +     '+ Regra</button>'
      + '</div>'
      + '<div class="b-tit" style="font-size:.95rem;margin:1rem 0 .4rem">Telemóveis</div>'
      + (d.dispositivos.length
          ? d.dispositivos.map(function (t) {
              return '<div class="b-regra"><span>um telemóvel'
                + (t.trocas ? ' <small>(já pediu por ' + (t.trocas + 1) + ' pessoas)</small>' : '')
                + '</span><button type="button" class="j-bt j-bt-nao" onclick="copaSoltar('
                + t.id + ',' + id + ')">Soltar</button></div>';
            }).join('')
          : '<p class="dica">Nenhum — ainda ninguém se escolheu neste nome.</p>')
      + '<p class="dica">Soltar serve quando a vida dá um nó: um telemóvel '
      +   'emprestado, um nome escolhido por engano. O próximo a abrir a página '
      +   'volta a escolher-se.</p>', null, { cancelar: 'Fechar' });
  };

  /** Uma regra escreve-se como quem fala, com listas em vez de campos. */
  window.copaRegraNova = function (convidadoId) {
    var itens = (EST.itens || []).map(function (i) { return { v: 'i' + i.id, r: i.nome }; });
    var cats  = (EST.categorias || []).map(function (c) { return { v: 'c' + c.id, r: c.nome }; });
    var sobre = [{ v: 'tudo', r: 'qualquer bebida' }].concat(cats, itens);
    licFormulario({
      titulo: 'Regra para esta pessoa',
      guardar: 'Pôr a regra',
      largo: true,
      dica: 'Três números dizem tudo: <b>0</b> proíbe; <b>N</b> sem intervalo é um '
          + 'tecto para a noite; <b>N</b> com intervalo é «N de cada vez».',
      campos: [
        { id: 'sobre', rot: 'Pode pedir', tipo: 'escolha', valor: 'tudo', opcoes: sobre },
        { id: 'quantidade', rot: 'No máximo', tipo: 'numero', valor: 1, min: 0, max: 99,
          dica: '0 = não pode pedir isto.' },
        { id: 'janela_min', rot: 'A cada (minutos)', tipo: 'numero', valor: 0, min: 0, max: 1440,
          dica: '0 = é um tecto para a noite inteira.' },
        { id: 'mensagem', rot: 'O que ele lê', tipo: 'text', valor: '', largura: 2,
          dica: 'Vazio, lê o texto de sempre. Nunca lê a nota.' },
        { id: 'nota', rot: 'Porquê (só nós vemos)', tipo: 'text', valor: '', largura: 2,
          dica: 'Ex.: «pediu-nos para o travarmos», «conduz».' }
      ],
      aoGuardar: async function (v) {
        var escopo = 'tudo', alvo = 0;
        if (v.sobre.charAt(0) === 'i') { escopo = 'item';      alvo = parseInt(v.sobre.slice(1), 10); }
        else if (v.sobre.charAt(0) === 'c') { escopo = 'categoria'; alvo = parseInt(v.sobre.slice(1), 10); }
        var d = await window.api('bar_regra_guardar', { method: 'POST', body: JSON.stringify({
          escopo: escopo, alvo_id: alvo, sujeito: 'convidado', alvo_convidado_id: convidadoId,
          unidade: 'bebidas', quantidade: parseInt(v.quantidade, 10) || 0,
          janela_min: parseInt(v.janela_min, 10) || 0,
          mensagem: v.mensagem, nota: v.nota }) });
        if (!d || !d.success) return false;
        toast('Regra posta. Vale já.');
        await carregar(true);
        avisarFora(d.fora);
        licFecharJanela();
        copaFicha(convidadoId);
        return false;
      }
    });
  };

  window.copaSoltar = async function (id, convidadoId) {
    var d = await window.api('bar_soltar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    toast('Telemóvel solto.');
    licFecharJanela();
    copaFicha(convidadoId);
  };

  window.copaRegraFora = async function (id, convidadoId) {
    var d = await window.api('bar_regra_apagar', { method: 'POST', body: JSON.stringify({ id: id }) });
    if (!d || !d.success) return;
    await carregar(true);
    licFecharJanela();
    if (convidadoId) copaFicha(convidadoId);
  };

  /** Uma regra vale de imediato, mas não recusa nada sozinha: quem a pôs pode
      muito bem querer servir o copo que já estava pedido (§8.0.1). */
  function avisarFora(fora) {
    if (!fora || !fora.length) return;
    toast(fora.length === 1
      ? 'O pedido ' + fora[0].codigo + ' já não cabe nas regras — está assinalado na fila.'
      : fora.length + ' pedidos na fila já não cabem nas regras.', true);
  }

  // ---- as regras da casa ---------------------------------------
  window.copaRegras = function () {
    var f = (EST && EST.defs) || {};
    licFormulario({
      titulo: 'Regras da casa',
      guardar: 'Guardar',
      largo: true,
      dica: 'Como o bar se porta com quem pede. Muda a meio da noite se for preciso.',
      campos: [
        { id: 'bar.mensagem_fechado', rot: 'O que dizer quando está fechado', tipo: 'area',
          linhas: 2, valor: f['bar.mensagem_fechado'] || '', largura: 2,
          dica: 'Ex.: «O bar abre depois do brinde, por volta das 21h.»' },
        { id: 'bar.procura_min', rot: 'Letras para procurar o nome', tipo: 'numero',
          valor: f['bar.procura_min'] || '4', min: 1, max: 8,
          dica: 'Menos letras, mais nomes de cada vez na lista.' },
        { id: 'bar.ip_modo', rot: 'Pedidos da mesma rede', tipo: 'escolha',
          valor: f['bar.ip_modo'] || 'registo',
          opcoes: [{ v: 'registo', r: 'Registar, sem incomodar' },
                   { v: 'aviso',   r: 'Registar e avisar a copa' },
                   { v: 'estrito', r: 'Recusar o segundo nome' }],
          dica: 'Numa festa quase todos partilham o mesmo wi-fi: «estrito» é '
              + 'para salas onde cada mesa tem a sua rede.' },
        { id: 'bar.garcon_direto', rot: 'Empregado lança pedidos', tipo: 'sim',
          valor: f['bar.garcon_direto'] === '1', aoLado: 'Sim, para quem não tem rede' },
        { id: 'bar.trocar_nome', rot: 'Trocar de nome no mesmo telemóvel', tipo: 'sim',
          valor: f['bar.trocar_nome'] === '1', aoLado: 'Deixar, avisando a copa' }
      ],
      aoGuardar: async function (v) {
        var env = {};
        Object.keys(v).forEach(function (k) {
          env[k] = (k === 'bar.garcon_direto' || k === 'bar.trocar_nome')
            ? (v[k] ? '1' : '0') : String(v[k]);
        });
        var d = await window.api('bar_defs', { method: 'POST', body: JSON.stringify(env) });
        if (!d || !d.success) return false;
        toast('Regras guardadas.');
        await carregar(true);
        return true;
      }
    });
  };

  // ---- pedir por um convidado ----------------------------------
  // Sem rede, o convidado pede em voz alta e a copa lança por ele. É o mesmo
  // pedido, com o nome de quem o lançou colado — para não haver dúvidas de
  // quem serviu o quê.
  window.copaPedirPor = function () {
    var itens = (EST.itens || []).filter(function (i) {
      return i.estado === 'ativo' && i.disponivel > 0;
    });
    if (!itens.length) { toast('Não há nada disponível para pedir.', true); return; }
    licFormulario({
      titulo: 'Pedir por um convidado',
      guardar: 'Lançar o pedido',
      largo: true,
      dica: 'Escreva parte do nome, escolha a pessoa, e depois a bebida.',
      campos: [
        { id: 'nome', rot: 'Nome do convidado', tipo: 'text', valor: '', largura: 2,
          dica: '<span id="pp-achados"></span>' },
        { id: 'item', rot: 'Bebida', tipo: 'escolha', valor: String(itens[0].id),
          opcoes: itens.map(function (i) {
            return { v: String(i.id), r: i.nome + ' (' + i.disponivel + ')' };
          }) },
        { id: 'quantidade', rot: 'Quantas', tipo: 'numero', valor: 1, min: 1, max: 12 }
      ],
      aoGuardar: async function (v) {
        if (!ppEscolhido) { licJanelaErro('Escolha o convidado na lista.'); return false; }
        var d = await window.api('bar_pedir_por', { method: 'POST',
          body: JSON.stringify({ convidado_id: ppEscolhido.id,
                                 mesa_id: ppEscolhido.mesa_id,
                                 itens: [{ item_id: parseInt(v.item, 10),
                                           quantidade: parseInt(v.quantidade, 10) || 1 }] }) });
        if (!d || !d.success) return false;
        toast('Pedido ' + d.pedido.codigo + ' lançado.');
        await carregar(true);
        return true;
      }
    });
    ligarProcuraPessoal();
  };

  var ppEscolhido = null, ppEspera = null;
  function ligarProcuraPessoal() {
    ppEscolhido = null;
    var cx = document.getElementById('lf-nome');
    if (!cx) return;
    cx.setAttribute('autocomplete', 'off');
    cx.addEventListener('input', function () {
      ppEscolhido = null;
      clearTimeout(ppEspera);
      ppEspera = setTimeout(ppProcurar, 220);
    });
  }
  async function ppProcurar() {
    var cx = document.getElementById('lf-nome');
    var saida = document.getElementById('pp-achados');
    if (!cx || !saida) return;
    var termo = cx.value.trim();
    if (termo.length < 2) { saida.textContent = 'Escreva pelo menos duas letras.'; return; }
    var d = await window.api('bar_procurar_pessoal&q=' + encodeURIComponent(termo),
                             { method: 'GET', silencioso: true });
    if (!d || !d.success) { saida.textContent = 'Não deu para procurar.'; return; }
    if (!d.nomes.length) { saida.textContent = 'Ninguém com esse nome.'; return; }
    saida.innerHTML = d.nomes.slice(0, 6).map(function (n) {
      return '<button type="button" class="j-bt j-bt-nao" style="min-height:34px;'
        + 'padding:.15rem .6rem;margin:.15rem .25rem 0 0" onclick="copaEscolher(' + n.id + ')">'
        + esc(n.nome) + (n.mesa ? ' · ' + esc(n.mesa) : '') + '</button>';
    }).join('');
    window.__ppNomes = d.nomes;
  }
  window.copaEscolher = function (id) {
    var n = (window.__ppNomes || []).filter(function (x) { return x.id === id; })[0];
    if (!n) return;
    ppEscolhido = n;
    var cx = document.getElementById('lf-nome');
    if (cx) cx.value = n.nome;
    var saida = document.getElementById('pp-achados');
    if (saida) saida.innerHTML = '<b>' + esc(n.nome) + '</b>'
      + (n.mesa ? ' — entrega na ' + esc(n.mesa) : ' — sem mesa marcada');
  };

  // ---- o relógio ------------------------------------------------
  carregar();
  relogio = setInterval(function () { if (!document.hidden) carregar(true); }, 8000);
  // Os «há N min» envelhecem sozinhos entre leituras: sem isto, um pedido
  // ficava «há 2 min» durante oito segundos de cada vez.
  tique = setInterval(function () { if (!document.hidden && EST) pintarFila(); }, 20000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) carregar(true);
  });
})();
