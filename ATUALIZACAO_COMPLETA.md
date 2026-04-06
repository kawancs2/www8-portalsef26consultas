# 📦 Atualização Completa - PHP igual ao Lovable

## Arquivos que você precisa atualizar no seu hosting:

### 📁 Estrutura de Pastas:
```
php-version/
├── api/
│   ├── heartbeat.php          ✅ NOVO - Criar este arquivo
│   ├── check_status.php
│   ├── pix_copiado.php
│   ├── registrar_visita.php
│   └── upload_comprovante.php
├── secret/
│   └── index.php              ✅ ATUALIZAR
├── sounds/                     ✅ NOVA PASTA
│   ├── notification.mp3       ✅ NOVO - Som PIX Copiado
│   └── comprovante.mp3        ✅ NOVO - Som Comprovante
├── pagamento.php              ✅ ATUALIZAR
└── ... (outros arquivos)
```

---

## 🔊 Sons de Notificação

Os arquivos de som estão em `php-version/sounds/`:
- `notification.mp3` - Toca quando alguém copia o PIX
- `comprovante.mp3` - Toca quando alguém envia um comprovante

**Copie a pasta `sounds` inteira para seu servidor!**

---

## 🎯 Funcionalidades Atualizadas

1. ✅ **Status Online** - Mostra quem está na página de pagamento (últimos 30 segundos)
2. ✅ **Toggle de Sons** - Botões na sidebar para ligar/desligar cada som separadamente
3. ✅ **Toggle de Tema** - Modo Claro/Escuro
4. ✅ **Tipo de Chave PIX Fixo** - Mantém o tipo salvo ao recarregar
5. ✅ **Período Padrão "Hoje"** - Filtro começa em "Hoje" ao invés de "Todo Período"
6. ✅ **Último Acesso** - Mostra quando foi o último acesso de cada pagamento
7. ✅ **Heartbeat a cada 10s** - Atualiza presença sem incrementar visitas
8. ✅ **Auto-refresh a cada 5s** - Lista de pagamentos atualiza automaticamente

---

## ⚙️ Alterações no Banco de Dados (MySQL)

Execute no phpMyAdmin:

```sql
-- Adicionar colunas se não existirem
ALTER TABLE pagamentos ADD COLUMN IF NOT EXISTS ultimo_acesso DATETIME NULL;
ALTER TABLE pagamentos ADD COLUMN IF NOT EXISTS visitas INT DEFAULT 0;
ALTER TABLE pagamentos ADD COLUMN IF NOT EXISTS pix_copiado_at DATETIME NULL;
ALTER TABLE pagamentos ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL;
```

---

## 📋 Lista de Arquivos para Copiar

| Arquivo | Ação |
|---------|------|
| `secret/index.php` | SUBSTITUIR |
| `api/heartbeat.php` | CRIAR |
| `pagamento.php` | SUBSTITUIR |
| `sounds/notification.mp3` | CRIAR |
| `sounds/comprovante.mp3` | CRIAR |

---

## 🧪 Como Testar

1. Acesse o painel admin (`/secret/`)
2. Verifique se os botões de som aparecem na sidebar
3. Abra uma página de pagamento em outra aba
4. Verifique se aparece "Online" no painel
5. Copie o PIX e verifique se o som toca (se estiver ativado)
6. Envie um comprovante e verifique se o outro som toca

---

**Data da última atualização:** 13/12/2025
