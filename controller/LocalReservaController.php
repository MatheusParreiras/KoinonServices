<?php

require_once __DIR__ . "/../model/dao/LocalReservaDAO.php";
require_once __DIR__ . "/../model/dto/LocalReservaDTO.php";

class LocalReservaController
{
    private LocalReservaDAO $dao;


    public function __construct()
    {
        $this->dao =
            new LocalReservaDAO();
    }


    /*
     * LISTAR
     */

    public function listar(): array
    {
        return $this->dao->listar();
    }


    /*
     * LISTAR ATIVOS
     */

    public function listarAtivos(): array
    {
        return $this->dao->listarAtivos();
    }


    /*
     * BUSCAR
     */

    public function buscarPorId(
        int $id
    ): ?LocalReservaDTO {

        return $this->dao->buscarPorId(
            $id
        );
    }


    /*
     * VERIFICAR NOME
     */

    public function nomeExiste(
        string $nome,
        ?int $idIgnorar = null
    ): bool {

        return $this->dao->nomeExiste(
            $nome,
            $idIgnorar
        );
    }


    /*
     * CADASTRAR
     */

    public function cadastrar(
        LocalReservaDTO $local
    ): bool {

        return $this->dao->cadastrar(
            $local
        );
    }


    /*
     * EDITAR
     */

    public function editar(
        LocalReservaDTO $local
    ): bool {

        return $this->dao->editar(
            $local
        );
    }


    /*
     * FOTOS
     */

    public function atualizarFotos(int $id, array $fotos): bool
    {
        return $this->dao->atualizarFotos($id, $fotos);
    }


    /*
     * DESATIVAR
     */

    public function excluir(
        int $id
    ): bool {

        return $this->dao->desativar(
            $id
        );
    }


    public function desativar(
        int $id
    ): bool {

        return $this->dao->desativar(
            $id
        );
    }


    /*
     * REATIVAR
     */

    public function reativar(
        int $id
    ): bool {

        return $this->dao->reativar(
            $id
        );
    }
}