<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../controller/NotificacaoController.php';
if (empty($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
try {
    $ok = (new NotificacaoController())->marcarTodasComoLidas((int)$_SESSION['usuario_id']);
    echo json_encode(['ok'=>$ok]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['ok'=>false]);
}
