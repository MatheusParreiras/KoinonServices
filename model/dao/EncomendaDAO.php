<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/EncomendaDTO.php";
require_once __DIR__ . "/../../service/ComprovanteDAO.php";

class EncomendaDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }

    /*
     * ==========================================================
     * LISTAR ENCOMENDAS
     * ==========================================================
     */

    public function listar(): array
    {
        $sql = "
            SELECT
                e.*,
                u.identificacao AS unidade_identificacao,
                morador.nome AS usuario_nome
            FROM encomenda e
            INNER JOIN unidade u
                ON u.id = e.unidade_id
            LEFT JOIN usuario morador
                ON morador.id = e.usuario_recebedor_id
            ORDER BY e.data_recebimento DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $resultados = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $resultados[] = $this->montarDTO($linha);
        }

        return $resultados;
    }

    /*
     * ==========================================================
     * BUSCAR POR ID
     * ==========================================================
     */

    public function buscarPorId(int $id): ?EncomendaDTO
    {
        $sql = "
            SELECT
                e.*,
                u.identificacao AS unidade_identificacao,
                morador.nome AS usuario_nome
            FROM encomenda e
            INNER JOIN unidade u
                ON u.id = e.unidade_id
            LEFT JOIN usuario morador
                ON morador.id = e.usuario_recebedor_id
            WHERE e.id = :id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$linha) {
            return null;
        }

        return $this->montarDTO($linha);
    }

    /*
     * ==========================================================
     * CADASTRAR
     * ==========================================================
     */

    public function cadastrar(
        EncomendaDTO $encomenda
    ): bool {

        $sql = "
            INSERT INTO encomenda (
                unidade_id,
                usuario_recebedor_id,
                descricao,
                foto_url,
                codigo_rastreio,
                data_recebimento,
                data_retirada,
                status
            )
            VALUES (
                :unidade_id,
                :usuario_recebedor_id,
                :descricao,
                :foto_url,
                :codigo_rastreio,
                :data_recebimento,
                NULL,
                :status
            )
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":unidade_id",
            $encomenda->getUnidadeId(),
            PDO::PARAM_INT
        );

        if (
            $encomenda->getUsuarioRecebedorId()
            !== null
        ) {
            $stmt->bindValue(
                ":usuario_recebedor_id",
                $encomenda->getUsuarioRecebedorId(),
                PDO::PARAM_INT
            );
        } else {
            $stmt->bindValue(
                ":usuario_recebedor_id",
                null,
                PDO::PARAM_NULL
            );
        }

        $stmt->bindValue(
            ":descricao",
            $encomenda->getDescricao(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":foto_url",
            $encomenda->getFotoUrl(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":codigo_rastreio",
            $encomenda->getCodigoRastreio(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":data_recebimento",
            $encomenda->getDataRecebimento(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":status",
            $encomenda->getStatus(),
            PDO::PARAM_STR
        );

        return $stmt->execute();
    }

    /*
     * ==========================================================
     * EDITAR
     * ==========================================================
     */

    public function editar(
        EncomendaDTO $encomenda
    ): bool {

        $atual = $this->buscarPorId((int)$encomenda->getId());
        if ($atual && $atual->getStatus() === "Entregue") {
            return false;
        }

        $sql = "
            UPDATE encomenda
            SET
                unidade_id = :unidade_id,
                usuario_recebedor_id = :usuario_recebedor_id,
                descricao = :descricao,
                foto_url = :foto_url,
                codigo_rastreio = :codigo_rastreio
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $encomenda->getId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":unidade_id",
            $encomenda->getUnidadeId(),
            PDO::PARAM_INT
        );

        if (
            $encomenda->getUsuarioRecebedorId()
            !== null
        ) {
            $stmt->bindValue(
                ":usuario_recebedor_id",
                $encomenda->getUsuarioRecebedorId(),
                PDO::PARAM_INT
            );
        } else {
            $stmt->bindValue(
                ":usuario_recebedor_id",
                null,
                PDO::PARAM_NULL
            );
        }

        $stmt->bindValue(
            ":descricao",
            $encomenda->getDescricao(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":foto_url",
            $encomenda->getFotoUrl(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":codigo_rastreio",
            $encomenda->getCodigoRastreio(),
            PDO::PARAM_STR
        );

        return $stmt->execute();
    }

    /*
     * ==========================================================
     * EXCLUIR
     * ==========================================================
     */

    public function excluir(int $id): bool
    {
        // Registros de encomendas fazem parte do histórico de segurança
        // e não podem ser apagados pelo sistema.
        return false;
    }

    /*
     * ==========================================================
     * ALTERAR STATUS
     * ==========================================================
     */

    public function alterarStatus(
        int $id,
        string $status
    ): bool {

        $statusPermitidos = [
            "Aguardando_Retirada",
            "Entregue",
            "Devolvido"
        ];

        if (
            !in_array(
                $status,
                $statusPermitidos,
                true
            )
        ) {
            return false;
        }

        if ($status === "Entregue") {
            return $this->marcarEntregue($id);
        }

        if ($status === "Aguardando_Retirada") {

            $sql = "
                UPDATE encomenda
                SET
                    status = 'Aguardando_Retirada',
                    data_retirada = NULL
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

        if ($status === "Devolvido") {

            $sql = "
                UPDATE encomenda
                SET
                    status = 'Devolvido',
                    data_retirada = NULL
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

        return false;
    }

    /*
     * ==========================================================
     * MARCAR COMO ENTREGUE
     * ==========================================================
     *
     * O usuario_recebedor_id continua sendo o MORADOR
     * destinatário da encomenda.
     *
     * A portaria apenas confirma a retirada.
     */

    public function marcarEntregue(
        int $id
    ): bool {

        $encomenda = $this->buscarPorId($id);
        if (!$encomenda || $encomenda->getStatus() !== "Aguardando_Retirada") {
            return false;
        }

        $this->conexao->beginTransaction();
        try {
            $stmt = $this->conexao->prepare("
                UPDATE encomenda
                SET status = 'Entregue', data_retirada = NOW()
                WHERE id = :id AND status = 'Aguardando_Retirada'
            ");
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() <= 0) {
                $this->conexao->rollBack();
                return false;
            }

            $usuarioNome = $encomenda->getUsuarioNome();
            $unidade = $encomenda->getUnidadeIdentificacao();
            $dados = [
                'encomenda_id' => $id,
                'unidade_id' => $encomenda->getUnidadeId(),
                'usuario_id' => $encomenda->getUsuarioRecebedorId(),
                'usuario_nome' => $usuarioNome,
                'unidade_identificacao' => $unidade,
                'descricao' => $encomenda->getDescricao(),
                'codigo_rastreio' => $encomenda->getCodigoRastreio(),
                'data_recebimento' => $encomenda->getDataRecebimento(),
                'data_retirada' => date('Y-m-d H:i:s'),
            ];
            $comprovante = new ComprovanteDAO();
            if (!$comprovante->criarEncomenda($dados)) {
                throw new RuntimeException('Não foi possível registrar o comprovante da retirada.');
            }
            $this->conexao->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->conexao->inTransaction()) $this->conexao->rollBack();
            return false;
        }
    }

    /*
     * ==========================================================
     * REABRIR
     * ==========================================================
     */

    public function reabrir(
        int $id
    ): bool {

        $check = $this->buscarPorId($id);
        if (!$check || $check->getStatus() === "Entregue") {
            return false;
        }

        $sql = "
            UPDATE encomenda
            SET
                status = 'Aguardando_Retirada',
                data_retirada = NULL
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
     * ==========================================================
     * LISTAR UNIDADES
     * ==========================================================
     */

    public function listarUnidades(): array
    {
        $sql = "
            SELECT
                id,
                identificacao
            FROM unidade
            ORDER BY identificacao
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }

    /*
     * ==========================================================
     * LISTAR MORADORES POR UNIDADE
     * ==========================================================
     */

    public function listarMoradoresPorUnidade(
        int $unidadeId
    ): array {

        $sql = "
            SELECT
                id,
                nome,
                cpf,
                email
            FROM usuario
            WHERE
                unidade_id = :unidade_id
                AND perfil = 'Morador'
                AND ativo = 1
            ORDER BY nome
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":unidade_id",
            $unidadeId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }

    /*
     * ==========================================================
     * MONTAR DTO
     * ==========================================================
     */

    private function montarDTO(
        array $linha
    ): EncomendaDTO {

        $dto = new EncomendaDTO();

        $dto->setId(
            isset($linha["id"])
                ? (int) $linha["id"]
                : null
        );

        $dto->setUnidadeId(
            isset($linha["unidade_id"])
                ? (int) $linha["unidade_id"]
                : null
        );

        $dto->setUsuarioRecebedorId(
            isset($linha["usuario_recebedor_id"])
                && $linha["usuario_recebedor_id"] !== null
                ? (int) $linha["usuario_recebedor_id"]
                : null
        );

        $dto->setDescricao(
            $linha["descricao"] ?? null
        );

        $dto->setFotoUrl(
            $linha["foto_url"] ?? null
        );

        $dto->setCodigoRastreio(
            $linha["codigo_rastreio"] ?? null
        );

        $dto->setDataRecebimento(
            $linha["data_recebimento"] ?? null
        );

        $dto->setDataRetirada(
            $linha["data_retirada"] ?? null
        );

        $dto->setStatus(
            $linha["status"]
                ?? "Aguardando_Retirada"
        );

        $dto->setUnidadeIdentificacao(
            $linha["unidade_identificacao"]
                ?? null
        );

        $dto->setUsuarioNome(
            $linha["usuario_nome"]
                ?? null
        );

        return $dto;
    }
}