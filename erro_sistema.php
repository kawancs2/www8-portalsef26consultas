<?php
require_once __DIR__ . '/config.php';
addSecurityHeaders();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Erro no Sistema - Neoenergia Elektro</title>
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .error-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #fff;
        }
        
        .error-container {
            text-align: center;
            max-width: 600px;
            width: 100%;
        }
        
        .error-illustration {
            margin-bottom: 20px;
        }
        
        .error-illustration img {
            max-width: 100%;
            width: 400px;
            height: auto;
        }
        
        .error-message {
            margin-bottom: 30px;
        }
        
        .error-message h1 {
            font-size: 20px;
            font-weight: 500;
            color: #ef4444;
            line-height: 1.5;
            margin: 0;
        }
        
        .error-btn {
            display: inline-block;
            padding: 14px 40px;
            background: #00a550;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 30px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 165, 80, 0.3);
        }
        
        .error-btn:hover {
            background: #008a42;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 165, 80, 0.4);
        }
        
        .error-btn:active {
            transform: translateY(0);
        }
        
        @media (max-width: 480px) {
            .error-illustration img {
                width: 300px;
            }
            
            .error-message h1 {
                font-size: 17px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/tracking.php'; ?>
    
    <div class="error-page">
        <div class="error-container">
            <!-- Ilustração de erro -->
            <div class="error-illustration">
                <img src="assets/illustracao-erro.svg" alt="Erro no sistema">
            </div>
            
            <!-- Mensagem de erro -->
            <div class="error-message">
                <h1>Aconteceu um erro inesperado em nosso sistema.<br>Por favor, tente novamente mais tarde!</h1>
            </div>
            
            <!-- Botão -->
            <a href="index.php" class="error-btn">FINALIZAR</a>
        </div>
    </div>
</body>
</html>