# Instruções de Atualização - Versão PHP

## PASSO 1: Atualizar o Banco de Dados (phpMyAdmin)

Execute estes comandos SQL no phpMyAdmin:

```sql
-- =============================================
-- ATUALIZAÇÃO DO BANCO DE DADOS
-- Execute no phpMyAdmin
-- =============================================

-- 1. Adicionar colunas de rastreamento na tabela pagamentos
ALTER TABLE pagamentos ADD COLUMN IF NOT EXISTS visitas INT DEFAULT 0;
ALTER TABLE pagamentos ADD COLUMN IF NOT EXISTS ultimo_acesso DATETIME NULL;

-- 2. Adicionar colunas do chat de atendimento
ALTER TABLE atendimento_conversas ADD COLUMN IF NOT EXISTS cliente_digitando TINYINT(1) DEFAULT 0;
ALTER TABLE atendimento_conversas ADD COLUMN IF NOT EXISTS cliente_digitando_at DATETIME NULL;

ALTER TABLE atendimento_mensagens ADD COLUMN IF NOT EXISTS arquivo_url TEXT NULL;
ALTER TABLE atendimento_mensagens ADD COLUMN IF NOT EXISTS arquivo_tipo VARCHAR(50) NULL;
ALTER TABLE atendimento_mensagens ADD COLUMN IF NOT EXISTS arquivo_nome VARCHAR(255) NULL;

-- 3. Adicionar configurações (ignorar se já existir)
INSERT IGNORE INTO configuracoes (id, chave, valor, ativo) VALUES 
(UUID(), 'chave_pix_padrao', '', 1),
(UUID(), 'tipo_chave_pix', 'cpf', 1),
(UUID(), 'mensagem_automatica', 'Olá! Seja bem-vindo(a) ao Atendimento Neoenergia Elektro. Meu nome é Assistente Virtual e estou aqui para ajudá-lo(a). Em que posso ser útil hoje?', 1);
```

**Se der erro no "IF NOT EXISTS" (versões antigas do MySQL):**
```sql
-- Primeiro verifique se as colunas existem:
SHOW COLUMNS FROM pagamentos LIKE 'visitas';
SHOW COLUMNS FROM pagamentos LIKE 'ultimo_acesso';
SHOW COLUMNS FROM atendimento_conversas LIKE 'cliente_digitando';
SHOW COLUMNS FROM atendimento_mensagens LIKE 'arquivo_url';

-- Se não existirem, execute cada linha separadamente:
ALTER TABLE pagamentos ADD COLUMN visitas INT DEFAULT 0;
ALTER TABLE pagamentos ADD COLUMN ultimo_acesso DATETIME NULL;
ALTER TABLE atendimento_conversas ADD COLUMN cliente_digitando TINYINT(1) DEFAULT 0;
ALTER TABLE atendimento_conversas ADD COLUMN cliente_digitando_at DATETIME NULL;
ALTER TABLE atendimento_mensagens ADD COLUMN arquivo_url TEXT NULL;
ALTER TABLE atendimento_mensagens ADD COLUMN arquivo_tipo VARCHAR(50) NULL;
ALTER TABLE atendimento_mensagens ADD COLUMN arquivo_nome VARCHAR(255) NULL;
```

---

## PASSO 2: Arquivos a Substituir/Adicionar

### ARQUIVOS NOVOS (criar):

1. **`api/registrar_visita.php`** - Já criado no projeto

### ARQUIVOS A MODIFICAR:

1. **`pagamento.php`** - Adicionar registro de visita no início
2. **`secret/index.php`** - Adicionar aba "Chave PIX Padrão" e exibição de visitas/online

---

## PASSO 3: Modificar `pagamento.php`

No início do arquivo, APÓS a linha onde busca o pagamento e ANTES de gerar o PIX, adicione:

```php
// Após esta parte existente:
// $stmt->execute([$codigo]);
// $pagamento = $stmt->fetch();

// ADICIONAR ESTA PARTE (registrar visita):
if ($pagamento) {
    $stmtVisita = $pdo->prepare("UPDATE pagamentos SET visitas = COALESCE(visitas, 0) + 1, ultimo_acesso = NOW() WHERE codigo = ?");
    $stmtVisita->execute([$codigo]);
}
```

---

## PASSO 4: Modificar `secret/index.php`

### 4.1 - No início, após buscar as configurações existentes, adicione:

```php
// Buscar configuração da Chave PIX padrão
$stmtChavePix = $pdo->prepare("SELECT * FROM configuracoes WHERE chave = 'chave_pix_padrao'");
$stmtChavePix->execute();
$chavePixConfig = $stmtChavePix->fetch();

$stmtTipoChave = $pdo->prepare("SELECT * FROM configuracoes WHERE chave = 'tipo_chave_pix'");
$stmtTipoChave->execute();
$tipoChaveConfig = $stmtTipoChave->fetch();
```

### 4.2 - Adicionar ação para salvar chave PIX no switch de ações:

```php
case 'salvar_chave_pix':
    $chavePix = sanitize($_POST['chave_pix_padrao']);
    $tipoChave = sanitize($_POST['tipo_chave']);
    
    // Salvar chave
    $stmt = $pdo->prepare("
        INSERT INTO configuracoes (id, chave, valor, ativo) 
        VALUES (?, 'chave_pix_padrao', ?, 1)
        ON DUPLICATE KEY UPDATE valor = ?
    ");
    $stmt->execute([generateUUID(), $chavePix, $chavePix]);
    
    // Salvar tipo
    $stmt = $pdo->prepare("
        INSERT INTO configuracoes (id, chave, valor, ativo) 
        VALUES (?, 'tipo_chave_pix', ?, 1)
        ON DUPLICATE KEY UPDATE valor = ?
    ");
    $stmt->execute([generateUUID(), $tipoChave, $tipoChave]);
    
    // Atualizar todos os pagamentos pendentes
    if (!empty($chavePix)) {
        $stmt = $pdo->prepare("UPDATE pagamentos SET chave_pix = ? WHERE status = 'pendente'");
        $stmt->execute([$chavePix]);
    }
    
    $message = 'Chave PIX salva! Todos os pagamentos pendentes foram atualizados.';
    $messageType = 'success';
    break;
```

### 4.3 - Adicionar nova aba no menu da sidebar (após "Comprovantes"):

```html
<a href="?tab=chave_pix" class="nav-btn <?= $tab === 'chave_pix' ? 'active' : '' ?>">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
    <span>Chave PIX Padrão</span>
</a>
```

### 4.4 - Adicionar CSS para badges de visitas/online (no <style>):

```css
/* Badges de Visitas e Online */
.visitas-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    background: rgba(147, 51, 234, 0.1);
    color: hsl(270, 80%, 55%);
    border: 1px solid rgba(147, 51, 234, 0.3);
}

.visitas-badge svg {
    width: 12px;
    height: 12px;
}

.online-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    background: rgba(34, 197, 94, 0.1);
    color: hsl(142, 71%, 45%);
    border: 1px solid rgba(34, 197, 94, 0.3);
    animation: pulse 2s infinite;
}

.online-badge svg {
    width: 12px;
    height: 12px;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}
```

### 4.5 - Na listagem de pagamentos, adicionar badges de visitas/online:

No loop de pagamentos, após o badge "PIX Copiado", adicione:

```php
<?php 
$visitas = intval($pag['visitas'] ?? 0);
$ultimoAcesso = $pag['ultimo_acesso'] ?? null;
$isOnline = false;
if ($ultimoAcesso) {
    $ultimoAcessoTime = strtotime($ultimoAcesso);
    $isOnline = (time() - $ultimoAcessoTime) < 120; // 2 minutos
}
?>

<?php if ($visitas > 0): ?>
    <span class="visitas-badge">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <?= $visitas ?> visita<?= $visitas > 1 ? 's' : '' ?>
    </span>
<?php endif; ?>

<?php if ($isOnline): ?>
    <span class="online-badge">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
        Online
    </span>
<?php endif; ?>
```

E na linha de data, adicione:

```php
<?php if ($ultimoAcesso): ?>
    <span style="margin-left: 8px;">• Último acesso: <?= formatDateTimeBR($ultimoAcesso) ?></span>
<?php endif; ?>
```

### 4.6 - Adicionar conteúdo da aba Chave PIX Padrão:

Antes do `<?php endif; ?>` final das tabs, adicione:

```php
<?php elseif ($tab === 'chave_pix'): ?>
    <!-- Chave PIX Padrão -->
    <div class="card" style="max-width: 576px; margin: 0 auto;">
        <div class="card-header">
            <h2 class="card-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                Chave PIX Padrão
            </h2>
            <p class="card-description">Configure a chave PIX que será usada em todos os novos pagamentos</p>
        </div>
        <div class="card-content">
            <form method="POST">
                <input type="hidden" name="action" value="salvar_chave_pix">
                <input type="hidden" name="tipo_chave" id="tipoChavePadrao" value="<?= htmlspecialchars($tipoChaveConfig['valor'] ?? 'cpf') ?>">
                
                <div class="form-group">
                    <label class="form-label">Tipo de Chave PIX</label>
                    <div class="btn-grid">
                        <button type="button" class="btn-type <?= ($tipoChaveConfig['valor'] ?? 'cpf') === 'cpf' ? 'active' : '' ?>" onclick="setTipoChavePadrao('cpf', this)">CPF</button>
                        <button type="button" class="btn-type <?= ($tipoChaveConfig['valor'] ?? '') === 'cnpj' ? 'active' : '' ?>" onclick="setTipoChavePadrao('cnpj', this)">CNPJ</button>
                        <button type="button" class="btn-type <?= ($tipoChaveConfig['valor'] ?? '') === 'email' ? 'active' : '' ?>" onclick="setTipoChavePadrao('email', this)">E-mail</button>
                        <button type="button" class="btn-type <?= ($tipoChaveConfig['valor'] ?? '') === 'celular' ? 'active' : '' ?>" onclick="setTipoChavePadrao('celular', this)">Celular</button>
                        <button type="button" class="btn-type <?= ($tipoChaveConfig['valor'] ?? '') === 'aleatoria' ? 'active' : '' ?>" onclick="setTipoChavePadrao('aleatoria', this)">Aleatória</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="chave_pix_padrao">Chave PIX</label>
                    <input type="text" name="chave_pix_padrao" id="chave_pix_padrao" class="form-input" value="<?= htmlspecialchars($chavePixConfig['valor'] ?? '') ?>" placeholder="Digite sua chave PIX">
                    <p class="form-hint">Esta chave será usada automaticamente em novos pagamentos e ao salvar, todos os pagamentos pendentes serão atualizados.</p>
                </div>
                
                <button type="submit" class="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Salvar Chave PIX
                </button>
            </form>
        </div>
    </div>
    
    <script>
        function setTipoChavePadrao(tipo, btn) {
            document.getElementById('tipoChavePadrao').value = tipo;
            document.querySelectorAll('.btn-type').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }
    </script>
```

### 4.7 - Usar chave PIX padrão no formulário de novo pagamento:

No formulário de criar pagamento, altere o input da chave PIX para:

```html
<input type="text" name="chave_pix" id="chave_pix" class="form-input" 
    value="<?= htmlspecialchars($chavePixConfig['valor'] ?? '') ?>" 
    placeholder="000.000.000-00" required>
```

---

## Resumo dos Arquivos

| Arquivo | Ação |
|---------|------|
| `api/registrar_visita.php` | CRIAR (já está no projeto) |
| `pagamento.php` | MODIFICAR (adicionar registro de visita) |
| `secret/index.php` | MODIFICAR (adicionar aba Chave PIX, badges visitas/online) |
| `database.sql` ou phpMyAdmin | EXECUTAR comandos SQL |

---

## Testando

1. Acesse `/secret/?tab=chave_pix` e salve uma chave PIX
2. Crie um novo pagamento - deve vir com a chave preenchida
3. Acesse a página de pagamento pelo link público
4. No painel, veja o contador de visitas e o status "Online"
