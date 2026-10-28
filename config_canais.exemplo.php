<?php

/*
 * Modelo de configuração. Copie para config_canais.php (que fica fora do
 * Git, pois guarda chaves de API) e preencha.
 */
return [
    /*
     * true só na máquina local / apresentação do TCC: mostra na tela os
     * links de senha que não puderam ser enviados e cria o administrador
     * inicial (admin@koinon.com). Em produção deixe false.
     */
    "modo_demonstracao" => false,

    /* Endereço público do sistema, usado nos links de senha. Obrigatório fora do modo demonstração. */
    "base_url" => "https://seu-dominio.com.br/koinon-service",

    /*
     * Pasta do sistema no servidor ("/koinon-service" no XAMPP, "/" na raiz
     * do domínio). Vazio: deduzido automaticamente.
     */
    "base_path" => "",

    /* Banco de dados. Os valores abaixo são os do XAMPP. */
    "banco" => [
        "host" => "127.0.0.1",
        "porta" => 3306,
        "nome" => "koinon_service",
        "usuario" => "root",
        "senha" => "",
    ],

    "email" => [
        "provedor" => "mail", // "mail" (função mail() do PHP) ou "resend"
        "remetente_nome" => "Koinon Services",
        "remetente_email" => "noreply@seu-dominio.com.br",
        "resend_api_key" => "",
    ],

    "whatsapp" => [
        "habilitado" => false,
        "phone_number_id" => "",
        "access_token" => "",
        "graph_version" => "v23.0",
        "template_name" => "koinon_redefinicao_senha",
        "template_language" => "pt_BR",
    ],
];
