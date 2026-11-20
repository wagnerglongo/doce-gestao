<?php

// Copie para config.php (na raiz do site) e preencha com os dados do banco
// criado no hPanel da Hostinger (Bancos de dados > MySQL).
// O config.php não vai para o Git.

return [
    'DB_DSN' => 'mysql:host=localhost;dbname=u000000000_docegestao;charset=utf8mb4',
    'DB_USER' => 'u000000000_docegestao',
    'DB_PASSWORD' => 'troque-esta-senha',

    // Primeiro acesso: usado pelo bin/setup.php só quando ainda não existe nenhum usuário.
    // Depois da instalação, pode apagar a senha daqui.
    'ADMIN_EMAIL' => 'admin@exemplo.com',
    'ADMIN_PASSWORD' => 'troque-esta-senha',
];
