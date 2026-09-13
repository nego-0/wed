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
/* Havia uma lista de escolha aberta quando se carregou no Escape?

   A pergunta tem de ser feita ANTES de o Select2 responder ao mesmo Escape, e
   é essa a razão de isto viver num ouvinte à parte, na fase de CAPTURA. Os
   eventos descem em captura e sobem em borbulha: o Select2 está ligado ao
   campo (lá em baixo) e o `licTeclaJanela` ao documento (cá em cima), e por
   isso, quando a janela é chamada, o Select2 JÁ fechou a lista e a marca de
   «aberta» já não existe.

   O sintoma era este: abrir uma escolha, arrepender-se, carregar em Escape —
   e perder o formulário inteiro, com tudo o que já lá estava escrito. Um
   Escape fecha UMA coisa de cada vez, e a de cima é a lista. */
let licHaviaLista = false;
document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape') {
    licHaviaLista = !!document.querySelector('.select2-container--open');
  }
}, true);

function licTeclaJanela(ev){
  if (ev.key === 'Escape'){
    // O Escape era da lista: ela fechou-se, e a janela fica. Ver acima.
    if (licHaviaLista) { licHaviaLista = false; return; }
    licFecharJanela(); return;
  }
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
          // SEMPRE a escolha da casa — a mesma que veste os <select> das
          // páginas. Havia aqui dois desenhos, um para listas curtas e outro
          // para longas, e um formulário com cinco campos mostrava os dois: a
          // altura mudava de linha para linha e o que se fazia num não se fazia
          // no outro. O que muda com o tamanho da lista é só a caixa de
          // procura, que não aparece quando não há o que procurar.
          campo = licSelProcuraHtml(c, v);
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
   A ESCOLHA COM PROCURA — agora sobre o Select2

   Uma lista de dezasseis bebidas ou de duzentos convidados dentro de um
   <select> é uma parede: rola-se à procura do nome, passa-se ao lado, e
   recomeça-se. O que se quer é escrever três letras.

   Isto foi, durante muito tempo, um componente escrito à mão — porque a regra
   da casa proibia bibliotecas novas. A regra foi REVOGADA (ver
   docs/bar-motor-assistido.md §5.1) e a ordem foi usar o Select2. Está usado:
   estas funções são uma casca fina por cima dele.

   A casca existe por uma razão só, e não é decorativa: as páginas desta casa
   chamam `licSelProcuraHtml`, `licSelDefinir`, `licSelRefrescar`,
   `licSelUpgrade` e `licSelProcuraLigar` em dezenas de sítios. Mantendo os
   NOMES e as ASSINATURAS, a troca do motor por baixo não obrigou a mexer em
   nenhum deles — e o dia em que o Select2 sair (ou subir de versão e mudar de
   DOM) também não obrigará.

   O que a casca acrescenta ao Select2, e porquê:

   - **Procura sem acentos.** O Select2 compara o que se escreve tal e qual:
     quem escreve «jose» não encontra «José». Numa festa em Angola, ninguém
     escreve acentos de pé, com o telemóvel numa mão. O `matcher` desta casa
     passa os dois lados por `licChave()`.
   - **A lista não é cortada pela janela.** Uma lista dentro de um modal que
     rola fica presa ao `overflow` dele. `dropdownParent` põe-na no modal, que
     é o que resolve isto — é a mesma dor que já custou uma tarde antes
     (§«a lista de uma escolha não pode ser cortada pela janela»).
   - **A caixa de procura desaparece nas listas curtas.** Escrever para filtrar
     entre três opções é trabalho para nada, e num telemóvel é um teclado a
     abrir-se por cima da lista que se quer ler.
   - **Fala português.** O Select2 vem em inglês; as frases estão aqui em baixo,
     escritas como o resto da casa fala.

   O `<select>` continua a ser a verdade: é ele que guarda o valor, é ele que o
   formulário envia, e é `#lf-<id>` como sempre foi. Quem o manipulava a mão
   continua a manipulá-lo — basta disparar `change` (ou chamar
   `licSelRefrescar`) para a caixa se voltar a sincronizar.
   ============================================================ */

/* A caixa de PROCURA só aparece a partir daqui. */
const LIC_SEL_PROCURA_MIN = 6;

/** Sem acentos e em minúsculas: quem escreve de pé não põe acentos nenhuns. */
function licChave(s){
  return String(s == null ? '' : s)
    .normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

/** Há Select2? Nas páginas que não o carregam, o <select> fica o do sistema. */
function licTemSelect2(){
  return typeof window.jQuery === 'function' && !!window.jQuery.fn
      && typeof window.jQuery.fn.select2 === 'function';
}

/* As frases do Select2, em português europeu. O `language` do Select2 aceita
   funções, e é por elas que passam os plurais — «1 carácter» e não
   «1 caracteres», que é o que se lê em metade dos sítios que o usam. */
const LIC_SEL_FRASES = {
  errorLoading: () => 'Não foi possível carregar a lista.',
  inputTooLong: (a) => {
    const n = a.input.length - a.maximum;
    return 'Apague ' + n + (n === 1 ? ' carácter' : ' caracteres') + '.';
  },
  inputTooShort: (a) => {
    const n = a.minimum - a.input.length;
    return 'Escreva mais ' + n + (n === 1 ? ' letra' : ' letras') + '.';
  },
  loadingMore: () => 'A carregar mais…',
  maximumSelected: (a) => {
    const n = a.maximum;
    return 'Só pode escolher ' + n + (n === 1 ? ' item' : ' itens') + '.';
  },
  noResults: () => 'Nada com esse nome.',
  searching: () => 'A procurar…',
  removeAllItems: () => 'Limpar'
};

/**
 * O filtro da casa: ignora acentos, e procura em qualquer sítio do nome.
 *
 * «gin» encontra «Gin Tónico» e também «Sloe Gin» — quem procura uma bebida
 * raramente sabe por que palavra ela começa. Os grupos (<optgroup>) são
 * tratados pelo Select2 se lhe devolvermos o grupo com os filhos que passaram.
 */
function licSelFiltro(params, dados){
  const q = licChave(params.term);
  if (!q) return dados;
  if (dados.children) {
    const filhos = dados.children.filter(f => licChave(f.text).indexOf(q) >= 0);
    if (!filhos.length) return null;
    return Object.assign({}, dados, { children: filhos });
  }
  return licChave(dados.text).indexOf(q) >= 0 ? dados : null;
}

/** As <option> de uma lista, a partir das opções da casa ({v, r}). */
function licSelOpcoesHtml(ops, escolhido){
  const alvo = ops.filter(o => String(o.v) === String(escolhido))[0]
            || ops[0] || { v: '', r: '—' };
  return ops.map(o =>
    '<option value="' + licEsc(o.v) + '"'
    + (String(o.v) === String(alvo.v) ? ' selected' : '') + '>'
    + licEsc(o.r) + '</option>').join('');
}

/**
 * O html de um campo de escolha, para quem monta formulários.
 *
 * Devolve um <select> a sério dentro da caixa da casa. Era um
 * <input type=hidden> mais uma lista de botões escritos à mão; agora que o
 * motor é o Select2, o que ele precisa de vestir é um <select>. O id não
 * mudou — `lf-<id>` —, e por isso quem lê formulários continua a ler isto sem
 * saber que mudou de motor.
 */
function licSelProcuraHtml(c, v){
  const ops = c.opcoes || [];
  // `classe` serve para quem a põe FORA de uma janela: sem `lic-sel-pagina` a
  // caixa procura tokens --j-* que só existem dentro do modal, e a lista sai
  // sem fundo — lê-se a página através dela.
  return '<div class="lic-sel' + (c.classe ? ' ' + c.classe : '') + '"'
    + ' data-sel="' + licEsc(c.id) + '"'
    + (c.procura === true ? ' data-procura="1"' : '')
    + (c.procura === false ? ' data-procura="0"' : '')
    + (c.dicaProcura ? ' data-dica-procura="' + licEsc(c.dicaProcura) + '"' : '')
    + '>'
    + '<select id="lf-' + licEsc(c.id) + '"'
    + ' aria-label="' + licEsc(c.rot || 'lista') + '">'
    + licSelOpcoesHtml(ops, v)
    + '</select>'
    + '</div>';
}

/**
 * Onde é que a lista há-de nascer.
 *
 * Por omissão o Select2 pendura-a no <body>, e dentro de um modal isso põe-na
 * por baixo do véu — a lista abre e não se vê. Pendurada no modal, vê-se e
 * acompanha-o. Fora de um modal, o <body> é o sítio certo: pendurá-la no campo
 * seria voltar a prendê-la ao `overflow` de quem estiver acima.
 */
function licSelOndePor(sel){
  const $ = window.jQuery;
  const modal = sel.closest('.pl-modal');
  return modal ? $(modal) : $(document.body);
}

/** Dar vida a uma escolha: o Select2, com as opções da casa. */
function licSelLigarUm(cx){
  if (!cx || cx.dataset.ligado) return;
  const sel = cx.querySelector('select');
  if (!sel) return;
  if (!licTemSelect2()) return;          // página sem a biblioteca: fica nativo
  cx.dataset.ligado = '1';
  const $ = window.jQuery;
  const quantas = sel.options.length;
  // `data-procura` força (1) ou proíbe (0); sem opinião, decide o tamanho.
  const opiniao = cx.dataset.procura;
  const comProcura = opiniao === '1' ? true
                   : opiniao === '0' ? false
                   : quantas >= LIC_SEL_PROCURA_MIN;
  $(sel).select2({
    width: '100%',
    dropdownParent: licSelOndePor(sel),
    // O Select2 conta as opções para decidir; nós já decidimos acima, e
    // dizemos-lho com um número que não deixa dúvidas.
    minimumResultsForSearch: comProcura ? 0 : Infinity,
    placeholder: cx.dataset.dicaProcura || '',
    language: LIC_SEL_FRASES,
    matcher: licSelFiltro,
    // O <select> desta casa nunca é de escolha múltipla nem se limpa com um
    // ✕: quando não há escolha, há uma opção que o diz («Sem mesa», «Todas»).
    allowClear: false,
    // A lista não leva html — os textos são escapados por quem os escreve.
    escapeMarkup: m => m
  });
  licSelEcoarNativo(sel);
  // A procura do Select2 nasce com o `placeholder` do campo, que aqui é a dica
  // de procura e não o valor. Sem isto lê-se «Mesa» dentro da caixa onde se
  // devia escrever «escreva o nome da mesa».
  if (comProcura && cx.dataset.dicaProcura) {
    $(sel).on('select2:open', () => {
      const campo = document.querySelector('.select2-container--open .select2-search__field');
      if (campo) campo.setAttribute('placeholder', cx.dataset.dicaProcura);
    });
  }
}

/* ============================================================
   O `change` DO jQUERY NÃO É UM `change` DO BROWSER

   Esta é a costura mais importante de todo o Select2 nesta casa, e a que menos
   se vê. O Select2 anuncia uma escolha com `$(sel).trigger('change')` — um
   evento do jQuery. E o `trigger` do jQuery, ao contrário do que quase toda a
   gente supõe, NÃO despacha um evento no browser: corre os handlers que o
   próprio jQuery registou, chama o `elem.onchange` escrito no html, e fica-se
   por aí. Quem ouviu com `addEventListener('change', …)` — que é como o resto
   deste sistema ouve, em dezasseis sítios — nunca é chamado.

   O sintoma era silencioso e por isso perigoso: escolher no ecrã mudava o
   valor, o formulário até gravava o valor certo, mas tudo o que devia REAGIR à
   escolha ficava parado. A frase que explica uma regra do bar não mudava com o
   modo; a mesa de entrega não repintava o rodapé; o cartão de uma entrega não
   gravava a mesa nova. Nada disto dá erro — simplesmente não acontece.

   A ponte é esta função: quando o jQuery anuncia, despacha-se um `change` a
   sério, e aí toda a gente ouve.

   O `onchange` é tirado e reposto à volta do despacho, e isso não é um truque
   sem razão: o jQuery vai chamá-lo a seguir, sozinho, dentro do mesmo
   `trigger`. Sem o tirar, o handler escrito no html corria DUAS vezes por cada
   escolha. Assim corre uma — pela mão do jQuery — e os ouvintes normais
   correm uma, pela do browser.
   ============================================================ */
function licSelEcoarNativo(sel){
  window.jQuery(sel).on('change.casa', function () {
    if (sel.__licEco) return;             // é o nosso próprio eco a voltar
    sel.__licEco = true;
    const inline = sel.onchange;
    sel.onchange = null;
    try { sel.dispatchEvent(new Event('change', { bubbles: true })); }
    finally { sel.onchange = inline; sel.__licEco = false; }
  });
}

function licSelProcuraLigar(raiz){
  (raiz || document.getElementById('lic-janela') || document)
    .querySelectorAll('.lic-sel').forEach(cx => licSelLigarUm(cx));
}

/* ============================================================
   A MESMA LISTA EM TODO O SISTEMA

   Havia dezenas de <select> nativos espalhados pelas páginas. Duas listas com
   desenhos diferentes na mesma página leem-se como duas aplicações, e a
   diferença notava-se logo no primeiro campo que não filtrava nada.

   Passa a haver uma passagem só, que veste o que encontrar. Corre ao carregar a
   página e volta a correr sobre o que nascer depois: os painéis desta casa
   escrevem-se com innerHTML e um select que aparecesse a meio de uma lista
   ficava de fora — e um campo diferente dos outros é pior do que todos iguais
   e feios.

   Quem quiser mesmo a roda do sistema (um campo dentro de uma linha apertada,
   por exemplo) marca-o com `data-sem-procura`, nele ou num antepassado.
   ============================================================ */
function licSelVestirTodos(raiz){
  if (!licTemSelect2()) return;
  const alvo = (raiz && raiz.querySelectorAll) ? raiz : document;
  alvo.querySelectorAll('select').forEach(sel => {
    // `multiple` não é uma escolha, é uma lista de caixas: esta não o sabe ser.
    if (sel.multiple || sel.dataset.licSel) return;
    if (sel.closest('[data-sem-procura]')) return;
    // Um campo que JÁ nasceu dentro de uma caixa da casa (é o que
    // licSelProcuraHtml escreve) liga-se por ela, e não se embrulha outra vez.
    // Sem esta linha havia dois caminhos a vestir o mesmo campo: esta passagem
    // chegava primeiro, punha-lhe uma segunda caixa à volta e perdia pelo
    // caminho o que a primeira dizia — entre outras coisas, o `data-procura`.
    // O sintoma era uma lista de duas opções com caixa de procura por cima.
    const jaTem = sel.closest('.lic-sel');
    if (jaTem) { licSelLigarUm(jaTem); sel.dataset.licSel = '1'; return; }
    licSelUpgrade(sel);
  });
}

/* Uma vez ao abrir, e depois sobre o que for aparecendo. O observador junta as
   chegadas de um mesmo instante numa passagem só: um painel que se escreve de
   uma vez traz vinte nós, e vesti-los um a um seria vinte passagens pela
   página.

   O observador IGNORA o que o próprio Select2 escreve. Ele pendura a lista no
   body e reescreve a caixa a cada abertura; sem este cuidado, cada abertura
   acordava a passagem, que voltava a varrer a página inteira — e uma delas
   apanhava o <select> de procura do próprio Select2 e tentava vesti-lo. */
(function () {
  let marcado = false;
  const nosso = (n) => n.classList && (n.classList.contains('select2-container')
                                    || n.classList.contains('select2-dropdown'));
  const vestirEmBreve = () => {
    if (marcado) return;
    marcado = true;
    requestAnimationFrame(() => { marcado = false; licSelVestirTodos(document); });
  };
  const arrancar = () => {
    licSelVestirTodos(document);
    new MutationObserver(mut => {
      for (const m of mut) {
        for (const n of m.addedNodes) {
          if (n.nodeType !== 1 || nosso(n)) continue;
          if (n.tagName === 'SELECT' || (n.querySelector && n.querySelector('select'))) {
            vestirEmBreve(); return;
          }
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arrancar);
  } else arrancar();
})();

/**
 * Vestir um <select> que já existe na página com a procura por dentro.
 *
 * É melhoria progressiva, e não substituição: o <select> continua lá, continua
 * a ser quem guarda o valor, e continua a disparar `change`. Quem o manipulava
 * antes — a acrescentar opções, a mudar `value`, a desactivá-lo — continua a
 * fazê-lo sem saber de nada; basta disparar `change` (ou chamar
 * `licSelRefrescar`) para a caixa se voltar a sincronizar.
 *
 * Vale para TODAS as listas do sistema, e não só para as longas. A roda nativa
 * do telemóvel é boa, mas cada sistema desenha a sua: numa página que já tem
 * esta escolha em metade dos campos, a outra metade aparecia com outro desenho,
 * outra altura e outro comportamento. Uma casa, uma lista. Quem quiser mesmo o
 * <select> do sistema põe-lhe `data-sem-procura`.
 */
function licSelUpgrade(sel, opc){
  if (!sel || sel.dataset.licSel) return null;
  opc = opc || {};
  const minimo = opc.minimo === undefined ? 0 : opc.minimo;
  if (sel.options.length <= minimo) return null;
  if (!licTemSelect2()) return null;
  sel.dataset.licSel = '1';

  // A caixa da casa fica à volta, e não é decoração: é por `closest('.lic-sel')`
  // que metade das páginas encontra o campo que acabou de mexer, e é nela que
  // vive o `data-sel` que as provas usam para chegar a um campo pelo nome.
  const cx = document.createElement('div');
  // `lic-sel-pagina` diz-lhe para se vestir pelos tokens da página: fora de um
  // modal não há --j-* nenhuns a herdar.
  cx.className = 'lic-sel'
    + (sel.closest('.pl-modal') ? '' : ' lic-sel-pagina')
    + (opc.classe ? ' ' + opc.classe : '');
  cx.dataset.sel = sel.className || sel.name || 'sel';
  if (opc.dicaProcura) cx.dataset.dicaProcura = opc.dicaProcura;
  if (opc.rotulo && !sel.getAttribute('aria-label')) {
    sel.setAttribute('aria-label', opc.rotulo);
  }
  sel.parentNode.insertBefore(cx, sel);
  cx.appendChild(sel);

  licSelLigarUm(cx);
  return cx;
}

/**
 * Pôr uma escolha num valor, de fora.
 *
 * `cx` é a caixa `.lic-sel` (é o que as páginas têm à mão), mas aceita também o
 * próprio <select>: quem chama nem sempre sabe qual dos dois apanhou.
 *
 * `calado` não dispara `change`: é para quando quem chama JÁ sabe (acabou de
 * ser ele a mudar o estado) e não se quer ouvir a si próprio. Mesmo calado, a
 * CAIXA tem de ser avisada — senão ela diz uma coisa e o formulário envia
 * outra —, e é isso que faz o `change.select2`, que só o Select2 ouve.
 */
function licSelDefinir(cx, valor, calado){
  if (!cx) return;
  const sel = cx.tagName === 'SELECT' ? cx : cx.querySelector('select');
  if (!sel) return;
  sel.value = String(valor);
  if (!licTemSelect2() || !sel.dataset.licSel && !sel.dataset.select2Id) {
    if (!calado) sel.dispatchEvent(new Event('change', { bubbles: true }));
    return;
  }
  const $ = window.jQuery;
  if (calado) licSelSoACaixa(sel);
  else $(sel).trigger('change');
}

/* ============================================================
   REDESENHAR A CAIXA SEM ACORDAR A PÁGINA

   O Select2 redesenha-se quando o <select> dispara `change.select2`. O
   problema é como se dispara isso: tanto o `trigger` como o `triggerHandler`
   do jQuery, mesmo com o namespace, acabam por invocar o `onchange=` escrito
   no html do elemento — o namespace serve para escolher entre os handlers
   LIGADOS, e o inline não é um deles, é lido do `elem.onchange`.

   E isso fechava um ciclo infinito bem real: a linha de uma pessoa, em
   index.php, tem `onchange="sincroMesaPapel(...)"`, e sincroMesaPapel chama
   licSelRefrescar para a caixa acompanhar o <select>. Redesenhar chamava o
   onchange, que mandava redesenhar, que chamava o onchange — «Maximum call
   stack size exceeded», e a página do painel de convidados morria ao abrir um
   convite.

   A tranca é uma só e é global de propósito: o ciclo passa POR FORA destas
   funções (vai à página e volta), e uma tranca por elemento não o via passar.
   ============================================================ */
let licSelADesenhar = false;
function licSelSoACaixa(sel){
  if (licSelADesenhar) return;
  licSelADesenhar = true;
  try { window.jQuery(sel).trigger('change.select2'); }
  finally { licSelADesenhar = false; }
}

/**
 * Voltar a pôr a caixa de acordo com o <select> que está por baixo dela.
 *
 * Chama-se quando as OPÇÕES mudaram por fora (o editor de convites acrescenta e
 * tira mesas). `change.select2` é o evento que redesenha a caixa sem a
 * reconstruir e sem acordar quem ouve `change` — que seria ouvir-se a si
 * próprio e, nos sítios que gravam ao mudar, gravar sem ninguém ter mexido.
 */
function licSelRefrescar(cx){
  if (!cx) return;
  const sel = cx.tagName === 'SELECT' ? cx : cx.querySelector('select');
  if (!sel || !licTemSelect2()) return;
  // Quem chama isto está a dizer «as opções mudaram, redesenha» — e não «o
  // valor mudou», que é o que um `change` anuncia e o que faria as páginas
  // gravarem sem ninguém ter mexido. Ver licSelSoACaixa: é ela que impede o
  // ciclo com o `onchange` da própria página.
  licSelSoACaixa(sel);
  if (cx.classList) cx.classList.toggle('desligada', sel.disabled);
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
