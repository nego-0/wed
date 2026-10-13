# Kulemba — guia breve da marca

Kulemba é a identidade da plataforma de organização de casamentos. O símbolo representa uma oferta e a união: duas formas entrelaçadas acolhem um coração. É uma interpretação gráfica original, não a reprodução de um símbolo tradicional.

A inspiração linguística vem da entrada **Kúlemba**, associada a oferecer um presente de noivado e a ficar noivo, no [Arquivo Kimbundu](https://www.kimbundu.org/en/word/kilembe). O nome comercial mantém a grafia escolhida: **Kulemba**.

## Ficheiros e aplicação

- `assets/logo-kulemba.png`: assinatura horizontal com fundo transparente, gerada para este projecto; preserve a proporção 1850 × 422.
- `assets/icone-sistema.png`: símbolo isolado, sem reconstruir a arte.
- `assets/icone-sistema-{32,180,192,512}.png` e `favicon.ico`: versões reduzidas com fundo marfim para leitura sobre separadores claros ou escuros.
- `parcial-marca.php`: ponto único para apresentar a assinatura. Em fundos escuros, o CSS converte a marca para branco; o ficheiro mantém a transparência.

Não esticar, comprimir, rodar nem separar as letras. Usar a assinatura horizontal a partir de 145 px de largura; nos tamanhos de ícone, usar apenas o símbolo. O logótipo foi criado com geração de imagem e os tamanhos de aplicação foram derivados por recorte, redimensionamento proporcional e margens.

## Paletas

**Jardim Nocturno** conserva os valores do antigo tema padrão: azul-noite `#16283A`, verde de acento `#63B22B` e marfim `#F6F8F4`. Os tokens de contraste existentes continuam em vigor, incluindo `#3C7517` para texto e botões. Clássico, Azul corporativo e Escuro continuam disponíveis.

A chave anterior `niras` existe apenas na migração/compatibilidade de preferências. A v55 converte a definição do sistema para `jardim`; `parcial-tema.php` converte a preferência do navegador. O service worker usa `porta-v4` para renovar a marca nos ficheiros em cache.
