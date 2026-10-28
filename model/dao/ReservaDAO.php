<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/ReservaDTO.php";
require_once __DIR__ . "/../../service/Erro.php";

class ReservaDAO
{
    private PDO $conexao;

    private string $ultimoErro = "";


    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }


    public function getUltimoErro(): string
    {
        return $this->ultimoErro;
    }


    /*
     * ==========================================================
     * LISTAR RESERVAS
     * ==========================================================
     */

    public function listar(): array
    {
        $this->atualizarStatusAutomaticos();

        $sql = "
            SELECT
                r.*,
                u.nome AS usuario_nome,
                u.unidade_id,

                (
                    SELECT c.status
                    FROM cobranca c
                    WHERE c.unidade_id = u.unidade_id
                      AND c.tipo_cobranca = 'Reserva'
                      AND c.descricao = CONCAT(
                          'Reserva #',
                          r.id,
                          ' - ',
                          r.espaco_nome
                      )
                      AND c.status <> 'Cancelado'
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS pagamento_status

            FROM reserva r

            INNER JOIN usuario u
                ON u.id = r.usuario_id

            ORDER BY r.data_inicio DESC, r.id DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $reservas = [];

        while ($dados = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $reservas[] = $this->montarDTO($dados);
        }

        return $reservas;
    }


    /*
     * ==========================================================
     * BUSCAR POR ID
     * ==========================================================
     */

    public function buscarPorId(int $id): ?ReservaDTO
    {
        $sql = "
            SELECT
                r.*,
                u.nome AS usuario_nome,
                u.unidade_id,

                (
                    SELECT c.status
                    FROM cobranca c
                    WHERE c.unidade_id = u.unidade_id
                      AND c.tipo_cobranca = 'Reserva'
                      AND c.descricao = CONCAT(
                          'Reserva #',
                          r.id,
                          ' - ',
                          r.espaco_nome
                      )
                      AND c.status <> 'Cancelado'
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS pagamento_status

            FROM reserva r

            INNER JOIN usuario u
                ON u.id = r.usuario_id

            WHERE r.id = :id

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
     * ==========================================================
     * CADASTRAR
     * ==========================================================
     */

    public function cadastrar(ReservaDTO $reserva): bool
    {
        $this->ultimoErro = "";

        if (!$this->prepararDadosLocal($reserva, false)) {
            return false;
        }

        $validacao =
            $this->validarRegrasLocal(
                $reserva,
                null
            );

        if ($validacao !== true) {
            $this->ultimoErro = $validacao;
            return false;
        }

        try {
            $this->conexao->beginTransaction();

            $sql = "
                INSERT INTO reserva (
                    usuario_id,
                    local_reserva_id,
                    espaco_nome,
                    lista_presenca,
                    data_inicio,
                    data_fim,
                    status,
                    valor_taxa
                )
                VALUES (
                    :usuario_id,
                    :local_reserva_id,
                    :espaco_nome,
                    :lista_presenca,
                    :data_inicio,
                    :data_fim,
                    :status,
                    :valor_taxa
                )
            ";

            $stmt =
                $this->conexao->prepare($sql);

            $stmt->bindValue(
                ":usuario_id",
                $reserva->getUsuarioId(),
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ":local_reserva_id",
                $reserva->getLocalReservaId(),
                PDO::PARAM_INT
            );

            $stmt->bindValue(
                ":espaco_nome",
                trim($reserva->getEspacoNome()),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":lista_presenca",
                ReservaDTO::listaParaJson($reserva->getListaPresenca()),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":data_inicio",
                $reserva->getDataInicio(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":data_fim",
                $reserva->getDataFim(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":status",
                $reserva->getStatus(),
                PDO::PARAM_STR
            );

            $stmt->bindValue(
                ":valor_taxa",
                $reserva->getValorTaxa()
            );

            if (!$stmt->execute()) {
                $info = $stmt->errorInfo();

                throw new RuntimeException(
                    $info[2] ??
                    "Não foi possível cadastrar a reserva."
                );
            }

            $idGerado =
                (int) $this->conexao->lastInsertId();

            if ($idGerado <= 0) {
                throw new RuntimeException(
                    "A reserva foi criada, mas não foi possível obter o identificador interno."
                );
            }

            $reserva->setId($idGerado);

            if (
                $reserva->getStatus() === "Confirmada" &&
                $reserva->getValorTaxa() > 0
            ) {
                $aguarda = $this->conexao->prepare(
                    "UPDATE reserva SET status = 'Aguardando_Pagamento' WHERE id = :id"
                );
                $aguarda->bindValue(":id", $idGerado, PDO::PARAM_INT);
                $aguarda->execute();
                $reserva->setStatus("Aguardando_Pagamento");
            }

            if (
                $reserva->getStatus() === "Aguardando_Pagamento" &&
                $reserva->getValorTaxa() > 0 &&
                !$this->gerarCobrancaReserva($reserva)
            ) {
                throw new RuntimeException(
                    "A reserva foi criada, mas a cobrança não pôde ser registrada."
                );
            }

            $this->conexao->commit();

            return true;

        } catch (Throwable $e) {

            if ($this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }

            $this->ultimoErro =
                "Não foi possível cadastrar a reserva: "
                . Erro::mensagem($e);

            return false;
        }
    }


    /*
     * ==========================================================
     * VALIDAR REGRAS DO LOCAL
     * ==========================================================
     */

    private function validarRegrasLocal(
        ReservaDTO $reserva,
        ?int $idIgnorar
    ): bool|string {

        $inicioTexto =
            trim((string) $reserva->getDataInicio());

        $fimTexto =
            trim((string) $reserva->getDataFim());

        if ($inicioTexto === "" || $fimTexto === "") {
            return "Informe a data e o horário da reserva.";
        }

        try {
            $inicio = new DateTime($inicioTexto);
            $fim = new DateTime($fimTexto);
        } catch (Throwable $e) {
            return "A data ou horário informado é inválido.";
        }

        if ($fim <= $inicio) {
            return "A data/hora final deve ser posterior à inicial.";
        }

        if ($inicio <= new DateTime()) {
            return "A data e horário inicial da reserva devem ser futuros.";
        }

        $localId =
            $reserva->getLocalReservaId();

        if ($localId === null || $localId <= 0) {
            return "Selecione um local de reserva válido.";
        }

        $sql = "
            SELECT
                capacidade,
                duracao_minutos_minima,
                duracao_minutos_maxima,
                horario_inicio,
                horario_fim,
                antecedencia_min_dias,
                antecedencia_max_dias,
                domingo,
                segunda,
                terca,
                quarta,
                quinta,
                sexta,
                sabado,
                ativo
            FROM local_reserva
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->bindValue(":id", $localId, PDO::PARAM_INT);
        $stmt->execute();

        $local =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$local) {
            return "O local de reserva selecionado não existe.";
        }

        if (!(bool) $local["ativo"]) {
            return "O local selecionado está inativo.";
        }

        $quantidade = $reserva->getQuantidadePessoas();

        if ($quantidade > (int) $local["capacidade"]) {
            return
                "A lista de presença excede a capacidade do local. "
                . "Capacidade máxima: "
                . (int) $local["capacidade"]
                . " pessoa(s).";
        }

        $dias = [
            1 => "segunda",
            2 => "terca",
            3 => "quarta",
            4 => "quinta",
            5 => "sexta",
            6 => "sabado",
            7 => "domingo"
        ];

        $diaNumero =
            (int) $inicio->format("N");

        $campoDia =
            $dias[$diaNumero];

        if (empty($local[$campoDia])) {
            $nomesDias = [
                1 => "segunda-feira",
                2 => "terça-feira",
                3 => "quarta-feira",
                4 => "quinta-feira",
                5 => "sexta-feira",
                6 => "sábado",
                7 => "domingo"
            ];

            return
                "O local não permite reservas às "
                . $nomesDias[$diaNumero]
                . ".";
        }

        $horarioInicio =
            substr((string) $local["horario_inicio"], 0, 5);

        $horarioFim =
            substr((string) $local["horario_fim"], 0, 5);

        if (
            $inicio->format("H:i") < $horarioInicio ||
            $fim->format("H:i") > $horarioFim
        ) {
            return
                "O horário escolhido está fora do período permitido. "
                . "Horário permitido: "
                . $horarioInicio
                . " às "
                . $horarioFim
                . ".";
        }

        $duracao =
            (int) round(
                ($fim->getTimestamp() - $inicio->getTimestamp()) / 60
            );

        $duracaoMinima =
            (int) $local["duracao_minutos_minima"];

        $duracaoMaxima =
            (int) $local["duracao_minutos_maxima"];

        if ($duracao < $duracaoMinima) {
            return
                "A duração mínima da reserva é de "
                . $this->formatarDuracao($duracaoMinima)
                . ".";
        }

        if ($duracao > $duracaoMaxima) {
            return
                "A duração máxima da reserva é de "
                . $this->formatarDuracao($duracaoMaxima)
                . ".";
        }

        $hoje = new DateTime("today");
        $dataReserva = new DateTime($inicio->format("Y-m-d"));
        $antecedencia =
            (int) $hoje->diff($dataReserva)->format("%r%a");

        if (
            $antecedencia <
            (int) $local["antecedencia_min_dias"]
        ) {
            return
                "A reserva precisa ser feita com pelo menos "
                . (int) $local["antecedencia_min_dias"]
                . " dia(s) de antecedência.";
        }

        if (
            $antecedencia >
            (int) $local["antecedencia_max_dias"]
        ) {
            return
                "A reserva só pode ser feita com até "
                . (int) $local["antecedencia_max_dias"]
                . " dia(s) de antecedência.";
        }

        $sqlConflito = "
            SELECT id
            FROM reserva
            WHERE local_reserva_id = :local_reserva_id
              AND status IN ('Pendente', 'Aguardando_Pagamento', 'Confirmada')
              AND data_inicio < :data_fim
              AND data_fim > :data_inicio
        ";

        if ($idIgnorar !== null) {
            $sqlConflito .= " AND id <> :id_ignorar";
        }

        $sqlConflito .= " LIMIT 1";

        $stmtConflito =
            $this->conexao->prepare($sqlConflito);

        $stmtConflito->bindValue(
            ":local_reserva_id",
            $localId,
            PDO::PARAM_INT
        );

        $stmtConflito->bindValue(
            ":data_inicio",
            $inicio->format("Y-m-d H:i:s"),
            PDO::PARAM_STR
        );

        $stmtConflito->bindValue(
            ":data_fim",
            $fim->format("Y-m-d H:i:s"),
            PDO::PARAM_STR
        );

        if ($idIgnorar !== null) {
            $stmtConflito->bindValue(
                ":id_ignorar",
                $idIgnorar,
                PDO::PARAM_INT
            );
        }

        $stmtConflito->execute();

        if ($stmtConflito->fetch(PDO::FETCH_ASSOC)) {
            return "Já existe uma reserva para este local no período informado.";
        }

        return true;
    }



    /*
     * ==========================================================
     * EDITAR
     * ==========================================================
     */

    public function editar(ReservaDTO $reserva): bool
    {
        $this->ultimoErro = "";

        if (!$this->prepararDadosLocal($reserva, true)) {
            return false;
        }

        $reservaAtual = $this->buscarPorId(
            (int) $reserva->getId()
        );

        if (!$reservaAtual) {
            return false;
        }

        $statusAnterior =
            $reservaAtual->getStatus();

        $statusNovo =
            $reserva->getStatus();

        if (
            $statusNovo === "Confirmada" &&
            $reserva->getValorTaxa() > 0 &&
            !$this->cobrancaReservaPaga($reserva)
        ) {
            $reserva->setStatus("Aguardando_Pagamento");
            $statusNovo = "Aguardando_Pagamento";
        }

        if ($statusNovo === "Aguardando_Pagamento") {

            try {

                $this->conexao->beginTransaction();

                if (!$this->executarEdicao($reserva)) {
                    $this->conexao->rollBack();
                    return false;
                }

                if (
                    $reserva->getValorTaxa() > 0 &&
                    !$this->garantirCobrancaReserva($reserva)
                ) {
                    $this->conexao->rollBack();
                    return false;
                }

                $this->conexao->commit();
                return true;

            } catch (Throwable $e) {
                if ($this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return false;
            }
        }

        /*
         * Se estiver confirmando agora,
         * a cobrança deve ser criada.
         */

        if (
            $statusNovo === "Confirmada" &&
            $statusAnterior !== "Confirmada"
        ) {

            try {

                $this->conexao->beginTransaction();

                if (
                    !$this->executarEdicao($reserva)
                ) {
                    $this->conexao->rollBack();
                    return false;
                }

                if (
                    $reserva->getValorTaxa() > 0
                ) {

                    if (
                        !$this->gerarCobrancaReserva(
                            $reserva
                        )
                    ) {
                        $this->conexao->rollBack();
                        return false;
                    }
                }

                $this->conexao->commit();

                return true;

            } catch (Throwable $e) {

                if (
                    $this->conexao->inTransaction()
                ) {
                    $this->conexao->rollBack();
                }

                return false;
            }
        }


        /*
         * Se já estava confirmada e continua confirmada,
         * atualiza normalmente.
         */

        if (
            $statusNovo === "Confirmada" &&
            $statusAnterior === "Confirmada"
        ) {

            if (
                !$this->executarEdicao($reserva)
            ) {
                return false;
            }

            /*
             * Garante que a cobrança exista.
             */
            if (
                $reserva->getValorTaxa() > 0
            ) {
                return $this->garantirCobrancaReserva(
                    $reserva
                );
            }

            return true;
        }


        return $this->executarEdicao(
            $reserva
        );
    }


    /*
     * ==========================================================
     * EXECUTAR EDIÇÃO
     * ==========================================================
     */

    private function executarEdicao(
        ReservaDTO $reserva
    ): bool {

        $sql = "
            UPDATE reserva
            SET
                usuario_id = :usuario_id,
                local_reserva_id = :local_reserva_id,
                espaco_nome = :espaco_nome,
                lista_presenca = :lista_presenca,
                data_inicio = :data_inicio,
                data_fim = :data_fim,
                status = :status,
                valor_taxa = :valor_taxa
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $reserva->getUsuarioId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":local_reserva_id",
            $reserva->getLocalReservaId(),
            $reserva->getLocalReservaId() === null
                ? PDO::PARAM_NULL
                : PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":espaco_nome",
            trim($reserva->getEspacoNome()),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":lista_presenca",
            ReservaDTO::listaParaJson($reserva->getListaPresenca()),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":data_inicio",
            $reserva->getDataInicio(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":data_fim",
            $reserva->getDataFim(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":status",
            $reserva->getStatus(),
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":valor_taxa",
            $reserva->getValorTaxa()
        );

        $stmt->bindValue(
            ":id",
            $reserva->getId(),
            PDO::PARAM_INT
        );

        try {
            $resultado = $stmt->execute();
        } catch (Throwable $e) {
            $this->ultimoErro =
                "Não foi possível atualizar a reserva: "
                . Erro::mensagem($e);

            return false;
        }

        if (!$resultado) {
            $info = $stmt->errorInfo();

            $this->ultimoErro =
                $info[2] ??
                "Não foi possível atualizar a reserva.";
        }

        return $resultado;
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
            "Pendente",
            "Aguardando_Pagamento",
            "Confirmada",
            "Cancelada",
            "Concluida"
        ];

        if (
            !in_array(
                $status,
                $statusPermitidos,
                true
            )
        ) {
            $this->ultimoErro =
                "Status da reserva inválido.";

            return false;
        }

        $reserva =
            $this->buscarPorId($id);

        if (!$reserva) {
            return false;
        }

        $statusAnterior =
            $reserva->getStatus();

        /*
         * Reserva paga só é confirmada depois do pagamento: confirmar uma
         * reserva com taxa a deixa "Aguardando pagamento" e gera a cobrança;
         * o pagamento (Pix, cartão ou boleto) muda o status para Confirmada.
         */
        if (
            $status === "Confirmada" &&
            $reserva->getValorTaxa() > 0 &&
            !$this->cobrancaReservaPaga($reserva)
        ) {
            $status = "Aguardando_Pagamento";
        }

        if ($status === "Aguardando_Pagamento") {

            try {

                $this->conexao->beginTransaction();

                $stmt = $this->conexao->prepare(
                    "UPDATE reserva SET status = 'Aguardando_Pagamento' WHERE id = :id"
                );
                $stmt->bindValue(":id", $id, PDO::PARAM_INT);

                if (!$stmt->execute()) {
                    $this->conexao->rollBack();
                    return false;
                }

                if (!$this->garantirCobrancaReserva($reserva)) {
                    $this->conexao->rollBack();
                    return false;
                }

                $this->conexao->commit();
                return true;

            } catch (Throwable $e) {
                if ($this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                $this->ultimoErro = "Não foi possível aguardar o pagamento: " . Erro::mensagem($e);
                return false;
            }
        }


        /*
         * Cancelamento
         */

        if ($status === "Cancelada") {

            $sql = "
                UPDATE reserva
                SET status = 'Cancelada'
                WHERE id = :id
            ";

            $stmt = $this->conexao->prepare($sql);

            $stmt->bindValue(
                ":id",
                $id,
                PDO::PARAM_INT
            );

            $resultado = $stmt->execute();

            if ($resultado) {
                $this->cancelarCobrancaReserva(
                    $reserva
                );
            }

            return $resultado;
        }


        /*
         * CONFIRMAÇÃO
         *
         * É aqui que a cobrança nasce.
         */

        if (
            $status === "Confirmada" &&
            $statusAnterior !== "Confirmada"
        ) {

            try {

                $this->conexao->beginTransaction();

                $sql = "
                    UPDATE reserva
                    SET status = 'Confirmada'
                    WHERE id = :id
                ";

                $stmt =
                    $this->conexao->prepare($sql);

                $stmt->bindValue(
                    ":id",
                    $id,
                    PDO::PARAM_INT
                );

                if (!$stmt->execute()) {
                    $this->conexao->rollBack();
                    return false;
                }

                /*
                 * Reserva gratuita:
                 * não cria cobrança.
                 */
                if (
                    $reserva->getValorTaxa() > 0
                ) {

                    if (
                        !$this->garantirCobrancaReserva(
                            $reserva
                        )
                    ) {
                        $this->conexao->rollBack();
                        return false;
                    }
                }

                $this->conexao->commit();

                return true;

            } catch (Throwable $e) {

                if (
                    $this->conexao->inTransaction()
                ) {
                    $this->conexao->rollBack();
                }

                return false;
            }
        }


        /*
         * Demais alterações de status.
         */

        $sql = "
            UPDATE reserva
            SET status = :status
            WHERE id = :id
        ";

        $stmt =
            $this->conexao->prepare($sql);

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

        try {
            $resultado = $stmt->execute();
        } catch (Throwable $e) {
            $this->ultimoErro =
                "Não foi possível atualizar o status da reserva: "
                . Erro::mensagem($e);

            return false;
        }

        if (!$resultado) {
            $info = $stmt->errorInfo();

            $this->ultimoErro =
                $info[2] ??
                "Não foi possível atualizar o status da reserva.";
        }

        return $resultado;
    }


    /*
     * ==========================================================
     * EXCLUIR
     * ==========================================================
     */

    public function excluir(int $id): bool
    {
        $sql = "
            DELETE FROM reserva
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
     * STATUS AUTOMÁTICOS
     * ==========================================================
     */

    private function atualizarStatusAutomaticos(): void
    {
        $sql = "
            UPDATE reserva
            SET status = 'Concluida'
            WHERE status = 'Confirmada'
              AND data_fim < NOW()
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        /*
         * Reserva que não foi paga até o início do evento é cancelada,
         * junto com a cobrança pendente.
         */
        try {
            $this->conexao->exec("
                UPDATE cobranca c
                INNER JOIN reserva r
                    ON c.tipo_cobranca = 'Reserva'
                   AND c.descricao = CONCAT('Reserva #', r.id, ' - ', r.espaco_nome)
                   AND c.status = 'Pendente'
                SET c.status = 'Cancelado'
                WHERE r.status = 'Aguardando_Pagamento'
                  AND r.data_inicio < NOW()
            ");
            $this->conexao->exec("
                UPDATE reserva
                SET status = 'Cancelada'
                WHERE status = 'Aguardando_Pagamento'
                  AND data_inicio < NOW()
            ");
        } catch (Throwable $e) {
        }
    }

    private function cobrancaReservaPaga(ReservaDTO $reserva): bool
    {
        $dados = $this->buscarDadosReserva((int) $reserva->getId());

        if (!$dados) {
            return false;
        }

        $stmt = $this->conexao->prepare(
            "SELECT 1 FROM cobranca
             WHERE unidade_id = :u AND tipo_cobranca = 'Reserva'
               AND descricao = :d AND status = 'Pago' LIMIT 1"
        );
        $stmt->execute([
            ":u" => (int) ($dados["unidade_id"] ?? 0),
            ":d" => "Reserva #" . $reserva->getId() . " - " . $reserva->getEspacoNome(),
        ]);

        return (bool) $stmt->fetchColumn();
    }


    /*
     * ==========================================================
     * BUSCAR DADOS INTERNOS
     * ==========================================================
     */

    private function buscarDadosReserva(
        int $reservaId
    ): ?array {

        $sql = "
            SELECT
                r.*,
                u.nome AS usuario_nome,
                u.unidade_id
            FROM reserva r
            INNER JOIN usuario u
                ON u.id = r.usuario_id
            WHERE r.id = :id
            LIMIT 1
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $reservaId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $dados =
            $stmt->fetch(PDO::FETCH_ASSOC);

        return $dados ?: null;
    }


    /*
     * ==========================================================
     * GARANTIR COBRANÇA
     *
     * Evita duplicidade.
     * ==========================================================
     */

    private function garantirCobrancaReserva(
        ReservaDTO $reserva
    ): bool {

        if (
            $reserva->getValorTaxa() <= 0
        ) {
            return true;
        }

        $dados =
            $this->buscarDadosReserva(
                (int) $reserva->getId()
            );

        if (!$dados) {
            return false;
        }

        $unidadeId =
            (int) (
                $dados["unidade_id"] ?? 0
            );

        if ($unidadeId <= 0) {
            return false;
        }


        /*
         * Verifica se já existe cobrança ativa.
         */

        $descricao =
            "Reserva #"
            . $reserva->getId()
            . " - "
            . $reserva->getEspacoNome();


        $sqlExiste = "
            SELECT
                id,
                status
            FROM cobranca
            WHERE unidade_id = :unidade_id
              AND tipo_cobranca = 'Reserva'
              AND descricao = :descricao
              AND status <> 'Cancelado'
            ORDER BY id DESC
            LIMIT 1
        ";

        $stmtExiste =
            $this->conexao->prepare(
                $sqlExiste
            );

        $stmtExiste->bindValue(
            ":unidade_id",
            $unidadeId,
            PDO::PARAM_INT
        );

        $stmtExiste->bindValue(
            ":descricao",
            $descricao,
            PDO::PARAM_STR
        );

        $stmtExiste->execute();

        $cobranca =
            $stmtExiste->fetch(
                PDO::FETCH_ASSOC
            );

        if ($cobranca) {

            /*
             * Já existe.
             * Não cria outra.
             */
            return true;
        }


        /*
         * Referência mensal.
         */

        $dataInicio =
            $reserva->getDataInicio();

        $mesReferencia =
            date(
                "Y-m",
                strtotime(
                    (string) $dataInicio
                )
            );


        /*
         * Vencimento = data da reserva.
         */

        $vencimento =
            date(
                "Y-m-d",
                strtotime(
                    (string) $dataInicio
                )
            );


        $sql = "
            INSERT INTO cobranca (
                unidade_id,
                tipo_cobranca,
                mes_referencia,
                descricao,
                valor,
                vencimento,
                status
            )
            VALUES (
                :unidade_id,
                'Reserva',
                :mes_referencia,
                :descricao,
                :valor,
                :vencimento,
                'Pendente'
            )
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":unidade_id",
            $unidadeId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":mes_referencia",
            $mesReferencia,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":descricao",
            $descricao,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ":valor",
            $reserva->getValorTaxa()
        );

        $stmt->bindValue(
            ":vencimento",
            $vencimento,
            PDO::PARAM_STR
        );

        return $stmt->execute();
    }


    /*
     * ==========================================================
     * COMPATIBILIDADE
     * ==========================================================
     */

    private function gerarCobrancaReserva(
        ReservaDTO $reserva
    ): bool {

        return $this->garantirCobrancaReserva(
            $reserva
        );
    }


    /*
     * ==========================================================
     * CANCELAR COBRANÇA DE RESERVA
     * ==========================================================
     */

    private function cancelarCobrancaReserva(
        ReservaDTO $reserva
    ): void {

        $dados =
            $this->buscarDadosReserva(
                (int) $reserva->getId()
            );

        if (!$dados) {
            return;
        }

        $unidadeId =
            (int) (
                $dados["unidade_id"] ?? 0
            );

        if ($unidadeId <= 0) {
            return;
        }

        $descricao =
            "Reserva #"
            . $reserva->getId()
            . " - "
            . $reserva->getEspacoNome();

        $sql = "
            UPDATE cobranca
            SET status = 'Cancelado'
            WHERE unidade_id = :unidade_id
              AND tipo_cobranca = 'Reserva'
              AND descricao = :descricao
              AND status = 'Pendente'
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":unidade_id",
            $unidadeId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":descricao",
            $descricao,
            PDO::PARAM_STR
        );

        $stmt->execute();
    }


    /*
     * ==========================================================
     * PREPARAR DADOS DO LOCAL
     *
     * Mantém espaco_nome e valor_taxa sincronizados com
     * local_reserva_id sem alterar a estrutura do banco.
     * ==========================================================
     */

    private function prepararDadosLocal(
        ReservaDTO $reserva,
        bool $permitirLocalInativo
    ): bool {

        $localId = $reserva->getLocalReservaId();

        if ($localId === null || $localId <= 0) {

            /*
             * Reservas antigas do banco podem não possuir
             * local_reserva_id. Em uma edição, preservamos
             * esse registro legado em vez de quebrá-lo.
             *
             * Novas reservas, porém, exigem um local.
             */
            if (
                $permitirLocalInativo &&
                trim($reserva->getEspacoNome()) !== ""
            ) {
                return true;
            }

            $this->ultimoErro =
                "Selecione um local de reserva válido.";

            return false;
        }

        $sql = "
            SELECT
                id,
                nome,
                valor,
                gratuito,
                ativo
            FROM local_reserva
            WHERE id = :id
            LIMIT 1
        ";

        try {
            $stmt =
                $this->conexao->prepare($sql);

            $stmt->bindValue(
                ":id",
                $localId,
                PDO::PARAM_INT
            );

            $stmt->execute();

            $local =
                $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {
            $this->ultimoErro =
                "Não foi possível consultar o local da reserva.";

            return false;
        }

        if (!$local) {
            $this->ultimoErro =
                "O local de reserva selecionado não existe.";

            return false;
        }

        if (
            !$permitirLocalInativo &&
            !(bool) $local["ativo"]
        ) {
            $this->ultimoErro =
                "O local selecionado está inativo.";

            return false;
        }

        $reserva->setEspacoNome(
            trim((string) $local["nome"])
        );

        $gratuito =
            (bool) ($local["gratuito"] ?? false);

        $valor =
            (float) ($local["valor"] ?? 0);

        $reserva->setValorTaxa(
            $gratuito ? 0.00 : $valor
        );

        return true;
    }


    /*
     * ==========================================================
     * MONTAR DTO
     * ==========================================================
     */

    private function montarDTO(
        array $dados
    ): ReservaDTO {

        $reserva = new ReservaDTO();

        $reserva->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $reserva->setUsuarioId(
            isset($dados["usuario_id"])
                ? (int) $dados["usuario_id"]
                : null
        );

        $reserva->setUsuarioNome(
            $dados["usuario_nome"] ?? null
        );

        $reserva->setLocalReservaId(
            isset($dados["local_reserva_id"]) &&
            $dados["local_reserva_id"] !== null
                ? (int) $dados["local_reserva_id"]
                : null
        );

        $reserva->setEspacoNome(
            $dados["espaco_nome"] ?? ""
        );

        $reserva->setListaPresenca(
            ReservaDTO::listaDeJson($dados["lista_presenca"] ?? null)
        );

        $reserva->setDataInicio(
            $dados["data_inicio"] ?? null
        );

        $reserva->setDataFim(
            $dados["data_fim"] ?? null
        );

        $reserva->setStatus(
            $dados["status"] ?? "Pendente"
        );

        $reserva->setValorTaxa(
            isset($dados["valor_taxa"])
                ? (float) $dados["valor_taxa"]
                : 0.00
        );

        $reserva->setPagamentoStatus(
            $dados["pagamento_status"] ?? null
        );

        $reserva->setCreatedAt(
            $dados["created_at"] ?? null
        );

        return $reserva;
    }
}