<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../controller/PermissaoController.php';
require_once __DIR__ . '/../controller/NotificacaoController.php';

if (empty($_SESSION['usuario_id']) || empty($_SESSION['usuario_perfil'])) {
    http_response_code(401);
    echo json_encode(['items' => [], 'total' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $usuarioId = (int) $_SESSION['usuario_id'];
    $controller = new NotificacaoController();
    $items = $controller->listarNaoLidas($usuarioId);
    $payload = [];
    foreach ($items as $item) {
        $created = $item->getCreatedAt();
        $payload[] = [
            'id' => (int) $item->getId(),
            'titulo' => (string) $item->getTitulo(),
            'mensagem' => (string) $item->getMensagem(),
            'tipo' => (string) $item->getTipo(),
            'link' => $item->getLink(),
            'created_at' => $created,
            'created_at_formatado' => $created ? date('d/m/Y H:i', strtotime($created)) : ''
        ];
    }
    echo json_encode(['items' => $payload, 'total' => count($payload)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['items' => [], 'total' => 0, 'error' => 'Não foi possível carregar as notificações.'], JSON_UNESCAPED_UNICODE);
}
