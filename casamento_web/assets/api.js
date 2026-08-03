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
})(window);
