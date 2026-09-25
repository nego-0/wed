(function(){
  var abas=[].slice.call(document.querySelectorAll('[data-demo]'));
  abas.forEach(function(bt){bt.addEventListener('click',function(){
    abas.forEach(function(x){x.setAttribute('aria-selected',String(x===bt));});
    document.querySelectorAll('[data-painel]').forEach(function(p){p.hidden=p.dataset.painel!==bt.dataset.demo;});
  });});
  document.querySelectorAll('.demo-janela').forEach(function(el){
    el.addEventListener('contextmenu',function(e){e.preventDefault();});
    el.addEventListener('dragstart',function(e){e.preventDefault();});
  });
})();
