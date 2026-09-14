<?php
// ============================================================
// modelo-prova.php — A cara de um modelo do convite impresso
//
// A página dos modelos precisa de mostrar o que cada um é. O convite digital
// já se sabe desenhar sozinho (convite-digital.php?demo=1&prova=1&modelo=N);
// o cartão não tinha por onde. Esta página é isso e mais nada: o cartão do
// modelo, sozinho numa folha, para entrar numa moldura.
//
// Sem ela, escolher um modelo era escolher pelo nome — que é escolher às
// cegas numa página que existe justamente para se ver o desenho.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pecas.php';
require_once __DIR__ . '/personalizacao.php';

// O admin vê qualquer modelo; um casal vê os que lhe são destinados — é a
// mesma regra de os aplicar, e é o que permite ao painel do editor mostrar as
// miniaturas em vez de uma lista de nomes. defsDoEditor é que decide: o
// terceiro valor só vem preenchido para quem tem direito a ver este modelo.
if (!ehAdminPlataforma() && !casamentoAtual()) { http_response_code(403); exit('Sem acesso.'); }

[$defs, , $MODELO] = defsDoEditor($conn, 'impresso');
if (!$MODELO) { http_response_code(404); exit('Modelo não encontrado.'); }

// Um convidado de exemplo: o cartão é personalizado por pessoa, e um cartão
// sem nome nenhum não mostra o que o modelo faz com um.
$conv = ['nome' => 'Família Agostinho', 'mesas' => [['nome'=>'Mesa Luar','n'=>2]]];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prova · <?= escP($MODELO['nome']) ?></title>
<link href="<?= asset('assets/fontes.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/pecas.css') ?>" rel="stylesheet">
<style>
  html,body{ margin:0; padding:0; width:720px; height:1080px; overflow:hidden; }
  /* O acrílico é transparente: o cartão mostra-se sobre fundo escuro, como no
     design e como em todo o resto do sistema. */
  body{ background:radial-gradient(120% 100% at 50% 15%,#2a2b26 0%,#191a16 55%,#0e0f0c 100%); }
</style>
</head>
<body>
<?= renderCartaoConvite(
      cartaoDadosEvento($defs), $conv,
      cartaoPaletaEfetiva($defs), $defs['cartao.folhagem'], true,
      cartaoCamadasVisiveis($defs), cartaoEstiloVars($defs), cartaoPosicoes($defs)) ?>
</body>
</html>
