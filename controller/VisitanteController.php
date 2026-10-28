<?php

require_once __DIR__ . "/../model/dao/VisitanteDAO.php";
require_once __DIR__ . "/../model/dto/VisitanteDTO.php";

class VisitanteController
{
    private VisitanteDAO $dao;

    public function __construct()
    {
        $this->dao = new VisitanteDAO();
    }


    /*
    |--------------------------------------------------------------------------
    | LISTAR
    |--------------------------------------------------------------------------
    */

    public function listar(): array
    {
        return $this->dao->listar();
    }


    /*
    |--------------------------------------------------------------------------
    | BUSCAR POR ID
    |--------------------------------------------------------------------------
    */

    public function buscarPorId(int $id): ?VisitanteDTO
    {
        return $this->dao->buscarPorId($id);
    }


    /*
    |--------------------------------------------------------------------------
    | CADASTRAR
    |--------------------------------------------------------------------------
    */

    public function cadastrar(VisitanteDTO $visitante): bool
    {
        return $this->dao->cadastrar($visitante);
    }


    /*
    |--------------------------------------------------------------------------
    | EDITAR
    |--------------------------------------------------------------------------
    */

    public function editar(VisitanteDTO $visitante): bool
    {
        return $this->dao->editar($visitante);
    }


    /*
    |--------------------------------------------------------------------------
    | EXCLUIR
    |--------------------------------------------------------------------------
    */

    public function excluir(int $id): bool
    {
        return $this->dao->excluir($id);
    }
}