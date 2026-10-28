<?php

require_once __DIR__ . "/../model/dao/InteracaoDAO.php";
require_once __DIR__ . "/../model/dto/InteracaoDTO.php";

class InteracaoController
{
    private InteracaoDAO $dao;

    public function __construct()
    {
        $this->dao = new InteracaoDAO();
    }

    public function listar(): array
    {
        return $this->dao->listar();
    }

    public function buscarPorId(
        int $id
    ): ?InteracaoDTO {

        return $this->dao->buscarPorId($id);
    }

    public function cadastrar(
        InteracaoDTO $interacao
    ): bool {

        return $this->dao->cadastrar(
            $interacao
        );
    }

    public function editar(
        InteracaoDTO $interacao
    ): bool {

        return $this->dao->editar(
            $interacao
        );
    }

    public function excluir(
        int $id
    ): bool {

        return $this->dao->excluir($id);
    }
}