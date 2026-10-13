<?php
// Prova isolada: php tests/chk_registo_identidade.php. Não usa MySQL.
// Extrai apenas as funções sob teste, sem arrancar sessões nem migrações.
function carregarFuncao(string $ficheiro, string $nome): void {
    $tokens = token_get_all(file_get_contents($ficheiro));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($tokens[$j]) || $tokens[$j][1] !== $nome) continue;
        $codigo = ''; $nivel = 0; $abriu = false;
        for (; $i < count($tokens); $i++) {
            $t = $tokens[$i]; $codigo .= is_array($t) ? $t[1] : $t;
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $nivel++; $abriu = true; }
            if ($t === '}' && --$nivel === 0 && $abriu) break;
        }
        eval($codigo); return;
    }
    throw new RuntimeException('Função em falta: ' . $nome);
}
function verificar($obtido, $esperado, string $caso): void {
    if ($obtido !== $esperado) throw new RuntimeException($caso . ': ' . var_export($obtido, true));
    echo "PASS: $caso\n";
}
$base = dirname(__DIR__);
foreach (['papel','papelPlataforma','utilizadorId','papelRegisto','emailAtual'] as $f)
    carregarFuncao($base . '/auth.php', $f);
foreach ([['admin', null, 'noivos'], ['admin', 'admin', 'admin'],
          [null, 'admin', 'admin'], ['admin', 'suporte', 'suporte'],
          ['porteiro', null, 'porteiro'], ['copeiro', null, 'copeiro'],
          ['entregador', null, 'entregador'], [null, null, null]] as [$local,$global,$esperado]) {
    $_SESSION = ['papel'=>$local, 'papel_plataforma'=>$global];
    verificar(papelRegisto(), $esperado, 'papel ' . ($local ?? 'vazio') . '/' . ($global ?? 'vazio'));
    verificar(papel(), $local, 'permissões originais preservadas');
}
$P = 'cw_';
$conn = new class {
    public function prepare($sql) {
        return new class {
            public int $id;
            public function bind_param($types, &$id) { $this->id = $id; }
            public function execute() {}
            public function get_result() { return $this; }
            public function fetch_assoc() { return ['email'=>'pessoa' . $this->id . '@exemplo.test']; }
        };
    }
};
$_SESSION = ['utilizador_id'=>7];
verificar(emailAtual(), 'pessoa7@exemplo.test', 'sessão antiga recupera email pelo id');
$_SESSION = ['utilizador_id'=>8, 'email'=>'antigo@exemplo.test'];
verificar(emailAtual(), 'pessoa8@exemplo.test', 'conta prevalece sobre email antigo na sessão');
$_SESSION = [];
verificar(emailAtual(), null, 'acção pública não herda identidade');
function nomeDaAcao(string $a): array { return [$a,'teste']; }
carregarFuncao($base . '/api.php', 'registoLinha');
$r=registoLinha(['accao'=>'teste','email'=>'autor@exemplo.test','utilizador'=>'Nome','papel'=>'noivos']);
verificar($r['utilizador'], 'autor@exemplo.test', 'API identifica o autor pelo email gravado');
$r=registoLinha(['accao'=>'teste','email'=>'','utilizador'=>'Nome antigo']);
verificar($r['utilizador'], 'Nome antigo', 'histórico sem email não recebe identidade inventada');
