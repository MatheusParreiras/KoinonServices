<?php

require_once __DIR__ . "/../model/dao/OcorrenciaDAO.php";
require_once __DIR__ . "/../service/NotificacaoService.php";
require_once __DIR__ . "/../model/dto/OcorrenciaDTO.php";

class OcorrenciaController
{
    private NotificacaoService $notificacao;

    private OcorrenciaDAO $dao;

    public function __construct()
    {
        $this->dao = new OcorrenciaDAO();
        $this->notificacao = new NotificacaoService();
    }

    public function listar(): array
    {
        return $this->dao->listar();
    }

    public function buscarPorId(
        int $id
    ): ?OcorrenciaDTO {

        return $this->dao->buscarPorId(
            $id
        );
    }

    public function cadastrar(
        OcorrenciaDTO $ocorrencia
    ): bool {

        $sucesso =
            $this->dao->cadastrar(
                $ocorrencia
            );

        if (
            $sucesso &&
            $ocorrencia->getUsuarioId() !== null
        ) {

            $this->notificacao->ocorrencia(
                (int) $ocorrencia->getUsuarioId(),
                $ocorrencia->getTitulo(),
                $ocorrencia->getStatus()
            );
        }

        return $sucesso;
    }

    public function editar(
        OcorrenciaDTO $ocorrencia,
        ?string $mensagemAoMorador = null
    ): bool {

        $sucesso =
            $this->dao->editar(
                $ocorrencia,
                $mensagemAoMorador
            );

        if (
            $sucesso &&
            $ocorrencia->getUsuarioId() !== null
        ) {

            $this->notificacao->ocorrencia(
                (int) $ocorrencia->getUsuarioId(),
                $ocorrencia->getTitulo(),
                $ocorrencia->getStatus(),
                $ocorrencia->getId()
            );
        }

        return $sucesso;
    }

    public function excluir(
        int $id
    ): bool {

        return $this->dao->excluir(
            $id
        );
    }

    public function alterarStatus(
        int $id,
        string $status,
        ?string $mensagemAoMorador = null
    ): bool {

        $ocorrencia =
            $this->dao->buscarPorId(
                $id
            );

        $sucesso =
            $this->dao->alterarStatus(
                $id,
                $status,
                $mensagemAoMorador
            );

        if (
            $sucesso &&
            $ocorrencia !== null &&
            $ocorrencia->getUsuarioId() !== null
        ) {

            $this->notificacao->ocorrencia(
                (int) $ocorrencia->getUsuarioId(),
                $ocorrencia->getTitulo(),
                $status,
                $id
            );
        }

        return $sucesso;
    }
}