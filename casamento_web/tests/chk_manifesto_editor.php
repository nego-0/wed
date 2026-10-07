<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../personalizacao.php';
require_once __DIR__ . '/../editor-modelo.php';

function falhar(string $m): never { fwrite(STDERR, "FALHOU: $m\n"); exit(1); }
$restrito = capacidadesModeloPadrao('digital');
$restrito['campos_editaveis'] = ['textos.kicker'];
$modelo = ['id'=>7, 'capacidades'=>$restrito, 'renderer_key'=>'kulemba-contemporaneo',
           'renderer_version'=>'1.0.0', 'renderer_schema'=>1];
$noivos = manifestoEditorModelo('digital', defsPadrao(), $modelo, 'noivos');
$admin = manifestoEditorModelo('digital', defsPadrao(), $modelo, 'administrador');
if ($noivos['schema'] !== 3) falhar('esquema do manifesto');
if ($noivos['modo'] !== 'noivos' || $admin['modo'] !== 'administrador') falhar('modo do editor');
if ($noivos['permissoes']['estrutura']) falhar('noivos com permissão estrutural');
if (!$admin['permissoes']['estrutura']) falhar('admin sem permissão estrutural');
if (count($noivos['inspector']['campos']) !== 1 || $noivos['inspector']['campos'][0]['chave'] !== 'textos.kicker')
    falhar('campos dos noivos não respeitam a ficha');
if (count($admin['inspector']['campos']) < 20) falhar('admin ficou limitado pelos noivos');
if (!in_array('rolagem_automatica', $admin['recursos'], true)) falhar('recurso do pacote ausente');
if (($admin['pacote']['componentes'] ?? []) === []) falhar('manifesto instalado não foi lido');
echo "OK — manifesto, inspector e modos separados.\n";
