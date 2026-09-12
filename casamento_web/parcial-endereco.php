<?php
// ============================================================
// parcial-endereco.php — A barra do endereço público
//
// Os QR e os links dos convites são absolutos. Enquanto houve um casamento e
// um só sítio, deduzi-los do pedido em curso bastava. Com vários casamentos —
// e com quem prepara os cartões a partir de uma máquina de testes — deixou de
// bastar: um QR impresso a apontar para 127.0.0.1 é papel deitado fora.
//
// Esta barra aparece onde se geram links e QR (gráficas, convites físicos,
// convites digitais), diz para onde eles apontam e deixa fixar o endereço
// definitivo antes de se imprimir.
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Desenha a barra. $porque diz o que está em jogo nesta página, para o aviso
 * falar do que a pessoa está mesmo a fazer.
 */
function barraEndereco(string $porque = 'os links e os QR desta página'): void {
    global $conn, $P;
    if (!ehAdmin()) return;

    $atual   = enderecoPublico();
    $r       = @$conn->query("SELECT endereco_publico FROM {$P}casamentos WHERE id=" . casamentoAtual() . " LIMIT 1");
    $fixado  = $r && ($x = $r->fetch_assoc()) ? rtrim((string)$x['endereco_publico'], '/') : '';
    $deduzido = ($fixado === '');
    $arriscado = $deduzido && enderecoSoLocal($atual);
    static $jaHouveUma = false;
    $primeira = !$jaHouveUma; $jaHouveUma = true;
    ?>
    <div class="end-barra<?= $arriscado ? ' aviso' : '' ?> no-print">
      <div class="txt">
        <?php if ($arriscado): ?>
          <b>Atenção:</b> <?= escP($porque) ?> apontam para <code><?= escP($atual) ?></code>,
          um endereço que só existe nesta máquina. No telemóvel de um convidado não abre —
          e, uma vez impresso, não há emenda. Fixe aqui o endereço definitivo.
        <?php elseif ($deduzido): ?>
          <?= escP(ucfirst($porque)) ?> apontam para <code><?= escP($atual) ?></code>,
          deduzido do endereço por onde entrou. Se o site for servido noutro, fixe-o.
        <?php else: ?>
          <?= escP(ucfirst($porque)) ?> apontam para <code><?= escP($atual) ?></code>.
        <?php endif; ?>
      </div>
      <div class="ac">
        <input type="url" class="end-campo" value="<?= escP($fixado) ?>"
               placeholder="https://casamento.exemplo.pt" spellcheck="false">
        <button class="btn btn-sm<?= $arriscado ? ' btn-ouro' : '' ?>" onclick="guardarEndereco(this)">Fixar</button>
      </div>
    </div>
    <?php if ($primeira): ?>
    <style>
      .end-barra{ display:flex; gap:.8rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem;
                  background:#fff; border:1px solid var(--line); border-left:4px solid var(--gold-soft);
                  border-radius:10px; padding:.65rem .9rem; font-size:.85rem; color:#6c7570; }
      .end-barra.aviso{ border-color:var(--warn); border-left-color:var(--warn); background:var(--warn-bg); color:var(--ink); }
      .end-barra .txt{ flex:1 1 320px; line-height:1.5; }
      .end-barra code{ font-family:ui-monospace,monospace; font-size:.82em; background:rgba(0,0,0,.05);
                       padding:.05rem .3rem; border-radius:4px; word-break:break-all; }
      .end-barra .ac{ display:flex; gap:.4rem; align-items:center; }
      .end-barra .end-campo{ width:min(280px,52vw); margin:0; }
      @media print{ .end-barra{ display:none !important; } }
    </style>
    <script>
    // Sem dependências: esta barra vive em páginas que não carregam a api.js.
    (function(){
      const TOKEN = <?= json_encode(csrfToken()) ?>;
      window.guardarEndereco = async function(btn){
        const campo = btn.closest('.end-barra').querySelector('.end-campo');
        btn.disabled = true;
        try {
          const r = await fetch('api.php?action=casamento_endereco', {
            method:'POST', headers:{'X-CSRF-Token':TOKEN,'Content-Type':'application/json'},
            body: JSON.stringify({ endereco: campo.value }) });
          const d = await r.json();
          if (d && d.success) { location.reload(); return; }
          alert((d && d.message) || 'Não foi possível guardar o endereço.');
        } catch (e) {
          alert('Não foi possível falar com o servidor.');
        }
        btn.disabled = false;
      };
    })();
    </script>
    <?php endif;
}
