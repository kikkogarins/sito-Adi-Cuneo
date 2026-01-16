<?php
/**
 * API Gestione Utenti Musicisti ADI Cuneo
 * File: api-musicisti.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');

$usersFile = 'musicisti-users.json';

// Funzione per leggere utenti
function getUsers() {
    global $usersFile;
    
    if (!file_exists($usersFile)) {
        // Crea file con utenti default
        $defaultUsers = [
            [
                'id' => 1,
                'username' => 'musicista',
                'password' => password_hash('adi2024', PASSWORD_DEFAULT),
                'name' => 'Musicista Default',
                'createdAt' => date('c')
            ],
            [
                'id' => 2,
                'username' => 'andrea',
                'password' => password_hash('chitarra123', PASSWORD_DEFAULT),
                'name' => 'Andrea Rossi',
                'createdAt' => date('c')
            ]
        ];
        
        file_put_contents($usersFile, json_encode($defaultUsers, JSON_PRETTY_PRINT));
        return $defaultUsers;
    }
    
    $content = file_get_contents($usersFile);
    return json_decode($content, true) ?: [];
}

// Funzione per salvare utenti
function saveUsers($users) {
    global $usersFile;
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

// Gestione richieste
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        // Lista utenti (senza password in chiaro)
        $users = getUsers();
        $safeUsers = array_map(function($user) {
            unset($user['password']);
            return $user;
        }, $users);
        
        echo json_encode([
            'success' => true,
            'users' => $safeUsers
        ]);
        break;
    
    case 'add':
        // Aggiungi utente
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['username']) || !isset($input['password']) || !isset($input['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Dati mancanti']);
            exit;
        }
        
        $users = getUsers();
        
        // Verifica username duplicato
        foreach ($users as $user) {
            if ($user['username'] === $input['username']) {
                echo json_encode(['success' => false, 'message' => 'Username già esistente']);
                exit;
            }
        }
        
        // Crea nuovo utente
        $newId = count($users) > 0 ? max(array_column($users, 'id')) + 1 : 1;
        
        $newUser = [
            'id' => $newId,
            'username' => $input['username'],
            'password' => password_hash($input['password'], PASSWORD_DEFAULT),
            'name' => $input['name'],
            'createdAt' => date('c')
        ];
        
        $users[] = $newUser;
        saveUsers($users);
        
        echo json_encode([
            'success' => true,
            'message' => 'Utente aggiunto con successo',
            'user' => [
                'id' => $newUser['id'],
                'username' => $newUser['username'],
                'name' => $newUser['name'],
                'createdAt' => $newUser['createdAt']
            ]
        ]);
        break;
    
    case 'delete':
        // Elimina utente
        $userId = intval($_GET['id'] ?? 0);
        
        if ($userId === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID mancante']);
            exit;
        }
        
        $users = getUsers();
        $users = array_filter($users, function($user) use ($userId) {
            return $user['id'] !== $userId;
        });
        
        $users = array_values($users); // Reindicizza array
        saveUsers($users);
        
        echo json_encode([
            'success' => true,
            'message' => 'Utente eliminato con successo'
        ]);
        break;
    
    case 'login':
        // Verifica login
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['username']) || !isset($input['password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Credenziali mancanti']);
            exit;
        }
        
        $users = getUsers();
        
        foreach ($users as $user) {
            if ($user['username'] === $input['username']) {
                if (password_verify($input['password'], $user['password'])) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Login effettuato',
                        'user' => [
                            'username' => $user['username'],
                            'name' => $user['name']
                        ]
                    ]);
                    exit;
                }
            }
        }
        
        echo json_encode(['success' => false, 'message' => 'Credenziali non valide']);
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Azione non valida']);
}
?>