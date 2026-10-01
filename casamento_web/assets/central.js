(function(){
  'use strict';
  // Estado exclusivamente em memória: esta montra não escreve nas APIs de gestão.
  const $=s=>document.querySelector(s), $$=s=>Array.from(document.querySelectorAll(s));
  const abas=$$('[data-demo]');
  function activar(bt){abas.forEach(x=>{x.setAttribute('aria-selected',String(x===bt));x.tabIndex=x===bt?0:-1;});$$('[data-painel]').forEach(p=>p.hidden=p.dataset.painel!==bt.dataset.demo);dimensionarCartao();}
  abas.forEach((bt,i)=>{bt.addEventListener('click',()=>activar(bt));bt.addEventListener('keydown',e=>{let j;if(e.key==='ArrowRight')j=(i+1)%abas.length;else if(e.key==='ArrowLeft')j=(i+abas.length-1)%abas.length;else if(e.key==='Home')j=0;else if(e.key==='End')j=abas.length-1;else return;e.preventDefault();activar(abas[j]);abas[j].focus();});});
  if(!abas.length)return;
  const nomes=['Ana Mendes','Paulo Mendes','Sofia Costa','Miguel Costa','Beatriz Lima','Tiago Silva'];
  const mesas=['Acácia','Oliveira','Jasmim'];
  const bebidas=['Água mineral','Sumo de manga','Espumante'];
  const despesas=[['Espaço','Local',500000],['Fotografia','Memórias',250000],['Flores','Decoração',100000]];
  let pessoas,pagamentos,escolha,pedido,avisoTimer;
  const kz=n=>new Intl.NumberFormat('pt-PT').format(n)+' Kz';
  function avisar(t){clearTimeout(avisoTimer);$('#demo-estado').textContent=t;avisoTimer=setTimeout(()=>$('#demo-estado').textContent='',5000);}
  function ligar(sel,evento,fn){const el=$(sel);if(el)el.addEventListener(evento,fn);}
  function renderConvidados(){
    const busca=($('#demo-busca')?.value||'').toLocaleLowerCase('pt'),filtro=$('#demo-filtro')?.value||'todos';
    const visiveis=pessoas.filter(p=>p.nome.toLocaleLowerCase('pt').includes(busca)&&(filtro==='todos'||p.confirmado===(filtro==='confirmado')));
    if($('#demo-convidados'))$('#demo-convidados').innerHTML=visiveis.map(p=>`<div class="convite-row"><span class="demo-avatar">${p.nome.split(' ').map(n=>n[0]).join('')}</span><div class="convite-corpo"><b>${p.nome}</b><div class="convite-meta">Mesa ${mesas[p.mesa]} · ${p.confirmado?'Confirmado':'Aguarda resposta'}</div></div>${p.confirmado?'<span class="demo-pill">Confirmado</span>':`<button class="btn" type="button" data-confirmar="${p.id}">Confirmar</button>`}</div>`).join('')||'<p>Nenhum convidado encontrado.</p>';
    $$('[data-confirmados],[data-esperados]').forEach(el=>el.textContent=pessoas.filter(p=>p.confirmado).length);
    $$('[data-pendentes]').forEach(el=>el.textContent=pessoas.filter(p=>!p.confirmado).length);
  }
  function renderMesas(){
    const select=$('#demo-pessoa');if(!select)return;
    const actual=select.value||'0';select.innerHTML=pessoas.map(p=>`<option value="${p.id}">${p.nome}</option>`).join('');select.value=actual;
    const p=pessoas[Number(select.value)];
    $('#demo-mesas').innerHTML=mesas.map((nome,i)=>{const n=pessoas.filter(p=>p.mesa===i).length;return `<button type="button" data-mesa="${i}" aria-pressed="${p.mesa===i}" aria-label="Mesa ${nome}, ${n} de 8 lugares ocupados">${mesaIcone({forma:i===2?'retangular':'redonda',capacidade:8,ocupacao:n},{tam:120})}<b>${nome}</b><small>${8-n} lugares livres</small></button>`;}).join('');
  }
  function renderPorta(){
    if(!$('#demo-porta'))return;const busca=$('#demo-porta-busca').value.toLocaleLowerCase('pt');
    $('#demo-porta').innerHTML=pessoas.filter(p=>p.nome.toLocaleLowerCase('pt').includes(busca)).map(p=>`<div class="convite-row"><div class="convite-corpo"><b>${p.nome}</b><div class="convite-meta">Mesa ${mesas[p.mesa]} · ${p.confirmado?'Confirmado':'Ainda sem confirmação'}</div></div>${p.presente?'<span class="demo-pill">Entrada registada</span>':`<button class="btn" type="button" data-entrada="${p.id}" ${p.confirmado?'':'disabled'}>Registar entrada</button>`}</div>`).join('')||'<p>Nenhum convidado encontrado.</p>';
    $$('[data-presentes]').forEach(el=>el.textContent=pessoas.filter(p=>p.presente).length);
  }
  function renderBar(){
    if(!$('#demo-bebidas'))return;
    $('#demo-bebidas').innerHTML=bebidas.map((nome,i)=>`<button type="button" data-bebida="${i}" aria-pressed="${escolha===i}">${ICO.ico('taca')}<span>${nome}</span></button>`).join('');
    $('#demo-pedir').disabled=escolha===null||!!(pedido&&pedido.estado<2);
    $('#demo-pedido').innerHTML=pedido?`<p><b>${bebidas[pedido.bebida]}</b> · 1 unidade</p><span class="demo-pill">${['Recebido na copa','Pronto para entrega','Entregue à mesa'][pedido.estado]}</span>${pedido.estado<2?`<div><button class="btn btn-ouro" type="button" data-avancar-pedido>${pedido.estado===0?'Preparar pedido':'Confirmar entrega'}</button></div>`:'<p>Pedido concluído. Pode experimentar outra bebida.</p>'}`:'<p>Escolha uma bebida para acompanhar o pedido até à entrega.</p>';
  }
  function renderDespesas(){
    if(!$('#demo-despesas'))return;
    const pago=despesas.reduce((n,d,i)=>n+(pagamentos[i]?d[2]:0),0);
    $('[data-pago]').textContent=kz(pago);$('[data-falta]').textContent=kz(850000-pago);
    $('#demo-despesas').innerHTML=despesas.map((d,i)=>`<tr><td><b>${d[0]}</b></td><td>${d[1]}</td><td>${kz(d[2])}</td><td>${pagamentos[i]?'<span class="demo-pill">Pago</span>':`<button class="btn" type="button" data-pagar="${i}">Registar pagamento</button>`}</td></tr>`).join('');
  }
  function renderPessoas(){renderConvidados();renderMesas();renderPorta();}
  const paletas=JSON.parse($('#demo-paletas').textContent);
  function mudarPaleta(){const card=$('.demo-escala .cartao');if(!card)return;const p=paletas[$('#demo-paleta').value];Object.entries({'accent':'accent','name':'nameColor','sub':'sub','head':'head','soft':'soft'}).forEach(([a,b])=>card.style.setProperty('--ct-'+a,p[b]));}
  function dimensionarCartao(){const papel=$('.demo-papel');if(papel&&papel.clientWidth)papel.style.setProperty('--demo-escala',papel.clientWidth/720);}
  function repor(anunciar){pessoas=nomes.map((nome,id)=>({id,nome,confirmado:id<4,presente:false,mesa:Math.floor(id/2)}));pagamentos=[true,false,false];escolha=null;pedido=null;
    ['#demo-busca','#demo-porta-busca'].forEach(s=>{if($(s))$(s).value='';});if($('#demo-filtro'))$('#demo-filtro').value='todos';if($('#demo-pessoa'))$('#demo-pessoa').value='0';if($('#demo-paleta')){$('#demo-paleta').value='ouro';mudarPaleta();}renderPessoas();renderBar();renderDespesas();if(anunciar){const f=$('.demo-digital iframe');if(f)f.src=f.getAttribute('src');avisar('A demonstração voltou ao início.');}}
  ligar('#demo-repor','click',()=>repor(true));ligar('#demo-busca','input',renderConvidados);ligar('#demo-filtro','change',renderConvidados);ligar('#demo-porta-busca','input',renderPorta);ligar('#demo-pessoa','change',renderMesas);ligar('#demo-paleta','change',()=>{mudarPaleta();avisar('A paleta do convite foi actualizada.');});
  $('#demonstracao').addEventListener('click',e=>{
    const bt=e.target.closest('button');if(!bt)return;
    if(bt.hasAttribute('data-confirmar')){const p=pessoas[Number(bt.dataset.confirmar)];p.confirmado=true;renderPessoas();avisar(p.nome+': presença confirmada.');}
    if(bt.hasAttribute('data-mesa')){const p=pessoas[Number($('#demo-pessoa').value)];p.mesa=Number(bt.dataset.mesa);renderPessoas();avisar(p.nome+' passou para a mesa '+mesas[p.mesa]+'.');}
    if(bt.hasAttribute('data-entrada')){const p=pessoas[Number(bt.dataset.entrada)];if(!p.confirmado)return;p.presente=true;renderPorta();avisar('Entrada de '+p.nome+' registada.');}
    if(bt.hasAttribute('data-bebida')){escolha=Number(bt.dataset.bebida);renderBar();}
    if(bt.hasAttribute('data-avancar-pedido')&&pedido&&pedido.estado<2){pedido.estado++;renderBar();avisar(pedido.estado===1?'Pedido preparado. A entrega pode recolhê-lo.':'Bebida entregue à mesa Acácia.');}
    if(bt.hasAttribute('data-pagar')){pagamentos[Number(bt.dataset.pagar)]=true;renderDespesas();avisar('Pagamento de exemplo registado.');}
  });
  ligar('#demo-pedir','click',()=>{if(escolha===null||(pedido&&pedido.estado<2))return;pedido={bebida:escolha,estado:0};renderBar();avisar('Pedido recebido na copa.');});
  window.addEventListener('resize',dimensionarCartao);
  if(window.ResizeObserver&&$('.demo-papel'))new ResizeObserver(dimensionarCartao).observe($('.demo-papel'));
  repor(false);dimensionarCartao();
})();
