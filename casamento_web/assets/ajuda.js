(function(){
  'use strict';
  const busca=document.getElementById('aj-busca'),cards=[...document.querySelectorAll('.aj-card')];if(!busca||!cards.length)return;
  const botoes=[...document.querySelectorAll('[data-aj-modulo]')],estado=document.getElementById('aj-resultado'),vazio=document.getElementById('aj-sem-resultados');let modulo='todos';
  const limpar=s=>(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
  function filtrar(){const termos=limpar(busca.value).split(/\s+/).filter(Boolean);let n=0;cards.forEach(c=>{const texto=limpar(c.dataset.pesquisa),ver=(modulo==='todos'||c.dataset.modulo===modulo)&&termos.every(t=>texto.includes(t));c.hidden=!ver;if(ver)n++;});vazio.hidden=n!==0;estado.textContent=n+(n===1?' tópico encontrado':' tópicos encontrados');}
  botoes.forEach(b=>b.addEventListener('click',()=>{modulo=b.dataset.ajModulo;botoes.forEach(x=>x.classList.toggle('ativo',x===b));filtrar();if(innerWidth<820)document.querySelector('.aj-conteudo')?.scrollIntoView({behavior:'smooth',block:'start'});}));
  busca.addEventListener('input',filtrar);document.addEventListener('keydown',e=>{if(e.key==='/'&&!/input|textarea|select/i.test(document.activeElement.tagName)){e.preventDefault();busca.focus();}});
  document.querySelectorAll('.aj-card>details').forEach(d=>d.addEventListener('toggle',()=>{if(!d.open)return;document.querySelectorAll('.aj-card>details[open]').forEach(outro=>{if(outro!==d)outro.open=false;});}));
  const repetirGif=el=>{if(!el)return;const atr=el.tagName==='SOURCE'?'srcset':'src',valor=el.getAttribute(atr);if(!valor)return;const url=new URL(valor,location.href);url.searchParams.set('passo',Date.now());el.setAttribute(atr,url.href);};
  document.querySelectorAll('.aj-passo').forEach(d=>d.addEventListener('toggle',()=>{
    if(!d.open)return;
    d.closest('.aj-passos')?.querySelectorAll('.aj-passo[open]').forEach(outro=>{if(outro!==d)outro.open=false;});
    repetirGif(d.querySelector('source'));repetirGif(d.querySelector('img'));
  }));
  filtrar();
})();
