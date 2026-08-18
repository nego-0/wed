<?php
// ============================================================
// modelos.php — Os desenhos que a casa oferece a todos
//
// As versões (cw_versoes) são de cada casamento: o que ESTE casal guardou. Os
// modelos são o outro lado — convites prontos, feitos pela casa, para um casal
// começar de qualquer coisa bonita em vez de uma folha em branco.
//
// Como se faz um modelo: cria-se aqui (do desenho de origem, ou do convite de
// um casamento que esteja aberto) e desenha-se no editor de sempre, que abre em
// modo de modelo — sem casamento nenhum pelo meio. Pedir emprestada a casa de
// um casal para fazer um modelo da CASA era pedir o que não é preciso, e
// arriscar deixar lá o rascunho.
//
// Aplicar um modelo COPIA-O para as definições do casamento. A partir daí o
// desenho é do casal: mexer no modelo depois disso não lhe toca, e um casal que
// tenha personalizado o convite não acorda com ele mudado porque a casa mexeu
// num modelo.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';

if (!ehAdminPlataforma()) {
    header('Location: ' . (utilizadorId() ? 'plataforma.php' : 'login.php?r=modelos.php')); exit;
}

$aberto = casamentoAtual();
$nomeAberto = '';
if ($aberto > 0) {
    $r = @$conn->query("SELECT nome FROM {$P}casamentos WHERE id=$aberto LIMIT 1");
    if ($r && ($x = $r->fetch_assoc())) $nomeAberto = (string)$x['nome'];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Modelos de convite · Plataforma</title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  .painel{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.1rem 1.2rem; margin-bottom:1.2rem; }
  .painel h3{ margin:0 0 .2rem; font-size:1.05rem; }
  .painel .dica{ font-size:.85rem; color:#8a8f88; margin-bottom:.8rem; line-height:1.5; }
  .lf{ display:grid; grid-template-columns:2fr 3fr 1fr auto; gap:.7rem; align-items:end; }
  /* ---- A grelha dos modelos ----
     Esta página é sobre DESENHOS, e não mostrava desenho nenhum: escolher um
     modelo pelo nome é escolher às cegas. Cada um passa a trazer a sua cara,
     desenhada a sério (o cartão por modelo-prova.php, o convite digital por
     convite-digital.php?modelo=N) e encolhida para caber. */
  .grelha{ display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:1rem; }
  /* Sem overflow:hidden aqui: era ele que cortava o menu "⋯" — o painel abria,
     mas ficava recortado pela borda do cartão e parecia não fazer nada. Quem
     precisa de recortar é a moldura da miniatura, e essa recorta-se a si. */
  .mod{ background:#fff; border:1px solid var(--line); border-radius:14px;
        display:flex; flex-direction:column; transition:.16s; }
  .mod:hover{ border-color:var(--gold-soft); box-shadow:0 8px 22px rgba(180,134,74,.14); }
  .mod.por-publicar{ background:repeating-linear-gradient(135deg,#fff 0 10px,#fdfbf6 10px 20px); }
  /* A moldura tem a proporção do cartão (2:3); a prova é desenhada em tamanho
     real e encolhida por transform, para o que se vê ser o que sai. */
  .cara{ position:relative; width:100%; aspect-ratio:2/3; overflow:hidden; background:#20211c;
         border-bottom:1px solid var(--line); display:block; border-radius:13px 13px 0 0; }
  .cara iframe{ position:absolute; top:0; left:0; border:0; transform-origin:top left; pointer-events:none; }
  .cara .selo{ position:absolute; right:.4rem; top:.4rem; z-index:2; width:26px; height:26px;
               border-radius:8px; background:rgba(255,255,255,.92); color:var(--forest);
               display:flex; align-items:center; justify-content:center; font-size:.9rem;
               border:1px solid var(--line); }
  .cara .lupa{ position:absolute; inset:0; z-index:3; display:flex; align-items:center;
               justify-content:center; background:rgba(22,38,30,.55); color:var(--ivory);
               font-size:.82rem; opacity:0; transition:.15s; text-decoration:none; }
  .mod:hover .cara .lupa{ opacity:1; }
  .mod .corpo{ padding:.7rem .8rem; display:flex; flex-direction:column; gap:.3rem; flex:1; }
  .mod .nm{ font-family:var(--serif); font-size:1.02rem; color:var(--ink); line-height:1.25; }
  .mod .meta{ font-size:.76rem; color:#8a8f88; display:flex; gap:.5rem; flex-wrap:wrap; }
  .mod .desc{ font-size:.8rem; color:#6c7570; line-height:1.45; }
  .mod .ac{ display:flex; gap:.35rem; align-items:center; flex-wrap:wrap;
            padding:.6rem .8rem; border-top:1px solid var(--line); margin-top:auto; }
  .mod .ac .btn{ font-size:.78rem; padding:.3rem .6rem; }
  .vazio-mod{ border:1px dashed var(--line); border-radius:14px; padding:2rem 1.2rem;
              text-align:center; color:#8a8f88; font-size:.9rem; line-height:1.6; }
  .et{ font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border-radius:50px;
       padding:.1rem .55rem; border:1px solid var(--line); }
  .et.publicado{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }
  .et.rascunho{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .et.alcance{ background:#fff; color:#6c7570; text-transform:none; letter-spacing:0; }
  .et.origem{ background:rgba(180,134,74,.12); color:var(--gold); border-color:var(--gold); }
  .et.fabrica{ background:#f4f2ee; color:#6c7570; text-transform:none; letter-spacing:0; }
  .restauro{ display:flex; gap:1rem; align-items:flex-start; justify-content:space-between;
             flex-wrap:wrap; background:var(--gold-pale); border:1px solid var(--gold-soft);
             border-radius:12px; padding:.9rem 1.1rem; margin:.2rem 0 1rem; }
  .restauro-txt{ flex:1 1 320px; font-size:.9rem; color:#5b6460; }
  .restauro-lista{ list-style:none; padding:0; margin:.5rem 0 0; display:grid; gap:.35rem; }
  .restauro-lista li{ display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
  .restauro-amb{ font-size:.78rem; color:#8a938e; }
  /* Janela das opções de um modelo: as escolhas e a lista de casamentos.
     Vive no modal (ver #ov-modelo), que tem largura para uma lista se ler. */
  .modal-corpo .escolhas{ display:flex; gap:1.4rem; flex-wrap:wrap; margin-bottom:.9rem; }
  .modal-corpo .op{ display:flex; align-items:center; gap:.5rem; font-size:.92rem; color:var(--text);
                    text-transform:none; letter-spacing:0; cursor:pointer; font-weight:400; }
  .modal-corpo .op input{ width:auto; margin:0; accent-color:var(--forest); flex:none; }
  .modal-corpo .lista-cas{ max-height:min(46vh,300px); overflow:auto; border:1px solid var(--line);
                           border-radius:12px; padding:.5rem .7rem; display:flex; flex-direction:column; }
  .modal-corpo .cas-item{ padding:.42rem .2rem; border-bottom:1px solid var(--line); }
  .modal-corpo .cas-item:last-child{ border-bottom:0; }
  .modal-corpo .cas-nome{ flex:1; min-width:0; }
  .modal-corpo .cas-data{ color:#8a8f88; font-size:.82rem; }
  .jan-fim{ display:flex; justify-content:flex-end; gap:.6rem; margin-top:1.2rem;
            border-top:1px solid var(--line); padding-top:1rem; }
  /* ---- Os dados de exemplo dos modelos ----
     São a identidade INTEIRA de um convite, e vista de enfiada é uma parede de
     campos. Vem por grupos, na ordem por que se lê um convite. */
  .ex-grupo{ border-top:1px solid var(--line); margin-top:1rem; padding-top:.9rem; }
  .ex-grupo:first-child{ border-top:0; margin-top:0; padding-top:0; }
  .ex-grupo > h4{ margin:0 0 .1rem; font-size:.88rem; font-family:var(--sans);
                  font-weight:600; color:var(--ink); }
  .ex-grupo > .nota{ font-size:.8rem; color:#8a8f88; margin-bottom:.7rem; line-height:1.5; }
  .ex-campos{ display:grid; gap:.7rem; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); }
  .ex-campos label{ display:block; margin-bottom:.3rem; font-size:.76rem; text-transform:uppercase;
                    letter-spacing:.06em; color:#8a8f88; }
  /* align-items:start — a capa é ao alto e as outras ao baixo; esticadas todas
     à altura da mais alta, ficavam três cartões com meio palmo de branco. */
  .exs{ display:grid; gap:.8rem; grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
        align-items:start; }
  .ex{ border:1px solid var(--line); border-radius:12px; padding:.6rem; background:#fff; }
  /* A miniatura recorta como a secção recorta no convite (cover + foco + zoom):
     é o único modo de o enquadramento ao lado querer dizer alguma coisa. */
  .ex .moldura{ display:block; width:100%; aspect-ratio:16/10; overflow:hidden; border-radius:8px;
                background:#20211c; border:1px solid var(--line); }
  .ex.alto .moldura{ aspect-ratio:4/5; }
  .ex .moldura img{ width:100%; height:100%; object-fit:cover; display:block; }
  .ex .nm{ margin:.5rem 0 .1rem; font-size:.76rem; text-transform:uppercase;
           letter-spacing:.06em; color:#8a8f88; }
  /* A medida que a peca espera. Sem isto, cada envio era uma adivinha. */
  .ex .med{ font-size:.7rem; color:#a8ada6; margin-bottom:.35rem; }
  .ex input[type=file]{ font-size:.76rem; width:100%; }
  .ex .enq{ display:grid; grid-template-columns:repeat(3,1fr); gap:.3rem; margin-top:.45rem; }
  .ex .enq input{ padding:.3rem .35rem; font-size:.78rem; text-align:center; }
  .ex .enq span{ display:block; font-size:.66rem; text-transform:uppercase; letter-spacing:.05em;
                 color:#a8ada6; text-align:center; margin-bottom:.15rem; }
  .ex .btn-gal{ width:100%; font-size:.78rem; padding:.32rem .5rem; margin-bottom:.35rem; }
  /* A galeria da casa, dentro da janela: uma grelha de fotografias a escolher. */
  .modal-corpo .gal{ display:grid; gap:.6rem; grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
                     max-height:min(56vh,420px); overflow:auto; padding:.2rem; }
  .modal-corpo .gal-i{ position:relative; border:1px solid var(--line); background:#fff;
                       border-radius:10px; overflow:hidden; transition:.14s; }
  .modal-corpo .gal-i:hover{ border-color:var(--gold-soft); box-shadow:0 6px 16px rgba(180,134,74,.18); }
  /* A que esta em vigor marca-se: numa grelha de vinte, saber qual e a de agora
     e a primeira pergunta que se faz. */
  .modal-corpo .gal-i.em-uso{ border-color:var(--forest); box-shadow:0 0 0 2px var(--forest); }
  .modal-corpo .gal-esc{ display:block; width:100%; padding:0; border:0; background:none;
                         cursor:pointer; font-family:var(--sans); text-align:left; }
  .modal-corpo .gal-i img{ display:block; width:100%; aspect-ratio:16/10; object-fit:cover; }
  .modal-corpo .gal-pe{ padding:.34rem .45rem; }
  .modal-corpo .gal-nm{ display:block; font-size:.73rem; color:#6c7570;
                        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .modal-corpo .gal-pe select{ margin-top:.25rem; padding:.2rem .3rem; font-size:.74rem; }
  .modal-corpo .gal-et{ display:block; margin-top:.15rem; font-style:normal; font-size:.68rem;
                        color:#a8ada6; }
  /* Os separadores por categoria, com a conta de cada uma: numa biblioteca que
     cresce, saber quantas ha antes de abrir a aba poupa o clique. */
  .modal-corpo .gal-abas{ display:flex; gap:.35rem; flex-wrap:wrap; margin-bottom:.7rem; }
  .modal-corpo .gal-abas .chip b{ font-weight:600; opacity:.6; margin-left:.15rem; }
  .modal-corpo .gal-env{ display:grid; grid-template-columns:1fr auto auto; gap:.5rem;
                         align-items:center; }
  .modal-corpo .gal-rep{ margin-top:.6rem; font-size:.82rem; color:#6c7570;
                         display:flex; gap:.6rem; align-items:center; }
  .modal-corpo .gal-x{ position:absolute; right:.3rem; top:.3rem; width:24px; height:24px;
                       border:0; border-radius:50%; background:rgba(255,255,255,.92);
                       color:var(--danger); font-size:1rem; line-height:1; cursor:pointer;
                       padding:0; }
  .modal-corpo .gal-x:hover{ background:var(--danger); color:#fff; }
  /* Acrescentar uma fotografia: fica no fim da janela, depois do que ja la esta. */
  .modal-corpo .gal-mais{ border-top:1px solid var(--line); margin-top:.9rem; padding-top:.8rem; }
  .modal-corpo .gal-mais label{ display:block; margin-bottom:.35rem; font-size:.76rem;
                                text-transform:uppercase; letter-spacing:.06em; color:#8a8f88; }
  .modal-corpo .gal-mais .med{ font-size:.72rem; color:#a8ada6; margin-top:.3rem; }
  .filtros{ display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .chip{ border:1px solid var(--line); background:#fff; color:#6c7570; border-radius:50px;
         padding:.3rem .8rem; font-size:.8rem; font-family:var(--sans); cursor:pointer; }
  .chip.on{ background:var(--forest); border-color:var(--forest); color:var(--ivory); }
  .chip-sep{ width:1px; align-self:stretch; background:var(--line); margin:.1rem .3rem; }
  .chip-acao.on{ background:var(--gold); border-color:var(--gold); color:#fff; }
  .chip-ferramentas{ border-style:dashed; display:inline-flex; align-items:center; gap:.35rem; }
  .chip-ferramentas.on{ background:var(--gold); border-color:var(--gold); border-style:solid; color:#fff; }
  .chip-num{ display:inline-flex; align-items:center; justify-content:center; min-width:1.15rem;
             height:1.15rem; padding:0 .3rem; border-radius:50px; background:var(--warn,#b5713a);
             color:#fff; font-size:.72rem; font-weight:700; line-height:1; }
  .chip-ferramentas.on .chip-num{ background:#fff; color:var(--gold); }
  .fr-bloco{ border:1px solid var(--line); border-radius:12px; padding:1rem 1.1rem; margin-bottom:1rem; }
  .fr-bloco h4{ margin:0 0 .3rem; font-family:var(--sans); font-size:1rem; }
  .fr-lista{ list-style:none; padding:0; margin:.6rem 0; display:grid; gap:.4rem; }
  .fr-item{ display:flex; align-items:center; gap:.55rem; flex-wrap:wrap;
            padding:.35rem .2rem; border-bottom:1px solid var(--line); }
  .fr-item:last-child{ border-bottom:0; }
  .fr-nome{ font-weight:600; }
  .fr-est{ font-size:.78rem; }
  .fr-est.ok{ color:var(--ok, #4b8b6f); }
  .fr-est.falta{ color:var(--warn, #b5713a); font-weight:600; }
  .aviso{ background:var(--warn-bg); border:1px solid var(--warn); color:var(--ink);
          border-radius:10px; padding:.7rem .9rem; font-size:.86rem; margin-bottom:1rem; line-height:1.5; }
  @media (max-width:720px){ .lf{ grid-template-columns:1fr; } .mod{ grid-template-columns:auto 1fr; }
                            .mod .ac{ grid-column:1/-1; } }
</style>
</head>
<body>
<?php cabecalho('Modelos de convite', 'Os desenhos que a casa oferece a todos os casais', 'modelos'); ?>

<main class="container">

  <div class="painel">
    <h3>Modelos</h3>
    <div class="dica">Um modelo publicado aparece a todos os casais, no seletor de versões do editor.
      Um por publicar fica só para si — serve para o preparar com calma.</div>
    <div class="filtros" id="filtros">
      <button class="chip on" data-vista="digital" data-ambito="" onclick="filtrar('')">Todos</button>
      <button class="chip" data-vista="digital" data-ambito="digital" onclick="filtrar('digital')">Convite digital</button>
      <button class="chip" data-vista="digital" data-ambito="impresso" onclick="filtrar('impresso')">Convite impresso</button>
      <span class="chip-sep" aria-hidden="true"></span>
      <button class="chip chip-acao" data-vista="novo" onclick="verNovo()">&#43; Novo modelo</button>
      <button class="chip chip-acao" data-vista="exemplo" onclick="verExemplo()">Dados de exemplo dos modelos</button>
      <button class="chip chip-ferramentas" data-vista="ferramentas" onclick="verFerramentas()">&#9881; Reposição e ficheiros<span class="chip-num" id="fr-num" hidden></span></button>
    </div>

    <div id="lista"><div class="dica">A carregar…</div></div>
    <div id="ferramentas" style="display:none"></div>

    <div id="vista-novo" style="display:none">
      <div class="dica">
        <?php if ($aberto > 0): ?>
          Nasce do convite de <b><?= escP($nomeAberto) ?></b>, tal como está agora — ou do desenho de
          origem, se preferir começar do princípio. Só o desenho viaja: nomes, datas e convidados
          ficam onde estão. Depois de criado, desenha-se no editor, sem casamento nenhum pelo meio.
        <?php else: ?>
          Nasce do desenho de origem e desenha-se a seguir no editor — sem ter de pedir emprestada
          a casa de um casal para fazer um modelo da casa.
        <?php endif; ?>
      </div>
      <div class="lf">
        <div><label>Nome</label><input type="text" id="n-nome" placeholder="Ex: Clássico verde"></div>
        <div><label>Descrição</label><input type="text" id="n-desc" placeholder="Para quem é, o que tem de particular"></div>
        <div><label>Peça</label>
          <select id="n-ambito"><option value="digital">Convite digital</option>
                                <option value="impresso">Convite impresso</option></select></div>
        <div><button class="btn btn-ouro" onclick="criar()">Criar modelo</button></div>
      </div>
      <div class="dica" style="margin:.7rem 0 0">
        <label style="display:inline-flex;gap:.4rem;align-items:center;font-weight:400">
          <input type="checkbox" id="n-visivel" checked style="width:auto;margin:0">
          Publicar já (os casais passam a vê-lo no seletor de versões)
        </label>
        <?php if ($aberto > 0): ?>
        <label style="display:inline-flex;gap:.4rem;align-items:center;font-weight:400;margin-left:1.2rem">
          <input type="checkbox" id="n-zero" style="width:auto;margin:0">
          Começar do desenho de origem, e não do convite deste casamento
        </label>
        <?php endif; ?>
      </div>
    </div>

    <div id="vista-exemplo" style="display:none">
      <div class="dica">
        Um modelo é um desenho, e serve todos os casais — por isso não pode nascer com o nome, a
        data e as fotografias do casamento onde foi composto. Nasce com <b>estes</b> dados, que não
        são de ninguém, e são os que se veem na prova e na miniatura do modelo.
        <br>Isto vale para os modelos que criar <b>daqui para a frente</b>: os que já existem ficam
        exatamente como estão.
      </div>
      <div id="ex-corpo"><div class="dica" style="margin:0">A carregar…</div></div>
      <div class="jan-fim">
        <button class="btn" onclick="abrirGaleria()">Gerir a galeria</button>
        <button class="btn" onclick="exemploFabrica()">Repor os de fábrica</button>
        <button class="btn btn-ouro" onclick="guardarExemplo()">Guardar</button>
      </div>
    </div>
  </div>
</main>

<div class="toast" id="toast"></div>

<!-- As opções de um modelo (mudar o nome, quem o vê) abrem AQUI, e não dentro
     do cartão: um cartão da grelha tem ~260px de largura, e uma lista de
     casamentos com procura espremida nessa coluna ficava ilegível — além de
     esticar a linha inteira da grelha e desalinhar os cartões vizinhos. -->
<div class="overlay" id="ov-modelo">
  <div class="modal">
    <div class="modal-topo">
      <h3 id="ov-titulo">Modelo</h3>
      <button class="fechar" onclick="fechar('ov-modelo')">&times;</button>
    </div>
    <div class="modal-corpo" id="ov-corpo"></div>
  </div>
</div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/menu-mais.js') ?>"></script>
<script>
const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
const TEM_CASAMENTO = <?= $aberto > 0 ? 'true' : 'false' ?>;
let AMBITO = '', MODELOS = {}, CATALOGO = [], VISTA = 'modelos';
const rotAmb = a => a === 'impresso' ? 'convite impresso' : 'convite digital';

async function restaurar(alvos){
  const d = await api('modelos_restaurar', { method:'POST',
    body: JSON.stringify(alvos ? { alvos } : {}) });
  if (!d || !d.success) return;
  const n = (d.criados || []).length;
  toast(n ? (n === 1 ? 'Modelo de origem reposto.' : n + ' modelos de origem repostos.')
          : 'Não faltava nenhum modelo de origem.');
  carregar();
}

/* O corpo da pastilha «Reposição e ficheiros»: o aviso do que falta e o estado
   do catálogo de origem com os botões de repor, e o levar/trazer num ficheiro.
   É aqui — e não na lista — que mora o aviso de reposição. */
function pintarFerramentas(){
  const cx = $('ferramentas'); if (!cx) return;
  const faltam = CATALOGO.filter(c => c.em_falta);
  const linhas = CATALOGO.map(c =>
    `<li class="fr-item${c.em_falta ? ' falta' : ''}">
       <span class="fr-nome">${esc(c.nome)}</span>
       <span class="restauro-amb">${rotAmb(c.ambito)}</span>
       ${c.origem ? '<span class="et fabrica">&#128274; ficheiro de origem</span>' : ''}
       ${c.em_falta
         ? `<span class="fr-est falta">falta</span>
            <button class="btn btn-sm" onclick='restaurar(${JSON.stringify([{ambito:c.ambito,nome:c.nome}])})'>Repor</button>`
         : '<span class="fr-est ok">&#10003; presente</span>'}
     </li>`).join('');

  const aviso = faltam.length
    ? `<div class="restauro"><div class="restauro-txt">
         <b>${faltam.length === 1 ? 'Falta um modelo de origem da casa'
                                  : 'Faltam ' + faltam.length + ' modelos de origem da casa'}.</b>
         Foram apagados — pode repô-los tal como o sistema os traz, sem tocar nos que criou.</div></div>`
    : '';

  cx.innerHTML = `
    <div class="fr-bloco">
      <h4>Repor os modelos de origem</h4>
      ${aviso}
      <div class="dica">Os modelos que a casa traz de origem. Repô-los devolve o que faltar,
        sem tocar nos modelos que criou. ${faltam.length ? '' : 'Está tudo presente.'}</div>
      <ul class="fr-lista">${linhas}</ul>
      <div class="jan-fim" style="justify-content:flex-start">
        <button class="btn btn-ouro" onclick="restaurar(null)" ${faltam.length ? '' : 'disabled'}>
          Repor os que faltam</button>
        <button class="btn" onclick="reporDesenhoDeOrigem()">Repor o desenho de origem em todos</button>
      </div>
    </div>

    <div class="fr-bloco">
      <h4>Levar e trazer</h4>
      <div class="dica">Os modelos num ficheiro, para os guardar ou os levar para outra instalação.
        Trazer <b>acrescenta</b>: não substitui nem mistura nada com os que já cá estão.</div>
      <div class="lf" style="grid-template-columns:auto 1fr auto">
        <div><label>&nbsp;</label><a class="btn" href="api.php?action=modelos_exportar">Descarregar os modelos</a></div>
        <div><label for="imp">Trazer de um ficheiro</label>
          <input type="file" id="imp" accept=".json,application/json"></div>
        <div><label>&nbsp;</label><button class="btn" onclick="importar()">Trazer</button></div>
      </div>
      <div class="segredo" id="imp-res" style="display:none;background:var(--gold-pale);
           border:1px dashed var(--gold-soft);border-radius:10px;padding:.8rem .9rem;margin-top:.9rem;font-size:.88rem"></div>
    </div>`;
}

/* O número na pastilha de reposição: quantos modelos de origem faltam. Fica
   sempre à vista, mesmo sem abrir a pastilha, para o aviso não passar
   despercebido. */
function pintarNumFerramentas(){
  const el = $('fr-num'); if (!el) return;
  const n = CATALOGO.filter(c => c.em_falta).length;
  if (n){ el.textContent = n; el.hidden = false; }
  else { el.hidden = true; el.textContent = ''; }
}

async function reporDesenhoDeOrigem(){
  if (!confirm('Devolver ao desenho de origem os modelos da casa que ainda têm o nome de origem?\n\n'
    + 'Os modelos que criou não são tocados — só os da casa voltam ao que o sistema traz.')) return;
  const d = await api('modelos_restaurar', { method:'POST', body: JSON.stringify({ repor: true }) });
  if (!d || !d.success) return;
  const n = (d.criados || []).length + (d.repostos || []).length;
  toast(n ? n + ' modelo(s) de origem repostos.' : 'Já estavam todos no desenho de origem.');
  carregar();
}
function toast(m, mau){ const t=$('toast'); t.textContent=m; t.className='toast mostrar'+(mau?' erro':'');
                        setTimeout(()=>t.className='toast', 2800); }

/* Uma só vista de cada vez: a lista (com o filtro de peça), ou a vista de uma
   das pastilhas de ação. A barra de pastilhas fica sempre à vista. */
function esconderVistas(){
  ['lista','ferramentas','vista-novo','vista-exemplo'].forEach(id => {
    const e = $(id); if (e) e.style.display = 'none';
  });
}
function marcarChip(sel){
  document.querySelectorAll('#filtros .chip').forEach(c => c.classList.remove('on'));
  const c = document.querySelector(sel); if (c) c.classList.add('on');
}

function filtrar(a){
  AMBITO = a; VISTA = 'modelos';
  document.querySelectorAll('#filtros .chip').forEach(c =>
    c.classList.toggle('on', c.dataset.vista === 'digital' && c.dataset.ambito === a));
  esconderVistas(); $('lista').style.display = '';
  carregar();
}

/* A pastilha das ferramentas: reposição dos modelos de origem e levar/trazer
   num ficheiro. Não filtra a lista — troca o corpo do painel por estas opções,
   que são da casa inteira e não de uma peça só. */
function verFerramentas(){
  VISTA = 'ferramentas'; marcarChip('.chip-ferramentas');
  esconderVistas(); $('ferramentas').style.display = '';
  carregar();
}

/* Fazer um modelo novo. */
function verNovo(){
  VISTA = 'novo'; marcarChip('[data-vista="novo"]');
  esconderVistas(); $('vista-novo').style.display = '';
  carregar();
}

/* Os dados de exemplo com que um modelo novo nasce. */
let EX_CARREGADO = false;
function verExemplo(){
  VISTA = 'exemplo'; marcarChip('[data-vista="exemplo"]');
  esconderVistas(); $('vista-exemplo').style.display = '';
  if (!EX_CARREGADO){ carregarExemplo(); EX_CARREGADO = true; }
  carregar();
}

async function carregar(){
  const d = await api('modelo_lista' + (AMBITO ? '&ambito=' + AMBITO : ''));
  if (!d || !d.success) return;
  MODELOS = {};
  d.modelos.forEach(m => { MODELOS[m.id] = m; });
  CATALOGO = d.catalogo || [];
  pintarNumFerramentas();
  if (VISTA === 'ferramentas') pintarFerramentas();
  const alvo = $('lista');
  if (!d.modelos.length){
    // O estado vazio dizia que o primeiro modelo se faz "do convite de um
    // casamento aberto" — deixou de ser verdade quando os modelos passaram a
    // nascer sem casa emprestada, e mandava o admin abrir um casamento à toa.
    alvo.innerHTML = `<div class="vazio-mod">
      ${AMBITO ? 'Ainda não há modelos desta peça.'
               : 'Ainda não há modelos.'}<br>
      Faça o primeiro na pastilha <b>Novo modelo</b>, aqui em cima: nasce do desenho de origem
      ${TEM_CASAMENTO ? 'ou do convite do casamento aberto, ' : ''}e desenha-se a seguir no editor.
      ${CATALOGO.some(c => c.em_falta) ? '<br>Faltam modelos de origem da casa — reponha-os na pastilha <b>Reposição e ficheiros</b>.' : ''}
    </div>`;
    return;
  }
  alvo.innerHTML = '<div class="grelha">' + d.modelos.map(m => {
    const impresso = m.ambito === 'impresso';
    const editor = (impresso ? 'editor-cartao' : 'convite-editor') + '.php?modelo=' + m.id;
    // A prova é desenhada em tamanho real e encolhida: o cartão tem 720px de
    // largura, o convite digital 640. É por isso que a escala difere.
    const larg = impresso ? 720 : 640, esc0 = impresso ? 0.29 : 0.33;
    const prova = impresso
      ? `modelo-prova.php?modelo=${m.id}`
      : `convite-digital.php?c=EXEMPLO&demo=1&prova=1&modelo=${m.id}`;
    return `<div class="mod${+m.visivel ? '' : ' por-publicar'}">
      <div class="cara">
        <span class="selo" title="${impresso ? 'Convite impresso' : 'Convite digital'}">${impresso ? '&#9635;' : '&#9993;'}</span>
        <iframe src="${prova}" loading="lazy" tabindex="-1" aria-hidden="true" scrolling="no"
                style="width:${larg}px;height:${Math.round(larg*1.5)}px;transform:scale(var(--e))"
                onload="ajustarCara(this)" data-larg="${larg}" data-esc="${esc0}"></iframe>
        <a class="lupa" href="${editor}">Abrir no editor</a>
      </div>
      <div class="corpo">
        <div class="nm">${esc(m.nome)}</div>
        ${m.descricao ? `<div class="desc">${esc(m.descricao)}</div>` : ''}
        <div class="meta">
          <span class="et ${+m.visivel ? 'publicado' : 'rascunho'}">${+m.visivel ? 'publicado' : 'por publicar'}</span>
          ${+m.visivel ? `<span class="et alcance" title="Quem vê este modelo">${
              m.alcance === 'selecionados'
                ? '&#9737; ' + (m.casamentos || []).length + ' casamento' + ((m.casamentos||[]).length===1?'':'s')
                : '&#9737; todos os casais'}</span>` : ''}
          ${m.de_origem ? `<span class="et origem" title="É a peça de origem desta peça: o ponto de regresso, e o nome por que a peça se dá a conhecer">&#9873; peça de origem</span>` : ''}
          ${m.de_fabrica && !m.de_origem ? `<span class="et fabrica" title="É o ficheiro de origem de fábrica: a rede de segurança que existe sempre e não se apaga">&#128274; origem de fábrica</span>` : ''}
          <span>${esc((m.atualizado_em || m.criado_em || '').slice(0,10))}</span>
        </div>
      </div>
      <div class="ac">
        <a class="btn btn-sm btn-ouro" href="${editor}">Desenhar</a>
        <button class="btn btn-sm" onclick="publicar(${m.id}, ${+m.visivel ? 0 : 1})">
          ${+m.visivel ? 'Retirar' : 'Publicar'}</button>
        <span class="mm"><button class="btn btn-sm" title="Mais ações"
              onclick="abrirMais(event,${m.id})"><svg class="ico-mais" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.4" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="12.6" cy="8" r="1.5"/></svg></button>
          <span class="mm-pop" id="mm-${m.id}" style="display:none">
            <button onclick="quemVe(${m.id})">Quem vê este modelo</button>
            <button onclick="editar(${m.id})">Mudar o nome</button>
            ${m.de_origem
              ? (m.de_fabrica
                  ? ''
                  : `<button onclick="definirOrigem(${m.id}, 0)">Deixar de ser peça de origem</button>`)
              : ((+m.visivel && m.alcance === 'todos')
                  ? `<button onclick="definirOrigem(${m.id}, 1)">Definir como peça de origem</button>`
                  : '')}
            ${m.protegido ? '' : `<hr>
            <button class="perigo" onclick="apagar(${m.id}, '${esc(m.nome)}')">Apagar</button>`}
          </span></span>
      </div>
    </div>`;
  }).join('') + '</div>';
}

/** O menu "⋯" de um modelo. Um de cada vez, e fecha-se ao clicar fora. */
/**
 * Encolhe a prova para caber na moldura. A escala vem da largura real da
 * moldura, e não de um número fixo: a grelha muda de colunas com o ecrã, e
 * uma escala fixa deixava faixas por preencher ou cortava a peça.
 */
function ajustarCara(fr){
  const cx = fr.parentElement; if (!cx) return;
  const e = cx.clientWidth / (+fr.dataset.larg || 720);
  fr.style.setProperty('--e', e);
  fr.style.height = Math.ceil(cx.clientHeight / e) + 'px';
}
addEventListener('resize', () => {
  clearTimeout(window._tCara);
  window._tCara = setTimeout(() => document.querySelectorAll('.cara iframe').forEach(ajustarCara), 150);
});

async function criar(){
  const nome = $('n-nome').value.trim();
  if (!nome) return toast('Dê um nome ao modelo.', true);
  const d = await api('modelo_criar', { method:'POST', body: JSON.stringify({
    nome, descricao: $('n-desc').value.trim(), ambito: $('n-ambito').value,
    visivel: $('n-visivel').checked, do_zero: !!($('n-zero') && $('n-zero').checked) }) });
  if (!d || !d.success) return;
  $('n-nome').value = $('n-desc').value = '';
  toast('Modelo criado. Carregue em «Desenhar» para o compor.');
  carregar();
}

/** Abre a janela das opções de um modelo, com um título e um corpo. */
function abrirModelo(titulo, html){
  document.querySelectorAll('.mm-pop').forEach(x => x.style.display = 'none');
  $('ov-titulo').textContent = titulo;
  $('ov-corpo').innerHTML = html;
  abrir('ov-modelo');
  return $('ov-corpo');
}
function abrir(id){ $(id).classList.add('aberto'); }
function fechar(id){ $(id).classList.remove('aberto'); }
// Clicar no fundo fecha, como nas outras janelas da casa.
document.addEventListener('click', e => {
  const o = $('ov-modelo');
  if (o && e.target === o) fechar('ov-modelo');
});
addEventListener('keydown', e => { if (e.key === 'Escape') fechar('ov-modelo'); });

function editar(id){
  const m = MODELOS[id] || {};
  abrirModelo('Mudar o nome', `
    <div class="campo"><label for="e-nome-${id}">Nome</label>
      <input type="text" id="e-nome-${id}" value="${esc(m.nome)}"></div>
    <div class="campo"><label for="e-desc-${id}">Descrição</label>
      <input type="text" id="e-desc-${id}" value="${esc(m.descricao || '')}"></div>
    <div class="dica" style="margin:.2rem 0 1rem">
      ${TEM_CASAMENTO
        ? `<button class="btn btn-sm" onclick="recapturar(${id})">Trazer o desenho do casamento aberto</button>
           <div style="margin-top:.4rem">Substitui o desenho guardado neste modelo pelo que o
           casamento aberto mostra agora. Os casais que já o usaram não são tocados.</div>`
        : 'Para trocar o desenho deste modelo, abra o casamento onde o desenhou.'}
    </div>
    <div class="jan-fim">
      <button class="btn" onclick="fechar('ov-modelo')">Cancelar</button>
      <button class="btn btn-ouro" onclick="guardar(${id})">Guardar</button>
    </div>`);
  $('e-nome-' + id).focus();
}

async function guardar(id, recapturar){
  const m = MODELOS[id] || {};
  const d = await api('modelo_editar', { method:'POST', body: JSON.stringify({
    id, nome: $('e-nome-' + id).value.trim(), descricao: $('e-desc-' + id).value.trim(),
    visivel: +m.visivel ? 1 : 0, recapturar: !!recapturar }) });
  if (d && d.success){ fechar('ov-modelo'); toast('Modelo guardado.'); carregar(); }
}
async function recapturar(id){
  if (!confirm('Trazer o desenho do casamento aberto para este modelo?\n\n'
    + 'O desenho que o modelo tinha perde-se. Quem já o aplicou fica como está.')) return;
  guardar(id, true);
}
async function publicar(id, visivel){
  const m = MODELOS[id] || {};
  const d = await api('modelo_editar', { method:'POST', body: JSON.stringify({
    id, nome: m.nome, descricao: m.descricao || '', visivel }) });
  if (d && d.success){ toast(visivel ? 'Publicado.' : 'Retirado dos casais.'); carregar(); }
}

/** Quem vê este modelo: todos os casais, ou só os casamentos escolhidos. */
async function quemVe(id){
  const m = MODELOS[id] || {};
  abrirModelo('Quem vê «' + m.nome + '»', '<div class="dica" style="margin:0">A carregar casamentos…</div>');
  const d = await api('casamento_lista&estado=ativo');
  const cas = (d && d.casamentos) || [];
  const sel = new Set((m.casamentos || []).map(Number));
  const escolhidos = m.alcance === 'selecionados';
  const aviso = +m.visivel ? '' :
    `<div class="aviso">Este modelo está <b>por publicar</b> — ninguém o vê enquanto não carregar
     em «Publicar». Aqui escolhe-se <b>quem</b> o verá depois.</div>`;
  $('ov-corpo').innerHTML = aviso + `
    <div class="escolhas">
      <label class="op"><input type="radio" name="alc-${id}" value="todos" ${escolhidos?'':'checked'}
             onchange="alternarAlcance(${id})"> Todos os casais</label>
      <label class="op"><input type="radio" name="alc-${id}" value="selecionados" ${escolhidos?'checked':''}
             onchange="alternarAlcance(${id})"> Só os casamentos escolhidos</label>
    </div>
    <div id="cx-cas-${id}" style="display:${escolhidos?'block':'none'}">
      <input type="text" placeholder="Procurar casamento…" oninput="filtrarCas(${id}, this.value)"
             style="margin-bottom:.5rem">
      <div class="lista-cas">
        ${cas.length ? cas.map(c => `<label class="op cas-item" data-nome="${esc((c.nome||'').toLowerCase())}">
            <input type="checkbox" value="${c.id}" ${sel.has(+c.id)?'checked':''}>
            <span class="cas-nome">${esc(c.nome)}</span>
            <span class="cas-data">${esc(c.data_evento ? c.data_evento.slice(0,10) : '')}</span>
          </label>`).join('')
          : '<div class="dica" style="margin:0">Não há casamentos ativos para escolher.</div>'}
      </div>
    </div>
    <div class="jan-fim">
      <button class="btn" onclick="fechar('ov-modelo')">Cancelar</button>
      <button class="btn btn-ouro" onclick="guardarVisibilidade(${id})">Guardar</button>
    </div>`;
}
function alternarAlcance(id){
  const esc = document.querySelector(`input[name="alc-${id}"]:checked`).value === 'selecionados';
  $('cx-cas-' + id).style.display = esc ? 'block' : 'none';
}
function filtrarCas(id, q){
  q = (q || '').trim().toLowerCase();
  document.querySelectorAll(`#cx-cas-${id} .cas-item`).forEach(el => {
    el.style.display = !q || el.dataset.nome.includes(q) ? '' : 'none';
  });
}
async function guardarVisibilidade(id){
  const alcance = document.querySelector(`input[name="alc-${id}"]:checked`).value;
  const casamentos = [...document.querySelectorAll(`#cx-cas-${id} input[type=checkbox]:checked`)].map(x => +x.value);
  if (alcance === 'selecionados' && !casamentos.length){
    return toast('Escolha ao menos um casamento, ou deixe em «Todos os casais».', true);
  }
  const d = await api('modelo_visibilidade', { method:'POST', body: JSON.stringify({ id, alcance, casamentos }) });
  if (d && d.success){
    fechar('ov-modelo');
    toast(d.alcance === 'todos' ? 'Passa a ver-se em todos os casais.'
                                : `Passa a ver-se em ${d.casamentos.length} casamento(s).`);
    carregar();
  }
}
async function apagar(id, nome){
  if (!confirm('Apagar o modelo "' + nome + '"?\n\n'
    + 'Os casais que já o usaram ficam como estão — o desenho passou a ser deles.')) return;
  const d = await api('modelo_apagar&id=' + id, { method:'POST' });
  if (d && d.success){ toast('Modelo apagado.'); carregar(); }
}

/* A peça de origem: o ponto de regresso de uma peça, e o nome por que ela se dá
   a conhecer quando o casal não tem versão nem outro modelo aplicado. É uma só
   por peça (digital e impresso), e uma escolha da casa — não de um casamento.
   Só um modelo publicado e disponível a todos pode sê-la. */
async function definirOrigem(id, on){
  const m = MODELOS[id] || {};
  if (on && !confirm('Passar «' + (m.nome||'') + '» a peça de origem'
      + (m.ambito === 'impresso' ? ' do cartão impresso' : ' do convite digital') + '?\n\n'
      + 'Passa a ser o ponto de regresso desta peça, e o nome por que ela se dá a '
      + 'conhecer quando o casal ainda não escolheu nada.')) return;
  const d = await api('modelo_pecaorigem&ambito=' + encodeURIComponent(m.ambito) + '&id=' + (on ? id : 0),
                      { method:'POST' });
  if (!d || !d.success) return;
  toast(on ? 'Passou a ser a peça de origem: ' + (d.nome || m.nome)
           : 'Deixou de ser a peça de origem (volta ao automático).');
  carregar();
}

/* ---- Os dados de exemplo com que um modelo novo nasce -------------------
   Não são de casamento nenhum: vivem na linha 0 das definições. Editá-los não
   toca em modelo nenhum já feito — só nos que se criarem a seguir. */
/* Os campos por grupos, na ordem por que se lê um convite. `tipo` é o do
   <input>; 'ficheiro' é uma imagem (ou a música) com envio próprio, e `enq` diz
   qual a chave de enquadramento que a acompanha. */
const EX_GRUPOS = [
  { titulo:'O casal e o dia',
    nota:'É este o nome que se lê na capa de qualquer modelo.',
    campos:[
      { k:'casal.noiva', r:'Noiva' },
      { k:'casal.noivo', r:'Noivo' },
      { k:'evento.data', r:'Data', tipo:'date' },
      { k:'evento.hora', r:'Hora', tipo:'time' },
      { k:'evento.convidados', r:'Lugares', tipo:'number' },
      { k:'evento.whatsapp', r:'WhatsApp', ph:'só dígitos' } ] },
  { titulo:'Onde é a festa',
    campos:[
      { k:'evento.venue_titulo', r:'Título' },
      { k:'evento.local', r:'Local' },
      { k:'evento.cidade', r:'Cidade' },
      { k:'evento.maps', r:'Google Maps', tipo:'url', ph:'https://…' } ] },
  { titulo:'Cerimónia civil',
    nota:'Sem hora, a cerimónia não se anuncia — deixar em branco é uma resposta.',
    campos:[
      { k:'evento.civil_titulo', r:'Título' },
      { k:'evento.civil_hora', r:'Hora', tipo:'time' },
      { k:'evento.civil_local', r:'Local' },
      { k:'evento.civil_maps', r:'Google Maps', tipo:'url', ph:'https://…' } ] },
  { titulo:'Cerimónia religiosa',
    campos:[
      { k:'evento.religiosa_titulo', r:'Título' },
      { k:'evento.religiosa_hora', r:'Hora', tipo:'time' },
      { k:'evento.religiosa_local', r:'Local' },
      { k:'evento.religiosa_maps', r:'Google Maps', tipo:'url', ph:'https://…' } ] },
  { titulo:'Imagens e som',
    nota:'A miniatura recorta como a secção recorta no convite. Por baixo, o '
       + 'enquadramento: o ponto da imagem que fica ao centro (X, Y) e a aproximação.',
    imagens:[
      { k:'media.hero', r:'Capa', enq:'foto.hero', alto:true, dim:'ao alto · 1000×1247 ou maior' },
      { k:'media.historia', r:'História', dim:'ao baixo · 1200×750 ou maior' },
      { k:'media.interludio', r:'Interlúdio', enq:'foto.interludio', dim:'ao baixo · 1300×812 ou maior' },
      { k:'media.acesso', r:'Acesso (QR)', enq:'foto.acesso', dim:'ao baixo · 1300×812 ou maior' },
      { k:'media.musica', r:'Música', som:true, dim:'m4a ou mp3 · até 8 MB' } ] }
];
const EX_CHAVES = EX_GRUPOS.flatMap(g =>
  (g.campos || []).map(c => c.k)
    .concat((g.imagens || []).flatMap(i => i.enq ? [i.k, i.enq] : [i.k])));
let EX_FABRICA = {}, EX_GALERIA = [], EX_ATUAL = {}, EX_DIM = {}, EX_CATEGORIAS = {}, EX_OCULTAS = 0;
const EX_CAT_DA_CHAVE = { 'media.hero':'capa', 'media.historia':'historia',
                          'media.interludio':'interludio', 'media.acesso':'acesso' };

async function carregarExemplo(soDados){
  const d = await api('modelo_exemplo');
  if (!d || !d.success) return;
  EX_FABRICA = d.fabrica || {};
  EX_GALERIA = d.galeria || [];
  EX_CATEGORIAS = d.categorias || {};
  EX_OCULTAS = d.ocultas || 0;
  EX_ATUAL = d.exemplo || {};
  EX_DIM = {};
  EX_GRUPOS.forEach(g => (g.imagens || []).forEach(i => EX_DIM[i.k] = i.dim || ''));
  if (!soDados) pintarExemplo(EX_ATUAL);
}

/** "50 8 100" -> {x,y,zoom}. Vazio vale o centro sem aproximação. */
function lerEnq(v){
  const p = String(v || '').trim().split(/\s+/).map(Number);
  return { x: p[0] >= 0 ? p[0] : 50, y: p[1] >= 0 ? p[1] : 50, zoom: p[2] > 0 ? p[2] : 100 };
}

function pintarExemplo(ex){
  $('ex-corpo').innerHTML = EX_GRUPOS.map(g => `
    <div class="ex-grupo">
      <h4>${g.titulo}</h4>
      ${g.nota ? `<div class="nota">${g.nota}</div>` : ''}
      ${g.campos ? `<div class="ex-campos">${g.campos.map(c => `
        <div><label for="ex-${c.k}">${c.r}</label>
          <input type="${c.tipo || 'text'}" id="ex-${c.k}" value="${esc(ex[c.k] ?? '')}"
                 ${c.ph ? `placeholder="${c.ph}"` : ''}></div>`).join('')}</div>` : ''}
      ${g.imagens ? `<div class="exs">${g.imagens.map(i => cartaoImagem(i, ex)).join('')}</div>` : ''}
    </div>`).join('');
}

function cartaoImagem(i, ex){
  if (i.som) return `<div class="ex">
     <div class="moldura" style="display:flex;align-items:center;justify-content:center">
       <audio id="ex-img-${i.k}" src="${esc(ex[i.k] ?? '')}" controls style="width:92%"></audio></div>
     <div class="nm">${i.r}</div>
     <div class="med">${i.dim || ''}</div>
     <input type="file" accept=".m4a,.mp3,audio/*" onchange="enviarExemplo('${i.k}', this)"></div>`;
  const e = lerEnq(ex[i.enq]);
  return `<div class="ex${i.alto ? ' alto' : ''}">
     <div class="moldura"><img id="ex-img-${i.k}" src="${esc(ex[i.k] ?? '')}" alt="${i.r}"
          style="object-position:${e.x}% ${e.y}%;transform:scale(${e.zoom / 100})"></div>
     <div class="nm">${i.r}</div>
     <div class="med">${i.dim || ''}</div>
     <button class="btn btn-gal" onclick="abrirGaleria('${i.k}','${i.r}')">Galeria</button>
     <input type="file" accept="image/*,.svg" onchange="enviarExemplo('${i.k}', this)">
     ${i.enq ? `<div class="enq" data-alvo="ex-img-${i.k}">
        ${[['x','X'],['y','Y'],['zoom','Zoom']].map(([c, r]) => `<div><span>${r}</span>
          <input type="number" id="ex-${i.enq}-${c}" value="${e[c]}" min="0"
                 max="${c === 'zoom' ? 300 : 100}" oninput="verEnq('${i.enq}','ex-img-${i.k}')"></div>`).join('')}
      </div>` : ''}</div>`;
}

/** Mostra na miniatura o enquadramento que se está a escrever. */
function verEnq(chaveEnq, idImg){
  const v = c => +($('ex-' + chaveEnq + '-' + c) || {}).value || 0;
  const img = $(idImg); if (!img) return;
  img.style.objectPosition = v('x') + '% ' + v('y') + '%';
  img.style.transform = 'scale(' + Math.max(100, v('zoom')) / 100 + ')';
}

/* A galeria da casa: fotografias que ja vem recortadas para a moldura da
   seccao, e por isso entram sem ser preciso acertar o enquadramento. */
let GAL_CHAVE = '', GAL_ROTULO = '', GAL_ABA = 'todas';

/* A galeria abre de dois sítios: de uma imagem do painel (e aí escolher aplica
   àquela secção) ou do botão "Gerir a galeria" (e aí é só arrumar). Mostra
   sempre TUDO, com separadores por categoria — uma fotografia enviada para o
   interlúdio pode muito bem servir a capa. */
function abrirGaleria(chave, rotulo){
  GAL_CHAVE = chave || ''; GAL_ROTULO = rotulo || '';
  GAL_ABA = chave ? (EX_CAT_DA_CHAVE[chave] || 'todas') : 'todas';
  abrirModelo(chave ? 'Galeria · escolher para ' + rotulo : 'Galeria de fotografias', '');
  pintarGaleria();
}

function contarCat(cat){
  return (EX_GALERIA || []).filter(f => cat === 'todas' || f.categoria === cat).length;
}

function pintarGaleria(){
  const cats = EX_CATEGORIAS || {};
  const abas = [['todas', 'Todas']].concat(Object.keys(cats).map(c => [c, cats[c]]));
  const fotos = (EX_GALERIA || []).filter(f => GAL_ABA === 'todas' || f.categoria === GAL_ABA);
  const usadas = Object.values(EX_ATUAL || {});
  const opcCat = c => Object.keys(cats)
      .map(k => `<option value="${k}"${k === c ? ' selected' : ''}>${esc(cats[k])}</option>`).join('');

  $('ov-corpo').innerHTML = `
    <div class="dica" style="margin:0 0 .7rem">${GAL_CHAVE
      ? 'Toque numa fotografia para a pôr em <b>' + esc(GAL_ROTULO) + '</b>. As da casa vêm '
        + 'recortadas para a sua categoria; escolher uma centra o enquadramento.'
      : 'Todas as fotografias que a casa traz e as que enviou. Aqui arruma-as por categoria '
        + 'e apaga as suas; para as usar, abra a galeria a partir da imagem que quer trocar.'}</div>
    <div class="gal-abas">${abas.map(([c, r]) => `
      <button class="chip${GAL_ABA === c ? ' on' : ''}" onclick="mudarAba('${c}')">
        ${esc(r)} <b>${contarCat(c)}</b></button>`).join('')}</div>
    <div class="gal">${fotos.map(f => `
      <div class="gal-i${usadas.includes(f.src) ? ' em-uso' : ''}">
        ${GAL_CHAVE
          ? `<button class="gal-esc" onclick="escolherDaGaleria('${esc(f.src)}')" title="Usar em ${esc(GAL_ROTULO)}">
               <img src="${esc(f.src)}" alt="${esc(f.nome)}" loading="lazy"></button>`
          : `<div class="gal-esc"><img src="${esc(f.src)}" alt="${esc(f.nome)}" loading="lazy"></div>`}
        <div class="gal-pe">
          <span class="gal-nm">${esc(f.nome)}</span>
          ${f.da_casa
            ? `<em class="gal-et">${esc(cats[f.categoria] || f.categoria)} · da casa</em>`
            : `<select onchange="mudarCategoria('${esc(f.src)}', this.value)">${opcCat(f.categoria)}</select>`}
        </div>
        <button class="gal-x" title="${f.da_casa ? 'Tirar da galeria' : 'Apagar esta fotografia'}"
                onclick="apagarDaGaleria('${esc(f.src)}', ${f.da_casa ? 'true' : 'false'})">&times;</button>
      </div>`).join('') || '<div class="dica" style="margin:0">Nada nesta categoria.</div>'}</div>
    <div class="gal-mais">
      <label for="gal-env">Acrescentar uma fotografia</label>
      <div class="gal-env">
        <input type="file" id="gal-env" accept="image/*,.svg">
        <select id="gal-env-cat">${opcCat(GAL_ABA === 'todas' ? 'sem' : GAL_ABA)}</select>
        <button class="btn btn-ouro" onclick="enviarParaGaleria()">Enviar</button>
      </div>
      <div class="med">A categoria diz para que secção a fotografia foi feita —
        «sem categoria» guarda-a à mesma, para decidir depois.</div>
      ${EX_OCULTAS ? `<div class="gal-rep">Tirou <b>${EX_OCULTAS}</b> fotografia(s) da casa.
        <button class="btn" onclick="reporGaleria()">Repor as da casa</button></div>` : ''}
    </div>
    <div class="jan-fim"><button class="btn" onclick="fechar('ov-modelo')">Fechar</button></div>`;
}

function mudarAba(c){ GAL_ABA = c; pintarGaleria(); }

/** Envia uma fotografia para a galeria, na categoria escolhida. */
async function enviarParaGaleria(){
  const el = $('gal-env'), f = el && el.files[0];
  if (!f) return toast('Escolha o ficheiro primeiro.', true);
  const fd = new FormData();
  fd.append('ficheiro', f);
  fd.append('categoria', $('gal-env-cat').value);
  // Só entra em vigor se a galeria tiver sido aberta a partir de uma secção E a
  // categoria escolhida for a dela; senão é só acervo.
  if (GAL_CHAVE && EX_CAT_DA_CHAVE[GAL_CHAVE] === $('gal-env-cat').value) fd.append('chave', GAL_CHAVE);
  const d = await api('modelo_exemplo_upload', { method:'POST', body: fd });
  el.value = '';
  if (!d || !d.success) return;
  aplicarGaleria(d);
  toast('Fotografia acrescentada à galeria.');
}

async function mudarCategoria(src, cat){
  const d = await api('modelo_exemplo_categoria', { method:'POST',
                       body: JSON.stringify({ src, categoria: cat }) });
  if (!d || !d.success) return;
  aplicarGaleria(d);
  toast('Arrumada em «' + (EX_CATEGORIAS[cat] || cat) + '».');
}

/** Trazer de volta as da casa que foram tiradas. */
async function reporGaleria(){
  const d = await api('modelo_exemplo_repor', { method:'POST' });
  if (!d || !d.success) return;
  aplicarGaleria(d);
  toast('Galeria da casa reposta.');
}

async function apagarDaGaleria(src, daCasa){
  const aviso = daCasa
    ? 'Tirar esta fotografia da galeria?\n\nÉ da casa: o ficheiro fica no servidor e pode repô-la '
      + 'a qualquer momento em «Repor as da casa».'
    : 'Apagar esta fotografia da galeria?\n\nO ficheiro é apagado. Os modelos já criados com ela '
      + 'ficam como estão.';
  if (!confirm(aviso)) return;
  const d = await api('modelo_exemplo_apagar', { method:'POST', body: JSON.stringify({ src }) });
  if (!d || !d.success) return;
  aplicarGaleria(d);
  toast('Apagada.');
}

/** O que a API devolveu depois de mexer na galeria, posto no ecrã. */
function aplicarGaleria(d){
  if (d.galeria) EX_GALERIA = d.galeria;
  if (d.ocultas !== undefined) EX_OCULTAS = d.ocultas;
  if (d.exemplo) { EX_ATUAL = d.exemplo; pintarExemplo(d.exemplo); }
  pintarGaleria();
}

async function enviarExemplo(chave, el){
  const f = el.files[0];
  if (!f) return;
  const fd = new FormData();
  fd.append('chave', chave); fd.append('ficheiro', f);
  const d = await api('modelo_exemplo_upload', { method:'POST', body: fd });
  el.value = '';
  if (!d || !d.success) return;
  $('ex-img-' + chave).src = d.path + '?t=' + Date.now();
  toast('Ficheiro de exemplo trocado.');
}

/** O que está nos campos, pronto a enviar. As imagens vão pelo seu envio. */
function corpoExemplo(){
  const corpo = {};
  EX_CHAVES.forEach(k => {
    if (k.startsWith('media.')) return;
    if (k.startsWith('foto.')) {
      const v = c => ($('ex-' + k + '-' + c) || {}).value;
      if ($('ex-' + k + '-x')) corpo[k] = [v('x'), v('y'), v('zoom')].join(' ');
      return;
    }
    const el = $('ex-' + k);
    if (el) corpo[k] = el.value;
  });
  return corpo;
}

async function guardarExemplo(){
  const d = await api('modelo_exemplo_guardar', { method:'POST', body: JSON.stringify(corpoExemplo()) });
  if (d && d.success){ EX_ATUAL = d.exemplo; pintarExemplo(d.exemplo); toast('Dados de exemplo guardados.'); }
}

async function exemploFabrica(){
  if (!confirm('Repor o casal, o evento, as imagens e o som de exemplo tal como vêm de fábrica?')) return;
  const corpo = {};
  EX_CHAVES.forEach(k => corpo[k] = EX_FABRICA[k] ?? '');
  const d = await api('modelo_exemplo_guardar', { method:'POST', body: JSON.stringify(corpo) });
  if (d && d.success){ EX_ATUAL = d.exemplo; pintarExemplo(d.exemplo); toast('Reposto o de fábrica.'); }
}

async function importar(){
  const f = $('imp').files[0];
  if (!f) return toast('Escolha o ficheiro primeiro.', true);
  let dados;
  try { dados = JSON.parse(await f.text()); }
  catch (e) { return toast('Esse ficheiro não é um JSON válido.', true); }
  const d = await api('modelos_importar', { method:'POST', body: JSON.stringify({ ficheiro: dados }) });
  if (!d || !d.success) return;
  $('imp-res').style.display = '';
  $('imp-res').innerHTML = `Entraram <b>${d.entraram}</b> modelo(s)`
    + (d.saltados ? ` · ${d.saltados} saltado(s) por não trazerem desenho aproveitável.` : '.');
  carregar();
}

carregar();   // a lista, e o número na pastilha de reposição. Os dados de
              // exemplo carregam-se quando a sua pastilha se abre (ver verExemplo).
</script>
</body>
</html>
