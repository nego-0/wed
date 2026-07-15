<?php
// ============================================================
// convite.php — Página de confirmação de presença (RSVP)
// Não exibe o código QR. Após confirmar, oferece descarregar o
// convite em PDF ou enviá-lo pelo WhatsApp.
// ============================================================
require_once __DIR__ . '/db.php';
$codigo = strtoupper(trim($_GET['c'] ?? ''));
$c = $codigo !== '' ? carregarConvite($conn, $codigo, 'codigo') : null;
$valido = (bool)$c;
$linkDigital = $valido ? base_url() . '/convite-digital.php?c=' . $c['codigo'] : '';
$linkPdf     = $valido ? $linkDigital . '&download=1' : '';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confirmação · Isabel &amp; Abednego</title>
<link href="assets/fontes.css" rel="stylesheet">
<style>
  :root{
    --ink:#20342A; --forest:#2C4536; --forest-deep:#16261E;
    --ivory:#FBF8F1; --cream:#F5EEDF; --sand:#E9DFC9;
    --gold:#B4864A; --gold-soft:#D9BC8C; --gold-pale:#EFE3CB;
    --text:#3B4A40;
    --serif:'Cormorant Garamond',Georgia,serif; --sans:'Jost',system-ui,sans-serif; --script:'Pinyon Script',cursive;
  }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:var(--sans); font-weight:300; color:var(--text);
    background:radial-gradient(1000px 620px at 50% -12%, rgba(217,188,140,.30), transparent 62%),
               linear-gradient(175deg,var(--ivory),var(--cream));
    min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.25rem; }
  .folha{ width:100%; max-width:540px; background:#fff; border-radius:22px; overflow:hidden;
    box-shadow:0 24px 60px rgba(22,38,30,.16); border:1px solid rgba(180,134,74,.18); }
  .cabeca{ background:linear-gradient(160deg,var(--forest),var(--forest-deep)); color:var(--ivory);
    text-align:center; padding:2.4rem 1.5rem 2rem; position:relative; overflow:hidden; }
  .cabeca::before{ content:''; position:absolute; inset:0; opacity:.14; pointer-events:none;
    background-image:radial-gradient(circle at 18% 24%,var(--gold-soft) 0 2px,transparent 3px),
      radial-gradient(circle at 84% 70%,var(--gold-soft) 0 2px,transparent 3px),
      radial-gradient(circle at 66% 14%,var(--gold-soft) 0 1.5px,transparent 2.5px);
    background-size:120px 120px,100px 100px,80px 80px; }
  .cabeca > *{ position:relative; z-index:1; }
  .selo{ width:60px; height:60px; margin:0 auto .8rem; border:2px solid var(--gold-soft); border-radius:50%;
    display:flex; align-items:center; justify-content:center; font-family:var(--serif); font-weight:700; color:var(--gold-soft); }
  .rotulo{ font-family:var(--serif); letter-spacing:4px; text-transform:uppercase; font-size:.72rem; color:var(--gold-pale); }
  .casal{ font-family:var(--script); font-size:3.2rem; line-height:1; margin:.3rem 0; color:#fff; }
  .quando{ font-family:var(--serif); font-size:1.05rem; color:var(--gold-pale); }
  .corpo{ padding:2rem 1.7rem; }
  .para{ text-align:center; margin-bottom:1.5rem; }
  .para .lab{ font-family:var(--serif); font-style:italic; color:var(--gold); font-size:1rem; }
  .para .nome{ font-family:var(--serif); font-size:1.8rem; font-weight:700; color:var(--ink); line-height:1.15; }
  .para .mesa{ display:inline-flex; align-items:center; gap:.4rem; margin-top:.55rem;
    font-family:var(--sans); font-size:.88rem; color:var(--forest); background:var(--cream);
    border:1px solid var(--gold-pale); border-radius:50px; padding:.26rem .8rem; }
  .para .mesa svg{ width:15px; height:15px; }
  .divisor{ display:flex; align-items:center; gap:.7rem; color:var(--gold-soft); margin:1.4rem 0; }
  .divisor::before,.divisor::after{ content:''; height:1px; flex:1; background:linear-gradient(90deg,transparent,var(--gold-soft),transparent); }
  .contador{ display:flex; justify-content:center; gap:.8rem; margin-bottom:.5rem; }
  .contador .u{ text-align:center; min-width:58px; }
  .contador .v{ font-family:var(--serif); font-size:2rem; font-weight:700; color:var(--forest); line-height:1; }
  .contador .t{ font-size:.66rem; text-transform:uppercase; letter-spacing:1px; color:var(--gold); }
  .info-ev{ text-align:center; font-size:.92rem; color:var(--text); line-height:1.7; }
  .info-ev strong{ color:var(--ink); font-weight:500; }

  h3.sec{ font-family:var(--serif); text-align:center; color:var(--ink); font-size:1.4rem; margin:0 0 1rem; }
  .opcoes{ display:grid; grid-template-columns:1fr 1fr; gap:.7rem; margin-bottom:1rem; }
  .op{ border:1.5px solid rgba(44,69,54,.15); border-radius:14px; padding:1rem; text-align:center; cursor:pointer; transition:.15s; background:#fff; }
  .op .em{ font-size:1.5rem; display:block; margin-bottom:.3rem; }
  .op:hover{ border-color:var(--gold-soft); }
  .op.sel-sim{ border-color:var(--forest); background:var(--cream); }
  .op.sel-nao{ border-color:#a5473f; background:#f7eae8; }
  .campo{ margin-bottom:1rem; }
  label{ font-family:var(--serif); font-size:1rem; color:var(--ink); display:block; margin-bottom:.4rem; }
  input,select,textarea{ font-family:var(--sans); font-weight:300; width:100%; padding:.7rem .9rem;
    border:1.5px solid rgba(44,69,54,.15); border-radius:12px; font-size:1rem; color:var(--text); }
  input:focus,select:focus,textarea:focus{ outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(180,134,74,.15); }
  .membros{ display:grid; gap:.5rem; margin-bottom:1rem; }
  .membro{ display:flex; align-items:center; gap:.6rem; border:1.5px solid rgba(44,69,54,.12); border-radius:12px; padding:.6rem .8rem; cursor:pointer; }
  .membro input{ width:auto; }
  .membro.off{ opacity:.5; }
  .btn{ font-family:var(--sans); font-weight:500; border:none; border-radius:50px; padding:.85rem 1.4rem; cursor:pointer;
    font-size:1rem; width:100%; display:inline-flex; align-items:center; justify-content:center; gap:.5rem; text-decoration:none; }
  .btn svg{ width:18px; height:18px; }
  .btn-ouro{ background:linear-gradient(135deg,var(--gold),#8A6031); color:#fff; }
  .btn-verde{ background:linear-gradient(135deg,#1faa53,#178a44); color:#fff; }
  .btn-linha{ background:#fff; color:var(--forest); border:1.5px solid var(--gold-soft); }
  .estado-atual{ text-align:center; background:var(--cream); border-radius:12px; padding:.7rem; font-size:.9rem; margin-bottom:1.2rem; }
  .estado-atual .ok{ color:var(--forest); font-weight:500; }
  .estado-atual .nao{ color:#a5473f; font-weight:500; }

  /* Bloco de conclusão (após confirmar) */
  .concluido{ display:none; text-align:center; }
  .concluido.on{ display:block; }
  .concluido .obrigado{ font-family:var(--serif); font-size:1.15rem; color:var(--ink); margin:.2rem 0 1rem; }
  .cod-entrada{ background:var(--cream); border:1px solid var(--gold-pale); border-radius:12px; padding:.7rem;
    font-size:.95rem; margin-bottom:1.1rem; }
  .cod-entrada strong{ font-family:var(--serif); letter-spacing:4px; font-size:1.25rem; color:var(--ink); }
  .acoes{ display:grid; gap:.7rem; }
  .wa-box{ margin-top:1.3rem; text-align:left; background:#f4faf5; border:1px solid #d5ecdb; border-radius:14px; padding:1rem; }
  .wa-box label{ font-family:var(--sans); font-size:.9rem; color:var(--forest-deep); }
  .wa-linha{ display:flex; gap:.5rem; }
  .wa-linha input{ flex:1; }
  .wa-linha .btn{ width:auto; white-space:nowrap; padding:.7rem 1.1rem; }
  .wa-nota{ font-size:.78rem; color:#7d8a80; margin-top:.5rem; }

  .rodape{ text-align:center; padding:1rem; font-size:.8rem; color:#9aa09a; border-top:1px solid rgba(44,69,54,.08); }
  .link-wa{ color:var(--gold); text-decoration:none; }
  .erro-pag{ text-align:center; padding:3rem 1.5rem; }
  .erro-pag .casal{ color:var(--forest); }
</style>
</head>
<body>
<?php if (!$valido): ?>
  <div class="folha"><div class="erro-pag">
    <div class="rotulo" style="color:var(--gold)">Convite</div>
    <div class="casal">Isabel &amp; Abednego</div>
    <p style="margin-top:1rem;">Este link de convite não é válido ou já não está disponível.<br>
    Por favor, confirme o endereço ou contacte os noivos.</p>
    <p><a class="link-wa" href="https://wa.me/<?= EVENTO['whatsapp'] ?>">Falar pelo WhatsApp</a></p>
  </div></div>
<?php else:
  $jaRespondeu = $c['rsvp_estado'] !== 'pendente';
  $confirmado  = in_array($c['rsvp_estado'], ['confirmado','parcial'], true);
?>
  <div class="folha">
    <div class="cabeca">
      <div class="selo">I&amp;A</div>
      <div class="rotulo">Têm o prazer de o(a) convidar</div>
      <div class="casal">Isabel &amp; Abednego</div>
      <div class="quando"><?= EVENTO['data_ext'] ?> · <?= EVENTO['hora'] ?></div>
    </div>

    <div class="corpo">
      <div class="para">
        <div class="lab">Com todo o carinho, para</div>
        <div class="nome"><?= htmlspecialchars(nomeConviteVisivel($c)) ?></div>
        <?php if (!empty($c['mesa_nome'])): ?>
          <div class="mesa">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h16"/><path d="M6 10V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v4"/><path d="M6 10v8"/><path d="M18 10v8"/><path d="M4 14h16"/></svg>
            Mesa: <strong style="margin-left:.15rem;"><?= htmlspecialchars($c['mesa_nome']) ?></strong>
          </div>
        <?php endif; ?>
      </div>

      <div class="contador" id="contador"></div>
      <div class="divisor">✦</div>

      <div class="info-ev">
        <strong><?= htmlspecialchars(EVENTO['local']) ?></strong><br>
        <?= htmlspecialchars(EVENTO['cidade']) ?>
      </div>

      <div class="divisor">Confirmação de presença</div>

      <?php if ($jaRespondeu): ?>
        <div class="estado-atual">
          <?php if ($confirmado): ?>
            <span class="ok">Presença confirmada</span> — obrigado! Pode alterar abaixo se necessário.
          <?php else: ?>
            <span class="nao">Registou que não poderá comparecer.</span> Pode alterar abaixo.
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- FORMULÁRIO -->
      <div id="form-rsvp">
        <div class="opcoes">
          <div class="op" id="op-sim" onclick="escolher('sim')"><span class="em">🌿</span>Vou comparecer</div>
          <div class="op" id="op-nao" onclick="escolher('nao')"><span class="em">🕊️</span>Não poderei ir</div>
        </div>

        <div id="detalhes-sim" style="display:none;">
          <?php /* O seletor de número só aparece quando NÃO há lista nominal:
                   com nomes, são as caixas "Quem vai comparecer?" que definem a contagem. */ ?>
          <?php if ((int)$c['lugares'] > 1 && count($c['membros']) <= 1): ?>
          <div class="campo">
            <label>Quantas pessoas irão comparecer?</label>
            <select id="confirmados">
              <?php for ($i=1;$i<=(int)$c['lugares'];$i++): ?>
                <option value="<?= $i ?>"><?= $i ?> pessoa<?= $i>1?'s':'' ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if (count($c['membros']) > 1): ?>
          <div class="campo">
            <label>Quem vai comparecer?</label>
            <div class="membros" id="membros">
              <?php foreach ($c['membros'] as $m): ?>
                <label class="membro" data-id="<?= $m['id'] ?>">
                  <input type="checkbox" checked onchange="this.closest('.membro').classList.toggle('off',!this.checked)">
                  <span><?= htmlspecialchars($m['nome']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="campo">
          <label>Deixe uma mensagem aos noivos <span style="color:#aaa;font-weight:300">(opcional)</span></label>
          <textarea id="mensagem" rows="2" placeholder="Uma palavra de carinho…"><?= htmlspecialchars($c['rsvp_mensagem'] ?? '') ?></textarea>
        </div>

        <button class="btn btn-ouro" id="btn-enviar" onclick="enviar()">Confirmar resposta</button>
      </div>

      <!-- CONCLUSÃO (após confirmar): sem QR; PDF + WhatsApp -->
      <div class="concluido <?= $confirmado ? 'on' : '' ?>" id="concluido">
        <div class="divisor">Presença confirmada</div>
        <p class="obrigado">Que alegria contar consigo neste dia!</p>
        <div class="cod-entrada">O seu código de entrada: <strong><?= htmlspecialchars($c['codigo']) ?></strong></div>
        <div class="acoes">
          <a class="btn btn-ouro" href="<?= htmlspecialchars($linkPdf) ?>" target="_blank" rel="noopener">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
            Descarregar convite (ver offline)
          </a>
          <a class="btn btn-linha" href="<?= htmlspecialchars($linkDigital) ?>" target="_blank" rel="noopener">Ver o meu convite</a>
        </div>
        <div class="wa-box">
          <label>Receber o convite no WhatsApp</label>
          <div class="wa-linha">
            <input id="wa-num" type="tel" inputmode="tel" placeholder="Ex: 244 9xx xxx xxx" value="<?= htmlspecialchars(preg_replace('/\D/','',$c['telefone'] ?? '')) ?>">
            <button class="btn btn-verde" onclick="enviarWhatsapp()">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.38a9.86 9.86 0 0 0 4.73 1.2h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.13-2.9-7A9.82 9.82 0 0 0 12.04 2Zm5.8 14.05c-.24.68-1.42 1.32-1.95 1.36-.5.04-1.13.24-3.72-.78-3.14-1.24-5.14-4.4-5.3-4.6-.16-.2-1.26-1.68-1.26-3.2 0-1.52.8-2.27 1.08-2.58.28-.3.6-.38.8-.38.2 0 .4 0 .58.02.18.02.44-.07.68.52.24.6.82 2.06.9 2.2.08.16.12.34.02.54-.1.2-.16.34-.3.52-.16.18-.32.4-.46.54-.16.16-.32.34-.14.66.18.32.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.5 1.54.32.16.5.14.68-.08.2-.24.78-.9.98-1.22.2-.32.4-.26.68-.16.28.1 1.76.84 2.06.98.3.14.5.22.58.34.08.12.08.7-.16 1.38Z"/></svg>
              Enviar
            </button>
          </div>
          <div class="wa-nota">Indique o número com o indicativo do país. Abrimos o WhatsApp já com o seu convite pronto a enviar.</div>
        </div>
      </div>
    </div>

    <div class="rodape">
      Dúvidas? <a class="link-wa" href="https://wa.me/<?= EVENTO['whatsapp'] ?>">Contacte-nos pelo WhatsApp</a>
    </div>
  </div>

<script>
const CODIGO   = <?= json_encode($c['codigo']) ?>;
const LINK_DIGITAL = <?= json_encode($linkDigital) ?>;
const LUGARES  = <?= (int)$c['lugares'] ?>;
const TEM_MEMB = <?= count($c['membros']) > 1 ? 'true':'false' ?>;
const DATA_EV  = new Date(<?= json_encode(EVENTO['data_iso'].'T'.EVENTO['hora'].':00') ?>);
let escolha = <?= $jaRespondeu ? json_encode($c['rsvp_estado']==='recusado'?'nao':'sim') : 'null' ?>;

const $=id=>document.getElementById(id);

// Contagem decrescente
function tick(){
  const agora=new Date(), dif=DATA_EV-agora;
  const box=$('contador'); if(!box) return;
  if(dif<=0){ box.innerHTML='<div class="u"><div class="v">♥</div><div class="t">É hoje!</div></div>'; return; }
  const d=Math.floor(dif/864e5), h=Math.floor(dif%864e5/36e5), m=Math.floor(dif%36e5/6e4), s=Math.floor(dif%6e4/1e3);
  const u=(v,t)=>`<div class="u"><div class="v">${v}</div><div class="t">${t}</div></div>`;
  box.innerHTML=u(d,'dias')+u(h,'horas')+u(m,'min')+u(s,'seg');
}
setInterval(tick,1000); tick();

function escolher(v){
  escolha=v;
  $('op-sim').classList.toggle('sel-sim', v==='sim');
  $('op-nao').classList.toggle('sel-nao', v==='nao');
  $('op-sim').classList.remove('sel-nao'); $('op-nao').classList.remove('sel-sim');
  $('detalhes-sim').style.display = v==='sim'?'block':'none';
}
<?php if ($jaRespondeu): ?>escolher(escolha);<?php endif; ?>

function enviarWhatsapp(){
  let n=($('wa-num').value||'').replace(/\D/g,'');
  if(n.length<9){ alert('Indique um número de telefone válido, com o indicativo do país.'); return; }
  const msg='Aqui está o meu convite para o casamento de Isabel & Abednego: '+LINK_DIGITAL;
  window.open('https://wa.me/'+n+'?text='+encodeURIComponent(msg),'_blank');
}

async function enviar(){
  if(!escolha){ alert('Por favor, indique se vai comparecer.'); return; }
  const btn=$('btn-enviar'); btn.textContent='A enviar…'; btn.disabled=true;
  const membros=[...document.querySelectorAll('#membros .membro')].map(el=>({
    id:+el.dataset.id, vai:el.querySelector('input').checked
  }));
  let confirmados=0;
  if(escolha==='sim'){
    confirmados = $('confirmados') ? +$('confirmados').value
                 : (TEM_MEMB ? membros.filter(m=>m.vai).length : 1);
  }
  const agora=()=>{ const d=new Date(),p=n=>String(n).padStart(2,'0');
    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds()); };
  const payload={ codigo:CODIGO, decisao:escolha, confirmados, mensagem:$('mensagem').value, membros, ts:agora() };
  const r=await fetch('api.php?action=rsvp_submit',{method:'POST',body:JSON.stringify(payload)});
  const d=await r.json();
  btn.disabled=false; btn.textContent='Confirmar resposta';
  if(!d.success){ alert(d.message||'Ocorreu um erro. Tente novamente.'); return; }
  if(escolha==='sim'){
    $('form-rsvp').style.display='none';
    $('concluido').classList.add('on');
    $('concluido').scrollIntoView({behavior:'smooth'});
  } else {
    $('form-rsvp').innerHTML='<div class="estado-atual"><span class="nao">Resposta registada.</span> Sentiremos a sua falta — obrigado por avisar. 🕊️</div>';
    $('concluido').classList.remove('on');
  }
}
</script>
<?php endif; ?>
</body>
</html>
