<?php

require_once __DIR__ . "/../../service/PagamentoService.php";
require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/CobrancaDTO.php";
require_once __DIR__ . "/../../service/ComprovanteDAO.php";

class CobrancaDAO
{
    private PDO $pdo;

    private array $tiposPermitidos = [
        "Taxa_Condominial",
        "Reserva",
        "Multa",
        "Taxa_Extraordinaria",
        "Servico",
        "Outros"
    ];

    private array $statusPermitidos = [
        "Pendente",
        "Pago",
        "Vencido",
        "Cancelado"
    ];

    public function __construct()
    {
        $this->pdo = Conexao::conectar();
    }

    public function atualizarVencimentos(): void
    {
        $sql = "
            UPDATE cobranca
            SET status = 'Vencido'
            WHERE status = 'Pendente'
            AND vencimento < CURDATE()
        ";

        $this->pdo->exec($sql);
    }

    public function listar(): array
    {
        $this->atualizarVencimentos();

        $sql = "
            SELECT
                c.*,
                u.identificacao AS unidade_identificacao
            FROM cobranca c
            INNER JOIN unidade u
                ON u.id = c.unidade_id
            ORDER BY c.vencimento DESC, c.id DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $lista = [];

        while ($row = $stmt->fetch()) {
            $lista[] = $this->montarDTO($row);
        }

        return $lista;
    }

    public function buscarPorId(int $id): ?CobrancaDTO
    {
        $sql = "
            SELECT
                c.*,
                u.identificacao AS unidade_identificacao
            FROM cobranca c
            INNER JOIN unidade u
                ON u.id = c.unidade_id
            WHERE c.id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(":id", $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->montarDTO($row);
    }

    public function cadastrar(CobrancaDTO $cobranca): bool
    {
        if (
            !in_array(
                $cobranca->getTipoCobranca(),
                $this->tiposPermitidos,
                true
            )
        ) {
            return false;
        }

        if (
            !in_array(
                $cobranca->getStatus(),
                $this->statusPermitidos,
                true
            )
        ) {
            return false;
        }

        $sql = "
            INSERT INTO cobranca (
                unidade_id,
                tipo_cobranca,
                mes_referencia,
                descricao,
                valor,
                vencimento,
                linha_digitavel,
                link_boleto,
                status
            ) VALUES (
                :unidade_id,
                :tipo_cobranca,
                :mes_referencia,
                :descricao,
                :valor,
                :vencimento,
                :linha_digitavel,
                :link_boleto,
                :status
            )
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->bindValue(
            ":unidade_id",
            $cobranca->getUnidadeId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":tipo_cobranca",
            $cobranca->getTipoCobranca(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":mes_referencia",
            $cobranca->getMesReferencia(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":descricao",
            $cobranca->getDescricao(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":valor",
            $cobranca->getValor()
        );

        $stmt->bindValue(
            ":vencimento",
            $cobranca->getVencimento(),
            PDO::PARAM_STR
        );

        if ($cobranca->getLinhaDigitavel() === null || $cobranca->getLinhaDigitavel() === "") {
            $stmt->bindValue(":linha_digitavel", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(
                ":linha_digitavel",
                $cobranca->getLinhaDigitavel(),
                PDO::PARAM_STR
            );
        }

        if ($cobranca->getLinkBoleto() === null || $cobranca->getLinkBoleto() === "") {
            $stmt->bindValue(":link_boleto", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(
                ":link_boleto",
                $cobranca->getLinkBoleto(),
                PDO::PARAM_STR
            );
        }

        $stmt->bindValue(
            ":status",
            $cobranca->getStatus(),
            PDO::PARAM_STR
        );

        return $stmt->execute();
    }

    public function editar(CobrancaDTO $cobranca): bool
    {
        $atual = $this->buscarPorId((int)$cobranca->getId());
        if ($atual && $atual->getStatus() === 'Pago' && $cobranca->getStatus() !== 'Pago') {
            return false;
        }

        if (
            !in_array(
                $cobranca->getTipoCobranca(),
                $this->tiposPermitidos,
                true
            )
        ) {
            return false;
        }

        if (
            !in_array(
                $cobranca->getStatus(),
                $this->statusPermitidos,
                true
            )
        ) {
            return false;
        }

        $sql = "
            UPDATE cobranca
            SET
                unidade_id = :unidade_id,
                tipo_cobranca = :tipo_cobranca,
                mes_referencia = :mes_referencia,
                descricao = :descricao,
                valor = :valor,
                vencimento = :vencimento,
                linha_digitavel = :linha_digitavel,
                link_boleto = :link_boleto,
                status = :status
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->bindValue(
            ":unidade_id",
            $cobranca->getUnidadeId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":tipo_cobranca",
            $cobranca->getTipoCobranca(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":mes_referencia",
            $cobranca->getMesReferencia(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":descricao",
            $cobranca->getDescricao(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":valor",
            $cobranca->getValor()
        );

        $stmt->bindValue(
            ":vencimento",
            $cobranca->getVencimento(),
            PDO::PARAM_STR
        );

        if ($cobranca->getLinhaDigitavel() === null || $cobranca->getLinhaDigitavel() === "") {
            $stmt->bindValue(":linha_digitavel", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(
                ":linha_digitavel",
                $cobranca->getLinhaDigitavel(),
                PDO::PARAM_STR
            );
        }

        if ($cobranca->getLinkBoleto() === null || $cobranca->getLinkBoleto() === "") {
            $stmt->bindValue(":link_boleto", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(
                ":link_boleto",
                $cobranca->getLinkBoleto(),
                PDO::PARAM_STR
            );
        }

        $stmt->bindValue(
            ":status",
            $cobranca->getStatus(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":id",
            $cobranca->getId(),
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    public function excluir(int $id): bool
    {
        $statusStmt = $this->pdo->prepare("SELECT status FROM cobranca WHERE id = :id LIMIT 1");
        $statusStmt->execute([':id' => $id]);
        $status = $statusStmt->fetchColumn();

        // Pagamentos já registrados possuem comprovante.
        if ($status === 'Pago') {
            return false;
        }

        $sql = "DELETE FROM cobranca WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(":id", $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function alterarStatus(
        int $id,
        string $status
    ): bool {
        $atual = $this->buscarPorId($id);
        if ($atual && $atual->getStatus() === 'Pago' && $status !== 'Pago') {
            return false;
        }
        if (!in_array($status, $this->statusPermitidos, true)) {
            return false;
        }

        if ($status === "Pago") {
            return $this->registrarPagamento(
                $id,
                date("Y-m-d H:i:s")
            );
        }

        $sql = "
            UPDATE cobranca
            SET
                status = :status,
                data_pagamento = NULL
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);

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

        return $stmt->execute();
    }

    public function registrarPagamento(
        int $id,
        string $dataPagamento,
        ?string $forma = null,
        ?string $transacao = null
    ): bool {
        $cobranca = $this->buscarPorId($id);
        if (!$cobranca || $cobranca->getStatus() === 'Pago') {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE cobranca
                SET status = 'Pago', data_pagamento = :data_pagamento
                WHERE id = :id AND status <> 'Pago'
            ");
            $stmt->bindValue(":data_pagamento", $dataPagamento, PDO::PARAM_STR);
            $stmt->bindValue(":id", $id, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() <= 0) {
                $this->pdo->rollBack();
                return false;
            }

            $uStmt = $this->pdo->prepare("SELECT id, nome FROM usuario WHERE unidade_id = :unidade AND perfil = 'Morador' AND ativo = 1 ORDER BY id LIMIT 1");
            $uStmt->execute([':unidade' => (int)$cobranca->getUnidadeId()]);
            $u = $uStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $dados = [
                'cobranca_id' => $id,
                'unidade_id' => $cobranca->getUnidadeId(),
                'usuario_id' => $u ? (int)$u['id'] : null,
                'usuario_nome' => $u['nome'] ?? null,
                'unidade_identificacao' => $cobranca->getUnidadeIdentificacao(),
                'descricao' => $cobranca->getDescricao(),
                'valor' => $cobranca->getValor(),
                'vencimento' => $cobranca->getVencimento(),
                'data_pagamento' => $dataPagamento,
                'mes_referencia' => $cobranca->getMesReferencia(),
                'tipo_cobranca' => $cobranca->getTipoCobranca(),
                'data_emissao' => $dataPagamento,
            ];
            $comprovante = new ComprovanteDAO();
            if (!$comprovante->criarCobranca($dados)) {
                throw new RuntimeException('Não foi possível registrar o comprovante de pagamento.');
            }

            $this->pdo->commit();

            // Forma de pagamento, reserva vinculada e avisos (push).
            PagamentoService::aposPagamento($this->pdo, $id, $forma, $transacao);

            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return false;
        }
    }

    private function montarDTO(array $row): CobrancaDTO
    {
        $dto = new CobrancaDTO();

        $dto->setId(
            isset($row["id"])
                ? (int) $row["id"]
                : null
        );

        $dto->setUnidadeId(
            isset($row["unidade_id"])
                ? (int) $row["unidade_id"]
                : null
        );

        $dto->setUnidadeIdentificacao(
            $row["unidade_identificacao"] ?? null
        );

        $dto->setTipoCobranca(
            $row["tipo_cobranca"] ?? "Taxa_Condominial"
        );

        $dto->setMesReferencia(
            $row["mes_referencia"] ?? ""
        );

        $dto->setDescricao(
            $row["descricao"] ?? ""
        );

        $dto->setValor(
            isset($row["valor"])
                ? (float) $row["valor"]
                : 0.00
        );

        $dto->setVencimento(
            $row["vencimento"] ?? null
        );

        $dto->setLinhaDigitavel(
            $row["linha_digitavel"] ?? null
        );

        $dto->setLinkBoleto(
            $row["link_boleto"] ?? null
        );

        $dto->setStatus(
            $row["status"] ?? "Pendente"
        );

        $dto->setDataPagamento(
            $row["data_pagamento"] ?? null
        );

        $dto->setFormaPagamento($row["forma_pagamento"] ?? null);
        $dto->setCodigoTransacao($row["codigo_transacao"] ?? null);

        $dto->setCreatedAt(
            $row["created_at"] ?? null
        );

        return $dto;
    }
}