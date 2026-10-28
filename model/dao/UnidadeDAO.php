<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/UnidadeDTO.php";

class UnidadeDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }

    /*
     * LISTAR TODAS AS UNIDADES
     */
    public function listar(): array
    {
        $sql = "
            SELECT *
            FROM unidade
            ORDER BY id DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $unidades = [];

        while ($dados = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unidades[] = $this->montarDTO($dados);
        }

        return $unidades;
    }

    /*
     * BUSCAR UNIDADE POR ID
     */
    public function buscarPorId(int $id): ?UnidadeDTO
    {
        $sql = "
            SELECT *
            FROM unidade
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
     * CADASTRAR UNIDADE
     */
    public function cadastrar(UnidadeDTO $unidade): bool
    {
        $sql = "
            INSERT INTO unidade (
                identificacao,
                tipo_unidade,
                fracao_ideal,
                quantidade_vagas
            )
            VALUES (
                :identificacao,
                :tipo_unidade,
                :fracao_ideal,
                :quantidade_vagas
            )
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":identificacao",
            $unidade->getIdentificacao()
        );

        $stmt->bindValue(
            ":tipo_unidade",
            $unidade->getTipoUnidade()
        );

        $stmt->bindValue(
            ":fracao_ideal",
            $unidade->getFracaoIdeal()
        );

        $stmt->bindValue(
            ":quantidade_vagas",
            $unidade->getQuantidadeVagas(),
            PDO::PARAM_INT
        );

        $ok=$stmt->execute();
        if($ok)$unidade->setId((int)$this->conexao->lastInsertId());
        return $ok;
    }

    /*
     * EDITAR UNIDADE
     */
    public function editar(UnidadeDTO $unidade): bool
    {
        $sql = "
            UPDATE unidade
            SET
                identificacao = :identificacao,
                tipo_unidade = :tipo_unidade,
                fracao_ideal = :fracao_ideal,
                quantidade_vagas = :quantidade_vagas
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":identificacao",
            $unidade->getIdentificacao()
        );

        $stmt->bindValue(
            ":tipo_unidade",
            $unidade->getTipoUnidade()
        );

        $stmt->bindValue(
            ":fracao_ideal",
            $unidade->getFracaoIdeal()
        );

        $stmt->bindValue(
            ":quantidade_vagas",
            $unidade->getQuantidadeVagas(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":id",
            $unidade->getId(),
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    /*
     * EXCLUIR UNIDADE
     *
     * ATENÇÃO:
     * Aqui usamos exclusão física porque a tabela
     * Unidade não possui campo "ativo".
     *
     * Porém, o banco possui relacionamentos que podem
     * impedir a exclusão caso existam registros vinculados.
     */
    public function excluir(int $id): bool
    {
        $sql = "
            DELETE FROM unidade
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
     * TRANSFORMAR DADOS DO BANCO EM DTO
     */
    private function montarDTO(array $dados): UnidadeDTO
    {
        $unidade = new UnidadeDTO();

        $unidade->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $unidade->setIdentificacao(
            $dados["identificacao"] ?? ""
        );

        $unidade->setTipoUnidade(
            $dados["tipo_unidade"] ?? ""
        );

        $unidade->setFracaoIdeal(
            isset($dados["fracao_ideal"])
                ? (float) $dados["fracao_ideal"]
                : 0.0
        );

        $unidade->setQuantidadeVagas(
            isset($dados["quantidade_vagas"]) ? (int) $dados["quantidade_vagas"] : 0
        );

        $unidade->setCreatedAt(
            $dados["created_at"] ?? null
        );

        return $unidade;
    }
}