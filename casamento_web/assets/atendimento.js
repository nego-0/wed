/**
 * A caixa de atendimento das páginas públicas.
 *
 * Quem chega ao login ou à inscrição com uma dúvida não tem por onde a pôr:
 * fecha a página e vai-se embora, e nunca se fica a saber porquê. As perguntas
 * são sempre as mesmas meia dúzia — quanto custa, como funciona, se é preciso
 * pagar já —, e por isso não é preciso ninguém do outro lado a teclar: chegam
 * as respostas já escritas, ao alcance de um toque, e os contactos para quem
 * precise mesmo de falar com uma pessoa.
 *
 * O que se diz aqui é tudo do admin (Casamentos → Atendimento): o nome e a
 * cara de quem atende, a saudação, as perguntas e os contactos. Esta página
 * limita-se a mostrá-lo — e a não aparecer sequer quando estiver desligado.
 *
 * Não é uma conversa a sério, e não finge sê-lo: as perguntas são as que a casa
 * escreveu, e a caixa diz-lhe «Perguntas frequentes». Prometer um humano do
 * outro lado que não existe seria pior do que não ter caixa nenhuma.
 */
(function () {
  'use strict';

  var D = null;          // o que o servidor respondeu
  var cx = null;         // o contentor de tudo
  var painel = null;
  var botao = null;
  var fio = null;        // onde as perguntas e respostas se acumulam
  var aberto = false;
  var devolverFoco = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** A inicial de quem atende, para quando não há fotografia. */
  function inicial(nome) {
    var n = (nome || '').trim();
    return n ? n.charAt(0).toUpperCase() : '?';
  }

  function avatar(cls) {
    var a = D.atendente || {};
    if (a.foto) {
      return '<span class="at-av ' + cls + '"><img src="' + esc(a.foto) + '" alt=""></span>';
    }
    return '<span class="at-av ' + cls + ' at-av-letra">' + esc(inicial(a.nome)) + '</span>';
  }

  /** Um contacto, só se existir. O que não está preenchido não se inventa. */
  function contactos() {
    var c = D.contactos || {}, h = '';
    var tel = (c.telefone || '').trim(), wa = (c.whatsapp || '').trim();
    var em = (c.email || '').trim(), ho = (c.horario || '').trim();
    if (tel) h += '<a class="at-ct" href="tel:' + esc(tel.replace(/\s+/g, '')) + '">'
               + '<span class="at-ct-ic" aria-hidden="true">☎</span>' + esc(tel) + '</a>';
    if (wa)  h += '<a class="at-ct" target="_blank" rel="noopener" href="https://wa.me/'
               + esc(wa.replace(/[^0-9]/g, '')) + '">'
               + '<span class="at-ct-ic" aria-hidden="true">✆</span>WhatsApp ' + esc(wa) + '</a>';
    if (em)  h += '<a class="at-ct" href="mailto:' + esc(em) + '">'
               + '<span class="at-ct-ic" aria-hidden="true">✉</span>' + esc(em) + '</a>';
    if (!h && !ho) return '';
    return '<div class="at-contactos">'
         + '<div class="at-ct-tit">Falar connosco</div>'
         + h
         + (ho ? '<div class="at-horario">' + esc(ho) + '</div>' : '')
         + '</div>';
  }

  function perguntasHtml() {
    var ps = D.perguntas || [];
    if (!ps.length) return '';
    return '<div class="at-sug" id="at-sug">'
         + '<div class="at-sug-tit">Perguntas frequentes</div>'
         + ps.map(function (p) {
             return '<button type="button" class="at-q" data-id="' + p.id + '">'
                  + esc(p.pergunta) + '</button>';
           }).join('')
         + '</div>';
  }

  function montar() {
    var a = D.atendente || {};
    cx = document.createElement('div');
    cx.className = 'at-cx';
    cx.innerHTML =
      '<div class="at-painel" id="at-painel" role="dialog" aria-modal="false" hidden'
    + '     aria-label="Atendimento — perguntas frequentes">'
    +   '<div class="at-cab">'
    +     avatar('at-av-g')
    +     '<div class="at-quem"><b>' + esc(a.nome || 'Atendimento') + '</b>'
    +       (a.cargo ? '<span>' + esc(a.cargo) + '</span>' : '') + '</div>'
    +     '<button type="button" class="at-x" id="at-x" aria-label="Fechar o atendimento">&times;</button>'
    +   '</div>'
    +   '<div class="at-corpo" id="at-corpo">'
    +     '<div class="at-fio" id="at-fio">'
    +       (D.saudacao ? bolhaHtml('dela', D.saudacao) : '')
    +     '</div>'
    +     perguntasHtml()
    +     contactos()
    +   '</div>'
    + '</div>'
    + '<button type="button" class="at-botao" id="at-botao" aria-expanded="false"'
    + '        aria-controls="at-painel">'
    +   '<span class="at-bolha-ico" aria-hidden="true">'
    +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
    +     ' stroke-linecap="round" stroke-linejoin="round">'
    +     '<path d="M21 11.5a8.4 8.4 0 01-9 8.4 9 9 0 01-3.9-.9L3 20.5l1.6-4.6A8.3 8.3 0 013 11.5'
    +     'a8.4 8.4 0 019-8.4 8.4 8.4 0 019 8.4z"/></svg>'
    +   '</span>'
    +   '<span class="at-botao-txt">Dúvidas?</span>'
    + '</button>';
    document.body.appendChild(cx);

    painel = document.getElementById('at-painel');
    botao  = document.getElementById('at-botao');
    fio    = document.getElementById('at-fio');

    botao.addEventListener('click', alternar);
    document.getElementById('at-x').addEventListener('click', fechar);
    var sug = document.getElementById('at-sug');
    if (sug) sug.addEventListener('click', function (e) {
      var b = e.target.closest('.at-q');
      if (b) responder(+b.dataset.id, b);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && aberto) fechar();
    });
  }

  function bolhaHtml(quem, texto) {
    return '<div class="at-b at-b-' + quem + '">' + esc(texto) + '</div>';
  }

  /**
   * A resposta escrita para aquela pergunta. Escreve-se primeiro a pergunta
   * (do lado de quem perguntou) e só depois a resposta: sem isso, o fio ficava
   * uma parede de texto sem se saber a que é que cada parágrafo respondia.
   */
  function responder(id, botaoQ) {
    var p = (D.perguntas || []).filter(function (x) { return x.id === id; })[0];
    if (!p) return;
    fio.insertAdjacentHTML('beforeend', bolhaHtml('minha', p.pergunta));
    fio.insertAdjacentHTML('beforeend',
      '<div class="at-resp">' + avatar('at-av-p') + bolhaHtml('dela', p.resposta) + '</div>');
    if (botaoQ) botaoQ.classList.add('at-q-feita');
    var corpo = document.getElementById('at-corpo');
    // Rola até à resposta nova: numa caixa pequena, ela nascia fora da vista.
    requestAnimationFrame(function () { corpo.scrollTop = corpo.scrollHeight; });
  }

  function abrir() {
    devolverFoco = document.activeElement;
    aberto = true;
    painel.hidden = false;
    cx.classList.add('on');
    botao.setAttribute('aria-expanded', 'true');
    document.getElementById('at-x').focus();
  }
  function fechar() {
    aberto = false;
    painel.hidden = true;
    cx.classList.remove('on');
    botao.setAttribute('aria-expanded', 'false');
    if (devolverFoco && devolverFoco.focus) devolverFoco.focus();
  }
  function alternar() { aberto ? fechar() : abrir(); }

  function arrancar() {
    fetch('api.php?action=atendimento_publico')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        // Desligado, ou sem nada para dizer: não aparece caixa nenhuma. Um
        // balão que abre para mostrar o vazio é pior do que não haver balão.
        if (!d || !d.success || !d.ativo) return;
        if (!(d.perguntas || []).length && !d.saudacao) return;
        D = d;
        montar();
      })
      .catch(function () { /* sem atendimento, a página serve na mesma */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arrancar);
  } else {
    arrancar();
  }
})();
