// Arrumar o bar depois de uma prova.
//
// Isto existe por uma armadilha que já mordeu três vezes: uma bebida com
// pedidos POR ENTREGAR não se apaga. E tem razão de ser — o pedido ficaria
// com uma linha sem nome, e quem o lesse na copa não saberia o que servir.
//
// Só que uma prova do bar quase sempre PEDE alguma coisa: é isso que ela veio
// provar. Depois manda apagar a bebida, a API recusa em silêncio (a prova não
// lê a resposta), e a bebida fica. Na corrida seguinte ela já lá está, e a
// prova que conta «quantas bebidas há na carta» falha num sítio que não tem
// nada a ver com o que partiu.
//
// A ordem é esta, e é a única que funciona: primeiro fecham-se os pedidos,
// depois apaga-se a bebida.
//
//     const { limparBar } = require('./limpar-bar');
//     await limparBar(p, { itens: [id1, id2], marca: 'zzu1234' });
//
// `marca` é opcional: com ela, fecham-se também os pedidos que tenham essa
// marca no nome de alguma linha, mesmo que a bebida já não esteja na lista.

/**
 * Fecha os pedidos desta prova e apaga-lhe as bebidas.
 *
 * @param p       uma página com sessão de noivos aberta (tem window.CSRF)
 * @param opc     { itens: number[], marca?: string }
 */
async function limparBar(p, opc) {
  const itens = (opc && opc.itens) || [];
  const marca = (opc && opc.marca) || '';
  return p.evaluate(async ({ itens, marca }) => {
    const post = (a, c) => fetch('api.php?action=' + a, { method: 'POST',
      headers: { 'X-CSRF-Token': window.CSRF, 'Content-Type': 'application/json' },
      body: c === undefined ? undefined : JSON.stringify(c) })
      .then(r => r.json()).catch(() => ({ success: false }));

    const meu = pedido => (pedido.itens || []).some(l =>
      itens.indexOf(+l.item_id) >= 0
      || (marca && (l.nome || '').indexOf(marca) >= 0));

    // Duas voltas: aprovar um pedido muda-o de estado, e a fila lida antes
    // dessa mudança fica velha. Duas chegam para tudo o que uma prova faz.
    for (let volta = 0; volta < 2; volta++) {
      const e = await (await fetch('api.php?action=bar_estado')).json();
      for (const x of (e.fila || [])) {
        if (!meu(x)) continue;
        if (x.estado === 'em_analise') {
          await post('bar_decidir', { id: x.id, decisao: 'recusar',
                                      motivo_texto: 'arrumar a prova' });
        } else if (x.estado === 'aprovado' || x.estado === 'a_caminho') {
          await post('bar_cancelar_copa', { id: x.id,
                                            motivo_texto: 'arrumar a prova' });
        }
      }
    }

    // Agora sim. Devolve-se o que ficou por apagar, para a prova poder dizê-lo
    // em vez de deixar lixo em silêncio — foi o silêncio que fez isto durar.
    const ficaram = [];
    for (const i of itens) {
      const d = await post('bar_item_apagar&id=' + i);
      if (!d || d.success !== true) ficaram.push(i + (d && d.message ? ': ' + d.message : ''));
    }
    return ficaram;
  }, { itens: itens.map(Number), marca });
}

module.exports = { limparBar };
