<?php
// ============================================================
// gestao.php — Os dados do casamento, num sítio só
//
// Até aqui, mudar a data do evento fazia-se... no editor do convite digital,
// entre a cor das pétalas e a citação do rodapé. E os nomes dos noivos, que a
// ficha do casamento já conhecia desde a inscrição, tinham de ser reescritos
// lá dentro para aparecerem nas peças.
//
// Esta página junta o que é do CASAMENTO — e não do desenho de uma peça:
//
//   • a ficha (nomes e data), que é o valor de origem de tudo o resto;
//   • os dados do evento (hora, sítio, mapa, contacto);
//   • o endereço público por onde os convidados chegam;
//   • quem entra, com que papel, e as contas dos porteiros;
//   • os códigos que abrem a porta ao suporte;
//   • a própria conta de quem está a ver.
//
// A última secção é para toda a gente, incluindo quem só trabalha à porta —
// era a única forma de um porteiro poder mudar a sua senha.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';
require_once __DIR__ . '/parcial-endereco.php';
exigirPorta();

$souAdmin = ehAdmin();
$visita   = emVisitaDeSuporte();
$soVer    = $visita && !podeCorrigir();
$DEFS = defsAtuais($conn);
$CAS  = casalInfo($DEFS);

// O orçamento total (teto) e a moeda são factos do casamento, como a ficha —
// definem-se aqui, e não na página do orçamento, que passa a ser só a leitura
// das despesas. Lêem-se direto (não vivem em defsAtuais).
$orcTeto  = $souAdmin ? (float)(orcamentoResumo($conn)['teto'] ?? 0) : 0.0;
$orcMoeda = $souAdmin ? orcamentoMoeda($conn) : 'Kz';

// A ficha, tal como está guardada — e não como as peças a mostram: se alguém
// escreveu outro nome por cima, no editor, é preciso poder ver a diferença.
$ficha = ['nome'=>'', 'noiva'=>'', 'noivo'=>'', 'data_evento'=>''];
if ($souAdmin) {
    $r = @$conn->query("SELECT nome, noiva, noivo, data_evento FROM {$P}casamentos
                        WHERE id=" . casamentoAtual() . " LIMIT 1");
    if ($r && ($x = $r->fetch_assoc())) $ficha = array_map(fn($v) => (string)$v, $x);
}
// Campos do convite que estão escritos POR CIMA da ficha (linha em cw_definicoes).
$porCima = [];
if ($souAdmin) {
    $r = @$conn->query("SELECT chave FROM {$P}definicoes WHERE " . doCasamento()
                       . " AND chave IN ('casal.noiva','casal.noivo','evento.data')");
    if ($r) while ($x = $r->fetch_assoc()) $porCima[] = $x['chave'];
}

// Os campos do evento que esta página governa: rótulo, tipo e limite (o mesmo
// que validarDefinicao aplica, para ninguém se surpreender depois de gravar).
$CAMPOS_EVENTO = [
    'evento.hora'          => ['Hora da festa', 'time', 5, ''],
    'evento.venue_titulo'  => ['Título do momento', 'text', 80, ''],
    'evento.local'         => ['Local da festa', 'text', 120, ''],
    'evento.cidade'        => ['Cidade / região', 'text', 80, ''],
    'evento.convidados'    => ['Convidados que espera', 'number', 5, 'Serve de teto na barra do painel.'],
    'evento.maps'          => ['Ligação do Google Maps', 'url', 500, ''],
    'evento.whatsapp'      => ['WhatsApp de contacto', 'text', 20, ''],
];
// As duas cerimónias, à parte da festa — e opcionais: há casamentos só com uma.
// Sem hora, a cerimónia simplesmente não se anuncia em lado nenhum.
$CAMPOS_CERIMONIA = [
    'evento.civil_hora'      => ['Cerimónia civil · hora', 'time', 5, ''],
    'evento.civil_local'     => ['Cerimónia civil · local', 'text', 120, ''],
    'evento.civil_maps'      => ['Cerimónia civil · Google Maps', 'url', 500, ''],
    'evento.religiosa_hora'  => ['Cerimónia religiosa · hora', 'time', 5, ''],
    'evento.religiosa_local' => ['Cerimónia religiosa · local', 'text', 120, ''],
    'evento.religiosa_maps'  => ['Cerimónia religiosa · Google Maps', 'url', 500, ''],
];
// O campo do nome do local que cada ligação do mapa acompanha (para o botão
// "Escolher no Google Maps" já abrir à procura desse sítio).
$MAPA_LOCAL = [
    'evento.maps'           => 'evento.local',
    'evento.civil_maps'     => 'evento.civil_local',
    'evento.religiosa_maps' => 'evento.religiosa_local',
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestão · <?= escP($CAS['casal']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<style>
  .painel{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.2rem 1.3rem; margin-bottom:1.2rem; }
  .painel h3{ margin:0 0 .2rem; font-size:1.05rem; }
  .painel .dica{ font-size:.85rem; color:#8a8f88; margin-bottom:1rem; line-height:1.5; }
  .grelha{ display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:.8rem; }
  .grelha .campo{ min-width:0; }
  .grelha label{ display:block; }
  .largo{ grid-column:1/-1; }
  .fim{ display:flex; gap:.6rem; align-items:center; margin-top:1rem; }
  .fim .estado{ font-size:.82rem; color:#8a8f88; }
  .linha{ display:grid; grid-template-columns:auto 1fr auto; gap:.9rem; align-items:center;
          padding:.65rem 0; border-top:1px solid var(--line); }
  .linha:first-of-type{ border-top:0; }
  .linha .selo{ width:34px; height:34px; border-radius:9px; background:var(--cream); color:var(--forest);
                display:flex; align-items:center; justify-content:center; font-family:var(--serif); border:1px solid var(--line); }
  .linha .nm{ font-size:.95rem; color:var(--ink); }
  .linha .mt{ font-size:.78rem; color:#8a8f88; margin-top:.1rem; }
  .linha .ac{ display:flex; gap:.4rem; align-items:center; white-space:nowrap; }
  .et{ font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border-radius:50px;
       padding:.1rem .55rem; border:1px solid var(--line); }
  .et.ativo{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }
  .et.pendente,.et.expirado{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .et.suspenso,.et.revogado{ background:var(--danger-bg); color:var(--danger); border-color:var(--danger); }
  .et.inativo{ background:var(--cream); color:#8a8f88; border-color:var(--line); }
  .et.valido{ background:var(--gold-pale); color:var(--ink); border-color:var(--gold-soft); }
  .lf{ display:grid; grid-template-columns:2fr 1fr auto; gap:.7rem; align-items:end; margin-top:1rem;
       padding-top:1rem; border-top:1px dashed var(--line); }
  .cod{ font-family:ui-monospace,monospace; font-size:1.05rem; letter-spacing:.12em; color:var(--ink); }
  .segredo{ background:var(--gold-pale); border:1px dashed var(--gold-soft); border-radius:10px;
            padding:.8rem .9rem; margin-top:.9rem; font-size:.88rem; line-height:1.6; }
  .aviso-visita{ background:var(--warn-bg); border:1px solid var(--warn); color:var(--ink);
                 border-radius:10px; padding:.7rem .9rem; font-size:.86rem; margin-bottom:1.2rem; line-height:1.5; }
  .porcima{ background:var(--cream); border-left:3px solid var(--gold-soft); border-radius:8px;
            padding:.6rem .8rem; font-size:.83rem; color:#6c7570; margin-top:.9rem; line-height:1.55; }
  .semdono{ background:var(--warn-bg); border-left:3px solid var(--warn); border-radius:8px;
            padding:.6rem .8rem; font-size:.84rem; color:var(--ink); margin-bottom:.8rem; line-height:1.55; }
  @media (max-width:640px){ .lf{ grid-template-columns:1fr; } .linha{ grid-template-columns:auto 1fr; }
                            .linha .ac{ grid-column:1/-1; } }
</style>
</head>
<body>
<?php cabecalho('Gestão', 'Os dados do casamento, quem lá entra e a sua conta', 'gestao'); ?>

<main class="container">

  <?php if ($visita): ?>
    <div class="aviso-visita">
      Está a acompanhar este casamento com um código de suporte
      <b><?= $soVer ? 'de leitura' : 'com permissão de correção' ?></b>.
      <a href="#" onclick="sairVisita();return false">Terminar a visita</a>.
    </div>
  <?php endif; ?>

  <?php if ($souAdmin): ?>
    <div class="painel">
      <h3>O nosso casamento</h3>
      <div class="dica">É daqui que sai o resto: o monograma, o cabeçalho, os nomes no convite,
        no cartão impresso e na contagem decrescente. Mude aqui uma vez, e muda em todo o lado.</div>
      <div class="grelha">
        <div class="campo"><label for="f-noiva">Nome da noiva</label>
          <input type="text" id="f-noiva" maxlength="80" value="<?= escP($ficha['noiva']) ?>"></div>
        <div class="campo"><label for="f-noivo">Nome do noivo</label>
          <input type="text" id="f-noivo" maxlength="80" value="<?= escP($ficha['noivo']) ?>"></div>
        <div class="campo"><label for="f-data">Data do casamento</label>
          <input type="date" id="f-data" value="<?= escP($ficha['data_evento']) ?>"></div>
        <div class="campo largo"><label for="f-nome">Como o casamento se chama no sistema</label>
          <input type="text" id="f-nome" maxlength="160" value="<?= escP($ficha['nome']) ?>"
                 placeholder="Deixe vazio para usar «Noiva &amp; Noivo»"></div>
      </div>
      <?php if ($porCima): ?>
        <div class="porcima">
          O convite tem <b><?= count($porCima) ?> destes campos escritos por cima</b>, no editor
          (<?= escP(implode(', ', $porCima)) ?>) — e é essa versão que os convidados veem.
          Ao guardar aqui, essa cópia é retirada e as peças voltam a seguir estes valores.
        </div>
      <?php endif; ?>
      <div class="fim">
        <button class="btn btn-ouro" onclick="guardarFicha()">Guardar</button>
        <span class="estado" id="e-ficha"></span>
      </div>
    </div>

    <div class="painel">
      <h3>O evento</h3>
      <div class="dica">Onde, a que horas e por onde falar consigo. Estes valores entram no convite
        digital, no cartão impresso e na página de confirmação — não é preciso ir ao editor por causa deles.</div>
      <div class="grelha">
        <?php foreach ($CAMPOS_EVENTO as $chave => [$rot, $tipo, $lim, $nota]):
          $id = 'd-' . str_replace('.', '-', $chave);
          $ehMapa = isset($MAPA_LOCAL[$chave]);
          $localId = $ehMapa ? 'd-' . str_replace('.', '-', $MAPA_LOCAL[$chave]) : ''; ?>
          <div class="campo<?= $ehMapa ? ' largo' : '' ?>">
            <label for="<?= $id ?>"><?= escP($rot) ?></label>
            <input type="<?= $tipo ?>" id="<?= $id ?>" data-chave="<?= escP($chave) ?>"
                   maxlength="<?= (int)$lim ?>" value="<?= escP((string)($DEFS[$chave] ?? '')) ?>"
                   <?= $ehMapa ? 'data-mapa data-mapa-local="' . escP($localId) . '" placeholder="https://maps.app.goo.gl/…"' : '' ?>>
            <?php if ($nota): ?><div class="dica" style="margin:.25rem 0 0"><?= escP($nota) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="dica" style="margin:1.2rem 0 .6rem"><b>As cerimónias</b>, se as houver —
        deixe em branco o que não se aplicar. Sem hora, a cerimónia não se anuncia.</div>
      <div class="grelha">
        <?php foreach ($CAMPOS_CERIMONIA as $chave => [$rot, $tipo, $lim, $nota]):
          $id = 'd-' . str_replace('.', '-', $chave);
          $ehMapa = isset($MAPA_LOCAL[$chave]);
          $localId = $ehMapa ? 'd-' . str_replace('.', '-', $MAPA_LOCAL[$chave]) : ''; ?>
          <div class="campo<?= $ehMapa ? ' largo' : '' ?>">
            <label for="<?= $id ?>"><?= escP($rot) ?></label>
            <input type="<?= $tipo ?>" id="<?= $id ?>" data-chave="<?= escP($chave) ?>"
                   maxlength="<?= (int)$lim ?>" value="<?= escP((string)($DEFS[$chave] ?? '')) ?>"
                   <?= $ehMapa ? 'data-mapa data-mapa-local="' . escP($localId) . '" placeholder="https://maps.app.goo.gl/…"' : '' ?>>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="fim">
        <button class="btn btn-ouro" onclick="guardarEvento()">Guardar</button>
        <span class="estado" id="e-evento"></span>
      </div>
    </div>

    <div class="painel">
      <h3>Orçamento</h3>
      <div class="dica">O teto do orçamento e a moeda em que o conta. O teto é opcional — sem ele,
        a barra do <a href="orcamento.php" style="color:var(--gold)">Orçamento</a> mede-se pela soma
        dos previstos das categorias. A gestão das despesas é feita lá.</div>
      <div class="grelha">
        <div class="campo">
          <label for="d-orc-total">Orçamento total (teto)</label>
          <input type="text" id="d-orc-total" class="campo-moeda" inputmode="decimal"
                 placeholder="ex.: 2 500 000,00" value="<?= $orcTeto > 0 ? escP(number_format($orcTeto, 2, ',', ' ')) : '' ?>">
        </div>
        <div class="campo">
          <label for="d-orc-moeda">Moeda</label>
          <input type="text" id="d-orc-moeda" maxlength="8" placeholder="Kz"
                 value="<?= $orcMoeda === 'Kz' ? '' : escP($orcMoeda) ?>">
        </div>
      </div>
      <div class="fim">
        <button class="btn btn-ouro" onclick="guardarOrcamento()">Guardar</button>
        <span class="estado" id="e-orcamento"></span>
      </div>
    </div>

    <div class="painel">
      <h3>Endereço público</h3>
      <div class="dica">O endereço por onde os convidados chegam. É este que vai nos QR impressos —
        e no papel já não há emenda.</div>
      <?php barraEndereco('os links e os QR dos convites'); ?>
    </div>

    <div class="painel">
      <h3>Quem entra neste casamento</h3>
      <div class="dica">Os <b>noivos</b> gerem tudo. O <b>porteiro</b> só vê a porta:
        procura convites e regista entradas, e mais nada. Convide-o pelo email — se ainda não
        tiver conta, ela é criada aqui e recebe uma senha temporária para lhe entregar.<br>
        Daqui convidam-se <b>porteiros</b>. Passar a gestão do casamento a outra conta faz-se com
        quem responde pela plataforma presente — senão bastava um convite mal dirigido para o
        casamento passar a ser de outra pessoa.</div>
      <div id="lista-acessos"><div class="dica">A carregar…</div></div>

      <?php if (!$soVer): ?>
      <div class="lf">
        <div><label>Email de quem convida</label>
          <input type="email" id="a-email" placeholder="porteiro@exemplo.pt" autocapitalize="none" spellcheck="false"></div>
        <div><label>Papel</label>
          <select id="a-papel" disabled><option value="porteiro">Porteiro</option></select></div>
        <div><button class="btn btn-ouro" onclick="convidar()">Dar acesso</button></div>
      </div>
      <div class="segredo" id="senha-nova" style="display:none"></div>
      <?php endif; ?>
    </div>

    <div class="painel">
      <h3>Códigos de suporte</h3>
      <div class="dica">Se precisar de ajuda de quem gere a plataforma, gere aqui um código e entregue-o.
        Sem código, o suporte não entra neste casamento. Pode revogá-lo a qualquer momento —
        e deixa de servir mesmo a quem esteja a usá-lo nesse instante.</div>
      <div id="lista-codigos"><div class="dica">A carregar…</div></div>

      <?php if (!$visita): ?>
      <div class="lf">
        <div><label>O que permite</label>
          <select id="s-corrigir">
            <option value="0">Só ver</option>
            <option value="1">Ver e corrigir</option>
          </select></div>
        <div><label>Válido por</label>
          <select id="s-dias">
            <option value="1">1 dia</option>
            <option value="7" selected>7 dias</option>
            <option value="30">30 dias</option>
          </select></div>
        <div><button class="btn btn-ouro" onclick="gerarCodigo()">Gerar código</button></div>
      </div>
      <div class="segredo" id="codigo-novo" style="display:none"></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($souAdmin): ?>
    <div class="painel">
      <h3>Os nossos dados</h3>
      <div class="dica">Os dados deste casamento são seus. Leve-os quando quiser — para guardar,
        para mudar de servidor, ou só para não ficar dependente de ninguém. O ficheiro traz a ficha,
        o desenho dos convites, as mesas, os convites e as pessoas.</div>
      <div class="fim" style="margin-top:0">
        <a class="btn" href="api.php?action=dados_exportar&amp;ambito=casamento">Descarregar os meus dados</a>
        <span class="estado">Um ficheiro <code>.json</code>, legível e completo.</span>
      </div>

      <?php if (!$soVer): ?>
      <div class="lf" style="grid-template-columns:1fr auto">
        <div><label for="imp-ficheiro">Trazer dados de um ficheiro</label>
          <input type="file" id="imp-ficheiro" accept=".json,application/json"></div>
        <div><button class="btn" onclick="importarDados()">Substituir por este ficheiro</button></div>
      </div>
      <div class="semdono" style="margin:.8rem 0 0">
        <b>Substituir apaga o que está cá.</b> Os convites, as pessoas, as mesas e o desenho deste
        casamento são trocados pelos do ficheiro. Não é uma junção — é uma troca. Descarregue os
        dados atuais primeiro, se quiser poder voltar atrás.
      </div>
      <div class="segredo" id="imp-resultado" style="display:none"></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="painel">
    <h3>A minha conta</h3>
    <div class="dica">Entrou como <b><?= escP(utilizadorAtual() ?? '') ?></b>.</div>
    <div class="grelha">
      <div class="campo"><label for="p-atual">Senha atual</label>
        <input type="password" id="p-atual" autocomplete="current-password"></div>
      <div class="campo"><label for="p-nova">Nova senha</label>
        <input type="password" id="p-nova" autocomplete="new-password"></div>
    </div>
    <div class="fim">
      <button class="btn" onclick="mudarSenha()">Mudar senha</button>
      <span class="estado">Pelo menos 8 caracteres.</span>
    </div>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/maps-campo.js') ?>"></script>
<script src="<?= asset('assets/moeda.js') ?>"></script>
<script>
const $ = id => document.getElementById(id);
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
const SOU_ADMIN = <?= $souAdmin ? 'true' : 'false' ?>;
const SO_VER_UI = <?= $soVer ? 'true' : 'false' ?>;
function toast(m, mau){ const t=$('toast'); t.textContent=m; t.className='toast mostrar'+(mau?' erro':'');
                        setTimeout(()=>t.className='toast', 2600); }

// ---------- a ficha ----------
async function guardarFicha(){
  const d = await api('casamento_identidade', { method:'POST', body: JSON.stringify({
    noiva: $('f-noiva').value.trim(), noivo: $('f-noivo').value.trim(),
    data_evento: $('f-data').value, nome: $('f-nome').value.trim() }) });
  if (!d || !d.success) return;
  $('e-ficha').textContent = 'Guardado. A recarregar…';
  // Recarrega-se de propósito: o monograma, o cabeçalho e o título da página
  // mudam com isto, e mostrá-los desatualizados seria mentir sobre o efeito.
  setTimeout(() => location.reload(), 700);
}

// ---------- o evento ----------
async function guardarEvento(){
  const defs = {};
  document.querySelectorAll('[data-chave]').forEach(el => { defs[el.dataset.chave] = el.value; });
  const d = await api('defs_save', { method:'POST', body: JSON.stringify({ defs }) });
  if (!d || !d.success) return;
  $('e-evento').textContent = (d.gravadas || 0) + ' alterado(s), ' + (d.repostas || 0) + ' reposto(s).';
  toast('Dados do evento guardados.');
}

// ---------- o orçamento (teto e moeda) ----------
async function guardarOrcamento(){
  const d = await api('orc_ajuste', { method:'POST', body: JSON.stringify({
    total: $('d-orc-total').value.trim(), moeda: $('d-orc-moeda').value.trim() }) });
  if (!d || !d.success) return;
  const r = d.resumo || {};
  $('e-orcamento').textContent = r.teto > 0
    ? ('Teto: ' + (window.Moeda ? window.Moeda.paraCampo(r.teto) : r.teto) + ' ' + (d.moeda || 'Kz') + '.')
    : 'Sem teto — a barra usa a soma dos previstos.';
  toast('Orçamento guardado.');
}

// A máscara dos campos de preço (espaço nos milhares, duas casas).
if (window.Moeda) window.Moeda.ligar('.campo-moeda');

// ---------- quem entra ----------
async function carregarAcessos(){
  const d = await api('acesso_lista');
  if (!d || !d.success) return;
  const alvo = $('lista-acessos');
  if (!d.acessos.length){
    alvo.innerHTML = `<div class="semdono" style="margin-bottom:0">Este casamento <b>ainda não tem conta nenhuma</b>.
      Dê acesso, aqui em baixo, ao email de quem o vai gerir — sem isso, só a plataforma lá entra.</div>`;
    return;
  }
  // Um casamento sem conta de noivos é um casamento sem dono: quem responde
  // pela plataforma chega lá, mas o casal não. Diz-se, e diz-se onde se
  // resolve — o campo de convidar está logo por baixo.
  const temDono = d.acessos.some(a => a.papel === 'noivos');
  const aviso = temDono ? '' :
    `<div class="semdono">Este casamento <b>não tem nenhuma conta de noivos</b>.
     Quem responde pela plataforma chega cá, mas o casal não. Dê acesso, aqui em baixo,
     ao email de quem o vai gerir.</div>`;
  alvo.innerHTML = aviso + d.acessos.map(a => {
    const eu = +a.utilizador_id === +d.eu;
    const nome = a.nome || a.email;
    const acoes = (SO_VER_UI || eu) ? '' : `
      <button class="btn btn-sm" onclick="tirar(${a.utilizador_id}, '${esc(nome)}')">Tirar</button>`;
    // Quem é da casa entra em qualquer casamento por responder pela plataforma.
    // Se também tem lugar aqui, aparece — mas dito pelo que é, e não como se
    // fosse do casal.
    const daCasa = a.papel_plataforma
      ? `<span class="et">${esc(a.papel_plataforma)} da plataforma</span>` : '';
    return `<div class="linha">
      <div class="selo">${esc(nome.slice(0,1).toUpperCase())}</div>
      <div>
        <div class="nm">${esc(nome)} ${eu ? '<span class="et">é você</span>' : ''}
          <span class="et ${esc(a.estado)}">${esc(a.estado)}</span> ${daCasa}</div>
        <div class="mt">${esc(a.email)} · ${a.papel === 'noivos' ? 'gere o casamento' : 'só a porta'}
          ${a.ultimo_acesso ? '· último acesso ' + esc(a.ultimo_acesso.slice(0,10)) : '· nunca entrou'}</div>
      </div>
      <div class="ac">${acoes}</div>
    </div>`;
  }).join('');
}
async function convidar(){
  const email = $('a-email').value.trim();
  if (!email) return toast('Indique o email.', true);
  const d = await api('acesso_convidar', { method:'POST',
    body: JSON.stringify({ email, papel: 'porteiro' }) });
  if (!d || !d.success) return;
  $('a-email').value = '';
  if (d.senha){
    $('senha-nova').style.display = '';
    $('senha-nova').innerHTML = `Conta criada para <b>${esc(d.email)}</b>.
      Senha temporária: <b class="cod">${esc(d.senha)}</b><br>
      Entregue-lha agora — não volta a aparecer. Ela deve mudá-la nesta página, em «A minha conta».`;
  } else {
    toast('Acesso dado a ' + d.email + '.');
  }
  carregarAcessos();
}
async function trocarPapel(uid, papel){
  const d = await api('acesso_papel&utilizador=' + uid + '&papel=' + papel, { method:'POST' });
  if (d && d.success) carregarAcessos();
}
async function tirar(uid, nome){
  if (!confirm('Tirar o acesso de ' + nome + ' a este casamento?\n\nA conta continua a existir.')) return;
  const d = await api('acesso_tirar&utilizador=' + uid, { method:'POST' });
  if (d && d.success) carregarAcessos();
}

// ---------- códigos de suporte ----------
async function carregarCodigos(){
  const d = await api('suporte_codigo_lista');
  if (!d || !d.success) return;
  const alvo = $('lista-codigos');
  if (!d.codigos.length){ alvo.innerHTML = '<div class="dica">Nenhum código gerado.</div>'; return; }
  alvo.innerHTML = d.codigos.map(c => {
    const usado = c.usado_em ? `usado por ${esc(c.usado_por_email || 'alguém')}` : 'ainda não usado';
    const expira = c.expira_em ? ('expira em ' + esc(c.expira_em.slice(0,10))) : 'sem prazo';
    const revogar = (c.estado === 'valido')
      ? `<button class="btn btn-sm" onclick="revogar(${c.id})">Revogar</button>` : '';
    return `<div class="linha">
      <div class="selo">&#128273;</div>
      <div>
        <div class="nm"><span class="cod">${esc(c.codigo)}</span>
          <span class="et ${esc(c.estado)}">${esc(c.estado)}</span>
          <span class="et">${c.pode_corrigir == 1 ? 'ver e corrigir' : 'só ver'}</span></div>
        <div class="mt">${expira} · ${usado}</div>
      </div>
      <div class="ac">${revogar}</div>
    </div>`;
  }).join('');
}
async function gerarCodigo(){
  const d = await api('suporte_codigo_criar', { method:'POST',
    body: JSON.stringify({ pode_corrigir: $('s-corrigir').value === '1', dias: +$('s-dias').value }) });
  if (!d || !d.success) return;
  $('codigo-novo').style.display = '';
  $('codigo-novo').innerHTML = `Código: <b class="cod">${esc(d.codigo)}</b> ·
    ${d.pode_corrigir ? 'ver e corrigir' : 'só ver'} · válido ${d.dias} dia(s).<br>
    Entregue-o a quem lhe vai dar apoio. Enquanto não o revogar, essa pessoa entra aqui com ele.`;
  carregarCodigos();
}
async function revogar(id){
  if (!confirm('Revogar este código?\n\nQuem o tiver deixa de entrar, já.')) return;
  const d = await api('suporte_codigo_revogar&id=' + id, { method:'POST' });
  if (d && d.success) carregarCodigos();
}

// ---------- a minha conta ----------
async function mudarSenha(){
  const atual = $('p-atual').value, nova = $('p-nova').value;
  if (!atual || !nova) return toast('Preencha as duas senhas.', true);
  const d = await api('senha_mudar', { method:'POST', body: JSON.stringify({ atual, nova }) });
  if (d && d.success){ $('p-atual').value = $('p-nova').value = ''; toast('Senha mudada.'); }
}
// ---------- trazer dados de um ficheiro ----------
async function importarDados(){
  const f = $('imp-ficheiro').files[0];
  if (!f) return toast('Escolha o ficheiro primeiro.', true);
  let dados;
  try { dados = JSON.parse(await f.text()); }
  catch (e) { return toast('Esse ficheiro não é um JSON válido.', true); }
  if (!dados || dados.formato !== 'casamento-web/1') {
    return toast('Este ficheiro não é uma exportação deste sistema.', true);
  }
  // Contam-se as coisas ANTES de perguntar: uma confirmação que não diz o que
  // vai acontecer não é uma confirmação.
  const c = (dados.casamentos || [])[0] || {};
  const nc = (c.convites || []).length;
  const np = (c.convites || []).reduce((s, x) => s + ((x.membros || []).length), 0);
  const nm = (c.mesas || []).length;
  if (!confirm(`Substituir os dados deste casamento?\n\n`
    + `Entram: ${nc} convite(s), ${np} pessoa(s), ${nm} mesa(s).\n`
    + `Sai: tudo o que está cá agora.\n\nIsto não se desfaz.`)) return;
  const comFicha = confirm('Trazer também os nomes e a data do ficheiro?\n\n'
    + 'OK = sim, ficam os do ficheiro.\nCancelar = não, ficam os que já cá estão.');
  const d = await api('dados_importar', { method:'POST',
    body: JSON.stringify({ modo: 'substituir', com_ficha: comFicha, ficheiro: dados }) });
  if (!d || !d.success) return;
  const r = (d.resumo || [])[0] || {};
  $('imp-resultado').style.display = '';
  $('imp-resultado').innerHTML = `Entraram <b>${r.convites || 0}</b> convite(s), `
    + `<b>${r.pessoas || 0}</b> pessoa(s), <b>${r.mesas || 0}</b> mesa(s) e `
    + `<b>${r.versoes || 0}</b> versão(ões).`
    + (r.codigos_trocados ? `<br><b>${r.codigos_trocados}</b> código(s) tiveram de mudar por já `
        + `estarem em uso — os QR antigos desses convites deixam de servir.` : '');
  toast('Dados importados.');
}

async function sairVisita(){
  const d = await api('suporte_sair', { method:'POST' });
  if (d && d.success) location.href = 'plataforma.php';
}

if (SOU_ADMIN){ carregarAcessos(); carregarCodigos(); }
</script>
</body>
</html>
