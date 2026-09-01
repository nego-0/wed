<?php
// ============================================================
// plataforma.php — Os casamentos que o sistema serve
//
// A casa vista de cima: quem é da plataforma (admin, suporte) vê aqui todos
// os casamentos, abre o que precisa e cria novos. Quem é dos noivos e tem mais
// do que um vê aqui os seus, e escolhe em qual quer trabalhar.
//
// Serve também de guarda: saber SEMPRE em que casamento se está é o que evita
// editar o convite do casal errado.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/personalizacao.php';
require_once __DIR__ . '/parcial-cabecalho.php';

// Qualquer pessoa autenticada chega aqui; o que vê é que difere.
if (!papel() && !ehPessoalPlataforma()) {
    header('Location: login.php?r=' . urlencode('plataforma.php')); exit;
}

// Antes de desenhar as listas, fecha-se a porta às licenças vencidas: os
// casamentos expirados passam a suspensos (e as contas deles param), para a
// página mostrar já o estado verdadeiro — é aqui que a suspensão automática se
// torna visível a quem responde pela casa.
if (ehPessoalPlataforma()) suspenderLicencasExpiradas($conn);

$meus    = casamentosDoUtilizador($conn);
$aberto  = casamentoAtual();
$daCasa  = ehPessoalPlataforma();
$mandaNaCasa = ehAdminPlataforma();   // criar casamentos e gerir contas é do admin

// Números de cada casamento, numa consulta só por tabela — em alojamento
// partilhado, uma consulta por linha da lista custa caro.
$conta = [];
foreach ([['convites', 'convites'], ['convidados', 'pessoas'], ['mesas', 'mesas']] as [$tab, $rot]) {
    $r = @$conn->query("SELECT casamento_id, COUNT(*) n FROM {$P}$tab GROUP BY casamento_id");
    if ($r) while ($x = $r->fetch_assoc()) $conta[(int)$x['casamento_id']][$rot] = (int)$x['n'];
}
// Casamentos sem uma conta de noivos. Desde que o admin da plataforma deixou de
// ocupar um lugar que não era dele, isto deixou de ser uma hipótese teórica:
// um casamento pode ficar sem ninguém que seja MESMO dele — e é preciso vê-lo,
// senão o casal nunca recebe as chaves da sua própria festa.
$semDono = [];
$r = @$conn->query("SELECT c.id FROM {$P}casamentos c
                    LEFT JOIN {$P}acessos a ON a.casamento_id = c.id AND a.papel = 'noivos'
                    WHERE c.estado <> 'arquivado' GROUP BY c.id HAVING COUNT(a.id) = 0");
if ($r) while ($x = $r->fetch_assoc()) $semDono[(int)$x['id']] = true;

// ---- Estatísticas de toda a casa (só para quem responde por ela) ----------
// Esta é a página onde o pessoal da plataforma aterra ao entrar. Aterrar numa
// lista de nomes não diz nada sobre o estado do sistema: quantos casamentos
// estão de pé, quanta gente lá dentro, o que está à espera de alguém.
//
// As consultas atravessam casamentos de propósito e dizem-no ('casamento_id > 0'),
// que é o que o guarda de âmbito exige para as deixar passar.
// O painel de estado da casa é do ADMIN. O suporte não tem aqui um posto de
// comando: entra num casamento com o código que o casal lhe der, e o que
// precisa de ver é isso — não o retrato de toda a casa.
$G = null;
if ($mandaNaCasa) {
    $G = ['casamentos'=>0, 'ativos'=>0, 'pendentes'=>0, 'suspensos'=>0,
          'convites'=>0, 'pessoas'=>0, 'confirmadas'=>0, 'presentes'=>0,
          'contas'=>0, 'contas_ativas'=>0, 'contas_espera'=>0, 'contas_suspensas'=>0,
          'codigos'=>0, 'sem_dono'=>count($semDono), 'proximo'=>null];

    $r = @$conn->query("SELECT estado, COUNT(*) n FROM {$P}casamentos GROUP BY estado");
    if ($r) while ($x = $r->fetch_assoc()) {
        $G['casamentos'] += (int)$x['n'];
        if ($x['estado'] === 'ativo')    $G['ativos']    = (int)$x['n'];
        if ($x['estado'] === 'pendente') $G['pendentes'] = (int)$x['n'];
        if ($x['estado'] === 'suspenso') $G['suspensos'] = (int)$x['n'];
    }

    $vivos = soVivos($conn, 'c');
    $r = @$conn->query("SELECT COUNT(*) n FROM {$P}convites c WHERE c.casamento_id > 0 AND $vivos");
    if ($r) $G['convites'] = (int)$r->fetch_assoc()['n'];

    $r = @$conn->query("SELECT COUNT(*) tot,
                               SUM(CASE WHEN g.rsvp='confirmado' THEN 1 ELSE 0 END) conf,
                               COALESCE(SUM(g.presente),0) pres
                        FROM {$P}convidados g
                        JOIN {$P}convites c ON c.id = g.convite_id AND c.casamento_id = g.casamento_id
                        WHERE g.casamento_id > 0 AND $vivos");
    if ($r && ($x = $r->fetch_assoc())) {
        $G['pessoas']     = (int)$x['tot'];
        $G['confirmadas'] = (int)$x['conf'];
        $G['presentes']   = (int)$x['pres'];
    }

    $r = @$conn->query("SELECT estado, COUNT(*) n FROM {$P}utilizadores GROUP BY estado");
    if ($r) while ($x = $r->fetch_assoc()) {
        $G['contas'] += (int)$x['n'];
        if ($x['estado'] === 'ativo')    $G['contas_ativas']    = (int)$x['n'];
        if ($x['estado'] === 'pendente') $G['contas_espera']    = (int)$x['n'];
        if ($x['estado'] === 'suspenso') $G['contas_suspensas'] = (int)$x['n'];
    }

    // Portas abertas ao suporte, neste momento. É o número que um administrador
    // deve poder ver de relance: cada um destes é alguém de fora com acesso a
    // uma festa que não é sua.
    $r = @$conn->query("SELECT COUNT(*) n FROM {$P}suporte_codigos
                        WHERE casamento_id > 0 AND revogado_em IS NULL
                          AND (expira_em IS NULL OR expira_em > NOW())");
    if ($r) $G['codigos'] = (int)$r->fetch_assoc()['n'];

    $r = @$conn->query("SELECT nome, data_evento FROM {$P}casamentos
                        WHERE estado='ativo' AND data_evento >= CURDATE()
                        ORDER BY data_evento LIMIT 1");
    if ($r && ($x = $r->fetch_assoc())) $G['proximo'] = $x;
}

// Os arquivados saem de casamentosDoUtilizador() — é o que arquivar quer dizer.
// Mas alguém tem de os poder ver, senão arquivar era perdê-los: ficavam na base
// sem porta por onde voltar.
$arquivados = [];
if (ehAdminPlataforma()) {
    $r = @$conn->query("SELECT id, nome, data_evento FROM {$P}casamentos
                        WHERE estado='arquivado' ORDER BY nome");
    if ($r) $arquivados = $r->fetch_all(MYSQLI_ASSOC);
}

// A lista de cima é a das casas EM FUNCIONAMENTO. Um casamento por aprovar já
// tem o seu lugar na fila, um arquivado tem a sua secção, e um suspenso passa a
// ter a dele: misturados, a lista principal deixava de responder à pergunta que
// se lhe faz de manhã — em quantos casamentos é que estamos a trabalhar.
$suspensos = [];
foreach ($meus as $id => $c) {
    if (($c['estado'] ?? '') !== 'ativo') {
        if (($c['estado'] ?? '') === 'suspenso') $suspensos[$id] = $c;
        unset($meus[$id]);
    }
}

// Registos à espera (só o admin da plataforma os despacha).
//
// Já não se aprovam aqui: um casamento abre-se ao decidir o seu PEDIDO DE
// LICENÇA, e não por um botão à parte. Eram duas decisões para a mesma coisa —
// e nada impedia que ficassem em desacordo (um casamento aprovado sem licença
// nenhuma é um casal que entra e não pode fazer nada). Esta lista passou a ser
// o que sempre devia ter sido: um aviso de que há gente à porta, com o caminho
// para o sítio onde se lhe abre.
$pendentes = [];
if (ehAdminPlataforma()) {
    $r = @$conn->query("SELECT c.id, c.nome, c.noiva, c.noivo, c.criado_em,
                               (SELECT COUNT(*) FROM {$P}lic_pedidos p
                                 WHERE p.casamento_id = c.id AND p.estado='pendente') tem_pedido
                        FROM {$P}casamentos c
                        WHERE c.estado='pendente' ORDER BY c.criado_em");
    if ($r) $pendentes = $r->fetch_all(MYSQLI_ASSOC);
}

// Sem casamento aberto não há casal a nomear — e ler as definições do
// casamento 0 devolvia o casal de origem do config.php, que não é de ninguém
// aqui. O cabeçalho, nesse caso, veste-se da casa.
$CAS = $aberto > 0 ? casalInfo(defsAtuais($conn))
                   : ['mono'=>PLATAFORMA['marca'], 'casal'=>PLATAFORMA['nome'], 'noiva'=>'', 'noivo'=>''];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Casamentos · Plataforma</title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/estilo.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/janela.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/planos.css') ?>" rel="stylesheet"><?php // a montra e a janela das políticas ?>
<link href="<?= asset('assets/atendimento.css') ?>" rel="stylesheet"><?php // a cara de quem atende, no painel do atendimento ?>
<style>
  .cas-lista{ display:grid; gap:.7rem; }
  .cas{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:.8rem 1rem;
        display:grid; grid-template-columns:auto 1fr auto; gap:.9rem; align-items:center; }
  .cas.aberto{ border-color:var(--gold-soft); box-shadow:0 0 0 3px rgba(180,134,74,.14); }
  .cas .selo{ width:38px; height:38px; border-radius:10px; background:var(--cream); color:var(--forest);
              display:flex; align-items:center; justify-content:center; font-family:var(--serif);
              font-size:1rem; border:1px solid var(--line); }
  .cas.aberto .selo{ background:var(--gold-pale); border-color:var(--gold-soft); }
  .cas .nm{ font-family:var(--serif); font-size:1.1rem; color:var(--ink); }
  .cas .meta{ font-size:.8rem; color:#8a8f88; display:flex; gap:.7rem; flex-wrap:wrap; margin-top:.15rem; }
  .cas .ac{ display:flex; gap:.4rem; align-items:center; white-space:nowrap; }
  .et{ font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border-radius:50px;
       padding:.1rem .55rem; border:1px solid var(--line); }
  .et.ativo{ background:var(--ok-bg); color:var(--ok); border-color:var(--ok); }
  .et.pendente{ background:var(--warn-bg); color:var(--warn); border-color:var(--warn); }
  .et.suspenso{ background:var(--danger-bg); color:var(--danger); border-color:var(--danger); }
  .et.inativo{ background:var(--cream); color:#8a8f88; border-color:var(--line); }
  .et.agora{ background:var(--gold-pale); color:var(--ink); border-color:var(--gold-soft); }
  .painel{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:1.1rem 1.2rem; margin-bottom:1.2rem; }
  .painel h3{ margin:0 0 .2rem; font-size:1.05rem; }
  .painel .dica{ font-size:.85rem; color:#8a8f88; margin-bottom:.8rem; line-height:1.5; }

  /* Os painéis que dobram vivem em assets/estilo.css: são de duas páginas. */
  .cod{ font-family:ui-monospace,monospace; letter-spacing:.12em; }
  .falta{ color:var(--warn); font-weight:500; }
  .filtros{ display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .chip{ border:1px solid var(--line); background:#fff; color:#6c7570; border-radius:50px;
         padding:.3rem .8rem; font-size:.8rem; font-family:var(--sans); cursor:pointer; }
  .chip.on{ background:var(--forest); border-color:var(--forest); color:var(--ivory); }
  .editor-conta{ grid-column:1/-1; border-top:1px dashed var(--line); margin-top:.7rem; padding-top:.8rem; }
  .editor-conta .lf{ margin-top:.5rem; }
  .numeros{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
            gap:.7rem; margin-bottom:.8rem; }
  .numeros .n{ background:#fff; border:1px solid var(--line); border-radius:12px;
               padding:.85rem 1rem; }
  .numeros .n b{ display:block; font-family:var(--serif); font-size:1.9rem; line-height:1;
                 color:var(--forest); }
  .numeros .n span{ display:block; font-size:.76rem; text-transform:uppercase;
                    letter-spacing:.05em; color:#8a8f88; margin-top:.35rem; }
  .numeros .n em{ display:block; font-style:normal; font-size:.76rem; color:#a2a8a2; margin-top:.2rem; }
  .numeros .n.alerta{ border-color:var(--gold-soft); background:var(--gold-pale); }
  .numeros .n.alerta b{ color:var(--gold); }
  /* Um número que leva a algum lado tem de o parecer. Os que são só contagem
     ficam como estavam — fingir que tudo é clicável é pior do que não o ser. */
  .numeros button.n{ font:inherit; text-align:left; cursor:pointer; transition:.15s; }
  .numeros button.n:hover{ border-color:var(--gold-soft); transform:translateY(-2px);
                           box-shadow:0 6px 16px rgba(180,134,74,.12); }
  .numeros button.n::after{ content:'ver →'; display:block; font-size:.72rem; color:var(--gold);
                            margin-top:.35rem; opacity:0; transition:.15s; }
  .numeros button.n:hover::after{ opacity:1; }

  /* ---- A linha de um casamento ----
     Dizia convites, pessoas e quando se lá mexeu. Faltava o que mais importa
     a quem gere casamentos: QUANDO é, e quantos já disseram que vêm. */
  .cas .quando{ font-variant-numeric:tabular-nums; }
  .cas .conta{ color:var(--gold); font-weight:500; }
  .cas .barra{ height:4px; border-radius:50px; background:var(--cream); overflow:hidden;
               margin-top:.45rem; max-width:260px; }
  .cas .barra i{ display:block; height:100%; background:var(--ok); }
  .cas .ac .btn{ white-space:nowrap; }
  /* ---- Licenças: pedidos, preçário e pacotes ---- */
  .lic-badge{ display:inline-flex; align-items:center; justify-content:center; min-width:18px;
    height:18px; padding:0 .3rem; margin-left:.4rem; border-radius:50px; background:var(--warn);
    color:#fff; font-size:.7rem; font-weight:700; font-variant-numeric:tabular-nums; }
  .lic-grupo{ font-family:var(--serif); font-size:1.15rem; color:var(--ink);
    margin:1.6rem 0 .8rem; display:flex; align-items:center; gap:.55rem; }
  .lic-grupo:first-child{ margin-top:.2rem; }
  .lic-grupo span{ font-family:var(--sans); font-size:.72rem; font-weight:700;
    background:var(--gold-pale); color:var(--gold-deep); border-radius:50px; padding:.12rem .5rem; }
  .lic-ped.pend{ border-left:4px solid var(--warn); }
  .lic-ped-cab{ display:flex; gap:1rem; align-items:flex-start; flex-wrap:wrap; }
  .lic-ped-nome{ font-family:var(--serif); font-size:1.15rem; color:var(--ink); }
  .lic-ped-vl{ font-family:var(--serif); font-size:1.35rem; color:var(--ink);
    font-variant-numeric:tabular-nums; white-space:nowrap; }
  .lic-ped-selo{ font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
  .lic-ped-selo.pendente{ color:var(--warn); }
  .lic-ped-selo.aprovado{ color:var(--ok); }
  .lic-ped-selo.recusado{ color:var(--danger); }
  .lic-ped-selo.cancelado{ color:#9aa09a; }
  .lic-ped-nota{ background:var(--cream); border-left:3px solid var(--gold); border-radius:8px;
    padding:.6rem .8rem; font-size:.85rem; line-height:1.55; margin-top:.7rem; }
  .lic-itens{ list-style:none; margin:.7rem 0 0; padding:0; }
  .lic-itens li{ display:flex; gap:.8rem; align-items:baseline; padding:.4rem 0;
    font-size:.86rem; border-bottom:1px solid var(--line); }
  .lic-itens li:last-child{ border-bottom:none; }
  .lic-itens .mod{ font-weight:700; color:var(--ink); min-width:8.5rem; }
  .lic-itens .med{ flex:1; color:#8a8f88; }
  .lic-itens .pr{ font-variant-numeric:tabular-nums; color:var(--gold); font-weight:600; }

  .lic-tab{ width:100%; border-collapse:collapse; margin-top:.9rem; }
  .lic-tab td{ padding:.55rem .5rem; border-bottom:1px solid var(--line); vertical-align:top;
    font-size:.87rem; }
  .lic-tab tr:last-child td{ border-bottom:none; }
  .lic-tab tr.off{ opacity:.5; }
  .lic-tab .med{ color:#8a8f88; }
  .lic-tab .pr{ font-variant-numeric:tabular-nums; font-weight:700; color:var(--gold);
    white-space:nowrap; text-align:right; }
  .lic-tab .ac{ text-align:right; white-space:nowrap; }
  .lic-tab .ac .btn{ margin-left:.3rem; }
  .et.destaque{ background:var(--gold-pale); color:var(--gold-deep); }
  .et.fita{ background:var(--gold); color:#fff; }


  /* ---- a prova do factor: o que ele faz, em números ---- */
  .lic-fator-prova:empty{ display:none; }
  .lic-fator-prova{ margin-top:1.1rem; border:1.5px solid var(--gold-soft); border-radius:12px;
    background:var(--gold-pale); padding:.9rem 1rem; }
  .lic-fp-tit{ font-weight:700; color:var(--ink); font-size:.88rem; }
  .lic-fp-sub{ font-size:.76rem; color:#8a8f88; margin-top:.1rem; }
  .lic-fp-linhas{ display:flex; gap:1.4rem; flex-wrap:wrap; margin:.7rem 0 .6rem; }
  .lic-fp-l{ display:flex; flex-direction:column; }
  .lic-fp-l span{ font-size:.7rem; letter-spacing:.05em; text-transform:uppercase; color:#8a8f88; }
  .lic-fp-l b{ font-size:1.05rem; color:var(--ink); font-variant-numeric:tabular-nums; }
  .lic-fp-l b.risca{ text-decoration:line-through; color:#a8ada6; font-weight:500; }
  /* Sem desconto não se risca nada: o proporcional passa a ser só a referência. */
  .lic-fp-l b.ref{ color:#8a8f88; font-weight:500; }
  .lic-fp-veredito{ font-size:.83rem; line-height:1.5; border-radius:8px; padding:.5rem .7rem; }
  .lic-fp-veredito.bom{ background:var(--ok-bg); color:var(--text); }
  .lic-fp-veredito.mau{ background:var(--danger-bg); color:var(--text); }
  .lic-fp-veredito.igual{ background:var(--warn-bg); color:var(--text); }

  @media (max-width:700px){
    .lic-form{ grid-template-columns:1fr; }
    .lic-f-c{ grid-column:span 1 !important; }
  }

  /* ---- os prazos, com o efeito do factor à vista ---- */
  .lic-prazos-lista{ display:grid; gap:.8rem;
    grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); }
  .lic-pz{ border:1.5px solid var(--line); border-radius:12px; padding:.9rem 1rem;
    background:var(--card); }
  .lic-pz.base{ border-color:var(--gold-soft); background:var(--gold-pale); }
  .lic-pz-cab{ display:flex; align-items:baseline; gap:.6rem; }
  .lic-pz-nome{ flex:1; font-weight:700; color:var(--ink); font-size:.98rem; }
  .lic-pz-fator{ font-variant-numeric:tabular-nums; font-weight:700; color:var(--gold);
    font-size:.9rem; }
  .lic-pz-res{ font-size:.78rem; color:#8a8f88; margin-top:.15rem; line-height:1.4; }
  /* A barra é o preço POR MÊS: mais curta é melhor negócio. É a única forma de
     ver, de relance, se um factor faz o que se queria que fizesse. */
  .lic-pz-barra{ position:relative; height:26px; border-radius:6px; background:var(--sand);
    margin:.6rem 0 .5rem; overflow:hidden; display:flex; align-items:center; }
  .lic-pz-barra i{ position:absolute; inset:0 auto 0 0; border-radius:6px;
    background:linear-gradient(90deg,var(--gold-soft),var(--gold-deep)); }
  .lic-pz-barra span{ position:relative; padding:0 .6rem; font-size:.78rem; font-weight:700;
    color:#fff; text-shadow:0 1px 2px rgba(0,0,0,.35); font-variant-numeric:tabular-nums; }
  .lic-pz-nums{ display:flex; gap:.9rem; flex-wrap:wrap; font-size:.76rem; color:#8a8f88; }
  .lic-pz-nums b{ color:var(--ink); font-variant-numeric:tabular-nums; }
  .lic-pz-nums b.risca{ text-decoration:line-through; color:#a8ada6; }
  .lic-pz-nums .poupa b{ color:var(--ok); }
  .lic-pz-nums .caro b{ color:var(--danger); }
  .lic-pz-ac{ display:flex; gap:.4rem; margin-top:.7rem; }

  .lic-pi-mod{ border-top:1px solid var(--line); padding:.8rem 0; }
  .lic-pi-mod:first-child{ border-top:none; }
  .lic-pi-nome{ font-weight:700; color:var(--ink); margin-bottom:.45rem; }
  .lic-pi{ display:flex; gap:.5rem; align-items:center; padding:.32rem .5rem; border-radius:8px;
    cursor:pointer; font-size:.87rem; text-transform:none; letter-spacing:normal;
    font-weight:400; margin:0; }
  /* Pela mesma razão que na montra: `width:100%` num rádio come a linha toda. */
  .lic-pi input{ width:16px; height:16px; min-width:16px; padding:0; margin:0;
    flex:none; accent-color:var(--gold); }
  .lic-pi-txt{ flex:1; min-width:0; }
  /* O preço vive na própria linha do escalão: é ali que se olha para ele. */
  .lic-pi-preco{ display:flex; align-items:center; gap:.25rem; flex:none; }
  .lic-pi-preco input{ width:6.5rem; padding:.25rem .45rem; font-size:.85rem; text-align:right;
    font-variant-numeric:tabular-nums; border:1px solid var(--line); border-radius:8px;
    background:var(--card); }
  .lic-pi-preco input:focus{ outline:none; border-color:var(--gold); box-shadow:0 0 0 2px var(--ring); }
  .lic-pi-preco small{ color:#8a8f88; font-size:.75rem; }
  .lic-pi-conta{ display:flex; gap:1.4rem; flex-wrap:wrap; margin-top:1rem; padding-top:.9rem;
    border-top:1px solid var(--line); }
  .lic-pi-conta > div{ display:flex; flex-direction:column; }
  .lic-pi-conta .r{ font-size:.7rem; letter-spacing:.06em; text-transform:uppercase; color:#8a8f88; }
  .lic-pi-conta b{ font-size:1.05rem; color:var(--ink); font-variant-numeric:tabular-nums; }
  .lic-pi-conta .bom b{ color:var(--ok); }
  .lic-pi-conta .mau b{ color:var(--warn); }
  .lic-pi:hover{ background:var(--cream); }
  .lic-pi small{ color:#8a8f88; }

  @media (max-width:640px){
    .lic-itens li{ flex-wrap:wrap; gap:.2rem .8rem; }
    .lic-itens .mod{ min-width:0; }
    .lic-tab td{ padding:.45rem .3rem; font-size:.83rem; }
  }

  /* O menu "⋯" vive em assets/estilo.css: é de duas páginas. */
  .linha-info{ display:flex; gap:1.2rem; flex-wrap:wrap; font-size:.84rem; color:#8a8f88;
               margin-bottom:1.4rem; padding:0 .2rem; }
  .segredo{ background:var(--gold-pale); border:1px dashed var(--gold-soft); border-radius:10px;
            padding:.8rem .9rem; margin-top:.9rem; font-size:.88rem; line-height:1.6; }
  .lf{ display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:.7rem; align-items:end; }
  @media (max-width:720px){ .lf{ grid-template-columns:1fr; } .cas{ grid-template-columns:auto 1fr; } .cas .ac{ grid-column:1/-1; } }

  /* ---- Verificações de preenchimento (as mesmas do formulário público) ----
     Um campo com erro acende a vermelho e diz o que falta, logo por baixo. */
  .campo{ display:flex; flex-direction:column; }
  .campo input, .campo select{ transition:border-color .15s, box-shadow .15s; }
  .campo.mau input, .campo.mau select{ border-color:var(--danger); }
  .campo.mau input:focus, .campo.mau select:focus{ box-shadow:0 0 0 3px rgba(165,71,63,.15); }
  .campo.ok input:not(:focus){ border-color:#bcd6c4; }
  .err{ display:none; color:var(--danger); font-size:.77rem; margin-top:.34rem; line-height:1.45; }
  .campo.mau .err{ display:block; }
  /* Palavra-passe: o olho para mostrar, e a força ao lado. O campo reserva
     espaço à direita para o botão não passar por cima do que se escreve. */
  .pw-wrap{ position:relative; }
  .pw-wrap input{ padding-right:4.6rem; }
  .pw-olho{ position:absolute; right:.5rem; top:50%; transform:translateY(-50%); border:0; background:none;
            cursor:pointer; color:#9aa09a; font-size:.74rem; padding:.2rem .3rem; }
  .pw-olho:hover{ color:var(--forest); }
  .pw-forca{ display:flex; align-items:center; gap:.5rem; margin-top:.4rem; }
  .pw-barras{ display:flex; gap:3px; flex:1; }
  .pw-barras i{ height:4px; flex:1; border-radius:50px; background:var(--cream); transition:background .2s; }
  .pw-forca.f1 i:nth-child(1){ background:var(--danger); }
  .pw-forca.f2 i:nth-child(-n+2){ background:var(--warn); }
  .pw-forca.f3 i:nth-child(-n+3){ background:#9a9a3c; }
  .pw-forca.f4 i{ background:var(--ok); }
  .pw-rot{ font-size:.72rem; color:#9aa09a; white-space:nowrap; min-width:4.5rem; text-align:right; }

  /* O aviso de estado da licença, no modal, veste-se do que diz. */
  .lic-estado.ok{ background:var(--ok-bg); border-color:var(--ok); border-style:solid; }
  .lic-estado.warn{ background:var(--warn-bg); border-color:var(--warn); border-style:solid; }
  .lic-estado.danger{ background:var(--danger-bg); border-color:var(--danger); border-style:solid; }
  /* No modal, os campos empilham-se em ecrã estreito. */
  @media (max-width:560px){ .modal-corpo .lf{ grid-template-columns:1fr; } }
  /* Os separadores de secção do editor completo. */
  .ed-sec{ font-family:var(--serif); color:var(--forest); font-size:1.02rem; margin:1.2rem 0 .5rem;
           padding-top:.9rem; border-top:1px solid var(--line); }
  .ed-sec:first-of-type{ border-top:0; padding-top:0; margin-top:.2rem; }
  .ed-conta{ border:1px solid var(--line); border-radius:12px; padding:.7rem .8rem; margin-bottom:.6rem; }
  .ed-conta .cab{ display:flex; align-items:center; gap:.5rem; margin-bottom:.5rem; }
  .ed-conta .ac{ display:flex; gap:.4rem; flex-wrap:wrap; }
  /* Atendimento — a cara de quem atende e a lista de perguntas. */
  .at-foto-ed{ display:flex; align-items:center; gap:.6rem; }
  .at-foto-bts{ display:flex; flex-direction:column; gap:.3rem; }
  .at-item{ border:1px solid var(--line); border-radius:12px; padding:.7rem .85rem; margin-bottom:.55rem; }
  .at-item.off{ opacity:.62; border-style:dashed; }
  .at-item-cab{ display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem; flex-wrap:wrap; }
  .at-item-cab b{ font-family:var(--serif); font-size:1rem; color:var(--ink); }
  .at-item .at-ord{ margin-left:auto; font-size:.74rem; color:#8a8f88; font-variant-numeric:tabular-nums; }
  .at-item-resp{ font-size:.86rem; color:#6c7570; line-height:1.55; margin-bottom:.55rem;
    white-space:pre-wrap; }
  .at-item .ac{ display:flex; gap:.4rem; flex-wrap:wrap; }
  /* As caixas de seleção do painel de dados (âmbitos e casamentos). */
  .dsel{ display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:.35rem .9rem; margin:.4rem 0 .9rem; }
  .dsel label{ display:flex; gap:.5rem; align-items:center; font-size:.9rem; color:var(--ink); cursor:pointer; }
  .dsel input{ width:auto; margin:0; accent-color:var(--forest); flex:none; }
  .dsel-cas{ max-height:230px; overflow:auto; border:1px solid var(--line); border-radius:10px;
             padding:.6rem .8rem; background:var(--cream); }
</style>
</head>
<body>
<?php
  // O título veste-se de quem chega: o admin vê a casa toda; o suporte tem um
  // posto simples, à volta do código; o casal vê os seus casamentos.
  if ($mandaNaCasa)      { $tit = 'Administração'; $sub = 'O estado do sistema e os casamentos que ele serve'; }
  elseif (ehSuporte())   { $tit = 'Suporte';       $sub = 'Entre num casamento com o código que o casal lhe der'; }
  else                   { $tit = 'Casamentos';    $sub = 'Os casamentos a que tem acesso'; }
  cabecalho($tit, $sub, 'plataforma');
?>

<main class="container">

<?php if (ehSuporte()): ?>
  <?php // ---- O posto do suporte: simples e ao que interessa ----
        // Nada de painel de estado da casa. O suporte não abre casamentos por
        // direito próprio: entra com o código que o casal gera e entrega, e
        // trata da sua própria conta. É só isto que precisa de ver. ?>
  <div class="painel">
    <h3>Entrar com um código do casal</h3>
    <div class="dica">O suporte não entra em casa de ninguém por direito próprio: é o casal
      que gera um código e o entrega. O código diz se pode só ver ou também corrigir,
      e o casal revoga-o quando quiser.</div>
    <div class="lf" style="grid-template-columns:1fr auto">
      <div><label for="s-codigo">Código</label>
        <input type="text" id="s-codigo" placeholder="XXXXXXXX" autocapitalize="characters"
               spellcheck="false" onkeydown="if(event.key==='Enter')entrarComCodigo()"></div>
      <div><button class="btn btn-ouro" onclick="entrarComCodigo()">Entrar</button></div>
    </div>
  </div>

  <div class="painel">
    <h3>A minha conta</h3>
    <div class="dica">Entrou como <b><?= escP(utilizadorAtual() ?? '') ?></b>. Aqui muda a sua
      própria palavra-passe — mais nada. Pelo menos 8 caracteres.</div>
    <div class="lf" style="grid-template-columns:1fr 1fr auto">
      <div><label for="sp-atual">Palavra-passe atual</label>
        <input type="password" id="sp-atual" autocomplete="current-password"></div>
      <div><label for="sp-nova">Nova palavra-passe</label>
        <input type="password" id="sp-nova" autocomplete="new-password"></div>
      <div><button class="btn" onclick="mudarMinhaSenha()">Mudar palavra-passe</button></div>
    </div>
  </div>

  <div class="toast" id="toast"></div>
  <script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
  <script src="<?= asset('assets/api.js') ?>"></script>
  <script src="<?= asset('assets/planos.js') ?>"></script><?php // Planos.politicas() na pré-visualização ?>
  <script>
  function toast(m, mau){
    const el = document.getElementById('toast');
    el.textContent = m; el.className = 'toast mostrar' + (mau ? ' erro' : '');
    setTimeout(() => el.className = 'toast', 2800);
  }
  async function entrarComCodigo(){
    const codigo = document.getElementById('s-codigo').value.trim();
    if (!codigo) return toast('Escreva o código que o casal lhe deu.', true);
    const d = await api('suporte_entrar', { method:'POST', body: JSON.stringify({ codigo }) });
    if (d && d.success) location.href = 'index.php';
  }
  async function mudarMinhaSenha(){
    const atual = document.getElementById('sp-atual').value;
    const nova  = document.getElementById('sp-nova').value;
    if (!atual || !nova) return toast('Preencha as duas palavras-passe.', true);
    if (nova.length < 8)  return toast('A nova precisa de pelo menos 8 caracteres.', true);
    const d = await api('senha_mudar', { method:'POST', body: JSON.stringify({ atual, nova }) });
    if (d && d.success){
      document.getElementById('sp-atual').value = document.getElementById('sp-nova').value = '';
      toast('Palavra-passe mudada.');
    }
  }
  </script>
</main>
</body>
</html>
<?php return; // o suporte não vê nada do painel de administração abaixo ?>
<?php endif; ?>

  <?php // As pastilhas que comandam a página: os casamentos, criar um novo, e as
        // contas administrativas. As duas últimas são do admin da casa. ?>
  <div class="filtros vista-chips" id="vista-chips" style="margin-bottom:1.2rem">
    <button class="chip on" data-vista="casamentos" onclick="verVista('casamentos')">Casamentos</button>
    <?php if ($mandaNaCasa): ?>
      <button class="chip" data-vista="novo" onclick="verVista('novo')">&#43; Novo casamento</button>
    <?php endif; ?>
    <?php if (ehAdminPlataforma()): ?>
      <button class="chip" data-vista="contas" onclick="verVista('contas')">Contas administrativas</button>
      <button class="chip" data-vista="licencas" onclick="verVista('licencas')">Licenças<span id="lic-conta-badge" class="lic-badge" style="display:none"></span></button>
      <?php // A pastilha traz o número de pedidos à espera: um casal parado à
            // porta é a coisa mais urgente desta página, e tem de se ver daqui. ?>
      <button class="chip" data-vista="dados" onclick="verVista('dados')">Gestão de Dados</button>
      <button class="chip" data-vista="registo" onclick="verVista('registo')">Registo de ações</button>
      <button class="chip" data-vista="atendimento" onclick="verVista('atendimento')">Atendimento</button>
      <button class="chip" data-vista="definicoes" onclick="verVista('definicoes')">Definições</button>
    <?php endif; ?>
  </div>

  <div id="vista-casamentos">
  <?php if ($G): ?>
    <?php // Os números que levam a algum lado são botões e levam mesmo lá:
          // um painel de contagens que não responde ao clique convida a
          // tentar e a não perceber porque nada aconteceu. ?>
    <div class="numeros">
      <button type="button" class="n" onclick="filtrarCasamentos('ativo',1)"
              title="Ver os casamentos ativos"><b><?= $G['ativos'] ?></b><span>casamentos ativos</span>
        <?php if ($G['pendentes']): ?><em><?= $G['pendentes'] ?> à espera</em><?php endif; ?></button>
      <div class="n"><b><?= $G['convites'] ?></b><span>convites</span>
        <em>em toda a casa</em></div>
      <div class="n"><b><?= $G['pessoas'] ?></b><span>pessoas convidadas</span>
        <em><?= $G['confirmadas'] ?> confirmaram</em></div>
      <div class="n"><b><?= $G['presentes'] ?></b><span>entradas registadas</span>
        <em>desde sempre</em></div>
      <?php if (ehAdminPlataforma()): ?>
        <button type="button" class="n" onclick="verVista('contas')"
                title="Ver as contas administrativas"><b><?= $G['contas_ativas'] ?></b><span>contas ativas</span>
          <em><?= $G['contas_espera'] ?> por aprovar · <?= $G['contas_suspensas'] ?> suspensas</em></button>
      <?php else: ?>
        <div class="n"><b><?= $G['contas_ativas'] ?></b><span>contas ativas</span>
          <em><?= $G['contas_espera'] ?> por aprovar · <?= $G['contas_suspensas'] ?> suspensas</em></div>
      <?php endif; ?>
      <div class="n<?= $G['codigos'] ? ' alerta' : '' ?>"><b><?= $G['codigos'] ?></b>
        <span>códigos de suporte de pé</span>
        <em><?= $G['codigos'] ? 'acesso de fora, agora' : 'nenhuma porta aberta' ?></em></div>
    </div>
    <div class="linha-info">
      <?php if ($G['proximo']): ?>
        <span>Próximo casamento: <b><?= escP($G['proximo']['nome']) ?></b>
          <?= escP(date('d/m/Y', strtotime($G['proximo']['data_evento']))) ?></span>
      <?php else: ?>
        <span>Nenhum casamento ativo com data marcada.</span>
      <?php endif; ?>
      <?php if ($G['sem_dono']): ?>
        <span class="falta"><?= $G['sem_dono'] ?> casamento(s) sem conta dos noivos</span>
      <?php endif; ?>
      <?php if ($G['suspensos']): ?>
        <a href="#" onclick="filtrarCasamentos('suspenso',1);return false"><?= $G['suspensos'] ?> suspenso(s)</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($pendentes): ?>
    <div class="painel" style="border-color:var(--warn); border-left:4px solid var(--warn)">
      <h3><?= count($pendentes) ?> registo(s) à espera</h3>
      <div class="dica">Estes casais já entram — mas só veem a sua licença, e mais nada.
        <b>Abre-se-lhes a casa decidindo o pedido de licença</b>, em
        <a href="#" onclick="verVista('licencas');return false"><b>Licenças</b></a>: aprovar o
        pedido activa o casamento e as suas contas, no mesmo gesto.</div>
      <div class="cas-lista">
        <?php foreach ($pendentes as $p): ?>
          <div class="cas">
            <div class="selo">?</div>
            <div>
              <div class="nm"><?= escP($p['nome']) ?></div>
              <div class="meta"><span>Inscrito em <?= escP(date('d/m/Y', strtotime($p['criado_em']))) ?></span>
                <?php if ((int)$p['tem_pedido']): ?>
                  <span class="et pendente">pedido à espera</span>
                <?php else: ?>
                  <span class="et">sem pedido — o casal ainda não escolheu plano</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="ac">
              <?php if ((int)$p['tem_pedido']): ?>
                <button class="btn btn-ouro btn-sm" onclick="verVista('licencas')">Ver o pedido</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>


  <div class="painel" style="margin-top:1.4rem">
    <h3>Casamentos</h3>
    <div class="dica">Por ordem do que se mexeu por último — quem abre esta página de manhã
      quer ver em cima aquilo em que andou ontem, e não o mais antigo do sistema.</div>
    <div class="lf" style="grid-template-columns:1fr auto;margin-bottom:.7rem">
      <div><input type="search" id="q-cas" placeholder="Procurar casamento ou noivos…"
                  autocomplete="off" name="procurar-casamento" oninput="carregarCasamentos()"></div>
      <div></div>
    </div>
    <div class="filtros" id="filtros-cas">
      <?php foreach (['ativo'=>'Ativos','pendente'=>'Por aprovar','suspenso'=>'Suspensos',
                      'arquivado'=>'Arquivados','todos'=>'Todos'] as $k => $rot): ?>
        <button class="chip<?= $k === 'ativo' ? ' on' : '' ?>" data-estado="<?= $k ?>"
                onclick="filtrarCasamentos('<?= $k ?>')"><?= escP($rot) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="cas-lista" id="lista-casamentos"><div class="dica">A carregar…</div></div>
  </div>
  </div><!-- /vista-casamentos -->

  <?php if ($mandaNaCasa): ?>
  <div id="vista-novo" style="display:none">
    <div class="painel">
      <h3>Novo casamento</h3>
      <div class="dica">Cria o casamento já ativo, com os seus dados e, se quiser, já com as contas
        dos noivos e do porteiro. O que ficar em branco fica no original e edita-se depois.</div>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr">
        <div><label>Nome</label><input type="text" id="n-nome" placeholder="Ex: Isabel &amp; Abednego"></div>
        <div><label>Noiva</label><input type="text" id="n-noiva" placeholder="Isabel" oninput="sugerirPorteiro()"></div>
        <div><label>Noivo</label><input type="text" id="n-noivo" placeholder="Abednego" oninput="sugerirPorteiro()"></div>
      </div>
      <div class="lf" style="grid-template-columns:repeat(4,1fr)">
        <div><label>Data</label><input type="date" id="n-data"></div>
        <div><label>Hora da festa</label><input type="time" id="n-hora"></div>
        <div><label>Convidados que espera</label><input type="number" id="n-convidados" min="1" max="5000"></div>
        <div><label>WhatsApp</label><input type="text" id="n-whatsapp" inputmode="numeric"></div>
      </div>
      <div class="lf" style="grid-template-columns:2fr 1fr">
        <div><label>Local da festa</label><input type="text" id="n-local"></div>
        <div><label>Cidade / região</label><input type="text" id="n-cidade"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr">
        <div><label>Orçamento total <small style="color:#8a8f88">· opcional, serve de teto no Orçamento</small></label>
          <input type="text" id="n-orcamento" class="campo-moeda" inputmode="decimal" placeholder="ex.: 2 500 000,00"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr">
        <div><label>Local da festa · Google Maps</label>
          <input type="url" id="n-maps" data-mapa data-mapa-local="n-local" placeholder="https://maps.app.goo.gl/…"></div>
      </div>
      <div class="dica" style="margin:.9rem 0 .4rem">As cerimónias, se as houver.</div>
      <div class="lf" style="grid-template-columns:repeat(4,1fr)">
        <div><label>Civil · hora</label><input type="time" id="n-civil-hora"></div>
        <div><label>Civil · local</label><input type="text" id="n-civil-local"></div>
        <div><label>Religiosa · hora</label><input type="time" id="n-religiosa-hora"></div>
        <div><label>Religiosa · local</label><input type="text" id="n-religiosa-local"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr">
        <div><label>Civil · Google Maps</label>
          <input type="url" id="n-civil-maps" data-mapa data-mapa-local="n-civil-local" placeholder="https://maps.app.goo.gl/…"></div>
        <div><label>Religiosa · Google Maps</label>
          <input type="url" id="n-religiosa-maps" data-mapa data-mapa-local="n-religiosa-local" placeholder="https://maps.app.goo.gl/…"></div>
      </div>

      <div class="dica" style="margin:1.1rem 0 .4rem"><b>A licença de uso.</b> Quanto tempo o casamento
        fica disponível. Expirada, é suspenso sozinho.</div>
      <div class="lf" style="grid-template-columns:1fr 1fr auto;align-items:center">
        <div><label>Período de licença</label>
          <select id="n-licenca" onchange="licencaMudou()">
            <option value="0">Sem limite</option>
            <option value="3">3 meses</option>
            <option value="6" selected>6 meses</option>
            <option value="12">12 meses</option>
            <option value="outro">Outro…</option>
          </select></div>
        <div id="n-licenca-outro" style="display:none"><label>Meses</label>
          <input type="number" id="n-licenca-meses" min="1" max="120" placeholder="ex.: 18"></div>
        <div><label style="display:inline-flex;gap:.4rem;align-items:center;font-weight:400">
          <input type="checkbox" id="n-licenca-ativa" checked style="width:auto;margin:0">
          Iniciar com a licença já ativa</label></div>
      </div>

      <div class="dica" style="margin:1.1rem 0 .4rem"><b>Conta dos noivos</b> <small style="color:#8a8f88">· opcional — quem gere o casamento. Deixe em branco para não criar já.</small></div>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr;align-items:start">
        <div class="campo"><label for="n-noivos-email">Email dos noivos</label>
          <input type="email" id="n-noivos-email" autocomplete="off" autocapitalize="none" spellcheck="false">
          <div class="err"></div></div>
        <div class="campo"><label for="n-noivos-senha">Palavra-passe</label>
          <div class="pw-wrap"><input type="password" id="n-noivos-senha" autocomplete="new-password" spellcheck="false" placeholder="em branco = gerada">
            <button type="button" class="pw-olho" id="olho-ne" onclick="verSenha('n-noivos-senha','olho-ne')" aria-label="Mostrar a palavra-passe">mostrar</button></div>
          <div class="pw-forca" id="forca-ne" aria-hidden="true"><span class="pw-barras"><i></i><i></i><i></i><i></i></span><span class="pw-rot" id="rot-ne"></span></div>
          <div class="err"></div></div>
        <div class="campo"><label for="n-noivos-confirmar">Repetir</label>
          <div class="pw-wrap"><input type="password" id="n-noivos-confirmar" autocomplete="new-password" spellcheck="false" placeholder="repita a palavra-passe">
            <button type="button" class="pw-olho" id="olho-nc" onclick="verSenha('n-noivos-confirmar','olho-nc')" aria-label="Mostrar a palavra-passe">mostrar</button></div>
          <div class="err"></div></div>
      </div>
      <div class="dica" style="margin:.9rem 0 .4rem"><b>Conta do porteiro</b> <small style="color:#8a8f88">· opcional — regista as entradas</small></div>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr;align-items:start">
        <div class="campo"><label for="n-porteiro-email">Email (utilizador) do porteiro</label>
          <input type="email" id="n-porteiro-email" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="porta-…">
          <div class="err"></div></div>
        <div class="campo"><label for="n-porteiro-senha">Palavra-passe</label>
          <div class="pw-wrap"><input type="password" id="n-porteiro-senha" autocomplete="new-password" spellcheck="false" placeholder="em branco = gerada">
            <button type="button" class="pw-olho" id="olho-pe" onclick="verSenha('n-porteiro-senha','olho-pe')" aria-label="Mostrar a palavra-passe">mostrar</button></div>
          <div class="pw-forca" id="forca-pe" aria-hidden="true"><span class="pw-barras"><i></i><i></i><i></i><i></i></span><span class="pw-rot" id="rot-pe"></span></div>
          <div class="err"></div></div>
        <div class="campo"><label for="n-porteiro-confirmar">Repetir</label>
          <div class="pw-wrap"><input type="password" id="n-porteiro-confirmar" autocomplete="new-password" spellcheck="false" placeholder="repita a palavra-passe">
            <button type="button" class="pw-olho" id="olho-pc" onclick="verSenha('n-porteiro-confirmar','olho-pc')" aria-label="Mostrar a palavra-passe">mostrar</button></div>
          <div class="err"></div></div>
      </div>

      <div class="fim" style="display:flex;gap:.6rem;align-items:center;margin-top:1.1rem">
        <button class="btn btn-ouro" onclick="criar()">Criar casamento</button>
        <span class="dica" style="margin:0">Só os nomes são obrigatórios. As senhas geradas mostram-se uma vez.</span>
      </div>
      <div class="segredo" id="n-resultado" style="display:none"></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (ehAdminPlataforma()): ?>
    <div id="vista-contas" style="display:none">
    <div class="painel">
      <h3>Contas administrativas</h3>
      <div class="dica">As contas de <b>administração</b> e <b>suporte</b> da casa. As contas de
        noivos e porteiro vivem em cada casamento — abra o casamento e veja-as em <b>Gestão</b>.
        Uma conta <b>suspensa</b> não entra, e recebe a mesma mensagem de senha errada.</div>
      <details class="dobra dobra-dentro" id="d-conta">
      <summary><span class="mais">+</span> Nova conta administrativa
        <small>suporte ou administração da plataforma</small></summary>
      <div class="lf" style="grid-template-columns:2fr 2fr 1fr auto;margin-bottom:.2rem;align-items:start">
        <div class="campo"><label for="c-nome">Nome</label>
          <input type="text" id="c-nome" placeholder="Nome de quem usa a conta"><div class="err"></div></div>
        <div class="campo"><label for="c-email">Email</label>
          <input type="email" id="c-email" autocomplete="off" autocapitalize="none" spellcheck="false"><div class="err"></div></div>
        <div><label for="c-tipo">Tipo</label>
          <select id="c-tipo" onchange="tipoMudou()">
            <option value="suporte">Suporte</option>
            <option value="admin">Admin da plataforma</option>
          </select></div>
        <div><button class="btn btn-ouro" onclick="criarConta()">Criar conta</button></div>
      </div>
      <div class="dica" id="nota-tipo" style="margin:.4rem 0 .2rem">A senha é gerada aqui e mostrada uma vez.</div>
      </details>

      <div class="lf" style="grid-template-columns:1fr auto;margin:.8rem 0 .6rem;border:0;padding:0">
        <div><input type="search" id="q-conta" placeholder="Procurar por email ou nome…"
                    autocomplete="off" name="procurar-conta" oninput="carregarContas()"></div>
        <div></div>
      </div>
      <div id="lista-contas"><div class="dica">A carregar…</div></div>
      <div class="segredo" id="senha-reposta" style="display:none"></div>
    </div>
    </div><!-- /vista-contas -->

    <div id="vista-licencas" style="display:none">
    <div class="filtros" style="margin-bottom:1rem">
      <button class="chip on" data-lic="pedidos" onclick="licVista('pedidos')">Pedidos</button>
      <button class="chip" data-lic="precario" onclick="licVista('precario')">Preçário</button>
      <button class="chip" data-lic="pacotes" onclick="licVista('pacotes')">Pacotes</button>
      <button class="chip" data-lic="prazos" onclick="licVista('prazos')">Prazos</button>
      <button class="chip" data-lic="politicas" onclick="licVista('politicas')">Políticas</button>
    </div>

    <?php // ---- pedidos ---- ?>
    <div id="lic-v-pedidos">
      <div class="painel">
        <h3>Pedidos de licença</h3>
        <div class="dica">Chegam em duas famílias, e não se leem da mesma maneira: um
          <b>pedido inicial</b> é um casal à porta, que ainda não entrou em lado nenhum; uma
          <b>actualização</b> é um casal que já trabalha aqui e quer mais. Aprovar concede os
          módulos pedidos e, se o casamento ainda estava à espera, abre-o com as suas contas.</div>
        <div class="filtros" style="margin:.6rem 0 .2rem">
          <button class="chip on" data-pest="pendente" onclick="licPedidos('pendente')">À espera</button>
          <button class="chip" data-pest="aprovado" onclick="licPedidos('aprovado')">Aprovados</button>
          <button class="chip" data-pest="recusado" onclick="licPedidos('recusado')">Recusados</button>
          <button class="chip" data-pest="todos" onclick="licPedidos('todos')">Todos</button>
        </div>
      </div>
      <div id="lic-pedidos-lista"><div class="dica">A carregar…</div></div>
    </div>

    <?php // ---- preçário: módulos e escalões ---- ?>
    <div id="lic-v-precario" style="display:none">
      <div class="painel">
        <h3>Preçário</h3>
        <div class="dica">Os módulos que se vendem, e as medidas em que se vendem. O preço é
          sempre do <b>escalão</b> — é isso que permite vender o mesmo recurso em tamanhos
          diferentes. Um escalão que já sustente licenças não se apaga: desliga-se.</div>
      </div>
      <div id="lic-precario"><div class="dica">A carregar…</div></div>
    </div>

    <?php // ---- pacotes ---- ?>
    <div id="lic-v-pacotes" style="display:none">
      <div class="painel">
        <h3>Pacotes</h3>
        <div class="dica">Conjuntos de escalões com preço próprio. A poupança que o casal vê é
          calculada — a diferença entre o preço do pacote e a soma dos escalões à peça — por isso
          um pacote mais caro do que as suas partes não engana ninguém: aparece sem poupança.</div>
        <div class="fim" style="margin-top:.6rem">
          <button class="btn btn-ouro btn-sm" onclick="licPacoteEditar(0)">&#43; Novo pacote</button>
        </div>
      </div>
      <div id="lic-pacotes"><div class="dica">A carregar…</div></div>
    </div>

    <?php // ---- prazos ---- ?>
    <div id="lic-v-prazos" style="display:none">
      <div class="painel">
        <h3>Prazos de licença</h3>
        <div class="dica">O preço depende de <b>quanto tempo</b> o casal quer a plataforma. Os
          preços escritos no preçário são os do prazo de <b>factor 1</b>; os outros multiplicam-nos.
          Factores <b>sublineares</b> (12 meses a 1,8 e não a 2,0) fazem o preço por mês descer com
          o compromisso — é o que torna o prazo longo um bom negócio, e não um castigo.</div>
        <div class="fim" style="margin-top:.6rem">
          <button class="btn btn-ouro btn-sm" onclick="licPrazoEditar(0)">&#43; Novo prazo</button>
        </div>
      </div>
      <div id="lic-prazos"><div class="dica">A carregar…</div></div>
    </div>

    <?php // ---- políticas ---- ?>
    <div id="lic-v-politicas" style="display:none">
      <div class="painel">
        <h3>Políticas de utilização</h3>
        <div class="dica">O texto que o casal lê e aceita ao pedir a licença. Guardar publica uma
          <b>versão nova</b> e a anterior fica: é a prova do texto a que cada casal disse que sim
          (Lei n.º 22/11, art. 5.º a) — consentimento informado). Marcação: <code>## </code> título,
          <code>- </code> alínea, linha em branco separa parágrafos.</div>
        <div class="campo" style="margin-top:.8rem">
          <label for="lic-pol-titulo">Título</label>
          <input type="text" id="lic-pol-titulo" maxlength="160">
        </div>
        <div class="campo">
          <label for="lic-pol-corpo">Texto</label>
          <textarea id="lic-pol-corpo" rows="22" style="width:100%;font-family:var(--mono,monospace);
            font-size:.83rem;line-height:1.6;border:1.5px solid var(--line);border-radius:12px;
            padding:.8rem;background:var(--card);color:var(--text);resize:vertical"></textarea>
        </div>
        <div class="fim" style="flex-wrap:wrap">
          <span class="dica" style="flex:1;margin:0" id="lic-pol-versao"></span>
          <button class="btn btn-sm" onclick="licPolPrever()">Pré-ver</button>
          <button class="btn btn-ouro btn-sm" onclick="licPolGuardar()">Publicar versão nova</button>
        </div>
      </div>
    </div>
    </div>

    <div id="vista-dados" style="display:none">
    <div class="painel">
      <h3>Gestão de Dados</h3>
      <div class="dica">Um sítio só para levar a casa num ficheiro, trazê-la de volta, ou
        <b>apagar</b>. Ou de uma vez — <b>tudo o que há no sistema</b> —, ou <b>só o que assinalar</b>
        na escolha por âmbitos, logo a seguir.</div>

      <h4 class="ed-sec">Tudo o que há no sistema</h4>
      <div class="dica">Sem escolher nada: os casamentos todos (com as suas listas, mesas, versões
        e orçamentos), os modelos da casa e as contas.</div>
      <div class="dsel" style="margin:.2rem 0 .5rem">
        <label><input type="checkbox" id="dados-tudo-senhas"> Contas <b>com senhas</b>
          <small style="color:#8a8f88">· só na exportação</small></label>
      </div>
      <div class="fim" style="flex-wrap:wrap;margin:.2rem 0 .4rem">
        <button class="btn btn-ouro" onclick="exportarSistemaTudo()">Exportar tudo</button>
        <button class="btn" onclick="document.getElementById('dados-ficheiro-tudo').click()">Importar tudo de ficheiro…</button>
        <input type="file" id="dados-ficheiro-tudo" accept=".json,application/json" style="display:none" onchange="importarSistemaTudo()">
        <button class="btn perigo" onclick="apagarSistemaTudo()">Apagar tudo</button>
      </div>
      <div class="porcima" style="margin:.2rem 0 1.2rem"><b>Apagar tudo</b> esvazia o sistema:
        apaga TODOS os casamentos por inteiro, os modelos personalizados (ficam os de origem) e as
        contas — a sua nunca. Não se desfaz: exporte primeiro.</div>

      <h4 class="ed-sec">Ou só uma parte</h4>
      <div class="dsel" id="dados-inc">
        <label><input type="checkbox" value="casamentos" checked onchange="dadosCasToggle()"> Casamentos</label>
        <label><input type="checkbox" value="modelos"> Modelos da casa</label>
        <label><input type="checkbox" value="contas_casamento"> Contas de casamento <small style="color:#8a8f88">· noivos e porteiros</small></label>
        <label><input type="checkbox" value="contas_admin"> Contas administrativas <small style="color:#8a8f88">· admin e suporte</small></label>
        <label><input type="checkbox" id="dados-senhas"> Contas <b>com senhas</b> <small style="color:#8a8f88">· só na exportação</small></label>
      </div>

      <div id="dados-cas-bloco">
        <div class="dica" style="display:flex;gap:.6rem;align-items:center;margin:.2rem 0 .4rem;flex-wrap:wrap">
          <span>Quais casamentos? <small>Vazio = todos, na exportação.</small></span>
          <button class="btn btn-sm" onclick="dadosCasTodos(true)">Todos</button>
          <button class="btn btn-sm" onclick="dadosCasTodos(false)">Nenhum</button>
        </div>
        <div class="dsel dsel-cas" id="dados-cas-lista"><div class="dica">A carregar…</div></div>
        <div class="dica" style="display:flex;gap:1rem;align-items:center;margin:.5rem 0 .2rem;flex-wrap:wrap">
          <span>Ao <b>Apagar</b>, os casamentos escolhidos:</span>
          <label><input type="radio" name="cas-modo" value="esvaziar" checked> Esvaziar
            <small style="color:#8a8f88">· fica o casamento, sem os dados</small></label>
          <label><input type="radio" name="cas-modo" value="apagar"> Apagar
            <small style="color:#8a8f88">· remove o casamento por inteiro</small></label>
        </div>
      </div>

      <div class="fim" style="flex-wrap:wrap;margin-top:1rem">
        <button class="btn btn-ouro" onclick="exportarDados()">Exportar selecionados</button>
        <button class="btn" onclick="document.getElementById('dados-ficheiro').click()">Importar de ficheiro…</button>
        <input type="file" id="dados-ficheiro" accept=".json,application/json" style="display:none" onchange="importarDados()">
        <button class="btn perigo" onclick="apagarDados()">Apagar</button>
      </div>
      <div class="porcima" style="margin-top:.8rem">
        <b>Exportar</b> descarrega um ficheiro <code>.json</code>. <b>Importar</b> traz os casamentos
        <b>como novos</b> (não substitui nada), os modelos e as contas que ainda não existam.
        <b>Apagar</b> elimina dados: os modelos personalizados (ficam os de origem),
        as contas de casamento e/ou as administrativas (a sua nunca), e — conforme escolher —
        <b>esvazia</b> ou <b>apaga por inteiro</b> os casamentos assinalados. Não se desfaz.</div>
      <div class="segredo" id="dados-resultado" style="display:none"></div>
    </div>
    </div><!-- /vista-dados -->

    <?php // ===== Registo de ações (auditoria) — só admin ===== ?>
    <div id="vista-registo" style="display:none">
    <div class="painel">
      <h3>Registo de ações</h3>
      <div class="dica">O rasto do que se faz na casa — em todos os casamentos e na plataforma. Filtre e
        pesquise. (Os noivos veem, no seu Painel, apenas o histórico do próprio casamento.)</div>
      <div class="lf" style="grid-template-columns:1.5fr 1.5fr 1fr 1fr auto;align-items:end;margin-top:.6rem">
        <div class="campo"><label>Casamento</label>
          <select id="aud-casamento"><option value="todos">Todos</option><option value="0">Plataforma</option></select></div>
        <div class="campo"><label>Ação</label>
          <select id="aud-accao"><option value="">Todas</option></select></div>
        <div class="campo"><label>De</label><input type="date" id="aud-de"></div>
        <div class="campo"><label>Até</label><input type="date" id="aud-ate"></div>
        <div><button class="btn btn-ouro" onclick="auditarFiltrar()">Filtrar</button></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr auto;align-items:end;margin-top:.5rem">
        <div><input type="search" id="aud-q" placeholder="Pesquisar por email, alvo ou detalhe…" autocomplete="off"
                    name="pesq-registo" onkeydown="if(event.key==='Enter')auditarFiltrar()"></div>
        <div><button class="btn btn-fantasma" onclick="auditarLimpar()">Limpar filtros</button></div>
      </div>
      <div id="aud-total" class="dica" style="margin-top:.8rem"></div>
      <div id="aud-resultado"><div class="dica">A carregar…</div></div>
      <div style="text-align:center;margin-top:1rem">
        <button class="btn btn-fantasma" id="aud-mais" style="display:none" onclick="auditarMais()">Ver mais</button></div>
    </div>
    </div><!-- /vista-registo -->

    <?php // ===== Definições do sistema — só admin ===== ?>
    <style>
      .temas{ display:grid; grid-template-columns:repeat(auto-fill,minmax(235px,1fr)); gap:.8rem; margin:.3rem 0 .2rem; }
      .tema-op{ display:flex; flex-direction:column; gap:.5rem; border:1.5px solid var(--line); border-radius:12px;
        padding:.9rem 1rem; cursor:pointer; background:var(--card); position:relative; transition:.15s; }
      .tema-op:hover{ border-color:var(--gold-soft); }
      .tema-op.on{ border-color:var(--gold-soft); box-shadow:0 0 0 3px var(--ring); }
      .tema-op input{ position:absolute; top:.7rem; right:.7rem; width:auto; }
      .tema-amostra{ display:flex; gap:5px; }
      .tema-amostra i{ width:30px; height:20px; border-radius:5px; display:block; border:1px solid rgba(0,0,0,.10); }
      .tema-nome{ font-weight:600; color:var(--ink); }
      .tema-desc{ font-size:.8rem; color:#8a8f88; line-height:1.4; }
      #aud-tabela{ width:100%; border-collapse:collapse; font-size:.86rem; }
      #aud-tabela th{ text-align:left; font-size:.7rem; letter-spacing:.06em; text-transform:uppercase;
        color:#8a8f88; padding:.5rem .6rem; border-bottom:1px solid var(--line); }
      #aud-tabela td{ padding:.5rem .6rem; border-bottom:1px solid var(--line); vertical-align:top; }
      #aud-tabela .a-accao{ font-family:var(--mono,monospace); font-weight:600; color:var(--gold); white-space:nowrap; }
      #aud-tabela .a-quando{ white-space:nowrap; color:#8a8f88; }
    </style>
    <div id="vista-definicoes" style="display:none">
    <div class="painel">
      <h3>Definições do sistema</h3>
      <div class="dica">O <b>tema visual</b> é da casa — só o admin o muda, e vale para toda a gente.
        Escolha um e guarde.</div>
      <h4 class="ed-sec">Tema visual</h4>
      <div class="temas" id="temas">
        <?php
          $temaAtualDef = temaSistema();
          $previews = [
            'niras'    => ['#16283A', '#63B22B', '#F6F8F4', 'Azul-noite + verde institucional.'],
            'classico' => ['#2C4536', '#B4864A', '#FBF8F1', 'Verde-floresta, dourado e marfim.'],
            'azul'     => ['#123C63', '#2E86C8', '#F4F7FB', 'Azul corporativo, claro.'],
            'escuro'   => ['#0E1B25', '#8AD24A', '#17232C', 'Grafite escuro, acento verde.'],
          ];
          foreach (temasDisponiveis() as $chave => $rot):
            [$c1, $c2, $c3, $desc] = $previews[$chave] ?? ['#888','#888','#eee',''];
            $on = $chave === $temaAtualDef; ?>
        <label class="tema-op<?= $on ? ' on' : '' ?>">
          <input type="radio" name="tema" value="<?= $chave ?>" <?= $on ? 'checked' : '' ?> onchange="marcarTema(this)">
          <span class="tema-amostra"><i style="background:<?= $c1 ?>"></i><i style="background:<?= $c2 ?>"></i><i style="background:<?= $c3 ?>"></i></span>
          <span class="tema-nome"><?= escP($rot) ?><?= $chave === 'niras' ? ' <small style="color:#8a8f88">· padrão</small>' : '' ?></span>
          <span class="tema-desc"><?= escP($desc) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="fim" style="margin-top:1rem">
        <button class="btn btn-ouro" onclick="guardarTema()">Guardar tema</button>
        <span class="estado" id="tema-estado"></span>
      </div>
      <div class="porcima" style="margin-top:.8rem">Aplica-se a todas as páginas e a todos os utilizadores —
        entrada, inscrição e área de gestão. A mudança fica visível no carregamento seguinte de cada página.</div>
    </div>
    </div><!-- /vista-definicoes -->

    <?php // ---- Atendimento: a caixa de perguntas das páginas públicas ---- ?>
    <div id="vista-atendimento" style="display:none">
    <div class="painel">
      <h3>Atendimento das páginas públicas</h3>
      <div class="dica">Quem chega à <b>entrada</b> ou à <b>inscrição</b> com uma dúvida não tem
        por onde a pôr: fecha a página e vai-se embora, e nunca se fica a saber porquê. Esta caixa
        aparece ao canto dessas duas páginas com as perguntas já feitas e as respostas já escritas
        — não é uma conversa a sério, e não finge sê-lo.</div>

      <label style="margin:.9rem 0 .2rem;display:inline-flex;align-items:center;gap:.5rem;
                    font-weight:600;text-transform:none;letter-spacing:0;font-size:.95rem">
        <input type="checkbox" id="at-ativo" style="width:auto;margin:0">
        Mostrar a caixa nas páginas públicas
      </label>
      <div class="porcima" style="margin:0 0 1rem">Desligada, não aparece botão nenhum — e a
        página não pede sequer estes dados ao servidor.</div>

      <h4 class="ed-sec">Quem atende</h4>
      <div class="dica">O nome e a cara que aparecem na caixa. Pode ser uma pessoa da equipa ou
        simplesmente «Atendimento» — o que não pode é prometer alguém que não existe do outro lado.</div>
      <div class="lf" style="grid-template-columns:auto 1.4fr 1.4fr">
        <div>
          <label>Fotografia</label>
          <div class="at-foto-ed">
            <span class="at-av at-av-g" id="at-foto-pre"></span>
            <div class="at-foto-bts">
              <input type="file" id="at-foto-f" accept="image/jpeg,image/png,image/webp" hidden
                     onchange="atFotoEnviar()">
              <button class="btn btn-sm" type="button" onclick="document.getElementById('at-foto-f').click()">Escolher…</button>
              <button class="btn btn-sm" type="button" id="at-foto-tirar" onclick="atFotoTirar()">Tirar</button>
            </div>
          </div>
        </div>
        <div class="campo"><label for="at-nome">Nome <span class="req">*</span></label>
          <input type="text" id="at-nome" maxlength="80" placeholder="Ex: Atendimento">
          <div class="err"></div></div>
        <div><label for="at-cargo">Função <small style="color:#8a8f88">· opcional</small></label>
          <input type="text" id="at-cargo" maxlength="80" placeholder="Ex: Gestão de Convidados"></div>
      </div>

      <div style="margin-top:.9rem"><label for="at-saudacao">Mensagem de boas-vindas</label>
        <textarea id="at-saudacao" rows="3" maxlength="600"
                  placeholder="A primeira coisa que se lê ao abrir a caixa."></textarea></div>

      <h4 class="ed-sec" style="margin-top:1.4rem">Formas de contacto</h4>
      <div class="dica">Aparecem no fim da caixa. O que ficar em branco não aparece — não se
        inventa um contacto que não existe.</div>
      <div class="lf" style="grid-template-columns:1fr 1fr">
        <div><label for="at-telefone">Telefone</label>
          <input type="text" id="at-telefone" maxlength="40" placeholder="+244 …"></div>
        <div><label for="at-whatsapp">WhatsApp</label>
          <input type="text" id="at-whatsapp" maxlength="40" placeholder="+244 …"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr;margin-top:.6rem">
        <div class="campo"><label for="at-email">Email</label>
          <input type="email" id="at-email" maxlength="120" autocapitalize="none" spellcheck="false">
          <div class="err"></div></div>
        <div><label for="at-horario">Horário</label>
          <input type="text" id="at-horario" maxlength="120" placeholder="Segunda a sexta, das 9h às 17h"></div>
      </div>

      <h4 class="ed-sec" style="margin-top:1.4rem">Chat ao vivo <small style="color:#8a8f88">· encaixe pronto</small></h4>
      <div class="dica">A caixa responde ao que se repete, e isso resolve a maioria — mas não fala
        com ninguém em tempo real. Quando quiserem uma ferramenta dessas (Tawk, Crisp, Chatwoot, o
        que for), é aqui que ela entra. Enquanto estiver em <b>nenhum</b>, as páginas públicas não
        carregam nada de fora e não aparece botão nenhum.</div>
      <div class="lf" style="grid-template-columns:1fr 2fr">
        <div><label for="at-chat-modo">Ferramenta</label>
          <select id="at-chat-modo" onchange="atChatModo()">
            <option value="nenhum">Nenhuma — só as perguntas frequentes</option>
            <option value="script">Script de um fornecedor</option>
          </select></div>
        <div class="campo"><label for="at-chat-script">Endereço do script</label>
          <input type="url" id="at-chat-script" maxlength="400" autocapitalize="none" spellcheck="false"
                 placeholder="https://…">
          <div class="err"></div></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 2fr;margin-top:.6rem" id="at-chat-extra">
        <div><label for="at-chat-rotulo">Texto do botão</label>
          <input type="text" id="at-chat-rotulo" maxlength="60" placeholder="Falar com uma pessoa"></div>
        <div class="porcima" style="align-self:end;margin:0">
          <b>Um script de fora corre nas vossas páginas de entrada e de inscrição com todos os
          poderes delas.</b> Ponha aqui só o de um fornecedor em que confie. Tem de ser
          <code>https://</code>, e só se carrega quando alguém abrir a caixa.
        </div>
      </div>
      <div class="porcima" style="margin-top:.6rem">Falta a cola que diz à caixa como abrir a
        janela do fornecedor — está documentada no topo de <code>assets/atendimento.js</code>
        (<code>Atendimento.registarAoVivo</code>). Sem ela, o botão avisa que o chat não está
        disponível e deixa os contactos, em vez de prometer o que não cumpre.</div>

      <div class="fim" style="margin-top:1.1rem">
        <button class="btn btn-ouro" onclick="atGuardar()">Guardar atendimento</button>
        <span class="estado" id="at-estado"></span>
      </div>
    </div>

    <div class="painel">
      <h3>Perguntas e respostas</h3>
      <div class="dica">São estas que a caixa oferece, por esta ordem. Uma pergunta <b>desligada</b>
        fica guardada mas não aparece a ninguém.</div>
      <div id="at-lista"><div class="dica">A carregar…</div></div>
      <div class="fim" style="margin-top:1rem">
        <button class="btn" onclick="atNovaPergunta()">&#43; Nova pergunta</button>
      </div>
    </div>
    </div><!-- /vista-atendimento -->
  <?php endif; ?>
</main>

<?php // ---- Modal: gerir a licença de um casamento ---- ?>
<div class="overlay" id="ov-licenca">
  <div class="modal">
    <div class="modal-topo">
      <h3>Licença · <span id="lic-nome"></span></h3>
      <button class="fechar" onclick="fecharModal('ov-licenca')">&times;</button>
    </div>
    <div class="modal-corpo">
      <div class="segredo lic-estado" id="lic-estado" style="margin-top:0"></div>

      <?php // ---- o QUÊ: os módulos que a licença abre ---- ?>
      <h4 class="ed-sec" style="margin-top:1.1rem">O que a licença abre</h4>
      <div class="dica">Um escalão por módulo. O que ficar em «não incluído» fecha-se ao casal —
        os dados não se apagam, só deixam de ter porta.</div>
      <div id="lic-mods"><div class="dica">A carregar…</div></div>

      <?php // ---- o ATÉ QUANDO: o prazo ---- ?>
      <h4 class="ed-sec" style="margin-top:1.3rem">Até quando</h4>
      <div class="dica">Quanto tempo o casamento fica disponível. Expirada, é suspenso sozinho —
        e as contas que só dele dependem param com ele.</div>
      <div class="lf" style="grid-template-columns:1fr 1fr">
        <div><label for="lic-periodo">Período de licença</label>
          <select id="lic-periodo" onchange="licPeriodoMudou()">
            <option value="0">Sem limite</option>
            <option value="3">3 meses</option>
            <option value="6">6 meses</option>
            <option value="12">12 meses</option>
            <option value="outro">Outro…</option>
          </select></div>
        <div id="lic-outro" style="display:none"><label for="lic-meses">Meses</label>
          <input type="number" id="lic-meses" min="1" max="120" placeholder="ex.: 18"></div>
      </div>
      <label id="lic-reiniciar-linha" style="display:flex;gap:.5rem;align-items:center;font-weight:400;margin-top:.9rem">
        <input type="checkbox" id="lic-reiniciar" checked style="width:auto;margin:0">
        <span id="lic-reiniciar-rot">Reiniciar o relógio a contar de hoje</span></label>
      <div class="dica" style="margin:.4rem 0 0">Sem esta opção, o período fica guardado mas o relógio
        não é mexido — serve para corrigir o número de meses sem dar tempo novo.</div>
      <div class="fim" style="display:flex;gap:.6rem;justify-content:flex-end;margin-top:1.2rem">
        <button class="btn" onclick="fecharModal('ov-licenca')">Cancelar</button>
        <button class="btn btn-ouro" onclick="guardarLicenca()">Guardar licença</button>
      </div>
      <div class="dica" style="margin-top:.8rem">Para <b>revogar</b> a licença por incumprimento
        das políticas, use «Revogar licença…» no menu do casamento: pede um motivo, que o casal lê.
      </div>
    </div>
  </div>
</div>

<?php // ---- Modal: editar todos os dados de um casamento ---- ?>
<div class="overlay" id="ov-editar">
  <div class="modal" style="max-width:820px">
    <div class="modal-topo">
      <h3>Editar · <span id="ed-nome-topo"></span></h3>
      <button class="fechar" onclick="fecharModal('ov-editar')">&times;</button>
    </div>
    <div class="modal-corpo">
      <input type="hidden" id="ed-id">
      <h4 class="ed-sec">Identidade</h4>
      <div class="lf" style="grid-template-columns:2fr 1fr 1fr">
        <div><label for="ed-cnome">Nome do casamento</label><input type="text" id="ed-cnome"></div>
        <div><label for="ed-noiva">Noiva</label><input type="text" id="ed-noiva"></div>
        <div><label for="ed-noivo">Noivo</label><input type="text" id="ed-noivo"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="ed-data">Data</label><input type="date" id="ed-data"></div>
        <div><label for="ed-hora">Hora da festa</label><input type="time" id="ed-hora"></div>
        <div><label for="ed-convidados">Convidados que espera</label><input type="number" id="ed-convidados" min="1" max="5000"></div>
      </div>

      <h4 class="ed-sec">O evento</h4>
      <div class="lf" style="grid-template-columns:2fr 1fr">
        <div><label for="ed-local">Local da festa</label><input type="text" id="ed-local"></div>
        <div><label for="ed-cidade">Cidade / região</label><input type="text" id="ed-cidade"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr">
        <div><label for="ed-whatsapp">WhatsApp</label><input type="text" id="ed-whatsapp" inputmode="numeric"></div>
        <div><label for="ed-orcamento">Orçamento total <small style="color:#8a8f88">· opcional</small></label>
          <input type="text" id="ed-orcamento" class="campo-moeda" inputmode="decimal" placeholder="ex.: 2 500 000,00"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr">
        <div><label for="ed-maps">Local da festa · Google Maps</label>
          <input type="url" id="ed-maps" data-mapa data-mapa-local="ed-local" placeholder="https://maps.app.goo.gl/…"></div>
      </div>
      <div class="lf" style="grid-template-columns:2fr 1fr">
        <div><label for="ed-venue">Título do local <small style="color:#8a8f88">· ex.: «Copo d’água»</small></label>
          <input type="text" id="ed-venue"></div>
        <div></div>
      </div>
      <div class="dica" style="margin:.6rem 0 .3rem">As cerimónias, se as houver.</div>
      <div class="lf" style="grid-template-columns:1fr 2fr 1fr 2fr;align-items:start">
        <div><label for="ed-civil-hora">Civil · hora</label><input type="time" id="ed-civil-hora"></div>
        <div><label for="ed-civil-local">Civil · local</label><input type="text" id="ed-civil-local"></div>
        <div><label for="ed-religiosa-hora">Religiosa · hora</label><input type="time" id="ed-religiosa-hora"></div>
        <div><label for="ed-religiosa-local">Religiosa · local</label><input type="text" id="ed-religiosa-local"></div>
      </div>
      <div class="lf" style="grid-template-columns:1fr 1fr;align-items:start">
        <div><label for="ed-civil-maps">Civil · Google Maps</label>
          <input type="url" id="ed-civil-maps" data-mapa data-mapa-local="ed-civil-local" placeholder="https://maps.app.goo.gl/…"></div>
        <div><label for="ed-religiosa-maps">Religiosa · Google Maps</label>
          <input type="url" id="ed-religiosa-maps" data-mapa data-mapa-local="ed-religiosa-local" placeholder="https://maps.app.goo.gl/…"></div>
      </div>
      <div class="fim" style="display:flex;gap:.6rem;align-items:center;margin:.6rem 0 0">
        <button class="btn btn-ouro" onclick="guardarDadosCasamento()">Guardar dados</button>
        <span class="dica" style="margin:0">A identidade e o evento. As contas geram-se abaixo.</span>
      </div>

      <h4 class="ed-sec">Contas associadas</h4>
      <div class="dica" style="margin:-.3rem 0 .5rem">Os <b>noivos</b> gerem o casamento; o <b>porteiro</b>
        só regista entradas. Aqui muda-se o email, repõe-se a senha ou tira-se o acesso.</div>
      <div id="ed-contas"><div class="dica">A carregar…</div></div>

      <details class="dobra dobra-dentro" id="ed-nova-conta" style="margin-top:.8rem">
        <summary><span class="mais">+</span> Adicionar uma conta</summary>
        <div class="lf" style="grid-template-columns:1fr 2fr 2fr auto;margin-top:.5rem;align-items:start">
          <div><label>Papel</label>
            <select id="ed-np-papel"><option value="porteiro">Porteiro</option><option value="noivos">Noivos</option></select></div>
          <div class="campo"><label for="ed-np-email">Email</label>
            <input type="email" id="ed-np-email" autocomplete="off" autocapitalize="none" spellcheck="false"><div class="err"></div></div>
          <div class="campo"><label for="ed-np-senha">Palavra-passe <small style="color:#8a8f88">· em branco = gerada</small></label>
            <div class="pw-wrap"><input type="password" id="ed-np-senha" autocomplete="new-password" spellcheck="false">
              <button type="button" class="pw-olho" id="olho-np" onclick="verSenha('ed-np-senha','olho-np')">mostrar</button></div>
            <div class="err"></div></div>
          <div><label aria-hidden="true" class="sp-label">&nbsp;</label>
            <button class="btn btn-ouro btn-sm" onclick="adicionarConta()">Adicionar</button></div>
          <div class="dica" id="ed-np-sem-porta" style="display:none;grid-column:1/-1;margin:.2rem 0 0">
            Sem o módulo <b>Controlo à porta</b> na licença deste casamento não há porteiro a
            criar. Junte-o em <b>Licença: módulos e prazo…</b>.</div>
        </div>
      </details>

      <div class="segredo" id="ed-segredo" style="display:none"></div>
      <div class="fim" style="display:flex;justify-content:flex-end;margin-top:1rem">
        <button class="btn" onclick="fecharModal('ov-editar')">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>window.CSRF = <?= json_encode(csrfToken()) ?>;</script>
<script src="<?= asset('assets/api.js') ?>"></script>
<script src="<?= asset('assets/janela.js') ?>"></script>
<script src="<?= asset('assets/maps-campo.js') ?>"></script>
<script src="<?= asset('assets/menu-mais.js') ?>"></script>
<script src="<?= asset('assets/moeda.js') ?>"></script>
<script>
if (window.Moeda) window.Moeda.ligar('.campo-moeda');
// Esta página chamava toast() sem o ter: as mensagens de erro rebentavam em
// silêncio na consola, em vez de aparecerem a quem estava a olhar.
function toast(m, mau){
  const el = document.getElementById('toast');
  el.textContent = m; el.className = 'toast mostrar' + (mau ? ' erro' : '');
  setTimeout(() => el.className = 'toast', 2800);
}

// ---------- as pastilhas: casamentos · novo · contas · dados · registo · definições ----------
function verVista(v){
  ['casamentos','novo','contas','licencas','dados','registo','atendimento','definicoes'].forEach(id => {
    const e = document.getElementById('vista-' + id);
    if (e) e.style.display = id === v ? '' : 'none';
  });
  document.querySelectorAll('#vista-chips .chip').forEach(c =>
    c.classList.toggle('on', c.dataset.vista === v));
  if (v === 'dados') carregarDadosCasamentos();
  if (v === 'registo') auditarPrimeiraVez();
  if (v === 'licencas') licPrimeiraVez();
  if (v === 'atendimento') atPrimeiraVez();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ============================================================
// Atendimento — a caixa de perguntas das páginas públicas
//
// O que se edita aqui vê-se em login.php e registo.php, ao canto. As respostas
// são escritas de antemão de propósito: é uma lista de perguntas frequentes com
// cara de conversa, e não uma promessa de que há alguém do outro lado a teclar.
// ============================================================
let AT_CARREGADO = false, AT_PERGUNTAS = [], AT_FOTO = '';

function atPrimeiraVez(){ if (!AT_CARREGADO){ AT_CARREGADO = true; atCarregar(); } }

async function atCarregar(){
  const d = await api('atendimento_ler');
  if (!d || !d.success) return;
  const c = d.def || {};
  $('at-ativo').checked = String(c.ativo) === '1';
  const pv = (id, v) => { const e = $(id); if (e) e.value = v || ''; };
  pv('at-nome', c.nome); pv('at-cargo', c.cargo); pv('at-saudacao', c.saudacao);
  pv('at-telefone', c.telefone); pv('at-whatsapp', c.whatsapp);
  pv('at-email', c.email); pv('at-horario', c.horario);
  $('at-chat-modo').value = c.chat_modo === 'script' ? 'script' : 'nenhum';
  pv('at-chat-script', c.chat_script); pv('at-chat-rotulo', c.chat_rotulo);
  atChatModo();
  AT_FOTO = c.foto || '';
  atPintarFoto();
  AT_PERGUNTAS = d.perguntas || [];
  atPintarLista();
}

function atPintarFoto(){
  const pre = $('at-foto-pre'); if (!pre) return;
  const nome = ($('at-nome').value || 'A').trim();
  if (AT_FOTO){
    pre.className = 'at-av at-av-g';
    pre.innerHTML = '<img src="' + esc(AT_FOTO) + '?v=' + Date.now() + '" alt="">';
  } else {
    pre.className = 'at-av at-av-g at-av-letra';
    pre.textContent = nome.charAt(0).toUpperCase() || 'A';
  }
  const bt = $('at-foto-tirar'); if (bt) bt.style.display = AT_FOTO ? '' : 'none';
}

async function atFotoEnviar(){
  const f = $('at-foto-f').files[0]; if (!f) return;
  const fd = new FormData(); fd.append('ficheiro', f);
  const r = await fetch('api.php?action=atendimento_foto',
    { method:'POST', headers:{ 'X-CSRF-Token': window.CSRF }, body: fd });
  const d = await r.json();
  $('at-foto-f').value = '';
  if (!d || !d.success){ toast((d && d.message) || 'Não foi possível guardar a imagem.', true); return; }
  AT_FOTO = d.path; atPintarFoto(); toast('Fotografia guardada.');
}

async function atFotoTirar(){
  const r = await licConfirmar({
    titulo: 'Tirar a fotografia?', icone: '🖼️', confirmar: 'Tirar a fotografia',
    texto: 'A caixa passa a mostrar a inicial do nome. O ficheiro é apagado — para o ter de '
         + 'volta, envie-o outra vez.'
  });
  if (!r.sim) return;
  const d = await api('atendimento_foto_tirar', { method:'POST' });
  if (!d || !d.success) return;
  AT_FOTO = ''; atPintarFoto(); toast('Fotografia removida.');
}

// Os campos do chat ao vivo só interessam com uma ferramenta escolhida.
function atChatModo(){
  const modo = $('at-chat-modo').value;
  const com = modo === 'script';
  $('at-chat-script').closest('.campo').style.display = com ? '' : 'none';
  $('at-chat-extra').style.display = com ? '' : 'none';
}

async function atGuardar(){
  const nome = ($('at-nome').value || '').trim();
  const email = ($('at-email').value || '').trim();
  const emailMau = email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  const modo = $('at-chat-modo').value;
  const script = ($('at-chat-script').value || '').trim();
  const scriptMau = modo === 'script' && !/^https:\/\//i.test(script);
  marca('at-nome', nome ? '' : 'Dê um nome a quem atende — é o que aparece na caixa.');
  marca('at-email', emailMau ? 'Email inválido.' : '');
  marca('at-chat-script', scriptMau
    ? (script ? 'Tem de começar por https:// — não se carrega código de fora em claro.'
              : 'Indique o endereço do script, ou escolha «nenhuma».') : '');
  if (!nome || emailMau || scriptMau) return;
  const d = await api('atendimento_guardar', { method:'POST', body: JSON.stringify({
    ativo: $('at-ativo').checked ? 1 : 0,
    nome, cargo: ($('at-cargo').value || '').trim(),
    saudacao: ($('at-saudacao').value || '').trim(),
    telefone: ($('at-telefone').value || '').trim(),
    whatsapp: ($('at-whatsapp').value || '').trim(),
    email, horario: ($('at-horario').value || '').trim(),
    chat_modo: modo, chat_script: script,
    chat_rotulo: ($('at-chat-rotulo').value || '').trim() }) });
  if (!d || !d.success) return;
  const est = $('at-estado');
  est.textContent = $('at-ativo').checked ? 'Guardado — a caixa está a aparecer.'
                                          : 'Guardado — a caixa está desligada.';
  setTimeout(() => { est.textContent = ''; }, 4000);
  atPintarFoto();
}

function atPintarLista(){
  const cx = $('at-lista');
  if (!AT_PERGUNTAS.length){
    cx.innerHTML = '<div class="dica">Ainda não há perguntas. A caixa só aparece com pelo menos uma.</div>';
    return;
  }
  cx.innerHTML = AT_PERGUNTAS.map(p => `
    <div class="at-item${p.ativo ? '' : ' off'}" id="atq-${p.id}">
      <div class="at-item-cab">
        <span class="et ${p.ativo ? 'agora' : ''}">${p.ativo ? 'visível' : 'desligada'}</span>
        <b>${esc(p.pergunta)}</b>
        <span class="at-ord">ordem ${p.ordem}</span>
      </div>
      <div class="at-item-resp">${esc(p.resposta)}</div>
      <div class="ac">
        <button class="btn btn-sm" onclick="atEditar(${p.id})">Editar…</button>
        <button class="btn btn-sm" onclick="atAlternar(${p.id})">${p.ativo ? 'Desligar' : 'Ligar'}</button>
        <button class="btn btn-sm perigo" onclick="atApagar(${p.id})">Apagar</button>
      </div>
    </div>`).join('');
}

function atFormulario(p){
  return licFormulario({
    titulo: p.id ? 'Editar a pergunta' : 'Nova pergunta',
    icone: '💬', guardar: 'Guardar pergunta',
    campos: [
      { id:'pergunta', rot:'Pergunta', tipo:'texto', valor:p.pergunta || '', largura:3,
        dica:'Como o casal a faria. «Quanto custa?» vale mais do que «Tabela de preços».' },
      { id:'resposta', rot:'Resposta', tipo:'area', linhas:6, valor:p.resposta || '', largura:3,
        dica:'Escreva-a como a diria a alguém à frente. Sem letra pequena.' },
      { id:'ordem', rot:'Ordem', tipo:'numero', valor:p.ordem || 0, min:0, max:9999,
        dica:'Menor primeiro. Deixe 0 numa pergunta nova para ela entrar no fim.' },
      { id:'ativo', rot:'Mostrar na caixa', tipo:'sim', valor:p.id ? !!p.ativo : true,
        largura:2, aoLado:'Aparece na caixa das páginas públicas' },
    ],
    aoGuardar: async (v) => {
      const d = await api('atendimento_faq_guardar', { method:'POST', body: JSON.stringify({
        id: p.id || 0, pergunta: v.pergunta, resposta: v.resposta,
        ordem: v.ordem, ativo: v.ativo ? 1 : 0 }) });
      if (!d || !d.success) return false;
      AT_PERGUNTAS = d.perguntas || []; atPintarLista();
      toast(p.id ? 'Pergunta guardada.' : 'Pergunta criada.');
      return true;
    }
  });
}
function atNovaPergunta(){ atFormulario({ id:0, ordem:0, ativo:1 }); }
function atEditar(id){
  const p = AT_PERGUNTAS.find(x => x.id === id); if (p) atFormulario(p);
}
async function atAlternar(id){
  const p = AT_PERGUNTAS.find(x => x.id === id); if (!p) return;
  const d = await api('atendimento_faq_guardar', { method:'POST', body: JSON.stringify({
    id: p.id, pergunta: p.pergunta, resposta: p.resposta, ordem: p.ordem,
    ativo: p.ativo ? 0 : 1 }) });
  if (!d || !d.success) return;
  AT_PERGUNTAS = d.perguntas || []; atPintarLista();
}
async function atApagar(id){
  const p = AT_PERGUNTAS.find(x => x.id === id); if (!p) return;
  const r = await licConfirmar({
    titulo: 'Apagar esta pergunta?', icone: '💬', perigo: true, confirmar: 'Apagar pergunta',
    texto: '<b>' + licEsc(p.pergunta) + '</b><br><br>Apaga-se a pergunta e a resposta. Para a '
         + 'esconder sem a perder, use antes <b>Desligar</b>.'
  });
  if (!r.sim) return;
  const d = await api('atendimento_faq_apagar&id=' + id, { method:'POST' });
  if (!d || !d.success) return;
  AT_PERGUNTAS = d.perguntas || []; atPintarLista();
  toast('Pergunta apagada.');
}

// ---------- Definições: o tema do sistema ----------
function marcarTema(inp){
  document.querySelectorAll('#temas .tema-op').forEach(l => l.classList.remove('on'));
  if (inp && inp.closest('.tema-op')) inp.closest('.tema-op').classList.add('on');
}
async function guardarTema(){
  const sel = document.querySelector('#temas input[name="tema"]:checked');
  if (!sel) return;
  const est = document.getElementById('tema-estado');
  const d = await api('sistema_tema_guardar', { method:'POST', body: JSON.stringify({ tema: sel.value }) });
  if (!d || !d.success) return;
  if (est) est.textContent = 'Guardado. A aplicar…';
  toast('Tema da casa guardado: ' + (d.rotulo || sel.value) + '.');
  // Isto define a BASE (para todos). Limpa-se a escolha pessoal deste navegador
  // para o próprio admin passar a ver a base que acabou de definir.
  try { localStorage.removeItem('tema'); } catch (e) {}
  setTimeout(() => location.reload(), 700);
}

// ---------- Licenças: pedidos, preçário, pacotes e políticas ----------
// Três coisas diferentes debaixo da mesma pastilha, porque respondem à mesma
// pergunta: o que é que a casa vende, e a quem é que já o deu.
let LIC_CAT = null, LIC_POL = null, LIC_PRONTO = false, LIC_PEST = 'pendente';
// Os pedidos que estão em lista, para a janela de decisão poder mostrar o que
// se está a aprovar — decidir às cegas sobre um número é decidir mal.
let LIC_PEDIDOS = [];

function licPrimeiraVez(){
  if (LIC_PRONTO) return; LIC_PRONTO = true;
  licPedidos('pendente');
  licCarregarCatalogo();
}

function licVista(v){
  ['pedidos','precario','pacotes','prazos','politicas'].forEach(id => {
    const e = document.getElementById('lic-v-' + id);
    if (e) e.style.display = id === v ? '' : 'none';
  });
  document.querySelectorAll('#vista-licencas .chip[data-lic]').forEach(c =>
    c.classList.toggle('on', c.dataset.lic === v));
  if (v === 'precario' || v === 'pacotes' || v === 'prazos') licCarregarCatalogo();
  if (v === 'politicas') licCarregarPolitica();
}

function licKz(v){
  v = Number(v) || 0;
  const casas = Math.abs(v % 1) > 0.001 ? 2 : 0;
  return v.toLocaleString('pt-PT', { minimumFractionDigits: casas, maximumFractionDigits: casas }) + ' Kz';
}
/** A medida de um escalão, dita como o casal a lê. */
function licMedida(it){
  const m = it.modulo_chave || it.modulo;
  if (m === 'convidados') return +it.limite > 0 ? 'até ' + it.limite + ' convidados' : 'sem limite';
  if (m === 'impresso' || m === 'digital')
    return +it.todos_modelos ? 'todos os modelos, com edição'
         : (+it.editar ? 'modelo padrão, com edição' : 'modelo padrão, sem edição');
  // Um módulo simples não tem medida: ou se leva ou não. Vazio, e quem o
  // escreve não põe travessão para nada.
  return '';
}

// ---- pedidos ----
async function licPedidos(estado){
  LIC_PEST = estado || LIC_PEST;
  document.querySelectorAll('#vista-licencas .chip[data-pest]').forEach(c =>
    c.classList.toggle('on', c.dataset.pest === LIC_PEST));
  const cx = document.getElementById('lic-pedidos-lista');
  cx.innerHTML = '<div class="dica">A carregar…</div>';
  const d = await api('lic_pedidos&estado=' + encodeURIComponent(LIC_PEST));
  if (!d || !d.success) { cx.innerHTML = '<div class="dica">Não foi possível ler os pedidos.</div>'; return; }
  licBadge(d.pendentes);
  LIC_PEDIDOS = d.pedidos || [];
  if (!d.pedidos.length){
    cx.innerHTML = '<div class="painel"><div class="dica">'
      + (LIC_PEST === 'pendente' ? 'Nenhum pedido à espera. Está tudo tratado.'
                                 : 'Nenhum pedido neste estado.') + '</div></div>';
    return;
  }

  // Os dois grupos aparecem separados, e por esta ordem: um casal à porta
  // espera pior do que um casal que já está lá dentro a trabalhar.
  const novos = d.pedidos.filter(p => p.tipo !== 'upgrade');
  const upg   = d.pedidos.filter(p => p.tipo === 'upgrade');
  let h = '';
  if (novos.length){
    h += '<h3 class="lic-grupo">Novos pedidos de licença <span>' + novos.length + '</span></h3>'
       + novos.map(licCartaoPedido).join('');
  }
  if (upg.length){
    h += '<h3 class="lic-grupo">Actualizações de pedidos de licença <span>' + upg.length + '</span></h3>'
       + '<div class="dica" style="margin:-.4rem 0 .8rem">Casamentos já a trabalhar que querem '
       + 'juntar módulos. Aprovar acrescenta ao que têm — nunca lhes tira nada.</div>'
       + upg.map(licCartaoPedido).join('');
  }
  cx.innerHTML = h;
}

function licBadge(n){
  const b = document.getElementById('lic-conta-badge');
  if (!b) return;
  b.textContent = n > 0 ? n : '';
  b.style.display = n > 0 ? '' : 'none';
}

function licCartaoPedido(p){
  const itens = (p.itens || []).map(it => {
    // «Até 80 convidados · até 80 convidados» não diz nada duas vezes: só se
    // acrescenta a medida quando ela conta alguma coisa que o nome não conta.
    const med = licMedida(it);
    const nome = it.escalao_nome || '';
    const extra = (med && med.toLowerCase() !== nome.toLowerCase()) ? ' · ' + med : '';
    return '<li><span class="mod">' + licEsc(it.modulo_chave) + '</span>'
      + '<span class="med">' + licEsc(nome) + licEsc(extra) + '</span>'
      + '<span class="pr">' + licKz(it.preco) + '</span></li>';
  }).join('');
  const pend = p.estado === 'pendente';
  const selo = { pendente:'⏳ à espera', aprovado:'✓ aprovado',
                 recusado:'✕ recusado', cancelado:'— cancelado' }[p.estado] || p.estado;
  return '<div class="painel lic-ped' + (pend ? ' pend' : '') + '">'
    + '<div class="lic-ped-cab">'
    +   '<div style="flex:1;min-width:200px">'
    +     '<div class="lic-ped-nome">' + licEsc(p.casamento_nome) + '</div>'
    +     '<div class="dica" style="margin:.15rem 0 0">'
    +       (p.tipo === 'upgrade' ? 'Reforço da licença' : 'Pedido inicial')
    +       ' · ' + licEsc(p.pacote_nome || 'plano à medida')
    +       ' · ' + p.meses + ' meses · ' + licData(p.criado_em) + '</div>'
    +   '</div>'
    +   '<div style="text-align:right"><div class="lic-ped-vl">' + licKz(p.total) + '</div>'
    +     '<div class="lic-ped-selo ' + p.estado + '">' + selo + '</div></div>'
    + '</div>'
    + '<ul class="lic-itens">' + itens + '</ul>'
    + (p.nota_casal ? '<div class="lic-ped-nota"><b>O casal escreveu:</b> '
        + licEsc(p.nota_casal) + '</div>' : '')
    + (p.nota_admin ? '<div class="lic-ped-nota"><b>Decisão:</b> ' + licEsc(p.nota_admin) + '</div>' : '')
    + '<div class="dica" style="margin:.7rem 0 0">Aceitou as políticas (versão '
    +   (p.politica_versao || 1) + ') em ' + licData(p.aceite_em) + '.</div>'
    + (pend
        ? '<div class="fim" style="margin-top:.8rem;flex-wrap:wrap">'
          + '<button class="btn btn-ouro btn-sm" onclick="licDecidir(' + p.id + ',\'aprovar\')">Aprovar</button>'
          + '<button class="btn btn-sm" onclick="licDecidir(' + p.id + ',\'recusar\')">Recusar</button>'
          + '</div>'
        : '')
    + '</div>';
}

function licData(s){
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? String(s) : d.toLocaleDateString('pt-PT',
    { day:'2-digit', month:'2-digit', year:'numeric' });
}

async function licDecidir(id, decisao){
  const p = (LIC_PEDIDOS || []).find(x => x.id === id);
  const quem = p ? licEsc(p.casamento_nome) : 'este casamento';
  // Num reforço, o preço da linha é o DEGRAU: o escalão novo menos o que o
  // casal já tem pago naquele módulo. Diz-se de onde vem — um «16 000» ao lado
  // de um preçário que diz «28 000» parece um erro se ninguém o explicar.
  const itens = p ? '<ul class="lic-conf-itens">' + (p.itens || []).map(it =>
      '<li><b>' + licEsc(it.escalao_nome) + '</b> <span>' + licEsc(it.modulo_chave)
    + (+it.credito > 0 ? ' · já pagos ' + licKz(it.credito) : '') + '</span>'
    + '<em>' + licKz(it.preco) + '</em></li>').join('') + '</ul>' : '';

  const r = await licConfirmar({
    titulo: (decisao === 'aprovar' ? 'Aprovar' : 'Recusar') + ' o pedido de ' + quem,
    icone: decisao === 'aprovar' ? '✓' : '✕',
    perigo: decisao === 'recusar',
    confirmar: decisao === 'aprovar' ? 'Conceder licença' : 'Recusar pedido',
    texto: (decisao === 'aprovar'
      ? '<b>' + (p ? licKz(p.total) : '') + '</b> · ' + (p ? p.meses : '?') + ' meses'
        + (p && p.pacote_nome ? ' · pacote ' + licEsc(p.pacote_nome) : ' · plano à medida')
        + itens
        + '<p>Os módulos acima passam a estar concedidos. Se o casamento ainda estava à espera, '
        + 'abre-se com as suas contas.</p>'
      : itens
        + '<p>O casal deixa de ter o que pediu. Se era o pedido <b>inicial</b>, fica sem licença '
        + 'nenhuma; se era um reforço, mantém o que já tinha.</p>'),
    motivo: {
      rot: decisao === 'aprovar' ? 'Nota para o casal (opcional)' : 'Motivo da recusa',
      exigido: decisao === 'recusar',
      dica: 'O casal lê-o na sua página da licença.',
      falta: 'Uma recusa sem explicação deixa o casal sem saber o que corrigir.',
      dica2: decisao === 'aprovar' ? 'Ex.: Bem-vindos!' : 'Ex.: falta confirmar o pagamento.'
    }
  });
  if (!r.sim) return;
  const d = await api('lic_decidir', { method:'POST',
    body: JSON.stringify({ id, decisao, nota: r.motivo }) });
  if (!d || !d.success) return;
  toast(decisao === 'aprovar'
    ? 'Licença concedida.' + (d.contas_ativadas ? ' ' + d.contas_ativadas + ' conta(s) ativada(s).' : '')
    : 'Pedido recusado.');
  licPedidos();
}

// ---- preçário ----
async function licCarregarCatalogo(){
  const d = await api('lic_catalogo');
  if (!d || !d.success) return;
  LIC_CAT = d.catalogo;
  licPintarPrecario();
  licPintarPacotes();
  licPintarPrazos();
}

// ---- prazos ----
function licPintarPrazos(){
  const cx = document.getElementById('lic-prazos');
  if (!cx || !LIC_CAT) return;
  const ps = LIC_CAT.prazos || [];
  if (!ps.length){
    cx.innerHTML = '<div class="painel"><div class="dica">Sem prazos, não há preço: o preçário '
      + 'fica a valer tal como está escrito. Crie pelo menos um.</div></div>';
    return;
  }
  // O prazo mais curto é a referência: é contra ele que se mede se um prazo
  // maior compensa. Uma amostra real do preçário torna a conta concreta —
  // «×1,8» não diz nada a ninguém; «57 600 em vez de 64 000» diz tudo.
  const base = ps.reduce((a, x) => (x.meses < a.meses ? x : a), ps[0]);
  let amostra = 0, amostraNome = '';
  (LIC_CAT.modulos || []).forEach(m => m.escaloes.forEach(e => {
    if (!amostra && e.ativo){ amostra = e.preco; amostraNome = m.nome + ' · ' + e.nome; }
  }));
  // A escala das barras: o maior preço por mês é a régua.
  const porMesDe = p => (amostra * p.fator) / p.meses;
  const maxMes = Math.max.apply(null, ps.map(porMesDe));

  cx.innerHTML = '<div class="painel">'
    + (amostra
        ? '<div class="dica" style="margin-bottom:.9rem">A conta abaixo é feita sobre '
          + '<b>' + licEsc(amostraNome) + '</b> (' + licKz(amostra) + ' a ' + base.meses
          + ' meses). A barra é o <b>preço por mês</b>: quanto mais curta, melhor negócio para '
          + 'o casal.</div>'
        : '')
    + '<div class="lic-prazos-lista">'
    + ps.map(p => {
        const mes = porMesDe(p);
        const total = amostra * p.fator;
        const proporcional = amostra * (base.fator * p.meses / base.meses);
        const descMes = Math.round((1 - mes / porMesDe(base)) * 100);
        const larg = maxMes > 0 ? Math.round(mes / maxMes * 100) : 0;
        const ehBase = p.meses === base.meses;
        return '<div class="lic-pz' + (ehBase ? ' base' : '') + '">'
          + '<div class="lic-pz-cab">'
          +   '<span class="lic-pz-nome">' + licEsc(p.nome)
          +     (p.etiqueta ? ' <span class="et fita">' + licEsc(p.etiqueta) + '</span>' : '')
          +     (ehBase ? ' <span class="et">preço base</span>' : '')
          +   '</span>'
          +   '<span class="lic-pz-fator">×' + (+p.fator).toFixed(2) + '</span>'
          + '</div>'
          + (p.resumo ? '<div class="lic-pz-res">' + licEsc(p.resumo) + '</div>' : '')
          + '<div class="lic-pz-barra"><i style="width:' + larg + '%"></i>'
          +   '<span>' + licKz(mes) + ' / mês</span></div>'
          + '<div class="lic-pz-nums">'
          +   '<span>Total <b>' + licKz(total) + '</b></span>'
          +   (!ehBase && proporcional > total + 0.5
                ? '<span>Proporcional <b class="risca">' + licKz(proporcional) + '</b></span>'
                  + '<span class="poupa">Poupa <b>' + licKz(proporcional - total) + '</b></span>'
                : '')
          +   (descMes !== 0
                ? '<span class="' + (descMes > 0 ? 'poupa' : 'caro') + '"><b>'
                  + (descMes > 0 ? '−' : '+') + Math.abs(descMes) + '%</b> por mês</span>'
                : '')
          + '</div>'
          + '<div class="lic-pz-ac">'
          +   '<button class="btn btn-sm" onclick="licPrazoEditar(' + p.id + ')">Editar</button>'
          +   '<button class="btn btn-sm perigo" onclick="licPrazoApagar(' + p.id + ')">Apagar</button>'
          + '</div></div>';
      }).join('')
    + '</div></div>';
}

function licPrazoEditar(id){
  const p = id ? (LIC_CAT.prazos || []).find(x => x.id === id) : null;
  const base = licPrazoBase();
  licFormulario({
    titulo: id ? 'Prazo · ' + licEsc(p.nome) : 'Novo prazo',
    dica: 'O <b>factor</b> multiplica todo o preçário. O prazo de factor <b>1</b> é aquele em que '
        + 'os preços estão escritos.'
        + (base ? ' Hoje é o de <b>' + base.meses + ' meses</b>.' : ''),
    campos: [
      { id:'meses', rot:'Meses', tipo:'numero', valor:p ? p.meses : 12, min:1, max:120 },
      { id:'nome',  rot:'Nome',  valor:p ? p.nome : '', largura:2,
        dica2:'Vazio = «N meses».' },
      { id:'fator', rot:'Factor de preço', tipo:'numero', valor:p ? p.fator : 1.8,
        min:0.001, passo:0.05 },
      { id:'etiqueta', rot:'Fita de destaque', valor:p ? p.etiqueta : '', largura:2,
        dica2:'ex.: MELHOR ESCOLHA' },
      { id:'resumo', rot:'Uma linha de apoio', valor:p ? p.resumo : '', largura:3,
        dica2:'Opcional — aparece por baixo do nome, na montra.' },
      { id:'ordem', rot:'Ordem', tipo:'numero', valor:p ? p.ordem : (p ? p.meses : 12), min:0 },
      { id:'ativo', rot:'', tipo:'sim', valor:p ? 1 : 1, aoLado:'À escolha do casal', largura:2 },
    ],
    // A conta ao vivo: escrever um factor às cegas é adivinhar. Aqui vê-se, ao
    // lado, o que ele faz ao preço e ao preço POR MÊS — que é o que decide se
    // um prazo longo compensa ou castiga.
    extra: '<div class="lic-fator-prova" id="lic-fator-prova"></div>',
    aoGuardar: async v => {
      const d = await api('lic_prazo_guardar', { method:'POST', body: JSON.stringify({
        id: id || 0, meses:v.meses, nome:v.nome, resumo:v.resumo, fator:v.fator,
        etiqueta:v.etiqueta, ordem:v.ordem || v.meses, ativo:v.ativo }) });
      if (!d || !d.success) return false;
      LIC_CAT = d.catalogo; licPintarPrazos(); toast('Prazo guardado.');
    }
  });
  // Liga a prova em directo aos dois campos que a determinam.
  const rever = () => licProvaFator(
    parseInt(document.getElementById('lf-meses').value, 10) || 0,
    parseFloat(String(document.getElementById('lf-fator').value).replace(',', '.')) || 0);
  ['lf-meses','lf-fator'].forEach(i => {
    const el = document.getElementById(i);
    if (el) el.addEventListener('input', rever);
  });
  rever();
}

/** O prazo de referência: o de factor 1, onde o preçário está escrito. */
function licPrazoBase(){
  const ps = (LIC_CAT && LIC_CAT.prazos) || [];
  if (!ps.length) return null;
  return ps.reduce((a, x) => (x.fator < a.fator ? x : a), ps[0]);
}

/**
 * O que um factor faz, em números — para não se escrever um às cegas.
 *
 * Mostra, sobre um preço real do preçário, o que o casal pagaria, o que pagaria
 * se fosse proporcional, e a diferença por mês. É a diferença por mês que
 * decide se o prazo longo é um bom negócio ou um castigo.
 */
function licProvaFator(meses, fator){
  const cx = document.getElementById('lic-fator-prova');
  if (!cx) return;
  const base = licPrazoBase();
  if (!base || !meses || !fator){ cx.innerHTML = ''; return; }
  // Um preço real, para a conta não ser abstracta.
  let amostra = 0, nome = '';
  (LIC_CAT.modulos || []).forEach(m => m.escaloes.forEach(e => {
    if (!amostra && e.ativo){ amostra = e.preco; nome = m.nome + ' · ' + e.nome; }
  }));
  if (!amostra) { cx.innerHTML = ''; return; }

  const paga = amostra * fator;
  const proporcional = amostra * (base.fator * meses / base.meses);
  const desc = proporcional > 0 ? Math.round((1 - paga / proporcional) * 100) : 0;
  const mesBase = (amostra * base.fator) / base.meses;
  const mesAgora = paga / meses;
  const descMes = mesBase > 0 ? Math.round((1 - mesAgora / mesBase) * 100) : 0;

  // Só se risca o proporcional quando ele é MAIOR do que o que se paga — isto
  // é, quando há mesmo desconto. Riscar um número menor do que o preço final
  // dizia o contrário do que se passa.
  const haDesconto = proporcional > paga + 0.5;
  cx.innerHTML = '<div class="lic-fp-tit">O que este factor faz</div>'
    + '<div class="lic-fp-sub">Sobre <b>' + licEsc(nome) + '</b> (' + licKz(amostra)
    + ' a ' + base.meses + ' meses)</div>'
    + '<div class="lic-fp-linhas">'
    +   licFpLinha('O casal paga', licKz(paga), '')
    +   licFpLinha(haDesconto ? 'Se fosse proporcional' : 'Proporcional seria',
                   licKz(proporcional), haDesconto ? 'risca' : 'ref')
    +   licFpLinha('Por mês', licKz(mesAgora), '')
    + '</div>'
    + '<div class="lic-fp-veredito ' + (descMes > 0 ? 'bom' : (descMes < 0 ? 'mau' : 'igual')) + '">'
    +   (descMes > 0
        ? '<b>−' + descMes + '% por mês</b> face aos ' + base.meses + ' meses. '
          + 'O casal poupa ' + licKz(proporcional - paga) + ' por levar ' + meses + ' meses.'
        : (descMes < 0
            ? '<b>+' + Math.abs(descMes) + '% por mês</b> — este prazo sai <b>mais caro</b> por mês '
              + 'do que o de ' + base.meses + '. Um prazo longo assim castiga quem se compromete.'
            : 'Custa o mesmo por mês que ' + base.meses + ' meses — não há vantagem em escolhê-lo.'))
    + '</div>'
    + (desc !== descMes ? '' : '');
}
function licFpLinha(rot, val, cls){
  return '<div class="lic-fp-l"><span>' + rot + '</span><b class="' + (cls || '') + '">'
       + val + '</b></div>';
}

async function licPrazoApagar(id){
  const p = (LIC_CAT.prazos || []).find(x => x.id === id); if (!p) return;
  const r = await licConfirmar({
    titulo: 'Apagar o prazo «' + licEsc(p.nome) + '»?',
    icone: '🗑', perigo: true, confirmar: 'Apagar prazo',
    texto: 'Deixa de estar à escolha na montra.<br><br>'
         + 'As licenças <b>já concedidas</b> com ele não se alteram: o prazo de cada casamento '
         + 'está guardado nele próprio.'
  });
  if (!r.sim) return;
  const d = await api('lic_prazo_apagar', { method:'POST', body: JSON.stringify({ id }) });
  if (!d || !d.success) return;
  LIC_CAT = d.catalogo; licPintarPrazos(); toast('Prazo apagado.');
}

function licPintarPrecario(){
  const cx = document.getElementById('lic-precario');
  if (!cx || !LIC_CAT) return;
  cx.innerHTML = LIC_CAT.modulos.map(m => {
    const escs = m.escaloes.map(e =>
        '<tr' + (e.ativo ? '' : ' class="off"') + '>'
      + '<td><b>' + licEsc(e.nome) + '</b>'
      +   (e.resumo ? '<div class="dica" style="margin:.1rem 0 0">' + licEsc(e.resumo) + '</div>' : '')
      +   (e.ativo ? '' : '<span class="et">desligado</span>') + '</td>'
      + '<td class="med">' + licMedida(Object.assign({ modulo_chave: m.chave }, e)) + '</td>'
      + '<td class="pr">' + licKz(e.preco) + '</td>'
      + '<td class="ac"><button class="btn btn-sm" onclick="licEscalaoEditar(' + m.id + ',' + e.id + ')">Editar</button>'
      +   '<button class="btn btn-sm perigo" onclick="licEscalaoApagar(' + e.id + ')">Apagar</button></td>'
      + '</tr>').join('');
    return '<div class="painel">'
      + '<div style="display:flex;gap:.8rem;align-items:flex-start;flex-wrap:wrap">'
      +   '<div style="font-size:1.5rem">' + licEsc(m.icone || '•') + '</div>'
      +   '<div style="flex:1;min-width:200px"><h3 style="margin:0">' + licEsc(m.nome)
      +     (m.ativo ? '' : ' <span class="et">desligado</span>') + '</h3>'
      +     '<div class="dica" style="margin:.15rem 0 0">' + licEsc(m.resumo) + '</div>'
      +     '<div style="color:var(--gold);font-weight:600;font-size:.83rem;margin-top:.15rem">'
      +       licEsc(m.beneficio) + '</div></div>'
      +   '<div class="fim" style="margin:0">'
      +     '<button class="btn btn-sm" onclick="licModuloEditar(' + m.id + ')">Editar módulo</button>'
      +     '<button class="btn btn-ouro btn-sm" onclick="licEscalaoEditar(' + m.id + ',0)">&#43; Escalão</button>'
      +   '</div>'
      + '</div>'
      + '<table class="lic-tab"><tbody>' + escs + '</tbody></table>'
      + '</div>';
  }).join('');
}

function licModulo(id){ return LIC_CAT.modulos.find(m => m.id === id); }
function licEscalao(id){
  for (const m of LIC_CAT.modulos){ const e = m.escaloes.find(x => x.id === id); if (e) return [m, e]; }
  return [null, null];
}

function licModuloEditar(id){
  const m = licModulo(id); if (!m) return;
  licFormulario({
    titulo: 'Módulo · ' + licEsc(m.nome),
    dica: 'O que este módulo é, e como se apresenta na montra da inscrição.',
    campos: [
      { id:'nome',   rot:'Nome',   valor:m.nome, largura:2 },
      { id:'icone',  rot:'Ícone',  valor:m.icone, dica:'Um emoji.' },
      { id:'ativo',  rot:'',       tipo:'sim', valor:m.ativo, aoLado:'À venda' },
      { id:'resumo', rot:'O que faz', valor:m.resumo, largura:3, tipo:'area', linhas:2,
        dica:'Uma linha, em linguagem de casamento.' },
      { id:'beneficio', rot:'A frase que vende', valor:m.beneficio, largura:3, tipo:'area', linhas:2,
        dica:'O <b>benefício</b>, não a funcionalidade: «Saiba quem vem» e não «lista de convidados».' },
    ],
    aoGuardar: async v => {
      if (!v.nome){ licJanelaErro('O módulo precisa de um nome.'); return false; }
      const d = await api('lic_modulo_guardar', { method:'POST', body: JSON.stringify({
        id, nome:v.nome, resumo:v.resumo, beneficio:v.beneficio, icone:v.icone, ativo:v.ativo }) });
      if (!d || !d.success) return false;
      LIC_CAT = d.catalogo; licPintarPrecario(); licPintarPacotes();
      toast('Módulo guardado.');
    }
  });
}

function licEscalaoEditar(modId, escId){
  const m = licModulo(modId); if (!m) return;
  const e = escId ? licEscalao(escId)[1] : null;

  // Cada módulo tem a sua medida. Perguntar «pode editar?» num escalão de
  // convidados era pedir uma resposta que não quer dizer nada — por isso o
  // formulário muda com o módulo, e mostra só o que ali faz sentido.
  const campos = [
    { id:'nome',   rot:'Nome do escalão', valor:e ? e.nome : '', largura:2 },
    { id:'preco',  rot:'Preço (prazo base)', tipo:'preco', valor:e ? e.preco : 0,
      min:0, passo:500, dica:'Em Kz. Os prazos longos multiplicam-no.' },
    { id:'resumo', rot:'Uma linha de apoio', valor:e ? e.resumo : '', largura:3,
      dica2:'Opcional — aparece por baixo do nome, na montra.' },
  ];
  if (m.chave === 'convidados'){
    campos.push({ id:'limite', rot:'Tecto de convidados', tipo:'numero',
                  valor:e ? e.limite : 0, min:0, max:100000,
                  dica:'<b>0</b> = sem limite.' });
  } else if (m.chave === 'impresso' || m.chave === 'digital'){
    campos.push({ id:'nivel', rot:'O que dá', tipo:'escolha', largura:2,
      valor: e ? (e.todos_modelos ? '3' : (e.editar ? '2' : '1')) : '1',
      opcoes:[{v:'1',r:'Modelo padrão, sem edição'},
              {v:'2',r:'Modelo padrão, com edição'},
              {v:'3',r:'Todos os modelos, com edição'}] });
  }
  campos.push({ id:'ordem', rot:'Ordem', tipo:'numero', valor:e ? e.ordem : 100, min:0,
                dica:'Menor aparece primeiro.' });
  campos.push({ id:'ativo', rot:'', tipo:'sim', valor:e ? e.ativo : 1, aoLado:'À venda' });

  licFormulario({
    titulo: (escId ? 'Escalão' : 'Novo escalão') + ' · ' + licEsc(m.nome),
    dica: 'Um escalão é uma <b>medida</b> em que o módulo se vende. É aqui que vive o preço.',
    campos,
    aoGuardar: async v => {
      if (!v.nome){ licJanelaErro('O escalão precisa de um nome.'); return false; }
      const corpo = { id: escId, modulo: modId, nome:v.nome, resumo:v.resumo,
                      preco: v.preco, limite: 0, editar: 0, todos_modelos: 0,
                      ordem: v.ordem, ativo: v.ativo };
      if (m.chave === 'convidados') corpo.limite = Math.max(0, v.limite | 0);
      if (m.chave === 'impresso' || m.chave === 'digital'){
        corpo.editar = (v.nivel === '2' || v.nivel === '3') ? 1 : 0;
        corpo.todos_modelos = v.nivel === '3' ? 1 : 0;
      }
      const d = await api('lic_escalao_guardar', { method:'POST', body: JSON.stringify(corpo) });
      if (!d || !d.success) return false;
      LIC_CAT = d.catalogo; licPintarPrecario(); licPintarPacotes();
      toast('Escalão guardado.');
    }
  });
}

async function licEscalaoApagar(id){
  const [m, e] = licEscalao(id); if (!e) return;
  const r = await licConfirmar({
    titulo: 'Apagar o escalão «' + licEsc(e.nome) + '»?',
    icone: '🗑',
    perigo: true, confirmar: 'Apagar escalão',
    texto: 'Deixa de estar à venda em <b>' + licEsc(m.nome) + '</b>.<br><br>'
         + 'Se já houver licenças assentes nele, <b>não se apaga</b>: desliga-se, e deixa apenas '
         + 'de aparecer na montra. As licenças que dele dependem ficam intactas.'
  });
  if (!r.sim) return;
  const d = await api('lic_escalao_apagar', { method:'POST', body: JSON.stringify({ id }) });
  if (!d || !d.success) return;
  LIC_CAT = d.catalogo; licPintarPrecario(); licPintarPacotes();
  toast(d.desligado
    ? 'Escalão desligado — ' + d.usos + ' licença(s) assentam nele.'
    : 'Escalão apagado.');
}

// ---- pacotes ----
function licPintarPacotes(){
  const cx = document.getElementById('lic-pacotes');
  if (!cx || !LIC_CAT) return;
  if (!LIC_CAT.pacotes.length){
    cx.innerHTML = '<div class="painel"><div class="dica">Ainda não há pacotes. '
      + 'Um preçário só com módulos à peça funciona — mas é nos pacotes que está a poupança '
      + 'que faz o casal subir de plano.</div></div>';
    return;
  }
  cx.innerHTML = LIC_CAT.pacotes.map(p => {
    const itens = p.itens.map(id => {
      const [m, e] = licEscalao(id);
      return e ? '<li><span class="mod">' + licEsc(m.nome) + '</span>'
               + '<span class="med">' + licEsc(e.nome) + '</span></li>' : '';
    }).join('');
    return '<div class="painel">'
      + '<div class="lic-ped-cab">'
      +   '<div style="flex:1;min-width:200px"><div class="lic-ped-nome">' + licEsc(p.nome)
      +     (p.destaque ? ' <span class="et destaque">em destaque</span>' : '')
      +     (p.ativo ? '' : ' <span class="et">desligado</span>')
      +     (p.etiqueta ? ' <span class="et fita">' + licEsc(p.etiqueta) + '</span>' : '') + '</div>'
      +     '<div class="dica" style="margin:.15rem 0 0">' + licEsc(p.promessa)
      +       ' · ' + p.meses + ' meses</div></div>'
      +   '<div style="text-align:right"><div class="lic-ped-vl">' + licKz(p.preco) + '</div>'
      +     '<div class="dica" style="margin:0">à peça ' + licKz(p.avulso)
      +       (p.poupanca > 0 ? ' · <b style="color:var(--ok)">poupa ' + licKz(p.poupanca) + '</b>'
                              : ' · <b style="color:var(--warn)">sem poupança</b>') + '</div></div>'
      + '</div>'
      + '<ul class="lic-itens">' + itens + '</ul>'
      + '<div class="fim" style="margin-top:.7rem">'
      +   '<button class="btn btn-sm" onclick="licPacoteEditar(' + p.id + ')">Editar</button>'
      +   '<button class="btn btn-sm" onclick="licPacoteItens(' + p.id + ')">Escolher módulos</button>'
      +   '<button class="btn btn-sm perigo" onclick="licPacoteApagar(' + p.id + ')">Apagar</button>'
      + '</div></div>';
  }).join('');
}

function licPacoteEditar(id){
  const p = id ? LIC_CAT.pacotes.find(x => x.id === id) : null;
  licFormulario({
    titulo: id ? 'Pacote · ' + licEsc(p.nome) : 'Novo pacote',
    dica: 'Um conjunto de escalões com preço próprio. A <b>poupança</b> que o casal vê é '
        + 'calculada — a diferença entre este preço e a soma dos escalões à peça.',
    campos: [
      { id:'nome',  rot:'Nome',  valor:p ? p.nome : '', largura:2 },
      { id:'preco', rot:'Preço (prazo base)', tipo:'preco', valor:p ? p.preco : 0, min:0, passo:1000 },
      { id:'promessa', rot:'A promessa', valor:p ? p.promessa : '', largura:3,
        dica2:'Uma linha: o que este pacote resolve.' },
      { id:'resumo', rot:'Descrição', tipo:'area', linhas:2, valor:p ? p.resumo : '', largura:3,
        dica2:'Opcional — o parágrafo mais longo, para quem quer ler mais.' },
      { id:'meses', rot:'Prazo sugerido', tipo:'numero', valor:p ? p.meses : 12, min:1, max:120,
        dica:'Meses. O casal pode escolher outro.' },
      { id:'etiqueta', rot:'Fita de destaque', valor:p ? p.etiqueta : '',
        dica2:'ex.: O MAIS ESCOLHIDO' },
      { id:'ordem', rot:'Ordem', tipo:'numero', valor:p ? p.ordem : 100, min:0 },
      { id:'destaque', rot:'', tipo:'sim', valor:p ? p.destaque : 0, largura:2,
        aoLado:'Em destaque na montra',
        dica:'Só um pacote pode estar — dois «mais escolhidos» não escolhem nada.' },
      { id:'ativo', rot:'', tipo:'sim', valor:p ? p.ativo : 1, aoLado:'À venda' },
    ],
    aoGuardar: async v => {
      if (!v.nome){ licJanelaErro('O pacote precisa de um nome.'); return false; }
      const d = await api('lic_pacote_guardar', { method:'POST', body: JSON.stringify({
        id: id || 0, nome:v.nome, promessa:v.promessa, resumo:v.resumo, preco:v.preco,
        meses:v.meses, etiqueta:v.etiqueta, destaque:v.destaque, ordem:v.ordem, ativo:v.ativo }) });
      if (!d || !d.success) return false;
      LIC_CAT = d.catalogo; licPintarPacotes();
      toast('Pacote guardado.' + (id ? '' : ' Escolha agora os módulos que inclui.'));
      if (!id) setTimeout(() => licPacoteItens(d.id), 350);
    }
  });
}

/**
 * Escolher os escalões de um pacote — e corrigir-lhes o preço ali mesmo.
 *
 * Montar um pacote é o momento em que se olha para os preços à peça: é deles
 * que sai a poupança que o pacote anuncia. Ter de sair daqui, ir ao preçário,
 * mudar um número e voltar era partir em três um gesto que é um só. A conta em
 * baixo acompanha o que se vai mexendo, para não se anunciar uma poupança que
 * não existe.
 */
function licPacoteItens(id){
  const p = LIC_CAT.pacotes.find(x => x.id === id); if (!p) return;
  const dentro = new Set(p.itens);
  const linhas = LIC_CAT.modulos.map(m =>
    '<div class="lic-pi-mod"><div class="lic-pi-nome">' + licEsc(m.icone || '•') + ' '
    + licEsc(m.nome) + '</div>'
    + m.escaloes.map(e =>
        '<label class="lic-pi"><input type="checkbox" value="' + e.id + '"'
      + (dentro.has(e.id) ? ' checked' : '') + ' data-mod="' + licEsc(m.chave) + '">'
      + '<span class="lic-pi-txt">' + licEsc(e.nome) + '</span>'
      + '<span class="lic-pi-preco"><input type="number" min="0" step="500" '
      + 'value="' + (+e.preco) + '" data-preco="' + e.id + '" '
      + 'aria-label="Preço de ' + licEsc(e.nome) + '"><small>Kz</small></span>'
      + '</label>').join('')
    + '</div>').join('');

  licJanela('Módulos e preços de «' + licEsc(p.nome) + '»',
    '<div class="dica">Um escalão por módulo — marcar dois do mesmo não faz sentido, e o pedido '
    + 'só guardaria o primeiro. Os preços que mudar aqui valem para <b>todo o preçário</b>, e não '
    + 'só para este pacote.</div>'
    + '<div id="lic-pi-lista">' + linhas + '</div>'
    + '<div class="lic-pi-conta" id="lic-pi-conta"></div>',
    async () => {
      const marc = [...document.querySelectorAll('#lic-pi-lista input[type=checkbox]:checked')]
                   .map(i => +i.value);
      const precos = {};
      document.querySelectorAll('#lic-pi-lista input[data-preco]').forEach(i => {
        precos[i.dataset.preco] = parseFloat(i.value) || 0;
      });
      const d = await api('lic_pacote_guardar', { method:'POST',
        body: JSON.stringify({ id, nome: p.nome, promessa: p.promessa, resumo: p.resumo,
          preco: p.preco, meses: p.meses, etiqueta: p.etiqueta, destaque: p.destaque,
          ordem: p.ordem, ativo: p.ativo, escaloes: marc, precos }) });
      if (!d || !d.success) return;
      LIC_CAT = d.catalogo; licPintarPacotes(); licPintarPrecario();
      if (d.faltam && d.faltam.length){
        toast('Guardado, MAS este pacote não inclui ' + d.faltam.join(', ')
            + ' — nenhum casal o poderá comprar assim.', true);
      } else {
        toast('Pacote guardado.' + (d.precos_mudados
          ? ' ' + d.precos_mudados + ' preço(s) de módulo actualizado(s).' : ''));
      }
    });

  // A conta ao vivo: quanto valem, à peça, os escalões marcados.
  const recontar = () => {
    let soma = 0;
    document.querySelectorAll('#lic-pi-lista input[type=checkbox]:checked').forEach(c => {
      const pr = document.querySelector('#lic-pi-lista input[data-preco="' + c.value + '"]');
      soma += parseFloat(pr && pr.value) || 0;
    });
    const poupa = soma - (+p.preco);
    const cx = document.getElementById('lic-pi-conta');
    if (!cx) return;
    cx.innerHTML = '<div><span class="r">À peça</span><b>' + licKz(soma) + '</b></div>'
      + '<div><span class="r">Preço do pacote</span><b>' + licKz(p.preco) + '</b></div>'
      + '<div class="' + (poupa > 0 ? 'bom' : 'mau') + '"><span class="r">'
      + (poupa > 0 ? 'O casal poupa' : 'Sem poupança') + '</span><b>'
      + (poupa > 0 ? licKz(poupa) : licKz(Math.abs(poupa)) + ' acima') + '</b></div>';
  };

  // Um módulo, um escalão: marcar outro do mesmo módulo desmarca o anterior.
  document.querySelectorAll('#lic-pi-lista input[type=checkbox]').forEach(inp => {
    inp.addEventListener('change', () => {
      if (inp.checked) {
        document.querySelectorAll('#lic-pi-lista input[data-mod="' + inp.dataset.mod + '"]')
          .forEach(o => { if (o !== inp) o.checked = false; });
      }
      recontar();
    });
  });
  document.querySelectorAll('#lic-pi-lista input[data-preco]').forEach(i => {
    i.addEventListener('input', recontar);
    // Clicar no preço não deve marcar/desmarcar a etiqueta que o contém.
    i.addEventListener('click', ev => ev.preventDefault());
  });
  recontar();
}

async function licPacoteApagar(id){
  const p = LIC_CAT.pacotes.find(x => x.id === id); if (!p) return;
  const r = await licConfirmar({
    titulo: 'Apagar o pacote «' + licEsc(p.nome) + '»?',
    icone: '🗑', perigo: true, confirmar: 'Apagar pacote',
    texto: 'Deixa de aparecer na montra da inscrição.<br><br>'
         + 'As licenças <b>já concedidas</b> por ele não se alteram: o que foi concedido está '
         + 'concedido, e os casamentos que o levaram continuam com os seus módulos.'
  });
  if (!r.sim) return;
  const d = await api('lic_pacote_apagar', { method:'POST', body: JSON.stringify({ id }) });
  if (!d || !d.success) return;
  LIC_CAT = d.catalogo; licPintarPacotes(); toast('Pacote apagado.');
}

// ---- políticas ----
async function licCarregarPolitica(){
  if (LIC_POL) return;
  const d = await api('lic_politica');
  if (!d || !d.success) return;
  LIC_POL = d.politica;
  document.getElementById('lic-pol-titulo').value = LIC_POL.titulo || '';
  document.getElementById('lic-pol-corpo').value = LIC_POL.corpo || '';
  document.getElementById('lic-pol-versao').textContent =
    'Em vigor: versão ' + (LIC_POL.versao || 1) + '.';
}

function licPolPrever(){
  const corpo = document.getElementById('lic-pol-corpo').value;
  const titulo = document.getElementById('lic-pol-titulo').value;
  if (window.Planos) Planos.politicas({ titulo, corpo, versao: (LIC_POL ? LIC_POL.versao : 1) }, null);
}

async function licPolGuardar(){
  const titulo = document.getElementById('lic-pol-titulo').value.trim();
  const corpo = document.getElementById('lic-pol-corpo').value.trim();
  const r = await licConfirmar({
    titulo: 'Publicar uma versão nova das políticas?',
    icone: '📄', confirmar: 'Publicar versão ' + ((LIC_POL ? LIC_POL.versao : 0) + 1),
    texto: 'A versão <b>' + (LIC_POL ? LIC_POL.versao : 1) + '</b> fica guardada — é a prova do '
         + 'texto que os casais já aceitaram, e essa não se reescreve.<br><br>'
         + 'Os pedidos <b>novos</b> passam a apontar para a versão nova; os antigos continuam a '
         + 'apontar para aquela a que cada casal disse que sim.'
  });
  if (!r.sim) return;
  const d = await api('lic_politica_guardar', { method:'POST', body: JSON.stringify({ titulo, corpo }) });
  if (!d || !d.success) return;
  LIC_POL = d.politica;
  document.getElementById('lic-pol-versao').textContent =
    'Em vigor: versão ' + LIC_POL.versao + '.';
  toast('Políticas publicadas — versão ' + LIC_POL.versao + '.');
}


// A pastilha das licenças traz o número de pedidos à espera mal a página abre:
// um pedido que ninguém vê é um casal que ficou à porta sem ninguém saber.
(async () => {
  const d = await api('lic_pedidos&estado=pendente', { silencioso: true });
  if (d && d.success) licBadge(d.pendentes);
})();

/** Revogar a licença por incumprimento. O motivo não é opcional: o casal vê-o. */
async function licRevogarDe(id, nome){
  const r = await licConfirmar({
    titulo: 'Revogar a licença de «' + licEsc(nome) + '»?',
    icone: '⚠️', perigo: true, confirmar: 'Revogar licença',
    texto: '<b>Todos os módulos fecham de imediato.</b> O casal deixa de poder entrar no painel, '
         + 'nas mesas, no orçamento e nos convites.<br><br>'
         + 'Os dados <b>não</b> se apagam, e a Gestão fica aberta: o casal continua a poder '
         + 'exportá-los, como as políticas lhe prometem (Lei n.º 22/11, artigos 26.º e 28.º).',
    motivo: {
      rot: 'Motivo da revogação',
      exigido: true,
      dica: 'O casal lê-o na sua página da licença, e fica no registo de ações.',
      falta: 'A revogação exige um motivo — o casal tem direito a sabê-lo.',
      dica2: 'Ex.: partilha de credenciais com terceiros (ponto 2 das políticas).'
    }
  });
  if (!r.sim) return;
  const d = await api('lic_revogar', { method:'POST',
    body: JSON.stringify({ casamento: id, motivo: r.motivo }) });
  if (!d || !d.success) return;
  toast('Licença revogada.');
  carregarCasamentos();
}

// ---------- Registo de ações (auditoria do admin) ----------
let AUD_PAGINA = 1, AUD_ROWS = [], AUD_PRONTO = false;
async function auditarPrimeiraVez(){
  if (AUD_PRONTO) return; AUD_PRONTO = true;
  // preencher o filtro de casamentos
  try {
    const d = await api('casamento_lista&estado=todos');
    const sel = document.getElementById('aud-casamento');
    (d.casamentos || []).forEach(c => {
      const o = document.createElement('option'); o.value = c.id; o.textContent = c.nome; sel.appendChild(o);
    });
  } catch (e) {}
  auditarFiltrar();
}
function audParams(){
  const p = ['por_pagina=60', 'pagina=' + AUD_PAGINA];
  const cas = document.getElementById('aud-casamento').value;
  const accao = document.getElementById('aud-accao').value;
  const de = document.getElementById('aud-de').value, ate = document.getElementById('aud-ate').value;
  const q = document.getElementById('aud-q').value.trim();
  if (cas && cas !== 'todos') p.push('casamento=' + encodeURIComponent(cas));
  if (accao) p.push('accao=' + encodeURIComponent(accao));
  if (de) p.push('de=' + de); if (ate) p.push('ate=' + ate);
  if (q) p.push('q=' + encodeURIComponent(q));
  return p.join('&');
}
async function auditarFiltrar(){ AUD_PAGINA = 1; AUD_ROWS = []; await auditarCarregar(); }
async function auditarMais(){ AUD_PAGINA++; await auditarCarregar(true); }
async function auditarCarregar(concat){
  const d = await api('registo_auditoria&' + audParams());
  if (!d || !d.success) return;
  AUD_ROWS = concat ? AUD_ROWS.concat(d.registos || []) : (d.registos || []);
  // preencher a lista de ações (uma vez), preservando a escolha
  const selA = document.getElementById('aud-accao');
  if (selA.options.length <= 1 && (d.accoes || []).length){
    d.accoes.forEach(a => { const o = document.createElement('option'); o.value = a; o.textContent = a; selA.appendChild(o); });
  }
  document.getElementById('aud-total').textContent =
    d.total + ' ação(ões)' + (audTemFiltro() ? ' (filtradas)' : '') + '.';
  pintarAuditoria();
  document.getElementById('aud-mais').style.display = d.ha_mais ? '' : 'none';
}
function audTemFiltro(){
  return document.getElementById('aud-casamento').value !== 'todos'
    || document.getElementById('aud-accao').value
    || document.getElementById('aud-de').value || document.getElementById('aud-ate').value
    || document.getElementById('aud-q').value.trim();
}
function auditarLimpar(){
  document.getElementById('aud-casamento').value = 'todos';
  document.getElementById('aud-accao').value = '';
  document.getElementById('aud-de').value = ''; document.getElementById('aud-ate').value = '';
  document.getElementById('aud-q').value = '';
  auditarFiltrar();
}
function pintarAuditoria(){
  const cx = document.getElementById('aud-resultado');
  if (!AUD_ROWS.length){ cx.innerHTML = '<div class="dica">Sem ações para estes filtros.</div>'; return; }
  const linhas = AUD_ROWS.map(r => {
    const cas = +r.casamento_id === 0 ? '<i>Plataforma</i>' : (r.casamento ? esc(r.casamento) : ('#' + r.casamento_id));
    const quem = esc(r.utilizador || '—') + (r.papel ? ' <small style="color:#8a8f88">(' + esc(r.papel) + ')</small>' : '');
    const det = [r.alvo, r.detalhe].filter(Boolean).map(esc).join(' · ');
    return `<tr><td class="a-quando">${esc((r.criado_em||'').replace('T',' ').slice(0,16))}</td>`
         + `<td>${cas}</td><td class="a-accao">${esc(r.accao)}</td>`
         + `<td>${quem}</td><td>${det}</td></tr>`;
  }).join('');
  cx.innerHTML = '<div style="overflow-x:auto"><table id="aud-tabela"><thead><tr>'
    + '<th>Quando</th><th>Casamento</th><th>Ação</th><th>Quem</th><th>Detalhe</th>'
    + '</tr></thead><tbody>' + linhas + '</tbody></table></div>';
}

// ---------- o formulário de novo casamento: licença e contas ----------
function licencaMudou(){
  const el = document.getElementById('n-licenca-outro');
  if (el) el.style.display = document.getElementById('n-licenca').value === 'outro' ? '' : 'none';
}
function licencaMesesNovo(){
  const v = document.getElementById('n-licenca').value;
  return v === 'outro'
    ? Math.max(0, parseInt(document.getElementById('n-licenca-meses').value || '0', 10))
    : parseInt(v, 10);
}
// Sugere um utilizador único para o porteiro, a partir dos nomes. Só enquanto
// ninguém lhe tocar — quem escrever o seu próprio manda.
function slugNome(s){
  return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}
let PORT_TOCADO = false;
function sugerirPorteiro(){
  const eIn = document.getElementById('n-porteiro-email');
  const sIn = document.getElementById('n-porteiro-senha');
  if (!eIn) return;
  if (!eIn.dataset.lig){ eIn.addEventListener('input', () => PORT_TOCADO = true); eIn.dataset.lig = '1'; }
  if (PORT_TOCADO) return;
  const base = [slugNome((document.getElementById('n-noiva')||{}).value),
                slugNome((document.getElementById('n-noivo')||{}).value)].filter(Boolean).join('-') || 'casamento';
  const suf = Math.random().toString(36).slice(2, 5);
  const host = (location.hostname || 'convite.local').replace(/^www\./, '');
  eIn.value = `porta-${base}-${suf}@${host}`;
  if (sIn && !sIn.value){
    sIn.value = 'porta' + Math.random().toString(36).slice(2, 10);
    const cIn = document.getElementById('n-porteiro-confirmar');
    if (cIn) cIn.value = sIn.value;   // a sugestão já vem confirmada consigo mesma
  }
}

// ---------- verificações de preenchimento das contas (email / senha) ----------
// As mesmas do formulário público, aqui só nos campos das contas: o email tem
// de parecer um email; a palavra-passe, se se escrever uma, precisa de 8 e de
// bater certo com a repetição. Em branco, a conta não se cria (ou a senha é
// gerada) — por isso um par vazio é válido.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const valc = id => (document.getElementById(id).value || '').trim();
function marca(id, erro){
  const el = document.getElementById(id), c = el.closest('.campo'); if (!c) return !erro;
  const box = c.querySelector('.err');
  if (erro){ c.classList.add('mau'); c.classList.remove('ok'); if (box) box.textContent = erro; el.setAttribute('aria-invalid','true'); }
  else { c.classList.remove('mau'); el.removeAttribute('aria-invalid'); if (valc(id)) c.classList.add('ok'); else c.classList.remove('ok'); }
  return !erro;
}
function regraConta(id){
  const v = valc(id);
  switch (id){
    case 'n-noivos-email':
      if (!v) return '';                                   // sem email: não se cria a conta
      return EMAIL_RE.test(v) ? '' : 'Esse email não parece válido.';
    case 'n-noivos-senha':
      if (!v) return '';                                   // em branco: senha gerada
      return v.length >= 8 ? '' : 'Pelo menos 8 caracteres.';
    case 'n-noivos-confirmar':
      if (!valc('n-noivos-senha')) return '';
      return v === document.getElementById('n-noivos-senha').value ? '' : 'As palavras-passe não coincidem.';
    case 'n-porteiro-email':
      if (!v && !valc('n-porteiro-senha')) return '';      // par vazio: opcional
      return EMAIL_RE.test(v) ? '' : 'Email do porteiro inválido.';
    case 'n-porteiro-senha':
      if (!v) return '';
      return v.length >= 8 ? '' : 'Pelo menos 8 caracteres para o porteiro.';
    case 'n-porteiro-confirmar':
      if (!valc('n-porteiro-senha')) return '';
      return v === document.getElementById('n-porteiro-senha').value ? '' : 'As palavras-passe não coincidem.';
  }
  return '';
}
function validaConta(id){ return marca(id, regraConta(id)); }
const CAMPOS_CONTA = ['n-noivos-email','n-noivos-senha','n-noivos-confirmar',
                      'n-porteiro-email','n-porteiro-senha','n-porteiro-confirmar'];
const PARES_CONTA = { 'n-noivos-senha':['n-noivos-confirmar'], 'n-noivos-confirmar':['n-noivos-senha'],
                      'n-porteiro-senha':['n-porteiro-confirmar','n-porteiro-email'],
                      'n-porteiro-confirmar':['n-porteiro-senha'],
                      'n-porteiro-email':['n-porteiro-senha'] };
CAMPOS_CONTA.forEach(id => {
  const el = document.getElementById(id); if (!el) return;
  el.addEventListener('blur', () => validaConta(id));
  el.addEventListener('input', () => {
    if (el.closest('.campo').classList.contains('mau')) validaConta(id);
    (PARES_CONTA[id] || []).forEach(p => { const pe = document.getElementById(p);
      if (pe && pe.closest('.campo').classList.contains('mau')) validaConta(p); });
  });
});

// A força de uma palavra-passe, para os dois campos de senha.
function ligarForca(campoId, caixaId, rotId){
  const el = document.getElementById(campoId), box = document.getElementById(caixaId);
  if (!el || !box) return;
  el.addEventListener('input', () => {
    const v = el.value, rot = document.getElementById(rotId);
    let n = 0;
    if (v.length >= 8) n++;
    if (v.length >= 12) n++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) n++;
    if (/\d/.test(v) && /[^\w\s]/.test(v)) n++;
    if (v && n === 0) n = 1;
    box.className = 'pw-forca f' + n;
    rot.textContent = v ? ['', 'fraca', 'razoável', 'boa', 'forte'][n] : '';
  });
}
ligarForca('n-noivos-senha', 'forca-ne', 'rot-ne');
ligarForca('n-porteiro-senha', 'forca-pe', 'rot-pe');
// A conta administrativa: limpa a marca de erro do email enquanto se corrige.
document.getElementById('c-email')?.addEventListener('input', () => {
  const c = document.getElementById('c-email').closest('.campo');
  if (c && c.classList.contains('mau')) marca('c-email',
    EMAIL_RE.test(document.getElementById('c-email').value.trim()) ? '' : 'Esse email não parece válido.');
});

// Mostrar / ocultar uma palavra-passe.
function verSenha(id, olhoId){
  const el = document.getElementById(id), b = document.getElementById(olhoId), ver = el.type === 'password';
  el.type = ver ? 'text' : 'password';
  b.textContent = ver ? 'ocultar' : 'mostrar';
}

async function abrir(id){
  const d = await api('casamento_abrir&id=' + id, { method:'POST' });
  if (d && d.success) location.href = 'index.php';
}
async function criar(){
  const v = id => (document.getElementById(id).value || '').trim();
  if (!v('n-nome') && !v('n-noiva') && !v('n-noivo')) {
    return toast('Indique ao menos os nomes dos noivos.', true);
  }
  // As contas passam pelas mesmas verificações do formulário público: um email
  // torto ou uma confirmação que não bate certo são apanhados aqui, e o primeiro
  // campo com erro chama o olho a si.
  let primeiro = null;
  CAMPOS_CONTA.forEach(id => { if (!validaConta(id) && !primeiro) primeiro = id; });
  if (primeiro){
    const el = document.getElementById(primeiro);
    el.focus(); el.closest('.campo').scrollIntoView({ behavior:'smooth', block:'center' });
    return toast('Reveja os dados das contas assinalados a vermelho.', true);
  }
  const d = await api('casamento_criar', { method:'POST', body: JSON.stringify({
    nome: v('n-nome'), noiva: v('n-noiva'), noivo: v('n-noivo'), data: v('n-data'),
    hora: v('n-hora'), local: v('n-local'), cidade: v('n-cidade'), maps: v('n-maps'),
    convidados: v('n-convidados'), whatsapp: v('n-whatsapp'),
    orcamento_total: v('n-orcamento'),
    licenca_meses: licencaMesesNovo(),
    licenca_ativa: document.getElementById('n-licenca-ativa').checked,
    noivos_email: v('n-noivos-email'), noivos_senha: v('n-noivos-senha'),
    porteiro_email: v('n-porteiro-email'), porteiro_senha: v('n-porteiro-senha'),
    civil_hora: v('n-civil-hora'), civil_local: v('n-civil-local'), civil_maps: v('n-civil-maps'),
    religiosa_hora: v('n-religiosa-hora'), religiosa_local: v('n-religiosa-local'), religiosa_maps: v('n-religiosa-maps'),
  }) });
  if (!d || !d.success) return;
  // As senhas geradas mostram-se UMA vez: se recarregasse já, perdiam-se. Só se
  // recarrega quando não há nada de secreto para o admin copiar.
  const contas = d.contas || {};
  const linhas = [];
  ['noivos','porteiro'].forEach(t => {
    const c = contas[t];
    if (c) linhas.push(`<b>${t === 'noivos' ? 'Noivos' : 'Porteiro'}</b>: <span class="cod">${esc(c.email)}</span>`
      + (c.senha ? ` · senha <b class="cod">${esc(c.senha)}</b>` : ' · conta já existente, ligada'));
  });
  const lic = d.licenca && !d.licenca.ilimitada
    ? (d.licenca.iniciada ? `Licença de ${d.licenca.meses} mês(es), até ${esc((d.licenca.ate||'').split('-').reverse().join('/'))}.`
                          : `Licença de ${d.licenca.meses} mês(es), por iniciar.`)
    : 'Sem limite de licença.';
  const cx = document.getElementById('n-resultado');
  cx.style.display = '';
  cx.innerHTML = `Casamento <b>${esc(d.nome)}</b> criado. ${lic}`
    + (linhas.length ? '<br>' + linhas.join('<br>') + '<br><small>Entregue as senhas agora — não voltam a aparecer.</small>' : '')
    + `<br><button class="btn btn-sm" style="margin-top:.6rem" onclick="verVista('casamentos');carregarCasamentos()">Ver nos casamentos</button>`;
  ['n-nome','n-noiva','n-noivo','n-data','n-hora','n-local','n-cidade','n-maps','n-convidados','n-whatsapp',
   'n-orcamento','n-civil-hora','n-civil-local','n-civil-maps','n-religiosa-hora','n-religiosa-local',
   'n-religiosa-maps','n-noivos-email','n-noivos-senha','n-noivos-confirmar',
   'n-porteiro-email','n-porteiro-senha','n-porteiro-confirmar']
    .forEach(id => { const e = document.getElementById(id); if (e) e.value = ''; });
  // Limpa as marcas de erro/válido e as barras de força, para o formulário
  // ficar como novo à próxima.
  document.querySelectorAll('#vista-novo .campo').forEach(c => c.classList.remove('mau','ok'));
  ['forca-ne','forca-pe'].forEach(i => { const e = document.getElementById(i); if (e) e.className = 'pw-forca'; });
  PORT_TOCADO = false;
  toast('Casamento criado.');
}
// Aprovar e recusar um registo viviam aqui. Agora é uma decisão só, e é a do
// pedido de licença: ver licDecidir(), em Licenças. Um casamento aprovado sem
// licença nenhuma era um casal que entrava e não podia fazer nada.

// ---------- arquivar, reabrir, apagar ----------
// Cada mudança de estado diz o que muda para as pessoas, e não só para a ficha.
const AVISO_ESTADO = {
  arquivado: { titulo: 'Arquivar «%s»?', icone: '📦', botao: 'Arquivar',
    texto: 'Sai das listas de trabalho, e as contas que só existem por causa dele '
         + '<b>ficam paradas</b> — o casal deixa de entrar.<br><br>'
         + '<b>Nada se apaga</b>: reabrir devolve o casamento e as contas.' },
  suspenso:  { titulo: 'Suspender «%s»?', icone: '⏸️', botao: 'Suspender', perigo: true,
    texto: 'O casal deixa de entrar, e os <b>convites deixam de abrir</b> para os '
         + 'convidados.<br><br><b>Nada se apaga.</b>' },
  ativo:     { titulo: 'Reabrir «%s»?', icone: '✅', botao: 'Reabrir',
    texto: 'Volta às listas de trabalho e o casal volta a entrar. As contas que ficaram '
         + 'paradas com ele voltam também.' },
};
/**
 * A licença deste casamento está EM VIGOR, e por isso prende a casa?
 *
 * Em vigor é: concedida e ainda dentro do prazo. Uma licença sem prazo
 * (licenca_dias nulo) é ilimitada — está em vigor sempre. Uma que já expirou
 * não prende nada: o que ela dava, já não dá.
 */
function licencaPrende(c){
  if ((c.licenca_estado || 'sem') !== 'ativa') return false;
  return c.licenca_dias === null || c.licenca_dias === undefined || +c.licenca_dias >= 0;
}

async function mudarEstado(id, estado, nome){
  const a = AVISO_ESTADO[estado];
  const r = await licConfirmar({
    titulo: a.titulo.replace('%s', licEsc(nome)), icone: a.icone,
    perigo: !!a.perigo, confirmar: a.botao, texto: a.texto
  });
  if (!r.sim) return;
  const d = await api('casamento_estado&id=' + id + '&estado=' + estado, { method:'POST' });
  if (!d || !d.success) return;
  // Dizer quantas contas foram atrás: quem arquiva tem de saber que fechou a
  // porta a pessoas, e não só a um casamento.
  if (d.contas_paradas)  toast(d.contas_paradas + ' conta(s) ficaram paradas com ele.');
  if (d.contas_ativadas) toast(d.contas_ativadas + ' conta(s) voltaram com ele.');
  setTimeout(() => location.reload(), d.contas_paradas || d.contas_ativadas ? 1600 : 0);
}
async function apagar(id, nome){
  // Escrever o nome, e não só carregar em "OK": é a única coisa aqui que não
  // se desfaz, e um clique distraído não deve chegar para a fazer.
  const r = await licConfirmar({
    titulo: 'Apagar «' + licEsc(nome) + '» de vez?',
    icone: '🗑️', perigo: true, confirmar: 'Apagar de vez',
    texto: 'Vão-se os <b>convites</b>, as <b>pessoas</b>, as <b>mesas</b>, o <b>desenho</b> '
         + 'e o <b>histórico</b>. Não se desfaz.<br><br>'
         + 'Se ainda quiser os dados, cancele e use «Levar os dados» primeiro.',
    escrever: { rot: 'Nome do casamento', valor: nome,
                falta: 'O nome não confere. Nada foi apagado.' }
  });
  if (!r.sim) return;
  const d = await api('casamento_apagar&id=' + id, { method:'POST' });
  if (!d || !d.success) return;
  toast('Apagado: ' + (d.levou ? `${d.levou.convites} convite(s), ${d.levou.pessoas} pessoa(s).` : ''));
  setTimeout(() => location.reload(), 1500);
}

// ---------- gestão de dados (a pastilha do admin) ----------
async function carregarDadosCasamentos(){
  const alvo = document.getElementById('dados-cas-lista');
  if (!alvo || alvo.dataset.pronto) return;   // carrega uma vez
  const d = await api('casamento_lista&estado=todos');
  if (!d || !d.success) return;
  alvo.dataset.pronto = '1';
  alvo.innerHTML = (d.casamentos || []).map(c =>
    `<label><input type="checkbox" value="${c.id}"> ${esc(c.nome)}
       <small style="color:#8a8f88">${esc(c.estado)}</small></label>`).join('')
    || '<div class="dica">Nenhum casamento.</div>';
}
function dadosCasToggle(){
  const on = document.querySelector('#dados-inc input[value="casamentos"]').checked;
  document.getElementById('dados-cas-bloco').style.display = on ? '' : 'none';
}
function dadosCasTodos(v){
  document.querySelectorAll('#dados-cas-lista input').forEach(i => i.checked = v);
}
function incEscolhidos(){
  return Array.from(document.querySelectorAll('#dados-inc input[value]:checked')).map(i => i.value);
}
function casEscolhidos(){
  return Array.from(document.querySelectorAll('#dados-cas-lista input:checked')).map(i => i.value);
}
// ---------- tudo o que há no sistema (ao lado da escolha por âmbitos) ----------
const INC_TUDO = ['casamentos','modelos','contas_casamento','contas_admin'];
function exportarSistemaTudo(){
  const q = ['ambito=sistema', 'inc=' + encodeURIComponent(INC_TUDO.join(','))];
  if (document.getElementById('dados-tudo-senhas').checked) q.push('senhas=1');
  location.href = 'api.php?action=dados_exportar&' + q.join('&');   // sem 'casamentos' = todos
}
async function importarSistemaTudo(){
  const inp = document.getElementById('dados-ficheiro-tudo');
  const f = inp.files[0]; inp.value = '';
  if (!f) return;
  let dados;
  try { dados = JSON.parse(await f.text()); }
  catch (e) { return toast('Esse ficheiro não é um JSON válido.', true); }
  if (!dados || dados.formato !== 'casamento-web/1') {
    return toast('Este ficheiro não é uma exportação deste sistema.', true);
  }
  const r = await licConfirmar({
    titulo: 'Importar tudo o que o ficheiro traz?',
    icone: '📥', confirmar: 'Importar tudo',
    texto: 'Os casamentos entram como <b>novos</b>; os modelos e as contas que já existam '
         + '<b>saltam-se</b>.<br><br><b>Nada do que já cá está é substituído.</b>'
  });
  if (!r.sim) return;
  const d = await api('sistema_importar', { method:'POST',
    body: JSON.stringify({ inc: INC_TUDO, ficheiro: dados }) });
  if (!d || !d.success) return;
  mostrarImportado(d);
}
async function apagarSistemaTudo(){
  // Aviso e confirmação escrita na mesma janela: quem lê a lista do que se vai
  // perder é quem escreve as palavras, sem um "OK" pelo meio a interromper.
  const r = await licConfirmar({
    titulo: 'Apagar tudo o que há no sistema?',
    icone: '☢️', perigo: true, confirmar: 'Apagar tudo',
    texto: '<ul class="lic-conf-lista">'
         + '<li>Todos os <b>casamentos</b>, por inteiro (listas, mesas, versões, orçamentos)</li>'
         + '<li>Os <b>modelos personalizados</b> (ficam os de origem)</li>'
         + '<li>As <b>contas</b> de casamento e as administrativas — a sua nunca</li></ul>'
         + '<br><b>Não se desfaz.</b> Exporte primeiro, se quiser poder voltar atrás.',
    escrever: { rot: 'Confirmação escrita', valor: 'APAGAR TUDO',
                falta: 'O texto não confere. Nada foi apagado.' }
  });
  if (!r.sim) return;
  const d = await api('sistema_repor_fabrica', { method:'POST', body: JSON.stringify({ tudo: true }) });
  if (!d || !d.success) return;
  mostrarApagado(d);
}

function exportarDados(){
  const inc = incEscolhidos();
  if (!inc.length) return toast('Escolha ao menos um âmbito.', true);
  const q = ['ambito=sistema', 'inc=' + encodeURIComponent(inc.join(','))];
  if (inc.includes('casamentos')){
    const ids = casEscolhidos();
    if (ids.length) q.push('casamentos=' + ids.join(','));   // vazio = todos
  }
  const temContas = inc.includes('contas_casamento') || inc.includes('contas_admin');
  if (document.getElementById('dados-senhas').checked && temContas) q.push('senhas=1');
  location.href = 'api.php?action=dados_exportar&' + q.join('&');
}
async function importarDados(){
  const inp = document.getElementById('dados-ficheiro');
  const f = inp.files[0]; inp.value = '';
  const inc = incEscolhidos();
  if (!inc.length) return toast('Escolha o que trazer do ficheiro.', true);
  if (!f) return;
  let dados;
  try { dados = JSON.parse(await f.text()); }
  catch (e) { return toast('Esse ficheiro não é um JSON válido.', true); }
  if (!dados || dados.formato !== 'casamento-web/1') {
    return toast('Este ficheiro não é uma exportação deste sistema.', true);
  }
  const rot = { casamentos:'casamentos (como novos)', modelos:'modelos',
                contas_casamento:'contas de casamento', contas_admin:'contas administrativas' };
  const r = await licConfirmar({
    titulo: 'Trazer do ficheiro?',
    icone: '📥', confirmar: 'Trazer',
    texto: '<ul class="lic-conf-lista">'
         + inc.map(i => '<li>' + licEsc(rot[i]) + '</li>').join('') + '</ul><br>'
         + 'Os casamentos entram como <b>novos</b>; modelos e contas que já existam '
         + '<b>saltam-se</b>. Nada do que já cá está é substituído.'
  });
  if (!r.sim) return;
  const d = await api('sistema_importar', { method:'POST', body: JSON.stringify({ inc, ficheiro: dados }) });
  if (!d || !d.success) return;
  mostrarImportado(d);
}
/** O que a importação trouxe, dito no painel — igual para «tudo» e para a escolha. */
function mostrarImportado(d){
  const r = d.res || {};
  const cx = document.getElementById('dados-resultado');
  cx.style.display = '';
  cx.innerHTML = 'Trazidos: '
    + `<b>${r.casamentos || 0}</b> casamento(s), <b>${r.modelos || 0}</b> modelo(s), `
    + `<b>${r.contas || 0}</b> conta(s)` + (r.contas_saltadas ? ` (${r.contas_saltadas} já existiam)` : '') + '.';
  toast('Importação concluída.');
  document.getElementById('dados-cas-lista').dataset.pronto = '';   // relista os casamentos novos
  setTimeout(() => { carregarCasamentos(); carregarDadosCasamentos(); }, 300);
}
function casModo(){
  const r = document.querySelector('input[name="cas-modo"]:checked');
  return r && r.value === 'apagar' ? 'apagar' : 'esvaziar';
}
async function apagarDados(){
  const inc = incEscolhidos();
  const alvos = [];
  const linhas = [];
  if (inc.includes('modelos')){ alvos.push('modelos'); linhas.push('apagar os modelos personalizados (ficam os de origem)'); }
  if (inc.includes('contas_casamento')){ alvos.push('contas_casamento'); linhas.push('apagar as contas de casamento (noivos e porteiros)'); }
  if (inc.includes('contas_admin')){     alvos.push('contas_admin');     linhas.push('apagar as contas administrativas (exceto a sua)'); }
  const ids = inc.includes('casamentos') ? casEscolhidos() : [];
  const modo = casModo();
  if (ids.length){
    alvos.push('casamentos');
    linhas.push(modo === 'apagar'
      ? 'APAGAR por inteiro ' + ids.length + ' casamento(s)'
      : 'esvaziar ' + ids.length + ' casamento(s): lista, mesas, versões, orçamento');
  }
  if (!alvos.length) {
    return toast('Assinale contas, modelos e/ou casamentos (com casamentos escolhidos) para apagar.', true);
  }
  const r = await licConfirmar({
    titulo: 'Apagar — isto elimina dados',
    icone: '🗑️', perigo: true, confirmar: 'Apagar',
    texto: '<ul class="lic-conf-lista">'
         + linhas.map(l => '<li>' + licEsc(l) + '</li>').join('') + '</ul>'
         + '<br><b>Não se desfaz.</b>'
  });
  if (!r.sim) return;
  const d = await api('sistema_repor_fabrica', { method:'POST',
    body: JSON.stringify({ alvos, casamentos: ids, casamentos_modo: modo }) });
  if (!d || !d.success) return;
  mostrarApagado(d);
}
/** O que a limpeza apagou, dito no painel — igual para «tudo» e para a escolha. */
function mostrarApagado(d){
  const r = d.res || {};
  const p = [];
  if (r.modelos != null) p.push(`<b>${r.modelos}</b> modelo(s)`);
  if (r.contas_casamento != null) p.push(`<b>${r.contas_casamento}</b> conta(s) de casamento`);
  if (r.contas_admin != null) p.push(`<b>${r.contas_admin}</b> conta(s) administrativa(s)`);
  if (r.casamentos != null) p.push(`<b>${r.casamentos}</b> casamento(s) esvaziado(s)`);
  if (r.casamentos_apagados != null) p.push(`<b>${r.casamentos_apagados}</b> casamento(s) apagado(s)`);
  const cx = document.getElementById('dados-resultado');
  cx.style.display = '';
  cx.innerHTML = 'Apagado: ' + (p.join(' · ') || 'nada — já não havia o que apagar') + '.';
  toast('Dados apagados.');
  document.getElementById('dados-cas-lista').dataset.pronto = '';
  setTimeout(() => { carregarCasamentos(); carregarDadosCasamentos(); }, 300);
}

// ---------- os casamentos, por ordem de uso ----------
let ESTADO_CAS = 'ativo';
function filtrarCasamentos(e, saltar){
  ESTADO_CAS = e;
  document.querySelectorAll('#filtros-cas .chip').forEach(c =>
    c.classList.toggle('on', c.dataset.estado === e));
  carregarCasamentos();
  // Vindo de um número lá de cima, o filtro muda uma lista que está fora do
  // ecrã: sem levar o olho lá, parece que o clique não fez nada.
  if (saltar) irPara('lista-casamentos');
}
const quando = s => {
  if (!s) return 'nunca aberto';
  const d = new Date(s.replace(' ', 'T'));
  const dias = Math.floor((Date.now() - d) / 86400000);
  if (dias <= 0) return 'aberto hoje';
  if (dias === 1) return 'aberto ontem';
  if (dias < 30) return 'aberto há ' + dias + ' dias';
  return 'aberto em ' + s.slice(0, 10);
};
async function carregarCasamentos(){
  const alvo = document.getElementById('lista-casamentos');
  const q = (document.getElementById('q-cas').value || '').trim();
  const d = await api('casamento_lista&estado=' + ESTADO_CAS + (q ? '&q=' + encodeURIComponent(q) : ''));
  if (!d || !d.success) return;
  CASAMENTOS = {};
  d.casamentos.forEach(c => { CASAMENTOS[c.id] = c; });   // para os modais não irem procurar outra vez
  if (!d.casamentos.length){
    alvo.innerHTML = '<div class="dica">Nenhum casamento aqui.</div>'; return;
  }
  alvo.innerHTML = d.casamentos.map(c => {
    const aberto = +c.id === +d.aberto;
    const arq = c.estado === 'arquivado';
    const n = esc(c.nome);
    // A ação principal fica sozinha e à vista; suspender, arquivar e apagar
    // vão para o "⋯". Ao mesmo peso, um clique distraído arquivava a festa de
    // alguém — e as três eram lidas como se fossem tão comuns como abrir.
    let principal = '';
    if (aberto) principal = '<a class="btn btn-ouro btn-sm" href="index.php">Continuar</a>';
    else if (!arq) principal = `<button class="btn btn-ouro btn-sm" onclick="abrir(${c.id})">Abrir</button>`;

    const mais = [];
    // Sair sem ir embora: quem responde pela casa entra e sai de casamentos
    // alheios o dia todo, e a única saída era abrir outro ou terminar sessão.
    if (aberto) mais.push(`<button onclick="fecharCasamento()">Sair deste casamento</button>`);
    if (MANDA_NA_CASA) {
      if (arq) {
        mais.push(`<button onclick="mudarEstado(${c.id},'ativo','${n}')">Reabrir</button>`);
        mais.push(`<a href="api.php?action=dados_exportar&ambito=casamento&id=${c.id}">Levar os dados</a>`);
        mais.push('<hr>');
        mais.push(`<button class="perigo" onclick="apagar(${c.id},'${n}')">Apagar para sempre</button>`);
      } else {
        mais.push(`<button onclick="editarTudo(${c.id})">Editar todos os dados…</button>`);
        mais.push(`<button onclick="gerirLicenca(${c.id})">Licença: módulos e prazo…</button>`);
        mais.push(`<button class="perigo" onclick="licRevogarDe(${c.id},'${n}')">Revogar licença…</button>`);
        // Fechar a casa a um casal que tem licença EM VIGOR é tirar-lhe uma
        // coisa por que pagou, e sem lhe dizer porquê: a licença continuaria a
        // dizer «ativa» enquanto ele não conseguia entrar. Primeiro decide-se
        // a licença — revogar (com motivo, que o casal lê) ou deixá-la
        // expirar —, e só então se fecha. Uma licença já expirada não trava
        // nada: aí não há nada a tirar.
        const fecho = [];
        if (c.estado === 'suspenso')
          fecho.push(`<button onclick="mudarEstado(${c.id},'ativo','${n}')">Reativar</button>`);
        if (!licencaPrende(c)) {
          if (c.estado !== 'suspenso')
            fecho.push(`<button class="perigo" onclick="mudarEstado(${c.id},'suspenso','${n}')">Suspender</button>`);
          fecho.push(`<button class="perigo" onclick="mudarEstado(${c.id},'arquivado','${n}')">Arquivar</button>`);
        }
        // O risco separa-se do resto — mas só há risca se houver o que separar.
        if (fecho.length) mais.push('<hr>', ...fecho);
      }
    }
    const menu = mais.length
      ? `<span class="mm"><button class="btn btn-sm" title="Mais ações"
             onclick="abrirMais(event,${c.id})"><svg class="ico-mais" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.4" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="12.6" cy="8" r="1.5"/></svg></button>
           <span class="mm-pop" id="mm-${c.id}" style="display:none">${mais.join('')}</span></span>`
      : '';

    // Confirmações: um casamento com 200 pessoas e 3 confirmadas é outra
    // notícia que um com 200 e 180, e a lista dizia os dois da mesma maneira.
    const pes = +c.pessoas || 0, conf = +c.confirmados || 0;
    const pc = pes ? Math.round(conf / pes * 100) : 0;

    return `<div class="cas${aberto ? ' aberto' : ''}">
      <div class="selo">${esc((c.nome || '?').slice(0,1).toUpperCase())}</div>
      <div>
        <div class="nm">${esc(c.nome)}
          ${aberto ? '<span class="et agora">aberto agora</span>' : ''}
          <span class="et ${esc(c.estado)}">${esc(c.estado)}</span></div>
        <div class="meta">
          ${dataCasamento(c.data_evento)}
          <span><b>${c.convites}</b> convites</span>
          <span><b>${pes}</b> pessoas${pes ? ` · <b>${conf}</b> confirmaram` : ''}</span>
          <span>${esc(quando(c.ultimo_acesso))}</span>
          ${licencaMeta(c)}
          ${modulosMeta(c)}
          ${+c.donos === 0 ? '<span class="falta">sem conta dos noivos</span>' : ''}
        </div>
        ${pes ? `<div class="barra" title="${conf} de ${pes} confirmaram (${pc}%)"><i style="width:${pc}%"></i></div>` : ''}
      </div>
      <div class="ac">${principal}${menu}</div>
    </div>`;
  }).join('');
}

/**
 * Quantos módulos a licença abre — e, sobretudo, se há um pedido à espera.
 *
 * Um casamento parado à porta é a coisa mais urgente desta lista: sem isto, a
 * única forma de o saber era ir à pastilha das Licenças procurar.
 */
function modulosMeta(c){
  const n = +c.lic_modulos || 0;
  const p = +c.lic_pedidos || 0;
  const est = c.licenca_estado || 'sem';
  let out = '';
  if (est === 'revogada') out += '<span class="falta">licença revogada</span>';
  else if (!n) out += '<span class="falta">sem módulos</span>';
  else out += `<span class="conta">${n} módulo(s)`
            + (c.licenca_pacote ? ` · ${esc(c.licenca_pacote)}` : '') + '</span>';
  if (p) out += `<span class="falta">${p} pedido(s) à espera</span>`;
  return out;
}

/** O que resta da licença, para a linha do casamento. */
function licencaMeta(c){
  const m = +c.licenca_meses || 0;
  if (!m) return '<span style="color:#b0b5af">licença sem limite</span>';
  if (!c.licenca_ate) return '<span class="falta">licença por iniciar</span>';
  const dias = c.licenca_dias == null ? null : +c.licenca_dias;
  if (dias == null) return '';
  if (dias < 0)  return '<span class="falta">licença expirada</span>';
  if (dias < 45) return `<span class="falta">licença: faltam ${dias} dia(s)</span>`;
  return `<span class="conta">licença: ~${Math.round(dias/30)} mes(es)</span>`;
}

/** Uma frase sobre o estado atual da licença, para o topo do modal. */
function licencaEstado(c){
  const m = +c.licenca_meses || 0;
  if (!m) return { txt: 'Sem limite de licença — o casamento não expira.', cls: 'ok' };
  if (!c.licenca_ate) return { txt: `Licença de ${m} mês(es), <b>por iniciar</b> — o relógio ainda não começou.`, cls: 'warn' };
  const dias = c.licenca_dias == null ? null : +c.licenca_dias;
  const ate = (c.licenca_ate || '').split('-').reverse().join('/');
  if (dias == null) return { txt: `Licença de ${m} mês(es).`, cls: '' };
  if (dias < 0)  return { txt: `Licença de ${m} mês(es) — <b>expirada</b> em ${esc(ate)} (há ${Math.abs(dias)} dia(s)).`, cls: 'danger' };
  if (dias < 45) return { txt: `Licença de ${m} mês(es) — termina em ${esc(ate)}, <b>faltam ${dias} dia(s)</b>.`, cls: 'warn' };
  return { txt: `Licença de ${m} mês(es) — ativa até ${esc(ate)} (~${Math.round(dias/30)} mês(es)).`, cls: 'ok' };
}

/** Gerir a licença de um casamento: um modal completo em vez de um prompt seco. */
let LICENCA_ALVO = 0;
async function gerirLicenca(id){
  const c = CASAMENTOS[id]; if (!c) return;
  LICENCA_ALVO = id;
  const est = licencaEstado(c);
  const m = +c.licenca_meses || 0;
  const jaIniciada = m > 0 && !!c.licenca_ate;
  $('lic-nome').textContent = c.nome || 'Casamento';
  // Os módulos vão buscar-se ao preçário e à ficha: a licença é uma coisa só —
  // o QUE se abre e ATÉ QUANDO —, e separá-las em dois sítios obrigava a
  // fazer duas vezes o mesmo caminho para tratar do mesmo assunto.
  $('lic-mods').innerHTML = '<div class="dica">A carregar…</div>';
  licDesenharModulos(id);
  $('lic-estado').className = 'segredo lic-estado ' + (est.cls || '');
  $('lic-estado').innerHTML = est.txt;
  // O período começa no que está definido; «Outro…» abre a caixa dos meses.
  const presets = [0, 3, 6, 12];
  $('lic-periodo').value = presets.includes(m) ? String(m) : 'outro';
  $('lic-outro').style.display = presets.includes(m) ? 'none' : '';
  $('lic-meses').value = presets.includes(m) ? '' : m;
  // Por omissão, iniciar/reiniciar o relógio: é o gesto comum (dar tempo novo).
  $('lic-reiniciar').checked = true;
  $('lic-reiniciar-linha').style.display = ($('lic-periodo').value === '0') ? 'none' : '';
  $('lic-reiniciar-rot').textContent = jaIniciada
    ? 'Reiniciar o relógio a contar de hoje' : 'Iniciar já o relógio (a contar de hoje)';
  abrirModal('ov-licenca');
}
/** Os módulos deste casamento, dentro do modal da licença. */
async function licDesenharModulos(id){
  if (!LIC_CAT) await licCarregarCatalogo();
  const f = await api('casamento_ficha&id=' + id);
  const tem = (f && f.success && f.licenca_modulos) ? f.licenca_modulos : {};
  const cx = $('lic-mods');
  if (!cx) return;
  if (!LIC_CAT){ cx.innerHTML = '<div class="dica">Não foi possível ler o preçário.</div>'; return; }

  cx.innerHTML = LIC_CAT.modulos.map(m => {
    const g = tem[m.chave];
    const escs = m.escaloes.map(e => {
      // Marca-se o escalão que corresponde ao que o casamento já tem.
      let igual = false;
      if (g && g.ativo){
        if (m.chave === 'convidados') igual = +e.limite === +g.limite;
        else if (m.chave === 'impresso' || m.chave === 'digital')
          igual = !!+e.editar === !!g.editar && !!+e.todos_modelos === !!g.todos_modelos;
        else igual = true;
      }
      return '<label class="lic-pi"><input type="radio" name="lm-' + licEsc(m.chave) + '" value="'
        + e.id + '"' + (igual ? ' checked' : '') + '>'
        + '<span class="lic-pi-txt">' + licEsc(e.nome) + '</span>'
        + '<span class="lic-pi-preco"><small>' + licKz(e.preco) + '</small></span></label>';
    }).join('');
    return '<div class="lic-pi-mod"><div class="lic-pi-nome">' + licEsc(m.icone || '•') + ' '
      + licEsc(m.nome) + '</div>'
      + '<label class="lic-pi"><input type="radio" name="lm-' + licEsc(m.chave) + '" value="0"'
      + (g && g.ativo ? '' : ' checked') + '>'
      + '<span class="lic-pi-txt">Não incluído</span></label>'
      + escs + '</div>';
  }).join('');
}

function licPeriodoMudou(){
  const v = $('lic-periodo').value;
  $('lic-outro').style.display = v === 'outro' ? '' : 'none';
  $('lic-reiniciar-linha').style.display = v === '0' ? 'none' : '';
}
function licMesesEscolhidos(){
  const v = $('lic-periodo').value;
  return v === 'outro' ? Math.max(0, Math.min(120, parseInt($('lic-meses').value || '0', 10))) : parseInt(v, 10);
}
async function guardarLicenca(){
  const meses = licMesesEscolhidos();
  const reiniciar = meses > 0 && $('lic-reiniciar').checked;
  const marc = [...document.querySelectorAll('#lic-mods input:checked')]
               .map(i => +i.value).filter(v => v > 0);
  // Um só gesto: os módulos e o prazo vão juntos, porque são a mesma licença.
  const d = await api('lic_conceder', { method:'POST',
    body: JSON.stringify({ casamento: LICENCA_ALVO, escaloes: marc, meses, reiniciar }) });
  if (!d || !d.success) return;
  fecharModal('ov-licenca');
  toast(d.modulos
    ? d.modulos + ' módulo(s) · ' + (meses ? meses + ' mês(es)' : 'sem limite de tempo') + '.'
    : 'Casamento ficou sem módulos.');
  carregarCasamentos();
}

// ---------- editar TODOS os dados de um casamento (identidade + evento + contas) ----------
let EDITAR_ALVO = 0;
async function editarTudo(id){
  EDITAR_ALVO = id;
  $('ed-segredo').style.display = 'none'; $('ed-segredo').innerHTML = '';
  // Limpa a caixa de nova conta, para não trazer o que ficou de outro casamento.
  ['ed-np-email','ed-np-senha'].forEach(i => { const e = $(i); if (e) e.value = ''; });
  document.querySelectorAll('#ov-editar .campo').forEach(c => c.classList.remove('mau','ok'));
  const nc = $('ed-nova-conta'); if (nc) nc.open = false;
  $('ed-contas').innerHTML = '<div class="dica">A carregar…</div>';
  abrirModal('ov-editar');
  const d = await api('casamento_ficha&id=' + id);
  if (!d || !d.success){ fecharModal('ov-editar'); return; }
  const c = d.casamento, ev = d.evento || {};
  $('ed-id').value = id;
  $('ed-nome-topo').textContent = c.nome || 'Casamento';
  $('ed-cnome').value = c.nome || '';
  $('ed-noiva').value = c.noiva || '';
  $('ed-noivo').value = c.noivo || '';
  $('ed-data').value  = (c.data_evento && c.data_evento !== '0000-00-00') ? c.data_evento : '';
  const pv = (k, id2) => { const e = $(id2); if (e) e.value = ev[k] || ''; };
  pv('evento.hora','ed-hora'); pv('evento.local','ed-local'); pv('evento.cidade','ed-cidade');
  pv('evento.venue_titulo','ed-venue');
  pv('evento.convidados','ed-convidados'); pv('evento.whatsapp','ed-whatsapp'); pv('evento.maps','ed-maps');
  pv('evento.civil_hora','ed-civil-hora'); pv('evento.civil_local','ed-civil-local'); pv('evento.civil_maps','ed-civil-maps');
  pv('evento.religiosa_hora','ed-religiosa-hora'); pv('evento.religiosa_local','ed-religiosa-local'); pv('evento.religiosa_maps','ed-religiosa-maps');
  // O orçamento passa pela máscara, para se ver com os separadores certos.
  const orc = $('ed-orcamento');
  if (orc){ orc.value = ev['orcamento.total'] || '';
    if (window.Moeda && orc.value) orc.value = window.Moeda.paraCampo(orc.value); }
  pintarContasEd(d.contas || []);
  // A opção «Porteiro» só se oferece a quem tem a porta na licença: sem o
  // módulo, a conta entrava e não encontrava nada — e a API recusa-a na mesma.
  const mods = d.licenca_modulos || {};
  const temPorta = !!(mods.porta && mods.porta.ativo);
  const selPapel = $('ed-np-papel');
  if (selPapel){
    const opPorta = [...selPapel.options].find(o => o.value === 'porteiro');
    if (opPorta) opPorta.hidden = !temPorta;
    if (!temPorta) selPapel.value = 'noivos';
    const nota = $('ed-np-sem-porta');
    if (nota) nota.style.display = temPorta ? 'none' : '';
  }
}
function pintarContasEd(contas){
  const alvo = $('ed-contas');
  if (!contas.length){ alvo.innerHTML = '<div class="dica">Este casamento ainda não tem contas. Adicione uma abaixo.</div>'; return; }
  alvo.innerHTML = contas.map(a => {
    const uid = a.utilizador_id;
    const papel = a.papel === 'noivos' ? 'Noivos · gere o casamento' : 'Porteiro · só a porta';
    return `<div class="ed-conta" id="edc-${uid}">
      <div class="cab"><span class="et ${a.papel === 'noivos' ? 'agora' : ''}">${esc(papel)}</span>
        <span class="et ${esc(a.estado)}">${esc(a.estado)}</span></div>
      <div class="lf" style="grid-template-columns:2fr 2fr auto;margin:0">
        <div><label>Email</label><input type="email" id="edc-email-${uid}" value="${esc(a.email || '')}" autocapitalize="none" spellcheck="false"></div>
        <div><label>Nome</label><input type="text" id="edc-nome-${uid}" value="${esc(a.nome || '')}"></div>
        <div class="ac" style="align-self:end">
          <button class="btn btn-sm" onclick="guardarContaLigada(${uid})">Guardar</button>
          <button class="btn btn-sm" onclick="reporSenhaLigada(${uid}, '${esc(a.email)}')">Repor senha</button>
          <button class="btn btn-sm perigo" onclick="tirarContaLigada(${uid}, '${esc(a.nome || a.email)}')">Eliminar conta</button>
        </div>
      </div>
    </div>`;
  }).join('');
}
function dadosEventoEd(){
  const v = id => ($(id).value || '').trim();
  return {
    nome: v('ed-cnome'), noiva: v('ed-noiva'), noivo: v('ed-noivo'), data: v('ed-data'),
    hora: v('ed-hora'), local: v('ed-local'), cidade: v('ed-cidade'), maps: v('ed-maps'),
    venue_titulo: v('ed-venue'),
    convidados: v('ed-convidados'), whatsapp: v('ed-whatsapp'),
    orcamento_total: v('ed-orcamento'),
    civil_hora: v('ed-civil-hora'), civil_local: v('ed-civil-local'), civil_maps: v('ed-civil-maps'),
    religiosa_hora: v('ed-religiosa-hora'), religiosa_local: v('ed-religiosa-local'), religiosa_maps: v('ed-religiosa-maps'),
  };
}
async function guardarDadosCasamento(){
  const corpo = Object.assign({ id: EDITAR_ALVO }, dadosEventoEd());
  const d = await api('casamento_editar', { method:'POST', body: JSON.stringify(corpo) });
  if (!d || !d.success) return;
  $('ed-nome-topo').textContent = d.nome || '';
  toast('Dados do casamento guardados.');
  carregarCasamentos();
}
async function adicionarConta(){
  const papel = $('ed-np-papel').value;
  const email = ($('ed-np-email').value || '').trim();
  const senha = $('ed-np-senha').value;
  const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  marca('ed-np-email', emailOk ? '' : 'Indique um email válido.');
  marca('ed-np-senha', (!senha || senha.length >= 8) ? '' : 'Pelo menos 8 caracteres (ou deixe em branco).');
  if (!emailOk || (senha && senha.length < 8)) return;
  const corpo = Object.assign({ id: EDITAR_ALVO }, dadosEventoEd());
  if (papel === 'noivos'){ corpo.noivos_email = email; corpo.noivos_senha = senha; }
  else { corpo.porteiro_email = email; corpo.porteiro_senha = senha; }
  const d = await api('casamento_editar', { method:'POST', body: JSON.stringify(corpo) });
  if (!d || !d.success) return;
  const c = (d.contas || {})[papel];
  const cx = $('ed-segredo');
  if (c){
    cx.style.display = '';
    cx.innerHTML = `Conta de <b>${papel === 'noivos' ? 'noivos' : 'porteiro'}</b> ligada: <span class="cod">${esc(c.email)}</span>`
      + (c.senha ? ` · senha <b class="cod">${esc(c.senha)}</b><br><small>Entregue-a agora — não volta a aparecer.</small>`
                 : ' · conta já existente, ligada.');
  }
  $('ed-np-email').value = $('ed-np-senha').value = '';
  editarTudoRecarregar();
  carregarCasamentos();
  toast('Conta adicionada.');
}
// Recarrega só a lista de contas do modal, sem fechar nem perder o segredo mostrado.
async function editarTudoRecarregar(){
  const d = await api('casamento_ficha&id=' + EDITAR_ALVO);
  if (d && d.success) pintarContasEd(d.contas || []);
}
async function guardarContaLigada(uid){
  const d = await api('utilizador_editar', { method:'POST', body: JSON.stringify({
    id: uid, nome: ($('edc-nome-' + uid).value || '').trim(),
    email: ($('edc-email-' + uid).value || '').trim(),
  }) });
  if (d && d.success){ toast('Conta guardada.'); carregarCasamentos(); }
}
/** A pergunta é a mesma nos dois sítios onde se repõe uma senha — vive aqui. */
async function pedirReporSenha(email){
  const r = await licConfirmar({
    titulo: 'Repor a senha de «' + licEsc(email) + '»?',
    icone: '🔑', confirmar: 'Repor senha',
    texto: 'A senha atual <b>deixa de servir</b> de imediato. A nova aparece aqui '
         + '<b>uma vez</b> — copie-a antes de fechar.'
  });
  return r.sim;
}
async function reporSenhaLigada(uid, email){
  if (!await pedirReporSenha(email)) return;
  const d = await api('utilizador_repor_senha&id=' + uid, { method:'POST' });
  if (!d || !d.success) return;
  const cx = $('ed-segredo');
  cx.style.display = '';
  cx.innerHTML = `Senha nova de <b>${esc(d.email)}</b>: <b class="cod">${esc(d.senha)}</b><br>
    <small>Entregue-a agora — não volta a aparecer.</small>`;
}
async function tirarContaLigada(uid, nome){
  const r = await licConfirmar({
    titulo: 'Eliminar a conta de «' + licEsc(nome) + '»?',
    icone: '🗑️', perigo: true, confirmar: 'Eliminar conta',
    texto: 'A conta é <b>apagada</b> e deixa de entrar. <b>Não se desfaz.</b><br><br>'
         + 'O email fica livre para uma conta nova.'
  });
  if (!r.sim) return;
  const d = await api('conta_apagar_do_casamento&utilizador=' + uid + '&casamento=' + EDITAR_ALVO, { method:'POST' });
  if (d && d.success){ toast('Conta eliminada.'); editarTudoRecarregar(); carregarCasamentos(); }
}

/** A data do casamento, e quanto falta — que é o que se quer saber primeiro. */
function dataCasamento(iso){
  if (!iso || iso === '0000-00-00') return '<span style="color:#b0b5af">sem data</span>';
  const d = new Date(iso + 'T12:00:00');
  if (isNaN(d)) return '';
  const hoje = new Date(); hoje.setHours(12,0,0,0);
  const dias = Math.round((d - hoje) / 86400000);
  const data = d.toLocaleDateString('pt-PT', { day:'2-digit', month:'short', year:'numeric' });
  let falta;
  if (dias > 1)       falta = `<span class="conta">faltam ${dias} dias</span>`;
  else if (dias === 1) falta = '<span class="conta">é amanhã</span>';
  else if (dias === 0) falta = '<span class="conta">é hoje</span>';
  else                falta = `<span style="color:#b0b5af">há ${Math.abs(dias)} dias</span>`;
  return `<span class="quando">${esc(data)}</span> ${falta}`;
}

/* O menu "⋯" (abrirMais) vive em assets/menu-mais.js: era o mesmo código aqui
   e em modelos.php, e uma correção feita num sítio não chegava ao outro. */

/** Leva o olho até um sítio da página e assinala-o por um instante. */
function irPara(id){
  const el = document.getElementById(id); if (!el) return;
  el.scrollIntoView({ behavior:'smooth', block:'center' });
}
async function fecharCasamento(){
  const d = await api('casamento_fechar', { method:'POST' });
  if (d && d.success) location.reload();
}

// ---------- contas (só o admin da plataforma) ----------
const esc = s => (s??'').toString().replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
const $ = id => document.getElementById(id);
let CONTAS = {};   // as contas já carregadas, para o editor não as ir procurar outra vez
let CASAMENTOS = {};  // idem, para os modais de licença e de edição completa

// Abrir e fechar as janelas modais (o «abrir» já existe, para abrir casamentos).
function abrirModal(id){ const e = $(id); if (e) e.classList.add('aberto'); }
function fecharModal(id){ const e = $(id); if (e) e.classList.remove('aberto'); }
// Só contas administrativas por aqui: suporte e admin. As de noivos/porteiro
// criam-se em cada casamento (no formulário de novo casamento, ou em Gestão).
function tipoMudou(){
  const tipo = $('c-tipo').value;
  $('nota-tipo').innerHTML = {
    suporte:  '<b>Não se liga a casamento nenhum.</b> Entra com o código que o casal gerar — '
            + 'é esse código que lhe abre a porta e diz se pode ver ou também corrigir.',
    admin:    'Responde pela casa: vê todos os casamentos, aprova registos e gere contas.',
  }[tipo];
}
async function criarConta(){
  const tipo = $('c-tipo').value;
  const email = $('c-email').value.trim();
  // Assinala o campo, em vez de um aviso solto: o email é obrigatório e tem de
  // parecer um email.
  const erroEmail = !email ? 'Indique o email da conta.'
                  : (EMAIL_RE.test(email) ? '' : 'Esse email não parece válido.');
  if (!marca('c-email', erroEmail)) { $('c-email').focus(); return; }
  const senha = 'tmp' + Math.random().toString(36).slice(2, 10);
  const corpo = { email, nome: $('c-nome').value.trim(), senha, papel_plataforma: tipo };
  const d = await api('utilizador_criar', { method:'POST', body: JSON.stringify(corpo) });
  if (!d || !d.success) return;
  $('c-nome').value = $('c-email').value = '';
  $('c-email').closest('.campo').classList.remove('mau','ok');
  const cx = $('senha-reposta');
  cx.style.display = '';
  cx.innerHTML = `Conta criada para <b>${esc(d.email)}</b>. Senha: <b class="cod">${esc(senha)}</b><br>
    Entregue-lha agora — não volta a aparecer. Ela deve mudá-la em Gestão.`;
  carregarContas();
}

async function carregarContas(){
  const alvo = document.getElementById('lista-contas');
  if (!alvo) return;
  const q = (document.getElementById('q-conta').value || '').trim();
  // Só as contas administrativas (admin e suporte). As de noivos/porteiro
  // pertencem a cada casamento e veem-se lá, em Gestão.
  const d = await api('utilizador_lista&tipo=plataforma' + (q ? '&q=' + encodeURIComponent(q) : ''));
  if (!d || !d.success) return;
  CONTAS = {};
  d.contas.forEach(c => { CONTAS[c.id] = c; });
  if (!d.contas.length){ alvo.innerHTML = '<div class="dica">Nenhuma conta corresponde.</div>'; return; }
  alvo.innerHTML = d.contas.map(c => {
    const eu = +c.id === +d.eu;
    const nome = c.nome || c.email;
    const plat = c.papel_plataforma ? `<span class="et agora">${esc(c.papel_plataforma)} da plataforma</span>` : '';
    const trocaEstado = c.estado === 'ativo' ? 'suspenso' : 'ativo';
    // 'inativo' quer dizer "o casamento dela foi arquivado", e não "alguém a
    // fechou". Sem o dizer, a lista parecia ter contas avariadas.
    const porque = c.estado === 'inativo'
      ? ' <span class="meta">parada com o casamento arquivado</span>' : '';
    // Como na lista de casamentos: editar à frente, e o que estraga atrás do
    // "⋯". Repor a senha de alguém e apagar-lhe a conta não são gestos que
    // devam estar a um clique de distância do que se faz todos os dias.
    const acoes = `<button class="btn btn-sm" onclick="editarConta(${c.id})">Editar</button>`
      + (eu ? '<span class="meta">é você</span>' : `
      <span class="mm"><button class="btn btn-sm" title="Mais ações"
            onclick="abrirMais(event,'c${c.id}')"><svg class="ico-mais" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.4" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="12.6" cy="8" r="1.5"/></svg></button>
        <span class="mm-pop" id="mm-c${c.id}" style="display:none">
          <button onclick="reporSenha(${c.id}, '${esc(c.email)}')">Repor senha</button>
          <hr>
          <button class="${c.estado === 'ativo' ? 'perigo' : ''}" onclick="estadoConta(${c.id}, '${trocaEstado}')">
            ${c.estado === 'ativo' ? 'Suspender' : 'Ativar'}</button>
          <button class="perigo" onclick="apagarConta(${c.id}, '${esc(c.email)}')">Apagar</button>
        </span></span>`);
    return `<div class="cas">
      <div class="selo">${esc(nome.slice(0,1).toUpperCase())}</div>
      <div>
        <div class="nm">${esc(nome)} <span class="et ${esc(c.estado)}">${esc(c.estado)}</span> ${plat}${porque}</div>
        <div class="meta"><span>${esc(c.email)}</span>
          <span>${c.ultimo_acesso ? 'último acesso ' + esc(c.ultimo_acesso.slice(0,10)) : 'nunca entrou'}</span></div>
      </div>
      <div class="ac">${acoes}</div>
      <div class="editor-conta" id="ed-${c.id}" style="display:none"></div>
    </div>`;
  }).join('');
}

// ---------- editar uma conta administrativa (admin ou suporte) ----------
// Estas contas não se prendem a casamento nenhum: o admin responde por toda a
// casa, o suporte entra por código. Por isso não há aqui lugares a dar nem a
// tirar — só o nome, o email e qual dos dois papéis é.
async function editarConta(id){
  const cx = document.getElementById('ed-' + id);
  if (cx.style.display !== 'none'){ cx.style.display = 'none'; return; }
  cx.style.display = '';
  const c = CONTAS[id] || {};
  const tipo = c.papel_plataforma === 'admin' ? 'admin' : 'suporte';
  cx.innerHTML = `
    <div class="lf" style="grid-template-columns:2fr 2fr 1fr auto;margin:0">
      <div><label>Nome</label><input type="text" id="e-nome-${id}" value="${esc(c.nome || '')}"></div>
      <div><label>Email</label><input type="email" id="e-email-${id}" value="${esc(c.email || '')}"></div>
      <div><label>Tipo</label>
        <select id="e-tipo-${id}">
          <option value="suporte"${tipo === 'suporte' ? ' selected' : ''}>Suporte</option>
          <option value="admin"${tipo === 'admin' ? ' selected' : ''}>Admin da plataforma</option>
        </select></div>
      <div><button class="btn btn-ouro btn-sm" onclick="guardarConta(${id})">Guardar</button></div>
    </div>
    <div class="dica" style="margin:.6rem 0 0">${tipo === 'suporte'
      ? 'Suporte: entra nos casamentos com o código que cada casal gerar.'
      : 'Admin: responde pela casa — vê todos os casamentos, aprova registos e gere contas.'}</div>`;
}
async function guardarConta(id){
  const tipo = document.getElementById('e-tipo-' + id).value;
  const d = await api('utilizador_editar', { method:'POST', body: JSON.stringify({
    id, nome: document.getElementById('e-nome-' + id).value.trim(),
    email: document.getElementById('e-email-' + id).value.trim(),
    papel_plataforma: tipo,
  }) });
  if (d && d.success){ toast('Conta guardada.'); carregarContas(); }
}
async function apagarConta(id, email){
  const r = await licConfirmar({
    titulo: 'Apagar a conta de «' + licEsc(email) + '»?',
    icone: '🗑️', perigo: true, confirmar: 'Apagar conta',
    texto: '<b>Não se desfaz.</b><br><br>Se ela ainda tiver lugar nalgum casamento, '
         + 'tire-lho primeiro em <b>Editar</b>.'
  });
  if (!r.sim) return;
  const d = await api('utilizador_apagar&id=' + id, { method:'POST' });
  if (d && d.success){ toast('Conta apagada.'); carregarContas(); }
}
async function estadoConta(id, estado){
  if (estado === 'suspenso'){
    const r = await licConfirmar({
      titulo: 'Suspender esta conta?',
      icone: '⏸️', confirmar: 'Suspender',
      texto: 'Deixa de entrar <b>até ser reativada</b>. Nada se apaga.'
    });
    if (!r.sim) return;
  }
  const d = await api('utilizador_estado&id=' + id + '&estado=' + estado, { method:'POST' });
  if (d && d.success) carregarContas();
}
async function reporSenha(id, email){
  if (!await pedirReporSenha(email)) return;
  const d = await api('utilizador_repor_senha&id=' + id, { method:'POST' });
  if (!d || !d.success) return;
  const cx = document.getElementById('senha-reposta');
  cx.style.display = '';
  cx.innerHTML = `Senha nova de <b>${esc(d.email)}</b>: <b class="cod">${esc(d.senha)}</b><br>
    Entregue-lha agora — não volta a aparecer. Ela deve mudá-la na página Gestão.`;
}
// ---------- arranque ----------
const MANDA_NA_CASA = <?= $mandaNaCasa ? 'true' : 'false' ?>;
carregarCasamentos();
carregarContas();
if (document.getElementById('c-tipo')) tipoMudou();
</script>
</body>
</html>
