<?php
// invia_email.php - Gestore form contatti ADI Cuneo

// Configurazione
$destinatario = "info@adicuneo.it";
$oggetto = "Nuovo messaggio dal sito ADI Cuneo";

// Sicurezza: consenti solo richieste POST
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: contatti.html");
    exit;
}

// Funzione per pulire i dati in input
function pulisci_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Recupera e pulisci i dati dal form
$nome = pulisci_input($_POST['nome']);
$email = pulisci_input($_POST['email']);
$telefono = isset($_POST['telefono']) ? pulisci_input($_POST['telefono']) : "Non fornito";
$motivo = isset($_POST['motivo']) ? pulisci_input($_POST['motivo']) : "Non specificato";
$messaggio = pulisci_input($_POST['messaggio']);

// Validazione email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: contatti.html?errore=email_non_valida");
    exit;
}

// Validazione campi obbligatori
if (empty($nome) || empty($email) || empty($messaggio)) {
    header("Location: contatti.html?errore=campi_mancanti");
    exit;
}

// Traduci il motivo del contatto
$motivi = array(
    'info' => 'Informazioni Generali',
    'preghiera' => 'Richiesta di Preghiera',
    'evento' => 'Informazioni su Eventi',
    'battesimo' => 'Battesimo',
    'altro' => 'Altro'
);
$motivo_testo = isset($motivi[$motivo]) ? $motivi[$motivo] : $motivo;

// Componi il corpo dell'email in HTML
$corpo_email = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4a4a54; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .field { margin-bottom: 15px; padding: 10px; background: white; border-left: 4px solid #e0ca55; }
        .field strong { color: #4a4a54; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2>Nuovo Messaggio dal Sito</h2>
            <p>ADI Cuneo</p>
        </div>
        <div class='content'>
            <div class='field'>
                <strong>Nome:</strong><br>
                $nome
            </div>
            <div class='field'>
                <strong>Email:</strong><br>
                $email
            </div>
            <div class='field'>
                <strong>Telefono:</strong><br>
                $telefono
            </div>
            <div class='field'>
                <strong>Motivo del contatto:</strong><br>
                $motivo_testo
            </div>
            <div class='field'>
                <strong>Messaggio:</strong><br>
                " . nl2br($messaggio) . "
            </div>
        </div>
        <div class='footer'>
            <p>Questo messaggio è stato inviato dal form contatti del sito ADI Cuneo</p>
            <p>Data: " . date('d/m/Y H:i:s') . "</p>
        </div>
    </div>
</body>
</html>
";

// Intestazioni email
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: Sito ADI Cuneo <noreply@adicuneo.it>" . "\r\n";
$headers .= "Reply-To: $email" . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Invia l'email
if (mail($destinatario, $oggetto, $corpo_email, $headers)) {
    // Successo - reindirizza alla pagina di ringraziamento
    header("Location: grazie.html");
    exit;
} else {
    // Errore nell'invio
    header("Location: contatti.html?errore=invio_fallito");
    exit;
}
?>