-- =============================================
-- BANCO DE DADOS COMPLETO - SISTEMA DE PAGAMENTOS PIX
-- Execute este arquivo no phpMyAdmin
-- VERSÃO SINCRONIZADA COM LOVABLE (React) + SUPABASE
-- ARQUIVO ÚNICO - Inclui todas as tabelas do sistema
-- =============================================

-- =============================================
-- TABELAS PRINCIPAIS
-- =============================================

-- Tabela de usuários admin
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    nome VARCHAR(255) DEFAULT 'Admin',
    role VARCHAR(20) DEFAULT 'admin',
    created_at DATETIME DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de pagamentos (SINCRONIZADA COM SUPABASE)
CREATE TABLE IF NOT EXISTS pagamentos (
    codigo INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id VARCHAR(36) NOT NULL UNIQUE,
    identificador VARCHAR(255) NOT NULL,
    descricao TEXT,
    valor DECIMAL(10,2) NOT NULL,
    chave_pix VARCHAR(255) NOT NULL,
    nome_recebedor VARCHAR(255) DEFAULT 'ELEKTR0',
    cidade VARCHAR(255) DEFAULT 'ELEKTR0',
    txid VARCHAR(255) DEFAULT '',
    status VARCHAR(20) DEFAULT 'pendente',
    pix_copiado_at DATETIME NULL,
    pix_copiado_count INT DEFAULT 0,
    paid_at DATETIME NULL,
    visitas INT DEFAULT 0,
    ultimo_acesso DATETIME NULL,
    ip_cliente VARCHAR(45) NULL,
    -- PixUp Gateway fields
    pixup_qrcode TEXT,
    pixup_qrcode_image TEXT,
    pixup_txid VARCHAR(255),
    created_at DATETIME DEFAULT NOW(),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_codigo (codigo),
    INDEX idx_ip_cliente (ip_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de comprovantes (SINCRONIZADA COM SUPABASE)
CREATE TABLE IF NOT EXISTS comprovantes (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    pagamento_id VARCHAR(36) NULL,
    identificador TEXT NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    arquivo_url TEXT NOT NULL,
    arquivo_nome TEXT NOT NULL,
    created_at DATETIME DEFAULT NOW(),
    INDEX idx_identificador (identificador(255)),
    INDEX idx_pagamento (pagamento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de configurações (SINCRONIZADA COM SUPABASE)
CREATE TABLE IF NOT EXISTS configuracoes (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    chave VARCHAR(255) NOT NULL UNIQUE,
    valor TEXT,
    ativo TINYINT(1) DEFAULT 1,
    updated_at DATETIME DEFAULT NOW(),
    INDEX idx_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de visitas anônimas (tracking de visitantes na consulta)
CREATE TABLE IF NOT EXISTS visitas_anonimas (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL UNIQUE,
    ip_hash VARCHAR(255),
    user_agent TEXT,
    pagina VARCHAR(50) NOT NULL DEFAULT 'index',
    estado VARCHAR(10),
    etapa VARCHAR(50) DEFAULT 'estado',
    created_at DATETIME DEFAULT NOW(),
    ultimo_acesso DATETIME DEFAULT NOW(),
    INDEX idx_ultimo_acesso (ultimo_acesso),
    INDEX idx_session_id (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para contagem de cliques/visitas à página inicial
CREATE TABLE IF NOT EXISTS estatisticas_cliques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL DEFAULT 'visita_index',
    session_id VARCHAR(255),
    ip_hash VARCHAR(255),
    created_at DATETIME DEFAULT NOW(),
    INDEX idx_created_at (created_at),
    INDEX idx_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TABELAS DE ATENDIMENTO ONLINE (CHAT)
-- =============================================

-- Tabela de conversas de atendimento
CREATE TABLE IF NOT EXISTS atendimento_conversas (
    id VARCHAR(36) PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    telefone VARCHAR(20) NULL,
    cpf VARCHAR(18) NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'online',
    cliente_digitando TINYINT(1) DEFAULT 0,
    cliente_digitando_at DATETIME NULL,
    ultimo_acesso DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_ultimo_acesso (ultimo_acesso),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de mensagens do chat
CREATE TABLE IF NOT EXISTS atendimento_mensagens (
    id VARCHAR(36) PRIMARY KEY,
    conversa_id VARCHAR(36) NOT NULL,
    remetente VARCHAR(20) NOT NULL COMMENT 'cliente ou admin',
    mensagem TEXT NOT NULL,
    lida TINYINT(1) NOT NULL DEFAULT 0,
    arquivo_url TEXT NULL,
    arquivo_tipo VARCHAR(50) NULL,
    arquivo_nome VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conversa (conversa_id),
    INDEX idx_created (created_at),
    INDEX idx_remetente (remetente),
    FOREIGN KEY (conversa_id) REFERENCES atendimento_conversas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de IPs bloqueados (loading infinito)
CREATE TABLE IF NOT EXISTS ips_bloqueados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(255) NOT NULL UNIQUE,
    motivo VARCHAR(255) DEFAULT 'Bloqueado pelo admin',
    created_at DATETIME DEFAULT NOW(),
    INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de status da API (monitoramento)
CREATE TABLE IF NOT EXISTS api_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_name VARCHAR(100) NOT NULL DEFAULT 'consultar_faturas',
    status VARCHAR(20) NOT NULL DEFAULT 'online',
    last_error TEXT NULL,
    last_error_at DATETIME NULL,
    last_success_at DATETIME NULL,
    error_count INT DEFAULT 0,
    updated_at DATETIME DEFAULT NOW(),
    UNIQUE KEY unique_api (api_name),
    INDEX idx_status (status),
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir registro inicial para API de faturas
INSERT IGNORE INTO api_status (api_name, status, updated_at) VALUES ('consultar_faturas', 'online', NOW());

-- =============================================
-- CONFIGURAÇÕES PADRÃO
-- =============================================

-- Inserir configurações padrão (ignora se já existe)
INSERT IGNORE INTO configuracoes (id, chave, valor, ativo) VALUES 
(UUID(), 'whatsapp', '', 1),
(UUID(), 'pix_chave', '', 1),
(UUID(), 'pix_nome_recebedor', 'ELEKTR0', 1),
(UUID(), 'pix_cidade', 'ELEKTR0', 1),
(UUID(), 'pix_referencia', '', 1),
(UUID(), 'mensagem_link', '', 1),
(UUID(), 'atendimento_online', '', 1),
(UUID(), 'mensagem_automatica', 'Olá! Seja bem-vindo(a) ao Atendimento Neoenergia Elektro. Meu nome é Assistente Virtual e estou aqui para ajudá-lo(a). Em que posso ser útil hoje?', 1);

-- =============================================
-- MIGRAÇÕES PARA ATUALIZAR TABELAS EXISTENTES
-- Execute apenas se as tabelas já existem
-- =============================================

-- Se a tabela pagamentos já existe sem a coluna 'id', execute:
-- ALTER TABLE pagamentos ADD COLUMN id VARCHAR(36) NOT NULL AFTER codigo;
-- UPDATE pagamentos SET id = UUID() WHERE id IS NULL OR id = '';
-- ALTER TABLE pagamentos ADD UNIQUE (id);

-- Se a tabela pagamentos já existe sem pix_copiado_count, execute:
-- ALTER TABLE pagamentos ADD COLUMN pix_copiado_count INT DEFAULT 0 AFTER pix_copiado_at;

-- Se a tabela pagamentos já existe sem as colunas do PIXUP, execute:
-- ALTER TABLE pagamentos ADD COLUMN pixup_qrcode TEXT;
-- ALTER TABLE pagamentos ADD COLUMN pixup_qrcode_image TEXT;
-- ALTER TABLE pagamentos ADD COLUMN pixup_txid VARCHAR(255);

-- Se a tabela pagamentos já existe sem visitas e ultimo_acesso, execute:
-- ALTER TABLE pagamentos ADD COLUMN visitas INT DEFAULT 0;
-- ALTER TABLE pagamentos ADD COLUMN ultimo_acesso DATETIME NULL;

-- Se a tabela pagamentos já existe sem ip_cliente, execute:
-- ALTER TABLE pagamentos ADD COLUMN ip_cliente VARCHAR(45) NULL;
-- CREATE INDEX idx_ip_cliente ON pagamentos(ip_cliente);

-- Se a tabela atendimento_conversas já existe sem cliente_digitando, execute:
-- ALTER TABLE atendimento_conversas ADD COLUMN cliente_digitando TINYINT(1) DEFAULT 0;
-- ALTER TABLE atendimento_conversas ADD COLUMN cliente_digitando_at DATETIME NULL;

-- Se a tabela atendimento_mensagens já existe sem arquivo_*, execute:
-- ALTER TABLE atendimento_mensagens ADD COLUMN arquivo_url TEXT NULL;
-- ALTER TABLE atendimento_mensagens ADD COLUMN arquivo_tipo VARCHAR(50) NULL;
-- ALTER TABLE atendimento_mensagens ADD COLUMN arquivo_nome VARCHAR(255) NULL;

-- =============================================
-- NOTA: O primeiro admin será criado automaticamente
-- ao acessar /secret/ pela primeira vez
-- =============================================
