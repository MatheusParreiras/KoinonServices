<?php

require_once __DIR__ . "/../../service/Sessao.php";
if (session_status() !== PHP_SESSION_ACTIVE) Sessao::iniciar();
require_once __DIR__ . "/../../controller/PermissaoController.php";
require_once __DIR__ . "/../../service/ComprovanteDAO.php";
require_once __DIR__ . "/../../service/ComprovantePdfService.php";
PermissaoController::exigirPerfil(["Morador"]);
$id=(int)($_SESSION['usuario_id']??0); $comprovanteId=(int)($_GET['id']??0);
if($comprovanteId<=0){http_response_code(400);exit('Comprovante inválido.');}
$comprovante=(new ComprovanteDAO())->buscarParaMorador($comprovanteId,$id);
if(!$comprovante){http_response_code(404);exit('Comprovante não encontrado.');}
ComprovantePdfService::output($comprovante, isset($_GET['download']));
