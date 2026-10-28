<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/OcorrenciaDTO.php";
require_once __DIR__ . "/../../service/OcorrenciaHistorico.php";

class OcorrenciaDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }

    public function listar(): array
    {
        $sql = "
            SELECT
                o.*,
                u.nome AS usuario_nome
            FROM ocorrencia o
            INNER JOIN usuario u
                ON o.usuario_id = u.id
            ORDER BY o.id DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $ocorrencias = [];

        while ($dados = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ocorrencias[] = $this->montarDTO($dados);
        }

        return $ocorrencias;
    }

    public function buscarPorId(int $id): ?OcorrenciaDTO
    {
        $sql = "
            SELECT
                o.*,
                u.nome AS usuario_nome
            FROM ocorrencia o
            INNER JOIN usuario u
                ON o.usuario_id = u.id
            WHERE o.id = :id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->bindValue(":id", $id, PDO::PARAM_INT);
        $stmt->execute();

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            return null;
        }

        return $this->montarDTO($dados);
    }

    public function cadastrar(OcorrenciaDTO $ocorrencia): bool
    {
        /*
         * data_abertura não é enviada.
         * O banco de dados preencherá automaticamente
         * usando DEFAULT CURRENT_TIMESTAMP.
         */

        $sql = "
            INSERT INTO ocorrencia (
                usuario_id,
                tipo,
                titulo,
                descricao,
                status,
                foto_url,
                data_fechamento
            )
            VALUES (
                :usuario_id,
                :tipo,
                :titulo,
                :descricao,
                :status,
                :foto_url,
                :data_fechamento
            )
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $ocorrencia->getUsuarioId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":tipo",
            $ocorrencia->getTipo(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":titulo",
            $ocorrencia->getTitulo(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":descricao",
            $ocorrencia->getDescricao(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":status",
            $ocorrencia->getStatus(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":foto_url",
            $ocorrencia->getFotoUrl(),
            $ocorrencia->getFotoUrl() === null
                ? PDO::PARAM_NULL
                : PDO::PARAM_STR
        );

        /*
         * A ocorrência começa sem data de fechamento.
         */

        $stmt->bindValue(
            ":data_fechamento",
            null,
            PDO::PARAM_NULL
        );

        $sucesso = $stmt->execute();

        if ($sucesso) {
            OcorrenciaHistorico::registrar(
                $this->conexao,
                (int) $this->conexao->lastInsertId(),
                $ocorrencia->getStatus(),
                "Ocorrência registrada."
            );
        }

        return $sucesso;
    }

    public function editar(
        OcorrenciaDTO $ocorrencia,
        ?string $mensagemAoMorador = null
    ): bool {

        $anterior = $ocorrencia->getId() !== null
            ? $this->buscarPorId((int) $ocorrencia->getId())
            : null;

        /*
         * A data de fechamento acompanha o status:
         *  - Resolvida: preenche (mantém a existente, se houver);
         *  - Aberta / Em andamento: limpa;
         *  - Cancelada: mantém o que já estava gravado.
         */
        $statusNovo = $ocorrencia->getStatus();

        if ($statusNovo === "Resolvida") {
            $ocorrencia->setDataFechamento(
                $ocorrencia->getDataFechamento() ?: date("Y-m-d H:i:s")
            );
        } elseif (in_array($statusNovo, ["Aberta", "Em_Andamento"], true)) {
            $ocorrencia->setDataFechamento(null);
        }

        $sql = "
            UPDATE ocorrencia
            SET
                usuario_id = :usuario_id,
                tipo = :tipo,
                titulo = :titulo,
                descricao = :descricao,
                status = :status,
                foto_url = :foto_url,
                data_abertura = :data_abertura,
                data_fechamento = :data_fechamento
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $ocorrencia->getUsuarioId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":tipo",
            $ocorrencia->getTipo(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":titulo",
            $ocorrencia->getTitulo(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":descricao",
            $ocorrencia->getDescricao(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":status",
            $ocorrencia->getStatus(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":foto_url",
            $ocorrencia->getFotoUrl(),
            $ocorrencia->getFotoUrl() === null
                ? PDO::PARAM_NULL
                : PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":data_abertura",
            $ocorrencia->getDataAbertura(),
            $ocorrencia->getDataAbertura() === null
                ? PDO::PARAM_NULL
                : PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":data_fechamento",
            $ocorrencia->getDataFechamento(),
            $ocorrencia->getDataFechamento() === null
                ? PDO::PARAM_NULL
                : PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":id",
            $ocorrencia->getId(),
            PDO::PARAM_INT
        );

        $sucesso = $stmt->execute();

        if ($sucesso && $ocorrencia->getId() !== null) {

            $mensagemAoMorador = trim((string) $mensagemAoMorador);
            $statusMudou = $anterior === null
                || $anterior->getStatus() !== $statusNovo;

            if ($statusMudou || $mensagemAoMorador !== "") {

                if ($mensagemAoMorador === "") {
                    $mensagemAoMorador = "Status atualizado para "
                        . OcorrenciaHistorico::rotuloStatus($statusNovo)
                        . ".";
                }

                OcorrenciaHistorico::registrar(
                    $this->conexao,
                    (int) $ocorrencia->getId(),
                    $statusNovo,
                    $mensagemAoMorador
                );
            }
        }

        return $sucesso;
    }

    public function excluir(int $id): bool
    {
        $sql = "
            DELETE FROM ocorrencia
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

    public function alterarStatus(
        int $id,
        string $status,
        ?string $mensagemAoMorador = null
    ): bool {

        // Evita reutilizar o mesmo placeholder nomeado em uma única instrução
        // PDO/MySQL, o que causa SQLSTATE[HY093] em algumas configurações.
        if ($status === 'Resolvida') {
            $sql = "
                UPDATE ocorrencia
                SET
                    status = :status,
                    data_fechamento = CURRENT_TIMESTAMP
                WHERE id = :id
            ";
        } elseif (in_array($status, ['Aberta', 'Em_Andamento'], true)) {
            $sql = "
                UPDATE ocorrencia
                SET
                    status = :status,
                    data_fechamento = NULL
                WHERE id = :id
            ";
        } else {
            $sql = "
                UPDATE ocorrencia
                SET
                    status = :status
                WHERE id = :id
            ";
        }

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":status",
            $status,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $sucesso = $stmt->execute();

        if ($sucesso) {

            $mensagemAoMorador = trim((string) $mensagemAoMorador);

            if ($mensagemAoMorador === "") {
                $mensagemAoMorador = "Status atualizado para "
                    . OcorrenciaHistorico::rotuloStatus($status)
                    . ".";
            }

            OcorrenciaHistorico::registrar(
                $this->conexao,
                $id,
                $status,
                $mensagemAoMorador
            );
        }

        return $sucesso;
    }

    private function montarDTO(array $dados): OcorrenciaDTO
    {
        $ocorrencia = new OcorrenciaDTO();

        $ocorrencia->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $ocorrencia->setUsuarioId(
            isset($dados["usuario_id"])
                ? (int) $dados["usuario_id"]
                : null
        );

        $ocorrencia->setUsuarioNome(
            $dados["usuario_nome"] ?? null
        );

        $ocorrencia->setTipo(
            $dados["tipo"] ?? ""
        );

        $ocorrencia->setTitulo(
            $dados["titulo"] ?? ""
        );

        $ocorrencia->setDescricao(
            $dados["descricao"] ?? ""
        );

        $ocorrencia->setStatus(
            $dados["status"] ?? "Aberta"
        );

        $ocorrencia->setFotoUrl(
            $dados["foto_url"] ?? null
        );

        $ocorrencia->setDataAbertura(
            $dados["data_abertura"] ?? null
        );

        $ocorrencia->setDataFechamento(
            $dados["data_fechamento"] ?? null
        );

        $ocorrencia->setHistorico(
            $dados["historico"] ?? null
        );

        return $ocorrencia;
    }
}