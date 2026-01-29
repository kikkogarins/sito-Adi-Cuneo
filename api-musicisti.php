<?php
/**
 * API Gestione Utenti Musicisti ADI Cuneo
 * File: api-musicisti.php
 * VERSIONE PROTETTA CON PASSWORD
 */

session_start();

// ============================================
// PROTEZIONE PASSWORD - CAMBIA QUI!
// ============================================
define('ADMIN_PASSWORD', 'admin2025'); // <-- CAMBIA QUESTA PASSWORD!

// Verifica autenticazione per tutte le azioni tranne login
$action = $_GET['action'] ?? '';

if ($action !== 'admin-login' && $action !== 'login') {
    // Verifica se è autenticato come admin
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Non autenticato. Accesso negato.'
        ]);
        exit;
    }
}

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

switch ($action) {
    case 'admin-login':
        // Login admin per accedere alla gestione
        $input = json_decode(file_get_contents('php://input'), true);
        $password = $input['password'] ?? '';
        
        if ($password === ADMIN_PASSWORD) {
            $_SESSION['admin_auth'] = true;
            $_SESSION['login_time'] = time();
            echo json_encode([
                'success' => true,
                'message' => 'Accesso autorizzato'
            ]);
        } else {
            sleep(1); // Rallenta brute force
            echo json_encode([
                'success' => false,
                'message' => 'Password errata'
            ]);
        }
        break;
    
    case 'admin-logout':
        session_destroy();
        echo json_encode([
            'success' => true,
            'message' => 'Logout effettuato'
        ]);
        break;
    
    case 'admin-check':
        $isAuth = isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true;
        echo json_encode([
            'success' => true,
            'authenticated' => $isAuth
        ]);
        break;
    
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
        // Verifica login (per i musicisti, non per admin)
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