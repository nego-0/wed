(function(){
  'use strict';
  const busca=document.getElementById('aj-busca'),cards=[...document.querySelectorAll('.aj-card')];if(!busca||!cards.length)return;
  const botoes=[...document.querySelectorAll('[data-aj-modulo]')],estado=document.getElementById('aj-resultado'),vazio=document.getElementById('aj-sem-resultados');let modulo='todos';
  const limpar=s=>(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
  function filtrar(){const termos=limpar(busca.value).split(/\s+/).filter(Boolean);let n=0;cards.forEach(c=>{const texto=limpar(c.dataset.pesquisa),ver=(modulo==='todos'||c.dataset.modulo===modulo)&&termos.every(t=>texto.includes(t));c.hidden=!ver;if(ver)n++;});vazio.hidden=n!==0;estado.textContent=n+(n===1?' guia encontrado':' guias encontrados');}
  botoes.forEach(b=>b.addEventListener('click',()=>{modulo=b.dataset.ajModulo;botoes.forEach(x=>x.classList.toggle('ativo',x===b));filtrar();}));busca.addEventListener('input',filtrar);document.addEventListener('keydown',e=>{if(e.key==='/'&&!/input|textarea|select/i.test(document.activeElement.tagName)){e.preventDefault();busca.focus();}});
  document.querySelectorAll('.aj-reproduzir').forEach(b=>b.addEventListener('click',()=>{const caixa=b.closest('.aj-media'),v=caixa.querySelector('video');if(!v)return;document.querySelectorAll('.aj-media video').forEach(o=>{if(o!==v)o.pause();});v.play();caixa.classList.add('a-tocar');}));document.querySelectorAll('.aj-media video').forEach(v=>v.addEventListener('pause',()=>v.closest('.aj-media').classList.remove('a-tocar')));filtrar();
})();
