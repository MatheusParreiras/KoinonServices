<?php

require_once __DIR__ . "/../model/dao/DocumentoDAO.php";
require_once __DIR__ . "/../service/NotificacaoService.php";
require_once __DIR__ . "/../model/dto/DocumentoDTO.php";

class DocumentoController
{
    private NotificacaoService $notificacao;

    private DocumentoDAO $dao;

    public function __construct()
    {
        $this->dao = new DocumentoDAO();
        $this->notificacao = new NotificacaoService();
    }

    public function listar(): array
    {
        return $this->dao->listar();
    }

    public function buscarPorId(
        int $id
    ): ?DocumentoDTO {

        return $this->dao->buscarPorId(
            $id
        );
    }

    public function cadastrar(
        DocumentoDTO $documento
    ): bool {

        $sucesso =
            $this->dao->cadastrar(
                $documento
            );

        if ($sucesso) {

            $this->notificacao->notificarTodosMoradores(
                "Documento",
                "📄 Novo documento",
                "Um novo documento do condomínio está disponível: "
                    . $documento->getTitulo()
                    . ".",
                "index.php"
            );
        }

        return $sucesso;
    }

    public function editar(
        DocumentoDTO $documento
    ): bool {

        return $this->dao->editar(
            $documento
        );
    }

    public function excluir(
        int $id
    ): bool {

        return $this->dao->excluir(
            $id
        );
    }
}