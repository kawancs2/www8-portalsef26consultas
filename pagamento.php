<?php
require_once 'config.php';
require_once 'pix.php';
require_once 'api/pixup.php';

// =============================================
// REGRAS DE ACESSO (público)
// - IP bloqueado -> Google
// - Desktop -> Google (site PHP é só celular)
// =============================================
enforceIpNotBlocked();
enforceMobileOnly();

addSecurityHeaders();

// Detectar se é um bot/crawler (WhatsApp, Facebook, etc.) - IDÊNTICO à versão Lovable
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$isBot = preg_match('/whatsapp|facebookexternalhit|facebot|twitterbot|telegrambot|slackbot|linkedinbot|discordbot|bot|crawler|spider|scraper|prerender/i', $userAgent);

// Obter código do pagamento (aceita ?p= ou ?codigo=)
$codigo = isset($_GET['p']) ? intval($_GET['p']) : (isset($_GET['codigo']) ? intval($_GET['codigo']) : 0);

$pixCode = '';
$pixupQrImage = '';
$usePixUp = false;
$pixupPendingGeneration = false; // PIX ainda não foi gerado via PixUp

if ($codigo <= 0) {
    $error = "Link de pagamento inválido.";
} else {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM pagamentos WHERE codigo = ?");
    $stmt->execute([$codigo]);
    $pagamento = $stmt->fetch();
    
    if (!$pagamento) {
        $error = "Pagamento não encontrado.";
    } else {
        // Registrar visita APENAS se não for bot
        // IMPORTANTE: NÃO atualizar ultimo_acesso aqui.
        // ultimo_acesso só deve ser marcado quando o cliente abrir o MODAL do QR Code.
        if (!$isBot) {
            $stmtVisita = $pdo->prepare("UPDATE pagamentos SET visitas = COALESCE(visitas, 0) + 1 WHERE codigo = ?");
            $stmtVisita->execute([$codigo]);
        }
        
        // Check if PixUp gateway is enabled
        if (isPixUpEnabled()) {
            $usePixUp = true;
            
            // Check if we already have a PixUp QR code for this payment
            if (!empty($pagamento['pixup_qrcode']) && !empty($pagamento['pixup_qrcode_image'])) {
                // PIX já foi gerado, usar o existente
                $pixCode = $pagamento['pixup_qrcode'];
                $pixupQrImage = $pagamento['pixup_qrcode_image'];
            } else {
                // PIX ainda não foi gerado - mostrar botão "Gerar PIX"
                // NÃO gerar automaticamente para evitar custos desnecessários
                $pixupPendingGeneration = true;
            }
        } else {
            // Use manual PIX (gera automaticamente pois não tem custo)
            // Importante: sempre priorizar a chave atual configurada no painel (chave_pix_padrao)
            $pixChaveAtual = '';
            $pixNomeAtual = '';
            $pixCidadeAtual = '';
            $pixTxidAtual = '';

            // 1) Prioridade máxima: chave_pix_padrao (JSON)
            $stmtPixPadrao = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'chave_pix_padrao' AND ativo = 1 LIMIT 1");
            $stmtPixPadrao->execute();
            $pixPadraoRow = $stmtPixPadrao->fetch();
            if ($pixPadraoRow && !empty($pixPadraoRow['valor'])) {
                $pixPadrao = json_decode($pixPadraoRow['valor'], true);
                if (is_array($pixPadrao)) {
                    $pixChaveAtual = trim((string)($pixPadrao['chave_pix'] ?? ''));
                    $pixNomeAtual = trim((string)($pixPadrao['nome_recebedor'] ?? ''));
                    $pixCidadeAtual = trim((string)($pixPadrao['cidade'] ?? ''));
                    $pixTxidAtual = trim((string)($pixPadrao['txid'] ?? ''));
                }
            }

            // 2) Fallback: chaves antigas separadas
            $stmtPix = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ('pix_chave', 'pix_nome_recebedor', 'pix_cidade', 'pix_referencia')");
            $stmtPix->execute();
            $pixConfigs = $stmtPix->fetchAll(PDO::FETCH_KEY_PAIR);

            if (empty($pixChaveAtual) && !empty($pixConfigs['pix_chave'])) $pixChaveAtual = $pixConfigs['pix_chave'];
            if (empty($pixNomeAtual) && !empty($pixConfigs['pix_nome_recebedor'])) $pixNomeAtual = $pixConfigs['pix_nome_recebedor'];
            if (empty($pixCidadeAtual) && !empty($pixConfigs['pix_cidade'])) $pixCidadeAtual = $pixConfigs['pix_cidade'];
            if (empty($pixTxidAtual) && !empty($pixConfigs['pix_referencia'])) $pixTxidAtual = $pixConfigs['pix_referencia'];

            $pixCode = generatePixCode(
                $pixChaveAtual ?: $pagamento['chave_pix'],
                $pagamento['valor'],
                $pixNomeAtual ?: $pagamento['nome_recebedor'],
                $pixCidadeAtual ?: $pagamento['cidade'],
                $pixTxidAtual ?: $pagamento['txid']
            );
        }
    }
}

// Buscar configuração do WhatsApp
$whatsapp = '';
if (isset($pdo)) {
    $stmtWpp = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'whatsapp' AND ativo = 1");
    $stmtWpp->execute();
    $wppConfig = $stmtWpp->fetch();
    if ($wppConfig && !empty($wppConfig['valor'])) {
        $whatsapp = $wppConfig['valor'];
    }
}

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
?>
<?php
// Construir URL base completa para Open Graph
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// URL fixa para a imagem OG (ajuste conforme seu domínio)
$ogImage = $protocol . '://' . $host . '/neo/assets/og-image.png';
$ogTitle = 'Neoenergia - Agência Virtual';
$ogDescription = '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $ogTitle ?></title>
    <meta name="description" content="<?= $ogDescription ?>">
    
    <!-- Open Graph / WhatsApp / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $ogTitle ?>">
    <meta property="og:description" content="<?= $ogDescription ?>">
    <meta property="og:image" content="<?= $ogImage ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="<?= $protocol . '://' . $host . $_SERVER['REQUEST_URI'] ?>">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $ogTitle ?>">
    <meta name="twitter:description" content="<?= $ogDescription ?>">
    <meta name="twitter:image" content="<?= $ogImage ?>">
    
    <link rel="icon" href="<?= $basePath ?>assets/favicon.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $basePath ?>assets/lovable-base.css">
    <link rel="stylesheet" href="<?= $basePath ?>assets/shared-buttons.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <style>
        :root {
            --primary: hsl(145, 100%, 32%);
            --primary-light: hsl(145, 100%, 38%);
            --accent: #f7941d;
            --background: hsl(0, 0%, 96%);
            --card-bg: hsl(0, 0%, 100%);
            --text: hsl(0, 0%, 20%);
            --text-muted: hsl(0, 0%, 45%);
            --border: hsl(0, 0%, 90%);
            --success: #22c55e;
            --error: #ef4444;
            --neo-green: #00A443;
            --neo-green-hex: #00A443;
        }
        
        body {
            font-family: 'Roboto', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: var(--background);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        
        /* Header */
        .header {
            background: white;
            padding: 24px 0;
        }
        
        .header .container {
            max-width: 512px;
            margin: 0 auto;
            padding: 0 16px;
            display: flex;
            justify-content: center;
        }
        
.logo {
            height: 48px;
            object-fit: contain;
        }
        
        /* Title Bar */
        .title-bar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            padding: 16px 0;
        }
        
        .title-bar h1 {
            color: white;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
        }
        
        /* Container */
        .container {
            max-width: 512px;
            margin: 0 auto;
            padding: 0 16px;
        }
        
        /* Main */
        main {
            padding: 32px 0;
        }
        
        .content-wrapper {
            display: flex;
            flex-direction: column;
            gap: 24px;
            animation: fadeInUp 0.5s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .subtitle {
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
        }
        
        /* Card */
        .card {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        /* Payment Header */
        .payment-header {
            padding: 32px 24px;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }
        
        .payment-header .label {
            display: block;
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }
        
        .payment-header .value {
            font-size: 48px;
            font-weight: 700;
            color: var(--primary);
        }
        
        /* Payment Content */
        .payment-content {
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        /* Description Box */
        .description-box {
            background: rgba(247, 148, 29, 0.1);
            border: 1px solid rgba(247, 148, 29, 0.2);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }
        
        .description-box p {
            color: var(--text);
            font-weight: 500;
            white-space: pre-line;
        }
        
        /* QR Section */
        .qr-section {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .qr-section p {
            font-size: 14px;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 16px;
        }
        
        .qr-box {
            background: white;
            padding: 16px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }
        
        .qr-box img {
            display: block;
        }
        
        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        
        .divider span {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Button */
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 16px 24px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
            box-shadow: 0 4px 12px rgba(247, 148, 29, 0.3);
        }
        
        .btn-primary:hover {
            background: #e8850a;
            transform: translateY(-1px);
        }
        
        .btn-primary.copied {
            background: var(--success);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px dashed rgba(0, 104, 56, 0.3);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            border-color: var(--primary);
            background: rgba(0, 104, 56, 0.05);
        }
        
        /* Info Box */
        .info-box {
            background: var(--background);
            border-radius: 12px;
            padding: 16px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }
        
        .info-row span {
            color: var(--text-muted);
        }
        
        .info-row strong {
            color: var(--text);
        }
        
        /* Help Section */
        .help-section {
            background: var(--background);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .help-trigger {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .help-trigger:hover {
            background: rgba(0, 0, 0, 0.05);
        }
        
        .help-trigger-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .help-trigger-left svg {
            width: 20px;
            height: 20px;
            color: var(--primary);
        }
        
        .help-trigger-left span {
            font-weight: 500;
            color: var(--text);
        }
        
        .help-trigger-right svg {
            width: 20px;
            height: 20px;
            color: var(--text-muted);
            transition: transform 0.2s;
        }
        
        .help-content {
            display: none;
            padding: 0 16px 16px;
        }
        
        .help-content.open {
            display: block;
        }
        
        .help-steps {
            background: rgba(0, 0, 0, 0.03);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .step {
            display: flex;
            gap: 12px;
        }
        
        .step-number {
            width: 24px;
            height: 24px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
        }
        
        .step p {
            font-size: 14px;
            color: var(--text);
        }
        
        .step strong {
            font-weight: 600;
        }
        
        /* Upload Card */
        .upload-card {
            padding: 24px;
            text-align: center;
        }
        
        .upload-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        
        .upload-header svg {
            width: 20px;
            height: 20px;
            color: var(--primary);
        }
        
        .upload-header span {
            font-weight: 500;
            color: var(--text);
        }
        
        .upload-card > p {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }
        
        .upload-success {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            background: rgba(0, 104, 56, 0.1);
            border-radius: 12px;
            color: var(--primary);
            font-weight: 500;
        }
        
        .upload-success svg {
            width: 20px;
            height: 20px;
        }
        
        /* Security Footer */
        .security-footer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 12px;
            padding: 24px 0;
        }
        
        .security-footer svg {
            width: 16px;
            height: 16px;
        }
        
        /* Success/Error Cards */
        .status-card {
            padding: 48px 24px;
            text-align: center;
        }
        
        .status-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        
        .status-icon.success {
            background: rgba(0, 104, 56, 0.1);
            color: var(--primary);
        }
        
        .status-icon.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
        }
        
        .status-icon svg {
            width: 48px;
            height: 48px;
        }
        
        .status-card h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }
        
        .status-card p {
            color: var(--text-muted);
        }
        
        /* WhatsApp Button */
        .whatsapp-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            background: #25D366;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.4);
            transition: transform 0.2s;
            z-index: 1000;
            text-decoration: none;
        }
        
        .whatsapp-btn:hover {
            transform: scale(1.1);
        }
        
        /* Loading */
        .loading-container {
            min-height: 60vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        
        .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 480px) {
            .logo {
                height: 48px;
            }
            
            .payment-header .value {
                font-size: 36px;
            }
        }
    </style>
    <?php include __DIR__ . '/includes/security.php'; ?>
</head>
<body>
    <!-- Overlay de bloqueio de IP -->
    <div id="bloqueioOverlay" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(255,255,255,0.95);z-index:999999;align-items:center;justify-content:center;flex-direction:column;">
        <div style="width:80px;height:80px;border:5px solid #e0e0e0;border-top-color:#006838;border-radius:50%;animation:spin 1s linear infinite;"></div>
        <p style="color:#6B6560;font-size:14px;margin-top:16px;">Carregando...</p>
    </div>
    <script>
    (function() {
        fetch('<?= $basePath ?>api/verificar_bloqueio.php?_t=' + Date.now())
            .then(r => r.json())
            .then(data => { if (data.bloqueado) document.getElementById('bloqueioOverlay').style.display = 'flex'; })
            .catch(() => {});
    })();
    </script>
    
    <!-- Header -->
    <header class="header">
        <div class="container">
            <img src="<?= $basePath ?>assets/logo-neoenergia-header.svg" alt="Neoenergia Elektro" class="logo">
        </div>
    </header>

    <!-- Title Bar -->
    <div class="title-bar">
        <h1>2ª Via de Pagamento</h1>
    </div>

    <main>
        <div class="container">
            <?php if (isset($error)): ?>
                <!-- Erro -->
                <div class="content-wrapper">
                    <div class="card">
                        <div class="status-card">
                            <div class="status-icon error">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </div>
                            <h2>Pagamento não encontrado</h2>
                            <p>Este link de pagamento não existe ou foi removido.</p>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($pagamento['status'] === 'pago'): ?>
                <!-- Pagamento Confirmado -->
                <div class="content-wrapper">
                    <div class="card">
                        <div class="status-card">
                            <div class="status-icon success">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg>
                            </div>
                            <h2>Pagamento Confirmado!</h2>
                            <p>Este pagamento já foi recebido e confirmado.</p>
                        </div>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Card de Pagamento -->
                <div class="content-wrapper">
                    <p class="subtitle">Visualize o código de pagamento da sua fatura em aberto de forma rápida e segura.</p>
                    
                    <div class="card">
                        <!-- Valor -->
                        <div class="payment-header">
                            <span class="label">Valor Total</span>
                            <span class="value"><?= formatCurrency($pagamento['valor']) ?></span>
                        </div>

                        <div class="payment-content">
                            <?php if (!empty($pagamento['descricao'])): ?>
                                <div class="description-box">
                                    <p><?= nl2br(sanitize($pagamento['descricao'])) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ($pixupPendingGeneration): ?>
                            <!-- PIX ainda não gerado - Botão para Gerar -->
                            <div id="pixPendingSection" class="qr-section">
                                <div style="padding: 40px 20px; text-align: center;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 16px; opacity: 0.7;">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                        <rect x="7" y="7" width="3" height="3"/>
                                        <rect x="14" y="7" width="3" height="3"/>
                                        <rect x="7" y="14" width="3" height="3"/>
                                        <rect x="14" y="14" width="3" height="3"/>
                                    </svg>
                                    <p style="color: var(--text-muted); margin-bottom: 20px;">Clique no botão abaixo para gerar seu código PIX</p>
                                    <button id="gerarPixBtn" class="btn btn-primary" onclick="gerarPixPixUp()" style="max-width: 280px; margin: 0 auto;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        <span id="gerarPixBtnText">Gerar Código PIX</span>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Seção que será exibida após gerar o PIX -->
                            <div id="pixGeneratedSection" style="display: none;">
                                <div class="qr-section">
                                    <p>Escaneie o QR Code para pagar</p>
                                    <div id="qrcode" class="qr-box"></div>
                                </div>

                                <div class="divider">
                                    <span>ou copie o código</span>
                                </div>

                                <button id="copyBtn" class="btn btn-primary" onclick="copyPix()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    <span id="copyBtnText">Copiar código PIX</span>
                                </button>
                            </div>
                            
                            <?php else: ?>
                            <!-- QR Code (PIX já gerado ou manual) -->
                            <div class="qr-section">
                                <p>Escaneie o QR Code para pagar</p>
                                <div id="qrcode" class="qr-box"></div>
                            </div>

                            <!-- Divider -->
                            <div class="divider">
                                <span>ou copie o código</span>
                            </div>

                            <!-- Botão Copiar -->
                            <button id="copyBtn" class="btn btn-primary" onclick="copyPix()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <span id="copyBtnText">Copiar código PIX</span>
                            </button>
                            <?php endif; ?>

                            <!-- Informações -->
                            <div class="info-box">
                                <div class="info-row">
                                    <span>Recebedor</span>
                                    <strong><?= sanitize($pagamento['nome_recebedor']) ?></strong>
                                </div>
                                <div class="info-row">
                                    <span>Forma de pagamento</span>
                                    <strong>PIX</strong>
                                </div>
                            </div>

                            <!-- Ajuda -->
                            <div class="help-section">
                                <button class="help-trigger" onclick="toggleHelp()">
                                    <div class="help-trigger-left">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                        <span>Como pagar com PIX Copia e Cola?</span>
                                    </div>
                                    <div class="help-trigger-right">
                                        <svg id="helpIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6,9 12,15 18,9"/></svg>
                                    </div>
                                </button>
                                <div id="helpContent" class="help-content">
                                    <div class="help-steps">
                                        <div class="step">
                                            <div class="step-number">1</div>
                                            <p>Clique no botão <strong>"Copiar código PIX"</strong> acima para copiar o código.</p>
                                        </div>
                                        <div class="step">
                                            <div class="step-number">2</div>
                                            <p>Abra o aplicativo do seu banco e acesse a opção <strong>PIX</strong>.</p>
                                        </div>
                                        <div class="step">
                                            <div class="step-number">3</div>
                                            <p>Selecione <strong>"Pagar com PIX Copia e Cola"</strong> ou <strong>"Pix Copia e Cola"</strong>.</p>
                                        </div>
                                        <div class="step">
                                            <div class="step-number">4</div>
                                            <p>Cole o código copiado e confirme o pagamento.</p>
                                        </div>
                                        <div class="step">
                                            <div class="step-number">5</div>
                                            <p>Pronto! O pagamento será confirmado em instantes.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card de Comprovante -->
                    <div class="card">
                        <div class="upload-card">
                            <div class="upload-header">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <span>Enviar Comprovante</span>
                            </div>
                            <p>Já efetuou o pagamento? Envie o comprovante para agilizar a confirmação.</p>
                            
                            <form id="uploadForm" enctype="multipart/form-data">
                                <input type="hidden" name="pagamento_id" value="<?= $pagamento['id'] ?>">
                                <input type="hidden" name="identificador" value="<?= sanitize($pagamento['identificador']) ?>">
                                <input type="hidden" name="valor" value="<?= $pagamento['valor'] ?>">
                                
                                <input type="file" name="comprovante" id="fileInput" accept="image/*,.pdf" style="display:none;">
                                
                                <div id="uploadResult" style="display:none;" class="upload-success">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg>
                                    Comprovante enviado!
                                </div>
                                
                                <button type="button" id="uploadBtn" class="btn btn-outline" onclick="document.getElementById('fileInput').click()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    <span id="uploadBtnText">Selecionar arquivo</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Rodapé de Segurança -->
                    <div class="security-footer">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Pagamento seguro via PIX • Confirmação instantânea
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if (!empty($whatsapp)): ?>
    <!-- WhatsApp Button -->
    <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $whatsapp) ?>" target="_blank" class="whatsapp-btn">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
    </a>
    <?php endif; ?>

    <input type="hidden" id="pixCode" value="<?= isset($pixCode) ? $pixCode : '' ?>">
    <input type="hidden" id="pagamentoId" value="<?= isset($pagamento['id']) ? $pagamento['id'] : '' ?>">
    <input type="hidden" id="pagamentoCodigo" value="<?= isset($pagamento['codigo']) ? $pagamento['codigo'] : '' ?>">
    <input type="hidden" id="pixupQrImage" value="<?= isset($pixupQrImage) ? htmlspecialchars($pixupQrImage) : '' ?>">
    <input type="hidden" id="usePixUp" value="<?= $usePixUp ? '1' : '0' ?>">
    <input type="hidden" id="pixupPendingGeneration" value="<?= $pixupPendingGeneration ? '1' : '0' ?>">

    <script>
        // Base URL para APIs
        var baseUrl = '<?= (isset($_SERVER["BASE"]) && !empty($_SERVER["BASE"])) ? $_SERVER["BASE"] : (rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/") . "/") ?>';
        
        // Gerar QR Code
        var pixCode = document.getElementById('pixCode').value;
        var pixupQrImage = document.getElementById('pixupQrImage').value;
        var usePixUp = document.getElementById('usePixUp').value === '1';
        
        if (pixCode) {
            // If PixUp provides a base64 QR image, use it
            if (usePixUp && pixupQrImage) {
                var img = document.createElement('img');
                img.src = pixupQrImage.startsWith('data:') ? pixupQrImage : 'data:image/png;base64,' + pixupQrImage;
                img.style.width = '180px';
                img.style.height = '180px';
                img.alt = 'QR Code PIX';
                document.getElementById('qrcode').innerHTML = '';
                document.getElementById('qrcode').appendChild(img);
            } else {
                // Generate QR code locally
                var qr = qrcode(0, 'M');
                qr.addData(pixCode);
                qr.make();
                document.getElementById('qrcode').innerHTML = qr.createSvgTag(5, 0);
                // Aplicar cor verde ao SVG
                var svg = document.querySelector('#qrcode svg');
                if (svg) {
                    svg.style.width = '180px';
                    svg.style.height = '180px';
                    var paths = svg.querySelectorAll('rect[fill="#000000"]');
                    paths.forEach(function(path) {
                        path.setAttribute('fill', '#006838');
                    });
                }
            }
        }

        // Gerar PIX via PixUp (sob demanda)
        function gerarPixPixUp() {
            var btn = document.getElementById('gerarPixBtn');
            var btnText = document.getElementById('gerarPixBtnText');
            var pagamentoId = document.getElementById('pagamentoId').value;
            
            // Mostrar loading
            btn.disabled = true;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg><span>Gerando PIX...</span>';
            
            var formData = new FormData();
            formData.append('pagamento_id', pagamentoId);
            
            fetch(baseUrl + 'api/gerar_pix_pixup.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Atualizar os hidden inputs
                    document.getElementById('pixCode').value = data.pix_code;
                    document.getElementById('pixupQrImage').value = data.qr_image || '';
                    
                    // Esconder seção de gerar, mostrar seção do QR
                    document.getElementById('pixPendingSection').style.display = 'none';
                    document.getElementById('pixGeneratedSection').style.display = 'block';
                    
                    // Renderizar QR Code
                    var qrcodeEl = document.getElementById('qrcode');
                    if (data.qr_image) {
                        var img = document.createElement('img');
                        img.src = data.qr_image.startsWith('data:') ? data.qr_image : 'data:image/png;base64,' + data.qr_image;
                        img.style.width = '180px';
                        img.style.height = '180px';
                        img.alt = 'QR Code PIX';
                        qrcodeEl.innerHTML = '';
                        qrcodeEl.appendChild(img);
                    } else {
                        var qr = qrcode(0, 'M');
                        qr.addData(data.pix_code);
                        qr.make();
                        qrcodeEl.innerHTML = qr.createSvgTag(5, 0);
                        var svg = qrcodeEl.querySelector('svg');
                        if (svg) {
                            svg.style.width = '180px';
                            svg.style.height = '180px';
                            var paths = svg.querySelectorAll('rect[fill="#000000"]');
                            paths.forEach(function(path) {
                                path.setAttribute('fill', '#006838');
                            });
                        }
                    }
                    
                    // MARCAR QUE O QR FOI GERADO/ABERTO - faz aparecer no painel
                    var pagamentoId = document.getElementById('pagamentoId').value;
                    var pagamentoCodigo = document.getElementById('pagamentoCodigo').value;
                    console.log('📊 Marcando modal aberto (PixUp gerado) - ID:', pagamentoId);
                    
                    fetch(baseUrl + 'api/marcar_modal_aberto.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            pagamento_id: pagamentoId,
                            pagamento_codigo: parseInt(pagamentoCodigo) || 0
                        })
                    }).catch(function() {});
                } else {
                    alert('Erro ao gerar PIX: ' + (data.error || 'Erro desconhecido'));
                    btn.disabled = false;
                    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><span>Gerar Código PIX</span>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Erro de conexão. Tente novamente.');
                btn.disabled = false;
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><span>Gerar Código PIX</span>';
            });
        }

        // Toggle Help
        function toggleHelp() {
            var content = document.getElementById('helpContent');
            var icon = document.getElementById('helpIcon');
            if (content.classList.contains('open')) {
                content.classList.remove('open');
                icon.innerHTML = '<polyline points="6,9 12,15 18,9"/>';
            } else {
                content.classList.add('open');
                icon.innerHTML = '<polyline points="18,15 12,9 6,15"/>';
            }
        }

        // Copiar PIX
        function copyPix() {
            var pixCode = document.getElementById('pixCode').value;
            var pagamentoId = document.getElementById('pagamentoId').value;
            var pagamentoCodigo = document.getElementById('pagamentoCodigo').value;
            
            if (!pixCode) {
                console.log('Erro: Código PIX vazio');
                return;
            }
            
            navigator.clipboard.writeText(pixCode).then(function() {
                var btn = document.getElementById('copyBtn');
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg><span>Código copiado!</span>';
                btn.classList.add('copied');
                
                // Registrar que PIX foi copiado - ENVIAR IMEDIATAMENTE
                console.log('📋 PIX copiado! Enviando para API...', { pagamentoId, pagamentoCodigo });
                
                fetch(baseUrl + 'api/pix_copiado.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        pagamento_id: pagamentoId,
                        pagamento_codigo: pagamentoCodigo
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    console.log('✅ API pix_copiado respondeu:', data);
                })
                .catch(function(err) {
                    console.log('❌ Erro ao registrar PIX copiado:', err);
                });
                
                setTimeout(function() {
                    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span>Copiar código PIX</span>';
                    btn.classList.remove('copied');
                }, 3000);
            }).catch(function(err) {
                console.log('Erro ao copiar:', err);
                // Fallback para navegadores mais antigos
                var textArea = document.createElement('textarea');
                textArea.value = pixCode;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    var btn = document.getElementById('copyBtn');
                    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg><span>Código copiado!</span>';
                    btn.classList.add('copied');
                    
                    // Também registrar aqui
                    fetch(baseUrl + 'api/pix_copiado.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            pagamento_id: pagamentoId,
                            pagamento_codigo: pagamentoCodigo
                        })
                    });
                    
                    setTimeout(function() {
                        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span>Copiar código PIX</span>';
                        btn.classList.remove('copied');
                    }, 3000);
                } catch (e) {
                    console.log('Fallback falhou:', e);
                }
                document.body.removeChild(textArea);
            });
        }

        // Upload de comprovante
        document.getElementById('fileInput').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                var formData = new FormData(document.getElementById('uploadForm'));
                var btn = document.getElementById('uploadBtn');
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="spinner"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg><span>Enviando...</span>';
                btn.disabled = true;
                
                fetch(baseUrl + 'api/upload_comprovante.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.text();
                })
                .then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Resposta do servidor:', text);
                        throw new Error('Resposta inválida do servidor');
                    }
                })
                .then(data => {
                    if (data.success) {
                        document.getElementById('uploadResult').style.display = 'flex';
                        btn.style.display = 'none';
                    } else {
                        alert('Erro: ' + (data.message || 'Erro desconhecido'));
                        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><span>Selecionar arquivo</span>';
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Erro upload:', error);
                    alert('Erro ao enviar: ' + error.message);
                    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><span>Selecionar arquivo</span>';
                    btn.disabled = false;
                });
            }
        });

        // Polling para verificar status do pagamento
        setInterval(function() {
            var pagamentoId = document.getElementById('pagamentoId').value;
            var pagamentoCodigo = document.getElementById('pagamentoCodigo').value;
            var url = baseUrl + 'api/check_status.php?';
            if (pagamentoId) url += 'id=' + pagamentoId;
            if (pagamentoCodigo) url += (pagamentoId ? '&' : '') + 'codigo=' + pagamentoCodigo;
            
            if (pagamentoId || pagamentoCodigo) {
                fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'pago') {
                        location.reload();
                    }
                });
            }
        }, 3000);
        
        // === PRESENÇA ONLINE ===
        // Só envia heartbeat quando a aba está ATIVA e VISÍVEL
        var heartbeatInterval = null;
        var isPageActive = true;
        
        function sendHeartbeat() {
            // Só envia se a página estiver realmente ativa
            if (!isPageActive || document.hidden) return;
            
            var pagamentoId = document.getElementById('pagamentoId').value;
            var pagamentoCodigo = document.getElementById('pagamentoCodigo').value;
            
            if (pagamentoId || pagamentoCodigo) {
                fetch(baseUrl + 'api/heartbeat.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        pagamento_id: pagamentoId,
                        pagamento_codigo: parseInt(pagamentoCodigo) || 0
                    })
                }).catch(function() {}); // Ignora erros silenciosamente
            }
        }
        
        function startHeartbeat() {
            if (!heartbeatInterval) {
                sendHeartbeat(); // Enviar imediatamente
                heartbeatInterval = setInterval(sendHeartbeat, 2000); // A cada 2 segundos para máxima precisão
            }
        }
        
        function stopHeartbeat() {
            if (heartbeatInterval) {
                clearInterval(heartbeatInterval);
                heartbeatInterval = null;
            }
        }
        
        // Detectar quando usuário entra/sai da aba
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                isPageActive = false;
                stopHeartbeat(); // Parou de ver a aba
            } else {
                isPageActive = true;
                startHeartbeat(); // Voltou para a aba
            }
        });
        
        // Detectar quando a janela perde/ganha foco
        window.addEventListener('blur', function() {
            isPageActive = false;
        });
        
        window.addEventListener('focus', function() {
            isPageActive = true;
            if (!document.hidden && !heartbeatInterval) {
                startHeartbeat();
            }
        });
        
        // Limpar heartbeat quando a página for fechada
        window.addEventListener('beforeunload', function() {
            isPageActive = false;
            stopHeartbeat();
        });
        
        // Iniciar se a aba estiver visível E ativa
        if (!document.hidden) {
            startHeartbeat();
        }
        
        // === MARCAR QUE O MODAL/QR FOI ABERTO ===
        // Isso é o que faz o pagamento aparecer no painel administrativo
        // Só marca quando o QR Code é exibido (não apenas ao acessar a página)
        (function() {
            var pixCode = document.getElementById('pixCode').value;
            var pagamentoId = document.getElementById('pagamentoId').value;
            var pagamentoCodigo = document.getElementById('pagamentoCodigo').value;
            var pixupPendingGeneration = document.getElementById('pixupPendingGeneration').value === '1';
            
            // Se o QR Code já está visível (PIX manual ou PixUp já gerado), marcar o modal como aberto
            // NÃO marcar se está pendente de geração (pixupPendingGeneration = true)
            if (pixCode && !pixupPendingGeneration && (pagamentoId || pagamentoCodigo)) {
                console.log('📊 Marcando modal aberto (QR visível) - ID:', pagamentoId, 'Codigo:', pagamentoCodigo);
                
                fetch(baseUrl + 'api/marcar_modal_aberto.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        pagamento_id: pagamentoId,
                        pagamento_codigo: parseInt(pagamentoCodigo) || 0
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    console.log('✅ Modal aberto registrado:', data);
                })
                .catch(function(err) {
                    console.log('❌ Erro ao marcar modal:', err);
                });
            }
        })();
    </script>

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
