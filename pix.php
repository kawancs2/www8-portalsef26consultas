<?php
// =============================================
// GERADOR DE CÓDIGO PIX
// =============================================

function generatePixCode($chavePix, $valor, $nomeRecebedor, $cidade, $txid = '') {
    // Formatar valores
    $chavePix = trim($chavePix);
    $nomeRecebedor = strtoupper(removeAccents(substr($nomeRecebedor, 0, 25)));
    $cidade = strtoupper(removeAccents(substr($cidade, 0, 15)));
    $txid = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25));
    $valorFormatado = number_format($valor, 2, '.', '');
    
    // Montar payload
    $payload = '';
    
    // ID 00 - Payload Format Indicator
    $payload .= '000201';
    
    // ID 26 - Merchant Account Information
    $merchantAccount = '0014BR.GOV.BCB.PIX01' . str_pad(strlen($chavePix), 2, '0', STR_PAD_LEFT) . $chavePix;
    $payload .= '26' . str_pad(strlen($merchantAccount), 2, '0', STR_PAD_LEFT) . $merchantAccount;
    
    // ID 52 - Merchant Category Code
    $payload .= '52040000';
    
    // ID 53 - Transaction Currency (986 = BRL)
    $payload .= '5303986';
    
    // ID 54 - Transaction Amount
    if ($valor > 0) {
        $payload .= '54' . str_pad(strlen($valorFormatado), 2, '0', STR_PAD_LEFT) . $valorFormatado;
    }
    
    // ID 58 - Country Code
    $payload .= '5802BR';
    
    // ID 59 - Merchant Name
    $payload .= '59' . str_pad(strlen($nomeRecebedor), 2, '0', STR_PAD_LEFT) . $nomeRecebedor;
    
    // ID 60 - Merchant City
    $payload .= '60' . str_pad(strlen($cidade), 2, '0', STR_PAD_LEFT) . $cidade;
    
    // ID 62 - Additional Data Field (TXID)
    if (!empty($txid)) {
        $additionalData = '05' . str_pad(strlen($txid), 2, '0', STR_PAD_LEFT) . $txid;
        $payload .= '62' . str_pad(strlen($additionalData), 2, '0', STR_PAD_LEFT) . $additionalData;
    }
    
    // ID 63 - CRC16
    $payload .= '6304';
    $crc = crc16($payload);
    $payload .= strtoupper($crc);
    
    return $payload;
}

function crc16($data) {
    $polynomial = 0x1021;
    $result = 0xFFFF;
    
    for ($i = 0; $i < strlen($data); $i++) {
        $result ^= (ord($data[$i]) << 8);
        for ($j = 0; $j < 8; $j++) {
            if ($result & 0x8000) {
                $result = (($result << 1) ^ $polynomial) & 0xFFFF;
            } else {
                $result = ($result << 1) & 0xFFFF;
            }
        }
    }
    
    return str_pad(dechex($result), 4, '0', STR_PAD_LEFT);
}

function removeAccents($string) {
    $accents = array(
        'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A',
        'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a',
        'Ç'=>'C', 'ç'=>'c',
        'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E',
        'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e',
        'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I',
        'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i',
        'Ñ'=>'N', 'ñ'=>'n',
        'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O',
        'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o',
        'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U',
        'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü'=>'u',
        'Ý'=>'Y', 'ý'=>'y', 'ÿ'=>'y'
    );
    return strtr($string, $accents);
}
?>
