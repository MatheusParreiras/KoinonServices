<?php

require_once __DIR__ . "/../service/Sessao.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    Sessao::iniciar();
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../controller/PermissaoController.php';
require_once __DIR__ . '/../controller/NotificacaoController.php';

if (empty($_SESSION['usuario_id']) || empty($_SESSION['usuario_perfil'])) {
    echo json_encode(['total' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $controller = new NotificacaoController();
    $total = $controller->contarNaoLidas((int) $_SESSION['usuario_id']);

    // Última notificação não lida: usada para o "push" (aviso na tela) em tempo real.
    $ultima = null;
    try {
        require_once __DIR__ . '/../model/dao/Conexao.php';
        $st = Conexao::conectar()->prepare(
            "SELECT id, tipo, titulo, mensagem, link FROM notificacao
             WHERE usuario_id = :u AND lida = 0 ORDER BY id DESC LIMIT 1"
        );
        $st->execute([':u' => (int) $_SESSION['usuario_id']]);
        $ultima = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $ultima = null;
    }

    echo json_encode([
        'total' => (int) $total,
        'perfil' => (string) $_SESSION['usuario_perfil'],
        'ultima' => $ultima
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['total' => 0], JSON_UNESCAPED_UNICODE);
}
