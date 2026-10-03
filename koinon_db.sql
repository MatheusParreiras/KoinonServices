SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
--
-- Banco de dados: `koinon_service`
--
-- Estrutura para tabela `autorizacao_acesso`
--

CREATE TABLE `autorizacao_acesso` (
  `id` bigint(20) NOT NULL,
  `visitante_id` bigint(20) NOT NULL,
  `unidade_id` bigint(20) NOT NULL,
  `usuario_autorizador_id` bigint(20) DEFAULT NULL,
  `data_inicio` datetime NOT NULL,
  `data_fim` datetime NOT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Ativo' CHECK (`status` in ('Ativo','Expirado','Cancelado','Utilizado')),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

--
-- Despejando dados para a tabela `autorizacao_acesso`
--

INSERT INTO `autorizacao_acesso` (`id`, `visitante_id`, `unidade_id`, `usuario_autorizador_id`, `data_inicio`, `data_fim`, `qr_code`, `status`, `created_at`) VALUES
(26, 16, 3, NULL, '2026-09-24 19:54:00', '2026-09-24 20:54:00', 'KOINON-BC09FE6DBA44FB90', 'Utilizado', '2026-09-24 19:54:24'),
(28, 16, 5, 22, '2026-09-25 21:01:00', '2026-09-25 22:01:00', 'KOINON-E01B15E27A7A8E43', 'Expirado', '2026-09-25 21:01:14');

-- --------------------------------------------------------

--
-- Estrutura para tabela `cobranca`
--

CREATE TABLE `cobranca` (
  `id` bigint(20) NOT NULL,
  `unidade_id` bigint(20) NOT NULL,
  `tipo_cobranca` varchar(30) NOT NULL DEFAULT 'Taxa_Condominial',
  `mes_referencia` varchar(7) NOT NULL,
  `descricao` varchar(150) NOT NULL DEFAULT 'Taxa Condominial Ordinária',
  `valor` decimal(10,2) NOT NULL,
  `vencimento` date NOT NULL,
  `linha_digitavel` varchar(60) DEFAULT NULL,
  `link_boleto` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Pendente' CHECK (`status` in ('Pendente','Pago','Vencido','Cancelado')),
  `data_pagamento` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Boletos e controle financeiro.';

--
-- Despejando dados para a tabela `cobranca`
--

INSERT INTO `cobranca` (`id`, `unidade_id`, `tipo_cobranca`, `mes_referencia`, `descricao`, `valor`, `vencimento`, `linha_digitavel`, `link_boleto`, `status`, `data_pagamento`, `created_at`) VALUES
(14, 3, 'Multa', '2026-09', 'Reserva #19 - Churrasqueira', 50.00, '2026-09-25', NULL, NULL, 'Pago', '2026-09-24 11:07:47', '2026-09-24 11:06:49'),
(16, 4, 'Taxa_Condominial', '2026-09', 'fgh', 150.00, '2026-09-26', '56345634563457354634563456345634', 'http://localhost/koinon-service-v3/view/esqueci_senha.php', 'Pago', '2026-09-25 10:17:28', '2026-09-25 00:47:11'),
(17, 4, 'Reserva', '2026-09', 'Reserva #21 - Churrasqueira', 50.00, '2026-09-25', NULL, NULL, 'Cancelado', NULL, '2026-09-25 08:53:43'),
(18, 5, 'Reserva', '2026-09', 'Reserva #23 - Salão de Festa', 150.00, '2026-09-30', NULL, NULL, 'Pago', '2026-09-29 09:53:42', '2026-09-29 09:52:10');

-- --------------------------------------------------------

--
-- Estrutura para tabela `comprovante`
--

CREATE TABLE `comprovante` (
  `id` bigint(20) NOT NULL,
  `numero_comprovante` varchar(40) NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `encomenda_id` bigint(20) DEFAULT NULL,
  `cobranca_id` bigint(20) DEFAULT NULL,
  `unidade_id` bigint(20) DEFAULT NULL,
  `usuario_id` bigint(20) DEFAULT NULL,
  `data_emissao` datetime NOT NULL DEFAULT current_timestamp(),
  `dados_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `comprovante`
--

INSERT INTO `comprovante` (`id`, `numero_comprovante`, `tipo`, `encomenda_id`, `cobranca_id`, `unidade_id`, `usuario_id`, `data_emissao`, `dados_json`, `created_at`) VALUES
(10, 'KNS-20260929134132-58342F', 'Retirada_Encomenda', 9, NULL, 3, NULL, '2026-09-25 16:54:42', '{\"encomenda_id\":9,\"unidade_id\":3,\"usuario_id\":null,\"usuario_nome\":null,\"unidade_identificacao\":\"Bloco 1 - 102\",\"descricao\":\"rfter\",\"codigo_rastreio\":\"75675467456\",\"data_recebimento\":\"2026-09-24 12:53:17\",\"data_retirada\":\"2026-09-25 16:54:42\",\"data_emissao\":\"2026-09-25 16:54:42\"}', '2026-09-29 13:41:32'),
(11, 'KNS-20260929134132-4E2BB3', 'Pagamento_Cobranca', NULL, 14, 3, NULL, '2026-09-24 11:07:47', '{\"cobranca_id\":14,\"unidade_id\":3,\"usuario_id\":null,\"usuario_nome\":null,\"unidade_identificacao\":\"Bloco 1 - 102\",\"descricao\":\"Reserva #19 - Churrasqueira\",\"valor\":\"50.00\",\"vencimento\":\"2026-09-25\",\"data_pagamento\":\"2026-09-24 11:07:47\",\"mes_referencia\":\"2026-09\",\"tipo_cobranca\":\"Multa\",\"data_emissao\":\"2026-09-24 11:07:47\"}', '2026-09-29 13:41:32'),
(12, 'KNS-20260929134132-4D2F09', 'Pagamento_Cobranca', NULL, 16, 4, NULL, '2026-09-25 10:17:28', '{\"cobranca_id\":16,\"unidade_id\":4,\"usuario_id\":null,\"usuario_nome\":null,\"unidade_identificacao\":\"Bloco A - 106\",\"descricao\":\"fgh\",\"valor\":\"150.00\",\"vencimento\":\"2026-09-26\",\"data_pagamento\":\"2026-09-25 10:17:28\",\"mes_referencia\":\"2026-09\",\"tipo_cobranca\":\"Taxa_Condominial\",\"data_emissao\":\"2026-09-25 10:17:28\"}', '2026-09-29 13:41:32'),
(13, 'KNS-20260929134132-B24C73', 'Pagamento_Cobranca', NULL, 18, 5, 22, '2026-09-29 09:53:42', '{\"cobranca_id\":18,\"unidade_id\":5,\"usuario_id\":22,\"usuario_nome\":\"Joao Morador\",\"unidade_identificacao\":\"Bloco A - 107\",\"descricao\":\"Reserva #23 - Salão de Festa\",\"valor\":\"150.00\",\"vencimento\":\"2026-09-30\",\"data_pagamento\":\"2026-09-29 09:53:42\",\"mes_referencia\":\"2026-09\",\"tipo_cobranca\":\"Reserva\",\"data_emissao\":\"2026-09-29 09:53:42\"}', '2026-09-29 13:41:32');

-- --------------------------------------------------------

--
-- Estrutura para tabela `documento`
--

CREATE TABLE `documento` (
  `id` bigint(20) NOT NULL,
  `usuario_upload_id` bigint(20) DEFAULT NULL,
  `titulo` varchar(150) NOT NULL,
  `categoria` varchar(50) NOT NULL CHECK (`categoria` in ('Atas','Convenção','Regimento_Interno','Prestação_Contas','Contrato','Outros')),
  `descricao` text DEFAULT NULL,
  `arquivo_url` text NOT NULL,
  `data_upload` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Atas, convenções e relatórios em PDF.';

-- --------------------------------------------------------

--
-- Estrutura para tabela `encomenda`
--

CREATE TABLE `encomenda` (
  `id` bigint(20) NOT NULL,
  `unidade_id` bigint(20) NOT NULL,
  `usuario_recebedor_id` bigint(20) DEFAULT NULL,
  `descricao` text NOT NULL,
  `foto_url` text DEFAULT NULL,
  `codigo_rastreio` varchar(50) DEFAULT NULL,
  `data_recebimento` datetime NOT NULL DEFAULT current_timestamp(),
  `data_retirada` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Aguardando_Retirada' CHECK (`status` in ('Aguardando_Retirada','Entregue','Devolvido'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Controle de recebimento e entrega de encomendas.';

--
-- Despejando dados para a tabela `encomenda`
--

INSERT INTO `encomenda` (`id`, `unidade_id`, `usuario_recebedor_id`, `descricao`, `foto_url`, `codigo_rastreio`, `data_recebimento`, `data_retirada`, `status`) VALUES
(9, 3, NULL, 'rfter', '../../assets/img/encomendas/encomenda_20260924_125338_b7c9a1a77ab817a2.jpeg', '75675467456', '2026-09-24 12:53:17', '2026-09-25 16:54:42', 'Entregue');

-- --------------------------------------------------------

--
-- Estrutura para tabela `enquete_opcao`
--

CREATE TABLE `enquete_opcao` (
  `id` bigint(20) NOT NULL,
  `publicacao_id` bigint(20) NOT NULL,
  `descricao` varchar(150) NOT NULL,
  `ordem` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Opções disponíveis para as publicações do tipo enquete.';

-- --------------------------------------------------------

--
-- Estrutura para tabela `interacao`
--

CREATE TABLE `interacao` (
  `id` bigint(20) NOT NULL,
  `publicacao_id` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `tipo_interacao` varchar(20) NOT NULL CHECK (`tipo_interacao` in ('Curtida','Comentario','Voto_Enquete')),
  `enquete_opcao_id` bigint(20) DEFAULT NULL,
  `conteudo_texto` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Curtidas, comentários e votos de enquetes.';

-- --------------------------------------------------------

--
-- Estrutura para tabela `local_reserva`
--

CREATE TABLE `local_reserva` (
  `id` bigint(20) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `capacidade` int(11) NOT NULL DEFAULT 1,
  `valor` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gratuito` tinyint(1) NOT NULL DEFAULT 1,
  `duracao_minutos_minima` int(11) NOT NULL DEFAULT 60,
  `duracao_minutos_maxima` int(11) NOT NULL DEFAULT 1440,
  `horario_inicio` time NOT NULL DEFAULT '08:00:00',
  `horario_fim` time NOT NULL DEFAULT '23:00:00',
  `antecedencia_min_dias` int(11) NOT NULL DEFAULT 0,
  `antecedencia_max_dias` int(11) NOT NULL DEFAULT 30,
  `limite_reservas_unidade` int(11) NOT NULL DEFAULT 1,
  `periodo_limite` varchar(10) NOT NULL DEFAULT 'Dia',
  `domingo` tinyint(1) NOT NULL DEFAULT 0,
  `segunda` tinyint(1) NOT NULL DEFAULT 1,
  `terca` tinyint(1) NOT NULL DEFAULT 1,
  `quarta` tinyint(1) NOT NULL DEFAULT 1,
  `quinta` tinyint(1) NOT NULL DEFAULT 1,
  `sexta` tinyint(1) NOT NULL DEFAULT 1,
  `sabado` tinyint(1) NOT NULL DEFAULT 1,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fotos` longtext DEFAULT NULL
) ;

--
-- Despejando dados para a tabela `local_reserva`
--

INSERT INTO `local_reserva` (`id`, `nome`, `descricao`, `capacidade`, `valor`, `gratuito`, `duracao_minutos_minima`, `duracao_minutos_maxima`, `horario_inicio`, `horario_fim`, `antecedencia_min_dias`, `antecedencia_max_dias`, `limite_reservas_unidade`, `periodo_limite`, `domingo`, `segunda`, `terca`, `quarta`, `quinta`, `sexta`, `sabado`, `ativo`, `created_at`, `updated_at`, `fotos`) VALUES
(1, 'Academia', '', 2, 0.00, 1, 60, 120, '08:00:00', '23:00:00', 1, 31, 4, 'Dia', 1, 0, 1, 1, 1, 1, 1, 1, '2026-09-22 19:01:58', '2026-09-25 20:59:19', '[\"uploads/locais/1/1f26938fd7ef7395.jpg\",\"uploads/locais/1/17e7c20a6bbd69b0.jpg\"]'),
(2, 'Quadra Esportiva', '', 10, 0.00, 1, 60, 240, '08:00:00', '23:00:00', 0, 2, 1, 'Dia', 1, 1, 1, 1, 1, 1, 1, 1, '2026-09-22 22:48:13', '2026-09-25 20:56:40', NULL),
(3, 'Salão de Festa', '', 500, 150.00, 0, 120, 360, '08:00:00', '23:00:00', 0, 30, 1, 'Dia', 1, 1, 1, 1, 1, 1, 1, 1, '2026-09-23 11:38:12', '2026-09-25 20:56:46', NULL),
(4, 'Churrasqueira', '', 20, 50.00, 0, 240, 360, '08:00:00', '23:00:00', 0, 30, 2, 'Dia', 1, 1, 1, 1, 1, 1, 1, 1, '2026-09-23 15:05:39', '2026-10-01 13:48:26', '[]');

-- --------------------------------------------------------

--
-- Estrutura para tabela `notificacao`
--

CREATE TABLE `notificacao` (
  `id` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `publicacao_id` bigint(20) DEFAULT NULL,
  `tipo` varchar(50) NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `mensagem` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `lida` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `notificacao`
--

INSERT INTO `notificacao` (`id`, `usuario_id`, `publicacao_id`, `tipo`, `titulo`, `mensagem`, `link`, `lida`, `created_at`) VALUES
(38, 12, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-23 09:45:51'),
(41, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço QUADRA teve seu status alterado para: Cancelada.', 'nova_reserva.php?id=11', 1, '2026-09-23 11:38:55'),
(52, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Salão de Festa. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-23 13:29:48'),
(54, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Salão de Festa teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=13', 1, '2026-09-23 13:30:59'),
(60, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço QUADRA. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-23 13:55:55'),
(63, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Salão de Festa. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-23 13:56:21'),
(66, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Churrasqueira. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-23 15:28:04'),
(68, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Churrasqueira teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=16', 1, '2026-09-23 16:02:38'),
(73, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço QUADRA. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-23 23:12:31'),
(76, 12, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-23 23:16:10'),
(80, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Churrasqueira teve seu status alterado para: Concluida.', 'nova_reserva.php?id=16', 1, '2026-09-23 23:30:16'),
(83, 12, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Salão de Festa. Status: Cancelada.', 'nova_reserva.php?id=15', 1, '2026-09-23 23:30:20'),
(97, 12, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-24 00:06:56'),
(102, 12, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-24 09:07:25'),
(106, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Churrasqueira. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-24 09:08:39'),
(111, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Churrasqueira teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=18', 1, '2026-09-24 09:34:02'),
(114, 12, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Churrasqueira. Status: Confirmada.', 'nova_reserva.php?id=18', 1, '2026-09-24 09:34:11'),
(133, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Churrasqueira. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-24 11:06:22'),
(135, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Churrasqueira teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=19', 1, '2026-09-24 11:06:49'),
(145, 12, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-24 12:44:29'),
(149, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Churrasqueira. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-24 12:45:34'),
(154, 12, NULL, 'Ocorrencia', '⚠️ Atualização de ocorrência', 'A ocorrência sdf está com status: Cancelada.', 'ocorrencias.php?id=8', 1, '2026-09-24 12:55:29'),
(162, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Churrasqueira teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=20', 1, '2026-09-24 13:05:12'),
(165, 12, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Churrasqueira. Status: Confirmada.', 'nova_reserva.php?id=20', 1, '2026-09-24 13:05:46'),
(173, 12, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-24 19:54:24'),
(178, 12, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-25 00:10:20'),
(184, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Churrasqueira. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-25 08:52:32'),
(187, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Churrasqueira teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=21', 1, '2026-09-25 08:53:43'),
(191, 12, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Churrasqueira. Status: Pendente.', 'nova_reserva.php?id=21', 1, '2026-09-25 09:20:26'),
(195, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Churrasqueira teve seu status alterado para: Cancelada.', 'nova_reserva.php?id=21', 1, '2026-09-25 09:20:38'),
(199, 12, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Churrasqueira. Status: Pendente.', 'nova_reserva.php?id=21', 1, '2026-09-25 09:20:43'),
(207, 22, NULL, 'Reserva', '📅 Atualização da reserva', 'Sua reserva do espaço Academia está com status: Pendente.', 'nova_reserva.php?id=22', 1, '2026-09-25 20:59:55'),
(208, 12, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Academia. Status: Pendente.', 'nova_reserva.php?id=22', 1, '2026-09-25 20:59:55'),
(209, 24, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Academia. Status: Pendente.', 'nova_reserva.php?id=22', 1, '2026-09-25 20:59:55'),
(210, 22, NULL, 'Reserva', '📅 Atualização da reserva', 'Sua reserva do espaço Academia está com status: Confirmada.', 'nova_reserva.php?id=22', 1, '2026-09-25 21:00:02'),
(211, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Academia teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=22', 1, '2026-09-25 21:00:02'),
(212, 24, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Academia teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=22', 1, '2026-09-25 21:00:02'),
(213, 22, NULL, 'Autorizacao', '🚗 Atualização de autorização', 'A autorização para Visitante está com status: Ativo.', 'autorizacoes.php', 1, '2026-09-25 21:01:14'),
(214, 12, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-25 21:01:14'),
(215, 24, NULL, 'Autorizacao', '🚗 Nova autorização de acesso', 'Uma nova autorização de acesso foi cadastrada. Visitante: Visitante. Status: Ativo.', 'autorizacoes.php', 1, '2026-09-25 21:01:14'),
(216, 23, NULL, 'Autorizacao', '🚪 Nova autorização de visitante', 'Uma nova autorização de acesso foi cadastrada para atendimento na portaria. Status: Ativo.', 'autorizacao_acesso/index.php', 1, '2026-09-25 21:01:14'),
(217, 22, NULL, 'Reserva', '📅 Atualização da reserva', 'Sua reserva do espaço Salão de Festa está com status: Pendente.', 'nova_reserva.php?id=23', 1, '2026-09-29 09:52:06'),
(218, 12, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Salão de Festa. Status: Pendente.', 'nova_reserva.php?id=23', 1, '2026-09-29 09:52:06'),
(219, 24, NULL, 'Reserva', '📅 Nova reserva cadastrada', 'Uma nova reserva foi cadastrada para o espaço Salão de Festa. Status: Pendente.', 'nova_reserva.php?id=23', 1, '2026-09-29 09:52:06'),
(220, 22, NULL, 'Reserva', '📅 Atualização da reserva', 'Sua reserva do espaço Salão de Festa está com status: Confirmada.', 'nova_reserva.php?id=23', 1, '2026-09-29 09:52:10'),
(221, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Salão de Festa teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=23', 1, '2026-09-29 09:52:10'),
(222, 24, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Salão de Festa teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=23', 1, '2026-09-29 09:52:10'),
(223, 22, NULL, 'Cobranca', '💰 Cobrança paga', 'A cobrança Reserva #23 - Salão de Festa foi registrada como paga.', 'index.php', 1, '2026-09-29 09:53:42'),
(224, 22, NULL, 'Reserva', '📅 Atualização da reserva', 'Sua reserva do espaço Academia está com status: Pendente.', 'nova_reserva.php', 1, '2026-09-29 14:48:42'),
(225, 12, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Academia. Status: Pendente.', 'view/reserva/index.php', 1, '2026-09-29 14:48:42'),
(226, 24, NULL, 'Reserva', '📅 Nova reserva solicitada', 'Uma nova reserva foi solicitada para o espaço Academia. Status: Pendente.', 'view/reserva/index.php', 0, '2026-09-29 14:48:42'),
(227, 22, NULL, 'Reserva', '📅 Atualização da reserva', 'Sua reserva do espaço Academia está com status: Confirmada.', 'nova_reserva.php?id=24', 0, '2026-10-01 13:47:47'),
(228, 12, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Academia teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=24', 0, '2026-10-01 13:47:47'),
(229, 24, NULL, 'Reserva', '📅 Reserva atualizada', 'A reserva do espaço Academia teve seu status alterado para: Confirmada.', 'nova_reserva.php?id=24', 0, '2026-10-01 13:47:47');

-- --------------------------------------------------------

--
-- Estrutura para tabela `ocorrencia`
--

CREATE TABLE `ocorrencia` (
  `id` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `descricao` text NOT NULL,
  `status` varchar(20) DEFAULT 'Aberta' CHECK (`status` in ('Aberta','Em_Andamento','Resolvida','Cancelada')),
  `foto_url` text DEFAULT NULL,
  `data_abertura` datetime NOT NULL DEFAULT current_timestamp(),
  `data_fechamento` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Chamados de moradores e manutenção.';

-- --------------------------------------------------------

--
-- Estrutura para tabela `publicacao`
--

CREATE TABLE `publicacao` (
  `id` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `categoria` varchar(30) NOT NULL CHECK (`categoria` in ('Aviso_Sindico','Feed_Social','Classificados','Achados_Perdidos','Enquete')),
  `tipo_achado` enum('Achado','Perdido') DEFAULT NULL,
  `titulo` varchar(150) NOT NULL,
  `conteudo` text NOT NULL,
  `local_ocorrencia` varchar(200) DEFAULT NULL,
  `data_ocorrencia` date DEFAULT NULL,
  `midia_url` text DEFAULT NULL,
  `preco` decimal(10,2) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Ativo' CHECK (`status` in ('Ativo','Encerrado','Arquivado','Removido')),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Feed social, avisos, classificados e enquetes.';

-- --------------------------------------------------------

--
-- Estrutura para tabela `registro_acesso`
--

CREATE TABLE `registro_acesso` (
  `id` bigint(20) NOT NULL,
  `usuario_id` bigint(20) DEFAULT NULL,
  `visitante_id` bigint(20) DEFAULT NULL,
  `data_hora` datetime NOT NULL DEFAULT current_timestamp(),
  `direcao` varchar(10) NOT NULL CHECK (`direcao` in ('Entrada','Saida')),
  `metodo_leitura` varchar(30) NOT NULL CHECK (`metodo_leitura` in ('Facial','QR_Code','Placa_LPR','Tag_RFID','Manual')),
  `foto_evento_url` text DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `autorizado_por_usuario_id` bigint(20) DEFAULT NULL
) ;

--
-- Despejando dados para a tabela `registro_acesso`
--

INSERT INTO `registro_acesso` (`id`, `usuario_id`, `visitante_id`, `data_hora`, `direcao`, `metodo_leitura`, `foto_evento_url`, `observacao`, `autorizado_por_usuario_id`) VALUES
(23, NULL, NULL, '2026-09-24 12:15:24', 'Entrada', 'Manual', NULL, 'Morador a pé.', NULL),
(24, NULL, NULL, '2026-09-24 12:15:37', 'Saida', 'Placa_LPR', NULL, 'Morador em veículo: SDFSD5425 — Prisma', NULL),
(26, NULL, NULL, '2026-09-24 12:16:00', 'Saida', 'Manual', NULL, 'saida', NULL),
(27, NULL, NULL, '2026-09-24 12:16:00', 'Entrada', 'QR_Code', NULL, 'teste morador', NULL),
(28, NULL, NULL, '2026-09-24 12:48:03', 'Entrada', 'QR_Code', NULL, 'Entrada autorizada através de QR Code.', NULL),
(29, NULL, 16, '2026-09-24 19:55:32', 'Entrada', 'QR_Code', NULL, 'Entrada autorizada através de QR Code.', NULL),
(30, NULL, NULL, '2026-09-25 09:43:28', 'Entrada', 'Manual', NULL, 'Morador a pé.', NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `reserva`
--

CREATE TABLE `reserva` (
  `id` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `local_reserva_id` bigint(20) DEFAULT NULL,
  `espaco_nome` varchar(100) NOT NULL,
  `quantidade_pessoas` int(11) NOT NULL DEFAULT 1,
  `data_inicio` datetime NOT NULL,
  `data_fim` datetime NOT NULL,
  `status` varchar(20) DEFAULT 'Pendente' CHECK (`status` in ('Pendente','Confirmada','Cancelada','Concluida')),
  `valor_taxa` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

--
-- Despejando dados para a tabela `reserva`
--

INSERT INTO `reserva` (`id`, `usuario_id`, `local_reserva_id`, `espaco_nome`, `quantidade_pessoas`, `data_inicio`, `data_fim`, `status`, `valor_taxa`, `created_at`) VALUES
(23, 22, 3, 'Salão de Festa', 1, '2026-09-30 10:00:00', '2026-09-30 12:00:00', 'Concluida', 150.00, '2026-09-29 09:52:06'),
(24, 22, 1, 'Academia', 1, '2026-09-30 10:00:00', '2026-09-30 11:00:00', 'Concluida', 0.00, '2026-09-29 14:48:42');

-- --------------------------------------------------------

--
-- Estrutura para tabela `unidade`
--

CREATE TABLE `unidade` (
  `id` bigint(20) NOT NULL,
  `identificacao` varchar(50) NOT NULL,
  `tipo_unidade` varchar(30) NOT NULL CHECK (`tipo_unidade` in ('Residencial','Comercial','Vaga','Depósito')),
  `fracao_ideal` decimal(8,6) DEFAULT 0.000000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `quantidade_vagas` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 CHECK (`quantidade_vagas` between 0 and 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Mapeia as frações autônomas do condomínio.';

--
-- Despejando dados para a tabela `unidade`
--

INSERT INTO `unidade` (`id`, `identificacao`, `tipo_unidade`, `fracao_ideal`, `created_at`, `quantidade_vagas`) VALUES
(3, 'Bloco 1 - 102', 'Residencial', 0.000000, '2026-09-23 19:33:04', 1),
(4, 'Bloco A - 106', 'Residencial', 0.000000, '2026-09-24 13:02:23', 1),
(5, 'Bloco A - 107', 'Residencial', 0.000000, '2026-09-25 09:19:52', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuario`
--

CREATE TABLE `usuario` (
  `id` bigint(20) NOT NULL,
  `unidade_id` bigint(20) DEFAULT NULL,
  `nome` varchar(150) NOT NULL,
  `cpf` varchar(14) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `senha_definida` tinyint(1) NOT NULL DEFAULT 1,
  `primeiro_acesso` tinyint(1) NOT NULL DEFAULT 0,
  `perfil` varchar(30) NOT NULL CHECK (`perfil` in ('Morador','Sindico','Porteiro','Administrador')),
  `biometria_hash` text DEFAULT NULL,
  `termo_lgpd` tinyint(1) NOT NULL DEFAULT 0,
  `data_aceite_lgpd` datetime DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Centraliza os usuários e vetores de biometria facial.';

--
-- Despejando dados para a tabela `usuario`
--

INSERT INTO `usuario` (`id`, `unidade_id`, `nome`, `cpf`, `email`, `telefone`, `senha_hash`, `senha_definida`, `primeiro_acesso`, `perfil`, `biometria_hash`, `termo_lgpd`, `data_aceite_lgpd`, `ativo`, `created_at`) VALUES
(12, NULL, 'Joao Administrador', '151561256', 'TESTEADM@GMAIL.COM', NULL, '$2y$10$P3PQ1WdNRxjxlYe3TNAnnOo3Z66gzE0Zr2WIM1s5lcbXfQUFDId/C', 1, 0, 'Administrador', NULL, 1, '2026-09-21 02:25:31', 1, '2026-09-20 21:25:31'),
(22, 5, 'Joao Morador', '78945612300', 'testemorador@gmail.com', '61999999999', '$2y$10$uTRXCpklQlGLZgicPf4i.e/QzsTnnqA5EC0X/6TLU9H7AptX6ppMe', 1, 0, 'Morador', NULL, 1, '2026-09-25 11:39:46', 1, '2026-09-25 11:35:12'),
(23, NULL, 'Joao Porteiro', '78945612301', 'TESTEPORTEIRO@GMAIL.COM', '61999999999', '$2y$10$CQJVwEiYkCW2KxhCbycDvuf/A66Mrh4nNDX/K60NyYry7MuRDwbeK', 1, 0, 'Porteiro', NULL, 1, '2026-09-25 11:44:24', 1, '2026-09-25 11:37:03'),
(24, NULL, 'Joao Sindico', '78945612303', 'TESTESINDICO@GMAIL.COM', '61999999999', '$2y$10$r/JYWVHCqmlk4icnnvi4R.dvrHeY2xgtv/l278eNvEQDukZtGiBHy', 1, 0, 'Sindico', NULL, 1, '2026-09-25 11:52:10', 1, '2026-09-25 11:38:00'),
(25, NULL, 'Administrador', '01234567890', 'admin@koinon.com', NULL, '$2y$10$A12T.T.9VGjT20D.TvyEVODZKPDHQ5mRvdVTJyr6Izte1cArolcGa', 0, 1, 'Administrador', NULL, 0, NULL, 0, '2026-09-25 20:49:34');

-- --------------------------------------------------------

--
-- Estrutura para tabela `veiculo`
--

CREATE TABLE `veiculo` (
  `id` bigint(20) NOT NULL,
  `usuario_id` bigint(20) NOT NULL,
  `placa` varchar(10) NOT NULL,
  `modelo` varchar(50) NOT NULL,
  `cor` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status_aprovacao` varchar(20) NOT NULL DEFAULT 'Pendente' CHECK (`status_aprovacao` in ('Pendente','Aprovado','Rejeitado'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Cadastro de veículos para automação LPR.';

--
-- Despejando dados para a tabela `veiculo`
--

INSERT INTO `veiculo` (`id`, `usuario_id`, `placa`, `modelo`, `cor`, `created_at`, `status_aprovacao`) VALUES
(10, 22, 'ABC3256', 'Onix', 'Preto', '2026-09-25 20:51:07', 'Aprovado');

-- --------------------------------------------------------

--
-- Estrutura para tabela `visitante`
--

CREATE TABLE `visitante` (
  `id` bigint(20) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `documento` varchar(20) NOT NULL,
  `foto_url` text DEFAULT NULL,
  `tipo_visitante` varchar(30) DEFAULT 'Visitante' CHECK (`tipo_visitante` in ('Visitante','Prestador_Servico','Entregador')),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Cadastro de visitantes e prestadores externos.';

--
-- Despejando dados para a tabela `visitante`
--

INSERT INTO `visitante` (`id`, `nome`, `documento`, `foto_url`, `tipo_visitante`, `created_at`) VALUES
(16, 'LUCAS', '5463456345', '../../assets/img/visitantes/visitante_20260924_195411_b8e62d9c.jpg', 'Visitante', '2026-09-24 19:54:11');

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `autorizacao_acesso`
--
ALTER TABLE `autorizacao_acesso`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD KEY `fk_autorizacao_visitante` (`visitante_id`),
  ADD KEY `fk_autorizacao_unidade` (`unidade_id`),
  ADD KEY `fk_autorizacao_usuario` (`usuario_autorizador_id`),
  ADD KEY `idx_autorizacao_qr` (`qr_code`);

--
-- Índices de tabela `cobranca`
--
ALTER TABLE `cobranca`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cobranca_unidade_status` (`unidade_id`,`status`);

--
-- Índices de tabela `comprovante`
--
ALTER TABLE `comprovante`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_comprovante_numero` (`numero_comprovante`),
  ADD UNIQUE KEY `uq_comprovante_encomenda` (`encomenda_id`),
  ADD UNIQUE KEY `uq_comprovante_cobranca` (`cobranca_id`),
  ADD KEY `idx_comprovante_unidade` (`unidade_id`),
  ADD KEY `idx_comprovante_usuario` (`usuario_id`);

--
-- Índices de tabela `documento`
--
ALTER TABLE `documento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_documento_usuario` (`usuario_upload_id`);

--
-- Índices de tabela `encomenda`
--
ALTER TABLE `encomenda`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_encomenda_usuario` (`usuario_recebedor_id`),
  ADD KEY `idx_encomenda_unidade_status` (`unidade_id`,`status`);

--
-- Índices de tabela `enquete_opcao`
--
ALTER TABLE `enquete_opcao`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enquete_opcao_publicacao` (`publicacao_id`);

--
-- Índices de tabela `interacao`
--
ALTER TABLE `interacao`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_interacao_publicacao` (`publicacao_id`),
  ADD KEY `fk_interacao_usuario` (`usuario_id`),
  ADD KEY `idx_interacao_enquete_opcao` (`enquete_opcao_id`);

--
-- Índices de tabela `local_reserva`
--
ALTER TABLE `local_reserva`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_local_reserva_nome` (`nome`);

--
-- Índices de tabela `notificacao`
--
ALTER TABLE `notificacao`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notificacao_usuario` (`usuario_id`),
  ADD KEY `fk_notificacao_publicacao` (`publicacao_id`);

--
-- Índices de tabela `ocorrencia`
--
ALTER TABLE `ocorrencia`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ocorrencia_usuario` (`usuario_id`);

--
-- Índices de tabela `publicacao`
--
ALTER TABLE `publicacao`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_publicacao_usuario` (`usuario_id`),
  ADD KEY `idx_publicacao_categoria` (`categoria`);

--
-- Índices de tabela `registro_acesso`
--
ALTER TABLE `registro_acesso`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_registro_usuario` (`usuario_id`),
  ADD KEY `fk_registro_visitante` (`visitante_id`),
  ADD KEY `idx_registro_acesso_data` (`data_hora`),
  ADD KEY `fk_registro_acesso_autorizador` (`autorizado_por_usuario_id`);

--
-- Índices de tabela `reserva`
--
ALTER TABLE `reserva`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_reserva_usuario` (`usuario_id`),
  ADD KEY `idx_reserva_local_data` (`local_reserva_id`,`data_inicio`,`data_fim`);

--
-- Índices de tabela `unidade`
--
ALTER TABLE `unidade`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cpf` (`cpf`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_usuario_unidade` (`unidade_id`),
  ADD KEY `idx_usuario_cpf` (`cpf`);

--
-- Índices de tabela `veiculo`
--
ALTER TABLE `veiculo`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `placa` (`placa`),
  ADD KEY `fk_veiculo_usuario` (`usuario_id`),
  ADD KEY `idx_veiculo_placa` (`placa`);

--
-- Índices de tabela `visitante`
--
ALTER TABLE `visitante`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `autorizacao_acesso`
--
ALTER TABLE `autorizacao_acesso`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `cobranca`
--
ALTER TABLE `cobranca`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT de tabela `comprovante`
--
ALTER TABLE `comprovante`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de tabela `documento`
--
ALTER TABLE `documento`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `encomenda`
--
ALTER TABLE `encomenda`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de tabela `enquete_opcao`
--
ALTER TABLE `enquete_opcao`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de tabela `interacao`
--
ALTER TABLE `interacao`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de tabela `local_reserva`
--
ALTER TABLE `local_reserva`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `notificacao`
--
ALTER TABLE `notificacao`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=230;

--
-- AUTO_INCREMENT de tabela `ocorrencia`
--
ALTER TABLE `ocorrencia`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de tabela `publicacao`
--
ALTER TABLE `publicacao`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT de tabela `registro_acesso`
--
ALTER TABLE `registro_acesso`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `reserva`
--
ALTER TABLE `reserva`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `unidade`
--
ALTER TABLE `unidade`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de tabela `usuario`
--
ALTER TABLE `usuario`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT de tabela `veiculo`
--
ALTER TABLE `veiculo`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de tabela `visitante`
--
ALTER TABLE `visitante`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `autorizacao_acesso`
--
ALTER TABLE `autorizacao_acesso`
  ADD CONSTRAINT `fk_autorizacao_unidade` FOREIGN KEY (`unidade_id`) REFERENCES `unidade` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_autorizacao_usuario` FOREIGN KEY (`usuario_autorizador_id`) REFERENCES `usuario` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_autorizacao_visitante` FOREIGN KEY (`visitante_id`) REFERENCES `visitante` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `cobranca`
--
ALTER TABLE `cobranca`
  ADD CONSTRAINT `fk_cobranca_unidade` FOREIGN KEY (`unidade_id`) REFERENCES `unidade` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `comprovante`
--
ALTER TABLE `comprovante`
  ADD CONSTRAINT `fk_comprovante_cobranca` FOREIGN KEY (`cobranca_id`) REFERENCES `cobranca` (`id`),
  ADD CONSTRAINT `fk_comprovante_encomenda` FOREIGN KEY (`encomenda_id`) REFERENCES `encomenda` (`id`),
  ADD CONSTRAINT `fk_comprovante_unidade` FOREIGN KEY (`unidade_id`) REFERENCES `unidade` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_comprovante_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `documento`
--
ALTER TABLE `documento`
  ADD CONSTRAINT `fk_documento_usuario` FOREIGN KEY (`usuario_upload_id`) REFERENCES `usuario` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `encomenda`
--
ALTER TABLE `encomenda`
  ADD CONSTRAINT `fk_encomenda_unidade` FOREIGN KEY (`unidade_id`) REFERENCES `unidade` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_encomenda_usuario` FOREIGN KEY (`usuario_recebedor_id`) REFERENCES `usuario` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `enquete_opcao`
--
ALTER TABLE `enquete_opcao`
  ADD CONSTRAINT `fk_enquete_opcao_publicacao` FOREIGN KEY (`publicacao_id`) REFERENCES `publicacao` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `interacao`
--
ALTER TABLE `interacao`
  ADD CONSTRAINT `fk_interacao_enquete_opcao` FOREIGN KEY (`enquete_opcao_id`) REFERENCES `enquete_opcao` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_interacao_publicacao` FOREIGN KEY (`publicacao_id`) REFERENCES `publicacao` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_interacao_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `notificacao`
--
ALTER TABLE `notificacao`
  ADD CONSTRAINT `fk_notificacao_publicacao` FOREIGN KEY (`publicacao_id`) REFERENCES `publicacao` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notificacao_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restrições para tabelas `ocorrencia`
--
ALTER TABLE `ocorrencia`
  ADD CONSTRAINT `fk_ocorrencia_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `publicacao`
--
ALTER TABLE `publicacao`
  ADD CONSTRAINT `fk_publicacao_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `registro_acesso`
--
ALTER TABLE `registro_acesso`
  ADD CONSTRAINT `fk_registro_acesso_autorizador` FOREIGN KEY (`autorizado_por_usuario_id`) REFERENCES `usuario` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_registro_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_registro_visitante` FOREIGN KEY (`visitante_id`) REFERENCES `visitante` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `reserva`
--
ALTER TABLE `reserva`
  ADD CONSTRAINT `fk_reserva_local_reserva` FOREIGN KEY (`local_reserva_id`) REFERENCES `local_reserva` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reserva_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `usuario`
--
ALTER TABLE `usuario`
  ADD CONSTRAINT `fk_usuario_unidade` FOREIGN KEY (`unidade_id`) REFERENCES `unidade` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `veiculo`
--
ALTER TABLE `veiculo`
  ADD CONSTRAINT `fk_veiculo_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
