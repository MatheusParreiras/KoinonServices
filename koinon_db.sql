-- =============================================================================
-- SCRIPT DO BANCO DE DADOS (DDL) - VERSÃO MYSQL 8.0+
-- Sistema de Gestão de Condomínios
-- Engine: InnoDB | Charset: utf8mb4
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS Documento;
DROP TABLE IF EXISTS Cobranca;
DROP TABLE IF EXISTS Interacao;
DROP TABLE IF EXISTS Publicacao;
DROP TABLE IF EXISTS Ocorrencia;
DROP TABLE IF EXISTS Encomenda;
DROP TABLE IF EXISTS Reserva;
DROP TABLE IF EXISTS Registro_Acesso;
DROP TABLE IF EXISTS Autorizacao_Acesso;
DROP TABLE IF EXISTS Visitante;
DROP TABLE IF EXISTS Veiculo;
DROP TABLE IF EXISTS Usuario;
DROP TABLE IF EXISTS Unidade;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 1. NÚCLEO E CADASTROS BASE
-- =============================================================================

-- TABELA 1: Unidade
CREATE TABLE Unidade (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    identificacao VARCHAR(50) NOT NULL,
    tipo_unidade VARCHAR(30) NOT NULL CHECK (tipo_unidade IN ('Residencial', 'Comercial', 'Vaga', 'Depósito')),
    fracao_ideal DECIMAL(8,6) DEFAULT 0.000000,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Mapeia as frações autônomas do condomínio.';

-- TABELA 2: Usuario
CREATE TABLE Usuario (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    unidade_id BIGINT,
    nome VARCHAR(150) NOT NULL,
    cpf VARCHAR(14) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    perfil VARCHAR(30) NOT NULL CHECK (perfil IN ('Morador', 'Sindico', 'Porteiro', 'Administrador')),
    biometria_hash TEXT,
    termo_lgpd BOOLEAN DEFAULT FALSE NOT NULL,
    data_aceite_lgpd DATETIME,
    ativo BOOLEAN DEFAULT TRUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_usuario_unidade FOREIGN KEY (unidade_id) REFERENCES Unidade(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Centraliza os usuários e vetores de biometria facial.';

-- TABELA 3: Veiculo
CREATE TABLE Veiculo (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT NOT NULL,
    placa VARCHAR(10) UNIQUE NOT NULL,
    modelo VARCHAR(50) NOT NULL,
    cor VARCHAR(30) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_veiculo_usuario FOREIGN KEY (usuario_id) REFERENCES Usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cadastro de veículos para automação LPR.';


-- =============================================================================
-- 2. PORTARIA INTELIGENTE E CONTROLE DE ACESSO
-- =============================================================================

-- TABELA 4: Visitante
CREATE TABLE Visitante (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    documento VARCHAR(20) NOT NULL,
    foto_url TEXT,
    tipo_visitante VARCHAR(30) DEFAULT 'Visitante' CHECK (tipo_visitante IN ('Visitante', 'Prestador_Servico', 'Entregador')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cadastro de visitantes e prestadores externos.';

-- TABELA 5: Autorizacao_Acesso
CREATE TABLE Autorizacao_Acesso (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    visitante_id BIGINT NOT NULL,
    unidade_id BIGINT NOT NULL,
    usuario_autorizador_id BIGINT,
    data_inicio DATETIME NOT NULL,
    data_fim DATETIME NOT NULL,
    qr_code VARCHAR(255) UNIQUE,
    status VARCHAR(20) DEFAULT 'Ativo' CHECK (status IN ('Ativo', 'Expirado', 'Cancelado', 'Utilizado')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_autorizacao_visitante FOREIGN KEY (visitante_id) REFERENCES Visitante(id) ON DELETE CASCADE,
    CONSTRAINT fk_autorizacao_unidade FOREIGN KEY (unidade_id) REFERENCES Unidade(id) ON DELETE CASCADE,
    CONSTRAINT fk_autorizacao_usuario FOREIGN KEY (usuario_autorizador_id) REFERENCES Usuario(id) ON DELETE SET NULL,
    CONSTRAINT chk_periodo_valido CHECK (data_fim > data_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Chaves virtuais e QR Codes temporários.';

-- TABELA 6: Registro_Acesso
CREATE TABLE Registro_Acesso (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT,
    visitante_id BIGINT,
    data_hora DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    direcao VARCHAR(10) NOT NULL CHECK (direcao IN ('Entrada', 'Saida')),
    metodo_leitura VARCHAR(30) NOT NULL CHECK (metodo_leitura IN ('Facial', 'QR_Code', 'Placa_LPR', 'Tag_RFID', 'Manual')),
    foto_evento_url TEXT,
    observacao TEXT,
    CONSTRAINT fk_registro_usuario FOREIGN KEY (usuario_id) REFERENCES Usuario(id) ON DELETE SET NULL,
    CONSTRAINT fk_registro_visitante FOREIGN KEY (visitante_id) REFERENCES Visitante(id) ON DELETE SET NULL,
    CONSTRAINT chk_registro_pessoa CHECK (usuario_id IS NOT NULL OR visitante_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Log de acessos da portaria.';


-- =============================================================================
-- 3. GESTÃO DE FACILITIES
-- =============================================================================

-- TABELA 7: Reserva
CREATE TABLE Reserva (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT NOT NULL,
    espaco_nome VARCHAR(100) NOT NULL,
    data_inicio DATETIME NOT NULL,
    data_fim DATETIME NOT NULL,
    status VARCHAR(20) DEFAULT 'Pendente' CHECK (status IN ('Pendente', 'Confirmada', 'Cancelada', 'Concluida')),
    valor_taxa DECIMAL(10,2) DEFAULT 0.00 NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_reserva_usuario FOREIGN KEY (usuario_id) REFERENCES Usuario(id) ON DELETE CASCADE,
    CONSTRAINT chk_reserva_periodo CHECK (data_fim > data_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Reservas de áreas comuns.';

-- TABELA 8: Encomenda
CREATE TABLE Encomenda (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    unidade_id BIGINT NOT NULL,
    usuario_recebedor_id BIGINT,
    descricao TEXT NOT NULL,
    foto_url TEXT,
    codigo_rastreio VARCHAR(50),
    data_recebimento DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    data_retirada DATETIME,
    status VARCHAR(20) DEFAULT 'Aguardando_Retirada' CHECK (status IN ('Aguardando_Retirada', 'Entregue', 'Devolvido')),
    CONSTRAINT fk_encomenda_unidade FOREIGN KEY (unidade_id) REFERENCES Unidade(id) ON DELETE CASCADE,
    CONSTRAINT fk_encomenda_usuario FOREIGN KEY (usuario_recebedor_id) REFERENCES Usuario(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Controle de recebimento e entrega de encomendas.';

-- TABELA 9: Ocorrencia
CREATE TABLE Ocorrencia (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    descricao TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'Aberta' CHECK (status IN ('Aberta', 'Em_Andamento', 'Resolvida', 'Cancelada')),
    foto_url TEXT,
    data_abertura DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    data_fechamento DATETIME,
    CONSTRAINT fk_ocorrencia_usuario FOREIGN KEY (usuario_id) REFERENCES Usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Chamados de moradores e manutenção.';


-- =============================================================================
-- 4. COMUNICAÇÃO E SOCIAL
-- =============================================================================

-- TABELA 10: Publicacao
CREATE TABLE Publicacao (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT NOT NULL,
    categoria VARCHAR(30) NOT NULL CHECK (categoria IN ('Aviso_Sindico', 'Feed_Social', 'Classificados', 'Achados_Perdidos', 'Enquete')),
    titulo VARCHAR(150) NOT NULL,
    conteudo TEXT NOT NULL,
    midia_url TEXT,
    preco DECIMAL(10,2),
    status VARCHAR(20) DEFAULT 'Ativo' CHECK (status IN ('Ativo', 'Encerrado', 'Arquivado', 'Removido')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_publicacao_usuario FOREIGN KEY (usuario_id) REFERENCES Usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Feed social, avisos, classificados e enquetes.';

-- TABELA 11: Interacao
CREATE TABLE Interacao (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    publicacao_id BIGINT NOT NULL,
    usuario_id BIGINT NOT NULL,
    tipo_interacao VARCHAR(20) NOT NULL CHECK (tipo_interacao IN ('Curtida', 'Comentario', 'Voto_Enquete')),
    conteudo_texto TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_interacao_publicacao FOREIGN KEY (publicacao_id) REFERENCES Publicacao(id) ON DELETE CASCADE,
    CONSTRAINT fk_interacao_usuario FOREIGN KEY (usuario_id) REFERENCES Usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Curtidas, comentários e votos de enquetes.';


-- =============================================================================
-- 5. FINANCEIRO E GOVERNANÇA
-- =============================================================================

-- TABELA 12: Cobranca
CREATE TABLE Cobranca (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    unidade_id BIGINT NOT NULL,
    mes_referencia VARCHAR(7) NOT NULL,
    descricao VARCHAR(150) DEFAULT 'Taxa Condominial Ordinária' NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    vencimento DATE NOT NULL,
    linha_digitavel VARCHAR(60),
    link_boleto TEXT,
    status VARCHAR(20) DEFAULT 'Pendente' CHECK (status IN ('Pendente', 'Pago', 'Vencido', 'Cancelado')),
    data_pagamento DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_cobranca_unidade FOREIGN KEY (unidade_id) REFERENCES Unidade(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Boletos e controle financeiro.';

-- TABELA 13: Documento
CREATE TABLE Documento (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_upload_id BIGINT,
    titulo VARCHAR(150) NOT NULL,
    categoria VARCHAR(50) NOT NULL CHECK (categoria IN ('Atas', 'Convenção', 'Regimento_Interno', 'Prestação_Contas', 'Contrato', 'Outros')),
    descricao TEXT,
    arquivo_url TEXT NOT NULL,
    data_upload DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_documento_usuario FOREIGN KEY (usuario_upload_id) REFERENCES Usuario(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Atas, convenções e relatórios em PDF.';


-- =============================================================================
-- ÍNDICES DE DESEMPENHO (PERFORMANCE INDEXES)
-- =============================================================================

CREATE INDEX idx_usuario_unidade ON Usuario(unidade_id);
CREATE INDEX idx_usuario_cpf ON Usuario(cpf);
CREATE INDEX idx_veiculo_placa ON Veiculo(placa);
CREATE INDEX idx_autorizacao_qr ON Autorizacao_Acesso(qr_code);
CREATE INDEX idx_registro_acesso_data ON Registro_Acesso(data_hora DESC);
CREATE INDEX idx_publicacao_categoria ON Publicacao(categoria);
CREATE INDEX idx_cobranca_unidade_status ON Cobranca(unidade_id, status);
CREATE INDEX idx_encomenda_unidade_status ON Encomenda(unidade_id, status);