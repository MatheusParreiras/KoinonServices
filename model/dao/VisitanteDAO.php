<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/VisitanteDTO.php";

class VisitanteDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }


    /*
    |--------------------------------------------------------------------------
    | LISTAR
    |--------------------------------------------------------------------------
    */

    public function listar(): array
    {
        $sql = "
            SELECT *
            FROM visitante
            ORDER BY id DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $visitantes = [];

        while ($dados = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $visitantes[] = $this->montarDTO($dados);
        }

        return $visitantes;
    }


    /*
    |--------------------------------------------------------------------------
    | BUSCAR POR ID
    |--------------------------------------------------------------------------
    */

    public function buscarPorId(int $id): ?VisitanteDTO
    {
        $sql = "
            SELECT *
            FROM visitante
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            return null;
        }

        return $this->montarDTO($dados);
    }


    /*
    |--------------------------------------------------------------------------
    | CADASTRAR
    |--------------------------------------------------------------------------
    */

    public function cadastrar(VisitanteDTO $visitante): bool
    {
        $sql = "
            INSERT INTO visitante (
                nome,
                documento,
                foto_url,
                tipo_visitante
            )
            VALUES (
                :nome,
                :documento,
                :foto_url,
                :tipo_visitante
            )
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":nome",
            $visitante->getNome()
        );

        $stmt->bindValue(
            ":documento",
            Cpf::limpar($visitante->getDocumento())
        );

        $stmt->bindValue(
            ":foto_url",
            $visitante->getFotoUrl()
        );

        $stmt->bindValue(
            ":tipo_visitante",
            $visitante->getTipoVisitante()
        );

        return $stmt->execute();
    }


    /*
    |--------------------------------------------------------------------------
    | EDITAR
    |--------------------------------------------------------------------------
    */

    public function editar(VisitanteDTO $visitante): bool
    {
        $sql = "
            UPDATE visitante
            SET
                nome = :nome,
                documento = :documento,
                foto_url = :foto_url,
                tipo_visitante = :tipo_visitante
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":nome",
            $visitante->getNome()
        );

        $stmt->bindValue(
            ":documento",
            Cpf::limpar($visitante->getDocumento())
        );

        $stmt->bindValue(
            ":foto_url",
            $visitante->getFotoUrl()
        );

        $stmt->bindValue(
            ":tipo_visitante",
            $visitante->getTipoVisitante()
        );

        $stmt->bindValue(
            ":id",
            $visitante->getId(),
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    /*
    |--------------------------------------------------------------------------
    | EXCLUIR
    |--------------------------------------------------------------------------
    */

    public function excluir(int $id): bool
    {
        $sql = "
            DELETE FROM visitante
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }


    /*
    |--------------------------------------------------------------------------
    | MONTAR DTO
    |--------------------------------------------------------------------------
    */

    private function montarDTO(array $dados): VisitanteDTO
    {
        $visitante = new VisitanteDTO();

        $visitante->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $visitante->setNome(
            $dados["nome"] ?? ""
        );

        $visitante->setDocumento(
            $dados["documento"] ?? ""
        );

        $visitante->setFotoUrl(
            $dados["foto_url"] ?? null
        );

        $visitante->setTipoVisitante(
            $dados["tipo_visitante"] ?? "Visitante"
        );

        $visitante->setCreatedAt(
            $dados["created_at"] ?? null
        );

        return $visitante;
    }
}