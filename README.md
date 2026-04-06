# Sistema de Pagamentos PIX - PHP + MySQL

## 📦 Estrutura de Arquivos

```
php-version/
├── secret/
│   ├── index.php       # Painel administrativo
│   ├── login.php       # Página de login
│   └── logout.php      # Logout
├── api/
│   ├── check_status.php      # Verifica status do pagamento
│   ├── pix_copiado.php       # Registra quando PIX foi copiado
│   └── upload_comprovante.php # Upload de comprovantes
├── assets/
│   ├── logo.png        # Logo (coloque sua logo aqui)
│   └── style.css       # Estilos CSS
├── uploads/
│   └── comprovantes/   # Pasta onde ficam os comprovantes (criada automaticamente)
├── config.php          # Configurações do banco de dados
├── database.sql        # Script SQL para criar as tabelas
├── index.php           # Página de loading infinito (entrada)
├── pagamento.php       # Página de checkout (pagamento PIX)
├── pix.php             # Gerador de código PIX
└── README.md           # Este arquivo
```

## 🚀 Instalação

### 1. Criar o Banco de Dados

1. Acesse o **phpMyAdmin** da sua hospedagem
2. Crie um banco de dados chamado `sistema_pagamentos`
3. Importe o arquivo `database.sql` ou execute o SQL manualmente

### 2. Configurar a Conexão

Edite o arquivo `config.php` e altere as configurações:

```php
define('DB_HOST', 'localhost');     // Host do MySQL
define('DB_NAME', 'sistema_pagamentos'); // Nome do banco
define('DB_USER', 'seu_usuario');   // Usuário do MySQL
define('DB_PASS', 'sua_senha');     // Senha do MySQL
```

### 3. Upload dos Arquivos

1. Faça upload de TODA a pasta `php-version` para sua hospedagem
2. Renomeie a pasta para o nome que preferir (ex: `pagamentos`)
3. Certifique-se de que a pasta `uploads/comprovantes` tenha permissão de escrita (chmod 755)

### 4. Adicionar a Logo

Substitua o arquivo `assets/logo.png` pela logo da sua empresa.

### 5. Acessar o Sistema

- **Página de Loading**: `seusite.com/` (mostra loading infinito)
- **Página de Pagamento**: `seusite.com/pagamento.php?p=CODIGO`
- **Painel Admin**: `seusite.com/secret/`

## 🔐 Login Padrão

- **Email**: admin@admin.com
- **Senha**: admin123

⚠️ **IMPORTANTE**: Altere a senha após o primeiro login!

Para alterar a senha, execute este SQL no phpMyAdmin:

```sql
UPDATE usuarios SET senha = '$2y$10$SEU_HASH_AQUI' WHERE email = 'admin@admin.com';
```

Gere o hash usando: `password_hash('sua_nova_senha', PASSWORD_DEFAULT)`

## 📱 Funcionalidades

### Painel Admin
- ✅ Criar novos pagamentos PIX
- ✅ Listar todos os pagamentos
- ✅ Marcar pagamentos como "Pago"
- ✅ Ver comprovantes enviados pelos clientes
- ✅ Configurar número do WhatsApp
- ✅ Copiar links de pagamento

### Página de Checkout
- ✅ Exibe QR Code PIX
- ✅ Botão para copiar código PIX
- ✅ Upload de comprovante
- ✅ Atualização automática quando pago (polling a cada 5 segundos)
- ✅ Botão flutuante do WhatsApp

## 🔧 Configurações Adicionais

### Hostinger
No painel da Hostinger, vá em:
1. **Banco de Dados** → Criar novo banco MySQL
2. Copie as credenciais e coloque no `config.php`

### Outras Hospedagens
O processo é similar - crie o banco, importe o SQL, configure as credenciais.

## ⚠️ Segurança

1. **Altere a senha padrão** imediatamente
2. Proteja a pasta `secret/` com `.htaccess` se necessário
3. Mantenha o PHP atualizado
4. Use HTTPS (SSL) sempre que possível

## 🆘 Suporte

Se tiver problemas:
1. Verifique se as credenciais do banco estão corretas
2. Verifique se o PHP está na versão 7.4 ou superior
3. Verifique as permissões das pastas
