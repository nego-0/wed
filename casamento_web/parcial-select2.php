<?php
// ============================================================
// parcial-select2.php — a lista com procura, e o que ela traz atrás
//
// A regra da casa era «nada de bibliotecas novas; sem jQuery, sem Select2».
// Foi REVOGADA por decisão de quem manda no produto (ver
// docs/bar-motor-assistido.md §5.1), e a ordem foi usar o Select2 em todos os
// <select> do sistema.
//
// As três peças vivem AQUI, num sítio só, e não copiadas por dezoito páginas:
//
//   1. a folha do Select2, tal como ele a publica;
//   2. a folha da casa, que lhe troca as cores pelas do tema (tem de vir
//      DEPOIS da dele, senão não ganha);
//   3. o jQuery e o Select2, por esta ordem — o segundo não existe sem o
//      primeiro.
//
// Chama-se IMEDIATAMENTE ANTES de assets/janela.js. Não é preferência de
// arrumação: o janela.js veste os <select> da página no DOMContentLoaded, e se
// o jQuery ainda não estiver definido nessa altura não veste nada — a página
// fica com as listas nativas e ninguém percebe porquê.
//
// Servido daqui, e nunca de um CDN. Um casamento num salão sem rede tem de
// abrir o bar à mesma, e um <script src="https://…"> que não carrega é um ecrã
// sem listas a meio de uma festa. É a mesma razão por que o qrious e o
// html5-qrcode já viviam em assets/.
// ============================================================

/** As folhas de estilo. Vão ao <head>, com as outras. */
function select2Estilos(): void { ?>
<link href="<?= asset('assets/select2.min.css') ?>" rel="stylesheet">
<link href="<?= asset('assets/select2-casa.css') ?>" rel="stylesheet">
<?php }

/** O jQuery e o Select2. Vão antes do assets/janela.js — ver acima. */
function select2Scripts(): void { ?>
<script src="<?= asset('assets/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/select2.min.js') ?>"></script>
<?php }

/**
 * As duas coisas de uma vez, para as páginas que carregam tudo no fim do
 * corpo. Um <link> no corpo é válido e não pisca aqui: o Select2 só se vê
 * depois de o javascript correr, e nessa altura a folha já chegou.
 */
function select2Tudo(): void { select2Estilos(); select2Scripts(); }
