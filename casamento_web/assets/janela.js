/**
 * As janelas da casa: perguntar e editar sem window.confirm() nem prompt().
 *
 * Uma janela nativa do browser não se estiliza, não valida nada e não sabe
 * pedir mais do que uma linha. Pior: um prompt() a seguir a um confirm() parte
 * a decisão em dois ecrãs, e quem leu o aviso já não o tem à frente quando
 * escreve a resposta. As perguntas que fecham portas — apagar uma casa, tirar
 * uma conta, esvaziar o sistema — merecem melhor do que isso.
 *
 * Três coisas vivem aqui, e servem o painel do admin e a Gestão dos noivos:
 *
 *   licJanela(titulo, html, aoConfirmar, opcoes)  a janela em si
 *   licFormulario(cfg)                            um formulário inteiro à vista
 *   licConfirmar(cfg)  -> Promise<{sim, motivo}>  a pergunta de sim/não
 *
 * O prefixo «lic» é de onde nasceram (a área das licenças); ficou como nome
 * próprio quando passaram a servir a casa toda.
 */

/** Texto que vai para dentro de HTML — sempre por aqui, nunca à mão. */
function licEsc(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/** Uma janela simples de OK/Cancelar, para as escolhas que não cabem num prompt. */
function licJanela(titulo, html, aoConfirmar, opcoes){
  opcoes = opcoes || {};
  let m = document.getElementById('lic-janela');
  if (!m){
    m = document.createElement('div'); m.id = 'lic-janela'; m.className = 'pl-modal';
    document.body.appendChild(m);
    m.addEventListener('click', ev => { if (ev.target === m) licFecharJanela(); });
  }
  m.innerHTML = '<div class="pl-modal-cx' + (opcoes.largo ? ' largo' : '') + '">'
    + '<div class="pl-modal-cab"><h3>' + titulo + '</h3>'
    + '<button type="button" class="pl-modal-x" id="lic-jx" aria-label="Fechar">×</button></div>'
    + '<div class="pl-modal-corpo">' + html + '</div>'
    + '<div class="pl-modal-rodape">'
    + '<span class="lic-j-erro" id="lic-jerro"></span>'
    + '<button class="btn btn-fantasma btn-sm" id="lic-jc">' + (opcoes.cancelar || 'Cancelar') + '</button>'
    + (aoConfirmar
        ? '<button class="btn ' + (opcoes.perigo ? 'perigo' : 'btn-ouro') + ' btn-sm" id="lic-jo">'
          + (opcoes.guardar || 'Guardar') + '</button>'
        : '')
    + '</div></div>';
  m.classList.add('on');
  document.getElementById('lic-jx').onclick = licFecharJanela;
  document.getElementById('lic-jc').onclick = licFecharJanela;
  const ok = document.getElementById('lic-jo');
  if (ok) ok.onclick = async () => {
    // Guardar pode recusar: devolver false deixa a janela aberta, com o aviso,
    // para se corrigir ali mesmo em vez de recomeçar tudo.
    ok.disabled = true; const rot = ok.textContent; ok.textContent = 'A guardar…';
    let r;
    try { r = await aoConfirmar(); } finally { ok.disabled = false; ok.textContent = rot; }
    if (r === false) return;
    licFecharJanela();
  };
  document.addEventListener('keydown', licTeclaJanela);
  // O primeiro campo fica pronto a escrever: quem abre uma janela de edição
  // quer escrever, não procurar onde carregar.
  const p1 = m.querySelector('.pl-modal-corpo input:not([type=hidden]):not([disabled]), '
                           + '.pl-modal-corpo textarea, .pl-modal-corpo select');
  if (p1) setTimeout(() => { try { p1.focus(); p1.select && p1.select(); } catch(e){} }, 60);
}
function licFecharJanela(){
  const m = document.getElementById('lic-janela');
  if (m) m.classList.remove('on');
  document.removeEventListener('keydown', licTeclaJanela);
}
function licTeclaJanela(ev){
  if (ev.key === 'Escape'){ licFecharJanela(); return; }
  // Enter guarda — excepto numa área de texto, onde Enter é mudar de linha.
  if (ev.key === 'Enter' && !ev.shiftKey && ev.target && ev.target.tagName !== 'TEXTAREA'){
    const ok = document.getElementById('lic-jo');
    if (ok && !ok.disabled){ ev.preventDefault(); ok.click(); }
  }
}
/** Um aviso dentro da janela, sem a fechar. */
function licJanelaErro(txt){
  const e = document.getElementById('lic-jerro');
  if (e) e.textContent = txt || '';
}

/**
 * Um formulário em janela, em vez de uma fila de prompt().
 *
 * Uma sequência de prompt() obriga a responder às perguntas às cegas, uma de
 * cada vez, sem se ver o conjunto nem poder voltar atrás — e um Cancelar a
 * meio deita fora o que já se escreveu. Aqui vê-se tudo, corrige-se tudo, e o
 * que se escreve fica à vista até se guardar.
 *
 * campos: [{ id, rot, tipo, valor, dica, opcoes, min, max, passo, largura }]
 *   tipo: 'texto' (omissão) | 'numero' | 'preco' | 'area' | 'sim' | 'escolha'
 */
function licFormulario(cfg){
  const campos = cfg.campos || [];
  const html = (cfg.dica ? '<div class="dica">' + cfg.dica + '</div>' : '')
    + '<div class="lic-form">'
    + campos.map(c => {
        const v = c.valor === undefined || c.valor === null ? '' : String(c.valor);
        const larg = c.largura ? ' style="grid-column:span ' + c.largura + '"' : '';
        let campo;
        if (c.tipo === 'area'){
          campo = '<textarea id="lf-' + c.id + '" rows="' + (c.linhas || 3) + '">'
                + licEsc(v) + '</textarea>';
        } else if (c.tipo === 'sim'){
          campo = '<label class="lic-f-sim"><input type="checkbox" id="lf-' + c.id + '"'
                + (c.valor ? ' checked' : '') + '><span>' + licEsc(c.aoLado || 'Sim') + '</span></label>';
        } else if (c.tipo === 'escolha'){
          campo = '<select id="lf-' + c.id + '">'
                + (c.opcoes || []).map(o =>
                    '<option value="' + licEsc(o.v) + '"' + (String(o.v) === v ? ' selected' : '') + '>'
                    + licEsc(o.r) + '</option>').join('')
                + '</select>';
        } else {
          const t = (c.tipo === 'numero' || c.tipo === 'preco') ? 'number' : 'text';
          campo = '<input type="' + t + '" id="lf-' + c.id + '" value="' + licEsc(v) + '"'
                + (c.min !== undefined ? ' min="' + c.min + '"' : '')
                + (c.max !== undefined ? ' max="' + c.max + '"' : '')
                + (c.passo ? ' step="' + c.passo + '"' : '')
                + (c.dica2 ? ' placeholder="' + licEsc(c.dica2) + '"' : '') + '>';
        }
        return '<div class="lic-f-c"' + larg + '>'
          + (c.tipo === 'sim' ? '' : '<label for="lf-' + c.id + '">' + licEsc(c.rot) + '</label>')
          + campo
          + (c.dica ? '<span class="lic-f-d">' + c.dica + '</span>' : '')
          + '</div>';
      }).join('')
    + '</div>' + (cfg.extra || '');

  licJanela(cfg.titulo, html, async () => {
    const vals = {};
    campos.forEach(c => {
      const el = document.getElementById('lf-' + c.id);
      if (!el) return;
      if (c.tipo === 'sim') vals[c.id] = el.checked ? 1 : 0;
      else if (c.tipo === 'numero' || c.tipo === 'preco')
        vals[c.id] = parseFloat(String(el.value).replace(',', '.')) || 0;
      else vals[c.id] = el.value.trim();
    });
    licJanelaErro('');
    return await cfg.aoGuardar(vals);
  }, { guardar: cfg.guardar, perigo: cfg.perigo, largo: cfg.largo });
}

/**
 * Uma pergunta de sim/não em janela, em vez de window.confirm().
 *
 * Devolve uma promessa com { sim, motivo }. Duas opções mudam o que a janela
 * exige antes de deixar confirmar:
 *
 *   motivo   — uma razão escrita, que fica no registo e o casal lê. Sem ela,
 *              não confirma (é o caso de revogar uma licença).
 *   escrever — copiar uma palavra exacta: o nome da casa que se vai apagar,
 *              ou «APAGAR TUDO». É para o que não se desfaz — um clique
 *              distraído não deve chegar.
 */
function licConfirmar(cfg){
  return new Promise(resolve => {
    const temMotivo = !!cfg.motivo;
    // 'escrever' pede que se copie uma palavra exacta. É para o que não se
    // desfaz: um clique distraído não deve chegar para apagar uma casa.
    const temEscrever = !!cfg.escrever;
    const html = '<div class="lic-conf' + (cfg.perigo ? ' perigo' : '') + '">'
      + (cfg.icone ? '<div class="lic-conf-ico">' + cfg.icone + '</div>' : '')
      + '<div class="lic-conf-txt">' + cfg.texto + '</div></div>'
      + (temMotivo
          ? '<div class="lic-f-c" style="margin-top:1rem">'
            + '<label for="lf-motivo">' + licEsc(cfg.motivo.rot) + '</label>'
            + '<textarea id="lf-motivo" rows="3" placeholder="'
            + licEsc(cfg.motivo.dica2 || '') + '"></textarea>'
            + (cfg.motivo.dica ? '<span class="lic-f-d">' + cfg.motivo.dica + '</span>' : '')
            + '</div>'
          : '')
      + (temEscrever
          ? '<div class="lic-f-c lic-escrever" style="margin-top:1rem">'
            + '<label for="lf-escrever">' + licEsc(cfg.escrever.rot) + '</label>'
            + '<input type="text" id="lf-escrever" autocomplete="off" spellcheck="false"'
            + ' placeholder="' + licEsc(cfg.escrever.valor) + '">'
            + '<span class="lic-f-d">Escreva <b>' + licEsc(cfg.escrever.valor)
            + '</b> para confirmar.</span></div>'
          : '');
    let respondeu = false;
    licJanela(cfg.titulo, html, async () => {
      const el = document.getElementById('lf-motivo');
      const txt = el ? el.value.trim() : '';
      if (temMotivo && cfg.motivo.exigido && !txt){
        licJanelaErro(cfg.motivo.falta || 'Escreva o motivo.');
        if (el) el.focus();
        return false;
      }
      if (temEscrever){
        const ec = document.getElementById('lf-escrever');
        if (!ec || ec.value.trim() !== String(cfg.escrever.valor).trim()){
          licJanelaErro(cfg.escrever.falta || 'O texto não confere. Nada foi apagado.');
          if (ec){ ec.focus(); ec.select(); }
          return false;
        }
      }
      respondeu = true;
      resolve({ sim: true, motivo: txt });
    }, { guardar: cfg.confirmar || 'Confirmar', perigo: cfg.perigo, cancelar: cfg.cancelar });
    // Fechar sem confirmar é responder que não.
    const m = document.getElementById('lic-janela');
    const obs = new MutationObserver(() => {
      if (!m.classList.contains('on') && !respondeu){
        obs.disconnect(); resolve({ sim: false, motivo: '' });
      }
      if (respondeu) obs.disconnect();
    });
    obs.observe(m, { attributes: true, attributeFilter: ['class'] });
  });
}
