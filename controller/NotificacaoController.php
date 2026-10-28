<?php

require_once __DIR__ . "/../model/dao/NotificacaoDAO.php";
require_once __DIR__ . "/../model/dto/NotificacaoDTO.php";

class NotificacaoController
{
    private NotificacaoDAO $dao;


    public function __construct()
    {
        $this->dao =
            new NotificacaoDAO();
    }


    // ==========================================================
    // CADASTRAR
    // ==========================================================

    public function cadastrar(
        NotificacaoDTO $notificacao
    ): bool {

        return $this->dao->cadastrar(
            $notificacao
        );
    }


    // ==========================================================
    // LISTAR POR USUÁRIO
    // ==========================================================

    public function listarPorUsuario(
        int $usuarioId
    ): array {

        return $this->dao->listarPorUsuario(
            $usuarioId
        );
    }


    // ==========================================================
    // LISTAR NÃO LIDAS
    // ==========================================================

    public function listarNaoLidas(
        int $usuarioId
    ): array {

        return $this->dao->listarNaoLidas(
            $usuarioId
        );
    }


    // ==========================================================
    // BUSCAR POR ID
    // ==========================================================

    public function buscarPorId(
        int $id,
        int $usuarioId
    ): ?NotificacaoDTO {

        return $this->dao->buscarPorId(
            $id,
            $usuarioId
        );
    }


    // ==========================================================
    // CONTAR NÃO LIDAS
    // ==========================================================

    public function contarNaoLidas(
        int $usuarioId
    ): int {

        return $this->dao->contarNaoLidas(
            $usuarioId
        );
    }


    // ==========================================================
    // MARCAR COMO LIDA
    // ==========================================================

    public function marcarComoLida(
        int $id,
        int $usuarioId
    ): bool {

        return $this->dao->marcarComoLida(
            $id,
            $usuarioId
        );
    }


    // ==========================================================
    // MARCAR TODAS COMO LIDAS
    // ==========================================================

    public function marcarTodasComoLidas(
        int $usuarioId
    ): bool {

        return $this->dao->marcarTodasComoLidas(
            $usuarioId
        );
    }


    // ==========================================================
    // EXCLUIR
    // ==========================================================

    public function excluir(
        int $id,
        int $usuarioId
    ): bool {

        return $this->dao->excluir(
            $id,
            $usuarioId
        );
    }
}