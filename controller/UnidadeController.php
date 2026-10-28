<?php

require_once __DIR__ . "/../model/dao/UnidadeDAO.php";
require_once __DIR__ . "/../model/dto/UnidadeDTO.php";

class UnidadeController
{
    private UnidadeDAO $dao;

    public function __construct()
    {
        $this->dao = new UnidadeDAO();
    }

    public function listar(): array
    {
        return $this->dao->listar();
    }

    public function buscarPorId(int $id): ?UnidadeDTO
    {
        return $this->dao->buscarPorId($id);
    }

    public function cadastrar(UnidadeDTO $unidade): bool
    {
        return $this->dao->cadastrar($unidade);
    }

    public function editar(UnidadeDTO $unidade): bool
    {
        return $this->dao->editar($unidade);
    }

    public function excluir(int $id): bool
    {
        return $this->dao->excluir($id);
    }
}