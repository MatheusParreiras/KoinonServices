<?php

require_once __DIR__ . "/../model/dao/VeiculoDAO.php";
require_once __DIR__ . "/../model/dto/VeiculoDTO.php";
require_once __DIR__ . "/../service/CondominioConfigService.php";

class VeiculoController
{
    private VeiculoDAO $dao;

    public function __construct()
    {
        $this->dao = new VeiculoDAO();
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

    public function getUltimoErro(): string
    {
        return $this->dao->getUltimoErro();
    }


    /*
    |--------------------------------------------------------------------------
    | BUSCAR POR ID
    |--------------------------------------------------------------------------
    */

    public function buscarPorId(int $id): ?VeiculoDTO
    {
        return $this->dao->buscarPorId($id);
    }


    /*
    |--------------------------------------------------------------------------
    | CADASTRAR
    |--------------------------------------------------------------------------
    */

    public function cadastrar(VeiculoDTO $veiculo): bool{$veiculo->setStatusAprovacao("Aprovado");return $this->dao->cadastrar($veiculo);}
    public function cadastrarPendente(VeiculoDTO $veiculo): bool{$veiculo->setStatusAprovacao("Pendente");return $this->dao->cadastrar($veiculo);}
    public function status(int $id):string{return CondominioConfigService::statusVeiculo($id);}
    public function alterarStatus(int $id,string $status):void{CondominioConfigService::salvarStatusVeiculo($id,$status);}
    public function removerStatus(int $id):void{CondominioConfigService::removerVeiculo($id);}


    /*
    |--------------------------------------------------------------------------
    | EDITAR
    |--------------------------------------------------------------------------
    */

    public function editar(VeiculoDTO $veiculo): bool
    {
        return $this->dao->editar($veiculo);
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