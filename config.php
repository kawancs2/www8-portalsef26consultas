<?php
// =============================================
// CONFIGURAÇÕES DO SISTEMA
// =============================================

// Timezone São Paulo
date_default_timezone_set('America/Sao_Paulo');

// Debug opcional (?debug=1)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/php-fatal.log';
    @ini_set('error_log', $logFile);

    register_shutdown_function(function () use ($logFile) {
        $err = error_get_last();
        if (!$err) return;

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($err['type'], $fatalTypes, true)) return;

        $msg = "[" . date('c') . "] FATAL: {$err['message']} in {$err['file']}:{$err['line']}\n";
        @file_put_contents($logFile, $msg, FILE_APPEND);

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "ERRO FATAL:\n" . $err['message'] . "\n" . $err['file'] . ":" . $err['line'] . "\n\n";
        echo "Log (se o servidor permitir escrita): " . $logFile . "\n";
    });
}

// =============================================
// CONFIGURAÇÕES DO BANCO DE DADOS - MySQL
// =============================================
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u708215636_detransc');
define('DB_USER', 'u708215636_detransc');
define('DB_PASS', 'Projetor123abc');

// Conexão PDO - MySQL
function getConnection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);

        // Configurar timezone para Brasília no MySQL
        $pdo->exec("SET time_zone = '-03:00'");

        return $pdo;
    } catch (PDOException $e) {
        error_log("Erro de conexão MySQL: " . $e->getMessage());
        die("Erro de conexão com o banco de dados.");
    }
}

// Iniciar sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Funções auxiliares
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function formatCurrency($value) {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

// Timezone Brasília
function getBrasiliaTimeZone() {
    try {
        return new DateTimeZone('America/Sao_Paulo');
    } catch (Exception $e) {
        return new DateTimeZone('-03:00');
    }
}

function formatDateTimeBR($dateString) {
    if (empty($dateString)) return '';
    $dt = new DateTime($dateString);
    $dt->setTimezone(getBrasiliaTimeZone());
    return $dt->format('d/m/Y, H:i:s');
}

function formatTimeBR($dateString) {
    if (empty($dateString)) return '';
    $dt = new DateTime($dateString);
    $dt->setTimezone(getBrasiliaTimeZone());
    return $dt->format('H:i');
}

function formatDateBR($dateString) {
    if (empty($dateString)) return '';
    $dt = new DateTime($dateString);
    $dt->setTimezone(getBrasiliaTimeZone());
    return $dt->format('d/m/Y');
}

function getStartOfDayBrasiliaUTC($date = 'today') {
    $tz = getBrasiliaTimeZone();
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $brasilia = new DateTime($date, $tz);
    } else {
        $brasilia = new DateTime('now', $tz);
        if ($date !== 'today' && $date !== 'now') {
            $brasilia->modify($date);
        }
    }
    $brasilia->setTime(0, 0, 0);
    return $brasilia->format('Y-m-d H:i:sP');
}

function getEndOfDayBrasiliaUTC($date = 'today') {
    $tz = getBrasiliaTimeZone();
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $brasilia = new DateTime($date, $tz);
    } else {
        $brasilia = new DateTime('now', $tz);
        if ($date !== 'today' && $date !== 'now') {
            $brasilia->modify($date);
        }
    }
    $brasilia->setTime(23, 59, 59);
    return $brasilia->format('Y-m-d H:i:sP');
}

// Headers de segurança HTTP
function addSecurityHeaders() {
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('Content-Security-Policy: frame-ancestors \'none\'');
    header('X-XSS-Protection: 1; mode=block');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// =============================================
// BLOQUEIO DE IP + MOBILE ONLY (para páginas públicas)
// =============================================
if (!function_exists('redirectToGoogle')) {
    function redirectToGoogle() {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: https://www.google.com', true, 302);
        exit;
    }
}

if (!function_exists('getClientIpForBlock')) {
    function getClientIpForBlock() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    }
}

if (!function_exists('isMobileDevice')) {
    function isMobileDevice() {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        return (bool) preg_match('/android|iphone|ipad|ipod|windows phone|mobile/i', $ua);
    }
}

if (!function_exists('enforceMobileOnly')) {
    function enforceMobileOnly() {
        try {
            $pdo = getConnection();
            $stmt = $pdo->prepare("SELECT ativo FROM configuracoes WHERE chave = 'modo_desktop'");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result && $result['ativo']) {
                return;
            }
        } catch (Exception $e) {
            // Se der erro, aplica a regra de mobile only
        }
        
        if (!isMobileDevice()) {
            redirectToGoogle();
        }
    }
}

if (!function_exists('enforceIpNotBlocked')) {
    function enforceIpNotBlocked() {
        try {
            $pdo = getConnection();
            $clientIp = getClientIpForBlock();
            $stmt = $pdo->prepare("SELECT id FROM ips_bloqueados WHERE ip = ?");
            $stmt->execute([$clientIp]);
            if ($stmt->fetch() !== false) {
                redirectToGoogle();
            }
        } catch (Exception $e) {
            // Se der erro, continua normalmente
        }
    }
}
?>
