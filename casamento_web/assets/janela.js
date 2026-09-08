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

/** Onde estava o foco antes de a janela abrir, para lho devolver ao fechar. */
let LIC_FOCO_ANTES = null;

/**
 * As doze cores que o campo `tipo:'cor'` oferece.
 *
 * Não são as do tema, e é de propósito: servem para DISTINGUIR coisas umas das
 * outras — gavetas de um menu, categorias de um orçamento — e para isso o que
 * importa é que se afastem entre si. Escolhidas com saturação e luminosidade
 * parecidas, ficam bem lado a lado na mesma grelha, que é onde vão parar, e
 * nenhuma delas berra sobre o creme ou sobre o escuro do salão.
 */
const LIC_CORES = [
  '#B24C7A', '#C0524B', '#C98A2E', '#A8871F', '#6F9E2B', '#2F9E8F',
  '#2E86C8', '#5A6FC0', '#8A5A2B', '#7A5AA8', '#4E7A5E', '#6E7C87'
];

/** Uma janela simples de OK/Cancelar, para as escolhas que não cabem num prompt. */
function licJanela(titulo, html, aoConfirmar, opcoes){
  opcoes = opcoes || {};
  let m = document.getElementById('lic-janela');
  if (!m){
    m = document.createElement('div'); m.id = 'lic-janela'; m.className = 'pl-modal';
    document.body.appendChild(m);
    m.addEventListener('click', ev => { if (ev.target === m) licFecharJanela(); });
  }
  // Quem abriu a janela volta a ter o foco quando ela fechar. Sem isto, o
  // teclado ficava no princípio da página e quem navega sem rato perdia o
  // sítio a cada pergunta.
  if (!m.classList.contains('on')) LIC_FOCO_ANTES = document.activeElement;
  // Os botões são da janela e não da página: os editores não carregam
  // estilo.css, e as classes .btn de lá não existem lá dentro.
  m.innerHTML = '<div class="pl-modal-cx' + (opcoes.largo ? ' largo' : '') + '" tabindex="-1"'
    + ' role="dialog" aria-modal="true" aria-labelledby="lic-jt">'
    + '<div class="pl-modal-cab"><h3 id="lic-jt">' + titulo + '</h3>'
    + '<button type="button" class="pl-modal-x" id="lic-jx" aria-label="Fechar">×</button></div>'
    + '<div class="pl-modal-corpo">' + html + '</div>'
    + '<div class="pl-modal-rodape">'
    + '<span class="lic-j-erro" id="lic-jerro" role="alert"></span>'
    + '<button type="button" class="j-bt j-bt-nao" id="lic-jc">'
    + (opcoes.cancelar || 'Cancelar') + '</button>'
    + (aoConfirmar
        ? '<button type="button" class="j-bt ' + (opcoes.perigo ? 'j-bt-perigo' : 'j-bt-sim')
          + '" id="lic-jo">' + (opcoes.guardar || 'Guardar') + '</button>'
        : '')
    + '</div></div>';
  m.classList.add('on');
  // A página por trás não rola enquanto a janela está aberta: rolar o que não
  // se pode tocar é desorientador, e no telemóvel leva a janela com ele.
  document.documentElement.style.overflow = 'hidden';
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
  // Sem campo nenhum, o foco vai para a própria janela — e não para um dos
  // botões: prende o Tab cá dentro e faz o leitor de ecrã anunciá-la, sem
  // deixar o dedo pousado em «Apagar» à espera de um Enter distraído.
  const primeiro = p1 || m.querySelector('.pl-modal-cx');
  if (primeiro) setTimeout(() => {
    try { primeiro.focus(); primeiro.select && primeiro.select(); } catch(e){}
  }, 60);
}
function licFecharJanela(){
  const m = document.getElementById('lic-janela');
  if (m) m.classList.remove('on');
  document.removeEventListener('keydown', licTeclaJanela);
  document.documentElement.style.overflow = '';
  if (LIC_FOCO_ANTES && LIC_FOCO_ANTES.focus){
    try { LIC_FOCO_ANTES.focus(); } catch(e){}
  }
  LIC_FOCO_ANTES = null;
}
function licTeclaJanela(ev){
  if (ev.key === 'Escape'){ licFecharJanela(); return; }
  // O Tab não sai da janela: por trás dela está uma página inteira de botões
  // que não se podem usar, e passar por eles às cegas é perder-se.
  if (ev.key === 'Tab'){
    const cx = document.querySelector('#lic-janela.on .pl-modal-cx');
    if (!cx) return;
    const focaveis = [...cx.querySelectorAll(
      'button:not([disabled]), input:not([type=hidden]):not([disabled]), '
      + 'select:not([disabled]), textarea:not([disabled]), a[href]')]
      .filter(el => el.offsetParent !== null);
    if (!focaveis.length) return;
    const primeiro = focaveis[0], ultimo = focaveis[focaveis.length - 1];
    if (ev.shiftKey && document.activeElement === primeiro){ ev.preventDefault(); ultimo.focus(); }
    else if (!ev.shiftKey && document.activeElement === ultimo){ ev.preventDefault(); primeiro.focus(); }
    return;
  }
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
          // Uma lista curta é melhor como <select>: é o que o telemóvel sabe
          // desenhar em roda, e ninguém procura entre três coisas. A partir de
          // uma dúzia o <select> passa a ser uma parede — dezasseis bebidas,
          // duzentos convidados — e aí a procura deixa de ser um luxo.
          // `procura: true` força-a; `procura: false` proíbe-a.
          const muitas = (c.opcoes || []).length > LIC_SEL_MUITAS;
          const comProcura = c.procura === undefined ? muitas : !!c.procura;
          campo = comProcura ? licSelProcuraHtml(c, v)
                : '<select id="lf-' + c.id + '">'
                + (c.opcoes || []).map(o =>
                    '<option value="' + licEsc(o.v) + '"' + (String(o.v) === v ? ' selected' : '') + '>'
                    + licEsc(o.r) + '</option>').join('')
                + '</select>';
        } else if (c.tipo === 'cor'){
          // Doze escolhas boas, e a porta para o resto. Um campo a pedir
          // «#rrggbb» é um teste de conhecimentos: ninguém escolhe uma cor
          // assim. As doze têm saturação e luminosidade parecidas, e por isso
          // ficam bem umas ao lado das outras — que é onde vão parar.
          const paleta = c.paleta || LIC_CORES;
          const v0 = String(v || paleta[0]).toLowerCase();
          campo = '<div class="b-cores" data-cor-de="' + c.id + '">'
            + paleta.map(p => '<button type="button" class="b-cor'
                + (p.toLowerCase() === v0 ? ' on' : '') + '" style="--c:' + p + '" '
                + 'data-cor="' + p + '" title="' + p + '" aria-label="Cor ' + p + '"></button>').join('')
            + '<label class="b-cor-mais" title="Outra cor">'
            +   '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            +   'stroke-width="1.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>'
            +   '<input type="color" value="' + licEsc(v0) + '"></label>'
            + '<input type="hidden" id="lf-' + c.id + '" value="' + licEsc(v0) + '">'
            + '</div>';
        } else {
          // 'hora' dá o relógio do telemóvel, que é o que se quer quando a
          // pergunta é «a partir de que horas» no meio de uma festa.
          const t = (c.tipo === 'numero' || c.tipo === 'preco') ? 'number'
                  : (c.tipo === 'hora' ? 'time' : 'text');
          campo = '<input type="' + t + '" id="lf-' + c.id + '" value="' + licEsc(v) + '"'
                + (c.min !== undefined ? ' min="' + c.min + '"' : '')
                + (c.max !== undefined ? ' max="' + c.max + '"' : '')
                + (c.passo ? ' step="' + c.passo + '"' : '')
                + (c.dica2 ? ' placeholder="' + licEsc(c.dica2) + '"' : '') + '>';
        }
        // O rótulo aparece SEMPRE, e o sim/não não é excepção. Era: e o que se
        // via numa janela de definições eram duas caixas a dizer «SIM, PARA
        // QUEM NÃO TEM REDE» e «DEIXAR, AVISANDO A COPA», sem em lado nenhum
        // dizer sim a QUÊ — a pergunta ficava só no código. A frase ao lado
        // (aoLado) é a resposta; o rótulo é a pergunta, e as duas precisam
        // uma da outra.
        return '<div class="lic-f-c"' + larg + '>'
          + '<label for="lf-' + c.id + '">' + licEsc(c.rot) + '</label>'
          + campo
          + (c.dica ? '<span class="lic-f-d">' + c.dica + '</span>' : '')
          + '</div>';
      }).join('')
    + '</div>' + (cfg.extra || '');

  // O selector de cor precisa de vida: as amostras escolhem, e a que fica
  // escolhida marca-se com um visto — nunca só pela moldura, que a quem
  // distingue mal as cores não diz nada (§25.7).
  const ligarCores = () => {
    document.querySelectorAll('#lic-janela .b-cores').forEach(cx => {
      const guardado = cx.querySelector('input[type=hidden]');
      const custom = cx.querySelector('input[type=color]');
      const marcar = (valor) => {
        guardado.value = valor;
        cx.querySelectorAll('.b-cor').forEach(b => {
          b.classList.toggle('on', b.dataset.cor.toLowerCase() === valor.toLowerCase());
        });
        if (custom) custom.value = valor;
      };
      cx.querySelectorAll('.b-cor').forEach(b => {
        b.addEventListener('click', () => marcar(b.dataset.cor));
      });
      if (custom) custom.addEventListener('input', () => marcar(custom.value));
    });
  };

  licJanela(cfg.titulo, html, async () => {
    const vals = {};
    campos.forEach(c => {
      const el = document.getElementById('lf-' + c.id);
      if (!el) return;
      if (c.tipo === 'sim') vals[c.id] = el.checked ? 1 : 0;
      else if (c.tipo === 'cor') vals[c.id] = el.value;
      else if (c.tipo === 'numero' || c.tipo === 'preco')
        vals[c.id] = parseFloat(String(el.value).replace(',', '.')) || 0;
      else vals[c.id] = el.value.trim();
    });
    licJanelaErro('');
    return await cfg.aoGuardar(vals);
  }, { guardar: cfg.guardar, perigo: cfg.perigo, largo: cfg.largo });
  ligarCores();
  // A escolha com procura guarda o valor num <input type=hidden> com o mesmo
  // id de sempre — por isso o leitor acima não sabe que ela existe, e não
  // precisa de saber.
  licSelProcuraLigar();
  // A última palavra é de quem montou o formulário: há campos que só fazem
  // sentido consoante a resposta de outro, e essa regra é do formulário e não
  // desta função. Recebe uma ajuda para esconder e mostrar linhas, que é o
  // caso comum e o único que valia a pena poupar a quem chama.
  if (cfg.aoMontar) cfg.aoMontar({
    campo: (id) => document.getElementById('lf-' + id),
    linha: (id) => {
      const el = document.getElementById('lf-' + id);
      return el ? el.closest('.lic-f-c') : null;
    },
    mostrar: (id, sim) => {
      const el = document.getElementById('lf-' + id);
      const linha = el ? el.closest('.lic-f-c') : null;
      if (linha) linha.hidden = !sim;
    }
  });
}

/* ============================================================
   A ESCOLHA COM PROCURA

   Uma lista de dezasseis bebidas ou de duzentos convidados dentro de um
   <select> é uma parede: rola-se à procura do nome, passa-se ao lado, e
   recomeça-se. O que se quer é escrever três letras.

   É nativo, e não Select2. O Select2 faz exactamente isto e fá-lo bem, mas
   traz o jQuery atrás (são ~160KB para uma caixa de procura), e traz a sua
   própria linguagem de cores — que teria de ser reescrita nos quatro temas da
   casa, mais o modo de leitura, mais as janelas dos editores, que se vestem
   por --j-*. É a mesma conta que se fez ao Bootstrap (docs §25.20) e dá o
   mesmo resultado: o que aqui falta não é uma biblioteca, é um componente, e
   o componente são setenta linhas que já falam a língua da casa.

   O que faz: abre, filtra sem olhar a acentos, anda com as setas, escolhe com
   Enter, fecha com Escape ou com um clique fora. O valor vive num campo
   escondido com o id de sempre (`lf-<id>`), e por isso tudo o que lê
   formulários continua a ler este como lia um <select>.
   ============================================================ */
const LIC_SEL_MUITAS = 8;

/** Sem acentos e em minúsculas: quem escreve de pé não põe acentos nenhuns. */
function licChave(s){
  return String(s == null ? '' : s)
    .normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

function licSelProcuraHtml(c, v){
  const ops = c.opcoes || [];
  const esc = ops.find(o => String(o.v) === String(v)) || ops[0] || { v: '', r: '—' };
  return '<div class="lic-sel" data-sel="' + licEsc(c.id) + '">'
    + '<input type="hidden" id="lf-' + licEsc(c.id) + '" value="' + licEsc(esc.v) + '">'
    + '<button type="button" class="lic-sel-bt" aria-haspopup="listbox" aria-expanded="false">'
    +   '<span class="txt">' + licEsc(esc.r) + '</span>'
    +   '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    +   'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    +   '<path d="m6 9 6 6 6-6"/></svg>'
    + '</button>'
    + '<div class="lic-sel-pop" hidden>'
    +   '<div class="lic-sel-q">'
    +     '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    +     'stroke-width="1.8" stroke-linecap="round" aria-hidden="true">'
    +     '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
    +     '<input type="search" autocomplete="off" placeholder="'
    +       licEsc(c.dicaProcura || 'Escreva para procurar') + '" '
    +       'aria-label="Procurar em ' + licEsc(c.rot) + '">'
    +   '</div>'
    +   '<div class="lic-sel-lista" role="listbox" aria-label="' + licEsc(c.rot) + '">'
    +     ops.map(o => licSelOpcaoHtml(o, esc.v)).join('')
    +   '</div>'
    +   '<div class="lic-sel-nada" hidden>Nada com esse nome.</div>'
    + '</div></div>';
}

/**
 * Uma linha da lista.
 *
 * Vive numa função sua, e não numa `.map()` embutida na de cima, por uma razão
 * que já custou uma tarde: esta folha alinha as cadeias de texto pondo o `+`
 * no princípio da linha, e dentro de uma arrow function isso encontra-se com o
 * `+` do operador. `'texto' + + (x ? ' on' : '')` é uma soma com um MAIS
 * UNÁRIO à frente — que converte ' on' em número, dá NaN, e cola «NaNNaNNaN»
 * ao fim de cada opção. O ecrã mostrava «Cervejas NaNNaNNaN» e a procura não
 * filtrava nada, porque os atributos do meio também se tinham desfeito.
 * Separada, a linha é uma expressão normal e a armadilha não existe.
 */
function licSelOpcaoHtml(o, escolhido){
  const on = String(o.v) === String(escolhido) ? ' on' : '';
  return '<button type="button" role="option" class="lic-sel-op' + on + '"'
       + ' data-v="' + licEsc(o.v) + '"'
       + ' data-k="' + licEsc(licChave(o.r)) + '">'
       + '<span>' + licEsc(o.r) + '</span>'
       + '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
       + ' stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"'
       + ' aria-hidden="true"><path d="M20 6.5 9.2 17.3 4 12.1"/></svg>'
       + '</button>';
}

function licSelProcuraLigar(){
  document.querySelectorAll('#lic-janela .lic-sel').forEach(cx => {
    if (cx.dataset.ligado) return;
    cx.dataset.ligado = '1';
    const guardado = cx.querySelector('input[type=hidden]');
    const bt   = cx.querySelector('.lic-sel-bt');
    const pop  = cx.querySelector('.lic-sel-pop');
    const q    = cx.querySelector('.lic-sel-q input');
    const nada = cx.querySelector('.lic-sel-nada');
    const ops  = () => Array.from(cx.querySelectorAll('.lic-sel-op'));
    const vivas = () => ops().filter(o => !o.hidden);

    const abrir = (sim) => {
      // Abrir uma fecha as outras. O clique no botão pára a propagação (senão
      // o ouvinte de «clicar fora» fechava-a no mesmo gesto que a abriu), e
      // sem isto duas listas ficavam abertas por cima uma da outra.
      if (sim){
        document.querySelectorAll('#lic-janela .lic-sel').forEach(outra => {
          if (outra === cx) return;
          const p = outra.querySelector('.lic-sel-pop');
          const b = outra.querySelector('.lic-sel-bt');
          if (p) p.hidden = true;
          if (b) b.setAttribute('aria-expanded', 'false');
        });
      }
      pop.hidden = !sim;
      bt.setAttribute('aria-expanded', sim ? 'true' : 'false');
      if (sim){ q.value = ''; filtrar(); q.focus(); }
    };
    const escolher = (op) => {
      guardado.value = op.dataset.v;
      bt.querySelector('.txt').textContent = op.querySelector('span').textContent;
      ops().forEach(o => o.classList.toggle('on', o === op));
      abrir(false); bt.focus();
      // Quem montou o formulário pode querer reagir à escolha (mostrar outro
      // campo, por exemplo). Um evento, e não um callback: assim o campo não
      // precisa de saber quem está a ouvir.
      guardado.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const filtrar = () => {
      const k = licChave(q.value);
      ops().forEach(o => { o.hidden = k !== '' && o.dataset.k.indexOf(k) < 0; });
      const n = vivas().length;
      nada.hidden = n > 0;
      ops().forEach(o => o.classList.remove('sob'));
      if (n) vivas()[0].classList.add('sob');
    };
    const andar = (passo) => {
      const lista = vivas();
      if (!lista.length) return;
      let i = lista.findIndex(o => o.classList.contains('sob'));
      i = i < 0 ? 0 : Math.min(lista.length - 1, Math.max(0, i + passo));
      lista.forEach(o => o.classList.remove('sob'));
      lista[i].classList.add('sob');
      lista[i].scrollIntoView({ block: 'nearest' });
    };

    bt.addEventListener('click', (e) => { e.stopPropagation(); abrir(pop.hidden); });
    q.addEventListener('input', filtrar);
    q.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown'){ e.preventDefault(); andar(1); }
      else if (e.key === 'ArrowUp'){ e.preventDefault(); andar(-1); }
      else if (e.key === 'Enter'){
        e.preventDefault();
        const sob = cx.querySelector('.lic-sel-op.sob');
        if (sob) escolher(sob);
      } else if (e.key === 'Escape'){ e.stopPropagation(); abrir(false); bt.focus(); }
    });
    cx.querySelectorAll('.lic-sel-op').forEach(o => {
      o.addEventListener('click', (e) => { e.stopPropagation(); escolher(o); });
    });
    // Um clique fora fecha. O ouvinte vive na janela e morre com ela.
    (document.getElementById('lic-janela') || document).addEventListener('click', (e) => {
      if (!cx.contains(e.target)) abrir(false);
    });
  });
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
      // O ícone pode ser o NOME de um sinal desenhado (assets/icones.js) ou
      // markup à medida. O nome é o caminho novo: um emoji numa janela de
      // confirmação muda de desenho conforme o sistema e não obedece ao tema.
      + (cfg.icone
          ? '<div class="lic-conf-ico">'
            + ((window.ICO && window.ICO.ico(cfg.icone)) || cfg.icone) + '</div>'
          : '')
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
