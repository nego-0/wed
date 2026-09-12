/* ============================================================
   api.js — Chamadas à API com tratamento de erro
   Antes, um `return r.json()` sem rede de segurança fazia com que
   uma sessão expirada (que responde com o HTML do login) ou um
   soluço de rede rebentassem em silêncio: nada acontecia e o
   utilizador não via mensagem nenhuma. Isto trata os dois casos.

   Usa: window.CSRF (token) e, se existir, window.toast(msg, erro).
   ============================================================ */
(function (global) {
  'use strict';

  // As páginas expõem estes em window (ver `window.CSRF` no topo de cada uma).
  function token()    { return typeof global.CSRF === 'string' ? global.CSRF : ''; }
  function marcaHora(){ return typeof global.agora === 'function' ? global.agora() : null; }

  function avisar(msg, silencioso) {
    if (silencioso) return;
    if (typeof global.toast === 'function') global.toast(msg, true);
    else if (typeof global.alert === 'function') global.alert(msg);
  }

  /**
   * Chama a API e devolve sempre um objeto — nunca lança.
   * Em caso de falha devolve { success:false, message, _erro:'<tipo>' }.
   * opts.silencioso = true não mostra aviso (para sondagens de fundo).
   */
  async function api(accao, opts) {
    opts = opts || {};
    // Carimbo de hora local do cliente (o servidor usa-o nos registos)
    if (opts.body && typeof opts.body === 'string') {
      try {
        const b = JSON.parse(opts.body);
        const ts = marcaHora();
        if (b && typeof b === 'object' && !Array.isArray(b) && ts) {
          b.ts = ts;
          opts.body = JSON.stringify(b);
        }
      } catch (e) { /* corpo não-JSON: segue como está */ }
    }
    opts.headers = Object.assign({ 'X-CSRF-Token': token() }, opts.headers || {});

    let r;
    try {
      r = await fetch('api.php?action=' + accao, opts);
    } catch (e) {
      avisar('Sem ligação. Verifique a internet e tente outra vez.', opts.silencioso);
      return { success: false, message: 'Sem ligação ao servidor.', _erro: 'rede' };
    }

    // Sessão expirada: o servidor redireciona para o login (HTML, não JSON).
    const tipo = (r.headers.get('content-type') || '').toLowerCase();
    if (r.redirected && /login\.php/.test(r.url) || (!tipo.includes('json') && r.ok)) {
      avisar('A sua sessão expirou. Vai voltar à página de entrada.', opts.silencioso);
      if (!opts.silencioso) setTimeout(function () { global.location.href = 'login.php'; }, 1800);
      return { success: false, message: 'Sessão expirada.', _erro: 'sessao' };
    }

    let d;
    try {
      d = await r.json();
    } catch (e) {
      avisar(r.status === 419 ? 'Sessão inválida. Recarregue a página.'
                              : 'O servidor respondeu de forma inesperada (' + r.status + ').',
             opts.silencioso);
      return { success: false, message: 'Resposta inválida do servidor.', _erro: 'formato' };
    }

    // Erro devolvido pela própria API: mostra a mensagem que ela deu.
    if (d && d.success === false && !opts.semAviso) {
      avisar(d.message || 'Não foi possível concluir a operação.', opts.silencioso);
    }
    return d;
  }

  global.api = api;

  /**
   * Envia um ficheiro grande em pedaços de ~1 MB (ação 'upload_chunk') e devolve
   * o token que o identifica no servidor. Serve para passar em alojamentos que
   * limitam cada envio a poucos MB — a música do convite, sobretudo.
   * onProgress(fração 0..1) é opcional. Lança em caso de falha.
   */
  async function enviarPorPedacos(file, onProgress) {
    const TAM = 1024 * 1024;                       // 1 MB: passa mesmo num limite de 2 MB
    const n = Math.max(1, Math.ceil(file.size / TAM));
    let sessao = '';
    for (let i = 0; i < n; i++) {
      const pedaco = file.slice(i * TAM, Math.min((i + 1) * TAM, file.size));
      const fd = new FormData();
      fd.append('i', i); fd.append('n', n);
      if (sessao) fd.append('token', sessao);
      fd.append('ficheiro', pedaco, 'p');
      const r = await fetch('api.php?action=upload_chunk',
        { method: 'POST', headers: { 'X-CSRF-Token': token() }, body: fd });
      let d; try { d = await r.json(); } catch (e) { d = null; }
      if (!d || !d.success) throw new Error((d && d.message) || 'Falha ao enviar o ficheiro.');
      sessao = d.token;
      if (onProgress) onProgress((i + 1) / n);
    }
    return sessao;
  }

  /**
   * Envia um ficheiro para uma ação que aceita $_FILES OU chunk_token, partindo-o
   * em pedaços quando é grande. Devolve o mesmo que api(). `campos` são os
   * campos extra do formulário (chave, categoria, …).
   */
  async function enviarFicheiroGrande(accao, campos, file, onProgress) {
    const GRANDE = 1.4 * 1024 * 1024;              // acima disto, arrisca o limite do servidor
    const fd = new FormData();
    Object.keys(campos || {}).forEach(function (k) {
      if (campos[k] !== undefined && campos[k] !== null) fd.append(k, campos[k]);
    });
    if (file.size > GRANDE) {
      let sessao;
      try { sessao = await enviarPorPedacos(file, onProgress); }
      catch (e) { avisar(e.message || 'Falha no envio.', false); return { success: false, message: e.message }; }
      fd.append('chunk_token', sessao); fd.append('nome', file.name);
    } else {
      fd.append('ficheiro', file, file.name);
    }
    return api(accao, { method: 'POST', body: fd });
  }

  global.enviarPorPedacos = enviarPorPedacos;
  global.enviarFicheiroGrande = enviarFicheiroGrande;
})(window);
