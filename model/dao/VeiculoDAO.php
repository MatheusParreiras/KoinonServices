<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/VeiculoDTO.php";
require_once __DIR__ . "/../../service/Erro.php";

class VeiculoDAO
{
    private PDO $conexao;
    private string $ultimoErro = "";

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }

    /*
    |--------------------------------------------------------------------------
    | LISTAR VEÍCULOS
    |--------------------------------------------------------------------------
    */

    public function listar(): array
    {
        $sql = "
            SELECT
                v.*,
                u.nome AS usuario_nome,
                un.id AS unidade_id,
                un.identificacao AS unidade_identificacao
            FROM veiculo v
            INNER JOIN usuario u ON v.usuario_id=u.id
            LEFT JOIN unidade un ON u.unidade_id=un.id
            ORDER BY v.id DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $veiculos = [];

        while ($dados = $stmt->fetch(PDO::FETCH_ASSOC)) {

            $veiculos[] = $this->montarDTO($dados);
        }

        return $veiculos;
    }


    /*
    |--------------------------------------------------------------------------
    | BUSCAR POR ID
    |--------------------------------------------------------------------------
    */

    public function buscarPorId(int $id): ?VeiculoDTO
    {
        $sql = "
            SELECT *
            FROM veiculo
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

    public function getUltimoErro(): string
    {
        return $this->ultimoErro;
    }

    public function cadastrar(VeiculoDTO $veiculo): bool
    {
        $this->ultimoErro = "";
        $sql = "
            INSERT INTO veiculo (
                usuario_id,
                placa,
                modelo,
                cor,
                status_aprovacao
            )
            VALUES (
                :usuario_id,
                :placa,
                :modelo,
                :cor,
                :status_aprovacao
            )
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $veiculo->getUsuarioId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":placa",
            $veiculo->getPlaca()
        );

        $stmt->bindValue(
            ":modelo",
            $veiculo->getModelo()
        );

        $stmt->bindValue(
            ":cor",
            $veiculo->getCor()
        );

        $stmt->bindValue(
            ":status_aprovacao",
            $veiculo->getStatusAprovacao()
        );

        try {
            $ok = $stmt->execute();
            if ($ok) $veiculo->setId((int)$this->conexao->lastInsertId());
            return $ok;
        } catch (PDOException $e) {
            $this->ultimoErro = $e->getCode() === "23000"
                ? "Essa placa já está cadastrada no sistema."
                : Erro::mensagem($e);
            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | EDITAR
    |--------------------------------------------------------------------------
    */

    public function editar(VeiculoDTO $veiculo): bool
    {
        $sql = "
            UPDATE veiculo
            SET
                usuario_id = :usuario_id,
                placa = :placa,
                modelo = :modelo,
                cor = :cor,
                status_aprovacao = :status_aprovacao
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $veiculo->getUsuarioId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":placa",
            $veiculo->getPlaca()
        );

        $stmt->bindValue(
            ":modelo",
            $veiculo->getModelo()
        );

        $stmt->bindValue(
            ":cor",
            $veiculo->getCor()
        );

        $stmt->bindValue(
            ":status_aprovacao",
            $veiculo->getStatusAprovacao()
        );

        $stmt->bindValue(
            ":id",
            $veiculo->getId(),
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
            DELETE FROM veiculo
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

    private function montarDTO(array $dados): VeiculoDTO
    {
        $veiculo = new VeiculoDTO();

        $veiculo->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $veiculo->setUsuarioId(
            isset($dados["usuario_id"])
                ? (int) $dados["usuario_id"]
                : null
        );

        $veiculo->setUsuarioNome($dados["usuario_nome"]??null);
        $veiculo->setUnidadeId(isset($dados["unidade_id"])?(int)$dados["unidade_id"]:null);
        $veiculo->setUnidadeIdentificacao($dados["unidade_identificacao"]??null);

        $veiculo->setPlaca(
            $dados["placa"] ?? ""
        );

        $veiculo->setModelo(
            $dados["modelo"] ?? ""
        );

        $veiculo->setCor(
            $dados["cor"] ?? ""
        );

        $veiculo->setStatusAprovacao($dados["status_aprovacao"] ?? "Pendente");

        $veiculo->setCreatedAt(
            $dados["created_at"] ?? null
        );

        return $veiculo;
    }
}